<?php

declare(strict_types=1);

namespace TN\Contracts\Entity;

interface EntityVersionInterface
{
    public function number(): int;

    public function isNewerThan(EntityVersionInterface $other): bool;

    public function __toString(): string;
}
