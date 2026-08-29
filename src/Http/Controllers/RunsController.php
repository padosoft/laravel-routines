<?php

declare(strict_types=1);

namespace Padosoft\Routines\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\Routines\Http\Presenters\RunPresenter;
use Padosoft\Routines\Http\Support\Permissions;
use Padosoft\Routines\Http\Support\Problem;
use Padosoft\Routines\Models\RoutineRun;
use Padosoft\Routines\RoutineManager;

final class RunsController
{
    public function __construct(
        private readonly RoutineManager $manager,
        private readonly RunPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! Permissions::allows(Permissions::READ)) {
            return Problem::forbidden('Non hai il permesso di vedere le esecuzioni.');
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', '25')));
        $query = RoutineRun::query()->with('routine');

        foreach (['routine_id', 'outcome', 'reason', 'correlation_id'] as $field) {
            $value = $request->query($field);
            if (is_string($value) && $value !== '') {
                $query->where($field, $value);
            }
        }
        if (is_string($from = $request->query('from')) && $from !== '') {
            $query->where('created_at', '>=', $from);
        }
        if (is_string($to = $request->query('to')) && $to !== '') {
            $query->where('created_at', '<=', $to);
        }
        if (is_string($q = $request->query('q')) && trim($q) !== '') {
            $query->where('message', 'like', '%'.trim($q).'%');
        }

        $cursor = $request->query('cursor');
        if (is_string($cursor) && $cursor !== '') {
            $query->where('id', '<', $cursor);
        }

        $rows = $query->orderByDesc('id')->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        $page = $rows->take($perPage);

        return new JsonResponse([
            'data' => $page->map(fn (RoutineRun $r): array => $this->presenter->summary($r))->values(),
            'meta' => [
                'total' => null,   // il ledger cresce senza limite: contarlo a ogni pagina non ripaga
                'per_page' => $perPage,
                'next_cursor' => $hasMore ? $page->last()?->id : null,
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        if (! Permissions::allows(Permissions::READ)) {
            return Problem::forbidden('Non hai il permesso di vedere le esecuzioni.');
        }
        $run = RoutineRun::with('routine')->find($id);

        return $run === null
            ? Problem::notFound('Questa esecuzione non esiste.')
            : new JsonResponse(['data' => $this->presenter->detail($run)]);
    }

    /** La coda di cio' che aspetta una risposta. La piu' vecchia per prima: chi aspetta di piu' viene prima. */
    public function attention(Request $request): JsonResponse
    {
        if (! Permissions::allows(Permissions::READ)) {
            return Problem::forbidden('Non hai il permesso di vedere le esecuzioni.');
        }

        $rows = RoutineRun::query()
            ->with('routine')
            ->where('outcome', 'paused')
            ->whereNull('resolved_at')
            ->orderBy('created_at')
            ->limit(min(100, max(1, (int) $request->query('per_page', '50'))))
            ->get();

        return new JsonResponse([
            'data' => $rows->map(fn (RoutineRun $r): array => $this->presenter->detail($r))->values(),
            'meta' => ['total' => $rows->count(), 'per_page' => $rows->count(), 'next_cursor' => null],
        ]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->resolve($request, $id, approved: true);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->resolve($request, $id, approved: false);
    }

    private function resolve(Request $request, string $id, bool $approved): JsonResponse
    {
        if (! Permissions::allows(Permissions::APPROVE)) {
            return Problem::forbidden('Non hai il permesso di rispondere alle richieste delle routine.');
        }
        $run = RoutineRun::with('routine')->find($id);
        if ($run === null) {
            return Problem::notFound('Questa esecuzione non esiste.');
        }
        if (! $run->isAwaitingHuman()) {
            // Non e' un errore del chiamante: e' la seconda risposta alla stessa domanda, e la
            // risposta giusta e' restituire lo stato attuale senza eseguire niente.
            return new JsonResponse(['data' => $this->presenter->detail($run)], 200);
        }

        $note = $request->input($approved ? 'note' : 'reason');
        $note = is_string($note) ? trim($note) : '';

        if (! $approved && $note === '') {
            return Problem::validation('Serve il motivo del rifiuto.', [
                'reason' => ['Scrivi perché stai rifiutando: qualcuno lo leggerà nel ledger.'],
            ]);
        }

        $resolved = $this->manager->resolve($run, $approved, $this->currentSubject($request), $note);

        return new JsonResponse(['data' => $this->presenter->detail($resolved)]);
    }

    /**
     * Chi sta rispondendo, in forma canonica.
     *
     * Va scritto sul fire: «approvata» senza dire da chi non e' evidenza di niente.
     */
    private function currentSubject(Request $request): string
    {
        $user = $request->user();
        if ($user === null) {
            return 'unknown';
        }

        $key = method_exists($user, 'getAuthIdentifier') ? $user->getAuthIdentifier() : null;

        return 'user:'.(is_scalar($key) ? (string) $key : 'unknown');
    }
}
