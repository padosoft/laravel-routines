<?php

declare(strict_types=1);

namespace Padosoft\Routines\Escalation;

use Padosoft\Routines\Contracts\Escalation\RoutineEscalation;
use Padosoft\Routines\Contracts\Escalation\RoutineEscalator;
use Psr\Log\LoggerInterface;

/**
 * L'escalator predefinito: scrive la domanda nel log a livello **warning**, e non lancia.
 *
 * Non è un no-op, ed è deliberato. Un no-op silenzioso produrrebbe il fallimento peggiore di tutto
 * il sistema: una routine ferma che aspetta la risposta a una domanda che nessuno ha mai ricevuto,
 * senza una riga da nessuna parte che lo dica. Un warning nel log almeno lascia una traccia a chi
 * andrà a cercare.
 *
 * Non lancia perché la domanda **resta comunque visibile nel pannello**: il canale è il modo veloce
 * di raggiungere una persona, non l'unico. Far fallire il fire perché non c'era un canale
 * configurato trasformerebbe una notifica mancata in un errore, che non è.
 */
final class LoggingEscalator implements RoutineEscalator
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function escalate(RoutineEscalation $escalation): void
    {
        $this->logger->warning('[routines] una routine si è fermata e sta aspettando una risposta', [
            'routine_id' => $escalation->routine->id,
            'routine' => $escalation->routine->name,
            'run_id' => $escalation->runId,
            'owner' => $escalation->owner,
            'action_class' => $escalation->actionClass,
            'question' => $escalation->question,
            'nota' => 'Nessun canale di escalation configurato: la richiesta è visibile solo nel pannello.',
        ]);
    }
}
