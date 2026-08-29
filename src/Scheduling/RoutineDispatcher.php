<?php

declare(strict_types=1);

namespace Padosoft\Routines\Scheduling;

use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Padosoft\Routines\Budget\BudgetGuard;
use Padosoft\Routines\Contracts\Consent\MandateExceeded;
use Padosoft\Routines\Contracts\Delegation\DelegationUnavailable;
use Padosoft\Routines\Contracts\Delegation\RoutineDelegationBroker;
use Padosoft\Routines\Contracts\Escalation\RoutineEscalation;
use Padosoft\Routines\Contracts\Escalation\RoutineEscalator;
use Padosoft\Routines\Contracts\Execution\FireReason;
use Padosoft\Routines\Contracts\Execution\RoutineExecution;
use Padosoft\Routines\Contracts\Routine\MissedRunPolicy;
use Padosoft\Routines\Contracts\Routine\OverlapPolicy;
use Padosoft\Routines\Contracts\Target\TargetNotRegistered;
use Padosoft\Routines\Contracts\Target\TargetOutcome;
use Padosoft\Routines\Contracts\Target\TargetResult;
use Padosoft\Routines\Events\RoutineFinished;
use Padosoft\Routines\Events\RoutineFired;
use Padosoft\Routines\Events\RoutinePaused;
use Padosoft\Routines\Events\RoutineSuspended;
use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\Models\RoutineRun;
use Padosoft\Routines\Targets\TargetRegistry;

/**
 * Decide cosa deve girare adesso, e lo fa girare una volta sola.
 *
 * Il dispatcher è dove stanno le garanzie, e vale la pena dire quali sono e come sono ottenute,
 * perché è la parte che chiunque riscriverebbe da zero sbagliando allo stesso modo:
 *
 * - **Una routine per volta, anche con dieci worker.** Il lock è una UPDATE condizionale, non un
 *   `select` seguito da un `save`: chi vince è deciso dal database. Il TTL serve al caso che
 *   nessuno considera — il worker che muore col lock in mano — perché senza, la routine resta
 *   ferma per sempre e non c'è nessun errore da nessuna parte.
 * - **Un'occorrenza produce un fire, anche se due tick partono insieme.** Il vincolo unique su
 *   `(routine_id, idempotency_key, attempt)` fa da arbitro: il secondo inserimento fallisce e
 *   quel tick si ritira in silenzio. È una garanzia di database, non di codice, e resiste anche a
 *   una race che nessuno ha previsto.
 * - **La riga del run nasce prima del lavoro.** Un processo ucciso a metà lascia un run aperto,
 *   che è visibile e recuperabile. Scrivere a esito noto lascerebbe un'email mandata e nessuna
 *   traccia.
 * - **Il tick può saltare.** Si legge `next_run_at <= now`, mai "il cron corrisponde a questo
 *   minuto": un deploy di sei minuti non fa sparire l'occorrenza delle 6:03.
 */
final class RoutineDispatcher
{
    public function __construct(
        private readonly TargetRegistry $registry,
        private readonly RoutineScheduler $scheduler,
        private readonly Events $events,
        private readonly RoutineDelegationBroker $delegation,
        private readonly RoutineEscalator $escalator,
        private readonly BudgetGuard $budget,
        /** Per quanto un worker tiene il lock prima che sia considerato morto. */
        private readonly int $lockSeconds = 900,
        /** Quante occorrenze perse si recuperano al massimo, per non trasformare un downtime in uno sciame. */
        private readonly int $catchUpCap = 25,
        /** Base del backoff esponenziale fra i tentativi. */
        private readonly int $retryBaseSeconds = 60,
    ) {}

    /**
     * Un giro completo: le routine scadute e i tentativi da ripetere.
     *
     * @return array{fired: int, skipped: int, retried: int}
     */
    public function tick(?\DateTimeImmutable $now = null, int $limit = 100): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $stats = ['fired' => 0, 'skipped' => 0, 'retried' => 0];

        foreach ($this->dueRoutines($now, $limit) as $routine) {
            $result = $this->dispatch($routine, $now);
            $stats['fired'] += $result['fired'];
            $stats['skipped'] += $result['skipped'];
        }

        $stats['retried'] = $this->retryDue($now, $limit);

