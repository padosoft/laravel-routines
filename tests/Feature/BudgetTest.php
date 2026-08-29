<?php

declare(strict_types=1);

use Padosoft\Routines\Budget\BudgetGuard;
use Padosoft\Routines\Contracts\Target\TargetResult;
use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\Models\RoutineRun;
use Padosoft\Routines\Scheduling\RoutineDispatcher;
use Padosoft\Routines\Targets\TargetRegistry;
use Padosoft\Routines\Tests\Support\RecordingTarget;

function capped(array $attributes = []): Routine
{
    $r = Routine::create(array_merge([
        'owner' => 'user:u1',
        'name' => 'Costosa',
        'target_type' => 'test',
        'target_payload' => [],
        'trigger_kind' => 'cron',
        'cron' => '*/5 * * * *',
        'timezone' => 'Europe/Rome',
        'budget_per_period' => 1.00,
        'budget_period' => 'day',
        'currency' => 'EUR',
    ], $attributes));
    $r->forceFill(['next_run_at' => now()->subMinute()])->save();

    return $r;
}

it('il fire che sfora il tetto e quello che lo scopre, e sospende', function (): void {
    // Un controllo solo a monte non basta: il primo sforamento arriva da un fire che a monte
    // era ancora dentro.
    app(TargetRegistry::class)->register(new RecordingTarget('test', fn () => TargetResult::succeeded('fatto', cost: 1.50)));
    $r = capped();

    app(RoutineDispatcher::class)->dispatch($r);

    expect(RoutineRun::first()->outcome)->toBe('succeeded')
        ->and($r->fresh()->status)->toBe('suspended')
        ->and($r->fresh()->suspension_reason)->toBe('budget_exhausted');
});

it('a tetto gia esaurito non parte, e non ritenta ogni ora', function (): void {
    // Saltare vorrebbe dire ritrovare lo stesso tetto superato ogni ora per tutto il mese.
    $target = new RecordingTarget;
    app(TargetRegistry::class)->register($target);
    $r = capped();

    RoutineRun::create([
        'routine_id' => $r->id, 'reason' => 'scheduled', 'attempt' => 1,
        'idempotency_key' => 'speso', 'started_at' => now()->subHour(),
    ])->forceFill(['outcome' => 'succeeded', 'cost' => 2.00, 'finished_at' => now()->subHour()])->save();

    app(RoutineDispatcher::class)->dispatch($r);

    expect($target->fires)->toHaveCount(0)
        ->and($r->fresh()->status)->toBe('suspended')
        ->and(RoutineRun::where('outcome', 'skipped')->first()->message)->toContain('tetto di spesa');
});

it('il messaggio dice quando riparte, non solo che e finito', function (): void {
    // Senza, l'unica azione che resta a chi legge e' alzare il tetto - che spesso non e' la cosa
    // giusta da fare.
    $r = capped(['budget_period' => 'month', 'budget_per_period' => 50.0]);

    $message = app(BudgetGuard::class)->exhaustedMessage($r);

    expect($message)->toContain('50,00 EUR')
        ->and($message)->toContain('questo mese')
        ->and($message)->toMatch('/Riprender\S+ il \d{2}\/\d{2}\/\d{4}/');
});

it('il periodo si calcola nel fuso del proprietario', function (): void {
    // "Questo mese" per chi vive a Roma comincia due ore prima che a Greenwich, e un tetto
    // mensile in UTC si azzera nel momento sbagliato.
    $r = capped(['budget_period' => 'month', 'timezone' => 'Europe/Rome']);

    $start = app(BudgetGuard::class)->periodStart($r, new DateTimeImmutable('2026-07-15 10:00:00', new DateTimeZone('UTC')));

    // Le 00:00 del 1° luglio a Roma sono le 22:00 del 30 giugno UTC.
    expect($start->format('Y-m-d H:i'))->toBe('2026-06-30 22:00');
});

it('il tetto residuo del periodo entra nel budget del fire', function (): void {
    // Il fire non deve poter spendere piu' di quanto resti nel periodo, anche se il tetto
    // per-fire e' piu' alto.
    $target = new RecordingTarget;
    app(TargetRegistry::class)->register($target);
    $r = capped(['budget_per_run' => 5.00, 'budget_per_period' => 10.00]);

    RoutineRun::create([
        'routine_id' => $r->id, 'reason' => 'scheduled', 'attempt' => 1,
        'idempotency_key' => 'speso', 'started_at' => now()->subHour(),
    ])->forceFill(['outcome' => 'succeeded', 'cost' => 7.50, 'finished_at' => now()->subHour()])->save();

    app(RoutineDispatcher::class)->dispatch($r);

    expect($target->fires[0]->budgetRemaining)->toBe(2.5);
});

it('senza tetto configurato non cambia niente', function (): void {
    $target = new RecordingTarget('test', fn () => TargetResult::succeeded('fatto', cost: 999.0));
    app(TargetRegistry::class)->register($target);
    $r = capped(['budget_per_period' => null, 'budget_period' => null]);

    app(RoutineDispatcher::class)->dispatch($r);

    expect($r->fresh()->status)->toBe('active')
        ->and($target->fires[0]->budgetRemaining)->toBeNull();
});
