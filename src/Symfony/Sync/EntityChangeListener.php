<?php

declare(strict_types=1);

namespace Ragbridge\Symfony\Sync;

use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Ragbridge\Sync\Syncable;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Collects the {@see Syncable} entities that changed while Doctrine flushes, and dispatches
 * a {@see SyncMessage} for each once the flush has succeeded.
 *
 * Registered by the bundle, tagged as a Doctrine event listener, when symfony/messenger and
 * doctrine/orm are both installed. The id and the external id are computed here, while the
 * entity is still fully loaded: a hard-deleted entity can no longer be reloaded, but its
 * document is still removed under the id it had.
 */
final class EntityChangeListener
{
    /** @var list<SyncMessage> */
    private array $pending = [];

    public function __construct(
        private readonly EntityExternalId $externalId,
        private readonly MessageBusInterface $bus,
        private readonly bool $enabled = true,
    ) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->queue($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->queue($args->getObject());
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $this->queue($args->getObject());
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $message) {
            $this->bus->dispatch($message);
        }
    }

    private function queue(object $entity): void
    {
        if (! $this->enabled || ! $entity instanceof Syncable) {
            return;
        }

        $this->pending[] = new SyncMessage(
            $entity::class,
            $this->externalId->key($entity),
            $this->externalId->of($entity),
        );
    }
}
