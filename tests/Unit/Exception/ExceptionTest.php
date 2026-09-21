<?php

declare(strict_types=1);

use Ragbridge\Exception\ApiException;
use Ragbridge\Exception\AuthenticationException;
use Ragbridge\Exception\ConflictException;
use Ragbridge\Exception\InvalidResponseException;
use Ragbridge\Exception\NotFoundException;
use Ragbridge\Exception\RagbridgeException;
use Ragbridge\Exception\RequestFailedException;
use Ragbridge\Exception\ServerException;
use Ragbridge\Exception\ServiceUnavailableException;
use Ragbridge\Exception\TransportException;
use Ragbridge\Exception\ValidationException;

it('implements the marker interface in every exception', function (string $class): void {
    expect(is_a($class, RagbridgeException::class, true))->toBeTrue();
})->with([
    TransportException::class,
    InvalidResponseException::class,
    AuthenticationException::class,
    NotFoundException::class,
    ValidationException::class,
    ServerException::class,
    RequestFailedException::class,
    ConflictException::class,
    ServiceUnavailableException::class,
]);

it('groups the status based exceptions under ApiException', function (string $class): void {
    expect(is_subclass_of($class, ApiException::class))->toBeTrue();
})->with([
    AuthenticationException::class,
    NotFoundException::class,
    ValidationException::class,
    ServerException::class,
    RequestFailedException::class,
    ConflictException::class,
    ServiceUnavailableException::class,
]);

it('carries the status code and the decoded body', function (string $class, int $status): void {
    expect(is_subclass_of($class, ApiException::class))->toBeTrue();
    assert(is_subclass_of($class, ApiException::class));

    $body = ['detail' => 'invalid API key'];

    $exception = $class::fromResponse($status, $body);

    expect($exception)->toBeInstanceOf($class)
        ->and($exception->statusCode())->toBe($status)
        ->and($exception->getCode())->toBe($status)
        ->and($exception->body())->toBe($body);
})->with([
    'authentication 401' => [AuthenticationException::class, 401],
    'authentication 403' => [AuthenticationException::class, 403],
    'not found' => [NotFoundException::class, 404],
    'server 500' => [ServerException::class, 500],
    'server 503' => [ServerException::class, 503],
    'rate limited' => [RequestFailedException::class, 429],
    'conflict' => [ConflictException::class, 409],
    'unavailable' => [ServiceUnavailableException::class, 503],
]);

it('uses the detail message of the service when there is one', function (): void {
    $exception = NotFoundException::fromResponse(404, ['detail' => 'document not found']);

    expect($exception->getMessage())->toBe('ragbridge service responded with HTTP 404: document not found');
});

it('falls back to the status code when the body has no usable detail', function (mixed $body): void {
    expect(ServerException::fromResponse(502, $body)->getMessage())
        ->toBe('ragbridge service responded with HTTP 502');
})->with([
    'no body' => [null],
    'empty detail' => [['detail' => '']],
    'structured detail' => [['detail' => [['msg' => 'x']]]],
    'other shape' => [['error' => 'boom']],
    'scalar body' => ['Bad Gateway'],
]);

it('exposes the field errors of a validation failure', function (): void {
    $exception = ValidationException::fromResponse(422, [
        'detail' => [
            ['loc' => ['body', 'question'], 'msg' => 'Field required', 'type' => 'missing', 'input' => []],
            ['loc' => ['body', 'top_k'], 'msg' => 'Input should be less than or equal to 20', 'type' => 'less_than_equal', 'ctx' => ['le' => 20]],
        ],
    ]);

    expect($exception->statusCode())->toBe(422)
        ->and($exception->errors())->toBe([
            ['loc' => ['body', 'question'], 'msg' => 'Field required', 'type' => 'missing'],
            ['loc' => ['body', 'top_k'], 'msg' => 'Input should be less than or equal to 20', 'type' => 'less_than_equal'],
        ])
        ->and($exception->getMessage())->toBe(
            'ragbridge service rejected the request (HTTP 422): body.question: Field required; body.top_k: Input should be less than or equal to 20',
        );
});

it('keeps integer positions in the error location', function (): void {
    $exception = ValidationException::fromResponse(422, [
        'detail' => [['loc' => ['body', 'items', 2], 'msg' => 'Invalid', 'type' => 'value_error']],
    ]);

    expect($exception->errors()[0]['loc'])->toBe(['body', 'items', 2]);
});

it('ignores malformed validation entries', function (): void {
    $exception = ValidationException::fromResponse(422, [
        'detail' => [
            'not an object',
            ['loc' => ['body'], 'type' => 'missing'],
            ['loc' => 'body', 'msg' => 'No location list', 'type' => 'value_error'],
        ],
    ]);

    expect($exception->errors())->toBe([['loc' => [], 'msg' => 'No location list', 'type' => 'value_error']]);
});

it('returns no field errors when the body is not a validation payload', function (mixed $body): void {
    $exception = ValidationException::fromResponse(422, $body);

    expect($exception->errors())->toBe([])
        ->and($exception->getMessage())->toStartWith('ragbridge service responded with HTTP 422');
})->with([
    'no body' => [null],
    'string detail' => [['detail' => 'unprocessable']],
    'scalar' => ['nope'],
]);

it('wraps the underlying failure in a transport exception', function (): void {
    $previous = new RuntimeException('Connection refused');

    $exception = TransportException::fromThrowable($previous);

    expect($exception->getMessage())->toBe('Request to the ragbridge service failed: Connection refused')
        ->and($exception->getPrevious())->toBe($previous);
});

it('describes an invalid response', function (): void {
    $previous = new JsonException('Syntax error');

    $exception = InvalidResponseException::because('body is not valid JSON', $previous);

    expect($exception->getMessage())->toBe('Invalid response from the ragbridge service: body is not valid JSON')
        ->and($exception->getPrevious())->toBe($previous);
});

it('keeps the exceptions that were thrown before the specific ones existed as their parents', function (): void {
    expect(get_parent_class(ConflictException::class))->toBe(RequestFailedException::class)
        ->and(get_parent_class(ServiceUnavailableException::class))->toBe(ServerException::class);
});
