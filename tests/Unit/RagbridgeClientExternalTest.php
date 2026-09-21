<?php

declare(strict_types=1);

use Ragbridge\Dto\DocumentStatus;
use Ragbridge\Dto\SyncResult;
use Ragbridge\Exception\ConflictException;
use Ragbridge\Exception\InvalidResponseException;
use Ragbridge\Exception\NotFoundException;
use Ragbridge\Exception\ServerException;
use Ragbridge\Exception\ServiceUnavailableException;
use Ragbridge\Exception\ValidationException;
use Ragbridge\RagbridgeClient;
use Ragbridge\RetryPolicy;
use Ragbridge\Tests\Support\ClientFactory;
use Ragbridge\Tests\Support\Fixtures;
use Ragbridge\Tests\Support\Payloads;
use Ragbridge\Tests\Support\Thrown;

/**
 * The JSON body of the last request, as text.
 */
function lastBodyText(Http\Mock\Client $http): string
{
    return (string) ClientFactory::lastRequest($http)->getBody();
}

describe('putDocument', function (): void {
    it('puts the record as JSON to the external id', function (): void {
        [$client, $http] = ClientFactory::answering(Payloads::syncResponse(201));

        $client->putDocument(
            'article:42',
            'Refund policy',
            'Refunds are possible within 14 days.',
            ['locale' => 'en'],
            new DateTimeImmutable('2026-09-21T10:00:00.123456+00:00'),
        );

        $request = ClientFactory::lastRequest($http);

        expect($request->getMethod())->toBe('PUT')
            ->and((string) $request->getUri())->toBe('http://localhost:8000/documents/external/article%3A42')
            ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
            ->and($request->getHeaderLine('Accept'))->toBe('application/json')
            ->and($request->getHeaderLine('Authorization'))->toBe('Bearer secret-key')
            ->and(json_decode((string) $request->getBody(), true))->toBe([
                'title' => 'Refund policy',
                'content' => 'Refunds are possible within 14 days.',
                'metadata' => ['locale' => 'en'],
                'source_updated_at' => '2026-09-21T10:00:00.123456+00:00',
            ]);
    });

    it('returns what the service did and the document', function (string $result, int $status, SyncResult $expected): void {
        [$client] = ClientFactory::answering(Payloads::syncResponse($status, $result));

        $synced = $client->putDocument('article:42', 'T', 'text');

        expect($synced->result)->toBe($expected)
            ->and($synced->statusCode)->toBe($status)
            ->and($synced->document->externalId)->toBe('article:42')
            ->and($synced->document->metadata)->toBe(['locale' => 'en'])
            ->and($synced->isQueued())->toBeFalse();
    })->with([
        'created' => ['created', 201, SyncResult::Created],
        'replaced' => ['replaced', 200, SyncResult::Replaced],
        'updated' => ['updated', 200, SyncResult::Updated],
        'unchanged' => ['unchanged', 200, SyncResult::Unchanged],
        'stale' => ['stale', 200, SyncResult::Stale],
    ]);

    it('reports a save that is processed by a worker as queued', function (): void {
        [$client] = ClientFactory::answering(Payloads::syncResponse(202, 'created', ['status' => 'pending']));

        $synced = $client->putDocument('article:42', 'T', str_repeat('x ', 1000));

        expect($synced->isQueued())->toBeTrue()
            ->and($synced->statusCode)->toBe(202)
            ->and($synced->result)->toBe(SyncResult::Created)
            ->and($synced->document->status)->toBe(DocumentStatus::Pending);
    });

    describe('metadata', function (): void {
        it('is left out when it is empty, and never sent as an empty JSON list', function (Closure $call): void {
            [$client, $http] = ClientFactory::answering(Payloads::syncResponse(200));

            $call($client);

            expect(lastBodyText($http))->not->toContain('metadata')
                ->and(lastBodyText($http))->not->toContain('[]');
        })->with([
            'not given' => [fn(RagbridgeClient $client) => $client->putDocument('article:42', 'T', 'text')],
            'an empty array' => [fn(RagbridgeClient $client) => $client->putDocument('article:42', 'T', 'text', [])],
        ]);

        it('is sent as a JSON object', function (): void {
            [$client, $http] = ClientFactory::answering(Payloads::syncResponse(200));

            $client->putDocument('article:42', 'T', 'text', ['locale' => 'en', 'tags' => ['a', 'b'], 'author' => ['id' => 7]]);

            expect(lastBodyText($http))->toContain('"metadata":{"locale":"en","tags":["a","b"],"author":{"id":7}}');
        });

        it('keeps an empty object inside the metadata as an object', function (): void {
            [$client, $http] = ClientFactory::answering(Payloads::syncResponse(200));

            $client->putDocument('article:42', 'T', 'text', ['filters' => new stdClass()]);

            expect(lastBodyText($http))->toContain('"metadata":{"filters":{}}');
        });

        it('is sent as an object when its keys are numbers that are not a sequence', function (): void {
            [$client, $http] = ClientFactory::answering(Payloads::syncResponse(200));

            $client->putDocument('article:42', 'T', 'text', [5 => 'five', 7 => 'seven']);

            expect(lastBodyText($http))->toContain('"metadata":{"5":"five","7":"seven"}');
        });

        it('is rejected when it is a list, because that is a JSON array', function (): void {
            [$client, $http] = ClientFactory::answering(Payloads::syncResponse(200));

            expect(fn() => $client->putDocument('article:42', 'T', 'text', ['en', 'de']))
                ->toThrow(InvalidArgumentException::class, 'metadata must be a map')
                ->and($http->getRequests())->toBe([]);
        });
    });

    describe('source_updated_at', function (): void {
        it('is left out when it is not given', function (): void {
            [$client, $http] = ClientFactory::answering(Payloads::syncResponse(200));

            $client->putDocument('article:42', 'T', 'text');

            expect(lastBodyText($http))->not->toContain('source_updated_at');
        });

        it('carries its time zone and its microseconds', function (DateTimeInterface $time, string $expected): void {
            [$client, $http] = ClientFactory::answering(Payloads::syncResponse(200));

            $client->putDocument('article:42', 'T', 'text', sourceUpdatedAt: $time);

            expect(Payloads::fromJson(lastBodyText($http))['source_updated_at'] ?? null)->toBe($expected);
        })->with([
            'UTC' => [new DateTimeImmutable('2026-09-21T10:00:00Z'), '2026-09-21T10:00:00.000000+00:00'],
            'an offset' => [new DateTimeImmutable('2026-09-21T12:00:00+02:00'), '2026-09-21T12:00:00.000000+02:00'],
            'microseconds' => [new DateTimeImmutable('2026-09-21T10:00:00.123456+00:00'), '2026-09-21T10:00:00.123456+00:00'],
            'a mutable date' => [new DateTime('2026-09-21T10:00:00+05:30'), '2026-09-21T10:00:00.000000+05:30'],
            'a named time zone' => [new DateTimeImmutable('2026-09-21 10:00:00', new DateTimeZone('Europe/Berlin')), '2026-09-21T10:00:00.000000+02:00'],
        ]);
    });

    describe('the external id', function (): void {
        it('is encoded in the path', function (string $id, string $encoded): void {
            [$client, $http] = ClientFactory::answering(Payloads::syncResponse(200));

            $client->putDocument($id, 'T', 'text');

            expect((string) ClientFactory::lastRequest($http)->getUri())->toBe('http://localhost:8000/documents/external/' . $encoded);
        })->with([
            'with a colon' => ['article:42', 'article%3A42'],
            'with a dot and an underscore' => ['wp_posts.42', 'wp_posts.42'],
            'with an at sign' => ['user@example', 'user%40example'],
            'with a dash' => ['shop-de:product:9f1c', 'shop-de%3Aproduct%3A9f1c'],
            'with a space, which the service will reject' => ['a b', 'a%20b'],
            'with a percent sign' => ['100%', '100%25'],
            'with a non-ASCII letter' => ['café', 'caf%C3%A9'],
        ]);

        it('is refused when it cannot be sent as a path, without sending anything', function (string $id): void {
            [$client, $http] = ClientFactory::answering(Payloads::syncResponse(200));

            expect(fn() => $client->putDocument($id, 'T', 'text'))
                ->toThrow(InvalidArgumentException::class, 'external id')
                ->and($http->getRequests())->toBe([]);
        })->with([
            'empty' => [''],
            'a slash' => ['a/b'],
            'a leading slash' => ['/a'],
            'a dot' => ['.'],
            'two dots' => ['..'],
        ]);

        it('is otherwise left to the service, which reports what is wrong', function (): void {
            [$client, $http] = ClientFactory::answering(Fixtures::response(422, json_encode([
                'detail' => [['type' => 'string_pattern_mismatch', 'loc' => ['path', 'external_id'], 'msg' => 'String should match pattern', 'input' => '-bad']],
            ], JSON_THROW_ON_ERROR)));

            $e = Thrown::by(fn() => $client->putDocument('-bad', 'T', 'text'), ValidationException::class);

            expect($e->statusCode())->toBe(422)
                ->and($e->errors()[0]['loc'])->toBe(['path', 'external_id'])
                ->and($http->getRequests())->toHaveCount(1);
        });
    });

    describe('failures', function (): void {
        it('tells a validation error, a conflict and an unavailable service apart', function (): void {
            $failing = static fn(int $status): RagbridgeClient => ClientFactory::answering(Fixtures::response($status, '{"detail":"x"}'))[0];

            $validation = Thrown::by(fn() => $failing(422)->putDocument('article:42', 'T', 'text'), ValidationException::class);
            $conflict = Thrown::by(fn() => $failing(409)->putDocument('article:42', 'T', 'text'), ConflictException::class);
            $unavailable = Thrown::by(fn() => $failing(503)->putDocument('article:42', 'T', 'text'), ServiceUnavailableException::class);
            $server = Thrown::by(fn() => $failing(500)->putDocument('article:42', 'T', 'text'), ServerException::class);

            expect([$validation->statusCode(), $conflict->statusCode(), $unavailable->statusCode(), $server->statusCode()])
                ->toBe([422, 409, 503, 500])
                ->and($server)->not->toBeInstanceOf(ServiceUnavailableException::class);
        });

        it('rejects a response with an unknown result', function (): void {
            [$client] = ClientFactory::answering(Payloads::syncResponse(200, 'merged'));

            expect(fn() => $client->putDocument('article:42', 'T', 'text'))
                ->toThrow(InvalidResponseException::class, 'SyncedDocument: unknown result "merged"');
        });

        it('rejects a response that is a document and not a result', function (): void {
            [$client] = ClientFactory::answering(Fixtures::response(200, json_encode(Payloads::externalDocument(), JSON_THROW_ON_ERROR)));

            expect(fn() => $client->putDocument('article:42', 'T', 'text'))
                ->toThrow(InvalidResponseException::class, 'missing required field "result"');
        });

        it('rejects text that is not valid UTF-8 without sending it', function (): void {
            [$client, $http] = ClientFactory::answering(Payloads::syncResponse(200));

            expect(fn() => $client->putDocument('article:42', 'T', "broken \xB1 text"))
                ->toThrow(InvalidArgumentException::class, 'cannot be encoded as JSON')
                ->and($http->getRequests())->toBe([]);
        });
    });

    it('sends text with quotes, line breaks and non-ASCII characters as it is', function (): void {
        [$client, $http] = ClientFactory::answering(Payloads::syncResponse(200));
        $content = "Zeile 1 \"zitiert\"\nZeile 2: Größe € 日本語 \\ /";

        $client->putDocument('article:42', 'Größe', $content);

        $sent = Payloads::fromJson(lastBodyText($http));

        expect($sent['content'] ?? null)->toBe($content)
            ->and($sent['title'] ?? null)->toBe('Größe');
    });
});

