<?php

declare(strict_types=1);

namespace TN\Contracts\Entity;

interface EntityLifecycleStateInterface
{
    public function value(): string;

    public function canTransitionTo(EntityLifecycleStateInterface $target): bool;

    public function __toString(): string;
}
