<?php

declare(strict_types=1);

use Padosoft\Routines\Contracts\Target\TargetResult;
use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\Models\RoutineRun;
use Padosoft\Routines\Scheduling\RoutineDispatcher;
use Padosoft\Routines\Targets\TargetRegistry;
use Padosoft\Routines\Tests\Support\RecordingTarget;

function due(array $attributes = []): Routine
{
    $r = Routine::create(array_merge([
        'owner' => 'user:u1',
        'name' => 'Test',
        'target_type' => 'test',
        'target_payload' => [],
        'trigger_kind' => 'cron',
        'cron' => '0 6 * * *',
        'timezone' => 'UTC',
    ], $attributes));

    $r->forceFill(['next_run_at' => now()->subMinute()])->save();

    return $r;
}

function register(RecordingTarget $target): RecordingTarget
{
    app(TargetRegistry::class)->register($target);

    return $target;
}

it('esegue una routine scaduta e rischedula', function (): void {
    $target = register(new RecordingTarget);
    $r = due();

    app(RoutineDispatcher::class)->dispatch($r);

    expect($target->fires)->toHaveCount(1)
        ->and($r->fresh()->next_run_at)->not->toBeNull()
        ->and($r->fresh()->next_run_at->isFuture())->toBeTrue()
        ->and(RoutineRun::first()->outcome)->toBe('succeeded');
});

it('non esegue due volte la stessa occorrenza, nemmeno con due dispatch concorrenti', function (): void {
    // È la garanzia che regge tutto il resto: due worker che partono nello stesso secondo devono
    // produrre UN fire. L'arbitro è il vincolo unique, non un `if` nel codice.
    $target = register(new RecordingTarget);
    $r = due();
    $occurrence = $r->next_run_at->toDateTimeImmutable();

    $dispatcher = app(RoutineDispatcher::class);
    $dispatcher->dispatch($r);

    // Il secondo worker aveva già in mano la routine scaduta: la ridispatcha con la stessa data.
    $stale = Routine::find($r->id);
    $stale->forceFill(['next_run_at' => $occurrence])->save();
    $dispatcher->dispatch($stale);

    expect(RoutineRun::where('idempotency_key', RoutineRun::scheduledKey($r->id, $occurrence))->count())->toBe(1)
        ->and($target->fires)->toHaveCount(1);
});

it('recupera le occorrenze perse quando la politica lo chiede', function (): void {
    $target = register(new RecordingTarget);
    $r = due(['cron' => '0 * * * *', 'missed_run_policy' => 'catch_up']);
    $r->forceFill(['next_run_at' => now()->subHours(3)->startOfHour()])->save();

    app(RoutineDispatcher::class)->dispatch($r);

    // Tre ore ferme: tre occorrenze, tutte con la loro chiave, nessuna persa.
    expect(count($target->fires))->toBeGreaterThanOrEqual(3)
        ->and(array_unique($target->keys()))->toHaveCount(count($target->keys()));
});

it("con skip_to_next recupera solo l'ultima", function (): void {
    // Un promemoria delle 9:00 consegnato alle 14:00 è rumore; cinque promemoria sono rumore
    // cinque volte. È una scelta di dominio, ed è per questo che è un campo e non un default.
    $target = register(new RecordingTarget);
    $r = due(['cron' => '0 * * * *', 'missed_run_policy' => 'skip_to_next']);
    $r->forceFill(['next_run_at' => now()->subHours(5)->startOfHour()])->save();

    app(RoutineDispatcher::class)->dispatch($r);

    expect($target->fires)->toHaveCount(1);
});

