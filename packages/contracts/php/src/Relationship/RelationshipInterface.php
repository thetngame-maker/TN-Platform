<?php

declare(strict_types=1);

namespace TN\Contracts\Relationship;

use DateTimeImmutable;
use TN\Contracts\Entity\EntityIdInterface;

interface RelationshipInterface
{
    public function id(): string;

    public function type(): string;

    public function sourceEntityId(): EntityIdInterface;

    public function targetEntityId(): EntityIdInterface;

    /** @return array<string, mixed> */
    public function metadata(): array;

    public function createdAt(): DateTimeImmutable;
}
