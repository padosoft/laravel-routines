<?php

declare(strict_types=1);

use Padosoft\Routines\Contracts\Consent\MandateExceeded;
use Padosoft\Routines\Contracts\Consent\RoutineMandate;
use Padosoft\Routines\Contracts\Delegation\DelegatedToken;
use Padosoft\Routines\Contracts\Delegation\DelegationUnavailable;
use Padosoft\Routines\Contracts\Delegation\RoutineDelegationBroker;
use Padosoft\Routines\Contracts\Escalation\RoutineEscalation;
use Padosoft\Routines\Contracts\Escalation\RoutineEscalator;
use Padosoft\Routines\Contracts\Routine\RoutineRef;
use Padosoft\Routines\Contracts\Target\TargetResult;
use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\Models\RoutineRun;
use Padosoft\Routines\RoutineManager;
use Padosoft\Routines\Scheduling\RoutineDispatcher;
use Padosoft\Routines\Targets\TargetRegistry;
use Padosoft\Routines\Tests\Support\RecordingTarget;

/** Un escalator che tiene il conto, per verificare che la domanda sia partita davvero. */
final class SpyEscalator implements RoutineEscalator
{
    /** @var list<RoutineEscalation> */
    public array $sent = [];

    public function __construct(private readonly bool $fails = false) {}

    public function escalate(RoutineEscalation $escalation): void
    {
        if ($this->fails) {
            throw new RuntimeException('nessun canale raggiungibile');
        }
        $this->sent[] = $escalation;
    }
}

final class FakeBroker implements RoutineDelegationBroker
{
    public int $calls = 0;

    public function __construct(private readonly ?string $failReason = null) {}

    public function tokenFor(RoutineRef $routine, string $grantId, ?RoutineMandate $mandate = null): DelegatedToken
    {
        $this->calls++;
        if ($this->failReason !== null) {
            throw new DelegationUnavailable($grantId, $this->failReason);
        }

        return new DelegatedToken('tok_'.$grantId, $grantId, new DateTimeImmutable('+5 minutes'), ['orders:read']);
    }

    public function isUsable(string $grantId): bool
    {
        return $this->failReason === null;
    }
}

function mandated(array $attributes = []): Routine
{
    // `delegation_grant_id` non e' mass-assignable: nel codice vero lo scrive grantMandate(),
    // perche' e' evidenza di un consenso, non un campo di configurazione. Qui lo forziamo.
    $grantId = $attributes['delegation_grant_id'] ?? null;
    unset($attributes['delegation_grant_id']);

    $r = Routine::create(array_merge([
        'owner' => 'user:u1',
        'name' => 'Riordino',
        'target_type' => 'test',
        'target_payload' => ['soglia' => 10],
        'trigger_kind' => 'cron',
        'cron' => '0 3 * * *',
        'timezone' => 'UTC',
    ], $attributes));

    $r->forceFill(array_filter([
        'next_run_at' => now()->subMinute(),
        'delegation_grant_id' => $grantId,
    ], static fn ($v): bool => $v !== null))->save();

    return $r;
}

it('un fire che eccede il mandato si ferma e la domanda parte', function (): void {
    // Il cuore del prodotto: non fallisce (non e' rotto niente) e non procede (non e' autorizzato).
    $spy = new SpyEscalator;
    app()->instance(RoutineEscalator::class, $spy);
    app()->forgetInstance(RoutineDispatcher::class);

    app(TargetRegistry::class)->register(new RecordingTarget('test', function (): TargetResult {
        throw new MandateExceeded('order.create', ['importo' => 1240.0, 'fornitore' => 'Fornitore SpA']);
    }));

    app(RoutineDispatcher::class)->dispatch(mandated());

    $run = RoutineRun::first();
    expect($run->outcome)->toBe('paused')
        ->and($run->action_class)->toBe('order.create')
        ->and($run->question)->toContain('1240')
        ->and($run->escalated_at)->not->toBeNull()
        ->and($spy->sent)->toHaveCount(1)
        ->and($spy->sent[0]->owner)->toBe('user:u1')
        // Sui canali va il minimo che serve a decidere, non il payload.
        ->and($spy->sent[0]->facts)->toHaveKey('importo');
});

it('se la domanda non arriva a nessuno, il fire lo registra', function (): void {
    // Una routine ferma in attesa di una domanda mai consegnata e' il fallimento peggiore del
    // sistema. Non fa fallire il fire - la domanda resta nel pannello - ma deve essere visibile.
    app()->instance(RoutineEscalator::class, new SpyEscalator(fails: true));
    app()->forgetInstance(RoutineDispatcher::class);

    app(TargetRegistry::class)->register(new RecordingTarget('test', fn () => TargetResult::paused(
        'Vuole ordinare 1.240 €.', metadata: ['action_class' => 'order.create'],
    )));

    app(RoutineDispatcher::class)->dispatch(mandated());

    $run = RoutineRun::first();
    expect($run->outcome)->toBe('paused')
        ->and($run->escalated_at)->toBeNull()
        ->and($run->escalation_error)->toContain('nessun canale');
});

