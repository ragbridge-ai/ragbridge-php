<?php

declare(strict_types=1);

use Ragbridge\Tests\Laravel\TestCase as LaravelTestCase;

pest()->in('Unit', 'Symfony');

// The Laravel tests need Orchestra Testbench. Registering their base class autoloads it, so do
// it only when it is installed, which keeps the other suites runnable without Laravel.
if (class_exists(Orchestra\Testbench\TestCase::class)) {
    pest()->extend(LaravelTestCase::class)->in('Laravel');
}