        return $stats;
    }

    /**
     * Le routine la cui ora è arrivata.
     *
     * @return Collection<int, Routine>
     */
    public function dueRoutines(\DateTimeImmutable $now, int $limit = 100)
    {
        return Routine::query()
            ->where('status', 'active')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->orderBy('next_run_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Esegui ciò che questa routine deve alla data corrente, poi rischedula.
     *
     * @return array{fired: int, skipped: int}
     */
    public function dispatch(Routine $routine, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $token = $this->acquireLock($routine, $now);
        if ($token === null) {
            // Il lock è di qualcun altro: non è un errore, è il sistema che funziona.
            return ['fired' => 0, 'skipped' => 0];
        }

        try {
            $routine->refresh();
            if (! $routine->statusEnum()->isRunnable()) {
                return ['fired' => 0, 'skipped' => 0];
            }

            // Un fire precedente ancora aperto: la politica di sovrapposizione decide.
            if ($this->hasOpenRun($routine)) {
                return $this->handleOverlap($routine, $now);
            }

            $occurrences = $this->occurrencesToRun($routine, $now);
            if ($occurrences === []) {
                $this->reschedule($routine, $now);

                return ['fired' => 0, 'skipped' => 0];
            }

            $fired = 0;
            foreach ($occurrences as $i => $occurrence) {
                $reason = $i === 0 && $occurrence >= $now->modify('-1 minute')
                    ? FireReason::Scheduled
                    : FireReason::CatchUp;

                $run = $this->openRun(
                    routine: $routine,
                    occurrence: $occurrence,
                    reason: $reason,
                    idempotencyKey: RoutineRun::scheduledKey($routine->id, $occurrence),
                    attempt: 1,
                );
                if ($run === null) {
                    // Un altro tick l'ha già aperta. Nostro compito finito.
                    continue;
                }

                $this->execute($routine, $run, $now);
                $fired++;
            }

            $this->reschedule($routine, $now);

            return ['fired' => $fired, 'skipped' => 0];
        } finally {
            $this->releaseLock($routine, $token);
        }
    }

    /**
     * "Esegui adesso", da un umano o da un evento. Salta lo schedule e non lo tocca.
     *
     * L'idempotency key è casuale per default, e deliberatamente: due click su "esegui adesso"
     * sono due intenzioni distinte. Chi ha una chiave propria (un webhook con un id di consegna)
     * la passa, ed è lì che la deduplicazione serve davvero.
     *
     * @param  array<string, mixed>  $input
     */
    public function fireNow(
        Routine $routine,
        array $input = [],
        FireReason $reason = FireReason::Manual,
        ?string $idempotencyKey = null,
        ?\DateTimeImmutable $now = null,
    ): ?RoutineRun {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if ($routine->statusEnum()->isTerminal()) {
            return null;
        }

        $run = $this->openRun(
            routine: $routine,
            occurrence: $now,
            reason: $reason,
            idempotencyKey: $idempotencyKey ?? (string) Str::ulid(),
            attempt: 1,
            input: $input,
        );
        if ($run === null) {
            // Chiave già vista: la consegna era duplicata, e questo è esattamente il caso per cui
            // il chiamante ce l'ha passata.
            return RoutineRun::query()
                ->where('routine_id', $routine->id)
                ->where('idempotency_key', $idempotencyKey)
                ->orderByDesc('attempt')
                ->first();
        }

        $this->execute($routine, $run, $now, $input);

        return $run->refresh();
    }

    /** Ritenta i fallimenti la cui attesa è finita. */
    public function retryDue(\DateTimeImmutable $now, int $limit = 100): int
    {
        $runs = RoutineRun::query()
            ->where('outcome', TargetOutcome::Failed->value)
            ->whereNotNull('retry_at')
            ->where('retry_at', '<=', $now)
            ->orderBy('retry_at')
            ->limit($limit)
            ->get();

        $count = 0;
        foreach ($runs as $failed) {
            $routine = $failed->routine;
            if (! $routine instanceof Routine || ! $routine->statusEnum()->isRunnable()) {
                $failed->forceFill(['retry_at' => null])->save();

                continue;
            }

            // Il tentativo di ripetere non deve ripetersi: si spegne la sveglia prima di suonare.
            $failed->forceFill(['retry_at' => null])->save();

            $next = $this->openRun(
                routine: $routine,
                occurrence: $failed->scheduled_for?->toDateTimeImmutable() ?? $now,
                reason: FireReason::Retry,
                idempotencyKey: $failed->idempotency_key,
                attempt: $failed->attempt + 1,
            );
            if ($next === null) {
                continue;
            }

            $this->execute($routine, $next, $now);
            $count++;
        }

        return $count;
    }

    /**
     * La risposta di un umano a un fire fermo.
     *
     * Approvare NON rifà il fire da capo: apre un nuovo tentativo con **la stessa chiave di
     * idempotenza**, così il bersaglio riconosce il lavoro come lo stesso e riprende da dove si era
     * fermato invece di ripetere ciò che aveva già fatto prima di fermarsi. Rifiutare chiude il
     * fire come `skipped` col motivo — non `failed`, perché non si è rotto niente: qualcuno ha
     * deciso di no, e quella decisione è un esito legittimo che va letto come tale nel ledger.
     *
     * @param  bool  $approved  la persona ha detto sì?
     * @param  string  $resolvedBy  chi ha risposto, in forma canonica `type:id`
     * @param  string  $note  obbligatoria sul rifiuto: qualcuno la leggerà
     */
    public function resolve(RoutineRun $run, bool $approved, string $resolvedBy, string $note = ''): RoutineRun
    {
        if ($run->outcome !== TargetOutcome::Paused->value) {
            // Già risolto, o mai stato in pausa. Rispondere due volte alla stessa domanda non deve
            // produrre due esecuzioni: la seconda risposta non fa niente.
            return $run;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if (! $approved) {
            if (trim($note) === '') {
                throw new \InvalidArgumentException('Un rifiuto senza motivo non è leggibile da chi lo troverà nel ledger.');
            }

            $run->forceFill([
                'outcome' => TargetOutcome::Skipped->value,
                'message' => 'Rifiutata da un umano: '.trim($note),
                'resolved_by' => $resolvedBy,
                'resolved_at' => $now,
                'resolution_note' => trim($note),
                'finished_at' => $now,
            ])->save();

            $routine = $run->routine;
            if ($routine instanceof Routine) {
                $this->events->dispatch(new RoutineFinished($routine, $run->refresh(), TargetResult::skipped($run->message ?? '')));
            }

            return $run->refresh();
        }

        $run->forceFill([
            'resolved_by' => $resolvedBy,
            'resolved_at' => $now,
            'resolution_note' => trim($note) !== '' ? trim($note) : null,
        ])->save();

        $routine = $run->routine;
        if (! $routine instanceof Routine) {
            return $run;
        }

        $resumed = $this->openRun(
            routine: $routine,
            occurrence: $run->scheduled_for?->toDateTimeImmutable() ?? $now,
            reason: FireReason::Resumed,
            idempotencyKey: $run->idempotency_key,   // stessa chiave: è lo stesso lavoro
            attempt: $run->attempt + 1,
        );
        if ($resumed === null) {
            return $run->refresh();
        }

        // Il token di ripresa e la nota tornano al bersaglio come input di QUESTO fire: è così che
        // riprende da dove si era fermato.
        $this->execute($routine, $resumed, $now, array_filter([
            'resume_token' => $run->resume_token,
            'approved_by' => $resolvedBy,
            'approval_note' => trim($note) !== '' ? trim($note) : null,
        ], static fn ($v): bool => $v !== null));

        return $resumed->refresh();
    }

    // ── Interno ──────────────────────────────────────────────────────────────

    /**
     * Manda la domanda a un umano e registra se ci è riuscita.
     *
     * Un fallimento di consegna NON fa fallire il fire: la domanda resta comunque nel pannello, e
     * il canale è il modo veloce di raggiungere una persona, non l'unico. Ma va **scritto**: una
     * routine ferma in attesa di una domanda mai consegnata è il fallimento peggiore del sistema,
     * e deve essere visibile a chi guarda il fire, non solo in un log.
     */
    private function escalate(Routine $routine, RoutineRun $run, TargetResult $result): void
    {
        $actionClass = is_string($result->metadata['action_class'] ?? null)
            ? $result->metadata['action_class']
            : 'unknown';

        $run->forceFill([
            'action_class' => $actionClass,
            'question' => $result->message,
        ])->save();

        try {
            $this->escalator->escalate(new RoutineEscalation(
                routine: $routine->ref(),
                runId: $run->id,
                question: $result->message,
                actionClass: $actionClass,
                owner: $routine->owner,
                facts: $this->scalarFacts($result->metadata),
            ));
            $run->forceFill(['escalated_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))])->save();
        } catch (\Throwable $e) {
            $run->forceFill(['escalation_error' => mb_substr($this->humanMessage($e), 0, 250)])->save();
        }
    }

    /**
     * I soli valori mostrabili su un canale: niente strutture, niente payload interi.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, scalar|null>
     */
    private function scalarFacts(array $metadata): array
    {
        $facts = [];
        foreach ($metadata as $key => $value) {
            if ($key !== 'action_class' && (is_scalar($value) || $value === null)) {
                $facts[$key] = $value;
            }
        }

        return $facts;
    }

    /**
     * Il tetto di questo fire: il più stretto fra quello configurato e quello del mandato.
     *
     * Il più stretto, sempre. Un mandato che copre 500 € non diventa più largo perché la routine
     * è configurata a 1.000: il consenso è un tetto, non un suggerimento.
     */
    private function budgetFor(Routine $routine): ?float
    {
        $configured = $routine->budget_per_run !== null ? (float) $routine->budget_per_run : null;
        $mandate = $routine->mandateObject()?->budgetCeiling;

        $period = $this->budget->remaining($routine);

        $candidates = array_values(array_filter(
            [$configured, $mandate, $period],
            static fn (?float $v): bool => $v !== null,
        ));

        return $candidates === [] ? null : min($candidates);
    }

    /** Il testo della domanda quando il bersaglio ha lanciato invece di comporlo lui. */
    private function pauseQuestion(Routine $routine, MandateExceeded $e): string
    {
        $facts = [];
        foreach ($this->scalarFacts($e->context) as $key => $value) {
            $facts[] = $key.': '.(is_bool($value) ? ($value ? 'sì' : 'no') : (string) $value);
        }

        return sprintf(
            '«%s» vuole compiere un\'azione di tipo "%s" che il suo mandato non copre.%s',
            $routine->name,
            $e->actionClass,
            $facts === [] ? '' : ' '.implode(' · ', $facts),
        );
    }

    /**
     * Apre la riga del run. `null` se quell'occorrenza è già stata presa da qualcun altro.
     *
     * @param  array<string, mixed>  $input
     */
    private function openRun(
        Routine $routine,
        \DateTimeImmutable $occurrence,
        FireReason $reason,
        string $idempotencyKey,
        int $attempt,
        array $input = [],
    ): ?RoutineRun {
        try {
            return RoutineRun::create([
                'routine_id' => $routine->id,
                'reason' => $reason->value,
                'attempt' => $attempt,
                'scheduled_for' => $occurrence,
                'started_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                'idempotency_key' => $idempotencyKey,
                'correlation_id' => (string) Str::ulid(),
                'metadata' => $input === [] ? null : ['input_keys' => array_keys($input)],
            ]);
        } catch (QueryException $e) {
            // 23000/23505: violazione di unique. È l'arbitro che fa il suo lavoro, non un guasto.
            if ($this->isUniqueViolation($e)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function execute(Routine $routine, RoutineRun $run, \DateTimeImmutable $now, array $input = []): void
    {
        try {
            $target = $this->registry->get($routine->target_type, $routine->id);
        } catch (TargetNotRegistered $e) {
            // Il pacchetto che forniva il bersaglio non c'è più. Ritentare non lo farà tornare.
            $this->closeRun($run, TargetResult::failed($e->getMessage()), retryable: false);
            $routine->suspend('target_not_registered');
            $this->events->dispatch(new RoutineSuspended($routine, 'target_not_registered', $e->getMessage()));

            return;
        }

        // Il tetto, prima del lavoro. Un controllo solo a posteriori scopre lo sforamento quando i
        // soldi sono gia' spesi.
        if (! $this->budget->allows($routine, $now)) {
            $message = $this->budget->exhaustedMessage($routine, $now);
            $this->closeRun($run, TargetResult::skipped($message), retryable: false);
            $routine->suspend('budget_exhausted');
            $this->events->dispatch(new RoutineSuspended($routine, 'budget_exhausted', $message));

            return;
        }

        // L'autorita' con cui girare, PRIMA di qualsiasi lavoro: se la delega non c'e' piu', non
        // deve essere successo niente. Chiederla dopo significherebbe scoprire di non essere
        // autorizzati a fare una cosa che si e' gia' fatta.
        $token = null;
        if (is_string($routine->delegation_grant_id) && $routine->delegation_grant_id !== '') {
            try {
                $token = $this->delegation->tokenFor(
                    $routine->ref(),
                    $routine->delegation_grant_id,
                    $routine->mandateObject(),
                );
            } catch (DelegationUnavailable $e) {
                // Non ritentabile: un backoff non fa tornare un consenso ritirato.
                $this->closeRun($run, TargetResult::failed($e->getMessage(), ['delegation_reason' => $e->reason]), retryable: false);
                $routine->suspend('delegation_'.$e->reason);
                $this->events->dispatch(new RoutineSuspended($routine, 'delegation_'.$e->reason, $e->getMessage()));

                return;
            }
        }

        $execution = new RoutineExecution(
            routine: $routine->ref(),
            runId: $run->id,
            reason: $run->reasonEnum(),
            payload: $routine->target_payload ?? [],
            idempotencyKey: $run->idempotency_key,
            scheduledFor: $run->scheduled_for?->toDateTimeImmutable() ?? $now,
            timezone: $routine->timezone,
            input: $input,
            delegatedToken: $token?->accessToken,
            delegationGrantId: $routine->delegation_grant_id,
            deadline: $routine->timeout_seconds !== null
                ? $now->modify('+'.$routine->timeout_seconds.' seconds')
                : null,
            budgetRemaining: $this->budgetFor($routine),
            attempt: $run->attempt,
            correlationId: $run->correlation_id,
        );

        $this->events->dispatch(new RoutineFired($routine, $run, $execution));

        try {
            $result = $target->fire($execution);
        } catch (MandateExceeded $e) {
            // Un bersaglio dovrebbe restituire TargetResult::paused(), ma lanciare e' l'errore piu'
            // naturale da commettere - e trattarlo come un fallimento qualsiasi sarebbe il difetto
            // peggiore: la routine si arrenderebbe in silenzio invece di chiedere. Lo convertiamo.
            $result = TargetResult::paused(
                $this->pauseQuestion($routine, $e),
                pendingApprovalId: null,
                metadata: ['action_class' => $e->actionClass] + $e->context,
            );
        } catch (\Throwable $e) {
            // Un'eccezione significa "non l'avevo considerato": è ritentabile, ma il messaggio va
            // scritto in chiaro perché qualcuno lo leggerà in un pannello, non in uno stack trace.
            $result = TargetResult::failed(
                $this->humanMessage($e),
                ['exception' => $e::class, 'file' => basename($e->getFile()).':'.$e->getLine()],
            );
        }

        $this->closeRun($run, $result, retryable: $run->attempt < $routine->max_attempts);

        $routine->forceFill(['last_fired_at' => $now])->save();

        // E dopo, perche' il fire che sfora il tetto e' proprio quello che lo scopre. Sospendere
        // invece di saltare: saltare vorrebbe dire ritrovare lo stesso tetto superato ogni ora
        // per tutto il resto del mese.
        if ($result->cost !== null && ! $this->budget->allows($routine->fresh() ?? $routine, $now)) {
            $message = $this->budget->exhaustedMessage($routine, $now);
            $routine->suspend('budget_exhausted');
            $this->events->dispatch(new RoutineSuspended($routine, 'budget_exhausted', $message));
        }

        if ($result->outcome === TargetOutcome::Paused) {
            $run->refresh();
            $this->escalate($routine, $run, $result);
            $this->events->dispatch(new RoutinePaused($routine, $run->refresh(), $result));
        } else {
            $this->events->dispatch(new RoutineFinished($routine, $run->refresh(), $result));
        }
    }

    private function closeRun(RoutineRun $run, TargetResult $result, bool $retryable): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $retryAt = null;
        if ($result->outcome->isRetryable() && $retryable) {
            // Backoff esponenziale: 1', 2', 4', 8'… Un fallimento che si ripete costa sempre meno.
            $retryAt = $now->modify('+'.($this->retryBaseSeconds * (2 ** max(0, $run->attempt - 1))).' seconds');
        }

        $run->forceFill([
            'outcome' => $result->outcome->value,
            'message' => $result->message !== '' ? $result->message : null,
            'metadata' => $result->metadata === [] ? $run->metadata : array_merge($run->metadata ?? [], $result->metadata),
            'external_ref' => $result->externalRef,
            'cost' => $result->cost,
            'pending_approval_id' => $result->pendingApprovalId,
            'resume_token' => $result->resumeToken,
            'finished_at' => $now,
            'retry_at' => $retryAt,
        ])->save();
    }

    /**
     * Quali occorrenze eseguire adesso: una sola, oppure il recupero di quelle perse.
     *
     * @return list<\DateTimeImmutable>
     */
    private function occurrencesToRun(Routine $routine, \DateTimeImmutable $now): array
    {
        $due = $routine->next_run_at?->toDateTimeImmutable();
        if ($due === null || $due > $now) {
            return [];
        }

        if ($routine->missedRunPolicy() === MissedRunPolicy::SkipToNext) {
            // Solo l'ultima occorrenza conta: un promemoria delle 9:00 consegnato alle 14:00 è
            // rumore, e consegnarne cinque è rumore cinque volte.
            $missed = $this->scheduler->missedOccurrences($routine, $now, $this->catchUpCap);

            return $missed === [] ? [$due] : [end($missed)];
        }

        $missed = $this->scheduler->missedOccurrences($routine, $now, $this->catchUpCap);

        return $missed === [] ? [$due] : $missed;
    }

    /** @return array{fired: int, skipped: int} */
    private function handleOverlap(Routine $routine, \DateTimeImmutable $now): array
    {
        return match ($routine->overlapPolicy()) {
            // Registra il salto come fatto: "non è successo niente" e "ho deciso di non farlo"
            // sono due cose diverse, e in un pannello la seconda va vista.
            OverlapPolicy::Skip => $this->recordSkip($routine, $now),
            // Non tocca lo schedule: il prossimo tick riproverà, e intanto il precedente finisce.
            OverlapPolicy::Queue => ['fired' => 0, 'skipped' => 0],
            OverlapPolicy::Overlap => (function () use ($routine, $now): array {
                $occurrence = $routine->next_run_at?->toDateTimeImmutable() ?? $now;
                $run = $this->openRun($routine, $occurrence, FireReason::Scheduled, RoutineRun::scheduledKey($routine->id, $occurrence), 1);
                if ($run !== null) {
                    $this->execute($routine, $run, $now);
                }
                $this->reschedule($routine, $now);

                return ['fired' => $run !== null ? 1 : 0, 'skipped' => 0];
            })(),
        };
    }

    /** @return array{fired: int, skipped: int} */
    private function recordSkip(Routine $routine, \DateTimeImmutable $now): array
    {
        $occurrence = $routine->next_run_at?->toDateTimeImmutable() ?? $now;
        $run = $this->openRun($routine, $occurrence, FireReason::Scheduled, RoutineRun::scheduledKey($routine->id, $occurrence), 1);
        if ($run !== null) {
            $this->closeRun($run, TargetResult::skipped('Il fire precedente era ancora in corso.'), retryable: false);
        }
        $this->reschedule($routine, $now);

        return ['fired' => 0, 'skipped' => 1];
    }

    private function hasOpenRun(Routine $routine): bool
    {
        return RoutineRun::query()
            ->where('routine_id', $routine->id)
            ->whereNull('outcome')
            ->exists();
    }

    /** Ricalcola il prossimo fire e memorizza l'occorrenza locale appena consumata. */
    private function reschedule(Routine $routine, \DateTimeImmutable $now): void
    {
        $consumed = $routine->next_run_at?->toDateTimeImmutable();
        if ($consumed !== null) {
            $routine->forceFill([
                'last_local_occurrence' => $this->scheduler->localOccurrence($routine, $consumed),
            ]);
        }

        $next = $this->scheduler->nextRunAt($routine, $now);
        $routine->forceFill(['next_run_at' => $next])->save();

        // Una one-shot ha finito il suo scopo: chiuderla è più onesto che lasciarla "attiva" per
        // sempre senza nulla da fare.
        if ($next === null && $routine->triggerKind()->isScheduled()) {
            $routine->end('completed');
        }
    }

    // ── Lock ─────────────────────────────────────────────────────────────────

    /** Prende il lock con una UPDATE condizionale: l'arbitro è il database, non il processo. */
    private function acquireLock(Routine $routine, \DateTimeImmutable $now): ?string
    {
        $token = Routine::newLockToken();
        $affected = DB::table('routines')
            ->where('id', $routine->id)
            ->where(function ($q) use ($now): void {
                $q->whereNull('lock_token')->orWhere('locked_until', '<=', $now);
            })
            ->update([
                'lock_token' => $token,
                'locked_until' => $now->modify('+'.$this->lockSeconds.' seconds'),
            ]);

        return $affected === 1 ? $token : null;
    }

    /** Rilascia SOLO se il lock è ancora nostro: un TTL scaduto e riassegnato non va rubato. */
    private function releaseLock(Routine $routine, string $token): void
    {
        DB::table('routines')
            ->where('id', $routine->id)
            ->where('lock_token', $token)
            ->update(['lock_token' => null, 'locked_until' => null]);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');

        return $sqlState === '23000' || $sqlState === '23505';
    }

    private function humanMessage(\Throwable $e): string
    {
        $message = trim($e->getMessage());

        return $message === '' ? $e::class : $message;
    }
}
