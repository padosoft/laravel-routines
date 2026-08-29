<?php

declare(strict_types=1);

use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\Models\RoutineRun;
use Padosoft\Routines\RoutineManager;
use Padosoft\Routines\Targets\TargetRegistry;
use Padosoft\Routines\Tests\Support\RecordingTarget;
use Padosoft\Routines\Triggers\EventTrigger;

final class OrderPlaced
{
    public function __construct(
        public int $orderId = 7,
        public string $customer = 'marco@acme.com',
        public ?object $model = null,
    ) {}
}

final class QueuedDelivery
{
    public function __construct(public string $deliveryId = 'd-1') {}

    public function idempotencyKey(): string
    {
        return $this->deliveryId;
    }
}

function target(): RecordingTarget
{
    if (! app(TargetRegistry::class)->has('test')) {
        app(TargetRegistry::class)->register(new RecordingTarget);
    }

    /** @var RecordingTarget */
    return app(TargetRegistry::class)->get('test');
}

function onEvent(string $eventName): Routine
{
    target();

    return app(RoutineManager::class)->create([
        'owner' => 'user:u1', 'name' => 'Su evento', 'target_type' => 'test',
        'target_payload' => [], 'trigger_kind' => 'event',
        'event_name' => $eventName, 'timezone' => 'UTC',
    ]);
}

function onWebhook(): Routine
{
    target();

    return app(RoutineManager::class)->create([
        'owner' => 'user:u1', 'name' => 'Su webhook', 'target_type' => 'test',
        'target_payload' => [], 'trigger_kind' => 'webhook', 'timezone' => 'UTC',
    ]);
}

function hookUrl(Routine $r): string
{
    return '/'.trim((string) config('routines.webhooks.prefix'), '/').'/'.$r->id;
}

// ── Evento ───────────────────────────────────────────────────────────────────

it('fa partire le routine in ascolto su quell evento, e solo quelle', function (): void {
    $t = target();
    onEvent('order.placed');
    onEvent('order.cancelled');

    app(EventTrigger::class)->handle('order.placed', new OrderPlaced);

    expect($t->fires)->toHaveCount(1)
        ->and($t->fires[0]->reason->value)->toBe('event')
        ->and($t->fires[0]->input('orderId'))->toBe(7);
});

it("dall'oggetto evento passa solo cio che e scalare", function (): void {
    // L'input finisce nel ledger: un model intero ci porterebbe relazioni e talvolta dati che non
    // devono uscire.
    $t = target();
    onEvent('order.placed');

    app(EventTrigger::class)->handle('order.placed', new OrderPlaced(model: new stdClass));

    expect($t->fires[0]->input)->toHaveKeys(['orderId', 'customer'])
        ->and($t->fires[0]->input)->not->toHaveKey('model');
});

it('due emissioni dello stesso evento sono due fatti', function (): void {
    // Deduplicarle sarebbe PEGGIO che eseguirle due volte: due `OrderPlaced` sono due ordini, e
    // fonderli significherebbe non spedirne uno.
    $t = target();
    onEvent('order.placed');

    app(EventTrigger::class)->handle('order.placed', new OrderPlaced(orderId: 1));
    app(EventTrigger::class)->handle('order.placed', new OrderPlaced(orderId: 2));

    expect($t->fires)->toHaveCount(2);
});

it("ma se l'evento dichiara una chiave, la consegna doppia non raddoppia", function (): void {
    // E' il mittente a sapere se due emissioni sono lo stesso fatto: qui non c'e' modo di
    // indovinarlo.
    $t = target();
    onEvent('queue.delivery');

    app(EventTrigger::class)->handle('queue.delivery', new QueuedDelivery('d-1'));
    app(EventTrigger::class)->handle('queue.delivery', new QueuedDelivery('d-1'));

    expect($t->fires)->toHaveCount(1);
});

it('una routine in pausa non reagisce agli eventi', function (): void {
    $t = target();
    $r = onEvent('order.placed');
    app(RoutineManager::class)->pause($r);

    app(EventTrigger::class)->handle('order.placed', new OrderPlaced);

    expect($t->fires)->toHaveCount(0);
});

// ── Webhook ──────────────────────────────────────────────────────────────────

