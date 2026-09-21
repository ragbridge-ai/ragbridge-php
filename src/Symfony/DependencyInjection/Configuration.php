<?php

declare(strict_types=1);

namespace Ragbridge\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Configuration tree of the `ragbridge` bundle.
 *
 * ```yaml
 * ragbridge:
 *     base_url: '%env(RAGBRIDGE_BASE_URL)%'   # required
 *     api_key: '%env(RAGBRIDGE_API_KEY)%'     # optional
 *     retry:                                  # optional, off by default
 *         enabled: true
 *         max_attempts: 3
 *         base_delay_ms: 200
 *         max_delay_ms: 10000
 *         retry_post: false
 * ```
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('ragbridge');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('base_url')
                    ->info('Root URL of the ragbridge service, for example http://localhost:8000.')
                    ->isRequired()
                    // Only the type is checked here. For an environment variable placeholder Symfony
                    // also runs the closure with stand-in values such as an empty string, so
                    // emptiness is checked by the extension on the processed value.
                    ->beforeNormalization()
                        ->ifTrue(static fn(mixed $value): bool => ! is_string($value))
                        ->then(static function (): never {
                            throw new InvalidConfigurationException('The "ragbridge.base_url" option must be a string.');
                        })
                    ->end()
                ->end()
                ->scalarNode('api_key')
                    ->info('Sent to the service as a bearer token. Leave it unset for a service that does not require authentication.')
                    ->defaultNull()
                    ->beforeNormalization()
                        ->ifTrue(static fn(mixed $value): bool => $value !== null && ! is_string($value))
                        ->then(static function (): never {
                            throw new InvalidConfigurationException('The "ragbridge.api_key" option must be a string or null.');
                        })
                    ->end()
                ->end()
                ->arrayNode('retry')
                    ->info('Sends requests again that failed for a transient reason: a connection error, or HTTP 429, 502, 503 or 504. Off by default.')
                    ->canBeEnabled()
                    ->children()
                        // The lower limit of 1 is checked by the extension, not with min(): before
                        // Symfony 6.4.x the limit is also applied to the stand-in value (0) of an
                        // environment variable placeholder and would reject %env(int:...)%.
                        ->integerNode('max_attempts')
                            ->info('Total number of tries, including the first. At least 1.')
                            ->defaultValue(3)
                        ->end()
                        ->integerNode('base_delay_ms')
                            ->info('Pause before the first retry, in milliseconds. It doubles for each further retry.')
                            ->defaultValue(200)
                            ->min(0)
                        ->end()
                        ->integerNode('max_delay_ms')
                            ->info('Longest pause between two tries, in milliseconds.')
                            ->defaultValue(10000)
                            ->min(0)
                        ->end()
                        ->booleanNode('retry_post')
                            ->info('Also retry POST requests (query, search, agent and uploads). A POST whose response was lost may already have been processed by the service.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
