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

describe('external ids', function (): void {
    it('reads the fields of a document identified by an external id', function (): void {
        $document = Document::fromArray(Payloads::externalDocument());

        expect($document->externalId)->toBe('article:42')
            ->and($document->filename)->toBe('Refund policy')
            ->and($document->metadata)->toBe(['locale' => 'en'])
            ->and($document->sourceUpdatedAt?->format('Y-m-d\TH:i:s.u'))->toBe('2026-09-21T10:00:00.123456')
            ->and($document->updatedAt?->format('Y-m-d\TH:i:s.u'))->toBe('2026-09-21T10:05:00.500000');
    });

    it('reads an upload as a document without an external id', function (): void {
        $document = Document::fromArray(Payloads::document([
            'external_id' => null,
            'metadata' => [],
            'source_updated_at' => null,
            'updated_at' => '2026-03-14T09:26:53.589793Z',
        ]));

        expect($document->externalId)->toBeNull()
            ->and($document->metadata)->toBe([])
            ->and($document->sourceUpdatedAt)->toBeNull()
            ->and($document->updatedAt)->not->toBeNull();
    });

    it('accepts a response from a service that does not send the fields', function (): void {
        $document = Document::fromArray(Payloads::document());

        expect($document->externalId)->toBeNull()
            ->and($document->metadata)->toBe([])
            ->and($document->sourceUpdatedAt)->toBeNull()
            ->and($document->updatedAt)->toBeNull();
    });

    it('ignores fields it does not know', function (): void {
        $document = Document::fromArray(Payloads::externalDocument(['tags' => ['a'], 'owner' => 'someone']));

        expect($document->externalId)->toBe('article:42');
    });

    it('reads empty metadata, which PHP decodes from {} as an empty array', function (): void {
        $payload = Payloads::fromJson('{"id": "3f2b8c1e-5d4a-4b7e-9c61-0a1b2c3d4e5f", "filename": "a.md", "content_type": "text/markdown", "status": "ready", "error": null, "created_at": "2026-09-21T10:00:00Z", "external_id": null, "metadata": {}, "source_updated_at": null, "updated_at": "2026-09-21T10:00:00Z"}');

        expect($payload['metadata'])->toBe([])
            ->and(Document::fromArray($payload)->metadata)->toBe([]);
    });

    it('keeps nested metadata and keys that look like numbers', function (): void {
        $metadata = ['locale' => 'en', 'tags' => ['a', 'b'], 'author' => ['id' => 7], '1' => 'one'];

        expect(Document::fromArray(Payloads::externalDocument(['metadata' => $metadata]))->metadata)->toBe($metadata);
    });

    it('treats null metadata like empty metadata', function (): void {
        expect(Document::fromArray(Payloads::externalDocument(['metadata' => null]))->metadata)->toBe([]);
    });

    it('treats a timestamp without an offset as UTC', function (): void {
        $document = Document::fromArray(Payloads::externalDocument(['source_updated_at' => '2026-09-21T10:00:00']));

        expect($document->sourceUpdatedAt?->getTimezone()->getName())->toBe('UTC');
    });

    it('rejects fields of the wrong type', function (string $field, mixed $value): void {
        expect(fn() => Document::fromArray(Payloads::externalDocument([$field => $value])))
            ->toThrow(InvalidArgumentException::class, sprintf('Document: field "%s"', $field));
    })->with([
        'external_id as integer' => ['external_id', 42],
        'external_id as array' => ['external_id', ['article:42']],
        'metadata as string' => ['metadata', 'locale=en'],
        'metadata as a non-empty list' => ['metadata', ['en', 'de']],
        'source_updated_at as integer' => ['source_updated_at', 1758448800],
        'source_updated_at not a date' => ['source_updated_at', 'sometime'],
        'updated_at as integer' => ['updated_at', 1758448800],
        'updated_at not a date' => ['updated_at', 'sometime'],
    ]);
});
