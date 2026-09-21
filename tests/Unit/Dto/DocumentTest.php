<?php

declare(strict_types=1);

use Ragbridge\Dto\Document;
use Ragbridge\Dto\DocumentStatus;
use Ragbridge\Tests\Support\Payloads;

it('builds a document from a valid payload', function (): void {
    $document = Document::fromArray(Payloads::document());

    expect($document->id)->toBe(Payloads::DOCUMENT_ID)
        ->and($document->filename)->toBe('handbook.pdf')
        ->and($document->contentType)->toBe('application/pdf')
        ->and($document->status)->toBe(DocumentStatus::Ready)
        ->and($document->error)->toBeNull()
        ->and($document->createdAt->format('Y-m-d\TH:i:s'))->toBe('2026-03-14T09:26:53');
});

it('keeps the error message of a failed document', function (): void {
    $document = Document::fromArray(Payloads::document(['status' => 'failed', 'error' => 'unsupported file type']));

    expect($document->status)->toBe(DocumentStatus::Failed)
        ->and($document->error)->toBe('unsupported file type');
});

it('treats a timestamp without an offset as UTC', function (): void {
    $document = Document::fromArray(Payloads::document(['created_at' => '2026-03-14T09:26:53']));

    expect($document->createdAt->getTimezone()->getName())->toBe('UTC');
});

it('accepts every documented status', function (string $status, DocumentStatus $expected): void {
    expect(Document::fromArray(Payloads::document(['status' => $status]))->status)->toBe($expected);
})->with([
    'pending' => ['pending', DocumentStatus::Pending],
    'processing' => ['processing', DocumentStatus::Processing],
    'ready' => ['ready', DocumentStatus::Ready],
    'failed' => ['failed', DocumentStatus::Failed],
]);

it('rejects a payload with a missing field', function (string $field): void {
    $payload = Payloads::document();
    unset($payload[$field]);

    expect(fn() => Document::fromArray($payload))
        ->toThrow(InvalidArgumentException::class, sprintf('Document: missing required field "%s"', $field));
})->with(['id', 'filename', 'content_type', 'status', 'created_at']);

it('rejects a payload with a field of the wrong type', function (string $field, mixed $value): void {
    expect(fn() => Document::fromArray(Payloads::document([$field => $value])))
        ->toThrow(InvalidArgumentException::class, sprintf('Document: field "%s"', $field));
})->with([
    'id as integer' => ['id', 42],
    'id not a uuid' => ['id', 'not-a-uuid'],
    'filename as null' => ['filename', null],
    'filename as array' => ['filename', ['handbook.pdf']],
    'content_type as integer' => ['content_type', 1],
    'status as integer' => ['status', 1],
    'error as integer' => ['error', 500],
    'created_at as integer' => ['created_at', 1710408413],
    'created_at not a date' => ['created_at', 'yesterday-ish?'],
]);

it('rejects an unknown status', function (): void {
    expect(fn() => Document::fromArray(Payloads::document(['status' => 'archived'])))
        ->toThrow(InvalidArgumentException::class, 'Document: unknown status "archived"');
});
