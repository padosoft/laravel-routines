<?php

declare(strict_types=1);

namespace Padosoft\Routines\Events;

use Padosoft\Routines\Models\Routine;

/** Il sistema l'ha fermata: bersaglio sparito, budget esaurito, anomalia, proprietario disabilitato. */
final readonly class RoutineSuspended
{
    public function __construct(
        public Routine $routine,
        public string $reason,
        public string $detail = '',
    ) {}
}
