<?php

declare(strict_types=1);

use Ragbridge\Symfony\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * @param list<array<string, mixed>> $configs
 *
 * @return array<mixed>
 */
function processedConfig(array $configs): array
{
    return (new Processor())->processConfiguration(new Configuration(), $configs);
}

/**
 * The `retry` section of a configuration that does not mention it.
 *
 * @return array<string, mixed>
 */
function retryDefaults(): array
{
    return ['enabled' => false, 'max_attempts' => 3, 'base_delay_ms' => 200, 'max_delay_ms' => 10000, 'retry_post' => false];
}

it('accepts a configuration with only the base URL', function (): void {
    expect(processedConfig([['base_url' => 'http://localhost:8000']]))
        ->toBe(['base_url' => 'http://localhost:8000', 'api_key' => null, 'retry' => retryDefaults()]);
});

it('accepts a configuration with an API key', function (): void {
    expect(processedConfig([['base_url' => 'https://rag.example.com', 'api_key' => 'secret-key']]))
        ->toBe(['base_url' => 'https://rag.example.com', 'api_key' => 'secret-key', 'retry' => retryDefaults()]);
});

it('accepts environment variable placeholders', function (): void {
    $config = processedConfig([['base_url' => '%env(RAGBRIDGE_BASE_URL)%', 'api_key' => '%env(RAGBRIDGE_API_KEY)%']]);

    expect($config['base_url'])->toBe('%env(RAGBRIDGE_BASE_URL)%')
        ->and($config['api_key'])->toBe('%env(RAGBRIDGE_API_KEY)%');
});

it('lets a later configuration override an earlier one', function (): void {
    $config = processedConfig([
        ['base_url' => 'http://localhost:8000', 'api_key' => 'base-key'],
        ['base_url' => 'https://prod.example.com'],
    ]);

    expect($config)->toBe(['base_url' => 'https://prod.example.com', 'api_key' => 'base-key', 'retry' => retryDefaults()]);
});

it('requires the base URL', function (): void {
    expect(fn() => processedConfig([[]]))
        ->toThrow(InvalidConfigurationException::class, 'The child config "base_url" under "ragbridge" must be configured');
});

it('rejects an invalid base URL value', function (mixed $value): void {
    expect(fn() => processedConfig([['base_url' => $value]]))
        ->toThrow(InvalidConfigurationException::class, 'The "ragbridge.base_url" option must be a string.');
})->with([
    'null' => [null],
    'integer' => [8000],
    'boolean' => [true],
    'array' => [['http://localhost']],
]);

it('rejects an API key that is not a string', function (mixed $value): void {
    expect(fn() => processedConfig([['base_url' => 'http://localhost:8000', 'api_key' => $value]]))
        ->toThrow(InvalidConfigurationException::class, 'The "ragbridge.api_key" option must be a string or null.');
})->with([
    'integer' => [12345],
    'boolean' => [false],
    'array' => [['key']],
]);

it('accepts an empty API key', function (): void {
    expect(processedConfig([['base_url' => 'http://localhost:8000', 'api_key' => '']])['api_key'])->toBe('');
});

it('rejects unknown options', function (): void {
    expect(fn() => processedConfig([['base_url' => 'http://localhost:8000', 'timeout' => 30]]))
        ->toThrow(InvalidConfigurationException::class, 'Unrecognized option "timeout" under "ragbridge"');
});

describe('retry', function (): void {
    it('is off unless it is enabled', function (): void {
        expect(processedConfig([['base_url' => 'http://localhost:8000']])['retry'])->toBe(retryDefaults());
    });

    it('can be enabled with the defaults', function (): void {
        $retry = processedConfig([['base_url' => 'http://localhost:8000', 'retry' => true]])['retry'];

        expect($retry)->toBe([...retryDefaults(), 'enabled' => true]);
    });

    it('can be enabled with options', function (): void {
        $retry = processedConfig([[
            'base_url' => 'http://localhost:8000',
            'retry' => ['enabled' => true, 'max_attempts' => 5, 'base_delay_ms' => 100, 'max_delay_ms' => 2000, 'retry_post' => true],
        ]])['retry'];

        expect($retry)->toBe(['enabled' => true, 'max_attempts' => 5, 'base_delay_ms' => 100, 'max_delay_ms' => 2000, 'retry_post' => true]);
    });

    it('can be switched off again by a later configuration', function (): void {
        $config = processedConfig([
            ['base_url' => 'http://localhost:8000', 'retry' => true],
            ['retry' => ['enabled' => false]],
        ]);

        expect($config)->toHaveKey('retry.enabled', false);
    });

    it('rejects values out of range', function (string $option, int $value): void {
        expect(fn() => processedConfig([['base_url' => 'http://localhost:8000', 'retry' => ['enabled' => true, $option => $value]]]))
            ->toThrow(InvalidConfigurationException::class, $option);
    })->with([
        'negative base delay' => ['base_delay_ms', -1],
        'negative maximum delay' => ['max_delay_ms', -1],
    ]);

    it('rejects values of the wrong type', function (string $option, mixed $value): void {
        expect(fn() => processedConfig([['base_url' => 'http://localhost:8000', 'retry' => ['enabled' => true, $option => $value]]]))
            ->toThrow(InvalidConfigurationException::class, $option);
    })->with([
        'attempts as text' => ['max_attempts', 'many'],
        'delay as array' => ['base_delay_ms', [1]],
        'retry_post as text' => ['retry_post', 'sometimes'],
    ]);

    it('rejects unknown options', function (): void {
        expect(fn() => processedConfig([['base_url' => 'http://localhost:8000', 'retry' => ['jitter' => false]]]))
            ->toThrow(InvalidConfigurationException::class, 'Unrecognized option "jitter" under "ragbridge.retry"');
    });
});
