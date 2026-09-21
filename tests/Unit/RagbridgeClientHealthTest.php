<?php

declare(strict_types=1);

use Http\Client\Exception\NetworkException;
use Http\Mock\Client as MockClient;
use Ragbridge\Exception\InvalidResponseException;
use Ragbridge\Exception\ServerException;
use Ragbridge\Exception\TransportException;
use Ragbridge\Tests\Support\ClientFactory;
use Ragbridge\Tests\Support\Fixtures;
use Ragbridge\Tests\Support\Thrown;

describe('health', function (): void {
    it('reports that the service is running', function (): void {
        [$client, $http] = ClientFactory::answering(Fixtures::response(200, '{"status":"ok"}'));

        $status = $client->health();

        $request = ClientFactory::lastRequest($http);

        expect($status->isOk())->toBeTrue()
            ->and($request->getMethod())->toBe('GET')
            ->and((string) $request->getUri())->toBe('http://localhost:8000/health')
            ->and($request->getHeaderLine('Accept'))->toBe('application/json')
            ->and((string) $request->getBody())->toBe('');
    });

    it('reports an unreachable service as a transport error', function (): void {
        $http = new MockClient();
        $http->addException(new NetworkException('Connection refused', Fixtures::factory()->createRequest('GET', 'http://localhost:8000/health')));

        expect(fn() => ClientFactory::make($http)->health())->toThrow(TransportException::class);
    });

    it('rejects a response that does not match the schema', function (): void {
        [$client] = ClientFactory::answering(Fixtures::response(200, '{"state":"ok"}'));

        expect(fn() => $client->health())->toThrow(InvalidResponseException::class, 'HealthStatus');
    });
});

describe('readiness', function (): void {
    it('reports that the service can serve requests', function (): void {
        [$client, $http] = ClientFactory::answering(Fixtures::response(200, '{"status":"ok"}'));

        $status = $client->readiness();

        $request = ClientFactory::lastRequest($http);

        expect($status->isOk())->toBeTrue()
            ->and($request->getMethod())->toBe('GET')
            ->and((string) $request->getUri())->toBe('http://localhost:8000/health/ready');
    });

    it('reports a service that is not ready as a server error', function (): void {
        [$client] = ClientFactory::answering(Fixtures::response(503, '{"detail":"database unavailable"}'));

        $e = Thrown::by(fn() => $client->readiness(), ServerException::class);

        expect($e->statusCode())->toBe(503)
            ->and($e->getMessage())->toContain('database unavailable');
    });
});
