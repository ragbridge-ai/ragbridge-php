<?php

declare(strict_types=1);

use Ragbridge\Dto\Document;
use Ragbridge\Dto\DocumentStatus;
use Ragbridge\Laravel\Facades\Ragbridge;
use Ragbridge\RagbridgeClient;
use Ragbridge\SearchMode;
use Ragbridge\Tests\Support\FixedClientStrategy;
use Ragbridge\Tests\Support\Fixtures;
use Ragbridge\Tests\Support\RecordingClient;

afterEach(function (): void {
    Ragbridge::clearResolvedInstances();
    FixedClientStrategy::reset();
});

it('forwards calls to the client in the container', function (): void {
    $http = new RecordingClient(Fixtures::jsonResponse(200, 'query_response'));
    FixedClientStrategy::install($http);

    $result = Ragbridge::query('How much leave do I get?', topK: 3, mode: SearchMode::Vector);

    expect($result->answer)->toBe('Employees receive 25 days of paid leave per year.')
        ->and((string) $http->lastRequest()->getUri())->toBe('http://localhost:8000/query')
        ->and(json_decode($http->lastBody(), true))->toBe(['question' => 'How much leave do I get?', 'top_k' => 3, 'mode' => 'vector']);
});

it('uses the singleton of the container', function (): void {
    FixedClientStrategy::install(new RecordingClient());

    expect(Ragbridge::getFacadeRoot())->toBe(app(RagbridgeClient::class));
});

it('can be mocked with shouldReceive', function (): void {
    Ragbridge::shouldReceive('deleteDocument')->once()->with('doc-1');

    Ragbridge::deleteDocument('doc-1');
});

it('can be mocked with a return value', function (): void {
    $document = new Document(
        id: '3f2b8c1e-5d4a-4b7e-9c61-0a1b2c3d4e5f',
        filename: 'handbook.pdf',
        contentType: 'application/pdf',
        status: DocumentStatus::Ready,
        error: null,
        createdAt: new DateTimeImmutable('2026-03-14T09:26:53Z'),
    );
    Ragbridge::shouldReceive('document')->once()->with($document->id)->andReturn($document);

    expect(Ragbridge::document($document->id))->toBe($document);
});

it('can be replaced with a client that talks to a fake HTTP client', function (): void {
    $http = new RecordingClient(Fixtures::jsonResponse(200, 'documents'));
    $factory = Fixtures::factory();
    Ragbridge::swap(new RagbridgeClient($http, $factory, $factory, 'https://rag.test'));

    expect(Ragbridge::documents())->toHaveCount(2)
        ->and((string) $http->lastRequest()->getUri())->toBe('https://rag.test/documents');
});

it('documents every public method of the client', function (): void {
    $docblock = (new ReflectionClass(Ragbridge::class))->getDocComment();
    $methods = array_filter(
        (new ReflectionClass(RagbridgeClient::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn(ReflectionMethod $method): bool => ! $method->isStatic() && ! $method->isConstructor(),
    );

    expect($docblock)->toBeString()
        ->and($methods)->not->toBeEmpty();

    foreach ($methods as $method) {
        expect($docblock)->toContain('@method static')->toContain(' ' . $method->getName() . '(');
    }
});

it('is registered as an alias for package auto-discovery', function (): void {
    $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);

    expect($composer)->toBeArray()
        ->toHaveKey('extra.laravel.aliases', ['Ragbridge' => Ragbridge::class]);
});
