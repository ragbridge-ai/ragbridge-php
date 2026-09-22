<?php

declare(strict_types=1);

namespace Ragbridge\Sync;

use DateTimeInterface;

/**
 * What a {@see Syncable} record sends to the service: the arguments of
 * {@see \Ragbridge\RagbridgeClient::putDocument()} without the id.
 */
final readonly class SyncDocument
{
    /**
     * @param string $title name of the document, 1 to 500 characters
     * @param string $content the text that is searched; it must not be blank
     * @param array<string, mixed> $metadata data stored with the document and returned with it
     * @param DateTimeInterface|null $sourceUpdatedAt when the record was last changed. The
     *                                                service ignores a state older than the
     *                                                stored one. The Laravel integration uses
     *                                                the model's updated_at when this is null
     */
    public function __construct(
        public string $title,
        public string $content,
        public array $metadata = [],
        public ?DateTimeInterface $sourceUpdatedAt = null,
    ) {}

    public function withSourceUpdatedAt(?DateTimeInterface $sourceUpdatedAt): self
    {
        return new self($this->title, $this->content, $this->metadata, $sourceUpdatedAt);
    }
}
