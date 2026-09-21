<?php

declare(strict_types=1);

use Ragbridge\Dto\QueryResult;
use Ragbridge\Dto\Source;
use Ragbridge\Tests\Support\Payloads;

it('builds a query result with its sources', function (): void {
    $result = QueryResult::fromArray(Payloads::queryResult([
        'sources' => [Payloads::source(), Payloads::source(['chunk_index' => 4])],
    ]));

    expect($result->answer)->toBe('Employees receive 25 days of leave.')
        ->and($result->sources)->toHaveCount(2)
        ->and($result->sources[0])->toBeInstanceOf(Source::class)
        ->and($result->sources[1]->chunkIndex)->toBe(4);
});

it('accepts an answer without sources', function (): void {
    expect(QueryResult::fromArray(Payloads::queryResult(['sources' => []]))->sources)->toBe([]);
});

it('rejects a payload with a missing field', function (string $field): void {
    $payload = Payloads::queryResult();
    unset($payload[$field]);

    expect(fn() => QueryResult::fromArray($payload))
        ->toThrow(InvalidArgumentException::class, sprintf('QueryResult: missing required field "%s"', $field));
})->with(['answer', 'sources']);

it('rejects a payload with a field of the wrong type', function (string $field, mixed $value): void {
    expect(fn() => QueryResult::fromArray(Payloads::queryResult([$field => $value])))
        ->toThrow(InvalidArgumentException::class, sprintf('QueryResult: field "%s"', $field));
})->with([
    'answer as null' => ['answer', null],
    'answer as array' => ['answer', ['text']],
    'sources as string' => ['sources', 'none'],
    'sources as object' => ['sources', ['first' => Payloads::source()]],
]);

it('rejects a source that is not an object', function (): void {
    expect(fn() => QueryResult::fromArray(Payloads::queryResult(['sources' => ['handbook.pdf']])))
        ->toThrow(InvalidArgumentException::class, 'QueryResult: field "sources" item 0 must be an object, string given');
});

it('reports an invalid source with its own context', function (): void {
    $payload = Payloads::queryResult(['sources' => [Payloads::source(), Payloads::source(['score' => 'high'])]]);

    expect(fn() => QueryResult::fromArray($payload))
        ->toThrow(InvalidArgumentException::class, 'Source: field "score"');
});
