<?php

declare(strict_types=1);

use Ragbridge\Internal\RetryAfter;

it('reads a number of seconds', function (string $value, int $expected): void {
    expect(RetryAfter::milliseconds($value))->toBe($expected);
})->with([
    'zero' => ['0', 0],
    'a few seconds' => ['3', 3_000],
    'with surrounding space' => [' 120 ', 120_000],
]);

it('reads an HTTP date as the time left until then', function (): void {
    $now = (new DateTimeImmutable('2026-09-21 12:00:00 UTC'))->getTimestamp();

    expect(RetryAfter::milliseconds('Mon, 21 Sep 2026 12:00:07 GMT', $now))->toBe(7_000);
});

it('does not go below zero for a date in the past', function (): void {
    $now = (new DateTimeImmutable('2026-09-21 12:00:00 UTC'))->getTimestamp();

    expect(RetryAfter::milliseconds('Mon, 21 Sep 2026 11:00:00 GMT', $now))->toBe(0);
});

it('uses the current time when none is given', function (): void {
    $milliseconds = RetryAfter::milliseconds(gmdate('D, d M Y H:i:s \G\M\T', time() + 30));

    expect($milliseconds)->toBeGreaterThan(27_000)->toBeLessThanOrEqual(30_000);
});

it('does not overflow for a huge number of seconds', function (): void {
    expect(RetryAfter::milliseconds('99999999999999999999'))->toBe(PHP_INT_MAX);
});

it('ignores a value it does not understand', function (string $value): void {
    expect(RetryAfter::milliseconds($value))->toBeNull();
})->with([
    'empty' => [''],
    'blank' => ['   '],
    'words' => ['soon'],
    'negative' => ['-5'],
    'fraction' => ['1.5'],
    'a date in another format' => ['2026-09-21T12:00:00Z'],
    'an impossible date' => ['Mon, 32 Sep 2026 12:00:00 GMT'],
]);
