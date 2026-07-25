<?php

declare(strict_types=1);

namespace TN\Entity;

use InvalidArgumentException;
use TN\Contracts\Entity\EntityLifecycleStateInterface;

final readonly class LifecycleState implements EntityLifecycleStateInterface
{
    private const TRANSITIONS = [
        'draft' => ['discovered', 'archived'],
        'discovered' => ['normalized', 'archived'],
        'normalized' => ['validated', 'archived'],
        'validated' => ['canonical', 'archived'],
        'canonical' => ['published', 'enriched', 'archived'],
        'published' => ['enriched', 'archived'],
        'enriched' => ['published', 'archived'],
        'archived' => [],
    ];

    private function __construct(private string $value)
    {
        if (!array_key_exists($value, self::TRANSITIONS)) {
            throw new InvalidArgumentException(sprintf('Unsupported lifecycle state: %s', $value));
        }
    }

    public static function fromString(string $value): self { return new self(strtolower(trim($value))); }
    public static function draft(): self { return new self('draft'); }
    public static function discovered(): self { return new self('discovered'); }
    public static function canonical(): self { return new self('canonical'); }

    public function value(): string { return $this->value; }
    public function canTransitionTo(EntityLifecycleStateInterface $target): bool
    {
        return in_array($target->value(), self::TRANSITIONS[$this->value], true);
    }
    public function __toString(): string { return $this->value; }
}