it('salta il fire quando il precedente è ancora aperto, e lo registra', function (): void {
    $target = register(new RecordingTarget);
    $r = due(['overlap_policy' => 'skip']);

    // Un run rimasto aperto: il worker precedente è morto senza chiuderlo.
    RoutineRun::create([
        'routine_id' => $r->id,
        'reason' => 'scheduled',
        'attempt' => 1,
        'idempotency_key' => 'aperto',
        'started_at' => now()->subMinutes(10),
    ]);

    $stats = app(RoutineDispatcher::class)->dispatch($r);

    expect($stats['skipped'])->toBe(1)
        ->and($target->fires)->toHaveCount(0)
        // "Non è successo niente" e "ho deciso di non farlo" sono cose diverse, e la seconda
        // deve essere visibile in un pannello.
        ->and(RoutineRun::where('outcome', 'skipped')->count())->toBe(1);
});

it('con queue non tocca lo schedule: riproverà al prossimo tick', function (): void {
    register(new RecordingTarget);
    $r = due(['overlap_policy' => 'queue']);
    $wasDue = $r->next_run_at->toDateTimeString();

    RoutineRun::create([
        'routine_id' => $r->id, 'reason' => 'scheduled', 'attempt' => 1,
        'idempotency_key' => 'aperto', 'started_at' => now()->subMinute(),
    ]);

    app(RoutineDispatcher::class)->dispatch($r);

    expect($r->fresh()->next_run_at->toDateTimeString())->toBe($wasDue);
});

it('un lock scaduto non blocca la routine per sempre', function (): void {
    // Il caso che nessuno considera: il worker muore col lock in mano. Senza TTL la routine resta
    // ferma e non c'è nessun errore da nessuna parte — il fallimento più silenzioso possibile.
    $target = register(new RecordingTarget);
    $r = due();
    $r->forceFill(['lock_token' => 'morto', 'locked_until' => now()->subHour()])->save();

    app(RoutineDispatcher::class)->dispatch($r->fresh());

    expect($target->fires)->toHaveCount(1);
});

it('un lock vivo di un altro worker ferma il dispatch', function (): void {
    $target = register(new RecordingTarget);
    $r = due();
    $r->forceFill(['lock_token' => 'vivo', 'locked_until' => now()->addMinutes(10)])->save();

    app(RoutineDispatcher::class)->dispatch($r->fresh());

    expect($target->fires)->toHaveCount(0);
});

it('un fallimento programma un tentativo, e il tentativo riusa la stessa chiave', function (): void {
    $target = register(new RecordingTarget('test', fn () => TargetResult::failed('rete giù')));
    $r = due(['max_attempts' => 3]);

    app(RoutineDispatcher::class)->dispatch($r);

    $run = RoutineRun::first();
    expect($run->outcome)->toBe('failed')
        ->and($run->retry_at)->not->toBeNull();

    // La chiave stabile è ciò che impedisce alla seconda email di partire: il bersaglio vede la
    // stessa chiave e sa che è lo stesso lavoro, non uno nuovo.
    $run->forceFill(['retry_at' => now()->subMinute()])->save();
    app(RoutineDispatcher::class)->retryDue(new DateTimeImmutable('now', new DateTimeZone('UTC')));

    expect($target->keys())->toHaveCount(2)
        ->and($target->keys()[0])->toBe($target->keys()[1])
        ->and($target->fires[1]->attempt)->toBe(2);
});

it('smette di ritentare a max_attempts', function (): void {
    register(new RecordingTarget('test', fn () => TargetResult::failed('rete giù')));
    $r = due(['max_attempts' => 1]);

    app(RoutineDispatcher::class)->dispatch($r);

    expect(RoutineRun::first()->retry_at)->toBeNull();
});

it('sospende la routine quando il bersaglio non è più registrato', function (): void {
    // Il pacchetto che forniva il bersaglio è stato disinstallato. Ritentare non lo farà tornare:
    // la routine si ferma e lo dice, invece di accumulare fallimenti per sempre.
    $r = due(['target_type' => 'sparito']);

    app(RoutineDispatcher::class)->dispatch($r);

    expect($r->fresh()->status)->toBe('suspended')
        ->and($r->fresh()->suspension_reason)->toBe('target_not_registered')
        ->and(RoutineRun::first()->message)->toContain('sparito');
});

