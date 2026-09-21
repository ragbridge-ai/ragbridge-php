<?php

declare(strict_types=1);

namespace Ragbridge\Exception;

use RuntimeException;
use Throwable;

/**
 * The request could not be sent or no response was received, for example because the
 * service is unreachable or the connection timed out. No HTTP status is available.
 */
final class TransportException extends RuntimeException implements RagbridgeException
{
    public static function fromThrowable(Throwable $previous): self
    {
        return new self(sprintf('Request to the ragbridge service failed: %s', $previous->getMessage()), 0, $previous);
    }
}
