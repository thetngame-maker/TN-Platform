<?php

declare(strict_types=1);

namespace TN\Graph;

use InvalidArgumentException;
use TN\Contracts\Entity\EntityIdInterface;
use TN\Relationship\RelationshipType;
use TN\Relationship\Repository\InMemoryRelationshipRepository;

final readonly class Graph
{
    public function __construct(private InMemoryRelationshipRepository $relationships)
    {
    }

    /** @return list<GraphPath> */
    public function traverse(
        EntityIdInterface $start,
        int $maxDepth = 1,
        ?RelationshipType $type = null,
    ): array {
        if ($maxDepth < 1 || $maxDepth > 10) {
            throw new InvalidArgumentException('Graph traversal depth must be between 1 and 10.');
        }

        $paths = [];
        $queue = [[new GraphPath([$start], []), 0]];

        while ($queue !== []) {
            [$path, $depth] = array_shift($queue);
            if ($depth >= $maxDepth) {
                continue;
            }

            foreach ($this->relationships->outgoing($path->end(), $type) as $relationship) {
                $target = $relationship->targetEntityId();
                foreach ($path->nodes() as $node) {
                    if ($node->equals($target)) {
                        continue 2;
                    }
                }

                $next = new GraphPath(
                    [...$path->nodes(), $target],
                    [...$path->edges(), $relationship],
                );
                $paths[] = $next;
                $queue[] = [$next, $depth + 1];
            }
        }

        return $paths;
    }

    public function connected(
        EntityIdInterface $source,
        EntityIdInterface $target,
        int $maxDepth = 3,
    ): bool {
        foreach ($this->traverse($source, $maxDepth) as $path) {
            if ($path->end()->equals($target)) {
                return true;
            }
        }

        return false;
    }
}
