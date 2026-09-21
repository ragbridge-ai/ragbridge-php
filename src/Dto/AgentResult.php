<?php

declare(strict_types=1);

namespace Ragbridge\Dto;

use InvalidArgumentException;
use Ragbridge\Internal\Payload;

/**
 * The answer of the agent, the sources it is based on and the searches that were run.
 *
 * The steps make a wrong answer traceable: they show what the agent looked for.
 *
 * Wire format:
 *
 * @phpstan-type AgentResultData array{
 *     answer: string,
 *     sources: list<array<mixed>>,
 *     steps: list<array<mixed>>,
 *     step_count: int
 * }
 */
final readonly class AgentResult
{
    /**
     * @param list<Source> $sources
     * @param list<AgentStep> $steps
     * @param int $stepCount number of searches the agent ran
     */
    public function __construct(
        public string $answer,
        public array $sources,
        public array $steps,
        public int $stepCount,
    ) {}

    /**
     * @param array<mixed> $data decoded JSON object, see AgentResultData
     *
     * @throws InvalidArgumentException when a field is missing or has the wrong type
     */
    public static function fromArray(array $data): self
    {
        $payload = new Payload($data, 'AgentResult');

        return new self(
            answer: $payload->string('answer'),
            sources: array_map(Source::fromArray(...), $payload->objects('sources')),
            steps: array_map(AgentStep::fromArray(...), $payload->objects('steps')),
            stepCount: $payload->int('step_count'),
        );
    }
}