describe('getByExternalId', function (): void {
    it('gets the document by the encoded external id', function (): void {
        [$client, $http] = ClientFactory::answering(Fixtures::response(200, json_encode(Payloads::externalDocument(), JSON_THROW_ON_ERROR)));

        $document = $client->getByExternalId('article:42');

        $request = ClientFactory::lastRequest($http);

        expect($document->externalId)->toBe('article:42')
            ->and($document->metadata)->toBe(['locale' => 'en'])
            ->and($request->getMethod())->toBe('GET')
            ->and((string) $request->getUri())->toBe('http://localhost:8000/documents/external/article%3A42')
            ->and($request->getHeaderLine('Authorization'))->toBe('Bearer secret-key');
    });

    it('reports an unknown id as not found', function (): void {
        [$client] = ClientFactory::answering(Fixtures::response(404, '{"detail":"document not found"}'));

        $e = Thrown::by(fn() => $client->getByExternalId('nope'), NotFoundException::class);

        expect($e->statusCode())->toBe(404);
    });

    it('refuses an id that cannot be sent as a path', function (string $id): void {
        [$client, $http] = ClientFactory::answering(Fixtures::jsonResponse(200, 'document'));

        expect(fn() => $client->getByExternalId($id))->toThrow(InvalidArgumentException::class)
            ->and($http->getRequests())->toBe([]);
    })->with(['', 'a/b', '.', '..']);

    it('reports an id the service rejects as a validation error', function (): void {
        [$client] = ClientFactory::answering(Fixtures::response(422, '{"detail":[]}'));

        expect(fn() => $client->getByExternalId('-bad'))->toThrow(ValidationException::class);
    });
});

