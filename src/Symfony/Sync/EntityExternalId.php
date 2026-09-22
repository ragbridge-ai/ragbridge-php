<?php

declare(strict_types=1);

namespace Ragbridge\Symfony\Sync;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Ragbridge\Sync\ExternalId;
use Ragbridge\Sync\HasExternalId;

/**
 * The external id a Doctrine entity is synced under: the one it chooses by implementing
 * {@see HasExternalId}, or the table name and the identifier value otherwise.
 *
 * Only an entity with a single-column identifier is supported. A composite identifier
 * needs {@see HasExternalId}, and still needs a single-column identifier for
 * {@see self::key()}, which is used to reload the entity.
 */
final readonly class EntityExternalId
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function of(object $entity): string
    {
        return $entity instanceof HasExternalId
            ? $entity->ragbridgeExternalId()
            : ExternalId::of($this->tableName($entity), $this->key($entity));
    }

    /**
     * @throws InvalidArgumentException when the identifier is composite, missing or not an
     *                                  int or a string
     */
    public function key(object $entity): int|string
    {
        $values = $this->entityManager->getClassMetadata($entity::class)->getIdentifierValues($entity);

        if (count($values) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '%s must have a single-column identifier to be synced; it has %d.',
                $entity::class,
                count($values),
            ));
        }

        $key = array_values($values)[0];

        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidArgumentException(sprintf('The identifier of %s must be an int or a string.', $entity::class));
        }

        return $key;
    }

    private function tableName(object $entity): string
    {
        return $this->entityManager->getClassMetadata($entity::class)->getTableName();
    }
}
