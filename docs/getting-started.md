---
title: Getting started
description: Install, write a target, create a routine — in about five minutes.
---

# Getting started

## Install

```bash
composer require padosoft/laravel-routines
php artisan vendor:publish --tag=routines-migrations
php artisan migrate
```

The tick registers itself in Laravel's scheduler. All it needs is for the scheduler to run:

```bash
* * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
```

::: callout tip "If nothing ever fires, check this first"
Nine times out of ten the answer is that the system cron is not running. The `/health` endpoint says
so in those words rather than making you guess — see [Admin API](/admin-api).
:::

## 1. Write a target

A **target** is a category of work a routine can start. The core does not know what it runs: a
flow, an agent, a job, a query — the target knows the domain, the core knows *when*, *on whose
behalf*, *under what ceiling* and *with what outcome*.

```php
use Padosoft\Routines\Contracts\Target\{RoutineTarget, TargetDescriptor, TargetResult, ValidationResult};
use Padosoft\Routines\Contracts\Execution\RoutineExecution;

final class SendDigestTarget implements RoutineTarget
{
    // Stable forever: changing it orphans history, which is evidence.
    public function type(): string { return 'digest'; }

    public function descriptor(): TargetDescriptor
    {
        return new TargetDescriptor(
            label: 'Email digest',
            summary: 'Sends the period summary to an address.',
            fields: ['email' => ['label' => 'Recipient', 'type' => 'email', 'required' => true]],
            actionClasses: ['email.send'],   // what the user authorises in the mandate
            reportsCost: true,
        );
    }

    public function validate(array $payload): ValidationResult
    {
        return filter_var($payload['email'] ?? '', FILTER_VALIDATE_EMAIL)
            ? ValidationResult::valid()
            : ValidationResult::invalid(['email' => ['That is not a valid address.']]);
    }

    public function fire(RoutineExecution $execution): TargetResult
    {
        Mail::to($execution->payload('email'))->send(
            new Digest($execution->scheduledFor, $execution->timezone, $execution->idempotencyKey)
        );

        return TargetResult::succeeded('Digest sent.', cost: 0.001);
    }
}
```

Register it from **your** service provider:

```php
$this->app->make(TargetRegistry::class)->register(new SendDigestTarget);
```

::: callout warning "Do not generate your own idempotency key"
It arrives in `$execution->idempotencyKey` and is **stable across retries**. A key generated inside
the target would be different on every attempt — exactly the bug the key exists to prevent.
:::

## 2. Create a routine

```php
$routine = app(RoutineManager::class)->create([
    'owner'          => 'user:'.$user->id,
    'name'           => 'Morning digest',
    'target_type'    => 'digest',
    'target_payload' => ['email' => $user->email],
    'trigger_kind'   => 'cron',
    'cron'           => '0 6 * * 1-5',
    'timezone'       => 'Europe/Rome',   // THEIR 6am, not UTC's
    'budget_per_run' => 0.50,
    'max_attempts'   => 3,
]);
```

The payload is validated **now**, while a human is looking at the form. A broken payload discovered
at the first 3am fire is a silent failure inside a log nobody reads.

## 3. Check what it will actually do

```php
app(RoutineManager::class)->preview($routine, 5);
// ["2026-09-01 06:00", "2026-09-02 06:00", …] — in the owner's timezone
```

This is the single most useful thing in the package. `0 6 * * 1-5` tells nobody anything; five dates
written out show immediately that the time is off by a timezone — before the routine runs that way
for a month.

## Commands

```bash
php artisan routines:tick --limit=100   # one pass (normally the scheduler calls it)
php artisan routines:list --status=active
```
