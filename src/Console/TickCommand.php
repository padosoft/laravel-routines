<?php

declare(strict_types=1);

namespace Padosoft\Routines\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Padosoft\Routines\Http\Controllers\StatsController;
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

        // Il battito. Serve alla schermata Salute a rispondere alla domanda piu' frequente di
        // tutto il prodotto - «perche' non e' partita?» - la cui risposta, nove volte su dieci,
        // e' che lo scheduler di sistema non sta girando.
        Cache::put(StatsController::LAST_TICK_KEY, (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM), 86400);

        $this->info(sprintf(
            'routines:tick — %d eseguite, %d saltate, %d ritentate.',
            $stats['fired'],
            $stats['skipped'],
            $stats['retried'],
        ));

        return self::SUCCESS;
    }
}
