---
title: Mandate
description: Delegated identity for an automation that runs when nobody is there to confirm.
---

# The mandate

## Why the ordinary token exchange is not enough

Interactive delegation (RFC 8693) presents the user's token as `subject_token`, and the issuer
checks that **their session is still alive**. That is the property that makes delegation safe while
the user is at the screen: if they logged out, the agent stops.

A routine runs at 3am. That session does not exist. The constraint cannot simply be dropped — it is
what stops a delegation from outliving the user — so it has to be **replaced**, and the replacement
has to be stated:

| Interactive delegation | A routine's delegation |
|---|---|
| A live session proves the user is present | A **standing mandate**, consented once with step-up and bound to the payload digest |
| The user sees every action as it happens | The mandate **limits in advance**: action classes, spend ceiling, window |
| Out of scope ⇒ ask on screen | Out of scope ⇒ **the fire stops** and the question leaves on a channel |
| Revoked ⇒ no further exchange | Revoked ⇒ no further exchange, **checked at every fire** |

The row that is *not* in that table is the important one: **there is no case in which the routine
proceeds without cover.** If the mandate does not cover, it stops. If the delegation is gone, it
suspends.

## Granting one

```php
app(RoutineManager::class)->grantMandate(
    $routine,
    actionClasses: ['order.create'],
    budgetCeiling: 500.0,
    notAfter: new DateTimeImmutable('+90 days'),
    delegationGrantId: $grant->id,
    confirmationId: $stepUp->id,     // the step-up evidence
    aal: 'aal2',
);
```

The digest of the **approved payload** is computed here rather than accepted, and that is the
point: if someone edits the payload later, the digest no longer matches and `mandateCovers()`
returns `false`. The consent was for **that** configuration, not for the routine in general — the
same principle as PSD2 dynamic linking, where changing the amount after confirmation invalidates
the confirmation.

An empty `actionClasses` authorises **nothing**, not everything. Fail-closed.

## Getting the token

`RoutineDelegationBroker::tokenFor()` is asked **before any work**. Asking afterwards would mean
discovering you were not authorized to do something you have already done.

It **throws** rather than returning null, because a null reads as "run as the application" — which
is full authority. When it throws `DelegationUnavailable` the routine is **suspended**, not
retried: a backoff does not bring back a consent that was withdrawn.

::: callout tip "Two different situations, kept apart"
Running as the application is a **choice** (no `delegation_grant_id`, `isDelegated()` is false).
Declaring a delegation that nobody can issue is a **broken configuration**, and it suspends. The
default broker throws with a message naming the package to install.
:::

## Stopping and asking

```php
public function fire(RoutineExecution $execution): TargetResult
{
    if ($amount > ($execution->budgetRemaining ?? INF)) {
        return TargetResult::paused(
            sprintf('It wants to place an order for %s. The mandate covers up to %s.', …),
            pendingApprovalId: $approval->id,
            resumeToken: 'step-3',
            metadata: ['action_class' => 'order.create', 'amount' => $amount],
        );
    }
    …
}
```

Throwing `MandateExceeded` works too and the core converts it — that is the most natural mistake to
make when writing a target, and treating it as an ordinary failure would be the worst outcome: the
routine would give up silently instead of asking.

The `question` is **already written text**. It is the evidence of what a person approved, so it is
not recomposed downstream: a text rebuilt by the panel or by a channel could diverge from the one
approved, and at that point the approval proves nothing.

## Escalation

`RoutineEscalator` carries the question out of the application —
`laravel-rebel-channels` (Telegram, WhatsApp, SMS, voice) is the reference implementation.

**It informs; it does not approve.** A "yes" written in a chat is not a confirmation, because
anyone holding that phone could have written it. The channel carries the question and the link; the
approval happens where identity is verified.

If delivery fails everywhere, the fire records it (`escalation_error`) and the question still shows
in the panel. A stopped routine waiting on a question nobody ever received is the worst failure in
the system, and it must be visible on the fire — not only in a log.

## Answering

```php
app(RoutineManager::class)->resolve($run, approved: true,  resolvedBy: 'user:42', note: 'ok');
app(RoutineManager::class)->resolve($run, approved: false, resolvedBy: 'user:42', note: 'Wrong supplier');
```

- **Approving** opens a new attempt with the **same idempotency key**: for the target it is the
  same work, so it resumes instead of repeating what it had already done before stopping.
- **Rejecting** closes it as `skipped` with the reason — not `failed`. Nothing broke: someone
  decided no, and `failed` would retry it. The reason is mandatory, because someone will read it.
- Answering twice does nothing the second time.
