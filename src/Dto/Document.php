<?php

declare(strict_types=1);

namespace Ragbridge\Dto;

use DateTimeImmutable;
use InvalidArgumentException;
use Ragbridge\Internal\Payload;

/**
 * A document stored in the service.
 *
 * Wire format:
 *
 * @phpstan-type DocumentData array{
 *     id: string,
 *     filename: string,
 *     content_type: string,
 *     status: 'pending'|'processing'|'ready'|'failed',
 *     error: string|null,
 *     created_at: string
 * }
 */
final readonly class Document
{
    public function __construct(
        public string $id,
        public string $filename,
        public string $contentType,
        public DocumentStatus $status,
        public ?string $error,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * @param array<mixed> $data decoded JSON object, see DocumentData
     *
     * @throws InvalidArgumentException when a field is missing or has the wrong type
     */
    public static function fromArray(array $data): self
    {
        $payload = new Payload($data, 'Document');

        $status = $payload->string('status');

        return new self(
            id: $payload->uuid('id'),
            filename: $payload->string('filename'),
            contentType: $payload->string('content_type'),
            status: DocumentStatus::tryFrom($status)
                ?? throw $payload->invalid(sprintf('unknown status "%s"', $status)),
            error: $payload->nullableString('error'),
            createdAt: $payload->dateTime('created_at'),
        );
    }
}
