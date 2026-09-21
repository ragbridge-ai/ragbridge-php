<?php

declare(strict_types=1);

namespace Ragbridge\Dto;

use InvalidArgumentException;
use Ragbridge\Internal\Payload;

/**
 * A chunk of a document that an answer was based on.
 *
 * Wire format:
 *
 * @phpstan-type SourceData array{
 *     document_id: string,
 *     filename: string,
 *     chunk_index: int,
 *     snippet: string,
 *     score: float|int,
 *     context_only?: bool,
 *     retrieval?: array<mixed>|null
 * }
 */
final readonly class Source
{
    public function __construct(
        public string $documentId,
        public string $filename,
        public int $chunkIndex,
        public string $snippet,
        public float $score,
        public bool $contextOnly = false,
        public ?RetrievalInfo $retrieval = null,
    ) {}

    /**
     * @param array<mixed> $data decoded JSON object, see SourceData
     *
     * @throws InvalidArgumentException when a field is missing or has the wrong type
     */
    public static function fromArray(array $data): self
    {
        $payload = new Payload($data, 'Source');

        $retrieval = $payload->nullableObject('retrieval');

        return new self(
            documentId: $payload->uuid('document_id'),
            filename: $payload->string('filename'),
            chunkIndex: $payload->int('chunk_index'),
            snippet: $payload->string('snippet'),
            score: $payload->float('score'),
            contextOnly: $payload->bool('context_only', false),
            retrieval: $retrieval === null ? null : RetrievalInfo::fromArray($retrieval),
        );
    }
}
