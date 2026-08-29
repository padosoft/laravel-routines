<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Padosoft\Routines\Contracts\Target\TargetResult;
use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\Models\RoutineRun;
use Padosoft\Routines\RoutineManager;
use Padosoft\Routines\Targets\TargetRegistry;
use Padosoft\Routines\Tests\Support\RecordingTarget;

beforeEach(function (): void {
    // Il primo parametro nullable e' cio' che rende l'ability valutabile anche senza utente
    // autenticato: qui si esercita il controller, non il guard.
    foreach (['routines.read', 'routines.write', 'routines.fire', 'routines.approve'] as $ability) {
        Gate::define($ability, fn (?object $user): bool => true);
    }
});

function api(string $path): string
{
    return '/'.trim((string) config('routines.api.prefix'), '/').'/'.ltrim($path, '/');
}

function seed(array $attributes = []): Routine
{
    // Non sovrascrive un bersaglio gia' registrato dal test: chi vuole un comportamento specifico
    // lo registra prima, e seed() gli mette solo la routine intorno.
    if (! app(TargetRegistry::class)->has('test')) {
        app(TargetRegistry::class)->register(new RecordingTarget);
    }

    return app(RoutineManager::class)->create(array_merge([
        'owner' => 'user:u1',
        'name' => 'Report del mattino',
        'target_type' => 'test',
        'target_payload' => ['soglia' => 1],
        'trigger_kind' => 'cron',
        'cron' => '0 6 * * 1-5',
        'timezone' => 'Europe/Rome',
    ], $attributes));
}

it('dichiara cosa e installato', function (): void {
    $body = $this->getJson(api('capabilities'))->assertOk()->json('data');

    // Senza modulo di delega la capability e' false, cosi' il pannello non mostra una scheda
    // "Mandato" vuota: promettere una funzione assente e' peggio che non mostrarla.
    expect($body['delegation'])->toBeFalse()
        ->and($body['channels'])->toBeFalse()
        ->and($body['can'])->toContain('routines.approve');
});

it('espone i descrittori dei bersagli, inclusi quelli usati ma non registrati', function (): void {
    // Un tipo usato e non registrato e' la causa numero uno delle sospensioni: nasconderlo
    // lascerebbe la schermata Salute senza la sua risposta.
    seed();
    Routine::create([
        'owner' => 'user:u1', 'name' => 'Orfana', 'target_type' => 'sparito',
        'target_payload' => [], 'trigger_kind' => 'manual', 'timezone' => 'UTC',
    ]);

    $data = collect($this->getJson(api('targets'))->assertOk()->json('data'));

    expect($data->firstWhere('type', 'test')['registered'])->toBeTrue()
        ->and($data->firstWhere('type', 'sparito')['registered'])->toBeFalse()
        ->and($data->firstWhere('type', 'sparito')['routines_count'])->toBe(1);
});

it('traduce il cron in italiano e mostra la sigla del fuso', function (): void {
    // Il campo che previene la classe di bug piu' comune del prodotto: un orario sbagliato di
    // un fuso, scoperto dopo un mese di esecuzioni.
    $body = $this->postJson(api('schedule/preview'), [
        'cron' => '0 6 * * 1-5',
        'timezone' => 'Europe/Rome',
        'count' => 3,
    ])->assertOk()->json('data');

    expect($body['schedule_human'])->toBe('Ogni giorno feriale alle 06:00')
        ->and($body['occurrences'])->toHaveCount(3)
        ->and($body['occurrences'][0]['local'])->toEndWith('06:00')
        ->and($body['occurrences'][0]['timezone_abbr'])->toBeIn(['CET', 'CEST']);
});

it('rifiuta un cron illeggibile con gli errori per campo', function (): void {
    $this->postJson(api('schedule/preview'), ['cron' => 'tutti i giorni', 'timezone' => 'UTC'])
        ->assertStatus(422)
        ->assertJsonPath('errors.cron.0', fn (string $m): bool => str_contains($m, 'cron'));
});

it('elenca le routine con schedule leggibile', function (): void {
    seed();

    $row = $this->getJson(api('routines'))->assertOk()->json('data.0');

    expect($row['schedule_human'])->toBe('Ogni giorno feriale alle 06:00')
        ->and($row['status'])->toBe('active')
        ->and($row['target_label'])->toBe('Test');
});

it('crea una routine e la valida col bersaglio', function (): void {
    app(TargetRegistry::class)->register(new RecordingTarget);

    $this->postJson(api('routines'), [
        'owner' => 'user:u1', 'name' => 'Nuova', 'target_type' => 'test',
        'target_payload' => ['ok' => false],       // il bersaglio dice di no
        'trigger_kind' => 'cron', 'cron' => '0 6 * * *', 'timezone' => 'UTC',
    ])->assertStatus(422)->assertJsonPath('errors.ok.0', 'Il payload dice di no.');
});

