<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Symfony\Sync\Support;

use Doctrine\ORM\Mapping as ORM;
use Ragbridge\Sync\HasExternalId;
use Ragbridge\Sync\Syncable;
use Ragbridge\Sync\SyncDocument;

/**
 * An entity that chooses its own external id instead of the default.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tagged_posts')]
final class TaggedPost implements HasExternalId, Syncable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $body;

    public function __construct(string $title, string $body)
    {
        $this->title = $title;
        $this->body = $body;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function toRagbridgeDocument(): SyncDocument
    {
        return new SyncDocument($this->title, $this->body);
    }

    public function ragbridgeExternalId(): string
    {
        return 'tag:' . $this->id;
    }
}
