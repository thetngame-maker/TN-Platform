<?php

declare(strict_types=1);

namespace TN\Entity;

use DateTimeImmutable;
use TN\Contracts\Entity\EntityIdInterface;
use TN\Contracts\Entity\EntitySnapshotInterface;
use TN\Contracts\Entity\EntityVersionInterface;

final readonly class EntitySnapshot implements EntitySnapshotInterface
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        private EntityIdInterface $entityIdValue,
        private EntityVersionInterface $versionValue,
        private array $payloadValue,
        private DateTimeImmutable $recordedAtValue,
    ) {}

    public function entityId(): EntityIdInterface { return $this->entityIdValue; }
    public function version(): EntityVersionInterface { return $this->versionValue; }
    public function payload(): array { return $this->payloadValue; }
    public function recordedAt(): DateTimeImmutable { return $this->recordedAtValue; }
}
