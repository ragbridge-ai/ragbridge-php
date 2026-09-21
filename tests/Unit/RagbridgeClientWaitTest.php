<?php

declare(strict_types=1);

use Ragbridge\Dto\Document;
use Ragbridge\Dto\DocumentStatus;
use Ragbridge\Exception\NotFoundException;
use Ragbridge\Exception\ProcessingTimeoutException;
use Ragbridge\Exception\RagbridgeException;
use Ragbridge\Tests\Support\ClientFactory;
use Ragbridge\Tests\Support\Fixtures;
use Ragbridge\Tests\Support\Payloads;
use Ragbridge\Tests\Support\Thrown;

/**
 * A response with the document in the given status.
 */
function documentWithStatus(string $status, ?string $error = null): Psr\Http\Message\ResponseInterface
{
    return Fixtures::response(200, json_encode(Payloads::externalDocument(['status' => $status, 'error' => $error]), JSON_THROW_ON_ERROR));
}

function pendingDocument(string $status = 'pending'): Document
{
    return Document::fromArray(Payloads::externalDocument(['status' => $status]));
}

describe('waitUntilProcessed', function (): void {
    it('returns a document that is ready without asking or waiting', function (string $status, DocumentStatus $expected): void {
        [$client, $http, $sleeps] = ClientFactory::retrying(null);
        $document = Document::fromArray(Payloads::externalDocument(['status' => $status]));

        expect($client->waitUntilProcessed($document))->toBe($document)
            ->and($document->status)->toBe($expected)
            ->and($http->requests)->toBe([])
            ->and($sleeps)->toHaveCount(0);
    })->with([
        'ready' => ['ready', DocumentStatus::Ready],
        'failed' => ['failed', DocumentStatus::Failed],
    ]);

    it('checks again after each pause until the document is ready', function (): void {
        [$client, $http, $sleeps] = ClientFactory::retrying(
            null,
            documentWithStatus('pending'),
            documentWithStatus('processing'),
            documentWithStatus('ready'),
        );

        $document = $client->waitUntilProcessed(pendingDocument());

        expect($document->status)->toBe(DocumentStatus::Ready)
            ->and($document->externalId)->toBe('article:42')
            ->and($http->requests)->toHaveCount(3)
            ->and($sleeps->getArrayCopy())->toBe([1000, 1000, 1000]);
    });

    it('fetches the document by its id', function (): void {
        [$client, $http] = ClientFactory::retrying(null, documentWithStatus('ready'));

        $client->waitUntilProcessed(pendingDocument());

        expect($http->requests[0]->getMethod())->toBe('GET')
            ->and((string) $http->requests[0]->getUri())->toBe('http://localhost:8000/documents/' . Payloads::DOCUMENT_ID)
            ->and($http->requests[0]->getHeaderLine('Authorization'))->toBe('Bearer secret-key');
    });

    it('returns a document that failed, with its error, instead of throwing', function (): void {
        [$client] = ClientFactory::retrying(null, documentWithStatus('processing'), documentWithStatus('failed', 'embedding service unavailable'));

        $document = $client->waitUntilProcessed(pendingDocument());

        expect($document->status)->toBe(DocumentStatus::Failed)
            ->and($document->error)->toBe('embedding service unavailable');
    });

    it('waits at the interval it is given', function (): void {
        [$client, , $sleeps] = ClientFactory::retrying(null, documentWithStatus('pending'), documentWithStatus('ready'));

        $client->waitUntilProcessed(pendingDocument(), intervalMs: 250);

        expect($sleeps->getArrayCopy())->toBe([250, 250]);
    });

    it('gives up when the timeout has passed and reports the document as it was last seen', function (): void {
        [$client, $http, $sleeps] = ClientFactory::retrying(
            null,
            documentWithStatus('pending'),
            documentWithStatus('processing'),
            documentWithStatus('processing'),
            documentWithStatus('ready'),
        );

        $e = Thrown::by(fn() => $client->waitUntilProcessed(pendingDocument(), timeoutSeconds: 3), ProcessingTimeoutException::class);

        expect($e->document->status)->toBe(DocumentStatus::Processing)
            ->and($e->getMessage())->toBe(sprintf('Document %s was still processing after waiting 3 seconds.', Payloads::DOCUMENT_ID))
            ->and($e)->toBeInstanceOf(RagbridgeException::class)
            ->and($http->requests)->toHaveCount(3)
            ->and($sleeps)->toHaveCount(3);
    });

    it('does not wait or ask again when the timeout is zero', function (): void {
        [$client, $http, $sleeps] = ClientFactory::retrying(null, documentWithStatus('ready'));

        expect(fn() => $client->waitUntilProcessed(pendingDocument(), timeoutSeconds: 0))
            ->toThrow(ProcessingTimeoutException::class)
            ->and($http->requests)->toBe([])
            ->and($sleeps)->toHaveCount(0);
    });

    it('stops when the interval is longer than the time that is left', function (): void {
        [$client, $http, $sleeps] = ClientFactory::retrying(null, documentWithStatus('pending'), documentWithStatus('pending'));

        expect(fn() => $client->waitUntilProcessed(pendingDocument(), timeoutSeconds: 1, intervalMs: 600))
            ->toThrow(ProcessingTimeoutException::class)
            ->and($sleeps->getArrayCopy())->toBe([600, 600])
            ->and($http->requests)->toHaveCount(2);
    });

    it('reports a document that is deleted while it is waited for', function (): void {
        [$client] = ClientFactory::retrying(null, Fixtures::response(404, '{"detail":"document not found"}'));

        expect(fn() => $client->waitUntilProcessed(pendingDocument()))->toThrow(NotFoundException::class);
    });

    it('rejects a timeout or an interval that makes no sense, without asking', function (int $timeout, int $interval): void {
        [$client, $http] = ClientFactory::retrying(null);

        expect(fn() => $client->waitUntilProcessed(pendingDocument(), $timeout, $interval))
            ->toThrow(InvalidArgumentException::class)
            ->and($http->requests)->toBe([]);
    })->with([
        'a negative timeout' => [-1, 1000],
        'an interval of zero' => [60, 0],
        'a negative interval' => [60, -5],
    ]);
});
