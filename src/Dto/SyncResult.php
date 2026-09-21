<?php

declare(strict_types=1);

namespace Ragbridge\Dto;

/**
 * What the service did with a document that was saved by its external id.
 */
enum SyncResult: string
{
    /** There was no document with this id, and one was created. */
    case Created = 'created';

    /** The text changed: the document was chunked and embedded again. */
    case Replaced = 'replaced';

    /** Only the title or the metadata changed. Nothing was chunked or embedded again. */
    case Updated = 'updated';

    /** The same record was sent again. Nothing was written. */
    case Unchanged = 'unchanged';

    /** Ignored, because the record is older than the stored one; the document is as it was. */
    case Stale = 'stale';
}
