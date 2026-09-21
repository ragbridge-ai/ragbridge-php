<?php

declare(strict_types=1);

use Ragbridge\Dto\AgentResult;
use Ragbridge\Dto\AgentStep;
use Ragbridge\Dto\Source;
use Ragbridge\Tests\Support\Payloads;

it('builds an agent result with its sources and steps', function (): void {
    $result = AgentResult::fromArray(Payloads::agentResult([
        'sources' => [Payloads::source(), Payloads::source(['chunk_index' => 4])],
        'steps' => [Payloads::agentStep(), Payloads::agentStep(['query' => 'remote work', 'results' => 2])],
        'step_count' => 2,
    ]));

    expect($result->answer)->toBe('Employees receive 25 days of leave.')
        ->and($result->sources)->toHaveCount(2)
        ->and($result->sources[0])->toBeInstanceOf(Source::class)
        ->and($result->sources[1]->chunkIndex)->toBe(4)
        ->and($result->steps)->toHaveCount(2)
        ->and($result->steps[0])->toBeInstanceOf(AgentStep::class)
        ->and($result->steps[0]->query)->toBe('How much leave do employees get?')
        ->and($result->steps[0]->results)->toBe(4)
        ->and($result->steps[1]->query)->toBe('remote work')
        ->and($result->stepCount)->toBe(2);
});

it('accepts an answer without sources or steps', function (): void {
    $result = AgentResult::fromArray(Payloads::agentResult(['sources' => [], 'steps' => [], 'step_count' => 0]));

    expect($result->sources)->toBe([])
        ->and($result->steps)->toBe([])
        ->and($result->stepCount)->toBe(0);
});

it('rejects a payload with a missing field', function (string $field): void {
    $payload = Payloads::agentResult();
    unset($payload[$field]);

    expect(fn() => AgentResult::fromArray($payload))
        ->toThrow(InvalidArgumentException::class, sprintf('AgentResult: missing required field "%s"', $field));
})->with(['answer', 'sources', 'steps', 'step_count']);

it('rejects a step with a missing field', function (string $field): void {
    $step = Payloads::agentStep();
    unset($step[$field]);

    expect(fn() => AgentResult::fromArray(Payloads::agentResult(['steps' => [$step]])))
        ->toThrow(InvalidArgumentException::class, sprintf('AgentStep: missing required field "%s"', $field));
})->with(['query', 'results']);

it('rejects fields of the wrong type', function (string $context, array $payload, string $field): void {
    expect(fn() => AgentResult::fromArray($payload))
        ->toThrow(InvalidArgumentException::class, sprintf('%s: field "%s"', $context, $field));
})->with([
    'answer as null' => ['AgentResult', Payloads::agentResult(['answer' => null]), 'answer'],
    'steps as string' => ['AgentResult', Payloads::agentResult(['steps' => 'none']), 'steps'],
    'step count as string' => ['AgentResult', Payloads::agentResult(['step_count' => '2']), 'step_count'],
    'step query as int' => ['AgentStep', Payloads::agentResult(['steps' => [Payloads::agentStep(['query' => 1])]]), 'query'],
    'step results as string' => ['AgentStep', Payloads::agentResult(['steps' => [Payloads::agentStep(['results' => 'many'])]]), 'results'],
    'source score as string' => ['Source', Payloads::agentResult(['sources' => [Payloads::source(['score' => 'high'])]]), 'score'],
]);
