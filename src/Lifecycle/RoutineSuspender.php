<?php

declare(strict_types=1);

namespace Padosoft\Routines\Lifecycle;

use Illuminate\Contracts\Events\Dispatcher;
use Padosoft\Routines\Contracts\Lifecycle\RoutineLifecycle;
use Padosoft\Routines\Events\RoutineSuspended;
use Padosoft\Routines\Models\Routine;

/**
 * L'implementazione del porto di sospensione: è ciò che permette a un componente ESTERNO
 * (l'anomaly detector di rebel-ai-guard, oggi) di fermare una routine senza dipendere da questo
 * pacchetto né conoscere il modello.
 *
 * Il motivo viene prefissato con l'attore. Una sospensione dice a chi la legge due cose diverse —
 * COSA è successo e CHI ha deciso — e tenerle separate conta: «anomalia rilevata» da un detector
 * automatico si tratta diversamente da «anomalia rilevata» scritta da una persona, e chi apre il
 * pannello alle 9:00 deve poterlo capire senza cercare altrove.
 *
 * Una routine inesistente non è un errore: un detector che gira su una finestra di ieri può
 * incontrare una routine cancellata nel frattempo, e far fallire il rilevamento per questo
 * significherebbe perdere anche le anomalie delle altre.
 */
final readonly class RoutineSuspender implements RoutineLifecycle
{
    public function __construct(private Dispatcher $events) {}

    public function suspend(string $routineId, string $reason, string $actor): void
    {
        $routine = Routine::findById($routineId);
        if ($routine === null) {
            return;
        }

        $routine->suspend($actor.':'.$reason);
        $this->events->dispatch(new RoutineSuspended($routine, $reason, 'sospesa da '.$actor));
    }
}
