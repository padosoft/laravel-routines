<?php

declare(strict_types=1);

namespace Padosoft\Routines\Events;

use Padosoft\Routines\Contracts\Execution\RoutineExecution;
use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\Models\RoutineRun;

/** Sta per partire. Emesso PRIMA del lavoro: chi ascolta può ancora fermarlo lanciando. */
final readonly class RoutineFired
{
    public function __construct(
        public Routine $routine,
        public RoutineRun $run,
        public RoutineExecution $execution,
    ) {}
}
