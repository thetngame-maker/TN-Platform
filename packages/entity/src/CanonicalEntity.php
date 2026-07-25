<?php

declare(strict_types=1);

namespace TN\Entity;

use DateTimeImmutable;
use DomainException;
use TN\Contracts\Entity\EntityIdInterface;
use TN\Contracts\Entity\EntityInterface;
use TN\Contracts\Entity\EntityLifecycleStateInterface;
use TN\Contracts\Entity\EntityTypeInterface;
use TN\Contracts\Entity\EntityVersionInterface;
use TN\Contracts\Relationship\RelationshipInterface;
use TN\Contracts\Source\SourceReferenceInterface;

final readonly class CanonicalEntity implements EntityInterface
{
    /**
     * @param array<string, mixed> $attributesValue
     * @param list<SourceReferenceInterface> $sources
     * @param list<RelationshipInterface> $relationshipValues
     */
    public function __construct(
        private EntityIdInterface $idValue,
        private EntityTypeInterface $typeValue,
        private EntityVersionInterface $versionValue,
        private EntityLifecycleStateInterface $lifecycleValue,
        private array $attributesValue,
        private array $sources,
        private array $relationshipValues,
        private DateTimeImmutable $updatedAtValue,
    ) {}

    /** @param array<string, mixed> $attributes */
    public static function create(EntityTypeInterface $type, array $attributes = []): self
    {
        return new self(
            EntityId::generate(),
            $type,
            new EntityVersion(),
            LifecycleState::draft(),
            $attributes,
            [],
            [],
            new DateTimeImmutable(),
        );
    }

    /** @param array<string, mixed> $attributes */
    public function withAttributes(array $attributes): self
    {
        return $this->evolve(attributes: $attributes);
    }

    public function withSource(SourceReferenceInterface $source): self
    {
        $sources = array_values(array_filter(
            $this->sources,
            static fn (SourceReferenceInterface $existing): bool => !(
                $existing->provider() === $source->provider()
                && $existing->externalId() === $source->externalId()
            ),
        ));
        $sources[] = $source;

        return $this->evolve(sources: $sources);
    }

    public function transitionTo(EntityLifecycleStateInterface $target): self
    {
        if (!$this->lifecycleValue->canTransitionTo($target)) {
            throw new DomainException(sprintf(
                'Invalid lifecycle transition from %s to %s.',
                $this->lifecycleValue->value(),
                $target->value(),
            ));
        }

        return $this->evolve(lifecycle: $target);
    }

    public function id(): EntityIdInterface { return $this->idValue; }
    public function type(): EntityTypeInterface { return $this->typeValue; }
    public function version(): EntityVersionInterface { return $this->versionValue; }
    public function lifecycleState(): EntityLifecycleStateInterface { return $this->lifecycleValue; }
    public function attributes(): array { return $this->attributesValue; }
    public function sourceReferences(): iterable { return $this->sources; }
    public function relationships(): iterable { return $this->relationshipValues; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAtValue; }

    /**
     * @param array<string, mixed>|null $attributes
     * @param list<SourceReferenceInterface>|null $sources
     */
    private function evolve(
        ?array $attributes = null,
        ?array $sources = null,
        ?EntityLifecycleStateInterface $lifecycle = null,
    ): self {
        $version = $this->versionValue instanceof EntityVersion
            ? $this->versionValue->next()
            : new EntityVersion($this->versionValue->number() + 1);

        return new self(
            $this->idValue,
            $this->typeValue,
            $version,
            $lifecycle ?? $this->lifecycleValue,
            $attributes ?? $this->attributesValue,
            $sources ?? $this->sources,
            $this->relationshipValues,
            new DateTimeImmutable(),
        );
    }
}
