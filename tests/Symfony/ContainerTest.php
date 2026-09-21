<?php

declare(strict_types=1);

use Ragbridge\Exception\ServerException;
use Ragbridge\RagbridgeClient;
use Ragbridge\Symfony\DependencyInjection\RagbridgeExtension;
use Ragbridge\Symfony\RagbridgeBundle;
use Ragbridge\Tests\Support\FixedClientStrategy;
use Ragbridge\Tests\Support\Fixtures;
use Ragbridge\Tests\Support\RecordingClient;
use Ragbridge\Tests\Symfony\Support\ClientConsumer;
use Ragbridge\Tests\Symfony\Support\ContainerFactory;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The Authorization header the mock response received, or null when none was sent.
 */
function authorizationOf(MockResponse $response): ?string
{
    $headers = $response->getRequestOptions()['normalized_headers'] ?? [];

    if (! is_array($headers) || ! is_array($headers['authorization'] ?? null)) {
        return null;
    }

    $value = $headers['authorization'][0] ?? null;

    return is_string($value) ? $value : null;
}

/**
 * The URL a mock response was requested with, or null when it was never used.
 */
function requestedUrl(MockResponse $response): ?string
{
    try {
        return $response->getRequestUrl();
    } catch (Error) {
        // The property is not initialised until the response is used.
        return null;
    }
}

afterEach(function (): void {
    FixedClientStrategy::reset();
    unset($_ENV['RAGBRIDGE_BASE_URL'], $_ENV['RAGBRIDGE_API_KEY']);
});

it('provides its extension under the ragbridge key', function (): void {
    $extension = (new RagbridgeBundle())->getContainerExtension();

    expect($extension)->toBeInstanceOf(RagbridgeExtension::class)
        ->and($extension?->getAlias())->toBe('ragbridge');
});

it('defines the client so that it can be autowired by type', function (): void {
    $container = ContainerFactory::build(['base_url' => 'http://localhost:8000']);

    $consumer = $container->get('test.consumer');
    assert($consumer instanceof ClientConsumer);

    expect($consumer->client)->toBeInstanceOf(RagbridgeClient::class);
});

it('offers the same client under the ragbridge.client alias', function (): void {
    $container = ContainerFactory::build(['base_url' => 'http://localhost:8000']);

    expect($container->get('test.alias'))->toBe($container->get('test.client'));
});

it('injects the shared client into consumers', function (): void {
    $container = ContainerFactory::build(['base_url' => 'http://localhost:8000']);

    $consumer = $container->get('test.consumer');
    assert($consumer instanceof ClientConsumer);

    expect($consumer->client)->toBe($container->get('test.client'));
});

it('exposes the configuration as parameters', function (): void {
    $container = ContainerFactory::build(['base_url' => 'https://rag.example.com', 'api_key' => 'secret-key']);

    expect($container->getParameter('ragbridge.base_url'))->toBe('https://rag.example.com')
        ->and($container->getParameter('ragbridge.api_key'))->toBe('secret-key');
});

it('sends requests through the http_client service of the application', function (): void {
    $response = new MockResponse('[]');
    $container = ContainerFactory::build(['base_url' => 'https://rag.example.com/api', 'api_key' => 'secret-key'], $response);

    $client = $container->get('test.client');
    expect($client)->toBeInstanceOf(RagbridgeClient::class);
    assert($client instanceof RagbridgeClient);

    $client->documents();

    expect($response->getRequestUrl())->toBe('https://rag.example.com/api/documents')
        ->and($response->getRequestMethod())->toBe('GET')
        ->and(authorizationOf($response))->toBe('Authorization: Bearer secret-key');
});

it('sends no authorization header without an API key', function (?string $apiKey): void {
    $response = new MockResponse('[]');
    $container = ContainerFactory::build(['base_url' => 'http://localhost:8000', 'api_key' => $apiKey], $response);

    $client = $container->get('test.client');
    assert($client instanceof RagbridgeClient);
    $client->documents();

    expect(authorizationOf($response))->toBeNull();
})->with([
    'not configured' => [null],
    'empty' => [''],
]);

