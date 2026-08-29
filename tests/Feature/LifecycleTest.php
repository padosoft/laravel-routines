<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Padosoft\Routines\Contracts\Lifecycle\RoutineLifecycle;
use Padosoft\Routines\Events\RoutineMandateGranted;
use Padosoft\Routines\Events\RoutineSuspended;
use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\RoutineManager;
use Padosoft\Routines\Targets\TargetRegistry;
use Padosoft\Routines\Tests\Support\RecordingTarget;

beforeEach(function (): void {
    app(TargetRegistry::class)->register(new RecordingTarget);
});

function lifecycleRoutine(): Routine
{
    return app(RoutineManager::class)->create([
        'owner' => 'user:u1',
        'name' => 'Solleciti notturni',
        'target_type' => 'test',
        'target_payload' => [],
        'trigger_kind' => 'cron',
        'cron' => '0 6 * * *',
        'timezone' => 'Europe/Rome',
    ]);
}

it('sospende la routine e le toglie il prossimo fire', function (): void {
    $routine = lifecycleRoutine();
    expect($routine->next_run_at)->not->toBeNull();

    app(RoutineLifecycle::class)->suspend($routine->id, 'routine_fire_burst', 'rebel-ai-guard');

    $routine->refresh();
    expect($routine->status)->toBe('suspended')
        ->and($routine->next_run_at)->toBeNull();
});

it('scrive nel motivo CHI ha deciso, non solo cosa e successo', function (): void {
    // Una sospensione automatica e una decisa da una persona si trattano diversamente, e chi apre
    // il pannello alle 9:00 deve poterlo capire senza andare a cercare altrove.
    $routine = lifecycleRoutine();

    app(RoutineLifecycle::class)->suspend($routine->id, 'routine_approval_starvation', 'rebel-ai-guard');

    expect($routine->refresh()->suspension_reason)->toBe('rebel-ai-guard:routine_approval_starvation');
});

it('emette RoutineSuspended, cosi le notifiche partono come per le sospensioni interne', function (): void {
    Event::fake([RoutineSuspended::class]);
    $routine = lifecycleRoutine();

    app(RoutineLifecycle::class)->suspend($routine->id, 'routine_failure_loop', 'rebel-ai-guard');

    Event::assertDispatched(
        RoutineSuspended::class,
        fn (RoutineSuspended $e): bool => $e->routine->id === $routine->id && $e->reason === 'routine_failure_loop',
    );
});

it('ignora una routine inesistente invece di lanciare', function (): void {
    // Un detector che gira su una finestra di ieri puo' incontrare una routine cancellata nel
    // frattempo: far fallire il rilevamento per questo significherebbe perdere anche le anomalie
    // di tutte le altre.
    app(RoutineLifecycle::class)->suspend('01JQQQQQQQQQQQQQQQQQQQQQQQ', 'routine_fire_burst', 'rebel-ai-guard');
})->throwsNoExceptions();

it('non riapre ne tocca una routine terminata', function (): void {
    $routine = lifecycleRoutine();
    $routine->end('completed');

    app(RoutineLifecycle::class)->suspend($routine->id, 'routine_fire_burst', 'rebel-ai-guard');

    expect($routine->refresh()->status)->toBe('ended');
});

it('emette RoutineMandateGranted con l evidenza del consenso', function (): void {
    $routine = lifecycleRoutine();

    Event::fake([RoutineMandateGranted::class]);
    // Il manager e' un singleton gia' risolto: senza questo continuerebbe a usare il dispatcher
    // vero, e il fake non vedrebbe niente.
    app()->forgetInstance(RoutineManager::class);

    app(RoutineManager::class)->grantMandate(
        $routine,
        ['invoice.remind'],
        budgetCeiling: 50.0,
        confirmationId: 'chal_01ABC',
        aal: 'aal2',
    );

    Event::assertDispatched(
        RoutineMandateGranted::class,
        fn (RoutineMandateGranted $e): bool => $e->routine->id === $routine->id
            && $e->mandate->actionClasses === ['invoice.remind']
            && $e->confirmationId === 'chal_01ABC'
            && $e->aal === 'aal2',
    );
});
