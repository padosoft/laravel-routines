<?php

declare(strict_types=1);

namespace Padosoft\Routines\Http\Controllers;

use Cron\CronExpression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\AiFinOps\FinOps;
use Padosoft\Routines\Contracts\Delegation\RoutineDelegationBroker;
use Padosoft\Routines\Contracts\Escalation\RoutineEscalator;
use Padosoft\Routines\Contracts\Target\RoutineTarget;
use Padosoft\Routines\Delegation\NullDelegationBroker;
use Padosoft\Routines\Escalation\LoggingEscalator;
use Padosoft\Routines\Http\Support\CronDescriber;
use Padosoft\Routines\Http\Support\Permissions;
use Padosoft\Routines\Http\Support\Problem;
use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\Scheduling\RoutineScheduler;
use Padosoft\Routines\Support\Cfg;
use Padosoft\Routines\Targets\TargetRegistry;

/**
 * Cosa c'e', cosa si puo' fare, e quando gira.
 *
 * `/capabilities` esiste perche' il pannello deve poter **nascondere cio' che non c'e'** invece di
 * mostrare una scheda "Mandato" vuota in un'installazione senza modulo di delega. Un'interfaccia
 * che promette una funzione assente e' peggio di una che non la mostra.
 */
final class MetaController
{
    public function __construct(
        private readonly TargetRegistry $registry,
        private readonly RoutineScheduler $scheduler,
    ) {}

    public function capabilities(): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'version' => '1.0',
                // Installato = c'e' un'implementazione VERA, non il default che lancia.
                'delegation' => ! app(RoutineDelegationBroker::class) instanceof NullDelegationBroker,
                'budgets' => class_exists(FinOps::class),
                'channels' => ! app(RoutineEscalator::class) instanceof LoggingEscalator,
                'approvals' => Permissions::allows(Permissions::APPROVE),
                'timezone' => Cfg::string('app.timezone', 'UTC'),
                'currency' => Cfg::string('routines.defaults.currency', 'EUR'),
                'can' => Permissions::granted(),
            ],
        ]);
    }

    /** I descrittori del registro: e' con questi che il pannello disegna il form di un tipo che non conosce. */
    public function targets(): JsonResponse
    {
        if (! Permissions::allows(Permissions::READ)) {
            return Problem::forbidden('Non hai il permesso di vedere i bersagli.');
        }

        $counts = $this->routinesPerTarget();

        $data = [];
        foreach ($this->registry->all() as $type => $target) {
            $data[] = $this->describe($type, $target, $counts[$type] ?? 0);
        }

        // I tipi USATI ma non registrati vanno mostrati: sono la causa numero uno delle
        // sospensioni, e nasconderli lascerebbe la schermata Salute senza la sua risposta.
        foreach ($counts as $type => $count) {
            if (! $this->registry->has((string) $type)) {
                $data[] = [
                    'type' => (string) $type,
                    'label' => (string) $type,
                    'summary' => 'Questo bersaglio non è registrato: il pacchetto che lo forniva non è installato.',
                    'icon' => null,
                    'fields' => (object) [],
                    'action_classes' => [],
                    'supports_pause' => false,
                    'reports_cost' => false,
                    'registered' => false,
                    'routines_count' => $count,
                ];
            }
        }

        return new JsonResponse(['data' => $data]);
    }

    /**
     * L'anteprima delle prossime esecuzioni, senza bisogno di una routine esistente.
     *
     * Serve nel wizard, mentre l'utente sta ancora scrivendo: e' li' che l'anteprima previene un
     * errore, non dopo aver salvato.
     */
    public function preview(Request $request): JsonResponse
    {
        $cron = $request->input('cron');
        $timezone = $request->input('timezone', Cfg::string('app.timezone', 'UTC'));
        $rawCount = $request->input('count', 5);
        $count = min(20, max(1, is_numeric($rawCount) ? (int) $rawCount : 5));

        if (! is_string($timezone) || ! in_array($timezone, timezone_identifiers_list(), true)) {
            return Problem::validation('Fuso orario sconosciuto.', ['timezone' => ['Questo fuso non esiste.']]);
        }
        if (! is_string($cron) || ! CronExpression::isValidExpression($cron)) {
            return Problem::validation('Espressione cron non valida.', [
                'cron' => ['Non riesco a leggere «'.(is_string($cron) ? $cron : '').'» come espressione cron.'],
            ]);
        }

        $probe = new Routine;
        $probe->forceFill(['trigger_kind' => 'cron', 'cron' => $cron, 'timezone' => $timezone]);

        $occurrences = [];
        $cursor = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $tz = new \DateTimeZone($timezone);
        $previousOffset = null;

        for ($i = 0; $i < $count; $i++) {
            $next = $this->scheduler->nextRunAt($probe, $cursor);
            if ($next === null) {
                break;
            }
            $local = $next->setTimezone($tz);
            $occurrences[] = [
                'at' => $next->format(\DateTimeInterface::ATOM),
                'local' => $local->format('Y-m-d H:i'),
                'timezone_abbr' => $local->format('T'),
                'dst_transition' => $previousOffset !== null && $previousOffset !== $local->getOffset(),
            ];
            $previousOffset = $local->getOffset();
            $cursor = $next;
        }

        return new JsonResponse([
            'data' => [
                'schedule_human' => CronDescriber::describe($cron),
                'timezone' => $timezone,
                'occurrences' => $occurrences,
            ],
        ]);
    }

    /**
     * Quante routine puntano a ciascun tipo di bersaglio.
     *
     * @return array<string, int>
     */
    private function routinesPerTarget(): array
    {
        $out = [];
        foreach (Routine::query()->selectRaw('target_type, count(*) as aggregate')->groupBy('target_type')->get() as $row) {
            $type = $row->getAttribute('target_type');
            $count = $row->getAttribute('aggregate');
            if (is_string($type)) {
                $out[$type] = is_numeric($count) ? (int) $count : 0;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function describe(string $type, RoutineTarget $target, int $count): array
    {
        $d = $target->descriptor();

        return [
            'type' => $type,
            'label' => $d->label,
            'summary' => $d->summary,
            'icon' => $d->icon,
            'fields' => $d->fields === [] ? (object) [] : $d->fields,
            'action_classes' => $d->actionClasses,
            'supports_pause' => $d->supportsPause,
            'reports_cost' => $d->reportsCost,
            'registered' => true,
            'routines_count' => $count,
        ];
    }
}
