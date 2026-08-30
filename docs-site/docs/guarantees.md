---
title: Guarantees
description: Locks, idempotency, daylight saving, catch-up and retries — the five edge cases a scheduler is judged on.
---

# Guarantees

A scheduling package is judged on five edge cases. All five are pinned by tests that fail if the
guard is removed.

## A missed tick does not lose an execution

The dispatcher asks **"what is due"** (`next_run_at <= now`), never **"which cron matches this
minute"**. A six-minute deploy does not make the 6:03 occurrence disappear: it is found on the next
tick.

Anyone computing from the tick loses executions silently — the worst way to lose them.

## Two workers produce one fire, not two

Two independent mechanisms, and neither is an `if`:

1. **The lock is a conditional `UPDATE`** — the arbiter is the database.
2. **`unique(routine_id, idempotency_key, attempt)`** closes the race the lock did not see: the
   second insert fails and that tick withdraws silently.

The lock has a **TTL**, and it exists for the case nobody considers: the worker that **dies holding
it**. Without the TTL that routine is stopped forever, and there is no error anywhere.

## Daylight saving has two traps, and they are different

Most schedulers handle one and ignore the other.

| | What happens | Behaviour |
|---|---|---|
| **Spring** (29 Mar 2026, Rome) | 02:30 local **does not exist** | The fire slides to 03:30 the same day. It is not lost. |
| **Autumn** (25 Oct 2026, Rome) | 02:30 local exists **twice**, at two distinct UTC instants | The last executed *local* occurrence is remembered and the repeat is skipped. **One fire, not two.** |

It happens twice a year and nobody remembers it — which is why both are pinned by tests that fail
if the guard is removed.

## A downtime does not become a swarm

A routine every 5 minutes, down for a day, has 288 pending occurrences. Replaying them all means
288 emails in thirty seconds.

Recovery is capped (`catch_up_cap`, default 25), and the policy is an **explicit field** because it
is a domain choice, not a technical default:

- `catch_up` — a daily report is worth recovering.
- `skip_to_next` — a 9am reminder delivered at 2pm is noise, and five of them are noise five times.

## The run row is written before the work

A process killed halfway leaves an open run, which is visible and recoverable. Writing at
known-outcome would leave an email sent and no trace of it — the shortest path to sending it twice.

## Retries have an instant, not a counter

Exponential backoff from `retry_base_seconds`: 1', 2', 4', 8'. The next attempt is a **timestamp**
(`retry_at`), so the delay survives a restart like any other scheduled thing.

A **paused** fire is never retried on a backoff: it is waiting for a person.

## Overlap

What to do when it is time to fire and the previous one is still running:

| Policy | Behaviour |
|---|---|
| `skip` (default) | Skip, and **record the skip**. "Nothing happened" and "I decided not to" are different things, and the second must be visible in a panel. |
| `queue` | Leave the schedule untouched: the next tick retries, and meanwhile the previous one finishes. |
| `overlap` | Run them together. Only for targets that know they are concurrent. |

The default is `skip`, and not out of laziness: a routine every 5 minutes whose fire takes 7
accumulates executions until the queue falls over.

## Spend ceilings

Scopes limit **what** a routine may do; the ceiling limits **how much**. It is the second defence,
and the one that is missing everywhere: a routine perfectly authorized to "send email", called a
thousand times by a runaway loop, has violated no permission — it has just sent a thousand emails.

Checked **before and after** every fire: before is not enough on its own (the first overrun comes
from a fire that was still within limits beforehand), after is not enough on its own (it discovers
the overrun once the money is spent).

On exhaustion the routine **suspends**, it does not skip: skipping would mean meeting the same
exceeded ceiling every hour for the rest of the month. The period is computed **in the owner's
timezone**, and the message says **when it resumes** — without that, the only action left to the
reader is raising the ceiling, which is often not the right thing to do.
