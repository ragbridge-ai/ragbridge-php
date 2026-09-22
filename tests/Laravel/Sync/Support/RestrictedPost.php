<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Laravel\Sync\Support;

use Illuminate\Database\Eloquent\Model;
use Ragbridge\Laravel\Sync\SyncsWithRagbridge;
use Ragbridge\Sync\ShouldSyncToRagbridge;
use Ragbridge\Sync\Syncable;
use Ragbridge\Sync\SyncDocument;

/**
 * A model that is removed from the service, without being asked, whenever it should not
 * be visible right now, independently of {@see Syncable::toRagbridgeDocument()}.
 */
final class RestrictedPost extends Model implements ShouldSyncToRagbridge, Syncable
{
    use SyncsWithRagbridge;
    use TypedAttributes;

    protected function casts(): array
    {
        return ['visible' => 'boolean'];
    }

    public function toRagbridgeDocument(): SyncDocument
    {
        return new SyncDocument($this->stringAttribute('title'), $this->stringAttribute('body'));
    }

    public function shouldSyncToRagbridge(): bool
    {
        return $this->getAttribute('visible') === true;
    }
}
