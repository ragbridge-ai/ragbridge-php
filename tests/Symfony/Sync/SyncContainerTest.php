<?php

declare(strict_types=1);

use Ragbridge\Symfony\DependencyInjection\RagbridgeExtension;
use Ragbridge\Symfony\Sync\EntityChangeListener;
use Ragbridge\Symfony\Sync\EntityExternalId;
use Ragbridge\Symfony\Sync\SyncCommand;
use Ragbridge\Symfony\Sync\SyncMessageHandler;
use Ragbridge\Sync\Reconciler;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Loads the extension into a bare, uncompiled container, so the definitions and their tags
 * can be inspected directly.
 *
 * @param array<string, mixed> $config configuration under the `ragbridge` key
 */
function loadedContainer(array $config = ['base_url' => 'http://localhost:8000']): ContainerBuilder
{
    $container = new ContainerBuilder();
    (new RagbridgeExtension())->load([$config], $container);

    return $container;
}

it('registers the reconciler and the external id helper', function (): void {
    $container = loadedContainer();

    expect($container->hasDefinition(Reconciler::class))->toBeTrue()
        ->and($container->hasDefinition(EntityExternalId::class))->toBeTrue();
});

it('registers the Doctrine listener for every lifecycle event it needs', function (): void {
    $container = loadedContainer();

    $events = array_column($container->getDefinition(EntityChangeListener::class)->getTag('doctrine.event_listener'), 'event');

    expect($events)->toBe(['postPersist', 'postUpdate', 'preRemove', 'postFlush']);
});

it('passes the sync.enabled configuration to the listener', function (bool $enabled): void {
    $container = loadedContainer(['base_url' => 'http://localhost:8000', 'sync' => ['enabled' => $enabled]]);

    expect($container->getDefinition(EntityChangeListener::class)->getArgument('$enabled'))->toBe($enabled);
})->with(['enabled' => [true], 'disabled' => [false]]);

it('registers the Messenger handler', function (): void {
    $container = loadedContainer();

    expect($container->getDefinition(SyncMessageHandler::class)->getTag('messenger.message_handler'))->toHaveCount(1);
});

it('registers the console command under the ragbridge:sync name', function (): void {
    $container = loadedContainer();

    expect($container->getDefinition(SyncCommand::class)->getTag('console.command'))
        ->toBe([['command' => 'ragbridge:sync']]);
});
