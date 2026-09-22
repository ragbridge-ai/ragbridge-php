<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Laravel\Sync\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Ragbridge\Laravel\Sync\SyncsWithRagbridge;
use Ragbridge\Sync\Syncable;
use Ragbridge\Sync\SyncDocument;

/**
 * A model synced under its default external id (table name and primary key), that leaves
 * itself out of the service while it is a draft.
 */
final class Post extends Model implements Syncable
{
    use SoftDeletes;
    use SyncsWithRagbridge;
    use TypedAttributes;

    /** @var array<string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return ['published' => 'boolean'];
    }

    public function toRagbridgeDocument(): ?SyncDocument
    {
        if ($this->getAttribute('published') !== true) {
            return null;
        }

        return new SyncDocument($this->stringAttribute('title'), $this->stringAttribute('body'));
    }
}
