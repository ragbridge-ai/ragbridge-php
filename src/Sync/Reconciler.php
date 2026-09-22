<?php

declare(strict_types=1);

namespace Ragbridge\Sync;

use InvalidArgumentException;
use Ragbridge\Dto\SyncedDocument;
use Ragbridge\Exception\RagbridgeException;
use Ragbridge\Exception\RequestFailedException;
use Ragbridge\Exception\ValidationException;
use Ragbridge\RagbridgeClient;
use Throwable;

/**
 * Brings the document of one record in line with the record's current state.
 *
 * It sends the state it is given and does not compare it with an earlier one, so calling it
 * again, or with an older state, is safe: the service answers `unchanged` or `stale`. The
 * framework integrations load the record and call it from a queued job.
 */
final readonly class Reconciler
{
    public function __construct(private RagbridgeClient $client) {}

    /**
     * Saves the document, or deletes it when there is none.
     *
     * @param SyncDocument|null $document the current state of the record, or null when the
     *                                    record is gone or should not be in the service
     *
     * @return SyncedDocument|null what the service did with the document, or null when it was deleted
     *
     * @throws RagbridgeException
     * @throws InvalidArgumentException when the id or the metadata cannot be sent
     */
    public function reconcile(string $externalId, ?SyncDocument $document): ?SyncedDocument
    {
        if ($document === null) {
            $this->client->deleteByExternalId($externalId);

            return null;
        }

        return $this->client->putDocument(
            $externalId,
            $document->title,
            $document->content,
            $document->metadata,
            $document->sourceUpdatedAt,
        );
    }

    /**
     * Whether sending the same record again cannot succeed, so a queued job should fail
     * without being retried: the service rejected the document as invalid (422) or too large
     * (413), or the id or the metadata cannot be sent. Everything else, such as a conflict
     * with a concurrent delete, an unavailable service or a transport error, may pass.
     */
    public static function isPermanentFailure(Throwable $failure): bool
    {
        return $failure instanceof ValidationException
            || $failure instanceof InvalidArgumentException
            || ($failure instanceof RequestFailedException && $failure->statusCode() === 413);
    }
}
