<?php

declare(strict_types=1);

namespace TN\Contracts\Entity;

use DateTimeImmutable;

interface EntityInterface
{
    public function id(): EntityIdInterface;

    public function type(): EntityTypeInterface;

    public function version(): EntityVersionInterface;

    public function lifecycleState(): EntityLifecycleStateInterface;

    /** @return array<string, mixed> */
    public function attributes(): array;

    /** @return iterable<\TN\Contracts\Source\SourceReferenceInterface> */
    public function sourceReferences(): iterable;

    /** @return iterable<\TN\Contracts\Relationship\RelationshipInterface> */
    public function relationships(): iterable;

    public function updatedAt(): DateTimeImmutable;
}
