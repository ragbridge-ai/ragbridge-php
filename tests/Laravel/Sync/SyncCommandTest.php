<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Ragbridge\Laravel\Sync\SyncModelJob;
use Ragbridge\Tests\Laravel\Sync\Support\ModelFactory;
use Ragbridge\Tests\Laravel\Sync\Support\Post;
use Ragbridge\Tests\Laravel\Sync\Support\Schema;

beforeEach(function (): void {
    Schema::create();
    // The trait's own dispatch, from creating the fixtures below, is not what this file
    // tests; only the command's own dispatch is.
    config(['ragbridge.sync.enabled' => false]);
});

afterEach(function (): void {
    Schema::drop();
});

it('queues a job for every record of the model', function (): void {
    $first = ModelFactory::create(Post::class, ['title' => 'One', 'body' => 'First', 'published' => true]);
    $second = ModelFactory::create(Post::class, ['title' => 'Two', 'body' => 'Second', 'published' => false]);
    Queue::fake();

    $exitCode = Artisan::call('ragbridge:sync', ['model' => Post::class]);

    expect($exitCode)->toBe(0);
    Queue::assertPushed(SyncModelJob::class, 2);
    Queue::assertPushed(
        SyncModelJob::class,
        fn(SyncModelJob $job): bool => $job->modelKey === ModelFactory::key($first) && $job->externalId === 'posts:' . ModelFactory::key($first),
    );
    Queue::assertPushed(
        SyncModelJob::class,
        fn(SyncModelJob $job): bool => $job->modelKey === ModelFactory::key($second) && $job->externalId === 'posts:' . ModelFactory::key($second),
    );
});

it('respects the chunk size without changing what is queued', function (): void {
    ModelFactory::create(Post::class, ['title' => 'One', 'body' => 'First']);
    ModelFactory::create(Post::class, ['title' => 'Two', 'body' => 'Second']);
    ModelFactory::create(Post::class, ['title' => 'Three', 'body' => 'Third']);
    Queue::fake();

    $exitCode = Artisan::call('ragbridge:sync', ['model' => Post::class, '--chunk' => 1]);

    expect($exitCode)->toBe(0);
    Queue::assertPushed(SyncModelJob::class, 3);
});

it('queues nothing for a model with no records', function (): void {
    Queue::fake();

    $exitCode = Artisan::call('ragbridge:sync', ['model' => Post::class]);

    expect($exitCode)->toBe(0);
    Queue::assertNothingPushed();
});

it('fails without queuing anything for a class that is not a syncable Eloquent model', function (): void {
    Queue::fake();

    $exitCode = Artisan::call('ragbridge:sync', ['model' => Schema::class]);

    expect($exitCode)->toBe(1);
    Queue::assertNothingPushed();
});

it('fails for a class that does not exist', function (): void {
    Queue::fake();

    $exitCode = Artisan::call('ragbridge:sync', ['model' => 'Not\\A\\Real\\Class']);

    expect($exitCode)->toBe(1);
    Queue::assertNothingPushed();
});
