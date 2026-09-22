<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Ragbridge\Laravel\RagbridgeServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param Application $app
     *
     * @return list<class-string<\Illuminate\Support\ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [RagbridgeServiceProvider::class];
    }

    /**
     * An in-memory SQLite database, for the tests under tests/Laravel/Sync that need one.
     *
     * @param Application $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $config = $app->make(Repository::class);

        $config->set('database.default', 'sqlite');
        $config->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
