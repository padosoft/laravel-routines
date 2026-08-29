<?php

declare(strict_types=1);

namespace Padosoft\Routines\Console;

use Illuminate\Console\Command;
use Padosoft\Routines\Scheduling\RoutineDispatcher;

/**
 * Il battito.
 *
 * Va nello scheduler di Laravel ogni minuto (`->everyMinute()`), e **se salta non succede niente**:
 * il dispatcher chiede "cosa è scaduto", non "cosa tocca a questo minuto". Un tick perso ritarda,
 * non cancella — che è la differenza fra un deploy e un incidente.
 */
final class TickCommand extends Command
{
    protected $signature = 'routines:tick {--limit=100 : Quante routine al massimo per giro}';

    protected $description = 'Esegue le routine scadute e i tentativi da ripetere.';

    public function handle(RoutineDispatcher $dispatcher): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $stats = $dispatcher->tick(limit: $limit);

        $this->info(sprintf(
            'routines:tick — %d eseguite, %d saltate, %d ritentate.',
            $stats['fired'],
            $stats['skipped'],
            $stats['retried'],
        ));

        return self::SUCCESS;
    }
}
