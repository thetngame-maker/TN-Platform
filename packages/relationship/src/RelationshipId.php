<?php

declare(strict_types=1);

namespace TN\Relationship;

use InvalidArgumentException;

final readonly class RelationshipId
{
    private function __construct(private string $value)
    {
        if (!preg_match('/^rel_[0-9A-HJKMNP-TV-Z]{26}$/', $value)) {
            throw new InvalidArgumentException('Relationship IDs must use the rel_ prefix followed by a ULID.');
        }
    }

    public static function fromString(string $value): self
    {
        $prefix = substr($value, 0, 4);
        $ulid = substr($value, 4);

        return new self(strtolower($prefix) . strtoupper($ulid));
    }

    public static function generate(): self
    {
        $timestamp = (int) floor(microtime(true) * 1000);
        $bytes = substr(pack('J', $timestamp), -6) . random_bytes(10);
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $bits = '00';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $ulid = '';
        foreach (str_split($bits, 5) as $chunk) {
            $ulid .= $alphabet[bindec($chunk)];
        }

        return new self('rel_' . substr($ulid, 0, 26));
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
