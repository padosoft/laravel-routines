---
title: Triggers
description: Cron, one-shot, manual, application event and signed webhook — and the one decision each of them forces.
---

# Triggers

A routine has one trigger, and may be fired manually regardless. Each non-cron trigger forces the
same question, and it has to be answered once and properly: **when are two arrivals the same fact,
and when are they two facts?**

## Cron

Evaluated in the **owner's timezone**, never the server's. `0 6 * * 1-5` means *their* 6am.

The scheduler answers "what is due" (`next_run_at <= now`), never "which cron matches this minute".
A six-minute deploy does not make the 6:03 occurrence disappear: it is found on the next tick.

Both directions of daylight saving are handled, and both are pinned by tests — see
[Guarantees](/guarantees).

## One-shot (`once_at`)

Fires once and then closes itself with `ended_reason = completed`. Leaving it "active" forever with
nothing to do would be less honest than closing it.

## Manual

No schedule at all. Fired from the panel, the API or `RoutineManager::fireNow()`.

Each manual fire gets a **new** idempotency key by default: two clicks on "run now" are two
intentions, and merging them would be as wrong as duplicating. Pass a key explicitly when you know
two requests are the same intent.

## Application event

```php
Event::listen(OrderPlaced::class, function (OrderPlaced $event): void {
    app(EventTrigger::class)->handle('order.placed', $event);
});
```

Every emission gets a new key by default. **Deduplicating two `OrderPlaced` would be worse than
running them twice** — it would mean not shipping an order.

If your event *can* arrive twice for the same fact (an at-least-once delivery from a queue), expose
`idempotencyKey(): string` on the event and deduplication happens. The sender is the only one who
knows: there is no way to guess it here.

Only scalars travel from the event object into the fire input — the input lands in the ledger, and
a whole model would bring relations and sometimes data that must not leave. Need more? Expose
`toRoutineInput(): array` and decide yourself what goes.

## Signed webhook

```php
$secret = app(RoutineManager::class)->rotateWebhookSecret($routine);
// Returned in clear ONCE. From then on it exists only encrypted in the column.
```

The sender signs with HMAC-SHA256 and calls `POST /hooks/routines/{id}`:

```
X-Routines-Timestamp: 1788000000
X-Routines-Signature: hmac_sha256("{timestamp}.{raw body}", secret)
X-Routines-Delivery:  <delivery id>       ← becomes the idempotency key
```

The route sits **outside the session guard**: a machine calls it, and it has no cookies and no CSRF
— and must not have them. The signature is the only authentication, so every detail of the
verification is a defence whose failure would be silent:

| Choice | What it prevents |
|---|---|
| Sign the **raw** body, not the re-serialized JSON | One normalized `+` and every legitimate delivery fails — or one that should not have passes |
| `hash_equals`, never `===` | A comparison that exits at the first differing byte tells you, in the timing, how many bytes were right |
| A 5-minute window on the **signed** timestamp | Without it, an intercepted delivery stays valid forever and can be replayed next year |
| The delivery id is the key | Webhook deliveries are at-least-once **by construction**: the sender guarantees "at least one", never "exactly one" |
| Unknown routine and secret-less routine → **same response** | Distinguishing them would tell an id-guesser which ids exist |

A paused routine answers `409`, not `403`: the signature was valid, and the caller must be able to
tell "you are not authorized" from "it is stopped".

::: callout tip "Encrypted, not hashed"
Verifying an HMAC requires the real secret at comparison time, so hashing it is not an option. The
most that can be achieved is that a database dump does not hand it over — and that whoever loses it
has to rotate it rather than read it back.
:::
