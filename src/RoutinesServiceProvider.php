<?php

declare(strict_types=1);

namespace Padosoft\Routines;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Support\Facades\Route;
use Padosoft\Routines\Budget\BudgetGuard;
use Padosoft\Routines\Console\ListCommand;
use Padosoft\Routines\Console\TickCommand;
use Padosoft\Routines\Contracts\Delegation\RoutineDelegationBroker;
use Padosoft\Routines\Contracts\Escalation\RoutineEscalator;
use Padosoft\Routines\Delegation\NullDelegationBroker;
use Padosoft\Routines\Escalation\LoggingEscalator;
use Padosoft\Routines\Scheduling\RoutineDispatcher;
use Padosoft\Routines\Scheduling\RoutineScheduler;
use Padosoft\Routines\Targets\JobTarget;
use Padosoft\Routines\Targets\TargetRegistry;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class RoutinesServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('routines')
            ->hasConfigFile('routines')
            ->hasMigration('create_routines_tables')
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
        $this->app->bindIf(RoutineEscalator::class, fn ($app) => new LoggingEscalator($app->make('log')));

        $this->app->singleton(RoutineDispatcher::class, fn ($app) => new RoutineDispatcher(
            registry: $app->make(TargetRegistry::class),
            scheduler: $app->make(RoutineScheduler::class),
            events: $app->make(Events::class),
            delegation: $app->make(RoutineDelegationBroker::class),
            escalator: $app->make(RoutineEscalator::class),
            budget: $app->make(BudgetGuard::class),
            lockSeconds: (int) config('routines.lock_seconds', 900),
            catchUpCap: (int) config('routines.catch_up_cap', 25),
            retryBaseSeconds: (int) config('routines.retry_base_seconds', 60),
        ));

        $this->app->singleton(RoutineManager::class);
    }

    public function packageBooted(): void
    {
        if (config('routines.targets.job.enabled', true)) {
            $this->app->make(TargetRegistry::class)->register(new JobTarget(
                bus: $this->app->make(Dispatcher::class),
                allowed: (array) config('routines.targets.job.allowed', []),
            ));
        }

        if (config('routines.api.enabled', true)) {
            Route::prefix((string) config('routines.api.prefix', 'api/routines/v1'))
                ->middleware((array) config('routines.api.middleware', ['web', 'auth']))
                ->group(__DIR__.'/Http/routes.php');
        }

        if ($this->app->runningInConsole() && config('routines.tick.schedule', true)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command(TickCommand::class, ['--limit' => (int) config('routines.tick.limit', 100)])
                    ->everyMinute()
                    // Il lock per-routine è nel database e regge comunque; questo evita solo di
                    // accumulare processi di tick se una macchina è lenta.
                    ->withoutOverlapping(5)
                    ->runInBackground();
            });
        }
    }
}