describe('deleteByExternalId', function (): void {
    it('deletes by the encoded external id', function (): void {
        [$client, $http] = ClientFactory::answering(Fixtures::response(204));

        $client->deleteByExternalId('article:42');

        $request = ClientFactory::lastRequest($http);

        expect($request->getMethod())->toBe('DELETE')
            ->and((string) $request->getUri())->toBe('http://localhost:8000/documents/external/article%3A42')
            ->and((string) $request->getBody())->toBe('');
    });

    it('does not fail when the same delete is repeated', function (): void {
        [$client, $http] = ClientFactory::answering(Fixtures::response(204), Fixtures::response(204));

        $client->deleteByExternalId('article:42');
        $client->deleteByExternalId('article:42');

        expect($http->getRequests())->toHaveCount(2);
    });

    it('refuses an id that cannot be sent as a path', function (string $id): void {
        [$client, $http] = ClientFactory::answering(Fixtures::response(204));

        expect(fn() => $client->deleteByExternalId($id))->toThrow(InvalidArgumentException::class)
            ->and($http->getRequests())->toBe([]);
    })->with(['', 'a/b', '.', '..']);

    it('reports an id the service rejects as a validation error', function (): void {
        [$client] = ClientFactory::answering(Fixtures::response(422, '{"detail":[]}'));

        expect(fn() => $client->deleteByExternalId('-bad'))->toThrow(ValidationException::class);
    });
});

