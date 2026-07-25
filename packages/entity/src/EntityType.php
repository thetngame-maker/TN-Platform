<?php

declare(strict_types=1);

namespace TN\Entity;

use InvalidArgumentException;
use TN\Contracts\Entity\EntityTypeInterface;

final readonly class EntityType implements EntityTypeInterface
{
    private const SUPPORTED = ['event', 'venue', 'place'];

    private function __construct(private string $value)
    {
        if (!in_array($value, self::SUPPORTED, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported entity type: %s', $value));
        }
    }

    public static function fromString(string $value): self
    {
        return new self(strtolower(trim($value)));
    }

    public static function event(): self { return new self('event'); }
    public static function venue(): self { return new self('venue'); }
    public static function place(): self { return new self('place'); }

    public function value(): string { return $this->value; }
    public function equals(EntityTypeInterface $other): bool { return $this->value === $other->value(); }
    public function __toString(): string { return $this->value; }
}
