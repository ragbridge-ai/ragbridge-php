<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Ragbridge\Exception\ServerException;
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
    $variables = [
        'RAGBRIDGE_BASE_URL' => 'https://env.example.com',
        'RAGBRIDGE_API_KEY' => 'key-from-env',
        'RAGBRIDGE_RETRY_ENABLED' => 'true',
        'RAGBRIDGE_RETRY_MAX_ATTEMPTS' => '5',
        'RAGBRIDGE_RETRY_BASE_DELAY_MS' => '100',
        'RAGBRIDGE_RETRY_MAX_DELAY_MS' => '2000',
        'RAGBRIDGE_RETRY_POST' => 'true',
    ];

    foreach ($variables as $name => $value) {
        $_ENV[$name] = $value;
    }

    try {
        $config = require RagbridgeServiceProvider::configPath();
    } finally {
        foreach (array_keys($variables) as $name) {
            unset($_ENV[$name]);
        }
    }

    expect($config)->toBe([
        'base_url' => 'https://env.example.com',
        'api_key' => 'key-from-env',
        'retry' => [
            'enabled' => true,
            'max_attempts' => '5',
            'base_delay_ms' => '100',
            'max_delay_ms' => '2000',
            'retry_post' => true,
        ],
    ]);
});

it('keeps retries off unless the environment enables them', function (): void {
    expect(require RagbridgeServiceProvider::configPath())->toHaveKey('retry', [
        'enabled' => false,
        'max_attempts' => 3,
        'base_delay_ms' => 200,
        'max_delay_ms' => 10000,
        'retry_post' => false,
    ]);
});

it('is registered for package auto-discovery', function (): void {
    $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);

    expect($composer)->toBeArray()
        ->toHaveKey('extra.laravel.providers', [RagbridgeServiceProvider::class]);
});

describe('retries', function (): void {
    it('are off by default', function (): void {
        $http = new RecordingClient(Fixtures::response(503), Fixtures::jsonResponse(200, 'documents'));
        FixedClientStrategy::install($http);

        expect(config('ragbridge.retry.enabled'))->toBeFalse()
            ->and(fn() => app(RagbridgeClient::class)->documents())->toThrow(ServerException::class)
            ->and($http->requests)->toHaveCount(1);
    });

    it('can be enabled in the configuration', function (): void {
        $http = new RecordingClient(Fixtures::response(503), Fixtures::jsonResponse(200, 'documents'));
        FixedClientStrategy::install($http);
        config(['ragbridge.retry.enabled' => true, 'ragbridge.retry.base_delay_ms' => 1, 'ragbridge.retry.max_delay_ms' => 2]);

        expect(app(RagbridgeClient::class)->documents())->toHaveCount(2)
            ->and($http->requests)->toHaveCount(2);
    });

    it('apply the configured number of attempts', function (): void {
        $http = new RecordingClient(Fixtures::response(503), Fixtures::response(503), Fixtures::response(503), Fixtures::response(503));
        FixedClientStrategy::install($http);
        config(['ragbridge.retry' => ['enabled' => true, 'max_attempts' => 2, 'base_delay_ms' => 1, 'max_delay_ms' => 2]]);

        expect(fn() => app(RagbridgeClient::class)->documents())->toThrow(ServerException::class)
            ->and($http->requests)->toHaveCount(2);
    });

    it('leave POST requests alone unless retry_post is set', function (bool $retryPost, int $requests): void {
        $http = new RecordingClient(Fixtures::response(503), Fixtures::jsonResponse(200, 'query_response'));
        FixedClientStrategy::install($http);
        config(['ragbridge.retry' => ['enabled' => true, 'retry_post' => $retryPost, 'base_delay_ms' => 1, 'max_delay_ms' => 2]]);

        try {
            app(RagbridgeClient::class)->query('Q');
        } catch (ServerException) {
        }

        expect($http->requests)->toHaveCount($requests);
    })->with([
        'off' => [false, 1],
        'on' => [true, 2],
    ]);

    it('read numbers and booleans from environment strings', function (): void {
        $http = new RecordingClient(Fixtures::response(503), Fixtures::response(503), Fixtures::response(503));
        FixedClientStrategy::install($http);
        config(['ragbridge.retry' => ['enabled' => 'true', 'max_attempts' => '3', 'base_delay_ms' => '1', 'max_delay_ms' => '2', 'retry_post' => 'false']]);

        expect(fn() => app(RagbridgeClient::class)->documents())->toThrow(ServerException::class)
            ->and($http->requests)->toHaveCount(3);
    });

    it('work when a published configuration has no retry section', function (): void {
        $http = new RecordingClient(Fixtures::response(503));
        FixedClientStrategy::install($http);
        config(['ragbridge.retry' => null]);

        expect(fn() => app(RagbridgeClient::class)->documents())->toThrow(ServerException::class)
            ->and($http->requests)->toHaveCount(1);
    });

    it('reject a value that is not a number', function (): void {
        FixedClientStrategy::install(new RecordingClient());
        config(['ragbridge.retry' => ['enabled' => true, 'max_attempts' => 'many']]);

        expect(fn() => app(RagbridgeClient::class))
            ->toThrow(InvalidArgumentException::class, 'ragbridge.retry.max_attempts');
    });

    it('reject values out of range', function (): void {
        FixedClientStrategy::install(new RecordingClient());
        config(['ragbridge.retry' => ['enabled' => true, 'max_attempts' => 0]]);

        expect(fn() => app(RagbridgeClient::class))
            ->toThrow(InvalidArgumentException::class, 'at least 1');
    });
});
