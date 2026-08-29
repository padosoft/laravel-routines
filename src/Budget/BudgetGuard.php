<?php

declare(strict_types=1);

namespace Padosoft\Routines\Budget;

use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\Models\RoutineRun;

/**
 * Il tetto di spesa di una routine, per periodo.
 *
 * ## Perché il tetto è una feature di sicurezza e non di contabilità
 *
 * Gli scope limitano **cosa** una routine può fare; il tetto limita **quanto**. Sono difese
 * diverse, e la seconda è quella che manca ovunque: una routine perfettamente autorizzata a
 * "mandare email" e chiamata mille volte da un ciclo impazzito non ha violato nessun permesso —
 * ha solo mandato mille email. Nessuna regola di autorizzazione la ferma, perché ogni singola
 * azione era permessa.
 *
 * ## Perché controlla PRIMA e non solo DOPO
 *
 * Un controllo solo a posteriori scopre lo sforamento quando i soldi sono già spesi. Il controllo
 * a monte è quello che conta; quello a valle serve a chiudere la porta quando il fire che ha
 * sforato era il primo a farlo.
 *
 * ## Perché sospende invece di saltare
 *
 * Saltare significa riprovare fra un'ora, e fra due, e fra tre — ogni volta trovando lo stesso
 * tetto superato, per tutto il resto del mese. Sospendere è dire una volta cosa è successo, e
 * lasciare che sia una persona a decidere se alzare il tetto o fermarsi lì.
 */
final class BudgetGuard
{
    /** La routine ha ancora margine per un fire? */
    public function allows(Routine $routine, ?\DateTimeImmutable $now = null): bool
    {
        return $this->remaining($routine, $now) !== 0.0;
    }

    /**
     * Quanto resta nel periodo. `null` = nessun tetto, `0.0` = esaurito.
     */
    public function remaining(Routine $routine, ?\DateTimeImmutable $now = null): ?float
    {
        $ceiling = $routine->budget_per_period;
        if ($ceiling === null || $routine->budget_period === null) {
            return null;
        }

        $spent = $this->spent($routine, $now);

        return max(0.0, (float) $ceiling - $spent);
    }

    public function spent(Routine $routine, ?\DateTimeImmutable $now = null): float
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return (float) RoutineRun::query()
            ->where('routine_id', $routine->id)
            ->where('created_at', '>=', $this->periodStart($routine, $now))
            ->sum('cost');
    }

    /**
     * L'inizio del periodo corrente, **nel fuso del proprietario**.
     *
     * Non è un dettaglio: "questo mese" per chi vive a Roma comincia due ore prima che a
     * Greenwich, e un tetto mensile calcolato in UTC si azzera nel momento sbagliato — di norma
     * mentre qualcuno sta guardando il contatore e non capisce perché è tornato a zero.
     */
    public function periodStart(Routine $routine, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $local = $now->setTimezone(new \DateTimeZone($routine->timezone));

        $start = match ($routine->budget_period) {
            'month' => $local->modify('first day of this month')->setTime(0, 0),
            'week' => $local->modify(($local->format('N') === '1' ? 'today' : 'last monday'))->setTime(0, 0),
            default => $local->setTime(0, 0),
        };

        return $start->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Il motivo leggibile, per il pannello e per chi riceve la notifica.
     *
     * Include **quando riparte**: senza, l'unica azione che rimane a chi legge è alzare il tetto,
     * che spesso non è la cosa giusta da fare.
     */
    public function exhaustedMessage(Routine $routine, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $tz = new \DateTimeZone($routine->timezone);
        $next = match ($routine->budget_period) {
            'month' => $this->periodStart($routine, $now)->setTimezone($tz)->modify('first day of next month'),
            'week' => $this->periodStart($routine, $now)->setTimezone($tz)->modify('+7 days'),
            default => $this->periodStart($routine, $now)->setTimezone($tz)->modify('+1 day'),
        };

        return sprintf(
            'Ha raggiunto il tetto di spesa di %s %s per questo %s. Riprenderà il %s, oppure alza il tetto.',
            number_format((float) $routine->budget_per_period, 2, ',', '.'),
            $routine->currency,
            match ($routine->budget_period) {
                'month' => 'mese', 'week' => 'settimana', default => 'giorno'
            },
            $next->format('d/m/Y'),
        );
    }
}