it("il dettaglio porta le prossime occorrenze e marca il cambio d'ora", function (): void {
    $r = seed(['cron' => '0 6 * * *', 'timezone' => 'Europe/Rome']);

    $data = $this->getJson(api('routines/'.$r->id))->assertOk()->json('data');

    expect($data['next_occurrences'])->toHaveCount(5)
        ->and($data['next_occurrences'][0])->toHaveKeys(['at', 'local', 'timezone_abbr', 'dst_transition'])
        ->and($data['mandate'])->toBeNull();
});

it('lo stato non si cambia con un PATCH', function (): void {
    // Il PATCH ha una allow-list: `suspend()` e `pause()` hanno regole di ripresa diverse, e
    // passare dallo stato via API le aggirerebbe entrambe.
    $r = seed();
    $r->suspend('budget_exhausted');

    $this->patchJson(api('routines/'.$r->id), ['status' => 'active', 'name' => 'Rinominata'])->assertOk();

    expect($r->fresh()->status)->toBe('suspended')
        ->and($r->fresh()->name)->toBe('Rinominata');
});

it('la sospensione esce gia scritta in italiano', function (): void {
    $r = seed();
    $r->suspend('target_not_registered');

    $data = $this->getJson(api('routines/'.$r->id))->assertOk()->json('data');

    expect($data['suspension_reason'])->toBe('target_not_registered')
        ->and($data['suspension_reason_label'])->toContain('non è più installato');
});

it('esegui adesso non tocca lo schedule e restituisce il fire', function (): void {
    $r = seed();
    $scheduled = $r->fresh()->next_run_at->toIso8601String();

    $run = $this->postJson(api('routines/'.$r->id.'/fire'), ['input' => ['testo' => 'ciao']])
        ->assertStatus(202)->json('data');

    expect($run['reason'])->toBe('manual')
        ->and($run['outcome'])->toBe('succeeded')
        ->and($r->fresh()->next_run_at->toIso8601String())->toBe($scheduled);
});

it('la stessa Idempotency-Key non esegue due volte', function (): void {
    // Due click sono due intenzioni; due invii della stessa richiesta per un rallentamento di
    // rete sono la stessa. Solo chi invia lo sa, e la chiave e' come lo dice.
    $r = seed();
    $target = collect(app(TargetRegistry::class)->all())->get('test');

    $this->withHeader('Idempotency-Key', 'k-1')->postJson(api('routines/'.$r->id.'/fire'))->assertStatus(202);
    $this->withHeader('Idempotency-Key', 'k-1')->postJson(api('routines/'.$r->id.'/fire'))->assertStatus(202);

    expect($target->fires)->toHaveCount(1);
});

it('una routine terminata non si lancia e non si modifica', function (): void {
    $r = seed();
    app(RoutineManager::class)->end($r, 'non serve piu');

    $this->postJson(api('routines/'.$r->id.'/fire'))->assertStatus(409);
    $this->patchJson(api('routines/'.$r->id), ['name' => 'x'])->assertStatus(409);
});

it('la copia non eredita il mandato e nasce in pausa', function (): void {
    // Il consenso era stato dato per QUELLA routine: trasferirlo autorizzerebbe qualcosa che
    // nessuno ha approvato.
    $r = seed();
    app(RoutineManager::class)->grantMandate($r, ['test.do'], budgetCeiling: 10.0);

    $copy = $this->postJson(api('routines/'.$r->id.'/duplicate'))->assertStatus(201)->json('data');

    expect($copy['mandate'])->toBeNull()
        ->and($copy['status'])->toBe('paused')
        ->and($copy['name'])->toBe('Report del mattino (copia)');
});

it('la coda delle richieste mette la piu vecchia per prima', function (): void {
    app(TargetRegistry::class)->register(new RecordingTarget('test', fn () => TargetResult::paused(
        'Posso?', metadata: ['action_class' => 'test.do'],
    )));
    $a = seed(['name' => 'Vecchia']);
    $b = seed(['name' => 'Nuova']);

    app(RoutineManager::class)->fireNow($a);
    app(RoutineManager::class)->fireNow($b);
    RoutineRun::query()->orderBy('created_at')->first()->forceFill(['created_at' => now()->subHours(4)])->save();

    $data = $this->getJson(api('attention'))->assertOk()->json('data');

    expect($data)->toHaveCount(2)
        ->and($data[0]['routine_name'])->toBe('Vecchia')
        ->and($data[0]['can_approve'])->toBeTrue()
        ->and($data[0]['question'])->toBe('Posso?');
});

