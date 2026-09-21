<?php

declare(strict_types=1);

use Ragbridge\Dto\RetrievalInfo;
use Ragbridge\Dto\Source;
use Ragbridge\Tests\Support\Payloads;

it('builds a source from a valid payload', function (): void {
    $source = Source::fromArray(Payloads::source());

    expect($source->documentId)->toBe(Payloads::DOCUMENT_ID)
        ->and($source->filename)->toBe('handbook.pdf')
        ->and($source->chunkIndex)->toBe(3)
        ->and($source->snippet)->toBe('Employees accrue 25 days of leave per year.')
        ->and($source->score)->toBe(0.87);
});

it('defaults the optional fields', function (): void {
    $source = Source::fromArray(Payloads::source());

    expect($source->contextOnly)->toBeFalse()
        ->and($source->retrieval)->toBeNull();
});

it('reads the optional fields when present', function (): void {
    $source = Source::fromArray(Payloads::source([
        'context_only' => true,
        'retrieval' => Payloads::retrieval(),
    ]));

    expect($source->contextOnly)->toBeTrue()
        ->and($source->retrieval)->toBeInstanceOf(RetrievalInfo::class)
        ->and($source->retrieval?->vectorRank)->toBe(2)
        ->and($source->retrieval?->keywordRank)->toBeNull()
        ->and($source->retrieval?->fusedScore)->toBe(0.0328)
        ->and($source->retrieval?->rankBeforeRerank)->toBe(4);
});

it('treats an explicit null retrieval as absent', function (): void {
    expect(Source::fromArray(Payloads::source(['retrieval' => null]))->retrieval)->toBeNull();
});

it('widens an integer score to a float', function (): void {
    expect(Source::fromArray(Payloads::source(['score' => 1]))->score)->toBe(1.0);
});

it('rejects a payload with a missing field', function (string $field): void {
    $payload = Payloads::source();
    unset($payload[$field]);

    expect(fn() => Source::fromArray($payload))
        ->toThrow(InvalidArgumentException::class, sprintf('Source: missing required field "%s"', $field));
})->with(['document_id', 'filename', 'chunk_index', 'snippet', 'score']);

it('rejects a payload with a field of the wrong type', function (string $field, mixed $value): void {
    expect(fn() => Source::fromArray(Payloads::source([$field => $value])))
        ->toThrow(InvalidArgumentException::class, sprintf('Source: field "%s"', $field));
})->with([
    'document_id not a uuid' => ['document_id', 'abc'],
    'filename as integer' => ['filename', 7],
    'chunk_index as string' => ['chunk_index', '3'],
    'chunk_index as float' => ['chunk_index', 3.5],
    'snippet as null' => ['snippet', null],
    'score as string' => ['score', '0.87'],
    'context_only as string' => ['context_only', 'yes'],
    'retrieval as list' => ['retrieval', [1, 2]],
    'retrieval as string' => ['retrieval', 'none'],
]);

it('reports an invalid retrieval object with its own context', function (): void {
    $payload = Payloads::source(['retrieval' => Payloads::retrieval(['rank_before_rerank' => 'first'])]);

    expect(fn() => Source::fromArray($payload))
        ->toThrow(InvalidArgumentException::class, 'RetrievalInfo: field "rank_before_rerank"');
});

it('rejects retrieval info with a missing score', function (): void {
    $retrieval = Payloads::retrieval();
    unset($retrieval['fused_score']);

    expect(fn() => RetrievalInfo::fromArray($retrieval))
        ->toThrow(InvalidArgumentException::class, 'RetrievalInfo: missing required field "fused_score"');
});
