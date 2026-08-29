<?php

declare(strict_types=1);

namespace Padosoft\Routines\Console;

use Illuminate\Console\Command;
use Padosoft\Routines\Models\Routine;

/** Cosa c'è e quando gira. Il comando che si lancia quando qualcosa non è partito. */
final class ListCommand extends Command
{
    protected $signature = 'routines:list {--status= : Filtra per stato} {--owner= : Filtra per proprietario}';

    protected $description = 'Elenca le routine con il prossimo fire previsto.';

    public function handle(): int
    {
        $query = Routine::query()->orderByRaw('next_run_at is null, next_run_at');

        if (is_string($status = $this->option('status')) && $status !== '') {
            $query->where('status', $status);
        }
        if (is_string($owner = $this->option('owner')) && $owner !== '') {
            $query->where('owner', $owner);
        }

        $rows = $query->get()->map(fn (Routine $r): array => [
            substr($r->id, -8),
            $r->name,
            $r->target_type,
            $r->status,
            $r->cron ?? $r->trigger_kind,
            $r->next_run_at?->setTimezone($r->timezone)->format('Y-m-d H:i').' '.$r->timezone,
        ])->all();

        if ($rows === []) {
            $this->info('Nessuna routine.');

            return self::SUCCESS;
        }

        $this->table(['id', 'nome', 'bersaglio', 'stato', 'trigger', 'prossimo fire (fuso owner)'], $rows);

        return self::SUCCESS;
    }
}
