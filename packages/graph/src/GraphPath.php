<?php

declare(strict_types=1);

namespace TN\Graph;

use TN\Contracts\Entity\EntityIdInterface;
use TN\Relationship\Relationship;

final readonly class GraphPath
{
    /** @param list<EntityIdInterface> $nodes @param list<Relationship> $edges */
    public function __construct(
        private array $nodes,
        private array $edges,
    ) {
    }

    /** @return list<EntityIdInterface> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /** @return list<Relationship> */
    public function edges(): array
    {
        return $this->edges;
    }

    public function depth(): int
    {
        return count($this->edges);
    }

    public function end(): EntityIdInterface
    {
        return $this->nodes[array_key_last($this->nodes)];
    }
}
