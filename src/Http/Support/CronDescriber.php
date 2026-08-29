<?php

declare(strict_types=1);

namespace Padosoft\Routines\Http\Support;

/**
 * Traduce un'espressione cron in una frase italiana.
 *
 * Esiste perché `0 6 * * 1-5` non dice niente a nessuno, e la persona che sta creando una routine
 * deve poter verificare di aver scritto quello che intendeva **prima** che giri per un mese
 * all'orario sbagliato. È la stessa ragione dell'anteprima delle prossime occorrenze: due modi
 * diversi di rileggere la stessa cosa, e insieme prendono quasi tutti gli errori.
 *
 * Copre i pattern che la gente scrive davvero. Per un'espressione esotica torna `null`, e la UI
 * mostra il cron grezzo: **una frase sbagliata sarebbe peggio di nessuna frase** — darebbe fiducia
 * a una configurazione che non fa quello che sembra.
 */
final class CronDescriber
{
    private const GIORNI = [
        0 => 'domenica', 1 => 'lunedì', 2 => 'martedì', 3 => 'mercoledì',
        4 => 'giovedì', 5 => 'venerdì', 6 => 'sabato', 7 => 'domenica',
    ];

    private const MESI = [
        1 => 'gennaio', 2 => 'febbraio', 3 => 'marzo', 4 => 'aprile', 5 => 'maggio', 6 => 'giugno',
        7 => 'luglio', 8 => 'agosto', 9 => 'settembre', 10 => 'ottobre', 11 => 'novembre', 12 => 'dicembre',
    ];

    public static function describe(?string $cron): ?string
    {
        if (! is_string($cron) || trim($cron) === '') {
            return null;
        }

        $parts = preg_split('/\s+/', trim($cron)) ?: [];
        if (count($parts) !== 5) {
            return null;
        }
        [$min, $hour, $dom, $mon, $dow] = $parts;

        $when = self::time($min, $hour);
        if ($when === null) {
            return null;
        }

        $days = self::days($dom, $mon, $dow);

        return $days === null ? null : ucfirst(trim($days.' '.$when));
    }

    /** La parte oraria: "alle 06:00", "ogni ora", "ogni 15 minuti". */
    private static function time(string $min, string $hour): ?string
    {
        if ($min === '*' && $hour === '*') {
            return 'ogni minuto';
        }
        if (preg_match('/^\*\/(\d+)$/', $min, $m) && $hour === '*') {
            return 'ogni '.$m[1].' minuti';
        }
        if (ctype_digit($min) && $hour === '*') {
            return $min === '0' ? 'ogni ora' : 'ogni ora al minuto '.(int) $min;
        }
        if (ctype_digit($min) && preg_match('/^\*\/(\d+)$/', $hour, $m)) {
            return sprintf('ogni %d ore al minuto %d', (int) $m[1], (int) $min);
        }
        if (ctype_digit($min) && ctype_digit($hour)) {
            return sprintf('alle %02d:%02d', (int) $hour, (int) $min);
        }
        // Elenco di ore: "0 8,13,18 * * *"
        if (ctype_digit($min) && preg_match('/^\d+(,\d+)+$/', $hour)) {
            $ore = array_map(static fn (string $h): string => sprintf('%02d:%02d', (int) $h, (int) $min), explode(',', $hour));

            return 'alle '.self::elenco($ore);
        }

        return null;
    }

    /** La parte del calendario: "ogni giorno", "ogni lunedì", "il 1° di ogni mese". */
    private static function days(string $dom, string $mon, string $dow): ?string
    {
        $ogniMese = $mon === '*';

        if ($dom === '*' && $dow === '*') {
            return $ogniMese ? 'ogni giorno' : self::inMese($mon).' ogni giorno';
        }

        if ($dom === '*' && $dow !== '*') {
            $giorni = self::weekdays($dow);
            if ($giorni === null) {
                return null;
            }

            return $ogniMese ? $giorni : $giorni.' '.self::inMese($mon);
        }

        if ($dow === '*' && $dom !== '*') {
            if (! preg_match('/^\d+(,\d+)*$/', $dom)) {
                return null;
            }
            $numeri = array_map(static fn (string $d): string => (int) $d === 1 ? '1°' : (string) (int) $d, explode(',', $dom));
            $quando = 'il '.self::elenco($numeri);

            return $ogniMese ? $quando.' di ogni mese' : $quando.' '.self::inMese($mon);
        }

        // dom e dow entrambi vincolati: in cron è un OR, ed è abbastanza controintuitivo da non
        // meritare una parafrasi. Meglio il cron grezzo.
        return null;
    }

    private static function weekdays(string $dow): ?string
    {
        if ($dow === '1-5') {
            return 'ogni giorno feriale';
        }
        if ($dow === '6,0' || $dow === '0,6' || $dow === '6-7') {
            return 'ogni sabato e domenica';
        }
        if (ctype_digit($dow)) {
            return 'ogni '.(self::GIORNI[(int) $dow] ?? '');
        }
        if (preg_match('/^\d+(,\d+)+$/', $dow)) {
            $nomi = array_map(static fn (string $d): string => self::GIORNI[(int) $d] ?? '', explode(',', $dow));

            return in_array('', $nomi, true) ? null : 'ogni '.self::elenco($nomi);
        }
        if (preg_match('/^(\d)-(\d)$/', $dow, $m)) {
            $da = self::GIORNI[(int) $m[1]] ?? null;
            $a = self::GIORNI[(int) $m[2]] ?? null;

            return ($da === null || $a === null) ? null : "da {$da} a {$a}";
        }

        return null;
    }

    private static function inMese(string $mon): string
    {
        if (ctype_digit($mon) && isset(self::MESI[(int) $mon])) {
            return 'a '.self::MESI[(int) $mon];
        }

        return 'nei mesi '.$mon;
    }

    /** @param list<string> $items */
    private static function elenco(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }
        $ultimo = array_pop($items);

        return implode(', ', $items).' e '.$ultimo;
    }
}
