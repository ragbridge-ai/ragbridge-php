<?php

declare(strict_types=1);

use Ragbridge\Dto\Document;
use Ragbridge\Dto\DocumentStatus;
use Ragbridge\Exception\AuthenticationException;
use Ragbridge\Exception\InvalidResponseException;
use Ragbridge\Exception\NotFoundException;
use Ragbridge\Exception\RequestFailedException;
use Ragbridge\RagbridgeClient;
use Ragbridge\Tests\Support\Fixtures;
use Ragbridge\Tests\Support\RecordingClient;
use Ragbridge\Tests\Support\Thrown;

function documentClient(RecordingClient $http): RagbridgeClient
{
    $factory = Fixtures::factory();

    return new RagbridgeClient($http, $factory, $factory, 'http://localhost:8000', 'secret-key');
}

/**
 * Writes a temporary file that is removed at the end of the test run.
 */
function temporaryFile(string $name, string $contents): string
{
    $directory = sys_get_temp_dir() . '/ragbridge-tests-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $path = $directory . '/' . $name;
    file_put_contents($path, $contents);

    register_shutdown_function(static function () use ($path, $directory): void {
        @unlink($path);
        @rmdir($directory);
    });

    return $path;
}

function boundaryFrom(RecordingClient $http): string
{
    preg_match('/^multipart\/form-data; boundary=(ragbridge-[0-9a-f]{32})$/', $http->lastRequest()->getHeaderLine('Content-Type'), $matches);

    return $matches[1] ?? '';
}

describe('upload', function (): void {
    it('streams the file as multipart form data and returns the document', function (): void {
        $http = new RecordingClient(Fixtures::jsonResponse(201, 'document'));
        $path = temporaryFile('handbook.pdf', '%PDF-1.7 fake content');

        $document = documentClient($http)->upload($path);

        $boundary = boundaryFrom($http);
        $request = $http->lastRequest();

        expect($request->getMethod())->toBe('POST')
            ->and((string) $request->getUri())->toBe('http://localhost:8000/documents')
            ->and($request->getHeaderLine('Authorization'))->toBe('Bearer secret-key')
            ->and($request->getHeaderLine('Accept'))->toBe('application/json')
            ->and($boundary)->not->toBe('')
            ->and($http->lastBody())->toBe(
                "--{$boundary}\r\n"
                . "Content-Disposition: form-data; name=\"file\"; filename=\"handbook.pdf\"\r\n"
                . "Content-Type: application/pdf\r\n"
                . "\r\n"
                . "%PDF-1.7 fake content\r\n"
                . "--{$boundary}--\r\n",
            )
            ->and($document)->toBeInstanceOf(Document::class)
            ->and($document->filename)->toBe('handbook.pdf')
            ->and($document->status)->toBe(DocumentStatus::Ready);
    });

    it('returns a pending document for an upload processed asynchronously', function (): void {
        $http = new RecordingClient(Fixtures::jsonResponse(202, 'document_pending'));

        $document = documentClient($http)->upload(temporaryFile('annual-report.pdf', 'x'));

        expect($document->status)->toBe(DocumentStatus::Pending);
    });

    it('accepts the duplicate response of the service', function (): void {
        $http = new RecordingClient(Fixtures::jsonResponse(200, 'document'));

        expect(documentClient($http)->upload(temporaryFile('handbook.pdf', 'x'))->id)
            ->toBe('3f2b8c1e-5d4a-4b7e-9c61-0a1b2c3d4e5f');
    });

    it('guesses the content type from the extension', function (string $name, string $expected): void {
        $http = new RecordingClient(Fixtures::jsonResponse(201, 'document'));

        documentClient($http)->upload(temporaryFile($name, 'x'));

        expect($http->lastBody())->toContain("Content-Type: {$expected}\r\n");
    })->with([
        'pdf' => ['a.pdf', 'application/pdf'],
        'uppercase pdf' => ['A.PDF', 'application/pdf'],
        'markdown' => ['a.md', 'text/markdown'],
        'long markdown' => ['a.markdown', 'text/markdown'],
        'text' => ['a.txt', 'text/plain'],
        'unknown' => ['a.docx', 'application/octet-stream'],
        'no extension' => ['README', 'application/octet-stream'],
    ]);

    it('lets the caller choose the stored name and content type', function (): void {
        $http = new RecordingClient(Fixtures::jsonResponse(201, 'document'));

        documentClient($http)->upload(temporaryFile('tmp-123', 'x'), 'policy.md', 'text/markdown');

        expect($http->lastBody())
            ->toContain('filename="policy.md"')
            ->toContain("Content-Type: text/markdown\r\n");
    });

    it('rejects a file that does not exist without sending a request', function (): void {
        $http = new RecordingClient();

        expect(fn() => documentClient($http)->upload('/nonexistent/file.pdf'))
            ->toThrow(InvalidArgumentException::class, 'does not exist or is not readable')
            ->and($http->requests)->toBe([]);
    });

    it('rejects a directory', function (): void {
        expect(fn() => documentClient(new RecordingClient())->upload(sys_get_temp_dir()))
            ->toThrow(InvalidArgumentException::class);
    });

    it('maps the error statuses of the service', function (int $status, string $exception): void {
        $http = new RecordingClient(Fixtures::response($status, '{"detail": "rejected"}'));

        expect(fn() => documentClient($http)->upload(temporaryFile('a.pdf', 'x')))->toThrow($exception);
    })->with([
        'unsupported media type' => [415, RequestFailedException::class],
        'too large' => [413, RequestFailedException::class],
        'unauthorized' => [401, AuthenticationException::class],
    ]);

    it('rejects an upload response that is not a document', function (): void {
        $http = new RecordingClient(Fixtures::response(201, '{"id": "nope"}'));

        expect(fn() => documentClient($http)->upload(temporaryFile('a.pdf', 'x')))
            ->toThrow(InvalidResponseException::class, 'Document:');
    });
});

