<?php

declare(strict_types=1);

namespace TN\Entity\Repository;

use TN\Contracts\Entity\EntityIdInterface;
use TN\Contracts\Entity\EntityInterface;
use TN\Contracts\Entity\EntitySnapshotInterface;
use TN\Contracts\Repository\EntityRepositoryInterface;
use TN\Contracts\Source\SourceReferenceInterface;
use TN\Entity\EntitySnapshot;

final class InMemoryEntityRepository implements EntityRepositoryInterface
{
    /** @var array<string, EntityInterface> */
    private array $entities = [];

    /** @var array<string, list<EntitySnapshotInterface>> */
    private array $history = [];

    public function find(EntityIdInterface $id): ?EntityInterface
    {
        return $this->entities[$id->value()] ?? null;
    }

    public function findBySource(string $provider, string $externalId): ?EntityInterface
    {
        foreach ($this->entities as $entity) {
            foreach ($entity->sourceReferences() as $source) {
                if (!$source instanceof SourceReferenceInterface) {
                    continue;
                }
                if ($source->provider() === strtolower(trim($provider)) && $source->externalId() === trim($externalId)) {
                    return $entity;
                }
            }
        }

        return null;
    }

    public function save(EntityInterface $entity): void
    {
        $this->entities[$entity->id()->value()] = $entity;
        $this->history[$entity->id()->value()][] = new EntitySnapshot(
            $entity->id(),
            $entity->version(),
            [
                'type' => $entity->type()->value(),
                'lifecycle' => $entity->lifecycleState()->value(),
                'attributes' => $entity->attributes(),
            ],
            $entity->updatedAt(),
        );
    }

    public function delete(EntityIdInterface $id): void
    {
        unset($this->entities[$id->value()]);
    }

    public function snapshots(EntityIdInterface $id): iterable
    {
        return $this->history[$id->value()] ?? [];
    }
}
