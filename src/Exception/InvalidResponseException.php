<?php

declare(strict_types=1);

namespace Ragbridge\Exception;

use RuntimeException;
use Throwable;

/**
 * The service answered with a success status but the body could not be decoded or does
 * not match the expected schema.
 */
final class InvalidResponseException extends RuntimeException implements RagbridgeException
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self(sprintf('Invalid response from the ragbridge service: %s', $reason), 0, $previous);
    }
}
