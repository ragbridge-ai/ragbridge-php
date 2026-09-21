<?php

declare(strict_types=1);

namespace Ragbridge\Exception;

use Ragbridge\Dto\Document;
use RuntimeException;

/**
 * A document was still being processed when the time to wait for it ran out.
 *
 * Nothing is wrong with the document: the service keeps processing it. The document as it
 * was last seen is available, so that the caller can wait again.
 */
final class ProcessingTimeoutException extends RuntimeException implements RagbridgeException
{
    public function __construct(public readonly Document $document, int $timeoutSeconds)
    {
        parent::__construct(sprintf(
            'Document %s was still %s after waiting %d seconds.',
            $document->id,
            $document->status->value,
            $timeoutSeconds,
        ));
    }
}
