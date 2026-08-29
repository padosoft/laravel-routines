<?php

declare(strict_types=1);

namespace Padosoft\Routines\Http\Presenters;

use Padosoft\Routines\Http\Support\Permissions;
use Padosoft\Routines\Models\RoutineRun;

/**
 * La forma con cui un fire esce dall'API.
 *
 * `message` esce **così com'è**: è il testo che il bersaglio ha scritto per una persona, e per un
 * fire in pausa è l'evidenza di cosa quella persona sta approvando. Riscriverlo, accorciarlo o
 * abbellirlo lo farebbe divergere da quello che è stato approvato, e a quel punto l'approvazione
 * non prova più niente.
 */
final class RunPresenter
{
    /** @return array<string, mixed> */
    public function summary(RoutineRun $run): array
    {
        // Accesso diretto e senza fallback: la foreign key con cascade garantisce che un run
        // abbia sempre la sua routine, e l'analizzatore lo sa. Un `?->` con default qui non
        // difenderebbe da niente - segnalerebbe solo un dubbio che lo schema non lascia.
        return [
            'id' => $run->id,
            'routine_id' => $run->routine_id,
            'routine_name' => $run->routine->name,
            'reason' => $run->reason,
            'outcome' => $run->outcome,
            'attempt' => $run->attempt,
            'max_attempts' => $run->routine->max_attempts,
            'scheduled_for' => $run->scheduled_for?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'duration_ms' => $run->durationMs(),
            'message' => $run->message,
            'cost' => $run->cost,
            'currency' => $run->routine->currency,
            'retry_at' => $run->retry_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(RoutineRun $run): array
    {
        return array_merge($this->summary($run), [
            'idempotency_key' => $run->idempotency_key,
            'correlation_id' => $run->correlation_id,
            'external_ref' => $run->external_ref,
            'external_url' => $this->externalUrl($run),
            'metadata' => $run->metadata,
            'pending_approval_id' => $run->pending_approval_id,
            'action_class' => $run->action_class,
            'question' => $run->question,
            'escalated_at' => $run->escalated_at?->toIso8601String(),
            'escalation_error' => $run->escalation_error,
            'resolved_by' => $run->resolved_by,
            'resolved_at' => $run->resolved_at?->toIso8601String(),
            'resolution_note' => $run->resolution_note,
            'owner_label' => $run->routine->owner,
            'can_approve' => $run->isAwaitingHuman() && Permissions::allows(Permissions::APPROVE),
        ]);
    }

    /**
     * Il link al lavoro nel dominio del bersaglio, se qualcuno sa costruirlo.
     *
     * Il core non lo sa — non conosce i flow, i job o gli ordini — quindi non indovina: se
     * l'applicazione registra un risolutore, il pannello mostra un link; altrimenti mostra il
     * riferimento grezzo, che è comunque copiabile.
     */
    private function externalUrl(RoutineRun $run): ?string
    {
        $resolver = config('routines.external_url_resolver');
        if (! is_callable($resolver) || $run->external_ref === null) {
            return null;
        }

        $url = $resolver($run->routine->target_type, $run->external_ref);

        return is_string($url) && $url !== '' ? $url : null;
    }
}
