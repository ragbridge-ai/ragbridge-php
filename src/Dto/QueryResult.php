<?php

declare(strict_types=1);

namespace Ragbridge\Dto;

use InvalidArgumentException;
use Ragbridge\Internal\Payload;

/**
 * The answer to a question together with the sources it was drawn from.
 *
 * Wire format:
 *
 * @phpstan-type QueryResultData array{answer: string, sources: list<array<mixed>>}
 */
final readonly class QueryResult
{
    /**
     * @param list<Source> $sources
     */
    public function __construct(
        public string $answer,
        public array $sources,
    ) {}

    /**
     * @param array<mixed> $data decoded JSON object, see QueryResultData
     *
     * @throws InvalidArgumentException when a field is missing or has the wrong type
     */
    public static function fromArray(array $data): self
    {
        $payload = new Payload($data, 'QueryResult');

        return new self(
            answer: $payload->string('answer'),
            sources: array_map(Source::fromArray(...), $payload->objects('sources')),
        );
    }
}
