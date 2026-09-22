<?php

declare(strict_types=1);

namespace Ragbridge\Sync;

/**
 * A record of the application that is kept as a document in the service.
 *
 * Implement it on an Eloquent model or a Doctrine entity to have it synced when it changes.
 * The record is identified by the table name and the primary key, as in `posts:42`, unless
 * it also implements {@see HasExternalId}.
 */
interface Syncable
{
    /**
     * The document that represents the record in its current state, or null when the record
     * should not be in the service, for example while it is a draft. A record that returns
     * null has its document deleted.
     */
    public function toRagbridgeDocument(): ?SyncDocument;
}