it("un'eccezione del bersaglio diventa un fallimento leggibile, non un crash", function (): void {
    register(new RecordingTarget('test', function (): TargetResult {
        throw new RuntimeException('il server ha chiuso la connessione');
    }));
    $r = due();

    app(RoutineDispatcher::class)->dispatch($r);

    $run = RoutineRun::first();
    expect($run->outcome)->toBe('failed')
        ->and($run->message)->toBe('il server ha chiuso la connessione')
        ->and($run->metadata['exception'])->toBe(RuntimeException::class);
});

it('la routine one-shot si chiude da sola dopo il fire', function (): void {
    register(new RecordingTarget);
    $r = Routine::create([
        'owner' => 'user:u1', 'name' => 'Una volta', 'target_type' => 'test',
        'target_payload' => [], 'trigger_kind' => 'once_at',
        'once_at' => now()->subMinute(), 'timezone' => 'UTC',
    ]);
    $r->forceFill(['next_run_at' => now()->subMinute()])->save();

    app(RoutineDispatcher::class)->dispatch($r);

    expect($r->fresh()->status)->toBe('ended')
        ->and($r->fresh()->ended_reason)->toBe('completed');
});

it('fireNow non tocca lo schedule', function (): void {
    $target = register(new RecordingTarget);
    $r = due();
    $r->forceFill(['next_run_at' => now()->addDay()])->save();
    $scheduled = $r->fresh()->next_run_at->toDateTimeString();

    app(RoutineDispatcher::class)->fireNow($r->fresh(), ['testo' => 'ciao']);

    expect($target->fires)->toHaveCount(1)
        ->and($target->fires[0]->reason->value)->toBe('manual')
        ->and($target->fires[0]->input('testo'))->toBe('ciao')
        ->and($r->fresh()->next_run_at->toDateTimeString())->toBe($scheduled);
});

it('fireNow con la stessa chiave esplicita non raddoppia il lavoro', function (): void {
    // È il caso del webhook consegnato due volte: la chiave la porta il chiamante, e serve
    // esattamente qui.
    $target = register(new RecordingTarget);
    $r = due();

    $dispatcher = app(RoutineDispatcher::class);
    $dispatcher->fireNow($r, [], idempotencyKey: 'consegna-42');
    $dispatcher->fireNow($r->fresh(), [], idempotencyKey: 'consegna-42');

    expect($target->fires)->toHaveCount(1);
});

it("una routine in pausa non gira nemmeno se l'ora è passata", function (): void {
    $target = register(new RecordingTarget);
    $r = due();
    $r->pause();
    $r->forceFill(['next_run_at' => now()->subMinute()])->save();

    app(RoutineDispatcher::class)->dispatch($r->fresh());

    expect($target->fires)->toHaveCount(0);
});

it('il tick raccoglie solo le routine scadute e attive', function (): void {
    $target = register(new RecordingTarget);
    due();                                        // scaduta
    $futura = due();
    $futura->forceFill(['next_run_at' => now()->addDay()])->save();

    $stats = app(RoutineDispatcher::class)->tick();

    expect($stats['fired'])->toBe(1)
        ->and($target->fires)->toHaveCount(1);
});

it('un fire in pausa resta aperto e porta con sé il riferimento per riprendere', function (): void {
    // È il caso per cui TargetOutcome ha quattro valori: non è fallito (non è rotto niente) e non
    // è riuscito (non ha fatto niente). È fermo, e serve un umano.
    register(new RecordingTarget('test', fn () => TargetResult::paused(
        'Servono 1.200 € e il mandato ne copre 500.',
        pendingApprovalId: 'appr_1',
        resumeToken: 'tok',
    )));
    $r = due();

    app(RoutineDispatcher::class)->dispatch($r);

    $run = RoutineRun::first();
    expect($run->outcome)->toBe('paused')
        ->and($run->pending_approval_id)->toBe('appr_1')
        ->and($run->resume_token)->toBe('tok')
        // Una pausa non si ritenta con un backoff: aspetta una persona.
        ->and($run->retry_at)->toBeNull();
});