it('creates a default HTTP client when the application has none', function (): void {
    $container = ContainerFactory::build(['base_url' => 'http://localhost:8000']);

    expect($container->has('http_client'))->toBeFalse()
        ->and($container->get('test.client'))->toBeInstanceOf(RagbridgeClient::class);
});

it('resolves environment variables in the configuration', function (): void {
    $_ENV['RAGBRIDGE_BASE_URL'] = 'https://env.example.com';
    $_ENV['RAGBRIDGE_API_KEY'] = 'key-from-env';

    $response = new MockResponse('[]');
    $container = ContainerFactory::build(['base_url' => '%env(RAGBRIDGE_BASE_URL)%', 'api_key' => '%env(RAGBRIDGE_API_KEY)%'], $response);

    $client = $container->get('test.client');
    assert($client instanceof RagbridgeClient);
    $client->documents();

    expect($response->getRequestUrl())->toBe('https://env.example.com/documents')
        ->and(authorizationOf($response))->toBe('Authorization: Bearer key-from-env');
});

it('falls back to discovery when the Symfony HTTP client is not used', function (): void {
    $http = new RecordingClient(Fixtures::jsonResponse(200, 'documents'));
    FixedClientStrategy::install($http);

    $container = ContainerFactory::build(['base_url' => 'https://rag.example.com', 'api_key' => 'secret-key'], null, false);

    $client = $container->get('test.client');
    assert($client instanceof RagbridgeClient);

    expect($container->has('ragbridge.http_client'))->toBeFalse()
        ->and($client->documents())->toHaveCount(2)
        ->and((string) $http->lastRequest()->getUri())->toBe('https://rag.example.com/documents')
        ->and($http->lastRequest()->getHeaderLine('Authorization'))->toBe('Bearer secret-key');
});

it('fails to compile without a base URL', function (): void {
    expect(fn() => ContainerFactory::build([]))
        ->toThrow(InvalidConfigurationException::class, 'base_url');
});

it('rejects an empty base URL', function (): void {
    expect(fn() => ContainerFactory::build(['base_url' => '']))
        ->toThrow(InvalidConfigurationException::class, 'The "ragbridge.base_url" option must not be empty.');
});

it('reports an empty base URL from the environment when the client is created', function (): void {
    $_ENV['RAGBRIDGE_BASE_URL'] = '';
    $container = ContainerFactory::build(['base_url' => '%env(RAGBRIDGE_BASE_URL)%']);

    expect(fn() => $container->get('test.client'))
        ->toThrow(InvalidArgumentException::class, 'The base URL must be an absolute http or https URL');
});

it('reports an invalid base URL when the client is created', function (): void {
    $container = ContainerFactory::build(['base_url' => 'not-a-url']);

    expect(fn() => $container->get('test.client'))
        ->toThrow(InvalidArgumentException::class, 'The base URL must be an absolute http or https URL');
});

