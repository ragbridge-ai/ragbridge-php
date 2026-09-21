<?php

declare(strict_types=1);

use Http\Client\Exception\NetworkException;
use Ragbridge\Exception\AuthenticationException;
use Ragbridge\Exception\InvalidResponseException;
use Ragbridge\Exception\NotFoundException;
use Ragbridge\Exception\RequestFailedException;
use Ragbridge\Exception\ServerException;
use Ragbridge\Exception\TransportException;
use Ragbridge\Exception\ValidationException;
use Ragbridge\RagbridgeClient;
use Ragbridge\RetryPolicy;
use Ragbridge\Tests\Support\ClientFactory;
use Ragbridge\Tests\Support\Fixtures;
use Ragbridge\Tests\Support\RecordingClient;
use Ragbridge\Tests\Support\StreamReadingClient;
use Ragbridge\Tests\Support\Thrown;
use Ragbridge\Tests\Support\UnsizedStream;

/** A failure of the connection, as a PSR-18 client reports it. */
function networkError(): NetworkException
{
    return new NetworkException('Connection reset', Fixtures::factory()->createRequest('GET', 'http://localhost:8000/documents'));
}

describe('by default', function (): void {
    it('sends a failed request only once', function (): void {
        [$client, $http, $sleeps] = ClientFactory::retrying(null, Fixtures::response(503, '{"detail":"database unavailable"}'));

        expect(fn() => $client->documents())->toThrow(ServerException::class)
            ->and($http->requests)->toHaveCount(1)
            ->and($sleeps)->toHaveCount(0);
    });

    it('does not retry a transport error', function (): void {
        [$client, $http] = ClientFactory::retrying(null, networkError());

        expect(fn() => $client->documents())->toThrow(TransportException::class)
            ->and($http->requests)->toHaveCount(1);
    });
});

describe('what is retried', function (): void {
    it('retries a GET that fails with a transient status', function (int $status): void {
        [$client, $http, $sleeps] = ClientFactory::retrying(
            new RetryPolicy(),
            Fixtures::response($status, '{"detail":"try later"}'),
            Fixtures::jsonResponse(200, 'documents'),
        );

        $documents = $client->documents();

        expect($documents)->toHaveCount(2)
            ->and($http->requests)->toHaveCount(2)
            ->and($sleeps)->toHaveCount(1);
    })->with([
        '429' => [429],
        '502' => [502],
        '503' => [503],
        '504' => [504],
    ]);

    it('retries after a transport error', function (): void {
        [$client, $http, $sleeps] = ClientFactory::retrying(new RetryPolicy(), networkError(), Fixtures::jsonResponse(200, 'documents'));

        expect($client->documents())->toHaveCount(2)
            ->and($http->requests)->toHaveCount(2)
            ->and($sleeps)->toHaveCount(1);
    });

    it('retries a DELETE', function (): void {
        [$client, $http] = ClientFactory::retrying(new RetryPolicy(), Fixtures::response(503), Fixtures::response(204));

        $client->deleteDocument('3f2b8c1e-5d4a-4b7e-9c61-0a1b2c3d4e5f');

        expect($http->requests)->toHaveCount(2)
            ->and($http->requests[1]->getMethod())->toBe('DELETE');
    });

    it('sends the same request again, including the API key', function (): void {
        [$client, $http] = ClientFactory::retrying(new RetryPolicy(), Fixtures::response(503), Fixtures::jsonResponse(200, 'document'));

        $client->document('3f2b8c1e-5d4a-4b7e-9c61-0a1b2c3d4e5f');

        expect((string) $http->requests[1]->getUri())->toBe((string) $http->requests[0]->getUri())
            ->and($http->requests[1]->getHeaderLine('Authorization'))->toBe('Bearer secret-key');
    });

    it('gives up after the maximum number of attempts and reports the last failure', function (): void {
        [$client, $http, $sleeps] = ClientFactory::retrying(
            new RetryPolicy(maxAttempts: 3),
            Fixtures::response(503),
            Fixtures::response(502),
            Fixtures::response(504, '{"detail":"gateway timeout"}'),
        );

        $e = Thrown::by(fn() => $client->documents(), ServerException::class);

        expect($e->statusCode())->toBe(504)
            ->and($e->getMessage())->toContain('gateway timeout')
            ->and($http->requests)->toHaveCount(3)
            ->and($sleeps)->toHaveCount(2);
    });

    it('reports the last transport error when every attempt fails', function (): void {
        [$client, $http, $sleeps] = ClientFactory::retrying(new RetryPolicy(maxAttempts: 2), networkError(), networkError());

        expect(fn() => $client->documents())->toThrow(TransportException::class)
            ->and($http->requests)->toHaveCount(2)
            ->and($sleeps)->toHaveCount(1);
    });

    it('recovers when a later attempt succeeds after mixed failures', function (): void {
        [$client, $http] = ClientFactory::retrying(
            new RetryPolicy(maxAttempts: 4),
            networkError(),
            Fixtures::response(429),
            Fixtures::response(503),
            Fixtures::jsonResponse(200, 'documents'),
        );

        expect($client->documents())->toHaveCount(2)
            ->and($http->requests)->toHaveCount(4);
    });

    it('does not retry when only one attempt is allowed', function (): void {
        [$client, $http, $sleeps] = ClientFactory::retrying(new RetryPolicy(maxAttempts: 1), Fixtures::response(503));

        expect(fn() => $client->documents())->toThrow(ServerException::class)
            ->and($http->requests)->toHaveCount(1)
            ->and($sleeps)->toHaveCount(0);
    });
});

