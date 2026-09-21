<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Ragbridge\Tests\Laravel\TestCase as LaravelTestCase;
use Ragbridge\Tests\Support\Integration;

pest()->in('Unit', 'Symfony');

// The integration tests need a running service and are skipped unless it is configured.
// Run them with `vendor/bin/pest --group=integration`, see tests/Integration/start.sh.
pest()->in('Integration')->group('integration')->beforeEach(function (): void {
    if (! Integration::isConfigured()) {
        TestCase::markTestSkipped('Set RAGBRIDGE_INTEGRATION_URL and RAGBRIDGE_INTEGRATION_API_KEY to run the integration tests.');
    }
});

// The Laravel tests need Orchestra Testbench. Registering their base class autoloads it, so do
// it only when it is installed, which keeps the other suites runnable without Laravel.
if (class_exists(Orchestra\Testbench\TestCase::class)) {
    pest()->extend(LaravelTestCase::class)->in('Laravel');
}
