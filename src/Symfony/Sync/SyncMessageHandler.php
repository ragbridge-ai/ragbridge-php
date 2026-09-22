<?php

declare(strict_types=1);

namespace Ragbridge\Symfony\Sync;

use Doctrine\ORM\EntityManagerInterface;
use Ragbridge\Sync\Reconciler;
use Ragbridge\Sync\ShouldSyncToRagbridge;
use Ragbridge\Sync\Syncable;
use Ragbridge\Sync\SyncDocument;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Throwable;

/**
 * Handles {@see SyncMessage}: reloads the entity and reconciles its document.
 *
 * Registered as a Messenger handler by the bundle when symfony/messenger and doctrine/orm
 * are both installed. A permanent failure ({@see Reconciler::isPermanentFailure()}) is
 * reported as {@see UnrecoverableMessageHandlingException}, so Messenger does not retry it;
 * anything else is left to propagate, so the transport's own retry strategy applies.
 */
final readonly class SyncMessageHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Reconciler $reconciler,
    ) {}

    public function __invoke(SyncMessage $message): void
    {
        try {
            $this->reconciler->reconcile($message->externalId, $this->document($message));
        } catch (Throwable $failure) {
            if (Reconciler::isPermanentFailure($failure)) {
                throw new UnrecoverableMessageHandlingException($failure->getMessage(), previous: $failure);
            }

            throw $failure;
        }
    }

    private function document(SyncMessage $message): ?SyncDocument
    {
        $entity = $this->entityManager->find($message->entityClass, $message->entityId);

        if (! $entity instanceof Syncable || self::isIneligible($entity)) {
            return null;
        }

        return $entity->toRagbridgeDocument();
    }

    private static function isIneligible(Syncable $entity): bool
    {
        return $entity instanceof ShouldSyncToRagbridge && ! $entity->shouldSyncToRagbridge();
    }
}
