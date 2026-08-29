<?php

declare(strict_types=1);

use Padosoft\Routines\InvalidRoutine;
use Padosoft\Routines\RoutineManager;
use Padosoft\Routines\Targets\TargetRegistry;
use Padosoft\Routines\Tests\Support\RecordingTarget;

beforeEach(function (): void {
    app(TargetRegistry::class)->register(new RecordingTarget);
});

function attrs(array $o = []): array
{
    return array_merge([
        'owner' => 'user:u1',
        'name' => 'Report',
        'target_type' => 'test',
        'target_payload' => [],
        'trigger_kind' => 'cron',
        'cron' => '0 6 * * *',
        'timezone' => 'Europe/Rome',
    ], $o);
}

it('crea la routine e le assegna subito un prossimo fire', function (): void {
    $r = app(RoutineManager::class)->create(attrs());

    expect($r->next_run_at)->not->toBeNull()
        ->and($r->status)->toBe('active');
});

it('rifiuta un payload che il bersaglio non accetta, con gli errori per campo', function (): void {
    // Il punto della validazione alla creazione: l'errore arriva mentre c'è un umano davanti al
    // form, non alle 3:00 dentro un log.
    try {
        app(RoutineManager::class)->create(attrs(['target_payload' => ['ok' => false]]));
        expect(false)->toBeTrue('doveva lanciare');
    } catch (InvalidRoutine $e) {
        expect($e->errors)->toHaveKey('ok');
    }
});

it('rifiuta un bersaglio non registrato', function (): void {
    expect(fn () => app(RoutineManager::class)->create(attrs(['target_type' => 'inesistente'])))
        ->toThrow(InvalidRoutine::class);
});

it('rifiuta un cron non valido e un fuso inesistente', function (): void {
    expect(fn () => app(RoutineManager::class)->create(attrs(['cron' => 'tutti i giorni'])))
        ->toThrow(InvalidRoutine::class);

    expect(fn () => app(RoutineManager::class)->create(attrs(['timezone' => 'Marte/Olympus'])))
        ->toThrow(InvalidRoutine::class);
});

it('ricalcola il prossimo fire quando lo schedule cambia', function (): void {
    // Senza questo, l'utente modifica l'orario e la routine continua a girare al vecchio, in
    // silenzio, fino al primo fire dopo la modifica.
    $manager = app(RoutineManager::class);
    $r = $manager->create(attrs(['cron' => '0 6 * * *']));
    $prima = $r->next_run_at->toDateTimeString();

    $manager->update($r, ['cron' => '0 18 * * *']);

    expect($r->fresh()->next_run_at->toDateTimeString())->not->toBe($prima);
});

it('la preview mostra i prossimi fire nel fuso del proprietario', function (): void {
    // "0 6 * * 2-6" non dice niente a nessuno; cinque date in chiaro fanno vedere subito se
    // l'orario è sbagliato di un fuso.
    $r = app(RoutineManager::class)->create(attrs(['cron' => '0 6 * * *', 'timezone' => 'Europe/Rome']));

    $preview = app(RoutineManager::class)->preview($r, 3);

    expect($preview)->toHaveCount(3)
        ->and($preview[0])->toEndWith('06:00');
});

it('riprendere una routine in pausa le ridà un prossimo fire', function (): void {
    $manager = app(RoutineManager::class);
    $r = $manager->create(attrs());

    $manager->pause($r);
    expect($r->fresh()->next_run_at)->toBeNull();

    $manager->resume($r->fresh());
    expect($r->fresh()->next_run_at)->not->toBeNull()
        ->and($r->fresh()->status)->toBe('active');
});

it('una routine chiusa non torna attiva', function (): void {
    $manager = app(RoutineManager::class);
    $r = $manager->create(attrs());

    $manager->end($r, 'non serve più');
    $manager->resume($r->fresh());

    expect($r->fresh()->status)->toBe('ended');
});

it('lo stato non è mass-assignable', function (): void {
    // `suspend()` e `pause()` hanno regole di ripresa diverse: un update dello stato le
    // aggirerebbe entrambe, e un utente potrebbe annullare una sospensione di sicurezza.
    $manager = app(RoutineManager::class);
    $r = $manager->create(attrs());
    $r->suspend('budget_exhausted');

    $manager->update($r->fresh(), ['status' => 'active', 'name' => 'Nuovo nome']);

    expect($r->fresh()->status)->toBe('suspended')
        ->and($r->fresh()->name)->toBe('Nuovo nome');
});
