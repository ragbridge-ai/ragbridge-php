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

it('accepts a configuration with only the base URL', function (): void {
    expect(processedConfig([['base_url' => 'http://localhost:8000']]))
        ->toBe(['base_url' => 'http://localhost:8000', 'api_key' => null]);
});

it('accepts a configuration with an API key', function (): void {
    expect(processedConfig([['base_url' => 'https://rag.example.com', 'api_key' => 'secret-key']]))
        ->toBe(['base_url' => 'https://rag.example.com', 'api_key' => 'secret-key']);
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

    expect($config)->toBe(['base_url' => 'https://prod.example.com', 'api_key' => 'base-key']);
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
