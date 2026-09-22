<?php

declare(strict_types=1);

use Ragbridge\Symfony\Sync\EntityExternalId;
use Ragbridge\Symfony\Sync\SyncCommand;
use Ragbridge\Tests\Symfony\Sync\Support\EntityManagerFactory;
use Ragbridge\Tests\Symfony\Sync\Support\Post;
use Ragbridge\Tests\Symfony\Sync\Support\RecordingMessageBus;
use Symfony\Component\Console\Tester\CommandTester;

it('queues a message for every record of the entity', function (): void {
    $entityManager = EntityManagerFactory::create();
    $first = new Post('One', 'First', published: true);
    $second = new Post('Two', 'Second', published: false);
    $entityManager->persist($first);
    $entityManager->persist($second);
    $entityManager->flush();

    $bus = new RecordingMessageBus();
    $tester = new CommandTester(new SyncCommand($entityManager, new EntityExternalId($entityManager), $bus));

    $exitCode = $tester->execute(['entity' => Post::class]);

    expect($exitCode)->toBe(0)
        ->and($bus->syncMessages())->toHaveCount(2);
    expect($bus->syncMessages()[0]->externalId)->toBe('posts:' . $first->id());
    expect($bus->syncMessages()[1]->externalId)->toBe('posts:' . $second->id());
});

it('respects the chunk size without changing what is queued', function (): void {
    $entityManager = EntityManagerFactory::create();
    foreach (['One', 'Two', 'Three'] as $title) {
        $entityManager->persist(new Post($title, 'Body'));
    }

    $entityManager->flush();

    $bus = new RecordingMessageBus();
    $tester = new CommandTester(new SyncCommand($entityManager, new EntityExternalId($entityManager), $bus));

    $exitCode = $tester->execute(['entity' => Post::class, '--chunk' => '1']);

    expect($exitCode)->toBe(0)
        ->and($bus->syncMessages())->toHaveCount(3);
});

it('queues nothing for an entity with no records', function (): void {
    $entityManager = EntityManagerFactory::create();
    $bus = new RecordingMessageBus();
    $tester = new CommandTester(new SyncCommand($entityManager, new EntityExternalId($entityManager), $bus));

    $exitCode = $tester->execute(['entity' => Post::class]);

    expect($exitCode)->toBe(0)
        ->and($bus->syncMessages())->toBeEmpty();
});

it('fails without queuing anything for a class that is not a syncable entity', function (): void {
    $entityManager = EntityManagerFactory::create();
    $bus = new RecordingMessageBus();
    $tester = new CommandTester(new SyncCommand($entityManager, new EntityExternalId($entityManager), $bus));

    $exitCode = $tester->execute(['entity' => stdClass::class]);

    expect($exitCode)->toBe(1)
        ->and($bus->syncMessages())->toBeEmpty();
});

it('fails for a class that does not exist', function (): void {
    $entityManager = EntityManagerFactory::create();
    $bus = new RecordingMessageBus();
    $tester = new CommandTester(new SyncCommand($entityManager, new EntityExternalId($entityManager), $bus));

    $exitCode = $tester->execute(['entity' => 'Not\\A\\Real\\Class']);

    expect($exitCode)->toBe(1)
        ->and($bus->syncMessages())->toBeEmpty();
});
