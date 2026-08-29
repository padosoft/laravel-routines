<?php

declare(strict_types=1);

namespace Padosoft\Routines\Events;

use Padosoft\Routines\Contracts\Consent\RoutineMandate;
use Padosoft\Routines\Models\Routine;

/**
 * Il consenso permanente è stato concesso (o rinnovato dopo una modifica del payload).
 *
 * È il momento in cui una persona ha deciso che cosa questa routine può fare da sola quando non
 * c'è nessuno — la decisione di sorveglianza più importante del sistema, ed è quella che finora
 * non lasciava traccia da nessuna parte se non nella riga della routine. Chi ascolta lo registra:
 * l'audit, il registro dei rischi, il tracker di sorveglianza umana.
 *
 * `confirmationId` e `aal` sono l'evidenza, non la decorazione: senza, «l'utente aveva
 * acconsentito» è un'affermazione e non un fatto verificabile.
 */
final readonly class RoutineMandateGranted
{
    public function __construct(
        public Routine $routine,
        public RoutineMandate $mandate,
        public ?string $confirmationId = null,
        public ?string $aal = null,
    ) {}
}
