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
            ->end();

        return $treeBuilder;
    }
}
