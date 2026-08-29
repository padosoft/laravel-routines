<?php

declare(strict_types=1);

namespace Padosoft\Routines\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Padosoft\Routines\Http\Support\Permissions;
use Padosoft\Routines\Http\Support\Problem;
use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\Models\RoutineRun;
use Padosoft\Routines\Support\Cfg;
use Padosoft\Routines\Targets\TargetRegistry;

final class StatsController
{
    /** Dove il tick lascia il proprio battito. Vedi TickCommand. */
    public const LAST_TICK_KEY = 'routines:last_tick_at';

    public function __construct(private readonly TargetRegistry $registry) {}

    public function overview(): JsonResponse
    {
        if (! Permissions::allows(Permissions::READ)) {
            return Problem::forbidden('Non hai il permesso di vedere queste statistiche.');
        }

        $awaiting = RoutineRun::query()->where('outcome', 'paused')->whereNull('resolved_at');

        $rate7 = $this->successRate(7, 0);
        $ratePrev = $this->successRate(14, 7);

        return new JsonResponse([
            'data' => [
                // Primo campo, e non per caso: e' l'unica cosa del pannello che chiede qualcosa a
                // chi lo sta guardando.
                'awaiting_human' => (clone $awaiting)->count(),
                'oldest_awaiting_since' => (clone $awaiting)->orderBy('created_at')->first()?->created_at?->toIso8601String(),

                'active_routines' => Routine::query()->where('status', 'active')->count(),
                'paused_routines' => Routine::query()->where('status', 'paused')->count(),
                'suspended_routines' => Routine::query()->where('status', 'suspended')->count(),

                'runs_24h' => RoutineRun::query()->where('created_at', '>=', now()->subDay())->count(),
                'failed_24h' => RoutineRun::query()->where('outcome', 'failed')->where('created_at', '>=', now()->subDay())->count(),

                'success_rate_7d' => $rate7,
                'success_rate_delta' => ($rate7 === null || $ratePrev === null) ? null : round($rate7 - $ratePrev, 4),

                'spend_7d' => (float) RoutineRun::query()->where('created_at', '>=', now()->subDays(7))->sum('cost'),
                'budget_utilisation' => $this->budgetUtilisation(),
                'currency' => Cfg::string('routines.defaults.currency', 'EUR'),
            ],
        ]);
    }

    public function timeline(Request $request): JsonResponse
    {
        if (! Permissions::allows(Permissions::READ)) {
            return Problem::forbidden('Non hai il permesso di vedere queste statistiche.');
        }

        $rawDays = $request->query('days', '30');
        $days = min(90, max(1, is_numeric($rawDays) ? (int) $rawDays : 30));
        $since = now()->subDays($days - 1)->startOfDay();

        $rows = RoutineRun::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('outcome')
            ->get(['outcome', 'created_at']);

        // Si costruisce la serie COMPLETA e poi la si riempie: un giorno senza esecuzioni deve
        // comparire come zero, non sparire. Un grafico con i buchi mente sulla cadenza.
        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $since->copy()->addDays($i)->format('Y-m-d');
            $series[$day] = ['date' => $day, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0, 'paused' => 0];
        }
        foreach ($rows as $run) {
            $day = $run->created_at?->format('Y-m-d');
            $outcome = $run->outcome;
            // I quattro esiti sono elencati: un valore fuori da questi verrebbe da una colonna
            // corrotta, e sommarlo a una chiave inventata gonfierebbe il grafico in silenzio.
            if ($day === null || ! in_array($outcome, ['succeeded', 'failed', 'skipped', 'paused'], true)) {
                continue;
            }
            if (! isset($series[$day])) {
                continue;
            }
            $series[$day][$outcome]++;
        }

