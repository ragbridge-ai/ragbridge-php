<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Symfony\Sync\Support;

use Doctrine\ORM\Mapping as ORM;
use Ragbridge\Sync\ShouldSyncToRagbridge;
use Ragbridge\Sync\Syncable;
use Ragbridge\Sync\SyncDocument;

/**
 * An entity that is removed from the service, without being asked, whenever it should not
 * be visible right now, independently of {@see Syncable::toRagbridgeDocument()}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'restricted_posts')]
final class RestrictedPost implements ShouldSyncToRagbridge, Syncable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(type: 'boolean')]
    private bool $visible;

    public function __construct(string $title, string $body, bool $visible = true)
    {
        $this->title = $title;
        $this->body = $body;
        $this->visible = $visible;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function toRagbridgeDocument(): SyncDocument
    {
        return new SyncDocument($this->title, $this->body);
    }

    public function shouldSyncToRagbridge(): bool
    {
        return $this->visible;
    }
}
