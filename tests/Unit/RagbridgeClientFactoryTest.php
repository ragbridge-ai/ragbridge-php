<?php

declare(strict_types=1);

use Http\Discovery\Exception\NotFoundException as DiscoveryNotFoundException;
use Ragbridge\RagbridgeClient;
use Ragbridge\Tests\Support\FixedClientStrategy;
use Ragbridge\Tests\Support\Fixtures;
use Ragbridge\Tests\Support\RecordingClient;

afterEach(function (): void {
    FixedClientStrategy::reset();
});

it('creates a client that sends requests through the discovered HTTP client', function (): void {
    $http = new RecordingClient(Fixtures::jsonResponse(200, 'documents'));
    FixedClientStrategy::install($http);

    $documents = RagbridgeClient::create('http://localhost:8000', 'secret-key')->documents();

    expect($documents)->toHaveCount(2)
        ->and((string) $http->lastRequest()->getUri())->toBe('http://localhost:8000/documents')
        ->and($http->lastRequest()->getHeaderLine('Authorization'))->toBe('Bearer secret-key');
});

it('builds requests with the discovered PSR-17 factories', function (): void {
    $http = new RecordingClient(Fixtures::jsonResponse(200, 'query_response'));
    FixedClientStrategy::install($http);

    RagbridgeClient::create('http://localhost:8000')->query('Q');

    expect($http->lastRequest()->getMethod())->toBe('POST')
        ->and(json_decode($http->lastBody(), true))->toBe(['question' => 'Q', 'top_k' => 5]);
});

it('works without an API key', function (): void {
    $http = new RecordingClient(Fixtures::jsonResponse(200, 'documents'));
    FixedClientStrategy::install($http);

    RagbridgeClient::create('http://localhost:8000')->documents();

    expect($http->lastRequest()->hasHeader('Authorization'))->toBeFalse();
});

it('validates the base URL', function (): void {
    FixedClientStrategy::install(new RecordingClient());

    expect(fn() => RagbridgeClient::create('not-a-url'))
        ->toThrow(InvalidArgumentException::class, 'The base URL must be an absolute http or https URL');
});

it('reports a clear error when no HTTP client can be found', function (): void {
    FixedClientStrategy::installNothing();

    expect(fn() => RagbridgeClient::create('http://localhost:8000'))
        ->toThrow(DiscoveryNotFoundException::class);
});

it('can be replaced by a test double', function (): void {
    $class = new ReflectionClass(RagbridgeClient::class);

    expect($class->isFinal())->toBeFalse()
        ->and($class->isReadOnly())->toBeFalse();
});
