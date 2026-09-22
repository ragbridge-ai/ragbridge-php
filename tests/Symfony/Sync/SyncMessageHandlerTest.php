<?php

declare(strict_types=1);

use Ragbridge\Exception\ServerException;
use Ragbridge\Symfony\Sync\SyncMessage;
use Ragbridge\Symfony\Sync\SyncMessageHandler;
use Ragbridge\Sync\Reconciler;
use Ragbridge\Tests\Support\ClientFactory;
use Ragbridge\Tests\Support\Fixtures;
use Ragbridge\Tests\Support\Payloads;
use Ragbridge\Tests\Symfony\Sync\Support\EntityManagerFactory;
use Ragbridge\Tests\Symfony\Sync\Support\Post;
use Ragbridge\Tests\Symfony\Sync\Support\RestrictedPost;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

it('puts the document of a published entity', function (): void {
    $entityManager = EntityManagerFactory::create();
    $post = new Post('Hello', 'World');
    $entityManager->persist($post);
    $entityManager->flush();

    [$client, $http] = ClientFactory::answering(Payloads::syncResponse(201));
    $handler = new SyncMessageHandler($entityManager, new Reconciler($client));

    $handler(new SyncMessage(Post::class, $post->id(), 'posts:' . $post->id()));

    $request = ClientFactory::lastRequest($http);

    expect($request->getMethod())->toBe('PUT')
        ->and((string) $request->getUri())->toBe('http://localhost:8000/documents/external/posts%3A' . $post->id())
        ->and(json_decode((string) $request->getBody(), true))->toMatchArray(['title' => 'Hello', 'content' => 'World']);
});

it('deletes the document of an unpublished entity', function (): void {
    $entityManager = EntityManagerFactory::create();
    $post = new Post('Hello', 'World', published: false);
    $entityManager->persist($post);
    $entityManager->flush();

    [$client, $http] = ClientFactory::answering(Fixtures::response(204));
    $handler = new SyncMessageHandler($entityManager, new Reconciler($client));

    $handler(new SyncMessage(Post::class, $post->id(), 'posts:' . $post->id()));

    expect(ClientFactory::lastRequest($http)->getMethod())->toBe('DELETE');
});

it('deletes the document of a hard-deleted entity under the id it had', function (): void {
    $entityManager = EntityManagerFactory::create();

    [$client, $http] = ClientFactory::answering(Fixtures::response(204));
    $handler = new SyncMessageHandler($entityManager, new Reconciler($client));

    $handler(new SyncMessage(Post::class, 4831, 'posts:4831'));

    $request = ClientFactory::lastRequest($http);

    expect($request->getMethod())->toBe('DELETE')
        ->and((string) $request->getUri())->toBe('http://localhost:8000/documents/external/posts%3A4831');
});

it('deletes the document of an entity that opts itself out', function (): void {
    $entityManager = EntityManagerFactory::create();
    $post = new RestrictedPost('Hello', 'World', visible: false);
    $entityManager->persist($post);
    $entityManager->flush();

    [$client, $http] = ClientFactory::answering(Fixtures::response(204));
    $handler = new SyncMessageHandler($entityManager, new Reconciler($client));

    $handler(new SyncMessage(RestrictedPost::class, $post->id(), 'restricted_posts:' . $post->id()));

    expect(ClientFactory::lastRequest($http)->getMethod())->toBe('DELETE');
});

it('puts the document of an entity that does not opt itself out', function (): void {
    $entityManager = EntityManagerFactory::create();
    $post = new RestrictedPost('Hello', 'World', visible: true);
    $entityManager->persist($post);
    $entityManager->flush();

    [$client, $http] = ClientFactory::answering(Payloads::syncResponse(201));
    $handler = new SyncMessageHandler($entityManager, new Reconciler($client));

    $handler(new SyncMessage(RestrictedPost::class, $post->id(), 'restricted_posts:' . $post->id()));

    expect(ClientFactory::lastRequest($http)->getMethod())->toBe('PUT');
});

it('reports a validation error as unrecoverable, so Messenger does not retry it', function (): void {
    $entityManager = EntityManagerFactory::create();
    $post = new Post('Hello', 'World');
    $entityManager->persist($post);
    $entityManager->flush();

    [$client] = ClientFactory::answering(Fixtures::response(422, '{"detail":[]}'));
    $handler = new SyncMessageHandler($entityManager, new Reconciler($client));

    expect(fn() => $handler(new SyncMessage(Post::class, $post->id(), 'posts:' . $post->id())))
        ->toThrow(UnrecoverableMessageHandlingException::class);
});

it('lets a transient failure propagate for the transport to retry', function (): void {
    $entityManager = EntityManagerFactory::create();
    $post = new Post('Hello', 'World');
    $entityManager->persist($post);
    $entityManager->flush();

    [$client] = ClientFactory::answering(Fixtures::response(503));
    $handler = new SyncMessageHandler($entityManager, new Reconciler($client));

    expect(fn() => $handler(new SyncMessage(Post::class, $post->id(), 'posts:' . $post->id())))
        ->toThrow(ServerException::class);
});