describe('what is never retried', function (): void {
    it('does not retry a client error', function (int $status, string $exception): void {
        [$client, $http, $sleeps] = ClientFactory::retrying(
            new RetryPolicy(maxAttempts: 5),
            Fixtures::response($status, '{"detail":"no"}'),
            Fixtures::jsonResponse(200, 'documents'),
        );

        expect(fn() => $client->documents())->toThrow($exception)
            ->and($http->requests)->toHaveCount(1)
            ->and($sleeps)->toHaveCount(0);
    })->with([
        '400' => [400, RequestFailedException::class],
        '401' => [401, AuthenticationException::class],
        '403' => [403, AuthenticationException::class],
        '404' => [404, NotFoundException::class],
        '415' => [415, RequestFailedException::class],
        '422' => [422, ValidationException::class],
        '500' => [500, ServerException::class],
    ]);

    it('does not retry a success response that cannot be read', function (): void {
        [$client, $http] = ClientFactory::retrying(new RetryPolicy(), Fixtures::response(200, 'not json'), Fixtures::jsonResponse(200, 'documents'));

        expect(fn() => $client->documents())->toThrow(InvalidResponseException::class)
            ->and($http->requests)->toHaveCount(1);
    });

    it('does not retry a request that is not valid to begin with', function (): void {
        [$client, $http] = ClientFactory::retrying(new RetryPolicy(retryPost: true));

        expect(fn() => $client->upload('/no/such/file.txt'))->toThrow(InvalidArgumentException::class)
            ->and($http->requests)->toHaveCount(0);
    });
});

describe('POST requests', function (): void {
    it('are not retried by default', function (Closure $call): void {
        [$client, $http, $sleeps] = ClientFactory::retrying(new RetryPolicy(), Fixtures::response(503), Fixtures::jsonResponse(200, 'query_response'));

        expect(fn() => $call($client))->toThrow(ServerException::class)
            ->and($http->requests)->toHaveCount(1)
            ->and($sleeps)->toHaveCount(0);
    })->with([
        'query' => [fn(RagbridgeClient $client) => $client->query('Q')],
        'search' => [fn(RagbridgeClient $client) => $client->search('Q')],
        'agent' => [fn(RagbridgeClient $client) => $client->agent('Q')],
    ]);

    it('are not retried after a transport error by default', function (): void {
        [$client, $http] = ClientFactory::retrying(new RetryPolicy(), networkError(), Fixtures::jsonResponse(200, 'query_response'));

        expect(fn() => $client->query('Q'))->toThrow(TransportException::class)
            ->and($http->requests)->toHaveCount(1);
    });

    it('are retried when that is enabled', function (): void {
        [$client, $http, $sleeps] = ClientFactory::retrying(
            new RetryPolicy(retryPost: true),
            Fixtures::response(503),
            networkError(),
            Fixtures::jsonResponse(200, 'query_response'),
        );

        $result = $client->query('Q', topK: 2);

        expect($result->answer)->toContain('25 days')
            ->and($http->requests)->toHaveCount(3)
            ->and($sleeps)->toHaveCount(2);
    });

    it('carry the same JSON body on every attempt', function (): void {
        [$client, $http] = ClientFactory::retrying(
            new RetryPolicy(retryPost: true),
            Fixtures::response(503),
            Fixtures::response(502),
            Fixtures::jsonResponse(200, 'query_response'),
        );

        $client->query('Q', topK: 2);

        expect($http->bodies)->toHaveCount(3)
            ->and($http->bodies[1])->toBe($http->bodies[0])
            ->and($http->bodies[2])->toBe($http->bodies[0])
            ->and(json_decode($http->bodies[2], true))->toBe(['question' => 'Q', 'top_k' => 2]);
    });

    it('still get no retry for a client error', function (): void {
        [$client, $http] = ClientFactory::retrying(new RetryPolicy(retryPost: true), Fixtures::jsonResponse(422, 'error_validation'));

        expect(fn() => $client->query('Q', topK: 0))->toThrow(ValidationException::class)
            ->and($http->requests)->toHaveCount(1);
    });
});

