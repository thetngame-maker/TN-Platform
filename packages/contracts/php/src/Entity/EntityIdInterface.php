<?php

declare(strict_types=1);

namespace TN\Contracts\Entity;

interface EntityIdInterface
{
    public function value(): string;

    public function equals(EntityIdInterface $other): bool;

    public function __toString(): string;
}
