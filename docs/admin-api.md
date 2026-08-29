---
title: Admin API
description: The REST surface the panel consumes — and anything else that wants to drive routines.
---

# Admin API

It lives in the **core**, not in the panel package. Deliberately: if the panel is replaced tomorrow
the API remains, and with it everything integrated on top. Same separation as `iam-console` and
`iam-server`.

```php
// config/routines.php
'api' => [
    'prefix' => 'api/routines/v1',
    'middleware' => ['web', 'auth'],   // your guard
],
```

## Permissions are fail-closed

Resolved through the host application's Gate: `routines.read`, `routines.write`, `routines.fire`,
`routines.approve`. An ability with no policy defined is **denied**, except `routines.read`.

An application that mounts the API without defining anything gets a read-only panel — the right
default for a tool that starts automations. The cost of having to define policies is an annoyance;
the cost of the opposite is an automation started by someone who should not have.

## The server writes the human-readable text

`schedule_human`, `suspension_reason_label`, `tick_diagnosis`, and a fire's `question`.

A client translating `target_not_registered` into a sentence would translate it differently from
the CLI, the notification email and the audit — and three people looking at the same event would
read three different things.

## Endpoints

| Method | Path | Notes |
|---|---|---|
| `GET` | `/capabilities` | What is installed. Call it first. |
| `GET` | `/targets` | Registry descriptors — including types **used but not registered** |
| `POST` | `/schedule/preview` | Next occurrences + the sentence, **without needing an existing routine** |
| `GET` `POST` | `/routines` | List (cursor paging) · create |
| `GET` `PATCH` | `/routines/{id}` | Detail · update |
| `POST` | `/routines/{id}/pause` · `/resume` · `/end` · `/fire` · `/duplicate` | |
| `GET` | `/attention` | Fires waiting for an answer, **oldest first** |
| `GET` | `/runs` · `/runs/{id}` | The ledger |
| `POST` | `/runs/{id}/approve` · `/reject` | Reject requires a reason |
| `GET` | `/stats/overview` · `/stats/timeline` · `/health` | |

## Details worth knowing

**`/schedule/preview` needs no routine.** It is used in the creation wizard, while the user is still
typing — which is where a preview prevents a mistake, not after saving.

**`next_occurrences` flags `dst_transition`.** The occurrence where the offset changes relative to
the previous one: the UTC instant moves by an hour while the *local* time stays the same. It is the
question users ask themselves twice a year looking at the logs.

**Duplicating does not inherit the mandate,** and the copy starts paused. The consent was given for
*that* routine; transferring it would authorise something nobody approved.

**Answering the same request twice returns `200` with the current state,** not an error. The second
answer is not the caller's mistake, and it must not produce a second execution.

**Sorting is on an allow-list.** `sort` arrives from the query string, and passing it to `orderBy`
unfiltered would let a parameter choose which column to interrogate.

**The timeline has no holes.** The full series is built and then filled: a day with no executions is
a zero, not a gap. A chart with gaps lies about the cadence.

## Errors: RFC 9457

```json
{
  "type": "https://padosoft.dev/problems/validation",
  "title": "Il dato inviato non è valido",
  "status": 422,
  "detail": "email: That is not a valid address.",
  "errors": { "email": ["That is not a valid address."] }
}
```

Same format as the `iam-server` Admin API — not for cosmetic uniformity: a client that can read one
`problem+json` can read them across the whole ecosystem, and `detail` is always a sentence you can
show a person without reworking it.

## `/health` diagnoses, it does not merely report

```json
{
  "tick_healthy": false,
  "tick_diagnosis": "Ultimo tick 47 minuti fa: lo scheduler di Laravel non sta girando, e finché non riparte nessuna routine partirà. Controlla il cron di sistema (`* * * * * php artisan schedule:run`) e che il processo sia vivo.",
  "overdue": [...],
  "stuck_locks": [...],
  "targets": [{ "type": "flow", "registered": false, "routines_count": 3 }]
}
```

A panel saying "last tick 47 minutes ago" has informed you. One saying "the scheduler is not
running, check the cron" has solved it.

## The panel that consumes it

[`padosoft/laravel-routines-admin`](https://github.com/padosoft/laravel-routines-admin) is a React
SPA built entirely on this API — no server-side state, no Blade beyond the mount view. It is the
reference consumer, but not a privileged one: everything it does is available to anything else that
speaks HTTP.

```bash
composer require padosoft/laravel-routines-admin
php artisan vendor:publish --tag=routines-admin-assets
```

The separation is deliberate. If the panel is replaced tomorrow the API stays, and with it every
integration built on top — the same split `laravel-iam-console` has from `laravel-iam-server`.

Two things the panel relies on, and that any other client should too:

- **The server composes every human-readable string.** `schedule_human`, `suspension_reason_label`,
  `tick_diagnosis` and a paused fire's `message` are evidence, not copy. A client that translated
  `target_not_registered` on its own would translate it differently from the CLI, the notification
  email and the audit — and three people looking at the same event would read three different
  things.
- **`POST /schedule/preview` needs no routine.** It exists so a wizard can show five real dates
  *before* there is anything to save, which is what stops the product's most common bug: a schedule
  that looks obviously right and fires at another hour because the routine's timezone is not the
  author's.
