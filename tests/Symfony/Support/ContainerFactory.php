<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Symfony\Support;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Ragbridge\RagbridgeClient;
use Ragbridge\Symfony\DependencyInjection\RagbridgeExtension;
use Ragbridge\Symfony\RagbridgeBundle;
use Ragbridge\Tests\Symfony\Sync\Support\EntityManagerFactory;
use Ragbridge\Tests\Symfony\Sync\Support\RecordingMessageBus;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBusInterface;

final class ContainerFactory
{
    /**
     * Builds and compiles a container that loads the bundle extension.
     *
     * Every service is private in a compiled container, so the client and its consumer are
     * exposed through public aliases: `test.client` (by class), `test.alias` (through the
     * `ragbridge.client` alias) and `test.consumer`.
     *
     * A real entity manager and a recording message bus are always provided, as
     * `doctrine-bundle` and `framework-bundle` would in a real application, so the sync
     * services, which are autowired against them, compile. Sync-specific tests read what
     * was dispatched from `test.message_bus`.
     *
     * @param array<string, mixed> $config configuration under the `ragbridge` key
     * @param MockResponse|list<MockResponse>|null $response when set, registered as the response, or the queue of responses, of the application's `http_client`
     * @param bool|null $useSymfonyHttpClient forces the HTTP client choice of the extension
     */
    public static function build(array $config, MockResponse|array|null $response = null, ?bool $useSymfonyHttpClient = null): ContainerBuilder
    {
        $container = new ContainerBuilder();

        $extension = $useSymfonyHttpClient === null
            ? (new RagbridgeBundle())->getContainerExtension()
            : new RagbridgeExtension($useSymfonyHttpClient);

        $container->registerExtension($extension ?? throw new LogicException('The bundle has no extension.'));
        $container->loadFromExtension('ragbridge', $config);

        if ($response !== null) {
            $container->register('http_client', MockHttpClient::class)->setArguments([$response]);
        }

        $container->register(ClientConsumer::class, ClientConsumer::class)
            ->setAutowired(true)
            ->setPublic(true);
        $container->setAlias('test.client', RagbridgeClient::class)->setPublic(true);
        $container->setAlias('test.consumer', ClientConsumer::class)->setPublic(true);
        $container->setAlias('test.alias', 'ragbridge.client')->setPublic(true);

        $container->register(EntityManagerInterface::class)->setSynthetic(true)->setPublic(true);
        $container->register(MessageBusInterface::class)->setSynthetic(true)->setPublic(true);
        $container->setAlias('test.message_bus', MessageBusInterface::class)->setPublic(true);

        $container->compile(true);

        $container->set(EntityManagerInterface::class, EntityManagerFactory::create());
        $container->set(MessageBusInterface::class, new RecordingMessageBus());

        return $container;
    }
}
