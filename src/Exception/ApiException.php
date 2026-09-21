<?php

declare(strict_types=1);

namespace Ragbridge\Exception;

use RuntimeException;

/**
 * Base class for errors the service reports with an HTTP error status.
 *
 * It carries the status code and the decoded response body, when there is one, so callers
 * can inspect the details the service returned.
 */
abstract class ApiException extends RuntimeException implements RagbridgeException
{
    /**
     * @param mixed $body decoded JSON body of the response, or null when it had none
     */
    final public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly mixed $body = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    /**
     * Builds the exception for a response, using the service's `detail` message when it
     * sent a plain string.
     *
     * @param mixed $body decoded JSON body of the response, or null when it had none
     */
    public static function fromResponse(int $statusCode, mixed $body): static
    {
        return new static(static::describe($statusCode, $body), $statusCode, $body);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return mixed decoded JSON body of the response, or null when it had none
     */
    public function body(): mixed
    {
        return $this->body;
    }

    protected static function describe(int $statusCode, mixed $body): string
    {
        $detail = is_array($body) ? ($body['detail'] ?? null) : null;

        return is_string($detail) && $detail !== ''
            ? sprintf('ragbridge service responded with HTTP %d: %s', $statusCode, $detail)
            : sprintf('ragbridge service responded with HTTP %d', $statusCode);
    }
}