describe('retries', function (): void {
    it('are off by default', function (): void {
        $responses = [new MockResponse('{"detail":"unavailable"}', ['http_code' => 503]), new MockResponse('[]')];
        $container = ContainerFactory::build(['base_url' => 'http://localhost:8000'], $responses);

        $client = $container->get('test.client');
        assert($client instanceof RagbridgeClient);

        expect(fn() => $client->documents())->toThrow(ServerException::class)
            ->and($responses[0]->getInfo('http_code'))->toBe(503)
            ->and(requestedUrl($responses[1]))->toBeNull();
    });

    it('can be enabled in the configuration', function (): void {
        $responses = [new MockResponse('', ['http_code' => 503]), new MockResponse('[]')];
        $container = ContainerFactory::build(
            ['base_url' => 'http://localhost:8000', 'retry' => ['enabled' => true, 'base_delay_ms' => 1, 'max_delay_ms' => 2]],
            $responses,
        );

        $client = $container->get('test.client');
        assert($client instanceof RagbridgeClient);

        expect($client->documents())->toBe([])
            ->and($responses[1]->getRequestUrl())->toBe('http://localhost:8000/documents');
    });

    it('apply the configured number of attempts', function (): void {
        $responses = [new MockResponse('', ['http_code' => 503]), new MockResponse('', ['http_code' => 503]), new MockResponse('[]')];
        $container = ContainerFactory::build(
            ['base_url' => 'http://localhost:8000', 'retry' => ['enabled' => true, 'max_attempts' => 2, 'base_delay_ms' => 1, 'max_delay_ms' => 2]],
            $responses,
        );

        $client = $container->get('test.client');
        assert($client instanceof RagbridgeClient);

        expect(fn() => $client->documents())->toThrow(ServerException::class)
            ->and($responses[1]->getRequestUrl())->toBe('http://localhost:8000/documents')
            ->and(requestedUrl($responses[2]))->toBeNull();
    });

    it('leave POST requests alone unless retry_post is set', function (bool $retryPost, bool $secondSent): void {
        $responses = [new MockResponse('', ['http_code' => 503]), new MockResponse('{"answer":"x","sources":[]}')];
        $container = ContainerFactory::build(
            ['base_url' => 'http://localhost:8000', 'retry' => ['enabled' => true, 'retry_post' => $retryPost, 'base_delay_ms' => 1, 'max_delay_ms' => 2]],
            $responses,
        );

        $client = $container->get('test.client');
        assert($client instanceof RagbridgeClient);

        try {
            $client->query('Q');
        } catch (ServerException) {
        }

        expect(requestedUrl($responses[1]) !== null)->toBe($secondSent);
    })->with([
        'off' => [false, false],
        'on' => [true, true],
    ]);

    it('are available without the Symfony HTTP client', function (): void {
        $http = new RecordingClient(Fixtures::response(503), Fixtures::jsonResponse(200, 'documents'));
        FixedClientStrategy::install($http);

        $container = ContainerFactory::build(
            ['base_url' => 'http://localhost:8000', 'retry' => ['enabled' => true, 'base_delay_ms' => 1, 'max_delay_ms' => 2]],
            null,
            false,
        );

        $client = $container->get('test.client');
        assert($client instanceof RagbridgeClient);

        expect($client->documents())->toHaveCount(2)
            ->and($http->requests)->toHaveCount(2);
    });

    it('read their settings from environment variables', function (): void {
        $_ENV['RAGBRIDGE_RETRY_ATTEMPTS'] = '2';
        $_ENV['RAGBRIDGE_RETRY_ON'] = '1';
        $responses = [new MockResponse('', ['http_code' => 503]), new MockResponse('', ['http_code' => 503]), new MockResponse('[]')];
        $container = ContainerFactory::build(
            [
                'base_url' => 'http://localhost:8000',
                'retry' => [
                    'enabled' => '%env(bool:RAGBRIDGE_RETRY_ON)%',
                    'max_attempts' => '%env(int:RAGBRIDGE_RETRY_ATTEMPTS)%',
                    'base_delay_ms' => 1,
                    'max_delay_ms' => 2,
                ],
            ],
            $responses,
        );

        $client = $container->get('test.client');
        assert($client instanceof RagbridgeClient);

        try {
            expect(fn() => $client->documents())->toThrow(ServerException::class)
                ->and($responses[1]->getRequestUrl())->toBe('http://localhost:8000/documents')
                ->and(requestedUrl($responses[2]))->toBeNull();
        } finally {
            unset($_ENV['RAGBRIDGE_RETRY_ATTEMPTS'], $_ENV['RAGBRIDGE_RETRY_ON']);
        }
    });
});
