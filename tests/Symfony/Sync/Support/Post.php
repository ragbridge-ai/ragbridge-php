<?php

declare(strict_types=1);

namespace Ragbridge\Tests\Symfony\Sync\Support;

use Doctrine\ORM\Mapping as ORM;
use Ragbridge\Sync\Syncable;
use Ragbridge\Sync\SyncDocument;

/**
 * An entity synced under its default external id (table name and primary key), that leaves
 * itself out of the service while it is a draft.
 */
#[ORM\Entity]
#[ORM\Table(name: 'posts')]
final class Post implements Syncable
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
    private bool $published;

    public function __construct(string $title, string $body, bool $published = true)
    {
        $this->title = $title;
        $this->body = $body;
        $this->published = $published;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function setPublished(bool $published): void
    {
        $this->published = $published;
    }

    public function toRagbridgeDocument(): ?SyncDocument
    {
        return $this->published ? new SyncDocument($this->title, $this->body) : null;
    }
}
