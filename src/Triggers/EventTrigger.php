<?php

declare(strict_types=1);

namespace Padosoft\Routines\Triggers;

use Padosoft\Routines\Contracts\Execution\FireReason;
use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\Models\RoutineRun;
use Padosoft\Routines\Scheduling\RoutineDispatcher;

/**
 * Fa partire le routine in ascolto su un evento dell'applicazione.
 *
 * ## La chiave di idempotenza qui è una decisione, non un dettaglio
 *
 * Un evento non ha un'occorrenza schedulata da cui derivare la chiave, e non ha nemmeno un id di
 * consegna come un webhook. Quindi ogni emissione dell'evento è un fatto distinto e riceve una
 * chiave nuova: due `OrderPlaced` sono due ordini, e deduplicarli sarebbe **peggio** che eseguirli
 * due volte — significherebbe non spedirne uno.
 *
 * Chi ha un evento che *può* arrivare due volte per lo stesso fatto (una consegna at-least-once da
 * una coda) espone un `idempotencyKey()` sull'evento, e allora la deduplicazione avviene. È il
 * mittente a sapere se due emissioni sono lo stesso fatto: qui non c'è modo di indovinarlo.
 */
final class EventTrigger
{
    public function __construct(private readonly RoutineDispatcher $dispatcher) {}

    /**
     * @param  object|array<string, mixed>  $payload
     * @return list<RoutineRun>
     */
    public function handle(string $eventName, object|array $payload = []): array
    {
        $routines = Routine::query()
            ->where('status', 'active')
            ->where('trigger_kind', 'event')
            ->where('event_name', $eventName)
            ->get();

        $runs = [];
        foreach ($routines as $routine) {
            $run = $this->dispatcher->fireNow(
                $routine,
                $this->input($payload),
                FireReason::Event,
                $this->idempotencyKey($payload),
            );
            if ($run !== null) {
                $runs[] = $run;
            }
        }

        return $runs;
    }

    /**
     * L'evento come input del fire.
     *
     * Solo valori scalari e strutture semplici: un oggetto evento può contenere un model intero
     * (con relazioni, e talvolta con dati che non devono uscire), e questo input finisce nel
     * ledger. Chi vuole di più lo mette in un array esplicito.
     *
     * @param  object|array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function input(object|array $payload): array
    {
        if (is_array($payload)) {
            return $this->stringKeyed($payload);
        }
        if (method_exists($payload, 'toRoutineInput')) {
            $custom = $payload->toRoutineInput();

            return is_array($custom) ? $this->stringKeyed($custom) : [];
        }

        $out = [];
        foreach (get_object_vars($payload) as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    private function stringKeyed(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** @param object|array<string, mixed> $payload */
    private function idempotencyKey(object|array $payload): ?string
    {
        if (is_object($payload) && method_exists($payload, 'idempotencyKey')) {
            $key = $payload->idempotencyKey();

            return is_string($key) && $key !== '' ? $key : null;
        }

        // null = chiave nuova. Vedi il docblock: due emissioni sono due fatti, salvo che il
        // mittente dica il contrario.
        return null;
    }
}
