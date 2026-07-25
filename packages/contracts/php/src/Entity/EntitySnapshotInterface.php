<?php

declare(strict_types=1);

namespace TN\Contracts\Entity;

use DateTimeImmutable;

interface EntitySnapshotInterface
{
    public function entityId(): EntityIdInterface;

    public function version(): EntityVersionInterface;

    /** @return array<string, mixed> */
    public function payload(): array;

    public function recordedAt(): DateTimeImmutable;
}
