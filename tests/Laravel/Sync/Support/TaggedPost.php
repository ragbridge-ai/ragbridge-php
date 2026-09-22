<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Laravel\Sync\Support;

use Illuminate\Database\Eloquent\Model;
use Ragbridge\Laravel\Sync\SyncsWithRagbridge;
use Ragbridge\Sync\HasExternalId;
use Ragbridge\Sync\Syncable;
use Ragbridge\Sync\SyncDocument;

/**
 * A model that chooses its own external id instead of the default.
 */
final class TaggedPost extends Model implements HasExternalId, Syncable
{
    use SyncsWithRagbridge;
    use TypedAttributes;

    /** @var array<string> */
    protected $guarded = [];

    public function toRagbridgeDocument(): SyncDocument
    {
        return new SyncDocument($this->stringAttribute('title'), $this->stringAttribute('body'));
    }

    public function ragbridgeExternalId(): string
    {
        return 'tag:' . $this->scalarKey();
    }
}
