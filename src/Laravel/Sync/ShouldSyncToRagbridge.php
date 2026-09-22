<?php

declare(strict_types=1);

namespace Ragbridge\Laravel\Sync;

/**
 * A {@see \Ragbridge\Sync\Syncable} Eloquent model that decides, beyond its own state,
 * whether it belongs in the service right now, for example because it is a draft.
 *
 * When this returns false the document is deleted and {@see \Ragbridge\Sync\Syncable::toRagbridgeDocument()}
 * is not called. A model without this interface is always eligible.
 */
interface ShouldSyncToRagbridge
{
    public function shouldSyncToRagbridge(): bool;
}
