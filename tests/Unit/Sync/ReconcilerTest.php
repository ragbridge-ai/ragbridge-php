<?php

declare(strict_types=1);

use Ragbridge\Dto\SyncResult;
use Ragbridge\Exception\ConflictException;
use Ragbridge\Exception\RequestFailedException;
use Ragbridge\Exception\ServiceUnavailableException;
use Ragbridge\Exception\ValidationException;
use Ragbridge\Sync\Reconciler;
use Ragbridge\Sync\SyncDocument;
use Ragbridge\Tests\Support\ClientFactory;
use Ragbridge\Tests\Support\Fixtures;
use Ragbridge\Tests\Support\Payloads;

describe('reconcile', function (): void {
    it('puts the document when given one', function (): void {
        [$client, $http] = ClientFactory::answering(Payloads::syncResponse(200, 'updated'));
        $reconciler = new Reconciler($client);

        $synced = $reconciler->reconcile(
            'posts:42',
            new SyncDocument('Refund policy', 'Refunds are possible within 14 days.', ['locale' => 'en']),
        );

        $request = ClientFactory::lastRequest($http);

        expect($synced?->result)->toBe(SyncResult::Updated)
            ->and($request->getMethod())->toBe('PUT')
            ->and((string) $request->getUri())->toBe('http://localhost:8000/documents/external/posts%3A42');
    });

    it('deletes the document when given none', function (): void {
        [$client, $http] = ClientFactory::answering(Fixtures::response(204));
        $reconciler = new Reconciler($client);

        $synced = $reconciler->reconcile('posts:42', null);

        $request = ClientFactory::lastRequest($http);

        expect($synced)->toBeNull()
            ->and($request->getMethod())->toBe('DELETE')
            ->and((string) $request->getUri())->toBe('http://localhost:8000/documents/external/posts%3A42');
    });
});

describe('isPermanentFailure', function (): void {
    it('is true for a validation error', function (): void {
        $failure = ValidationException::fromResponse(422, ['detail' => 'nope']);

        expect(Reconciler::isPermanentFailure($failure))->toBeTrue();
    });

    it('is true for an invalid argument', function (): void {
        expect(Reconciler::isPermanentFailure(new InvalidArgumentException('bad id')))->toBeTrue();
    });

    it('is true for a request too large', function (): void {
        $failure = RequestFailedException::fromResponse(413, null);

        expect(Reconciler::isPermanentFailure($failure))->toBeTrue();
    });

    it('is false for a conflict', function (): void {
        $failure = ConflictException::fromResponse(409, null);

        expect(Reconciler::isPermanentFailure($failure))->toBeFalse();
    });

    it('is false for an unavailable service', function (): void {
        $failure = ServiceUnavailableException::fromResponse(503, null);

        expect(Reconciler::isPermanentFailure($failure))->toBeFalse();
    });

    it('is false for a request failure that is not 413', function (): void {
        $failure = RequestFailedException::fromResponse(429, null);

        expect(Reconciler::isPermanentFailure($failure))->toBeFalse();
    });
});
