<?php

declare(strict_types=1);

namespace Padosoft\Routines\Events;

use Padosoft\Routines\Contracts\Target\TargetResult;
use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\Models\RoutineRun;

/**
 * Ha finito, comunque sia andata.
 *
 * Un solo evento per successo, fallimento e salto — e non tre — perché chi ascolta (audit, FinOps,
 * notifiche) vuole ogni esito, e con tre eventi ne dimenticherebbe uno. L'esito è nel risultato.
 */
final readonly class RoutineFinished
{
    public function __construct(
        public Routine $routine,
        public RoutineRun $run,
        public TargetResult $result,
    ) {}
}
