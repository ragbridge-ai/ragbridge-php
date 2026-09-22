<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Ragbridge\Laravel\Sync\SyncModelJob;
use Ragbridge\Tests\Laravel\Sync\Support\ModelFactory;
use Ragbridge\Tests\Laravel\Sync\Support\Post;
use Ragbridge\Tests\Laravel\Sync\Support\Schema;
use Ragbridge\Tests\Laravel\Sync\Support\TaggedPost;

beforeEach(function (): void {
    Schema::create();
    Queue::fake();
});

afterEach(function (): void {
    Schema::drop();
});

it('queues a job after the transaction commits when a model is created', function (): void {
    $post = ModelFactory::create(Post::class, ['title' => 'Hello', 'body' => 'World']);
    $key = ModelFactory::key($post);

    Queue::assertPushed(SyncModelJob::class, function (SyncModelJob $job) use ($key): bool {
        return $job->modelClass === Post::class
            && $job->modelKey === $key
            && $job->externalId === 'posts:' . $key;
    });
});

it('queues a job when a model is updated', function (): void {
    $post = ModelFactory::create(Post::class, ['title' => 'Hello', 'body' => 'World']);
    Queue::fake();

    $post->forceFill(['title' => 'Updated'])->save();

    Queue::assertPushed(SyncModelJob::class, 1);
});

it('queues a job when a model is deleted', function (): void {
    $post = ModelFactory::create(Post::class, ['title' => 'Hello', 'body' => 'World']);
    Queue::fake();

    $post->delete();

    Queue::assertPushed(SyncModelJob::class, 1);
});

it('queues a job when a soft-deleted model is restored', function (): void {
    $post = ModelFactory::create(Post::class, ['title' => 'Hello', 'body' => 'World']);
    $post->delete();
    Queue::fake();

    $post->restore();

    Queue::assertPushed(SyncModelJob::class, 1);
});

it('uses the id a model chooses for itself', function (): void {
    $post = ModelFactory::create(TaggedPost::class, ['title' => 'Hello', 'body' => 'World']);
    $key = ModelFactory::key($post);

    Queue::assertPushed(SyncModelJob::class, fn(SyncModelJob $job): bool => $job->externalId === 'tag:' . $key);
});

it('does not queue anything when the sync is disabled', function (): void {
    config(['ragbridge.sync.enabled' => false]);

    ModelFactory::create(Post::class, ['title' => 'Hello', 'body' => 'World']);

    Queue::assertNothingPushed();
});

it('dispatches on the configured connection and queue', function (): void {
    config(['ragbridge.sync.connection' => 'redis', 'ragbridge.sync.queue' => 'ragbridge-sync']);

    ModelFactory::create(Post::class, ['title' => 'Hello', 'body' => 'World']);

    Queue::assertPushedOn('ragbridge-sync', SyncModelJob::class);
});

it('dispatches only after the transaction it was made in commits', function (): void {
    ModelFactory::create(Post::class, ['title' => 'Hello', 'body' => 'World']);

    Queue::assertPushed(SyncModelJob::class, fn(SyncModelJob $job): bool => $job->afterCommit === true);
});
