<?php

declare(strict_types=1);

namespace Ragbridge\Dto;

use DateTimeImmutable;
use InvalidArgumentException;
use Ragbridge\Internal\Payload;

/**
 * A document stored in the service.
 *
 * A document is either an upload, or it is identified by an id of the application that owns
 * the record it was made from, its external id. The last four properties are only filled by
 * a service that supports external ids; against an older one they keep their defaults.
 *
 * Wire format:
 *
 * @phpstan-type DocumentData array{
 *     id: string,
 *     filename: string,
 *     content_type: string,
 *     status: 'pending'|'processing'|'ready'|'failed',
 *     error: string|null,
 *     created_at: string,
 *     external_id?: string|null,
 *     metadata?: array<array-key, mixed>|null,
 *     source_updated_at?: string|null,
 *     updated_at?: string|null
 * }
 */
final readonly class Document
{
    /**
     * @param string|null $externalId the application's own id, null for an uploaded file
     * @param array<array-key, mixed> $metadata data stored with the document, empty when there is none
     * @param DateTimeImmutable|null $sourceUpdatedAt when the record was last changed in the application
     * @param DateTimeImmutable|null $updatedAt when the service last changed the document
     */
    public function __construct(
        public string $id,
        public string $filename,
        public string $contentType,
        public DocumentStatus $status,
        public ?string $error,
        public DateTimeImmutable $createdAt,
        public ?string $externalId = null,
        public array $metadata = [],
        public ?DateTimeImmutable $sourceUpdatedAt = null,
        public ?DateTimeImmutable $updatedAt = null,
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
            externalId: $payload->nullableString('external_id'),
            metadata: $payload->map('metadata'),
            sourceUpdatedAt: $payload->nullableDateTime('source_updated_at'),
            updatedAt: $payload->nullableDateTime('updated_at'),
        );
    }
}
