<?php

declare(strict_types=1);

use Ragbridge\Exception\ServerException;
use Ragbridge\Laravel\Sync\SyncModelJob;
use Ragbridge\Tests\Laravel\Sync\Support\ModelFactory;
use Ragbridge\Tests\Laravel\Sync\Support\Post;
use Ragbridge\Tests\Laravel\Sync\Support\RestrictedPost;
use Ragbridge\Tests\Laravel\Sync\Support\Schema;
use Ragbridge\Tests\Support\FixedClientStrategy;
use Ragbridge\Tests\Support\Fixtures;
use Ragbridge\Tests\Support\Payloads;
use Ragbridge\Tests\Support\RecordingClient;

beforeEach(function (): void {
    Schema::create();
    // Fixtures are created through the trait, which would otherwise dispatch a real job on
    // the default (sync) queue connection, before the test below installs its mock client.
    config([
        'ragbridge.base_url' => 'http://localhost:8000',
        'ragbridge.api_key' => 'secret-key',
        'ragbridge.sync.enabled' => false,
    ]);
});

afterEach(function (): void {
    Schema::drop();
    FixedClientStrategy::reset();
});

/**
 * Runs a job the way a queue worker would, with the container resolving its dependencies.
 */
function handleSyncJob(SyncModelJob $job): void
{
    app()->call([$job, 'handle']);
}

it('puts the document of a published record', function (): void {
    $post = ModelFactory::create(Post::class, ['title' => 'Hello', 'body' => 'World', 'published' => true]);
    $key = ModelFactory::key($post);
    $http = new RecordingClient(Payloads::syncResponse(201));
    FixedClientStrategy::install($http);

    handleSyncJob(new SyncModelJob(Post::class, $key, 'posts:' . $key));

    $request = $http->lastRequest();

    expect($request->getMethod())->toBe('PUT')
        ->and((string) $request->getUri())->toBe('http://localhost:8000/documents/external/posts%3A' . $key)
        ->and(json_decode((string) $request->getBody(), true))->toMatchArray(['title' => 'Hello', 'content' => 'World']);
});

it('deletes the document of an unpublished record', function (): void {
    $post = ModelFactory::create(Post::class, ['title' => 'Hello', 'body' => 'World', 'published' => false]);
    $key = ModelFactory::key($post);
    $http = new RecordingClient(Fixtures::response(204));
    FixedClientStrategy::install($http);

    handleSyncJob(new SyncModelJob(Post::class, $key, 'posts:' . $key));

    expect($http->lastRequest()->getMethod())->toBe('DELETE');
});

it('deletes the document of a soft-deleted record', function (): void {
    $post = ModelFactory::create(Post::class, ['title' => 'Hello', 'body' => 'World']);
    $key = ModelFactory::key($post);
    $post->delete();
    $http = new RecordingClient(Fixtures::response(204));
    FixedClientStrategy::install($http);

    handleSyncJob(new SyncModelJob(Post::class, $key, 'posts:' . $key));

    expect($http->lastRequest()->getMethod())->toBe('DELETE');
});

it('deletes the document of a hard-deleted record under the id it had', function (): void {
    $http = new RecordingClient(Fixtures::response(204));
    FixedClientStrategy::install($http);

    handleSyncJob(new SyncModelJob(Post::class, 4831, 'posts:4831'));

    $request = $http->lastRequest();

    expect($request->getMethod())->toBe('DELETE')
        ->and((string) $request->getUri())->toBe('http://localhost:8000/documents/external/posts%3A4831');
});

it('deletes the document of a record that opts itself out', function (): void {
    $post = ModelFactory::create(RestrictedPost::class, ['title' => 'Hello', 'body' => 'World', 'visible' => false]);
    $key = ModelFactory::key($post);
    $http = new RecordingClient(Fixtures::response(204));
    FixedClientStrategy::install($http);

    handleSyncJob(new SyncModelJob(RestrictedPost::class, $key, 'restricted_posts:' . $key));

    expect($http->lastRequest()->getMethod())->toBe('DELETE');
});

it('puts the document of a record that does not opt itself out', function (): void {
    $post = ModelFactory::create(RestrictedPost::class, ['title' => 'Hello', 'body' => 'World', 'visible' => true]);
    $key = ModelFactory::key($post);
    $http = new RecordingClient(Payloads::syncResponse(201));
    FixedClientStrategy::install($http);

    handleSyncJob(new SyncModelJob(RestrictedPost::class, $key, 'restricted_posts:' . $key));

    expect($http->lastRequest()->getMethod())->toBe('PUT');
});

it('sends the record updated_at when the document has none of its own', function (): void {
    $post = ModelFactory::create(Post::class, ['title' => 'Hello', 'body' => 'World', 'published' => true]);
    $key = ModelFactory::key($post);
    $http = new RecordingClient(Payloads::syncResponse(200, 'updated'));
    FixedClientStrategy::install($http);

    handleSyncJob(new SyncModelJob(Post::class, $key, 'posts:' . $key));

    $decoded = json_decode((string) $http->lastRequest()->getBody(), true);
    $sourceUpdatedAt = is_array($decoded) && is_string($decoded['source_updated_at'] ?? null)
        ? $decoded['source_updated_at']
        : null;
    $updatedAt = $post->getAttribute('updated_at');

    if (! $updatedAt instanceof DateTimeInterface) {
        throw new RuntimeException('Post::updated_at must be set after save().');
    }

    expect($sourceUpdatedAt)->not->toBeNull()
        ->and($sourceUpdatedAt)->toContain($updatedAt->format('Y-m-d'));
});

it('fails the job permanently on a validation error, without retrying', function (): void {
    $post = ModelFactory::create(Post::class, ['title' => 'Hello', 'body' => 'World', 'published' => true]);
    $key = ModelFactory::key($post);
    $http = new RecordingClient(Fixtures::response(422, '{"detail":[]}'));
    FixedClientStrategy::install($http);

    handleSyncJob(new SyncModelJob(Post::class, $key, 'posts:' . $key));
})->throwsNoExceptions();

it('lets a transient failure be retried by the queue', function (): void {
    $post = ModelFactory::create(Post::class, ['title' => 'Hello', 'body' => 'World', 'published' => true]);
    $key = ModelFactory::key($post);
    $http = new RecordingClient(Fixtures::response(503));
    FixedClientStrategy::install($http);

    expect(fn() => handleSyncJob(new SyncModelJob(Post::class, $key, 'posts:' . $key)))
        ->toThrow(ServerException::class);
});
