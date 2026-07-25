<?php

declare(strict_types=1);

namespace TN\Relationship\Repository;

use TN\Contracts\Entity\EntityIdInterface;
use TN\Relationship\Relationship;
use TN\Relationship\RelationshipCollection;
use TN\Relationship\RelationshipType;

final class InMemoryRelationshipRepository
{
    /** @var array<string, Relationship> */
    private array $relationships = [];

    public function save(Relationship $relationship): void
    {
        $this->relationships[$relationship->id()] = $relationship;
    }

    public function find(string $id): ?Relationship
    {
        return $this->relationships[$id] ?? null;
    }

    public function delete(string $id): void
    {
        unset($this->relationships[$id]);
    }

    public function outgoing(EntityIdInterface $entityId, ?RelationshipType $type = null): RelationshipCollection
    {
        return $this->matching($entityId, true, $type);
    }

    public function incoming(EntityIdInterface $entityId, ?RelationshipType $type = null): RelationshipCollection
    {
        return $this->matching($entityId, false, $type);
    }

    private function matching(EntityIdInterface $entityId, bool $outgoing, ?RelationshipType $type): RelationshipCollection
    {
        $matches = [];

        foreach ($this->relationships as $relationship) {
            $candidate = $outgoing ? $relationship->sourceEntityId() : $relationship->targetEntityId();
            if (!$candidate->equals($entityId)) {
                continue;
            }
            if ($type !== null && $relationship->type() !== $type->value()) {
                continue;
            }
            $matches[] = $relationship;
        }

        return new RelationshipCollection($matches);
    }
}
