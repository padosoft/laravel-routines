<?php

declare(strict_types=1);

use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\Scheduling\RoutineScheduler;

function routine(array $attributes = []): Routine
{
    return Routine::create(array_merge([
        'owner' => 'user:u1',
        'name' => 'Test',
        'target_type' => 'test',
        'target_payload' => [],
        'trigger_kind' => 'cron',
        'cron' => '0 6 * * *',
        'timezone' => 'Europe/Rome',
    ], $attributes));
}

it('calcola il prossimo fire nel fuso del proprietario, non in UTC', function (): void {
    // 6:00 a Roma d'inverno è 5:00 UTC. Chi calcola in UTC manda il report un'ora dopo, tutti
    // i giorni, e se ne accorge a marzo quando l'errore cambia segno.
    $r = routine(['cron' => '0 6 * * *', 'timezone' => 'Europe/Rome']);
    $next = app(RoutineScheduler::class)->nextRunAt(
        $r,
        new DateTimeImmutable('2026-01-15 00:00:00', new DateTimeZone('UTC'))
    );

    expect($next?->format('Y-m-d H:i'))->toBe('2026-01-15 05:00')
        ->and($next?->setTimezone(new DateTimeZone('Europe/Rome'))->format('H:i'))->toBe('06:00');
});

it("in estate lo stesso cron cade a un'ora UTC diversa", function (): void {
    $r = routine(['cron' => '0 6 * * *', 'timezone' => 'Europe/Rome']);
    $next = app(RoutineScheduler::class)->nextRunAt(
        $r,
        new DateTimeImmutable('2026-07-15 00:00:00', new DateTimeZone('UTC'))
    );

    expect($next?->format('H:i'))->toBe('04:00')
        ->and($next?->setTimezone(new DateTimeZone('Europe/Rome'))->format('H:i'))->toBe('06:00');
});

it("in autunno non ripete l'occorrenza locale che l'ora legale sdoppia", function (): void {
    // 25 ottobre 2026, Roma torna indietro alle 3:00: le 02:30 locali esistono due volte.
    // Senza il ricordo dell'occorrenza locale già eseguita, il fire partirebbe due volte.
    $r = routine(['cron' => '30 2 * * *', 'timezone' => 'Europe/Rome']);
    // Non e' mass-assignable: lo scrive il dispatcher dopo il fire.
    $r->forceFill(['last_local_occurrence' => '2026-10-25 02:30'])->save();

    $next = app(RoutineScheduler::class)->nextRunAt(
        $r,
        new DateTimeImmutable('2026-10-25 00:35:00', new DateTimeZone('UTC')) // = 02:35 CEST
    );

    // Salta la seconda 02:30 locale e va al giorno dopo.
    expect(
        $next?->setTimezone(new DateTimeZone('Europe/Rome'))->format('Y-m-d H:i')
    )->toBe('2026-10-26 02:30');
});

it("in primavera esegue l'orario che non esiste appena l'orologio lo supera", function (): void {
    // 29 marzo 2026: alle 2:00 Roma passa alle 3:00, quindi le 02:30 quel giorno non esistono.
    // Il fire NON va perso: scivola in avanti allo stesso giorno, alle 03:30. E' il comportamento
    // giusto e va pinnato, perche' l'alternativa silenziosa - saltare il giorno - farebbe sparire
    // un report una volta l'anno senza che nessun log lo dica.
    $r = routine(['cron' => '30 2 * * *', 'timezone' => 'Europe/Rome']);
    $next = app(RoutineScheduler::class)->nextRunAt(
        $r,
        new DateTimeImmutable('2026-03-29 00:00:00', new DateTimeZone('UTC'))
    );

    expect($next?->setTimezone(new DateTimeZone('Europe/Rome'))->format('Y-m-d H:i'))
        ->toBe('2026-03-29 03:30');
});

it('elenca le occorrenze perse, con un tetto', function (): void {
    $r = routine([
        'cron' => '*/5 * * * *',
        'timezone' => 'UTC',
    ]);
    $r->forceFill(['next_run_at' => new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'))])->save();

    $missed = app(RoutineScheduler::class)->missedOccurrences(
        $r,
        new DateTimeImmutable('2026-01-01 06:00:00', new DateTimeZone('UTC')),
        cap: 10,
    );

    // Sei ore a 5 minuti sono 72 occorrenze: il tetto è ciò che impedisce a un downtime di
    // diventare uno sciame di 72 esecuzioni.
    expect($missed)->toHaveCount(10)
        ->and($missed[0]->format('H:i'))->toBe('00:00');
});
