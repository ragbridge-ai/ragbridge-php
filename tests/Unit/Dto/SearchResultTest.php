<?php

declare(strict_types=1);

use Ragbridge\Dto\RetrievalInfo;
use Ragbridge\Dto\SearchHit;
use Ragbridge\Dto\SearchResult;
use Ragbridge\Tests\Support\Payloads;

it('builds a search result with its hits', function (): void {
    $result = SearchResult::fromArray(Payloads::searchResult([
        'results' => [Payloads::searchHit(), Payloads::searchHit(['chunk_index' => 4])],
    ]));

    expect($result->results)->toHaveCount(2)
        ->and($result->results[0])->toBeInstanceOf(SearchHit::class)
        ->and($result->results[0]->documentId)->toBe(Payloads::DOCUMENT_ID)
        ->and($result->results[0]->filename)->toBe('handbook.pdf')
        ->and($result->results[0]->chunkIndex)->toBe(3)
        ->and($result->results[0]->content)->toBe('Employees accrue 25 days of leave per year.')
        ->and($result->results[0]->score)->toBe(0.87)
        ->and($result->results[1]->chunkIndex)->toBe(4);
});

it('leaves the retrieval details and the candidate count empty when the service omits them', function (): void {
    $result = SearchResult::fromArray(Payloads::searchResult());

    expect($result->candidateCount)->toBeNull()
        ->and($result->results[0]->retrieval)->toBeNull();
});

it('reads the retrieval details and the candidate count', function (): void {
    $result = SearchResult::fromArray(Payloads::searchResult([
        'results' => [Payloads::searchHit(['retrieval' => Payloads::retrieval()])],
        'candidate_count' => 12,
    ]));

    expect($result->candidateCount)->toBe(12)
        ->and($result->results[0]->retrieval)->toBeInstanceOf(RetrievalInfo::class)
        ->and($result->results[0]->retrieval?->vectorRank)->toBe(2)
        ->and($result->results[0]->retrieval?->keywordRank)->toBeNull();
});

it('accepts a search without hits', function (): void {
    expect(SearchResult::fromArray(['results' => []])->results)->toBe([]);
});

it('widens an integer score to a float', function (): void {
    expect(SearchResult::fromArray(Payloads::searchResult(['results' => [Payloads::searchHit(['score' => 1])]]))->results[0]->score)->toBe(1.0);
});

it('rejects a result with a missing field', function (): void {
    expect(fn() => SearchResult::fromArray([]))
        ->toThrow(InvalidArgumentException::class, 'SearchResult: missing required field "results"');
});

it('rejects a hit with a missing field', function (string $field): void {
    $hit = Payloads::searchHit();
    unset($hit[$field]);

    expect(fn() => SearchResult::fromArray(Payloads::searchResult(['results' => [$hit]])))
        ->toThrow(InvalidArgumentException::class, sprintf('SearchHit: missing required field "%s"', $field));
})->with(['document_id', 'filename', 'chunk_index', 'content', 'score']);

it('rejects fields of the wrong type', function (string $context, array $payload, string $field): void {
    expect(fn() => SearchResult::fromArray($payload))
        ->toThrow(InvalidArgumentException::class, sprintf('%s: field "%s"', $context, $field));
})->with([
    'results as string' => ['SearchResult', Payloads::searchResult(['results' => 'none']), 'results'],
    'candidate count as string' => ['SearchResult', Payloads::searchResult(['candidate_count' => 'many']), 'candidate_count'],
    'content as null' => ['SearchHit', Payloads::searchResult(['results' => [Payloads::searchHit(['content' => null])]]), 'content'],
    'chunk index as string' => ['SearchHit', Payloads::searchResult(['results' => [Payloads::searchHit(['chunk_index' => '3'])]]), 'chunk_index'],
    'score as string' => ['SearchHit', Payloads::searchResult(['results' => [Payloads::searchHit(['score' => 'high'])]]), 'score'],
    'document id that is not a UUID' => ['SearchHit', Payloads::searchResult(['results' => [Payloads::searchHit(['document_id' => 'abc'])]]), 'document_id'],
]);
