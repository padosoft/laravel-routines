<?php

declare(strict_types=1);

namespace Padosoft\Routines\Events;

use Padosoft\Routines\Contracts\Target\TargetResult;
use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\Models\RoutineRun;

/**
 * Ferma, in attesa di un umano.
 *
 * È l'evento su cui si aggancia l'escalation: il fire ha incontrato qualcosa che il mandato non
 * copre e si è fermato invece di procedere. Chi ascolta manda la richiesta di approvazione sul
 * canale del proprietario. Senza un ascoltatore, la routine resta ferma — che è il fallimento
 * giusto: non agisce senza permesso.
 */
final readonly class RoutinePaused
{
    public function __construct(
        public Routine $routine,
        public RoutineRun $run,
        public TargetResult $result,
    ) {}
}
