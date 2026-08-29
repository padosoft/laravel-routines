<?php

declare(strict_types=1);

namespace Padosoft\Routines;

use Cron\CronExpression;
use Illuminate\Support\Facades\Crypt;
use Padosoft\Routines\Contracts\Consent\RoutineMandate;
use Padosoft\Routines\Contracts\Execution\FireReason;
use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\Models\RoutineRun;
use Padosoft\Routines\Scheduling\RoutineDispatcher;
use Padosoft\Routines\Scheduling\RoutineScheduler;
use Padosoft\Routines\Targets\TargetRegistry;

/**
 * Il punto d'ingresso applicativo: creare, modificare, lanciare, fermare una routine.
 *
 * Esiste per una ragione sola, e vale la pena scriverla: **la validazione del payload avviene qui,
 * alla creazione, e non al fire**. Un `Routine::create()` diretto salterebbe il bersaglio, e la
 * routine scoprirebbe di essere rotta alle 3:00, dentro un log che nessuno legge, in un momento in
 * cui non c'è nessuno a cui dirlo. Passare da qui è ciò che sposta l'errore dal log al form.
 */
final class RoutineManager
{
    public function __construct(
        private readonly TargetRegistry $registry,
        private readonly RoutineScheduler $scheduler,
        private readonly RoutineDispatcher $dispatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidRoutine se il payload non regge, o lo schedule non è calcolabile
     */
    public function create(array $attributes): Routine
    {
        $this->assertValid($attributes);

        $routine = new Routine;
        $routine->fill($this->fillable($attributes));
        $routine->save();

        $this->refreshSchedule($routine);

        return $routine;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidRoutine
     */
    public function update(Routine $routine, array $attributes): Routine
    {
        $merged = array_merge([
            'target_type' => $routine->target_type,
            'target_payload' => $routine->target_payload,
            'trigger_kind' => $routine->trigger_kind,
            'cron' => $routine->cron,
            'once_at' => $routine->once_at,
            'timezone' => $routine->timezone,
        ], $attributes);

        $this->assertValid($merged);

        $routine->fill($this->fillable($attributes));
        $routine->save();

        // Cambiare lo schedule DEVE ricalcolare il prossimo fire: lasciarlo com'era significa che
        // l'utente ha modificato l'orario e la routine continua a girare al vecchio, in silenzio.
        $this->refreshSchedule($routine);

        return $routine;
    }

    /** Ricalcola `next_run_at` a partire da adesso. */
    public function refreshSchedule(Routine $routine, ?\DateTimeImmutable $now = null): void
    {
        if (! $routine->statusEnum()->isRunnable()) {
            return;
        }
        $routine->forceFill(['next_run_at' => $this->scheduler->nextRunAt($routine, $now)])->save();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function fireNow(Routine $routine, array $input = [], ?string $idempotencyKey = null): ?RoutineRun
    {
        return $this->dispatcher->fireNow($routine, $input, FireReason::Manual, $idempotencyKey);
    }

    /**
     * Registra il mandato con cui la routine potrà girare da sola.
     *
     * Il mandato è vincolato al **digest canonico del payload approvato**, ed è il motivo per cui
     * questo metodo lo calcola invece di accettarlo: se domani qualcuno modifica il payload, il
     * digest non corrisponde più e `mandateCovers()` dice di no. Il consenso vale per **quella**
     * configurazione, non per la routine in generale — è lo stesso principio del dynamic linking
     * PSD2, dove cambiare l'importo dopo la conferma invalida la conferma.
     *
     * @param  list<string>  $actionClasses  vuoto = il mandato non autorizza NIENTE (fail-closed:
     *                                       vedi RoutineMandate::covers)
     * @param  string|null  $confirmationId  l'evidenza della conferma step-up
     * @param  string|null  $aal  il livello di garanzia con cui è stata data
     */
    public function grantMandate(
        Routine $routine,
        array $actionClasses,
        ?float $budgetCeiling = null,
        ?\DateTimeImmutable $notAfter = null,
        ?string $delegationGrantId = null,
        ?string $confirmationId = null,
        ?string $aal = null,
    ): RoutineMandate {
        $mandate = new RoutineMandate(
            targetType: $routine->target_type,
            payloadDigest: $routine->payloadDigest(),
            actionClasses: $actionClasses,
            budgetCeiling: $budgetCeiling,
            notAfter: $notAfter,
            currency: $routine->currency,
        );

        $routine->forceFill([
            'mandate' => [
                'target_type' => $mandate->targetType,
                'payload_digest' => $mandate->payloadDigest,
                'action_classes' => $mandate->actionClasses,
                'budget_ceiling' => $mandate->budgetCeiling,
                'currency' => $mandate->currency,
                'not_after' => $mandate->notAfter?->format(\DateTimeInterface::ATOM),
            ],
            'mandate_digest' => $mandate->digest(),
            'mandate_granted_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'delegation_grant_id' => $delegationGrantId ?? $routine->delegation_grant_id,
            'consent_confirmation_id' => $confirmationId,
            'consent_aal' => $aal,
        ])->save();

        return $mandate;
    }

    /**
     * Il mandato copre ancora la configurazione attuale?
     *
     * `false` quando il payload è cambiato dopo il consenso. Non è un errore da lanciare: è uno
     * stato da **mostrare**, con l'azione per rimediare («richiedi un nuovo consenso»). Lanciare
     * qui trasformerebbe una modifica legittima in un guasto.
     */
    public function mandateCovers(Routine $routine): bool
    {
        $mandate = $routine->mandateObject();
        if ($mandate === null) {
            return true;   // nessun mandato = nessun vincolo da violare
        }

        return $mandate->payloadDigest === $routine->payloadDigest()
            && $mandate->targetType === $routine->target_type
            && ($mandate->notAfter === null || $mandate->notAfter > new \DateTimeImmutable);
    }

    /**
     * Genera (o rigenera) il segreto con cui questa routine firma i propri webhook.
     *
     * Restituisce il segreto **in chiaro una sola volta**: da quel momento esiste solo cifrato in
     * colonna. Non è un hash — per verificare un HMAC serve il segreto vero al confronto — quindi
     * il massimo ottenibile è che un dump del database non lo consegni, e che chi lo perde debba
     * rigenerarlo invece di andarselo a rileggere.
     */
    public function rotateWebhookSecret(Routine $routine): string
    {
        $secret = bin2hex(random_bytes(32));
        $routine->forceFill(['webhook_secret' => Crypt::encryptString($secret)])->save();

        return $secret;
    }

    /**
     * La firma che un mittente deve produrre. Utile a chi integra, e ai test.
     *
     * Firma `timestamp.corpo grezzo`: il timestamp dentro la firma è ciò che impedisce di
     * riutilizzare una consegna intercettata cambiando solo l'ora.
     */
    public static function webhookSignature(string $secret, int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    /**
     * La risposta di un umano a un fire fermo. Delega al dispatcher, che sa riprenderlo.
     */
    public function resolve(RoutineRun $run, bool $approved, string $resolvedBy, string $note = ''): RoutineRun
    {
        return $this->dispatcher->resolve($run, $approved, $resolvedBy, $note);
    }

    public function pause(Routine $routine): void
    {
        $routine->pause();
    }

    public function resume(Routine $routine): void
    {
        $routine->resume();
        $this->refreshSchedule($routine);
    }

    public function end(Routine $routine, string $reason = 'ended_by_user'): void
    {
        $routine->end($reason);
    }

    /**
     * La preview dei prossimi fire, nel fuso del proprietario.
     *
     * Serve alla UI, e non è un lusso: "0 6 * * 2-6" non dice niente a nessuno, mentre cinque date
     * scritte in chiaro fanno vedere subito che l'orario è sbagliato di un fuso — prima che la
     * routine giri per un mese all'ora sbagliata.
     *
     * @return list<string>
     */
    public function preview(Routine $routine, int $count = 5): array
    {
        if (! is_string($routine->cron) || $routine->cron === '' || ! CronExpression::isValidExpression($routine->cron)) {
            return [];
        }

        $out = [];
        $tz = new \DateTimeZone($routine->timezone);
        $cursor = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone($tz);
        $expression = new CronExpression($routine->cron);

        for ($i = 0; $i < $count; $i++) {
            $next = \DateTimeImmutable::createFromMutable($expression->getNextRunDate($cursor, 0, false));
            $out[] = $next->format('Y-m-d H:i');
            $cursor = $next;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidRoutine
     */
    private function assertValid(array $attributes): void
    {
        $type = $attributes['target_type'] ?? null;
        if (! is_string($type) || ! $this->registry->has($type)) {
            throw InvalidRoutine::fromErrors([
                'target_type' => [is_string($type) && $type !== ''
                    ? "Nessun bersaglio registrato per il tipo \"{$type}\"."
                    : 'Indica il tipo di bersaglio.'],
            ]);
        }

        $payload = $attributes['target_payload'] ?? [];
        $result = $this->registry->get($type)->validate(is_array($payload) ? $payload : []);
        if (! $result->valid) {
            throw InvalidRoutine::fromValidation($result);
        }

        $this->assertSchedule($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidRoutine
     */
    private function assertSchedule(array $attributes): void
    {
        $kind = $attributes['trigger_kind'] ?? 'cron';
        $tz = $attributes['timezone'] ?? 'UTC';

        if (is_string($tz) && ! in_array($tz, timezone_identifiers_list(), true)) {
            throw InvalidRoutine::fromErrors(['timezone' => ["Fuso orario sconosciuto: \"{$tz}\"."]]);
        }

        if ($kind === 'cron') {
            $cron = $attributes['cron'] ?? null;
            if (! is_string($cron) || $cron === '' || ! CronExpression::isValidExpression($cron)) {
                throw InvalidRoutine::fromErrors(['cron' => ['Espressione cron non valida.']]);
            }
        }

        if ($kind === 'once_at' && ($attributes['once_at'] ?? null) === null) {
            throw InvalidRoutine::fromErrors(['once_at' => ["Indica l'istante di esecuzione."]]);
        }

        if ($kind === 'event' && ! is_string($attributes['event_name'] ?? null)) {
            throw InvalidRoutine::fromErrors(['event_name' => ["Indica l'evento che fa partire la routine."]]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function fillable(array $attributes): array
    {
        // `status` non è qui apposta: si passa da pause()/suspend()/resume()/end(), che hanno
        // regole diverse fra loro. Vedi Routine.
        unset($attributes['status'], $attributes['next_run_at'], $attributes['lock_token'], $attributes['locked_until']);

        return $attributes;
    }
}
