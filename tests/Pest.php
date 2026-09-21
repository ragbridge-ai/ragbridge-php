<?php

declare(strict_types=1);

use Ragbridge\Tests\Laravel\TestCase as LaravelTestCase;

pest()->in('Unit');
pest()->extend(LaravelTestCase::class)->in('Laravel');
