<?php

declare(strict_types=1);

namespace Padosoft\Routines\Scheduling;

use Cron\CronExpression;
use Padosoft\Routines\Models\Routine;

/**
 * Calcola QUANDO una routine deve girare.
 *
 * Tre decisioni che questa classe incarna, e che sono la ragione per cui esiste separata dal
 * dispatcher:
 *
 * 1. **Si schedula su una data, non su un tick.** Il prossimo fire è un istante persistito
 *    (`next_run_at`), e il dispatcher chiede "cosa è scaduto", non "quale cron corrisponde a questo
 *    minuto". Il cron di sistema salta — deploy, restart, worker morto — e chi calcola dal tick
 *    perde esecuzioni in silenzio, che è il modo peggiore di perderle.
 *
 * 2. **Si calcola nel fuso del proprietario.** "Ogni giorno alle 6" in UTC è sbagliato per metà
 *    anno per chiunque non viva a Greenwich.
 *
 * 3. **L'ora legale ha due trappole, e sono diverse fra loro.** In primavera un orario locale NON
 *    ESISTE: cron-expression salta all'occorrenza valida successiva, ed è il comportamento giusto.
 *    In autunno un orario locale ESISTE DUE VOLTE, in due istanti UTC distinti — e lì il calcolo
 *    "prossimo dopo l'ultimo" produrrebbe due fire per la stessa occorrenza. Per questo teniamo
 *    l'ultima occorrenza LOCALE eseguita e saltiamo quella ripetuta. Succede due volte l'anno e
 *    nessuno se lo ricorda: per questo è pinnato da un test.
 */
final class RoutineScheduler
{
    /**
     * Il prossimo istante (UTC) in cui la routine deve girare, o null se non ne ha uno.
     *
     * @param  \DateTimeImmutable|null  $after  calcola dopo questo istante; default: adesso
     */
    public function nextRunAt(Routine $routine, ?\DateTimeImmutable $after = null): ?\DateTimeImmutable
    {
        $after ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return match ($routine->trigger_kind) {
            'cron' => $this->nextCronRun($routine, $after),
            'once_at' => $this->nextOnceRun($routine, $after),
            // manual, event, webhook non hanno un prossimo run: partono quando qualcosa li chiama.
            default => null,
        };
    }

    /**
     * L'occorrenza locale (Y-m-d H:i) a cui corrisponde un istante UTC, nel fuso della routine.
     *
     * È la chiave con cui si riconosce un'occorrenza già eseguita quando l'ora legale la ripete.
     */
    public function localOccurrence(Routine $routine, \DateTimeImmutable $instant): string
    {
        return $instant
            ->setTimezone(new \DateTimeZone($routine->timezone))
            ->format('Y-m-d H:i');
    }

    /**
     * Le occorrenze PERSE fra `next_run_at` e adesso — quelle passate mentre il sistema era fermo.
     *
     * Serve a distinguere "sono in ritardo di un fire" da "sono in ritardo di duecento": una
     * routine ogni 5 minuti ferma per un giorno ha 288 occorrenze perse, e recuperarle tutte
     * significa mandare 288 email. Chi chiama decide cosa farne in base alla MissedRunPolicy;
     * questa funzione si limita a dire quante e quali, con un tetto per non esplodere in memoria.
     *
     * @return list<\DateTimeImmutable>
     */
    public function missedOccurrences(Routine $routine, \DateTimeImmutable $now, int $cap = 100): array
    {
        if ($routine->trigger_kind !== 'cron' || ! is_string($routine->cron) || $routine->cron === '') {
            return [];
        }
        $from = $routine->next_run_at?->toDateTimeImmutable();
        if ($from === null || $from > $now) {
            return [];
        }

        $missed = [];
        $cursor = $from;
        $expression = new CronExpression($routine->cron);
        $tz = new \DateTimeZone($routine->timezone);

        // La prima è `next_run_at` stessa: è già scaduta per definizione.
        $missed[] = $cursor;

        while (count($missed) < $cap) {
            $next = \DateTimeImmutable::createFromMutable(
                $expression->getNextRunDate($cursor->setTimezone($tz), 0, false)
            )->setTimezone(new \DateTimeZone('UTC'));

            if ($next > $now) {
                break;
            }
            $missed[] = $next;
            $cursor = $next;
        }

        return $missed;
    }

    private function nextCronRun(Routine $routine, \DateTimeImmutable $after): ?\DateTimeImmutable
    {
        if (! is_string($routine->cron) || $routine->cron === '') {
            return null;
        }
        if (! CronExpression::isValidExpression($routine->cron)) {
            return null;
        }

        $tz = new \DateTimeZone($routine->timezone);
        $expression = new CronExpression($routine->cron);
        $cursor = $after->setTimezone($tz);

        // Al massimo due salti: uno per l'occorrenza normale, uno se l'ora legale l'ha già usata.
        // Più di così vorrebbe dire un cron che genera la stessa occorrenza locale all'infinito,
        // che non esiste.
        for ($i = 0; $i < 2; $i++) {
            $next = \DateTimeImmutable::createFromMutable(
                $expression->getNextRunDate($cursor, 0, false)
            );
            $utc = $next->setTimezone(new \DateTimeZone('UTC'));

            // Ora legale, autunno: l'orario locale si ripete. Se l'abbiamo già eseguito, avanza.
            if ($routine->last_local_occurrence !== null
                && $this->localOccurrence($routine, $utc) === $routine->last_local_occurrence) {
                $cursor = $next;

                continue;
            }

            return $utc;
        }

        return null;
    }

    private function nextOnceRun(Routine $routine, \DateTimeImmutable $after): ?\DateTimeImmutable
    {
        $at = $routine->once_at?->toDateTimeImmutable();
        if ($at === null) {
            return null;
        }
        // Già eseguita: una one-shot non si ripete.
        if ($routine->last_fired_at !== null) {
            return null;
        }

        return $at->setTimezone(new \DateTimeZone('UTC'));
    }
}
