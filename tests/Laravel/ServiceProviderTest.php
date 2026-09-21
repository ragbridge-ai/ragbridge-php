<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Ragbridge\Laravel\RagbridgeServiceProvider;
use Ragbridge\RagbridgeClient;
use Ragbridge\Tests\Support\FixedClientStrategy;
use Ragbridge\Tests\Support\Fixtures;
use Ragbridge\Tests\Support\RecordingClient;

afterEach(function (): void {
    FixedClientStrategy::reset();
});

it('binds the client as a singleton', function (): void {
    FixedClientStrategy::install(new RecordingClient());

    expect(app(RagbridgeClient::class))->toBeInstanceOf(RagbridgeClient::class)
        ->and(app(RagbridgeClient::class))->toBe(app(RagbridgeClient::class));
});

it('resolves the same client through the ragbridge alias', function (): void {
    FixedClientStrategy::install(new RecordingClient());

    expect(app('ragbridge'))->toBe(app(RagbridgeClient::class));
});

it('provides defaults for the configuration', function (): void {
    expect(config('ragbridge.base_url'))->toBe('http://localhost:8000')
        ->and(config('ragbridge.api_key'))->toBeNull();
});

it('builds the client from the configuration', function (): void {
    $http = new RecordingClient(Fixtures::jsonResponse(200, 'documents'));
    FixedClientStrategy::install($http);
    config(['ragbridge.base_url' => 'https://rag.example.com/api', 'ragbridge.api_key' => 'secret-key']);

    $documents = app(RagbridgeClient::class)->documents();

    expect($documents)->toHaveCount(2)
        ->and((string) $http->lastRequest()->getUri())->toBe('https://rag.example.com/api/documents')
        ->and($http->lastRequest()->getHeaderLine('Authorization'))->toBe('Bearer secret-key');
});

it('sends no authorization header when the API key is missing or empty', function (?string $apiKey): void {
    $http = new RecordingClient(Fixtures::jsonResponse(200, 'documents'));
    FixedClientStrategy::install($http);
    config(['ragbridge.api_key' => $apiKey]);

    app(RagbridgeClient::class)->documents();

    expect($http->lastRequest()->hasHeader('Authorization'))->toBeFalse();
})->with([
    'null' => [null],
    'empty string' => [''],
]);

it('rejects a missing base URL with a clear message', function (mixed $baseUrl): void {
    FixedClientStrategy::install(new RecordingClient());
    config(['ragbridge.base_url' => $baseUrl]);

    expect(fn() => app(RagbridgeClient::class))
        ->toThrow(InvalidArgumentException::class, 'RAGBRIDGE_BASE_URL');
})->with([
    'null' => [null],
    'empty' => [''],
    'not a string' => [8000],
]);

it('rejects an API key that is not a string', function (): void {
    FixedClientStrategy::install(new RecordingClient());
    config(['ragbridge.api_key' => 12345]);

    expect(fn() => app(RagbridgeClient::class))
        ->toThrow(InvalidArgumentException::class, 'ragbridge.api_key');
});

it('rejects a base URL that is not absolute', function (): void {
    FixedClientStrategy::install(new RecordingClient());
    config(['ragbridge.base_url' => 'localhost:8000']);

    expect(fn() => app(RagbridgeClient::class))
        ->toThrow(InvalidArgumentException::class, 'absolute http or https URL');
});

it('makes the configuration publishable', function (): void {
    $paths = ServiceProvider::pathsToPublish(RagbridgeServiceProvider::class, RagbridgeServiceProvider::CONFIG_TAG);

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toBe(RagbridgeServiceProvider::configPath())
        ->and(file_exists(RagbridgeServiceProvider::configPath()))->toBeTrue()
        ->and(array_values($paths)[0])->toBeString()->toEndWith('ragbridge.php');
});

it('reads the configuration values from the environment', function (): void {
    $_ENV['RAGBRIDGE_BASE_URL'] = 'https://env.example.com';
    $_ENV['RAGBRIDGE_API_KEY'] = 'key-from-env';

    try {
        $config = require RagbridgeServiceProvider::configPath();
    } finally {
        unset($_ENV['RAGBRIDGE_BASE_URL'], $_ENV['RAGBRIDGE_API_KEY']);
    }

    expect($config)->toBe(['base_url' => 'https://env.example.com', 'api_key' => 'key-from-env']);
});

it('is registered for package auto-discovery', function (): void {
    $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);

    expect($composer)->toBeArray()
        ->toHaveKey('extra.laravel.providers', [RagbridgeServiceProvider::class]);
});
