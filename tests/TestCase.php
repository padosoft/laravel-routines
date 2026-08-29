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
    }
}
