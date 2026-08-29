<?php

declare(strict_types=1);

namespace Padosoft\Routines\Support;

/**
 * Letture tipizzate della configurazione.
 *
 * `config()` restituisce `mixed`, e un `(int) config(...)` sparso per il codice non e' solo brutto
 * per l'analizzatore: nasconde che quel valore arriva da un file che qualcuno puo' scrivere a mano.
 * Un `'lock_seconds' => 'novecento'` diventerebbe `0` in silenzio - cioe' nessun lock.
 *
 * Qui il default vince su qualsiasi valore non utilizzabile, e la conversione e' esplicita.
 */
final class Cfg
{
    public static function int(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function string(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public static function bool(string $key, bool $default): bool
    {
        $value = config($key, $default);

        return is_bool($value) ? $value : $default;
    }

    /**
     * @return array<int|string, mixed>
     */
    public static function array(string $key): array
    {
        $value = config($key, []);

        return is_array($value) ? $value : [];
    }

    /**
     * Le classi dichiarate in configurazione, filtrate a quelle che esistono davvero.
     *
     * Una classe elencata ma inesistente e' un errore di battitura in un file di config, e va
     * scartata qui: piu' avanti diventerebbe un fatal in un fire delle 3:00.
     *
     * @return list<class-string>
     */
    public static function classList(string $key): array
    {
        $out = [];
        foreach (self::array($key) as $value) {
            if (is_string($value) && $value !== '' && class_exists($value)) {
                $out[] = $value;
            }
        }

        return $out;
    }
}
