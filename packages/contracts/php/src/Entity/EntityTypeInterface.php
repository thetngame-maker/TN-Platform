<?php

declare(strict_types=1);

namespace TN\Contracts\Entity;

interface EntityTypeInterface
{
    public function value(): string;

    public function equals(EntityTypeInterface $other): bool;

    public function __toString(): string;
}
