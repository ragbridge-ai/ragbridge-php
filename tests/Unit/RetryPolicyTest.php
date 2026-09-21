<?php

declare(strict_types=1);

use Ragbridge\RetryPolicy;

describe('construction', function (): void {
    it('has defaults that retry twice with a short delay and leave POST alone', function (): void {
        $policy = new RetryPolicy();

        expect($policy->maxAttempts)->toBe(3)
            ->and($policy->baseDelayMs)->toBe(200)
            ->and($policy->maxDelayMs)->toBe(10_000)
            ->and($policy->retryPost)->toBeFalse();
    });

    it('rejects values that are out of range', function (Closure $build, string $message): void {
        expect($build)->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'no attempts' => [fn() => new RetryPolicy(maxAttempts: 0), 'at least 1'],
        'negative base delay' => [fn() => new RetryPolicy(baseDelayMs: -1), 'cannot be negative'],
        'maximum shorter than base' => [fn() => new RetryPolicy(baseDelayMs: 500, maxDelayMs: 100), 'cannot be shorter'],
    ]);

    it('accepts a single attempt and equal delays', function (): void {
        expect((new RetryPolicy(maxAttempts: 1, baseDelayMs: 50, maxDelayMs: 50))->maxAttempts)->toBe(1);
    });
});

describe('methods', function (): void {
    it('applies to idempotent methods only', function (string $method, bool $applies): void {
        expect((new RetryPolicy())->appliesTo($method))->toBe($applies);
    })->with([
        'GET' => ['GET', true],
        'get in lower case' => ['get', true],
        'DELETE' => ['DELETE', true],
        'HEAD' => ['HEAD', true],
        'PUT' => ['PUT', true],
        'OPTIONS' => ['OPTIONS', true],
        'POST' => ['POST', false],
        'PATCH' => ['PATCH', false],
    ]);

    it('applies to POST when it is enabled', function (): void {
        $policy = new RetryPolicy(retryPost: true);

        expect($policy->appliesTo('POST'))->toBeTrue()
            ->and($policy->appliesTo('post'))->toBeTrue()
            ->and($policy->appliesTo('PATCH'))->toBeFalse();
    });
});

describe('statuses', function (): void {
    it('retries only the statuses that are transient', function (int $status, bool $retryable): void {
        expect((new RetryPolicy())->isRetryableStatus($status))->toBe($retryable);
    })->with([
        [429, true],
        [502, true],
        [503, true],
        [504, true],
        [400, false],
        [401, false],
        [403, false],
        [404, false],
        [422, false],
        [500, false],
        [501, false],
        [200, false],
    ]);
});

describe('delay', function (): void {
    it('grows exponentially, with the wait between half of the step and the whole step', function (): void {
        $policy = new RetryPolicy(maxAttempts: 10, baseDelayMs: 100, maxDelayMs: 100_000);

        foreach ([1 => 100, 2 => 200, 3 => 400, 4 => 800, 5 => 1600] as $attempt => $ceiling) {
            for ($i = 0; $i < 200; $i++) {
                expect($policy->delayMs($attempt))->toBeGreaterThanOrEqual(intdiv($ceiling, 2))
                    ->toBeLessThanOrEqual($ceiling);
            }
        }
    });

    it('never exceeds the maximum delay', function (): void {
        $policy = new RetryPolicy(maxAttempts: 100, baseDelayMs: 1_000, maxDelayMs: 1_500);

        foreach ([1, 2, 3, 10, 50, 100] as $attempt) {
            for ($i = 0; $i < 100; $i++) {
                expect($policy->delayMs($attempt))->toBeLessThanOrEqual(1_500);
            }
        }
    });

    it('does not overflow for a very large number of attempts', function (): void {
        $policy = new RetryPolicy(maxAttempts: PHP_INT_MAX, baseDelayMs: 1, maxDelayMs: PHP_INT_MAX);

        foreach ([1, 62, 63, 64, 65, 200] as $attempt) {
            expect($policy->delayMs($attempt))->toBeInt()->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(PHP_INT_MAX);
        }
    });

    it('stays at the maximum delay when doubling would pass it', function (): void {
        $policy = new RetryPolicy(maxAttempts: 100, baseDelayMs: PHP_INT_MAX - 1, maxDelayMs: PHP_INT_MAX);

        expect($policy->delayMs(5))->toBeInt()->toBeGreaterThanOrEqual(intdiv(PHP_INT_MAX, 2));
    });

    it('does not wait when the base delay is zero', function (): void {
        expect((new RetryPolicy(baseDelayMs: 0, maxDelayMs: 0))->delayMs(3))->toBe(0);
    });

    it('varies between calls', function (): void {
        $policy = new RetryPolicy(baseDelayMs: 10_000, maxDelayMs: 10_000);
        $delays = array_map(static fn(): ?int => $policy->delayMs(1), range(1, 50));

        expect(count(array_unique($delays)))->toBeGreaterThan(1);
    });

    it('uses the wait requested by the service as it is', function (): void {
        expect((new RetryPolicy())->delayMs(1, 2_000))->toBe(2_000)
            ->and((new RetryPolicy())->delayMs(4, 0))->toBe(0);
    });

    it('does not accept a wait longer than the maximum delay', function (): void {
        $policy = new RetryPolicy(maxDelayMs: 5_000);

        expect($policy->delayMs(1, 5_000))->toBe(5_000)
            ->and($policy->delayMs(1, 5_001))->toBeNull();
    });
});
