<?php

declare(strict_types=1);

namespace TN\Entity;

use InvalidArgumentException;
use TN\Contracts\Entity\EntityIdInterface;

final readonly class EntityId implements EntityIdInterface
{
    private function __construct(private string $value)
    {
        if (!preg_match('/^ent_[0-9A-HJKMNP-TV-Z]{26}$/', $value)) {
            throw new InvalidArgumentException('Entity IDs must use the ent_ prefix followed by a ULID.');
        }
    }

    public static function fromString(string $value): self
    {
        $value = trim($value);
        if (strncasecmp($value, 'ent_', 4) !== 0) {
            throw new InvalidArgumentException('Entity IDs must begin with ent_.');
        }

        return new self('ent_' . strtoupper(substr($value, 4)));
    }

    public static function generate(): self
    {
        $timestamp = (int) floor(microtime(true) * 1000);
        $bytes = pack('J', $timestamp);
        $bytes = substr($bytes, -6) . random_bytes(10);
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $bits = '00';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $ulid = '';
        foreach (str_split($bits, 5) as $chunk) {
            $ulid .= $alphabet[bindec($chunk)];
        }

        return new self('ent_' . substr($ulid, 0, 26));
    }

    public function value(): string { return $this->value; }
    public function equals(EntityIdInterface $other): bool { return $this->value === $other->value(); }
    public function __toString(): string { return $this->value; }
}
