<?php

declare(strict_types=1);

namespace Ragbridge\Sync;

/**
 * A {@see Syncable} record that decides, beyond its own state, whether it belongs in the
 * service right now, for example because it is a draft.
 *
 * When this returns false the document is deleted and {@see Syncable::toRagbridgeDocument()}
 * is not called. A record without this interface is always eligible.
 */
interface ShouldSyncToRagbridge
{
    public function shouldSyncToRagbridge(): bool;
}