it('senza risposta non succede niente', function (): void {
    // Il test negativo che tutto il design esiste per rendere vero.
    app()->instance(RoutineEscalator::class, new SpyEscalator);
    app()->forgetInstance(RoutineDispatcher::class);

    $target = new RecordingTarget('test', fn () => TargetResult::paused('Posso?', metadata: ['action_class' => 'order.create']));
    app(TargetRegistry::class)->register($target);

    $r = mandated();
    app(RoutineDispatcher::class)->dispatch($r);

    // Un altro tick passa. Nessuno ha risposto.
    app(RoutineDispatcher::class)->tick();

    expect($target->fires)->toHaveCount(1)
        ->and(RoutineRun::where('outcome', 'paused')->count())->toBe(1);
});

it("l'approvazione riprende il fire con la stessa chiave, non da capo", function (): void {
    app()->instance(RoutineEscalator::class, new SpyEscalator);
    app()->forgetInstance(RoutineDispatcher::class);

    $seen = 0;
    $target = new RecordingTarget('test', function ($execution) use (&$seen): TargetResult {
        $seen++;

        return $seen === 1
            ? TargetResult::paused('Posso?', resumeToken: 'punto-3', metadata: ['action_class' => 'order.create'])
            : TargetResult::succeeded('ordine emesso');
    });
    app(TargetRegistry::class)->register($target);

    $r = mandated();
    app(RoutineDispatcher::class)->dispatch($r);
    $paused = RoutineRun::first();

    $resumed = app(RoutineDispatcher::class)->resolve($paused, approved: true, resolvedBy: 'user:u1', note: 'ok');

    expect($resumed->outcome)->toBe('succeeded')
        ->and($resumed->reason)->toBe('resumed')
        // Stessa chiave: per il bersaglio e' lo STESSO lavoro, quindi riprende invece di ripetere.
        ->and($resumed->idempotency_key)->toBe($paused->idempotency_key)
        ->and($target->fires[1]->input('resume_token'))->toBe('punto-3')
        ->and($target->fires[1]->input('approved_by'))->toBe('user:u1');
});

it('il rifiuto chiude il fire come saltato, col motivo, e non come fallito', function (): void {
    // Non si e' rotto niente: qualcuno ha deciso di no. Marcarlo `failed` lo farebbe ritentare, ed
    // e' l'ultima cosa che deve succedere dopo un rifiuto.
    app()->instance(RoutineEscalator::class, new SpyEscalator);
    app()->forgetInstance(RoutineDispatcher::class);

    app(TargetRegistry::class)->register(new RecordingTarget('test', fn () => TargetResult::paused(
        'Posso?', metadata: ['action_class' => 'order.create'],
    )));

    app(RoutineDispatcher::class)->dispatch(mandated());
    $paused = RoutineRun::first();

    $resolved = app(RoutineDispatcher::class)->resolve($paused, approved: false, resolvedBy: 'user:u1', note: 'Fornitore sbagliato');

    expect($resolved->outcome)->toBe('skipped')
        ->and($resolved->retry_at)->toBeNull()
        ->and($resolved->resolution_note)->toBe('Fornitore sbagliato')
        ->and($resolved->message)->toContain('Fornitore sbagliato');
});

it('un rifiuto senza motivo non passa', function (): void {
    app()->instance(RoutineEscalator::class, new SpyEscalator);
    app()->forgetInstance(RoutineDispatcher::class);
    app(TargetRegistry::class)->register(new RecordingTarget('test', fn () => TargetResult::paused('Posso?', metadata: ['action_class' => 'x'])));

    app(RoutineDispatcher::class)->dispatch(mandated());

    expect(fn () => app(RoutineDispatcher::class)->resolve(RoutineRun::first(), false, 'user:u1', '  '))
        ->toThrow(InvalidArgumentException::class);
});

it('rispondere due volte alla stessa domanda non esegue due volte', function (): void {
    app()->instance(RoutineEscalator::class, new SpyEscalator);
    app()->forgetInstance(RoutineDispatcher::class);

    $seen = 0;
    $target = new RecordingTarget('test', function () use (&$seen): TargetResult {
        $seen++;

        return $seen === 1 ? TargetResult::paused('Posso?', metadata: ['action_class' => 'x']) : TargetResult::succeeded('fatto');
    });
    app(TargetRegistry::class)->register($target);

    app(RoutineDispatcher::class)->dispatch(mandated());
    $paused = RoutineRun::first();

    app(RoutineDispatcher::class)->resolve($paused, true, 'user:u1');
    app(RoutineDispatcher::class)->resolve($paused->fresh(), true, 'user:u1');

    expect($target->fires)->toHaveCount(2);   // il primo fire + una sola ripresa
});

