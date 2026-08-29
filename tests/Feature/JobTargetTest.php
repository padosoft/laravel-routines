<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Padosoft\Routines\Contracts\Execution\FireReason;
use Padosoft\Routines\Contracts\Execution\RoutineExecution;
use Padosoft\Routines\Contracts\Routine\RoutineRef;
use Padosoft\Routines\Targets\JobTarget;

final class HarmlessJob
{
    public function __construct(public string $to = '') {}

    public function handle(): void {}
}

final class NotAllowedJob
{
    public function handle(): void {}
}

function execution(array $payload): RoutineExecution
{
    return new RoutineExecution(
        routine: new RoutineRef('r1', 'Test', 'user:u1'),
        runId: 'run1',
        reason: FireReason::Scheduled,
        payload: $payload,
        idempotencyKey: 'k1',
        scheduledFor: new DateTimeImmutable('now'),
    );
}

it('mette in coda un job dichiarato', function (): void {
    Bus::fake();
    $target = new JobTarget(app(Dispatcher::class), [HarmlessJob::class]);

    $result = $target->fire(execution(['job' => HarmlessJob::class, 'arguments' => ['a@b.c']]));

    expect($result->outcome->value)->toBe('succeeded');
    Bus::assertDispatched(HarmlessJob::class);
});

it("rifiuta in validazione un job fuori dall'allow-list", function (): void {
    // Il payload di una routine è dato — arriva da un form, da un import, in futuro da un
    // assistente. Istanziare la classe che ci trovi dentro darebbe a quel dato il diritto di
    // eseguire qualsiasi cosa esista nell'applicazione.
    $target = new JobTarget(app(Dispatcher::class), [HarmlessJob::class]);

    $result = $target->validate(['job' => NotAllowedJob::class]);

    expect($result->valid)->toBeFalse()
        ->and($result->errors)->toHaveKey('job');
});

it("rifiuta al fire un job tolto dall'allow-list dopo la creazione", function (): void {
    // Una routine creata quando il job era permesso non deve continuare a girare dopo che è
    // stato tolto: per questo l'allow-list si ricontrolla al fire, non solo alla creazione.
    Bus::fake();
    $target = new JobTarget(app(Dispatcher::class), []);

    $result = $target->fire(execution(['job' => HarmlessJob::class]));

    expect($result->outcome->value)->toBe('failed')
        ->and($result->message)->toContain('non è più abilitato');
    Bus::assertNothingDispatched();
});

it('un costruttore che non corrisponde è un fallimento leggibile', function (): void {
    Bus::fake();
    $target = new JobTarget(app(Dispatcher::class), [HarmlessJob::class]);

    $result = $target->fire(execution(['job' => HarmlessJob::class, 'arguments' => [['non', 'una', 'stringa']]]));

    expect($result->outcome->value)->toBe('failed')
        ->and($result->message)->toContain('costruttore');
});
