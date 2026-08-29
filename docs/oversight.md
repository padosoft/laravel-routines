---
title: Oversight
description: What watches an automation that runs when nobody is there — anomaly detection, the AI Act register, and the target contract shipped as tests.
---

# Oversight

An unattended routine has a property no interactive system has: **when it goes wrong, nobody is
looking.** Everything on this page exists because of that one sentence.

## The failure nothing else can see

A routine that paused to ask, and that nobody answered, is the worst failure in the system — and it
is invisible by construction. The routine is doing exactly what it is supposed to do: it met
something its mandate does not cover, and it did not act without permission. So it raises no
exception, writes no error, trips no monitor. It just waits, forever, while the thing it was
supposed to do quietly does not happen.

Nothing in this package can detect that, because from inside there is nothing wrong.
[`laravel-rebel-ai-guard`](https://github.com/padosoft/laravel-rebel-ai-guard) detects it from the
outside, by reading the `routine_runs` ledger:

| Rule | What it means |
| --- | --- |
| `routine_approval_starvation` | Paused runs unanswered beyond a threshold (default 24h). The case names the **oldest unanswered question** so a human can answer from there |
| `routine_fire_burst` | A routine firing far above its schedule — a runaway event emitter, or replayed webhook deliveries that each carry a distinct delivery id, which idempotency correctly treats as distinct facts |
| `routine_failure_loop` | Repeated failures. The engine retries *within* one occurrence and then gives up; the next occurrence starts over, so nothing looks **across** occurrences |
| `routine_mandate_probing` | Repeated pauses, broken down by action class — even when every one gets approved, it means the consent no longer describes what the routine does, and needs renegotiating rather than approving daily |

Detection is advisory by default. Opt in with `REBEL_AIGUARD_ROUTINE_AUTO_SUSPEND=true` and a
High/Critical case also suspends the routine through the `RoutineLifecycle` port — High/Critical
only, and a suspension failure never breaks detection: the case stays open, and a human still sees
it.

Suspending never blocks answering the questions already pending. Those live on the run, not on the
routine — which is what makes suspension the *safe* response to starvation rather than a way to bury
it.

## The mandate is the AI Act record

Article 14 asks for effective human oversight of an AI system. For an unattended routine the
oversight is the **standing mandate**: a named person decided, in advance and bound to the digest of
an approved payload, what it may do on its own.

[`laravel-ai-act-compliance`](https://github.com/padosoft/laravel-ai-act-compliance) records that
automatically once its `routines` bridge is enabled:

- the mandate becomes an `approved` oversight record carrying action classes, budget ceiling and the
  **payload digest** — the part that makes the consent verifiable later, because it says what the
  person actually said yes to;
- the routine enters the Art. 6 risk register the moment authority is granted, not the first time it
  fires: those are different days, and the register should show the earlier one;
- every pause opens a `pending` item, closed by the human's answer with who and why;
- a suspension moves the register entry to `mitigating` with the reason.

The bridge listens to `RoutineResolved` — the answer — and not to the run finishing. On approval the
work resumes and ends later, sometimes much later, sometimes failing. *"The human said yes"* and
*"the work succeeded"* are different facts, and an oversight ledger that conflates them cannot
answer the question it exists for.

## The contract your target must satisfy, as tests

The package's own suite proves the **engine** keeps its guarantees. Your target is code you write,
and no engine guarantee covers it. `Testing\TargetContract` turns the rules that make a target safe
into assertions:

```php
use Padosoft\Routines\Testing\TargetContract;

public function test_it_respects_the_routine_target_contract(): void
{
    TargetContract::assertAll(
        new InvoiceReminderTarget(...),
        validPayload: ['template' => 'reminder'],
        invalidPayload: ['template' => ''],
        outOfMandateInput: ['action' => 'invoice.write_off'],
    );
}
```

| Assertion | The silent failure it prevents |
| --- | --- |
| The payload is validated, with the error on the field | A broken payload discovered at 3am in a log nobody reads, instead of in the form |
| The descriptor declares its action classes | No mandate can authorise it, and no pause can tell a human **what** they are approving |
| Re-firing with the same idempotency key gives the same outcome | A network timeout becomes a second email actually sent |
| Outside the mandate it **stops** (`paused` or `MandateExceeded`), it does not fail | Failing makes the routine give up silently; succeeding means it acted without permission |

Both pause forms are accepted, because the engine treats them identically — a kit that accepted only
one would force people to rewrite correct code.

Every assertion in the kit has, in this package's suite, a test that watches it **fail** against a
target that violates it. A kit that passes on anything proves nothing.
