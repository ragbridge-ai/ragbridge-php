<?php

declare(strict_types=1);

namespace Ragbridge\Symfony\DependencyInjection;

use Ragbridge\RagbridgeClient;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Registers the ragbridge client.
 *
 * When symfony/http-client is installed, the client sends its requests through the
 * application's `http_client` service, wrapped in Symfony's Psr18Client, which also
 * provides the PSR-17 factories. Otherwise the client is created with php-http/discovery,
 * like RagbridgeClient::create().
 *
 * The client is available for autowiring as Ragbridge\RagbridgeClient and as the
 * `ragbridge.client` alias. The configuration values are exposed as the container
 * parameters `ragbridge.base_url` and `ragbridge.api_key`.
 */
final class RagbridgeExtension extends Extension
{
    /**
     * @param bool|null $useSymfonyHttpClient force the choice of HTTP client, detected when null
     */
    public function __construct(private readonly ?bool $useSymfonyHttpClient = null) {}

    /**
     * @param array<array-key, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        // The types are guaranteed by the validation in Configuration.
        assert(is_string($config['base_url']));
        assert($config['api_key'] === null || is_string($config['api_key']));

        if ($config['base_url'] === '') {
            throw new InvalidConfigurationException('The "ragbridge.base_url" option must not be empty.');
        }

        $container->setParameter('ragbridge.base_url', $config['base_url']);
        $container->setParameter('ragbridge.api_key', $config['api_key']);

        $container->setDefinition(RagbridgeClient::class, $this->clientDefinition($container));
        $container->setAlias('ragbridge.client', RagbridgeClient::class);
    }

    private function clientDefinition(ContainerBuilder $container): Definition
    {
        if (! ($this->useSymfonyHttpClient ?? class_exists(Psr18Client::class))) {
            return (new Definition(RagbridgeClient::class, ['%ragbridge.base_url%', '%ragbridge.api_key%']))
                ->setFactory([RagbridgeClient::class, 'create']);
        }

        // Psr18Client is a PSR-18 client and also a PSR-17 request and stream factory.
        // Without an http_client service it creates its own default client.
        $container->register('ragbridge.http_client', Psr18Client::class)
            ->setArguments([new Reference('http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE)]);

        $http = new Reference('ragbridge.http_client');

        return new Definition(RagbridgeClient::class, [$http, $http, $http, '%ragbridge.base_url%', '%ragbridge.api_key%']);
    }
}
