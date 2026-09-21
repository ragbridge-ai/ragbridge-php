<?php

declare(strict_types=1);

namespace Ragbridge\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Ragbridge\RagbridgeClient;
use Ragbridge\RetryPolicy;

/**
 * Registers the ragbridge client in the Laravel container.
 *
 * The client is a singleton built from the `ragbridge` configuration. It uses the PSR-18
 * client and PSR-17 factories that php-http/discovery finds, which includes the Guzzle
 * installation every Laravel application already has. Retries are enabled through the
 * `ragbridge.retry` configuration.
 */
final class RagbridgeServiceProvider extends ServiceProvider
{
    public const CONFIG_TAG = 'ragbridge-config';

    public function register(): void
    {
        $this->mergeConfigFrom(self::configPath(), 'ragbridge');

        $this->app->singleton(RagbridgeClient::class, static function (Application $app): RagbridgeClient {
            $config = $app->make(Repository::class);

            $baseUrl = $config->get('ragbridge.base_url');
            $apiKey = $config->get('ragbridge.api_key');

            if (! is_string($baseUrl) || $baseUrl === '') {
                throw new InvalidArgumentException(
                    'The ragbridge base URL is not configured. Set the RAGBRIDGE_BASE_URL environment variable or the ragbridge.base_url configuration value.',
                );
            }

            if ($apiKey !== null && ! is_string($apiKey)) {
                throw new InvalidArgumentException('The ragbridge.api_key configuration value must be a string or null.');
            }

            return RagbridgeClient::create($baseUrl, $apiKey, self::retryPolicy($config->get('ragbridge.retry')));
        });

        $this->app->alias(RagbridgeClient::class, 'ragbridge');
    }

    /**
     * The retry policy of the `ragbridge.retry` configuration, or null when retries are off.
     *
     * Values read from the environment arrive as strings, so numbers and booleans are parsed.
     */
    private static function retryPolicy(mixed $retry): ?RetryPolicy
    {
        if (! is_array($retry) || ! filter_var($retry['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $integer = static function (string $key, int $default) use ($retry): int {
            $value = filter_var($retry[$key] ?? $default, FILTER_VALIDATE_INT);

            return $value !== false
                ? $value
                : throw new InvalidArgumentException(sprintf('The ragbridge.retry.%s configuration value must be an integer.', $key));
        };

        return new RetryPolicy(
            maxAttempts: $integer('max_attempts', 3),
            baseDelayMs: $integer('base_delay_ms', 200),
            maxDelayMs: $integer('max_delay_ms', 10_000),
            retryPost: filter_var($retry['retry_post'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([self::configPath() => $this->app->configPath('ragbridge.php')], self::CONFIG_TAG);
        }
    }

    public static function configPath(): string
    {
        return __DIR__ . '/../../config/ragbridge.php';
    }
}
