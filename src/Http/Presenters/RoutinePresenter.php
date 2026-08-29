<?php

declare(strict_types=1);

namespace Padosoft\Routines\Http\Presenters;

use Padosoft\Routines\Contracts\Target\TargetDescriptor;
use Padosoft\Routines\Http\Support\CronDescriber;
use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\Models\RoutineRun;
use Padosoft\Routines\RoutineManager;
use Padosoft\Routines\Scheduling\RoutineScheduler;
use Padosoft\Routines\Targets\TargetRegistry;

/**
 * La forma con cui una routine esce dall'API.
 *
 * Ogni campo `*_label` e `*_human` esiste per una ragione precisa: **il testo leggibile lo compone
 * il server**, non il client. Un pannello che traduce da sé `target_not_registered` in una frase
 * italiana la tradurrà diversamente dal comando CLI, dall'email di notifica e dall'audit — e a quel
 * punto tre persone che guardano lo stesso evento leggono tre cose diverse.
 */
final class RoutinePresenter
{
    public function __construct(
        private readonly TargetRegistry $registry,
        private readonly RoutineScheduler $scheduler,
        private readonly RoutineManager $manager,
    ) {}

    /** @return array<string, mixed> */
    public function summary(Routine $routine): array
    {
        $descriptor = $this->descriptor($routine->target_type);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return [
            'id' => $routine->id,
            'name' => $routine->name,
            'description' => $routine->description,
            'owner' => $routine->owner,
            'owner_label' => $this->ownerLabel($routine->owner),
            'organization_id' => $routine->organization_id,
            'status' => $routine->status,
            'suspension_reason' => $routine->suspension_reason,
            'suspension_reason_label' => $this->suspensionLabel($routine),
            'ended_reason' => $routine->ended_reason,
            'target_type' => $routine->target_type,
            'target_label' => $descriptor === null ? $routine->target_type : $descriptor->label,
            'target_icon' => $descriptor?->icon,
            'trigger_kind' => $routine->trigger_kind,
            'cron' => $routine->cron,
            'schedule_human' => $this->scheduleHuman($routine),
            'timezone' => $routine->timezone,
            'next_run_at' => $routine->next_run_at?->toIso8601String(),
            'is_overdue' => $routine->next_run_at !== null
                && $routine->statusEnum()->isRunnable()
                && $routine->next_run_at->toDateTimeImmutable() < $now->modify('-2 minutes'),
            'last_fired_at' => $routine->last_fired_at?->toIso8601String(),
            'last_outcome' => $this->lastOutcome($routine),
            'runs_24h' => $this->runsIn($routine, 24),
            'success_rate_7d' => $this->successRate($routine, 7),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Routine $routine): array
    {
        return array_merge($this->summary($routine), [
            'target_payload' => $routine->target_payload ?? [],
            'once_at' => $routine->once_at?->toIso8601String(),
            'event_name' => $routine->event_name,
            'overlap_policy' => $routine->overlap_policy,
            'missed_run_policy' => $routine->missed_run_policy,
            'max_attempts' => $routine->max_attempts,
            'timeout_seconds' => $routine->timeout_seconds,
            'budget_per_run' => $routine->budget_per_run,
            'budget_per_period' => $routine->budget_per_period,
            'budget_period' => $routine->budget_period,
            'budget_used_period' => $this->budgetUsed($routine),
            'currency' => $routine->currency,
            'initiation' => $routine->initiation,
            'created_by' => $routine->created_by,
            'created_at' => $routine->created_at?->toIso8601String(),
            'updated_at' => $routine->updated_at?->toIso8601String(),
            'mandate' => $this->mandate($routine),
            'next_occurrences' => $this->occurrences($routine, 5),
        ]);
    }

    /**
     * Le prossime occorrenze, con la sigla del fuso e l'avviso sul cambio di ora legale.
     *
     * `dst_transition` marca l'occorrenza in cui l'offset cambia rispetto alla precedente: è il
     * momento in cui l'orario UTC di esecuzione si sposta di un'ora **pur restando lo stesso
     * orario locale**. Nessun altro prodotto lo dice, ed è la domanda che l'utente si fa due volte
     * l'anno guardando i log.
     *
     * @return list<array<string, mixed>>
     */
    public function occurrences(Routine $routine, int $count): array
    {
        $out = [];
        $tz = new \DateTimeZone($routine->timezone);
        $cursor = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $previousOffset = null;

        // Una copia, così il calcolo non consuma il ricordo dell'ultima occorrenza eseguita della
        // routine vera (che serve alla guardia dell'ora legale nel dispatcher).
        $probe = $routine->replicate();
        $probe->last_local_occurrence = null;

        for ($i = 0; $i < $count; $i++) {
            $next = $this->scheduler->nextRunAt($probe, $cursor);
            if ($next === null) {
                break;
            }
            $local = $next->setTimezone($tz);
            $offset = $local->getOffset();

            $out[] = [
                'at' => $next->format(\DateTimeInterface::ATOM),
                'local' => $local->format('Y-m-d H:i'),
                'timezone_abbr' => $local->format('T'),
                'dst_transition' => $previousOffset !== null && $previousOffset !== $offset,
            ];

            $previousOffset = $offset;
            $cursor = $next;
        }

        return $out;
    }

    private function scheduleHuman(Routine $routine): ?string
    {
        return match ($routine->trigger_kind) {
            'cron' => CronDescriber::describe($routine->cron),
            'once_at' => $routine->once_at === null
                ? null
                : 'Una sola volta, il '.$routine->once_at->setTimezone($routine->timezone)->format('d/m/Y \a\l\l\e H:i'),
            'manual' => 'Solo quando la lanci a mano',
            'event' => $routine->event_name === null ? null : 'Quando accade «'.$routine->event_name.'»',
            'webhook' => 'Quando arriva un webhook firmato',
            default => null,
        };
    }

    /** @return array<string, mixed>|null */
    private function mandate(Routine $routine): ?array
    {
        $mandate = $routine->mandateObject();
        if ($mandate === null) {
            return null;
        }

        return [
            'digest' => $routine->mandate_digest,
            'payload_digest' => $mandate->payloadDigest,
            'payload_matches' => $this->manager->mandateCovers($routine),
            'action_classes' => $mandate->actionClasses,
            'budget_ceiling' => $mandate->budgetCeiling,
            'currency' => $mandate->currency,
            'not_after' => $mandate->notAfter?->format(\DateTimeInterface::ATOM),
            'granted_at' => $routine->mandate_granted_at?->toIso8601String(),
            'consent_aal' => $routine->consent_aal,
            'delegation_grant_id' => $routine->delegation_grant_id,
            'actor_chain' => $this->actorChain($routine),
        ];
    }

    /** @return list<array{subject: string, label: string|null}> */
    private function actorChain(Routine $routine): array
    {
        if ($routine->delegation_grant_id === null) {
            return [];
        }

        // Il pacchetto di delega, quando c'è, arricchisce questa catena con le etichette degli
        // agenti. Da solo il core sa una cosa sola, ed è vera: per conto di chi gira.
        return [['subject' => $routine->owner, 'label' => $this->ownerLabel($routine->owner)]];
    }

    private function descriptor(string $type): ?TargetDescriptor
    {
        return $this->registry->has($type) ? $this->registry->get($type)->descriptor() : null;
    }

    private function suspensionLabel(Routine $routine): ?string
    {
        $reason = $routine->suspension_reason;
        if ($reason === null) {
            return null;
        }

        return match (true) {
            $reason === 'target_not_registered' => sprintf(
                'Il bersaglio «%s» non è più installato, quindi questa routine non può essere eseguita.',
                $routine->target_type,
            ),
            $reason === 'delegation_revoked' => 'La delega con cui girava è stata revocata.',
            $reason === 'delegation_expired' => 'La delega con cui girava è scaduta.',
            $reason === 'delegation_no_broker' => 'Questa routine dichiara una delega, ma nessun modulo di delega è installato per emetterla.',
            str_starts_with($reason, 'delegation_') => 'La delega con cui girava non è più utilizzabile.',
            $reason === 'budget_exhausted' => 'Ha raggiunto il tetto di spesa configurato.',
            $reason === 'owner_disabled' => 'Il proprietario non è più abilitato.',
            default => $reason,
        };
    }

    /**
     * L'etichetta umana del proprietario.
     *
     * Il core non conosce il modello utente dell'applicazione ospite, quindi non inventa: mostra
     * l'identificativo canonico. Chi vuole l'email o il nome registra un risolutore.
     */
    private function ownerLabel(string $owner): ?string
    {
        $resolver = config('routines.owner_label_resolver');
        if (! is_callable($resolver)) {
            return null;
        }

        $label = $resolver($owner);

        return is_string($label) && $label !== '' ? $label : null;
    }

    private function lastOutcome(Routine $routine): ?string
    {
        return RoutineRun::query()
            ->where('routine_id', $routine->id)
            ->whereNotNull('outcome')
            ->latest('created_at')
            ->first()?->outcome;
    }

    private function runsIn(Routine $routine, int $hours): int
    {
        return RoutineRun::query()
            ->where('routine_id', $routine->id)
            ->where('created_at', '>=', now()->subHours($hours))
            ->count();
    }

    private function successRate(Routine $routine, int $days): ?float
    {
        $rows = RoutineRun::query()
            ->where('routine_id', $routine->id)
            ->whereNotNull('outcome')
            ->where('created_at', '>=', now()->subDays($days))
            ->get(['outcome']);

        if ($rows->isEmpty()) {
            return null;
        }

        // I saltati non contano: non sono un fallimento, e conteggiarli farebbe scendere il tasso
        // di successo di una routine che sta funzionando esattamente come configurata.
        $considered = $rows->reject(fn (RoutineRun $r): bool => $r->outcome === 'skipped');
        if ($considered->isEmpty()) {
            return null;
        }

        return round($considered->where('outcome', 'succeeded')->count() / $considered->count(), 4);
    }

    private function budgetUsed(Routine $routine): ?float
    {
        if ($routine->budget_period === null) {
            return null;
        }
        $since = match ($routine->budget_period) {
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            default => now()->startOfDay(),
        };

        return (float) RoutineRun::query()
            ->where('routine_id', $routine->id)
            ->where('created_at', '>=', $since)
            ->sum('cost');
    }
}
