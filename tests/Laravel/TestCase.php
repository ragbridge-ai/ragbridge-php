<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Laravel;

use Orchestra\Testbench\TestCase as Orchestra;
use Ragbridge\Laravel\RagbridgeServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return list<class-string<\Illuminate\Support\ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [RagbridgeServiceProvider::class];
    }
}