        return new JsonResponse(['data' => array_values($series)]);
    }

    /**
     * La pagina che si apre quando «una cosa non e' partita».
     *
     * Ogni riquadro non da' solo un numero ma **la diagnosi**: un pannello che dice "ultimo tick 47
     * minuti fa" ha informato; uno che dice "lo scheduler non sta girando, controlla il cron" ha
     * risolto.
     */
    public function health(): JsonResponse
    {
        if (! Permissions::allows(Permissions::READ)) {
            return Problem::forbidden('Non hai il permesso di vedere lo stato del sistema.');
        }

        $lastTick = Cache::get(self::LAST_TICK_KEY);
        $lastTickAt = is_string($lastTick) ? new \DateTimeImmutable($lastTick) : null;
        $age = $lastTickAt === null ? null : max(0, time() - $lastTickAt->getTimestamp());

        $overdue = Routine::query()
            ->where('status', 'active')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<', now()->subMinutes(5))
            ->orderBy('next_run_at')
            ->limit(50)
            ->get();

        $stuck = Routine::query()
            ->whereNotNull('lock_token')
            ->where('locked_until', '>', now())
            ->where('locked_until', '<', now()->addSeconds(intdiv(Cfg::int('routines.lock_seconds', 900), 2)))
            ->limit(50)
            ->get();

        $targets = [];
        foreach (Routine::query()->selectRaw('target_type, count(*) as aggregate')->groupBy('target_type')->get() as $row) {
            $type = $row->getAttribute('target_type');
            if (! is_string($type)) {
                continue;
            }
            $count = $row->getAttribute('aggregate');
            $registered = $this->registry->has($type);
            $targets[] = [
                'type' => $type,
                'label' => $registered ? $this->registry->get($type)->descriptor()->label : null,
                'registered' => $registered,
                'routines_count' => is_numeric($count) ? (int) $count : 0,
            ];
        }

        return new JsonResponse([
            'data' => [
                'last_tick_at' => $lastTickAt?->format(\DateTimeInterface::ATOM),
                'tick_age_seconds' => $age,
                'tick_healthy' => $age !== null && $age < 120,
                'tick_diagnosis' => $this->tickDiagnosis($age),
                'overdue' => $overdue->map(fn (Routine $r): array => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'next_run_at' => $r->next_run_at?->toIso8601String(),
                    'late_seconds' => $r->next_run_at === null ? 0 : max(0, time() - $r->next_run_at->getTimestamp()),
                ])->values(),
                'stuck_locks' => $stuck->map(fn (Routine $r): array => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'locked_until' => $r->locked_until?->toIso8601String(),
                    'locked_for_seconds' => Cfg::int('routines.lock_seconds', 900) - max(0, ($r->locked_until?->getTimestamp() ?? time()) - time()),
                ])->values(),
                'targets' => $targets,
            ],
        ]);
    }

    private function tickDiagnosis(?int $age): ?string
    {
        if ($age === null) {
            return 'Il tick non è mai partito. Verifica che lo scheduler di Laravel giri: '
                .'`* * * * * cd /percorso && php artisan schedule:run >> /dev/null 2>&1`';
        }
        if ($age < 120) {
            return null;
        }
        if ($age < 900) {
            return sprintf('Ultimo tick %d minuti fa: lo scheduler è in ritardo. Se non rientra, le routine slittano.', intdiv($age, 60));
        }

        return sprintf(
            'Ultimo tick %d minuti fa: lo scheduler di Laravel non sta girando, e finché non riparte nessuna routine partirà. '
            .'Controlla il cron di sistema (`* * * * * php artisan schedule:run`) e che il processo sia vivo.',
            intdiv($age, 60),
        );
    }

    private function successRate(int $daysAgoFrom, int $daysAgoTo): ?float
    {
        $rows = RoutineRun::query()
            ->whereNotNull('outcome')
            ->where('outcome', '!=', 'skipped')
            ->where('created_at', '>=', now()->subDays($daysAgoFrom))
            ->when($daysAgoTo > 0, fn ($q) => $q->where('created_at', '<', now()->subDays($daysAgoTo)))
            ->get(['outcome']);

        if ($rows->isEmpty()) {
            return null;
        }

        return round($rows->where('outcome', 'succeeded')->count() / $rows->count(), 4);
    }

    private function budgetUtilisation(): ?float
    {
        $ceiling = (float) Routine::query()->whereNotNull('budget_per_period')->sum('budget_per_period');
        if ($ceiling <= 0.0) {
            return null;
        }
        $spent = (float) RoutineRun::query()->where('created_at', '>=', now()->startOfMonth())->sum('cost');

        return round(min(1.0, $spent / $ceiling), 4);
    }
}
