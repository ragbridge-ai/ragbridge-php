<?php

declare(strict_types=1);

namespace Ragbridge\Sync;

/**
 * A {@see Syncable} record that chooses the id of its document itself, instead of the
 * default made of the table name and the primary key.
 *
 * The id must stay the same while the record exists. When it changes, the document with the
 * old id is left behind in the service.
 */
interface HasExternalId
{
    /**
     * 1 to 255 letters, digits and the characters . _ : @ - starting with a letter or a digit.
     */
    public function ragbridgeExternalId(): string;
}
