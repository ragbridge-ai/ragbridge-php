<?php

declare(strict_types=1);

namespace Ragbridge\Dto;

use InvalidArgumentException;
use Ragbridge\Internal\Payload;

/**
 * The state the service reports for a health check.
 *
 * Wire format:
 *
 * @phpstan-type HealthStatusData array{status: string}
 */
final readonly class HealthStatus
{
    /**
     * @param string $status what the service reports, "ok" when it is healthy
     */
    public function __construct(
        public string $status,
    ) {}

    /**
     * @param array<mixed> $data decoded JSON object, see HealthStatusData
     *
     * @throws InvalidArgumentException when a field is missing or has the wrong type
     */
    public static function fromArray(array $data): self
    {
        return new self(status: (new Payload($data, 'HealthStatus'))->string('status'));
    }

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }
}
