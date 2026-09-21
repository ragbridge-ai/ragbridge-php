<?php

declare(strict_types=1);

namespace Ragbridge\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Ragbridge\RagbridgeClient;

/**
 * Registers the ragbridge client in the Laravel container.
 *
 * The client is a singleton built from the `ragbridge` configuration. It uses the PSR-18
 * client and PSR-17 factories that php-http/discovery finds, which includes the Guzzle
 * installation every Laravel application already has.
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

            // An empty environment variable means "no key".
            return RagbridgeClient::create($baseUrl, $apiKey === '' ? null : $apiKey);
        });

        $this->app->alias(RagbridgeClient::class, 'ragbridge');
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
