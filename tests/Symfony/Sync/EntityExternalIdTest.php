<?php

declare(strict_types=1);

use Ragbridge\Symfony\Sync\EntityExternalId;
use Ragbridge\Tests\Symfony\Sync\Support\EntityManagerFactory;
use Ragbridge\Tests\Symfony\Sync\Support\Post;
use Ragbridge\Tests\Symfony\Sync\Support\TaggedPost;

it('is the table name and the identifier by default', function (): void {
    $entityManager = EntityManagerFactory::create();
    $post = new Post('Hello', 'World');
    $entityManager->persist($post);
    $entityManager->flush();

    $externalId = new EntityExternalId($entityManager);

    expect($externalId->of($post))->toBe('posts:' . $post->id())
        ->and($externalId->key($post))->toBe($post->id());
});

it('lets an entity choose its own id', function (): void {
    $entityManager = EntityManagerFactory::create();
    $post = new TaggedPost('Hello', 'World');
    $entityManager->persist($post);
    $entityManager->flush();

    $externalId = new EntityExternalId($entityManager);

    expect($externalId->of($post))->toBe('tag:' . $post->id());
});
