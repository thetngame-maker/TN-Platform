<?php

declare(strict_types=1);

namespace TN\Relationship;

use InvalidArgumentException;

final readonly class RelationshipType
{
    private const BUILT_IN = [
        'contains',
        'inside',
        'located_in',
        'near',
        'part_of',
        'held_at',
        'has_top_sight',
        'starts_at',
        'ends_at',
        'connects_to',
        'recommended_with',
        'managed_by',
    ];

    private function __construct(private string $value)
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $value)) {
            throw new InvalidArgumentException('Relationship types must use lowercase snake_case.');
        }
    }

    public static function fromString(string $value): self
    {
        return new self(strtolower(trim($value)));
    }

    public static function heldAt(): self
    {
        return new self('held_at');
    }

    public static function locatedIn(): self
    {
        return new self('located_in');
    }

    /** @return list<string> */
    public static function builtIn(): array
    {
        return self::BUILT_IN;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isBuiltIn(): bool
    {
        return in_array($this->value, self::BUILT_IN, true);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
