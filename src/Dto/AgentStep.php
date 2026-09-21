<?php

declare(strict_types=1);

namespace Ragbridge\Dto;

use InvalidArgumentException;
use Ragbridge\Internal\Payload;

/**
 * One search the agent ran while answering a question.
 *
 * Wire format:
 *
 * @phpstan-type AgentStepData array{query: string, results: int}
 */
final readonly class AgentStep
{
    /**
     * @param string $query the text that was searched for
     * @param int $results number of chunks the search found
     */
    public function __construct(
        public string $query,
        public int $results,
    ) {}

    /**
     * @param array<mixed> $data decoded JSON object, see AgentStepData
     *
     * @throws InvalidArgumentException when a field is missing or has the wrong type
     */
    public static function fromArray(array $data): self
    {
        $payload = new Payload($data, 'AgentStep');

        return new self(
            query: $payload->string('query'),
            results: $payload->int('results'),
        );
    }
}
