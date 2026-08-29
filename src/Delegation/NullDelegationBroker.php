<?php

declare(strict_types=1);

namespace Padosoft\Routines\Delegation;

use Padosoft\Routines\Contracts\Consent\RoutineMandate;
use Padosoft\Routines\Contracts\Delegation\DelegatedToken;
use Padosoft\Routines\Contracts\Delegation\DelegationUnavailable;
use Padosoft\Routines\Contracts\Delegation\RoutineDelegationBroker;
use Padosoft\Routines\Contracts\Routine\RoutineRef;

/**
 * L'implementazione predefinita: **non emette niente**.
 *
 * È la scelta giusta come default, e vale la pena dire perché. Senza un modulo di delega installato
 * l'unico token che questo pacchetto potrebbe fabbricare sarebbe uno con l'autorità
 * dell'applicazione — cioè la piena autorità, per sempre, senza nessuno responsabile. Un default
 * comodo che regala esattamente ciò che tutto il progetto esiste per non regalare.
 *
 * Quindi: una routine senza delega gira **come l'applicazione**, e lo sa (`isDelegated() === false`).
 * Una routine che *dichiara* una delega e non trova chi la emetta si ferma. Sono due situazioni
 * diverse e vanno distinte: la prima è una scelta, la seconda è una configurazione rotta.
 */
final class NullDelegationBroker implements RoutineDelegationBroker
{
    public function tokenFor(RoutineRef $routine, string $grantId, ?RoutineMandate $mandate = null): DelegatedToken
    {
        throw new DelegationUnavailable(
            $grantId,
            'no_broker',
            "La routine {$routine->id} dichiara la delega {$grantId}, ma nessun modulo di delega è ".
            'installato per emetterla. Installa padosoft/laravel-iam-agents, oppure togli la delega '.
            "dalla routine perché giri con l'autorità dell'applicazione."
        );
    }

    public function isUsable(string $grantId): bool
    {
        return false;
    }
}