describe('waiting', function (): void {
    it('waits longer before each further retry, within the jittered range', function (): void {
        [$client, , $sleeps] = ClientFactory::retrying(
            new RetryPolicy(maxAttempts: 5, baseDelayMs: 100, maxDelayMs: 10_000),
            Fixtures::response(503),
            Fixtures::response(503),
            Fixtures::response(503),
            Fixtures::response(503),
            Fixtures::jsonResponse(200, 'documents'),
        );

        $client->documents();

        expect($sleeps)->toHaveCount(4);

        foreach ([100, 200, 400, 800] as $index => $ceiling) {
            expect($sleeps[$index])->toBeGreaterThanOrEqual(intdiv($ceiling, 2))->toBeLessThanOrEqual($ceiling);
        }
    });

    it('never waits longer than the maximum delay', function (): void {
        [$client, , $sleeps] = ClientFactory::retrying(
            new RetryPolicy(maxAttempts: 4, baseDelayMs: 1_000, maxDelayMs: 1_500),
            Fixtures::response(503),
            Fixtures::response(503),
            Fixtures::response(503),
            Fixtures::jsonResponse(200, 'documents'),
        );

        $client->documents();

        expect($sleeps)->toHaveCount(3);

        foreach ($sleeps as $sleep) {
            expect($sleep)->toBeLessThanOrEqual(1_500);
        }
    });

    it('waits as long as Retry-After says, in seconds', function (): void {
        [$client, , $sleeps] = ClientFactory::retrying(
            new RetryPolicy(baseDelayMs: 100, maxDelayMs: 10_000),
            Fixtures::response(429, '{"detail":"slow down"}', ['Retry-After' => '2']),
            Fixtures::jsonResponse(200, 'documents'),
        );

        $client->documents();

        expect($sleeps->getArrayCopy())->toBe([2_000]);
    });

    it('waits until the date in Retry-After', function (): void {
        [$client, , $sleeps] = ClientFactory::retrying(
            new RetryPolicy(maxDelayMs: 10_000),
            Fixtures::response(503, '', ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 5)]),
            Fixtures::jsonResponse(200, 'documents'),
        );

        $client->documents();

        expect($sleeps)->toHaveCount(1)
            ->and($sleeps[0])->toBeGreaterThan(3_000)->toBeLessThanOrEqual(5_000);
    });

    it('retries at once when Retry-After is zero', function (): void {
        [$client, , $sleeps] = ClientFactory::retrying(
            new RetryPolicy(),
            Fixtures::response(429, '', ['Retry-After' => '0']),
            Fixtures::jsonResponse(200, 'documents'),
        );

        $client->documents();

        expect($sleeps->getArrayCopy())->toBe([0]);
    });

    it('reports the failure instead of waiting longer than the maximum delay', function (): void {
        [$client, $http, $sleeps] = ClientFactory::retrying(
            new RetryPolicy(maxDelayMs: 10_000),
            Fixtures::response(503, '{"detail":"maintenance"}', ['Retry-After' => '3600']),
            Fixtures::jsonResponse(200, 'documents'),
        );

        $e = Thrown::by(fn() => $client->documents(), ServerException::class);

        expect($e->statusCode())->toBe(503)
            ->and($http->requests)->toHaveCount(1)
            ->and($sleeps)->toHaveCount(0);
    });

    it('falls back to the backoff when Retry-After cannot be read', function (): void {
        [$client, , $sleeps] = ClientFactory::retrying(
            new RetryPolicy(baseDelayMs: 100, maxDelayMs: 10_000),
            Fixtures::response(503, '', ['Retry-After' => 'soon']),
            Fixtures::jsonResponse(200, 'documents'),
        );

        $client->documents();

        expect($sleeps)->toHaveCount(1)
            ->and($sleeps[0])->toBeGreaterThanOrEqual(50)->toBeLessThanOrEqual(100);
    });

    it('does not use Retry-After to retry a status that is not transient', function (): void {
        [$client, $http, $sleeps] = ClientFactory::retrying(
            new RetryPolicy(),
            Fixtures::response(404, '{"detail":"nope"}', ['Retry-After' => '1']),
        );

        expect(fn() => $client->document('3f2b8c1e-5d4a-4b7e-9c61-0a1b2c3d4e5f'))->toThrow(NotFoundException::class)
            ->and($http->requests)->toHaveCount(1)
            ->and($sleeps)->toHaveCount(0);
    });

    it('really waits when no sleep function is injected', function (): void {
        $http = new RecordingClient(Fixtures::response(503), Fixtures::jsonResponse(200, 'documents'));
        $factory = Fixtures::factory();
        $client = new RagbridgeClient($http, $factory, $factory, 'http://localhost:8000', null, new RetryPolicy(baseDelayMs: 40, maxDelayMs: 40));

        $started = hrtime(true);
        $client->documents();
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        // Half of the 40 ms step is always waited, the rest is jitter.
        expect($elapsedMs)->toBeGreaterThanOrEqual(19.0);
    });
});

