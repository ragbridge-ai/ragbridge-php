<?php

declare(strict_types=1);

namespace Ragbridge\Symfony\DependencyInjection;

use Doctrine\ORM\EntityManagerInterface;
use Ragbridge\RagbridgeClient;
use Ragbridge\Symfony\RetryPolicyFactory;
use Ragbridge\Symfony\Sync\EntityChangeListener;
use Ragbridge\Symfony\Sync\EntityExternalId;
use Ragbridge\Symfony\Sync\SyncCommand;
use Ragbridge\Symfony\Sync\SyncMessageHandler;
use Ragbridge\Sync\Reconciler;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\Messenger\MessageBusInterface;

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
 *
 * Retries are off unless `ragbridge.retry.enabled` is set. The retry policy is then created by
 * RetryPolicyFactory and passed to the client, so no extra service is exposed.
 */
final class RagbridgeExtension extends Extension
{
    /**
     * @param bool|null $useSymfonyHttpClient force the choice of HTTP client, detected when null;
     *                                        for the package's own tests, not part of the public API
     *
     * @internal
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

        $retry = $config['retry'];
        assert(is_array($retry));

        // A literal value is checked here. An environment variable placeholder is a string
        // until run time, when RetryPolicy checks the value it resolves to.
        if (is_int($retry['max_attempts']) && $retry['max_attempts'] < 1) {
            throw new InvalidConfigurationException('The "ragbridge.retry.max_attempts" option must be at least 1.');
        }

        $container->setDefinition(RagbridgeClient::class, $this->clientDefinition($container, $this->retryPolicyDefinition($retry)));
        $container->setAlias('ragbridge.client', RagbridgeClient::class);

        $this->registerSync($container, $config['sync']);
    }

    /**
     * Registers the Doctrine listener, the Messenger handler and the console command, only
     * when both symfony/messenger and doctrine/orm are installed. Neither is required by
     * the package; an application without them sees nothing extra in its container.
     *
     * @param mixed $sync the processed `sync` configuration
     */
    private function registerSync(ContainerBuilder $container, mixed $sync): void
    {
        assert(is_array($sync));

        if (! interface_exists(MessageBusInterface::class) || ! interface_exists(EntityManagerInterface::class)) {
            return;
        }

        $container->register(Reconciler::class)->setAutowired(true);
        $container->register(EntityExternalId::class)->setAutowired(true);

        $container->register(EntityChangeListener::class)
            ->setAutowired(true)
            ->setArgument('$enabled', $sync['enabled'])
            ->addTag('doctrine.event_listener', ['event' => 'postPersist'])
            ->addTag('doctrine.event_listener', ['event' => 'postUpdate'])
            ->addTag('doctrine.event_listener', ['event' => 'preRemove'])
            ->addTag('doctrine.event_listener', ['event' => 'postFlush']);

        $container->register(SyncMessageHandler::class)
            ->setAutowired(true)
            ->addTag('messenger.message_handler');

        $container->register(SyncCommand::class)
            ->setAutowired(true)
            ->addTag('console.command', ['command' => 'ragbridge:sync']);
    }

    /**
     * @param array<mixed> $retry the processed `retry` configuration
     */
    private function retryPolicyDefinition(array $retry): ?Definition
    {
        // Only a literal false is final. Any other value, including an environment variable
        // placeholder, is evaluated by the factory when the client is created.
        if ($retry['enabled'] === false) {
            return null;
        }

        return (new Definition(null, [
            $retry['enabled'],
            $retry['max_attempts'],
            $retry['base_delay_ms'],
            $retry['max_delay_ms'],
            $retry['retry_post'],
        ]))->setFactory([RetryPolicyFactory::class, 'create']);
    }

    private function clientDefinition(ContainerBuilder $container, ?Definition $retryPolicy): Definition
    {
        if (! ($this->useSymfonyHttpClient ?? class_exists(Psr18Client::class))) {
            $definition = (new Definition(RagbridgeClient::class, ['%ragbridge.base_url%', '%ragbridge.api_key%']))
                ->setFactory([RagbridgeClient::class, 'create']);

            return $retryPolicy === null ? $definition : $definition->addArgument($retryPolicy);
        }

        // Psr18Client is a PSR-18 client and also a PSR-17 request and stream factory.
        // Without an http_client service it creates its own default client.
        $container->register('ragbridge.http_client', Psr18Client::class)
            ->setArguments([new Reference('http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE)]);

        $http = new Reference('ragbridge.http_client');
        $definition = new Definition(RagbridgeClient::class, [$http, $http, $http, '%ragbridge.base_url%', '%ragbridge.api_key%']);

        return $retryPolicy === null ? $definition : $definition->addArgument($retryPolicy);
    }
}
