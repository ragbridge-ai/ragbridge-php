<?php

declare(strict_types=1);

namespace Ragbridge\Dto;

use InvalidArgumentException;
use Ragbridge\Internal\Payload;

/**
 * A chunk of a document returned by a search.
 *
 * Unlike a {@see Source}, which carries a short snippet, a hit carries the full text of the
 * chunk, so that the caller can reason over it.
 *
 * Wire format:
 *
 * @phpstan-type SearchHitData array{
 *     document_id: string,
 *     filename: string,
 *     chunk_index: int,
 *     content: string,
 *     score: float|int,
 *     retrieval?: array<mixed>|null
 * }
 */
final readonly class SearchHit
{
    public function __construct(
        public string $documentId,
        public string $filename,
        public int $chunkIndex,
        public string $content,
        public float $score,
        public ?RetrievalInfo $retrieval = null,
    ) {}

    /**
     * @param array<mixed> $data decoded JSON object, see SearchHitData
     *
     * @throws InvalidArgumentException when a field is missing or has the wrong type
     */
    public static function fromArray(array $data): self
    {
        $payload = new Payload($data, 'SearchHit');

        $retrieval = $payload->nullableObject('retrieval');

        return new self(
            documentId: $payload->uuid('document_id'),
            filename: $payload->string('filename'),
            chunkIndex: $payload->int('chunk_index'),
            content: $payload->string('content'),
            score: $payload->float('score'),
            retrieval: $retrieval === null ? null : RetrievalInfo::fromArray($retrieval),
        );
    }
}
