<?php

declare(strict_types=1);

namespace Padosoft\Routines\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\Routines\Http\Presenters\RoutinePresenter;
use Padosoft\Routines\Http\Presenters\RunPresenter;
use Padosoft\Routines\Http\Support\Permissions;
use Padosoft\Routines\Http\Support\Problem;
use Padosoft\Routines\InvalidRoutine;
use Padosoft\Routines\Models\Routine;
use Padosoft\Routines\RoutineManager;

final class RoutinesController
{
    public function __construct(
        private readonly RoutineManager $manager,
        private readonly RoutinePresenter $presenter,
        private readonly RunPresenter $runs,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! Permissions::allows(Permissions::READ)) {
            return Problem::forbidden('Non hai il permesso di vedere le routine.');
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', '25')));
        $query = Routine::query();

        foreach (['status', 'target_type', 'owner', 'organization_id'] as $field) {
            $value = $request->query($field);
            if (is_string($value) && $value !== '') {
                $query->where($field, $value);
            }
        }

        if (is_string($q = $request->query('q')) && trim($q) !== '') {
            $term = '%'.trim($q).'%';
            $query->where(function ($sub) use ($term): void {
                $sub->where('name', 'like', $term)->orWhere('description', 'like', $term);
            });
        }

        // Ordinamento su allow-list: `sort` arriva dalla query string, e passarlo a orderBy senza
        // filtrarlo significherebbe far scegliere a un parametro quale colonna interrogare.
        $sort = match ($request->query('sort')) {
            'next_run_at' => ['next_run_at', 'asc'],
            'name' => ['name', 'asc'],
            'last_fired_at' => ['last_fired_at', 'desc'],
            default => ['created_at', 'desc'],
        };

        $cursor = $request->query('cursor');
        if (is_string($cursor) && $cursor !== '') {
            $query->where('id', $sort[1] === 'asc' ? '>' : '<', $cursor);
        }

        $rows = $query->orderBy($sort[0], $sort[1])->orderBy('id')->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        $page = $rows->take($perPage);

        return new JsonResponse([
            'data' => $page->map(fn (Routine $r): array => $this->presenter->summary($r))->values(),
            'meta' => [
                'total' => $cursor === null ? Routine::query()->count() : null,
                'per_page' => $perPage,
                'next_cursor' => $hasMore ? $page->last()?->id : null,
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        if (! Permissions::allows(Permissions::READ)) {
            return Problem::forbidden('Non hai il permesso di vedere le routine.');
        }
        $routine = Routine::find($id);

        return $routine === null
            ? Problem::notFound('Questa routine non esiste.')
            : new JsonResponse(['data' => $this->presenter->detail($routine)]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! Permissions::allows(Permissions::WRITE)) {
            return Problem::forbidden('Non hai il permesso di creare routine.');
        }

        try {
            $routine = $this->manager->create($this->attributes($request));
        } catch (InvalidRoutine $e) {
            return Problem::validation($e->getMessage(), $e->errors);
        }

        return new JsonResponse(['data' => $this->presenter->detail($routine)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (! Permissions::allows(Permissions::WRITE)) {
            return Problem::forbidden('Non hai il permesso di modificare le routine.');
        }
        $routine = Routine::find($id);
        if ($routine === null) {
            return Problem::notFound('Questa routine non esiste.');
        }
        if ($routine->statusEnum()->isTerminal()) {
            return Problem::conflict('Questa routine è stata terminata: non si modifica, se ne crea una nuova.');
        }

        try {
            $this->manager->update($routine, $this->attributes($request));
        } catch (InvalidRoutine $e) {
            return Problem::validation($e->getMessage(), $e->errors);
        }

        return new JsonResponse(['data' => $this->presenter->detail($routine->fresh())]);
    }

    public function pause(string $id): JsonResponse
    {
        return $this->lifecycle($id, Permissions::WRITE, function (Routine $r): void {
            $this->manager->pause($r);
        });
    }

    public function resume(string $id): JsonResponse
    {
        return $this->lifecycle($id, Permissions::WRITE, function (Routine $r): void {
            $this->manager->resume($r);
        });
    }

    public function end(Request $request, string $id): JsonResponse
    {
        $reason = $request->input('reason');

        return $this->lifecycle($id, Permissions::WRITE, function (Routine $r) use ($reason): void {
            $this->manager->end($r, is_string($reason) && $reason !== '' ? $reason : 'ended_by_user');
        });
    }

    /**
     * Esegui adesso.
     *
     * `Idempotency-Key` è opzionale ma **il pannello la manda sempre**: due click su "esegui
     * adesso" sono due intenzioni distinte, ma due invii della stessa richiesta per un rallentamento
     * di rete sono la stessa. Solo chi invia sa distinguerle, e la chiave è come lo dice.
     */
    public function fire(Request $request, string $id): JsonResponse
    {
        if (! Permissions::allows(Permissions::FIRE)) {
            return Problem::forbidden('Non hai il permesso di lanciare le routine.');
        }
        $routine = Routine::find($id);
        if ($routine === null) {
            return Problem::notFound('Questa routine non esiste.');
        }
        if ($routine->statusEnum()->isTerminal()) {
            return Problem::conflict('Questa routine è stata terminata e non può più essere eseguita.');
        }

        $input = $request->input('input');
        $run = $this->manager->fireNow(
            $routine,
            is_array($input) ? $input : [],
            $request->header('Idempotency-Key'),
        );

        return $run === null
            ? Problem::conflict('Non è stato possibile avviare questa routine.')
            : new JsonResponse(['data' => $this->runs->detail($run)], 202);
    }

    public function duplicate(string $id): JsonResponse
    {
        if (! Permissions::allows(Permissions::WRITE)) {
            return Problem::forbidden('Non hai il permesso di creare routine.');
        }
        $routine = Routine::find($id);
        if ($routine === null) {
            return Problem::notFound('Questa routine non esiste.');
        }

        $copy = $this->manager->create([
            'owner' => $routine->owner,
            'organization_id' => $routine->organization_id,
            'name' => $routine->name.' (copia)',
            'description' => $routine->description,
            'target_type' => $routine->target_type,
            'target_payload' => $routine->target_payload,
            'trigger_kind' => $routine->trigger_kind,
            'cron' => $routine->cron,
            'once_at' => $routine->once_at,
            'event_name' => $routine->event_name,
            'timezone' => $routine->timezone,
            'overlap_policy' => $routine->overlap_policy,
            'missed_run_policy' => $routine->missed_run_policy,
            'max_attempts' => $routine->max_attempts,
            'timeout_seconds' => $routine->timeout_seconds,
            'budget_per_run' => $routine->budget_per_run,
            'budget_per_period' => $routine->budget_per_period,
            'budget_period' => $routine->budget_period,
            'currency' => $routine->currency,
        ]);

        // La copia NON eredita il mandato: il consenso era stato dato per quella routine, e
        // trasferirlo significherebbe autorizzare qualcosa che nessuno ha approvato.
        $copy->pause();

        return new JsonResponse(['data' => $this->presenter->detail($copy->fresh())], 201);
    }

    /** @param \Closure(Routine): void $action */
    private function lifecycle(string $id, string $ability, \Closure $action): JsonResponse
    {
        if (! Permissions::allows($ability)) {
            return Problem::forbidden('Non hai il permesso di modificare le routine.');
        }
        $routine = Routine::find($id);
        if ($routine === null) {
            return Problem::notFound('Questa routine non esiste.');
        }

        $action($routine);

        return new JsonResponse(['data' => $this->presenter->detail($routine->fresh())]);
    }

    /** @return array<string, mixed> */
    private function attributes(Request $request): array
    {
        // Allow-list esplicita: `status`, `next_run_at` e i campi del lock non sono modificabili
        // dall'API, e i campi del mandato passano dal flusso di consenso, non da un PATCH.
        return $request->only([
            'owner', 'organization_id', 'name', 'description',
            'target_type', 'target_payload',
            'trigger_kind', 'cron', 'once_at', 'event_name', 'timezone',
            'overlap_policy', 'missed_run_policy',
            'budget_per_run', 'budget_per_period', 'budget_period', 'currency',
            'timeout_seconds', 'max_attempts', 'initiation', 'created_by',
        ]);
    }
}
