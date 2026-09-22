<?php

declare(strict_types=1);

namespace Ragbridge\Symfony\Sync;

/**
 * Reconciles the document of one Doctrine entity with the entity's current state.
 *
 * Carries the class, the id and the external id, not the data, so {@see SyncMessageHandler}
 * reloads the entity when the message is handled and sends whatever state it finds then.
 */
final readonly class SyncMessage
{
    /**
     * @param class-string $entityClass
     * @param int|string $entityId the entity's identifier; only a single-column identifier
     *                             is supported, see {@see EntityExternalId}
     */
    public function __construct(
        public string $entityClass,
        public int|string $entityId,
        public string $externalId,
    ) {}
}
