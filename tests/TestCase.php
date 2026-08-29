<?php

declare(strict_types=1);

namespace Padosoft\Routines\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\Routines\RoutinesServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [RoutinesServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('routines.retry_base_seconds', 60);
        // Le rotte si registrano al boot, prima di qualsiasi beforeEach: il middleware va
        // neutralizzato QUI, altrimenti i test dell'API sbattono contro il guard invece di
        // esercitare il controller.
        $app['config']->set('routines.api.middleware', []);
        // Testbench non ne imposta una: senza, il middleware di sessione esplode appena si
        // colpisce una rotta.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }
}
