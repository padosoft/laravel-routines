<?php

declare(strict_types=1);

namespace Padosoft\Routines\Events;

use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\Models\RoutineRun;

/**
 * Un umano ha risposto a un fire fermo.
 *
 * Separato da `RoutineFinished` perché sono due fatti diversi, e confonderli fa perdere quello
 * che conta. Sul ramo dell'approvazione il fire riprende e finisce dopo — a volte molto dopo, a
 * volte fallendo: chi ascolta per registrare la DECISIONE («Anna, alle 9:14, ha approvato la
 * chiusura della fattura INV-003») non deve aspettare l'esito del lavoro, e non deve dedurla da
 * un evento che parla d'altro. È anche la prova che l'art. 14 dell'AI Act chiede: sorveglianza
 * umana esercitata, con un nome e un momento.
 *
 * Emesso PRIMA della ripresa del lavoro, per lo stesso motivo.
 */
final readonly class RoutineResolved
{
    public function __construct(
        public Routine $routine,
        public RoutineRun $run,
        public bool $approved,
        public string $resolvedBy,
        public string $note = '',
    ) {}
}
