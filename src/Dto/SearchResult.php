<?php

declare(strict_types=1);

namespace Ragbridge\Dto;

use InvalidArgumentException;
use Ragbridge\Internal\Payload;

/**
 * The chunks a search found, best match first.
 *
 * Wire format:
 *
 * @phpstan-type SearchResultData array{results: list<array<mixed>>, candidate_count?: int|null}
 */
final readonly class SearchResult
{
    /**
     * @param list<SearchHit> $results
     * @param int|null $candidateCount number of chunks considered before the best ones were
     *                                 picked, only reported by the service when retrieval
     *                                 details are requested
     */
    public function __construct(
        public array $results,
        public ?int $candidateCount = null,
    ) {}

    /**
     * @param array<mixed> $data decoded JSON object, see SearchResultData
     *
     * @throws InvalidArgumentException when a field is missing or has the wrong type
     */
    public static function fromArray(array $data): self
    {
        $payload = new Payload($data, 'SearchResult');

        return new self(
            results: array_map(SearchHit::fromArray(...), $payload->objects('results')),
            candidateCount: $payload->nullableInt('candidate_count'),
        );
    }
}
