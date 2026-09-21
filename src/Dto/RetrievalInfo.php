<?php

declare(strict_types=1);

namespace Ragbridge\Dto;

use InvalidArgumentException;
use Ragbridge\Internal\Payload;

/**
 * Explains why a chunk was returned: which retrieval arms found it and how reranking
 * changed its position. Only present when a query is sent with explain enabled.
 *
 * Wire format:
 *
 * @phpstan-type RetrievalInfoData array{
 *     vector_rank: int|null,
 *     keyword_rank: int|null,
 *     fused_score: float|int,
 *     rank_before_rerank: int
 * }
 */
final readonly class RetrievalInfo
{
    public function __construct(
        public ?int $vectorRank,
        public ?int $keywordRank,
        public float $fusedScore,
        public int $rankBeforeRerank,
    ) {}

    /**
     * @param array<mixed> $data decoded JSON object, see RetrievalInfoData
     *
     * @throws InvalidArgumentException when a field is missing or has the wrong type
     */
    public static function fromArray(array $data): self
    {
        $payload = new Payload($data, 'RetrievalInfo');

        return new self(
            vectorRank: $payload->nullableInt('vector_rank'),
            keywordRank: $payload->nullableInt('keyword_rank'),
            fusedScore: $payload->float('fused_score'),
            rankBeforeRerank: $payload->int('rank_before_rerank'),
        );
    }
}
