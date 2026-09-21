<?php

declare(strict_types=1);

namespace Ragbridge\Dto;

use InvalidArgumentException;
use Ragbridge\Internal\Payload;

/**
 * The outcome of saving a document by its external id: what the service did, and the
 * document as it now stands.
 *
 * Wire format:
 *
 * @phpstan-type SyncedDocumentData array{
 *     result: 'created'|'replaced'|'updated'|'unchanged'|'stale',
 *     document: array<mixed>
 * }
 */
final readonly class SyncedDocument
{
    /**
     * @param int $statusCode HTTP status of the response: 201 when the document was created,
     *                        202 when the text is large and is processed by a worker, and
     *                        200 otherwise
     */
    public function __construct(
        public SyncResult $result,
        public Document $document,
        public int $statusCode,
    ) {}

    /**
     * @param array<mixed> $data decoded JSON object, see SyncedDocumentData
     * @param int $statusCode HTTP status of the response
     *
     * @throws InvalidArgumentException when a field is missing or has the wrong type
     */
    public static function fromArray(array $data, int $statusCode): self
    {
        $payload = new Payload($data, 'SyncedDocument');

        $result = $payload->string('result');

        return new self(
            result: SyncResult::tryFrom($result)
                ?? throw $payload->invalid(sprintf('unknown result "%s"', $result)),
            document: Document::fromArray($payload->object('document')),
            statusCode: $statusCode,
        );
    }

    /**
     * Whether the text was too large to process within the request. The document is then
     * pending and becomes ready, or failed, later. For a replaced document, the old version
     * stays searchable until then.
     */
    public function isQueued(): bool
    {
        return $this->statusCode === 202;
    }
}
