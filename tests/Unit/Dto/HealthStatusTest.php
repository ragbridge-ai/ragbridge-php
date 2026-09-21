<?php

declare(strict_types=1);

use Ragbridge\Dto\HealthStatus;

it('reads the reported status', function (): void {
    $status = HealthStatus::fromArray(['status' => 'ok']);

    expect($status->status)->toBe('ok')
        ->and($status->isOk())->toBeTrue();
});

it('is not ok for any other status', function (): void {
    expect(HealthStatus::fromArray(['status' => 'degraded'])->isOk())->toBeFalse();
});

it('ignores fields it does not know', function (): void {
    expect(HealthStatus::fromArray(['status' => 'ok', 'version' => '1.2.3'])->status)->toBe('ok');
});

it('rejects a payload without a status', function (): void {
    expect(fn() => HealthStatus::fromArray([]))
        ->toThrow(InvalidArgumentException::class, 'HealthStatus: missing required field "status"');
});

it('rejects a status of the wrong type', function (): void {
    expect(fn() => HealthStatus::fromArray(['status' => true]))
        ->toThrow(InvalidArgumentException::class, 'HealthStatus: field "status" must be a string, bool given');
});
