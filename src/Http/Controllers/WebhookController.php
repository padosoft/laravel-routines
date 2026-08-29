<?php

declare(strict_types=1);

namespace Padosoft\Routines\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Padosoft\Routines\Contracts\Execution\FireReason;
use Padosoft\Routines\Http\Presenters\RunPresenter;
use Padosoft\Routines\Http\Support\Problem;
use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\Scheduling\RoutineDispatcher;

/**
 * L'ingresso webhook: un sistema esterno fa partire una routine.
 *
 * ## Perché questa rotta sta fuori dal guard di sessione
 *
 * La chiama una macchina, non un browser: non ha cookie, non ha CSRF e non deve averne. Al posto
 * della sessione c'è una firma HMAC-SHA256 sul corpo grezzo, con un segreto per routine. È
 * l'unica autenticazione, quindi ogni dettaglio conta:
 *
 * - **Il corpo grezzo, non quello decodificato.** Firmare il JSON riserializzato significa
 *   confrontare una firma su byte diversi da quelli ricevuti, e basta un `+` normalizzato per far
 *   fallire ogni consegna legittima — o, peggio, per farne passare una che non doveva.
 * - **`hash_equals`, mai `===`.** Un confronto che esce al primo byte diverso racconta, nei
 *   tempi, quanti byte erano giusti. Con abbastanza tentativi la firma si ricostruisce.
 * - **Una finestra temporale.** Senza, una consegna legittima intercettata resta valida per
 *   sempre: chi la registra oggi la rigioca fra un anno e la routine parte.
 * - **L'id di consegna diventa la chiave di idempotenza.** Le consegne webhook sono
 *   at-least-once per costruzione — è il mittente a garantire "almeno una", mai "esattamente
 *   una". Senza chiave, ogni ritentativo del mittente è un'esecuzione in più.
 *
 * Il fallimento di ognuno di questi punti è silenzioso: il sistema continua a funzionare, e la
 * porta resta aperta.
 */
final class WebhookController
{
    /** Oltre questa distanza dal timestamp firmato la consegna è considerata un replay. */
    private const TOLERANCE_SECONDS = 300;

    public function __construct(
        private readonly RoutineDispatcher $dispatcher,
        private readonly RunPresenter $presenter,
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $routine = Routine::query()
            ->where('id', $id)
            ->where('trigger_kind', 'webhook')
            ->first();

        // Stessa risposta per "non esiste" e "non è un webhook": distinguerle direbbe a chi prova
        // gli id quali esistono.
        if ($routine === null || $routine->webhook_secret === null) {
            return Problem::notFound('Nessuna routine webhook con questo identificativo.');
        }

        $timestamp = (int) $request->header('X-Routines-Timestamp', '0');
        $signature = (string) $request->header('X-Routines-Signature', '');
        $delivery = $request->header('X-Routines-Delivery');

        if ($signature === '' || $timestamp === 0) {
            return Problem::forbidden('Firma assente.');
        }
        if (abs(time() - $timestamp) > self::TOLERANCE_SECONDS) {
            return Problem::forbidden('Consegna troppo vecchia o troppo nel futuro: rifiutata come replay.');
        }

        try {
            $secret = Crypt::decryptString($routine->webhook_secret);
        } catch (\Throwable) {
            // Chiave dell'applicazione ruotata dopo la creazione: il segreto non è più leggibile.
            // Dirlo è meglio che restituire un 403 che manda a cercare il problema nel mittente.
            return Problem::conflict('Il segreto di questa routine non è più decifrabile: rigeneralo.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        if (! hash_equals($expected, $signature)) {
            return Problem::forbidden('Firma non valida.');
        }

        if (! $routine->statusEnum()->isRunnable()) {
            // 409 e non 403: la firma era giusta, e chi chiama deve poter distinguere «non sei
            // autorizzato» da «è ferma».
            return Problem::conflict('Questa routine non è attiva.');
        }

        $payload = $request->json()->all();

        $run = $this->dispatcher->fireNow(
            $routine,
            is_array($payload) ? $payload : [],
            FireReason::Webhook,
            is_string($delivery) && $delivery !== '' ? 'wh:'.$delivery : null,
        );

        return $run === null
            ? Problem::conflict('Non è stato possibile avviare questa routine.')
            : new JsonResponse(['data' => $this->presenter->summary($run)], 202);
    }
}
