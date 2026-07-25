<?php

declare(strict_types=1);

namespace TN\Relationship;

use DateTimeImmutable;
use InvalidArgumentException;
use TN\Contracts\Entity\EntityIdInterface;
use TN\Contracts\Relationship\RelationshipInterface;

final readonly class Relationship implements RelationshipInterface
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        private RelationshipId $relationshipId,
        private RelationshipType $relationshipType,
        private EntityIdInterface $source,
        private EntityIdInterface $target,
        private array $relationshipMetadata = [],
        private float $confidence = 1.0,
        private ?string $sourceProvider = null,
        private ?DateTimeImmutable $createdAtValue = null,
    ) {
        if ($source->equals($target)) {
            throw new InvalidArgumentException('A relationship cannot target its source entity.');
        }
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException('Relationship confidence must be between 0 and 1.');
        }
    }

    /** @param array<string, mixed> $metadata */
    public static function create(
        RelationshipType $type,
        EntityIdInterface $source,
        EntityIdInterface $target,
        array $metadata = [],
        float $confidence = 1.0,
        ?string $sourceProvider = null,
    ): self {
        return new self(RelationshipId::generate(), $type, $source, $target, $metadata, $confidence, $sourceProvider);
    }

    public function id(): string
    {
        return $this->relationshipId->value();
    }

    public function type(): string
    {
        return $this->relationshipType->value();
    }

    public function sourceEntityId(): EntityIdInterface
    {
        return $this->source;
    }

    public function targetEntityId(): EntityIdInterface
    {
        return $this->target;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return $this->relationshipMetadata + [
            'confidence' => $this->confidence,
            'source_provider' => $this->sourceProvider,
        ];
    }

    public function confidence(): float
    {
        return $this->confidence;
    }

    public function sourceProvider(): ?string
    {
        return $this->sourceProvider;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAtValue ?? new DateTimeImmutable();
    }
}