it('il rifiuto senza motivo torna 422, non un rifiuto muto', function (): void {
    app(TargetRegistry::class)->register(new RecordingTarget('test', fn () => TargetResult::paused('Posso?', metadata: ['action_class' => 'x'])));
    $r = seed();
    app(RoutineManager::class)->fireNow($r);
    $run = RoutineRun::first();

    $this->postJson(api('runs/'.$run->id.'/reject'), [])->assertStatus(422)
        ->assertJsonPath('errors.reason.0', fn (string $m): bool => str_contains($m, 'ledger'));
});

it("l'approvazione riprende il fire", function (): void {
    $seen = 0;
    app(TargetRegistry::class)->register(new RecordingTarget('test', function () use (&$seen): TargetResult {
        $seen++;

        return $seen === 1 ? TargetResult::paused('Posso?', metadata: ['action_class' => 'x']) : TargetResult::succeeded('fatto');
    }));
    $r = seed();
    app(RoutineManager::class)->fireNow($r);
    $run = RoutineRun::first();

    $data = $this->postJson(api('runs/'.$run->id.'/approve'), ['note' => 'ok'])->assertOk()->json('data');

    expect($data['outcome'])->toBe('succeeded')
        ->and($data['reason'])->toBe('resumed')
        ->and($data['resolved_by'] ?? null)->toBeNull();  // il resolved_by e' sul run in pausa, non sulla ripresa
});

it('rispondere due volte alla stessa richiesta non esegue due volte', function (): void {
    $seen = 0;
    app(TargetRegistry::class)->register(new RecordingTarget('test', function () use (&$seen): TargetResult {
        $seen++;

        return $seen === 1 ? TargetResult::paused('Posso?', metadata: ['action_class' => 'x']) : TargetResult::succeeded('fatto');
    }));
    $r = seed();
    app(RoutineManager::class)->fireNow($r);
    $run = RoutineRun::first();

    $this->postJson(api('runs/'.$run->id.'/approve'))->assertOk();
    $this->postJson(api('runs/'.$run->id.'/approve'))->assertOk();

    expect($seen)->toBe(2);   // il fire iniziale + una sola ripresa
});

it('la panoramica mette per prima la cosa che chiede una risposta', function (): void {
    app(TargetRegistry::class)->register(new RecordingTarget('test', fn () => TargetResult::paused('Posso?', metadata: ['action_class' => 'x'])));
    $r = seed();
    app(RoutineManager::class)->fireNow($r);

    $data = $this->getJson(api('stats/overview'))->assertOk()->json('data');

    expect($data['awaiting_human'])->toBe(1)
        ->and($data['oldest_awaiting_since'])->not->toBeNull()
        ->and($data['active_routines'])->toBe(1);
});

it('la timeline non ha buchi: un giorno senza esecuzioni vale zero', function (): void {
    // Un grafico con i buchi mente sulla cadenza.
    $data = $this->getJson(api('stats/timeline?days=7'))->assertOk()->json('data');

    expect($data)->toHaveCount(7)
        ->and($data[0])->toHaveKeys(['date', 'succeeded', 'failed', 'skipped', 'paused']);
});

it('la salute diagnostica lo scheduler fermo, non lo constata soltanto', function (): void {
    $data = $this->getJson(api('health'))->assertOk()->json('data');

    expect($data['tick_healthy'])->toBeFalse()
        ->and($data['tick_diagnosis'])->toContain('schedule:run');
});

it('il tick lascia il proprio battito, e la salute lo vede', function (): void {
    $this->artisan('routines:tick')->assertSuccessful();

    $data = $this->getJson(api('health'))->assertOk()->json('data');

    expect($data['tick_healthy'])->toBeTrue()
        ->and($data['tick_diagnosis'])->toBeNull();
});

it('senza policy definite si ottiene sola lettura', function (): void {
    // Fail-closed. Il costo di dover definire le policy e' un fastidio; il costo del contrario
    // e' un'automazione avviata da chi non doveva.
    foreach (['routines.read', 'routines.write', 'routines.fire', 'routines.approve'] as $ability) {
        Gate::define($ability, fn (?object $user): bool => false);
    }
    $r = seed();

    $this->getJson(api('routines'))->assertStatus(403);

    Gate::define('routines.read', fn (?object $user): bool => true);
    $this->getJson(api('routines'))->assertOk();
    $this->postJson(api('routines/'.$r->id.'/fire'))->assertStatus(403);
    $this->patchJson(api('routines/'.$r->id), ['name' => 'x'])->assertStatus(403);
});

it('gli errori sono problem+json', function (): void {
    $this->getJson(api('routines/inesistente'))
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://padosoft.dev/problems/not-found');
});
