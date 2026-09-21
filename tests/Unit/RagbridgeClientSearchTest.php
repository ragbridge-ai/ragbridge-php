<?php

declare(strict_types=1);

use Ragbridge\Exception\InvalidResponseException;
use Ragbridge\Exception\ValidationException;
use Ragbridge\SearchMode;
use Ragbridge\Tests\Support\ClientFactory;
use Ragbridge\Tests\Support\Fixtures;
use Ragbridge\Tests\Support\Thrown;

describe('search', function (): void {
    it('returns the matching chunks', function (): void {
        [$client] = ClientFactory::answering(Fixtures::jsonResponse(200, 'search_response'));

        $result = $client->search('annual leave');

        expect($result->results)->toHaveCount(2)
            ->and($result->results[0]->filename)->toBe('handbook.pdf')
            ->and($result->results[0]->chunkIndex)->toBe(3)
            ->and($result->results[0]->content)->toContain('25 days')
            ->and($result->results[1]->chunkIndex)->toBe(7)
            ->and($result->candidateCount)->toBeNull()
            ->and($result->results[0]->retrieval)->toBeNull();
    });

    it('returns retrieval details when they are requested', function (): void {
        [$client] = ClientFactory::answering(Fixtures::jsonResponse(200, 'search_explain_response'));

        $result = $client->search('annual leave', explain: true);

        expect($result->candidateCount)->toBe(12)
            ->and($result->results[0]->retrieval?->vectorRank)->toBe(1)
            ->and($result->results[0]->retrieval?->keywordRank)->toBeNull()
            ->and($result->results[0]->retrieval?->rankBeforeRerank)->toBe(3);
    });

    it('posts the query as JSON to the search endpoint', function (): void {
        [$client, $http] = ClientFactory::answering(Fixtures::jsonResponse(200, 'search_response'));

        $client->search('annual leave', topK: 3);

        $request = ClientFactory::lastRequest($http);

        expect($request->getMethod())->toBe('POST')
            ->and((string) $request->getUri())->toBe('http://localhost:8000/search')
            ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
            ->and($request->getHeaderLine('Authorization'))->toBe('Bearer secret-key')
            ->and(json_decode((string) $request->getBody(), true))->toBe([
                'query' => 'annual leave',
                'top_k' => 3,
            ]);
    });

    it('sends the mode and explain flag only when they are set', function (): void {
        [$client, $http] = ClientFactory::answering(Fixtures::jsonResponse(200, 'search_response'));

        $client->search('Q', mode: SearchMode::Vector, explain: true);

        expect(json_decode((string) ClientFactory::lastRequest($http)->getBody(), true))->toBe([
            'query' => 'Q',
            'top_k' => 5,
            'mode' => 'vector',
            'explain' => true,
        ]);
    });

    it('reports a rejected request as a validation error', function (): void {
        [$client] = ClientFactory::answering(Fixtures::jsonResponse(422, 'error_validation'));

        $e = Thrown::by(fn() => $client->search('Q', topK: 0), ValidationException::class);

        expect($e->statusCode())->toBe(422);
    });

    it('rejects a response that does not match the schema', function (): void {
        [$client] = ClientFactory::answering(Fixtures::response(200, '{"hits": []}'));

        expect(fn() => $client->search('Q'))->toThrow(InvalidResponseException::class, 'SearchResult');
    });
});
