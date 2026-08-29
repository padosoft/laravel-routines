<?php

declare(strict_types=1);

namespace Padosoft\Routines\Http\Support;

use Illuminate\Support\Facades\Gate;

/**
 * Le ability dell'API, risolte dal Gate dell'applicazione ospite.
 *
 * **Fail-closed**: un'ability senza policy definita è negata, tranne `routines.read` che resta il
 * default. Un'applicazione che monta l'API senza definire nulla ottiene un pannello in sola
 * lettura — che è il default giusto per uno strumento che fa partire automazioni: il costo di
 * doverne definire le policy è un fastidio, il costo del contrario è un'automazione avviata da chi
 * non doveva.
 */
final class Permissions
{
    public const READ = 'routines.read';

    public const WRITE = 'routines.write';

    public const FIRE = 'routines.fire';

    public const APPROVE = 'routines.approve';

    public const ALL = [self::READ, self::WRITE, self::FIRE, self::APPROVE];

    public static function allows(string $ability): bool
    {
        if (! Gate::has($ability)) {
            return $ability === self::READ;
        }

        return Gate::allows($ability);
    }

    /** @return list<string> */
    public static function granted(): array
    {
        return array_values(array_filter(self::ALL, self::allows(...)));
    }
}
