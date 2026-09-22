<?php

declare(strict_types=1);

use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Ragbridge\Symfony\Sync\EntityChangeListener;
use Ragbridge\Symfony\Sync\EntityExternalId;
use Ragbridge\Symfony\Sync\SyncMessage;
use Ragbridge\Tests\Symfony\Sync\Support\EntityManagerFactory;
use Ragbridge\Tests\Symfony\Sync\Support\Post;
use Ragbridge\Tests\Symfony\Sync\Support\RecordingMessageBus;

it('dispatches a message once the flush that persisted the entity succeeds', function (): void {
    $entityManager = EntityManagerFactory::create();
    $post = new Post('Hello', 'World');
    $entityManager->persist($post);
    $entityManager->flush();

    $bus = new RecordingMessageBus();
    $listener = new EntityChangeListener(new EntityExternalId($entityManager), $bus);

    $listener->postPersist(new PostPersistEventArgs($post, $entityManager));
    expect($bus->syncMessages())->toBeEmpty();

    $listener->postFlush(new PostFlushEventArgs($entityManager));

    expect($bus->syncMessages())->toHaveCount(1);
    $message = $bus->syncMessages()[0];
    expect($message)->toBeInstanceOf(SyncMessage::class)
        ->and($message->entityClass)->toBe(Post::class)
        ->and($message->entityId)->toBe($post->id())
        ->and($message->externalId)->toBe('posts:' . $post->id());
});

it('dispatches a message for an update', function (): void {
    $entityManager = EntityManagerFactory::create();
    $post = new Post('Hello', 'World');
    $entityManager->persist($post);
    $entityManager->flush();

    $bus = new RecordingMessageBus();
    $listener = new EntityChangeListener(new EntityExternalId($entityManager), $bus);

    $listener->postUpdate(new PostUpdateEventArgs($post, $entityManager));
    $listener->postFlush(new PostFlushEventArgs($entityManager));

    expect($bus->syncMessages())->toHaveCount(1);
});

it('captures the id before a removed entity is gone', function (): void {
    $entityManager = EntityManagerFactory::create();
    $post = new Post('Hello', 'World');
    $entityManager->persist($post);
    $entityManager->flush();
    $id = $post->id();

    $bus = new RecordingMessageBus();
    $listener = new EntityChangeListener(new EntityExternalId($entityManager), $bus);

    $listener->preRemove(new PreRemoveEventArgs($post, $entityManager));
    $listener->postFlush(new PostFlushEventArgs($entityManager));

    expect($bus->syncMessages())->toHaveCount(1)
        ->and($bus->syncMessages()[0]->entityId)->toBe($id);
});

it('dispatches every pending message and then starts empty again', function (): void {
    $entityManager = EntityManagerFactory::create();
    $first = new Post('One', 'First');
    $second = new Post('Two', 'Second');
    $entityManager->persist($first);
    $entityManager->persist($second);
    $entityManager->flush();

    $bus = new RecordingMessageBus();
    $listener = new EntityChangeListener(new EntityExternalId($entityManager), $bus);

    $listener->postPersist(new PostPersistEventArgs($first, $entityManager));
    $listener->postPersist(new PostPersistEventArgs($second, $entityManager));
    $listener->postFlush(new PostFlushEventArgs($entityManager));

    expect($bus->syncMessages())->toHaveCount(2);

    $listener->postFlush(new PostFlushEventArgs($entityManager));

    expect($bus->syncMessages())->toHaveCount(2);
});

it('ignores an entity that is not Syncable', function (): void {
    $entityManager = EntityManagerFactory::create();
    $bus = new RecordingMessageBus();
    $listener = new EntityChangeListener(new EntityExternalId($entityManager), $bus);

    $listener->postPersist(new PostPersistEventArgs(new stdClass(), $entityManager));
    $listener->postFlush(new PostFlushEventArgs($entityManager));

    expect($bus->syncMessages())->toBeEmpty();
});

it('dispatches nothing while it is disabled', function (): void {
    $entityManager = EntityManagerFactory::create();
    $post = new Post('Hello', 'World');
    $entityManager->persist($post);
    $entityManager->flush();

    $bus = new RecordingMessageBus();
    $listener = new EntityChangeListener(new EntityExternalId($entityManager), $bus, enabled: false);

    $listener->postPersist(new PostPersistEventArgs($post, $entityManager));
    $listener->postFlush(new PostFlushEventArgs($entityManager));

    expect($bus->syncMessages())->toBeEmpty();
});