it('la routine chiede il token delegato PRIMA di lavorare', function (): void {
    $broker = new FakeBroker;
    app()->instance(RoutineDelegationBroker::class, $broker);
    app()->forgetInstance(RoutineDispatcher::class);

    $target = new RecordingTarget;
    app(TargetRegistry::class)->register($target);

    app(RoutineDispatcher::class)->dispatch(mandated(['delegation_grant_id' => 'dgr_1']));

    expect($broker->calls)->toBe(1)
        ->and($target->fires[0]->isDelegated())->toBeTrue()
        ->and($target->fires[0]->delegatedToken)->toBe('tok_dgr_1')
        ->and($target->fires[0]->delegationGrantId)->toBe('dgr_1');
});

it('delega revocata: la routine si sospende e non ritenta', function (): void {
    // Un backoff non fa tornare un consenso ritirato: ritentare significherebbe bussare a una
    // porta chiusa ogni cinque minuti per tre giorni.
    app()->instance(RoutineDelegationBroker::class, new FakeBroker('revoked'));
    app()->forgetInstance(RoutineDispatcher::class);

    $target = new RecordingTarget;
    app(TargetRegistry::class)->register($target);
    $r = mandated(['delegation_grant_id' => 'dgr_1']);

    app(RoutineDispatcher::class)->dispatch($r);

    expect($target->fires)->toHaveCount(0)          // non e' successo NIENTE
        ->and($r->fresh()->status)->toBe('suspended')
        ->and($r->fresh()->suspension_reason)->toBe('delegation_revoked')
        ->and(RoutineRun::first()->retry_at)->toBeNull();
});

it('senza modulo di delega, una routine che ne dichiara una si ferma', function (): void {
    // Distinzione che conta: girare come l'applicazione e' una scelta; dichiarare una delega che
    // nessuno puo' emettere e' una configurazione rotta, e le due cose non vanno confuse.
    app(TargetRegistry::class)->register(new RecordingTarget);
    $r = mandated(['delegation_grant_id' => 'dgr_1']);

    app(RoutineDispatcher::class)->dispatch($r);

    expect($r->fresh()->status)->toBe('suspended')
        ->and(RoutineRun::first()->message)->toContain('laravel-iam-agents');
});

it('senza delega dichiarata la routine gira come applicazione, senza rumore', function (): void {
    $target = new RecordingTarget;
    app(TargetRegistry::class)->register($target);

    app(RoutineDispatcher::class)->dispatch(mandated());

    expect($target->fires[0]->isDelegated())->toBeFalse()
        ->and(RoutineRun::first()->outcome)->toBe('succeeded');
});

it('il tetto del mandato vince su una configurazione piu larga', function (): void {
    // Il consenso e' un tetto, non un suggerimento: 500 non diventa 1000 perche' qualcuno ha
    // scritto 1000 nel form.
    $target = new RecordingTarget;
    app(TargetRegistry::class)->register($target);

    $r = mandated(['budget_per_run' => 1000.0]);
    app(RoutineManager::class)->grantMandate($r, ['order.create'], budgetCeiling: 500.0);

    app(RoutineDispatcher::class)->dispatch($r->fresh());

    expect($target->fires[0]->budgetRemaining)->toBe(500.0);
});

it('il mandato smette di coprire se il payload cambia dopo il consenso', function (): void {
    // Stesso principio del dynamic linking PSD2: il consenso vale per QUELLA configurazione.
    app(TargetRegistry::class)->register(new RecordingTarget);
    $manager = app(RoutineManager::class);

    $r = mandated();
    $manager->grantMandate($r, ['order.create'], budgetCeiling: 500.0);
    expect($manager->mandateCovers($r->fresh()))->toBeTrue();

    $manager->update($r->fresh(), ['target_payload' => ['soglia' => 999]]);

    expect($manager->mandateCovers($r->fresh()))->toBeFalse();
});

it('un mandato senza classi di azione non autorizza niente', function (): void {
    // Fail-closed: la lista vuota e' "nessuna", non "tutte".
    app(TargetRegistry::class)->register(new RecordingTarget);
    $r = mandated();

    $mandate = app(RoutineManager::class)->grantMandate($r, []);

    expect($mandate->covers('order.create'))->toBeFalse()
        ->and($mandate->covers('qualsiasi.cosa'))->toBeFalse();
});
