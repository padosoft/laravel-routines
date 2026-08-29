<?php

declare(strict_types=1);

namespace Padosoft\Routines;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Padosoft\Routines\Budget\BudgetGuard;
use Padosoft\Routines\Console\ListCommand;
use Padosoft\Routines\Console\TickCommand;
use Padosoft\Routines\Contracts\Delegation\RoutineDelegationBroker;
use Padosoft\Routines\Contracts\Escalation\RoutineEscalator;
use Padosoft\Routines\Delegation\NullDelegationBroker;
use Padosoft\Routines\Escalation\LoggingEscalator;
use Padosoft\Routines\Http\Controllers\WebhookController;
use Padosoft\Routines\Scheduling\RoutineDispatcher;
use Padosoft\Routines\Scheduling\RoutineScheduler;
use Padosoft\Routines\Support\Cfg;
use Padosoft\Routines\Targets\JobTarget;
use Padosoft\Routines\Targets\TargetRegistry;
use Padosoft\Routines\Triggers\EventTrigger;
use Psr\Log\LoggerInterface;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class RoutinesServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('routines')
            ->hasConfigFile('routines')
            ->hasCommands([TickCommand::class, ListCommand::class]);
    }

    public function packageRegistered(): void
    {
        // Il registro è un singleton perché i bersagli si registrano dai provider degli altri
        // pacchetti: se ne esistessero due istanze, metà dei bersagli sarebbe invisibile all'altra
        // metà del sistema, e il sintomo sarebbe una routine "non eseguibile" senza motivo.
        $this->app->singleton(TargetRegistry::class);
        $this->app->singleton(RoutineScheduler::class);
        $this->app->singleton(BudgetGuard::class);

        // Entrambi hanno un default che NON simula la funzione mancante: il broker nullo lancia
        // invece di emettere un token dell'applicazione, e l'escalator di default scrive un
        // warning invece di ingoiare la domanda. Un default comodo, qui, regalerebbe autorita' o
        // nasconderebbe una routine ferma che nessuno sta aspettando di sbloccare.
        $this->app->bindIf(RoutineDelegationBroker::class, NullDelegationBroker::class);
        $this->app->bindIf(RoutineEscalator::class, fn (Application $app) => new LoggingEscalator($app->make(LoggerInterface::class)));

        $this->app->singleton(RoutineDispatcher::class, fn (Application $app) => new RoutineDispatcher(
            registry: $app->make(TargetRegistry::class),
            scheduler: $app->make(RoutineScheduler::class),
            events: $app->make(Events::class),
            delegation: $app->make(RoutineDelegationBroker::class),
            escalator: $app->make(RoutineEscalator::class),
            budget: $app->make(BudgetGuard::class),
            lockSeconds: Cfg::int('routines.lock_seconds', 900),
            catchUpCap: Cfg::int('routines.catch_up_cap', 25),
            retryBaseSeconds: Cfg::int('routines.retry_base_seconds', 60),
        ));

        $this->app->singleton(RoutineManager::class);
        $this->app->singleton(EventTrigger::class);
    }

    public function packageBooted(): void
    {
        // `publishesMigrations` e non `hasMigration`: quest'ultimo cerca un file
        // `create_routines_tables.php.stub` secondo la convenzione di package-tools, mentre qui la
        // migration e' un normale file datato (cosi' i test del pacchetto possono caricarla con
        // loadMigrationsFrom, che uno .stub non permette). Il difetto era invisibile finche' non
        // si installava il pacchetto in un'applicazione vera: `vendor:publish` falliva con «Can't
        // locate path».
        $this->publishesMigrations([
            __DIR__.'/../database/migrations/2026_08_29_000001_create_routines_tables.php' => $this->app->databasePath('migrations/2026_08_29_000001_create_routines_tables.php'),
        ], 'routines-migrations');

        if (Cfg::bool('routines.targets.job.enabled', true)) {
            $this->app->make(TargetRegistry::class)->register(new JobTarget(
                bus: $this->app->make(Dispatcher::class),
                allowed: Cfg::classList('routines.targets.job.allowed'),
            ));
        }

        if (Cfg::bool('routines.api.enabled', true)) {
            Route::prefix(Cfg::string('routines.api.prefix', 'api/routines/v1'))
                ->middleware(array_values(Cfg::array('routines.api.middleware')))
                ->group(__DIR__.'/Http/routes.php');
        }

        if (Cfg::bool('routines.webhooks.enabled', true)) {
            Route::prefix(Cfg::string('routines.webhooks.prefix', 'hooks/routines'))
                ->middleware(array_values(Cfg::array('routines.webhooks.middleware')))
                ->post('/{id}', WebhookController::class)
                ->name('routines.webhook');
        }

        if ($this->app->runningInConsole() && Cfg::bool('routines.tick.schedule', true)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command(TickCommand::class, ['--limit' => Cfg::int('routines.tick.limit', 100)])
                    ->everyMinute()
                    // Il lock per-routine è nel database e regge comunque; questo evita solo di
                    // accumulare processi di tick se una macchina è lenta.
                    ->withoutOverlapping(5)
                    ->runInBackground();
            });
        }
    }
}