describe('uploads', function (): void {
    it('are not retried by default', function (): void {
        $http = new StreamReadingClient(Fixtures::response(503), Fixtures::jsonResponse(200, 'document'));
        $client = ClientFactory::withSleeps($http, new RetryPolicy(), ClientFactory::sleeps());

        expect(fn() => $client->uploadStream(Fixtures::factory()->createStream('hello'), 'a.txt'))
            ->toThrow(ServerException::class)
            ->and($http->bodies)->toHaveCount(1);
    });

    it('send the whole file again when they are retried', function (): void {
        $http = new StreamReadingClient(Fixtures::response(503), Fixtures::response(504, '{"detail":"upstream timed out"}'), Fixtures::jsonResponse(200, 'document'));
        $sleeps = ClientFactory::sleeps();
        $client = ClientFactory::withSleeps($http, new RetryPolicy(retryPost: true), $sleeps);

        $document = $client->uploadStream(Fixtures::factory()->createStream('hello world'), 'a.txt');

        expect($document->filename)->toBe('handbook.pdf')
            ->and($http->bodies)->toHaveCount(3)
            ->and($http->bodies[0])->toContain('hello world')
            ->and($http->bodies[1])->toBe($http->bodies[0])
            ->and($http->bodies[2])->toBe($http->bodies[0])
            ->and($sleeps)->toHaveCount(2);
    });

    it('send the rest of the stream again, not what was read before it', function (): void {
        $http = new StreamReadingClient(Fixtures::response(503), Fixtures::jsonResponse(200, 'document'));
        $client = ClientFactory::withSleeps($http, new RetryPolicy(retryPost: true), ClientFactory::sleeps());
        $stream = Fixtures::factory()->createStream('SKIPPED-hello');
        $stream->seek(8);

        $client->uploadStream($stream, 'a.txt');

        expect($http->bodies)->toHaveCount(2)
            ->and($http->bodies[1])->toBe($http->bodies[0]);

        expect($http->bodies[0])->toContain('hello');
        expect($http->bodies[0])->not->toContain('SKIPPED');
    });

    it('send a file from disk again when they are retried', function (): void {
        $path = tempnam(sys_get_temp_dir(), 'ragbridge-');
        assert($path !== false);
        file_put_contents($path, 'file content');

        try {
            $http = new StreamReadingClient(Fixtures::response(502), Fixtures::jsonResponse(200, 'document'));
            $client = ClientFactory::withSleeps($http, new RetryPolicy(retryPost: true), ClientFactory::sleeps());

            $client->upload($path, 'notes.txt');

            expect($http->bodies)->toHaveCount(2)
                ->and($http->bodies[0])->toContain('file content')
                ->and($http->bodies[1])->toBe($http->bodies[0]);
        } finally {
            unlink($path);
        }
    });

    it('are not retried when the stream cannot be rewound', function (): void {
        $http = new StreamReadingClient(Fixtures::response(503), Fixtures::jsonResponse(200, 'document'));
        $sleeps = ClientFactory::sleeps();
        $client = ClientFactory::withSleeps($http, new RetryPolicy(retryPost: true), $sleeps);
        $stream = new UnsizedStream(Fixtures::factory()->createStream('hello'));

        expect(fn() => $client->uploadStream($stream, 'a.txt'))->toThrow(ServerException::class)
            ->and($http->bodies)->toHaveCount(1)
            ->and($sleeps)->toHaveCount(0);
    });
});
