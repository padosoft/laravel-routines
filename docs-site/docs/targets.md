---
title: Targets
description: How the core stays ignorant of what it runs — and why that is the whole architecture.
---

# Targets

The core has **no list of what can be run**. Packages that know a domain register a `RoutineTarget`
from their own service provider, and the core does not change by a line.

It is the same pattern as `ReviewableSource` in `laravel-iam-server`, for the same reason:
orchestrating and knowing a domain are two different responsibilities, and keeping them apart is
what lets an optional package walk in — and walk out — without leaving the core holding a broken
reference.

## The interface

```php
interface RoutineTarget
{
    public function type(): string;                    // stable forever
    public function descriptor(): TargetDescriptor;    // so a panel can draw a form for it
    public function validate(array $payload): ValidationResult;
    public function fire(RoutineExecution $execution): TargetResult;
}
```

Implement it against **`padosoft/laravel-routines-contracts`**, which has no dependencies of its
own. A target must not depend on `laravel-routines`: it knows its domain, not the scheduler.

## `type()` is stable forever

Changing it orphans history — and history is evidence. Past routines would point at a type that no
longer exists and become neither runnable nor readable.

When a type disappears (the package was uninstalled) the core does **not** retry: retrying will not
bring it back. It suspends the routine with `target_not_registered` and says so in words.

## `validate()` runs at creation

Not at fire time. The moment matters: a broken payload found at the first 3am fire is a silent
failure inside a log nobody reads; found while the user is filling the form, it is an error message.

Return field-keyed errors — the destination is a form, not a log:

```php
return ValidationResult::invalid(['email' => ['That is not a valid address.']]);
```

## `fire()` does not throw for expected failures

That is `TargetResult::failed()`. An exception means "something happened I had not considered", and
the core treats it as such: it becomes a readable failure with the exception class in metadata.

Four outcomes, and the fourth is the one that matters:

| Outcome | Meaning | Retried? |
|---|---|---|
| `succeeded` | Done | no |
| `failed` | Broken. Try again | **yes**, with exponential backoff |
| `skipped` | Nothing to do. Not a failure | no |
| `paused` | **Stopped, needs a human** | no — it waits for a person, not a backoff |

Without `paused`, whoever implements a target is forced to choose between **proceeding without
permission** and **failing silently**. Neither is what should happen when an automation meets
something its mandate does not cover.

## Bundled: `JobTarget`

Queues an application job. Useful in itself, and the reference example of how a target is written.

::: callout warning "The allow-list is the boundary, not a precaution"
A routine's payload is **data**: it comes from a form, it can come from an import, and in a later
phase it can come from an assistant that proposed it. Instantiating whatever class is found in
there would give that data the right to execute anything in the application.

So runnable jobs are declared in config, and the allow-list is re-checked **at fire time** too: a
routine created when a job was permitted must not keep running after it was removed.
:::

```php
'targets' => [
    'job' => [
        'enabled' => true,
        'allowed' => [App\Jobs\SendDailyDigest::class],   // empty by default
    ],
],
```

## From the ecosystem: `FlowTarget`

`padosoft/laravel-flow` registers a `flow` target when this package is installed. Three seams make
it work rather than quietly do the wrong thing:

1. **Idempotency comes from the routine.** The flow run carries the fire's key, so a retry resumes
   the same run instead of starting a second one.
2. **A paused flow is a paused fire.** `FlowRun::STATUS_PAUSED` means an approval gate is waiting
   for a person: it maps to `TargetOutcome::Paused`.
3. **The delegated subject travels in the options, never in the input.** `flow_runs.input` is
   persisted unredacted.

::: callout tip "There is deliberately no AgentTarget"
An agentic routine is a `FlowTarget` pointing at a flow whose node is a bounded agent. Running an
agent *outside* a flow would run it outside the place where the tool allow-list, the taint
analysis, the approval gates and the run ledger live — a convenience that would function as a hole.
:::
