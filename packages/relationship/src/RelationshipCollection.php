<?php

declare(strict_types=1);

namespace TN\Relationship;

use Countable;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, Relationship> */
final readonly class RelationshipCollection implements Countable, IteratorAggregate
{
    /** @param list<Relationship> $items */
    public function __construct(private array $items = [])
    {
    }

    public function add(Relationship $relationship): self
    {
        foreach ($this->items as $item) {
            if ($item->id() === $relationship->id()) {
                return $this;
            }
        }

        return new self([...$this->items, $relationship]);
    }

    public function ofType(RelationshipType $type): self
    {
        return new self(array_values(array_filter(
            $this->items,
            static fn (Relationship $relationship): bool => $relationship->type() === $type->value(),
        )));
    }

    /** @return list<Relationship> */
    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): Traversable
    {
        yield from $this->items;
    }
}
