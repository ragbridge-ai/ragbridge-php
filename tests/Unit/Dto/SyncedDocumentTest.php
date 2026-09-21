<?php

declare(strict_types=1);

use Ragbridge\Dto\Document;
use Ragbridge\Dto\DocumentStatus;
use Ragbridge\Dto\SyncedDocument;
use Ragbridge\Dto\SyncResult;
use Ragbridge\Tests\Support\Payloads;

it('reads the result, the document and keeps the status', function (): void {
    $synced = SyncedDocument::fromArray(Payloads::syncResult('replaced'), 200);

    expect($synced->result)->toBe(SyncResult::Replaced)
        ->and($synced->document)->toBeInstanceOf(Document::class)
        ->and($synced->document->externalId)->toBe('article:42')
        ->and($synced->statusCode)->toBe(200)
        ->and($synced->isQueued())->toBeFalse();
});

it('accepts every documented result', function (string $value, SyncResult $expected): void {
    expect(SyncedDocument::fromArray(Payloads::syncResult($value), 200)->result)->toBe($expected);
})->with([
    'created' => ['created', SyncResult::Created],
    'replaced' => ['replaced', SyncResult::Replaced],
    'updated' => ['updated', SyncResult::Updated],
    'unchanged' => ['unchanged', SyncResult::Unchanged],
    'stale' => ['stale', SyncResult::Stale],
]);

it('is queued only for a 202', function (int $status, bool $queued): void {
    expect(SyncedDocument::fromArray(Payloads::syncResult('created', ['status' => 'pending']), $status)->isQueued())->toBe($queued);
})->with([
    '200' => [200, false],
    '201' => [201, false],
    '202' => [202, true],
]);

it('reports the pending document of a queued save', function (): void {
    $synced = SyncedDocument::fromArray(Payloads::syncResult('replaced', ['status' => 'pending']), 202);

    expect($synced->document->status)->toBe(DocumentStatus::Pending)
        ->and($synced->isQueued())->toBeTrue();
});

it('rejects an unknown result', function (): void {
    expect(fn() => SyncedDocument::fromArray(Payloads::syncResult('merged'), 200))
        ->toThrow(InvalidArgumentException::class, 'SyncedDocument: unknown result "merged"');
});

it('rejects a payload with a missing field', function (string $field): void {
    $payload = Payloads::syncResult();
    unset($payload[$field]);

    expect(fn() => SyncedDocument::fromArray($payload, 200))
        ->toThrow(InvalidArgumentException::class, sprintf('SyncedDocument: missing required field "%s"', $field));
})->with(['result', 'document']);

it('rejects a field of the wrong type', function (string $field, mixed $value): void {
    expect(fn() => SyncedDocument::fromArray([...Payloads::syncResult(), $field => $value], 200))
        ->toThrow(InvalidArgumentException::class, sprintf('SyncedDocument: field "%s"', $field));
})->with([
    'result as integer' => ['result', 1],
    'document as string' => ['document', 'article:42'],
    'document as list' => ['document', [Payloads::externalDocument()]],
    'document as null' => ['document', null],
]);

it('reports an invalid document with its own context', function (): void {
    expect(fn() => SyncedDocument::fromArray(Payloads::syncResult('created', ['status' => 'archived']), 200))
        ->toThrow(InvalidArgumentException::class, 'Document: unknown status "archived"');
});
