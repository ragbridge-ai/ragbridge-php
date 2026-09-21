<?php

declare(strict_types=1);

use Http\Client\Exception\NetworkException;
use Http\Mock\Client as MockClient;
use Ragbridge\Exception\ApiException;
use Ragbridge\Exception\AuthenticationException;
use Ragbridge\Exception\InvalidResponseException;
use Ragbridge\Exception\NotFoundException;
use Ragbridge\Exception\RagbridgeException;
use Ragbridge\Exception\RequestFailedException;
use Ragbridge\Exception\ServerException;
use Ragbridge\Exception\TransportException;
use Ragbridge\Exception\ValidationException;
use Ragbridge\RagbridgeClient;
use Ragbridge\SearchMode;
use Ragbridge\Tests\Support\ClientFactory;
use Ragbridge\Tests\Support\Fixtures;
use Ragbridge\Tests\Support\Thrown;

describe('query', function (): void {
    it('returns the answer and its sources', function (): void {
        [$client] = ClientFactory::answering(Fixtures::jsonResponse(200, 'query_response'));

        $result = $client->query('How much leave do I get?');

        expect($result->answer)->toBe('Employees receive 25 days of paid leave per year.')
            ->and($result->sources)->toHaveCount(2)
            ->and($result->sources[0]->filename)->toBe('handbook.pdf')
            ->and($result->sources[0]->chunkIndex)->toBe(3)
            ->and($result->sources[0]->contextOnly)->toBeFalse()
            ->and($result->sources[1]->contextOnly)->toBeTrue();
    });

    it('returns retrieval details when they are requested', function (): void {
        [$client] = ClientFactory::answering(Fixtures::jsonResponse(200, 'query_explain_response'));

        $result = $client->query('How much leave do I get?', explain: true);

        expect($result->sources[0]->retrieval?->vectorRank)->toBe(1)
            ->and($result->sources[0]->retrieval?->keywordRank)->toBeNull()
            ->and($result->sources[0]->retrieval?->rankBeforeRerank)->toBe(3);
    });

    it('posts the question as JSON to the query endpoint', function (): void {
        [$client, $http] = ClientFactory::answering(Fixtures::jsonResponse(200, 'query_response'));

        $client->query('How much leave do I get?', topK: 3);

        $request = ClientFactory::lastRequest($http);

        expect($request->getMethod())->toBe('POST')
            ->and((string) $request->getUri())->toBe('http://localhost:8000/query')
            ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
            ->and($request->getHeaderLine('Accept'))->toBe('application/json')
            ->and(json_decode((string) $request->getBody(), true))->toBe([
                'question' => 'How much leave do I get?',
                'top_k' => 3,
            ]);
    });

    it('sends the mode and explain flag only when they are set', function (): void {
        [$client, $http] = ClientFactory::answering(Fixtures::jsonResponse(200, 'query_response'));

        $client->query('Q', mode: SearchMode::Keyword, explain: true);

        expect(json_decode((string) ClientFactory::lastRequest($http)->getBody(), true))->toBe([
            'question' => 'Q',
            'top_k' => 5,
            'mode' => 'keyword',
            'explain' => true,
        ]);
    });

    it('sends the API key as a bearer token', function (): void {
        [$client, $http] = ClientFactory::answering(Fixtures::jsonResponse(200, 'query_response'));

        $client->query('Q');

        expect(ClientFactory::lastRequest($http)->getHeaderLine('Authorization'))->toBe('Bearer secret-key');
    });

    it('sends no authorization header without an API key', function (): void {
        $http = new MockClient();
        $http->addResponse(Fixtures::jsonResponse(200, 'query_response'));

        ClientFactory::make($http, apiKey: null)->query('Q');

        expect(ClientFactory::lastRequest($http)->hasHeader('Authorization'))->toBeFalse();
    });

    it('keeps a path prefix of the base URL and ignores trailing slashes', function (string $baseUrl): void {
        $http = new MockClient();
        $http->addResponse(Fixtures::jsonResponse(200, 'query_response'));

        ClientFactory::make($http, baseUrl: $baseUrl)->query('Q');

        expect((string) ClientFactory::lastRequest($http)->getUri())->toBe('https://rag.example.com/api/query');
    })->with([
        'without slash' => ['https://rag.example.com/api'],
        'with slash' => ['https://rag.example.com/api/'],
    ]);

    it('encodes non-ASCII questions as UTF-8 JSON', function (): void {
        [$client, $http] = ClientFactory::answering(Fixtures::jsonResponse(200, 'query_response'));

        $client->query('Wie viel Urlaub gibt es für Mitarbeiter?');

        expect(json_decode((string) ClientFactory::lastRequest($http)->getBody(), true))
            ->toHaveKey('question', 'Wie viel Urlaub gibt es für Mitarbeiter?');
    });

    it('rejects a question that cannot be encoded as JSON', function (): void {
        [$client, $http] = ClientFactory::answering();

        expect(fn() => $client->query("invalid \xB1 utf-8"))
            ->toThrow(InvalidArgumentException::class, 'cannot be encoded as JSON')
            ->and($http->getRequests())->toBe([]);
    });
});