describe('uploadStream', function (): void {
    it('uploads the content of a stream under the given name', function (): void {
        $http = new RecordingClient(Fixtures::jsonResponse(201, 'document'));
        $stream = Fixtures::factory()->createStream('in-memory notes');

        documentClient($http)->uploadStream($stream, 'notes.txt');

        expect($http->lastBody())
            ->toContain('filename="notes.txt"')
            ->toContain("Content-Type: text/plain\r\n")
            ->toContain("\r\n\r\nin-memory notes\r\n--");
    });

    it('leaves the stream open for the caller', function (): void {
        $http = new RecordingClient(Fixtures::jsonResponse(201, 'document'));
        $stream = Fixtures::factory()->createStream('data');

        documentClient($http)->uploadStream($stream, 'a.txt');

        expect($stream->getSize())->toBe(4);
    });
});

describe('documents', function (): void {
    it('lists the documents', function (): void {
        $http = new RecordingClient(Fixtures::jsonResponse(200, 'documents'));

        $documents = documentClient($http)->documents();

        $request = $http->lastRequest();

        expect($request->getMethod())->toBe('GET')
            ->and((string) $request->getUri())->toBe('http://localhost:8000/documents')
            ->and($request->getHeaderLine('Content-Type'))->toBe('')
            ->and($http->lastBody())->toBe('')
            ->and($documents)->toHaveCount(2)
            ->and($documents[0]->filename)->toBe('handbook.pdf')
            ->and($documents[1]->status)->toBe(DocumentStatus::Failed)
            ->and($documents[1]->error)->toBe('no extractable text');
    });

    it('returns an empty list when there are no documents', function (): void {
        $http = new RecordingClient(Fixtures::response(200, '[]'));

        expect(documentClient($http)->documents())->toBe([]);
    });

    it('rejects a response that is not a list', function (string $body, string $message): void {
        $http = new RecordingClient(Fixtures::response(200, $body));

        expect(fn() => documentClient($http)->documents())
            ->toThrow(InvalidResponseException::class, $message);
    })->with([
        'object' => ['{"documents": []}', 'expected a JSON list, got array'],
        'empty body' => ['', 'expected a JSON list, got null'],
        'scalar item' => ['["a"]', 'expected item 0 to be a JSON object, got string'],
        'invalid item' => ['[{"id": 1}]', 'Document:'],
    ]);

    it('reports an authentication failure', function (): void {
        $http = new RecordingClient(Fixtures::jsonResponse(401, 'error_unauthorized'));

        expect(fn() => documentClient($http)->documents())->toThrow(AuthenticationException::class);
    });
});

describe('document', function (): void {
    it('fetches one document by id', function (): void {
        $http = new RecordingClient(Fixtures::jsonResponse(200, 'document_pending'));

        $document = documentClient($http)->document('9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d');

        expect($http->lastRequest()->getMethod())->toBe('GET')
            ->and((string) $http->lastRequest()->getUri())
            ->toBe('http://localhost:8000/documents/9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d')
            ->and($document->status)->toBe(DocumentStatus::Pending);
    });

    it('encodes the id so it cannot change the path', function (): void {
        $http = new RecordingClient(Fixtures::jsonResponse(404, 'error_not_found'));

        Thrown::by(fn() => documentClient($http)->document('../health?x=1'), NotFoundException::class);

        expect((string) $http->lastRequest()->getUri())->toBe('http://localhost:8000/documents/..%2Fhealth%3Fx%3D1');
    });

    it('throws NotFoundException for an unknown document', function (): void {
        $http = new RecordingClient(Fixtures::jsonResponse(404, 'error_not_found'));

        $e = Thrown::by(fn() => documentClient($http)->document('3f2b8c1e-5d4a-4b7e-9c61-0a1b2c3d4e5f'), NotFoundException::class);

        expect($e->statusCode())->toBe(404)
            ->and($e->getMessage())->toBe('ragbridge service responded with HTTP 404: document not found');
    });
});

describe('deleteDocument', function (): void {
    it('deletes a document', function (): void {
        $http = new RecordingClient(Fixtures::response(204));

        documentClient($http)->deleteDocument('3f2b8c1e-5d4a-4b7e-9c61-0a1b2c3d4e5f');

        expect($http->lastRequest()->getMethod())->toBe('DELETE')
            ->and((string) $http->lastRequest()->getUri())
            ->toBe('http://localhost:8000/documents/3f2b8c1e-5d4a-4b7e-9c61-0a1b2c3d4e5f')
            ->and($http->lastRequest()->getHeaderLine('Authorization'))->toBe('Bearer secret-key');
    });

    it('throws NotFoundException for an unknown document', function (): void {
        $http = new RecordingClient(Fixtures::jsonResponse(404, 'error_not_found'));

        $e = Thrown::by(fn() => documentClient($http)->deleteDocument('3f2b8c1e-5d4a-4b7e-9c61-0a1b2c3d4e5f'), NotFoundException::class);

        expect($e->statusCode())->toBe(404)
            ->and($e->body())->toBe(['detail' => 'document not found']);
    });

    it('reports an authentication failure', function (): void {
        $http = new RecordingClient(Fixtures::jsonResponse(401, 'error_unauthorized'));

        expect(fn() => documentClient($http)->deleteDocument('3f2b8c1e-5d4a-4b7e-9c61-0a1b2c3d4e5f'))
            ->toThrow(AuthenticationException::class);
    });
});