it('una consegna firmata correttamente fa partire la routine', function (): void {
    $t = target();
    $r = onWebhook();
    $secret = app(RoutineManager::class)->rotateWebhookSecret($r);

    $body = json_encode(['ordine' => 42], JSON_THROW_ON_ERROR);
    $ts = time();

    $this->call('POST', hookUrl($r), [], [], [], [
        'HTTP_X-Routines-Timestamp' => (string) $ts,
        'HTTP_X-Routines-Signature' => RoutineManager::webhookSignature($secret, $ts, $body),
        'HTTP_X-Routines-Delivery' => 'del-1',
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertStatus(202);

    expect($t->fires)->toHaveCount(1)
        ->and($t->fires[0]->reason->value)->toBe('webhook')
        ->and($t->fires[0]->input('ordine'))->toBe(42);
});

it('una firma sbagliata non fa partire niente', function (): void {
    $t = target();
    $r = onWebhook();
    app(RoutineManager::class)->rotateWebhookSecret($r);

    $ts = time();
    $this->call('POST', hookUrl($r), [], [], [], [
        'HTTP_X-Routines-Timestamp' => (string) $ts,
        'HTTP_X-Routines-Signature' => str_repeat('a', 64),
        'CONTENT_TYPE' => 'application/json',
    ], '{}')->assertStatus(403);

    expect($t->fires)->toHaveCount(0);
});

it('una consegna vecchia e rifiutata come replay', function (): void {
    // Senza finestra, una consegna legittima intercettata resta valida per sempre.
    $t = target();
    $r = onWebhook();
    $secret = app(RoutineManager::class)->rotateWebhookSecret($r);

    $ts = time() - 3600;
    $this->call('POST', hookUrl($r), [], [], [], [
        'HTTP_X-Routines-Timestamp' => (string) $ts,
        'HTTP_X-Routines-Signature' => RoutineManager::webhookSignature($secret, $ts, '{}'),
        'CONTENT_TYPE' => 'application/json',
    ], '{}')->assertStatus(403);

    expect($t->fires)->toHaveCount(0);
});

it('la firma copre il corpo: cambiarlo la invalida', function (): void {
    $r = onWebhook();
    $secret = app(RoutineManager::class)->rotateWebhookSecret($r);
    $ts = time();
    $signature = RoutineManager::webhookSignature($secret, $ts, '{"importo":10}');

    $this->call('POST', hookUrl($r), [], [], [], [
        'HTTP_X-Routines-Timestamp' => (string) $ts,
        'HTTP_X-Routines-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], '{"importo":10000}')->assertStatus(403);
});

it("l'id di consegna e la chiave: il ritentativo del mittente non raddoppia", function (): void {
    // Le consegne webhook sono at-least-once per costruzione: il mittente garantisce "almeno
    // una", mai "esattamente una".
    $t = target();
    $r = onWebhook();
    $secret = app(RoutineManager::class)->rotateWebhookSecret($r);
    $ts = time();
    $headers = [
        'HTTP_X-Routines-Timestamp' => (string) $ts,
        'HTTP_X-Routines-Signature' => RoutineManager::webhookSignature($secret, $ts, '{}'),
        'HTTP_X-Routines-Delivery' => 'del-42',
        'CONTENT_TYPE' => 'application/json',
    ];

    $this->call('POST', hookUrl($r), [], [], [], $headers, '{}')->assertStatus(202);
    $this->call('POST', hookUrl($r), [], [], [], $headers, '{}')->assertStatus(202);

    expect($t->fires)->toHaveCount(1)
        ->and(RoutineRun::count())->toBe(1);
});

it('una routine senza segreto non e raggiungibile, e non lo dice', function (): void {
    // Stessa risposta di "non esiste": distinguerle direbbe a chi prova gli id quali esistono.
    $r = onWebhook();

    $this->call('POST', hookUrl($r), [], [], [], [
        'HTTP_X-Routines-Timestamp' => (string) time(),
        'HTTP_X-Routines-Signature' => str_repeat('a', 64),
        'CONTENT_TYPE' => 'application/json',
    ], '{}')->assertStatus(404);

    $this->call('POST', hookUrl($r).'-inesistente', [], [], [], [
        'HTTP_X-Routines-Timestamp' => (string) time(),
        'HTTP_X-Routines-Signature' => str_repeat('a', 64),
        'CONTENT_TYPE' => 'application/json',
    ], '{}')->assertStatus(404);
});

it('una routine in pausa risponde 409, non 403', function (): void {
    // La firma era giusta: chi chiama deve distinguere «non sei autorizzato» da «e' ferma».
    $r = onWebhook();
    $secret = app(RoutineManager::class)->rotateWebhookSecret($r);
    app(RoutineManager::class)->pause($r);

    $ts = time();
    $this->call('POST', hookUrl($r), [], [], [], [
        'HTTP_X-Routines-Timestamp' => (string) $ts,
        'HTTP_X-Routines-Signature' => RoutineManager::webhookSignature($secret, $ts, '{}'),
        'CONTENT_TYPE' => 'application/json',
    ], '{}')->assertStatus(409);
});

it('il segreto non e leggibile in chiaro dalla colonna', function (): void {
    $r = onWebhook();
    $secret = app(RoutineManager::class)->rotateWebhookSecret($r);

    expect($r->fresh()->webhook_secret)->not->toBe($secret)
        ->and($r->fresh()->webhook_secret)->not->toContain($secret);
});