describe('error responses', function (): void {
    it('maps the status to an exception', function (int $status, string $exception): void {
        [$client] = ClientFactory::answering(Fixtures::response($status, '{"detail": "nope"}'));

        expect(fn() => $client->query('Q'))->toThrow($exception);
    })->with([
        '401' => [401, AuthenticationException::class],
        '403' => [403, AuthenticationException::class],
        '404' => [404, NotFoundException::class],
        '422' => [422, ValidationException::class],
        '500' => [500, ServerException::class],
        '503' => [503, ServerException::class],
        '400' => [400, RequestFailedException::class],
        '429' => [429, RequestFailedException::class],
        '302' => [302, RequestFailedException::class],
    ]);

    it('exposes the status code and body of an authentication failure', function (): void {
        [$client] = ClientFactory::answering(Fixtures::jsonResponse(401, 'error_unauthorized'));

        $e = Thrown::by(fn() => $client->query('Q'), AuthenticationException::class);

        expect($e->statusCode())->toBe(401)
            ->and($e->body())->toBe(['detail' => 'invalid API key'])
            ->and($e->getMessage())->toBe('ragbridge service responded with HTTP 401: invalid API key');
    });

    it('exposes the field errors of a validation failure', function (): void {
        [$client] = ClientFactory::answering(Fixtures::jsonResponse(422, 'error_validation'));

        $e = Thrown::by(fn() => $client->query(''), ValidationException::class);

        expect($e->errors())->toHaveCount(2)
            ->and($e->errors()[1]['loc'])->toBe(['body', 'top_k'])
            ->and($e->errors()[1]['type'])->toBe('less_than_equal');
    });

    it('still throws when the error body is not JSON', function (): void {
        [$client] = ClientFactory::answering(
            Fixtures::response(502, '<html>Bad Gateway</html>', ['Content-Type' => 'text/html']),
        );

        $e = Thrown::by(fn() => $client->query('Q'), ServerException::class);

        expect($e->statusCode())->toBe(502)
            ->and($e->body())->toBeNull();
    });

    it('implements the marker interface for every error', function (int $status): void {
        [$client] = ClientFactory::answering(Fixtures::response($status));

        $e = Thrown::by(fn() => $client->query('Q'), RagbridgeException::class);

        expect($e)->toBeInstanceOf(ApiException::class);
    })->with([
        '401' => [401],
        '404' => [404],
        '422' => [422],
        '429' => [429],
        '500' => [500],
    ]);
});

describe('transport and malformed responses', function (): void {
    it('wraps a failure of the HTTP client', function (): void {
        $http = new MockClient();
        $http->addException(new NetworkException(
            'Connection refused',
            Fixtures::factory()->createRequest('POST', 'http://localhost:8000/query'),
        ));

        $e = Thrown::by(fn() => ClientFactory::make($http)->query('Q'), TransportException::class);

        expect($e->getMessage())->toContain('Connection refused')
            ->and($e->getPrevious())->toBeInstanceOf(NetworkException::class);
    });

    it('rejects a success response that is not valid JSON', function (): void {
        [$client] = ClientFactory::answering(Fixtures::response(200, '{"answer": '));

        expect(fn() => $client->query('Q'))
            ->toThrow(InvalidResponseException::class, 'the body is not valid JSON');
    });

    it('rejects a success response with an empty body', function (): void {
        [$client] = ClientFactory::answering(Fixtures::response(200));

        expect(fn() => $client->query('Q'))
            ->toThrow(InvalidResponseException::class, 'expected a JSON object, got null');
    });

    it('rejects a success response that is not an object', function (): void {
        [$client] = ClientFactory::answering(Fixtures::response(200, '["answer"]'));

        expect(fn() => $client->query('Q'))
            ->toThrow(InvalidResponseException::class, 'expected a JSON object, got array');
    });

    it('rejects a success response that does not match the schema', function (): void {
        [$client] = ClientFactory::answering(
            Fixtures::response(200, '{"answer": "Yes", "sources": [{"filename": "a.pdf"}]}'),
        );

        $e = Thrown::by(fn() => $client->query('Q'), InvalidResponseException::class);

        expect($e->getMessage())->toContain('Source: missing required field "document_id"')
            ->and($e->getPrevious())->toBeInstanceOf(InvalidArgumentException::class);
    });
});

describe('construction', function (): void {
    it('rejects a base URL that is not an absolute http URL', function (string $baseUrl): void {
        expect(fn() => ClientFactory::make(new MockClient(), baseUrl: $baseUrl))
            ->toThrow(InvalidArgumentException::class, 'The base URL must be an absolute http or https URL');
    })->with([
        'empty' => [''],
        'no scheme' => ['localhost:8000'],
        'relative' => ['/api'],
        'wrong scheme' => ['ftp://localhost'],
        'no host' => ['http://'],
    ]);

    it('can be built from any PSR-18 client and PSR-17 factories', function (): void {
        $factory = Fixtures::factory();

        expect(new RagbridgeClient(new MockClient(), $factory, $factory, 'http://localhost:8000'))
            ->toBeInstanceOf(RagbridgeClient::class);
    });
});

describe('API key', function (): void {
    it('treats an empty API key as no key', function (): void {
        $http = new MockClient();
        $http->addResponse(Fixtures::jsonResponse(200, 'query_response'));

        ClientFactory::make($http, apiKey: '')->query('Q');

        expect(ClientFactory::lastRequest($http)->hasHeader('Authorization'))->toBeFalse();
    });
});