describe('with retries', function (): void {
    it('sends a PUT again after a transient failure, with the same body', function (): void {
        [$client, $http, $sleeps] = ClientFactory::retrying(
            new RetryPolicy(),
            Fixtures::response(503, '{"detail":"queue unavailable"}'),
            Payloads::syncResponse(201),
        );

        $synced = $client->putDocument('article:42', 'T', 'text', ['locale' => 'en']);

        expect($synced->result)->toBe(SyncResult::Created)
            ->and($http->requests)->toHaveCount(2)
            ->and($http->requests[1]->getMethod())->toBe('PUT')
            ->and($http->bodies[1])->toBe($http->bodies[0])
            ->and($sleeps)->toHaveCount(1);
    });

    it('does not send a PUT again after a conflict, so that the caller decides', function (): void {
        [$client, $http] = ClientFactory::retrying(new RetryPolicy(), Fixtures::response(409, '{"detail":"conflict"}'), Payloads::syncResponse(201));

        expect(fn() => $client->putDocument('article:42', 'T', 'text'))->toThrow(ConflictException::class)
            ->and($http->requests)->toHaveCount(1);
    });

    it('does not send a PUT again after a validation error', function (): void {
        [$client, $http] = ClientFactory::retrying(new RetryPolicy(), Fixtures::response(422, '{"detail":[]}'), Payloads::syncResponse(201));

        expect(fn() => $client->putDocument('-bad', 'T', 'text'))->toThrow(ValidationException::class)
            ->and($http->requests)->toHaveCount(1);
    });

    it('sends a DELETE by external id again after a transient failure', function (): void {
        [$client, $http] = ClientFactory::retrying(new RetryPolicy(), Fixtures::response(502), Fixtures::response(204));

        $client->deleteByExternalId('article:42');

        expect($http->requests)->toHaveCount(2);
    });
});
