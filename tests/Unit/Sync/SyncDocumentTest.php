<?php

declare(strict_types=1);

use Ragbridge\Sync\SyncDocument;

it('replaces only the time when asked', function (): void {
    $document = new SyncDocument('Title', 'Content', ['k' => 'v']);
    $updatedAt = new DateTimeImmutable('2026-09-22T10:00:00+00:00');

    $withTime = $document->withSourceUpdatedAt($updatedAt);

    expect($withTime->title)->toBe('Title')
        ->and($withTime->content)->toBe('Content')
        ->and($withTime->metadata)->toBe(['k' => 'v'])
        ->and($withTime->sourceUpdatedAt)->toBe($updatedAt)
        ->and($document->sourceUpdatedAt)->toBeNull();
});
