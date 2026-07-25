<?php

declare(strict_types=1);

namespace TN\Entity;

use InvalidArgumentException;
use TN\Contracts\Entity\EntityVersionInterface;

final readonly class EntityVersion implements EntityVersionInterface
{
    public function __construct(private int $number = 1)
    {
        if ($number < 1) {
            throw new InvalidArgumentException('Entity version must be at least 1.');
        }
    }

    public function number(): int { return $this->number; }
    public function next(): self { return new self($this->number + 1); }
    public function isNewerThan(EntityVersionInterface $other): bool { return $this->number > $other->number(); }
    public function __toString(): string { return (string) $this->number; }
}
