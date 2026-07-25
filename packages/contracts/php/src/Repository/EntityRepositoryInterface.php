<?php

declare(strict_types=1);

namespace TN\Contracts\Repository;

use TN\Contracts\Entity\EntityIdInterface;
use TN\Contracts\Entity\EntityInterface;
use TN\Contracts\Entity\EntitySnapshotInterface;

interface EntityRepositoryInterface
{
    public function find(EntityIdInterface $id): ?EntityInterface;

    public function findBySource(string $provider, string $externalId): ?EntityInterface;

    public function save(EntityInterface $entity): void;

    public function delete(EntityIdInterface $id): void;

    /** @return iterable<EntitySnapshotInterface> */
    public function snapshots(EntityIdInterface $id): iterable;
}
