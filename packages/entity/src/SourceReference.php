<?php

declare(strict_types=1);

namespace TN\Entity;

use DateTimeImmutable;
use InvalidArgumentException;
use TN\Contracts\Source\SourceReferenceInterface;

final readonly class SourceReference implements SourceReferenceInterface
{
    public function __construct(
        private string $provider,
        private string $externalId,
        private ?string $url = null,
        private ?string $checksum = null,
        private ?DateTimeImmutable $importedAtValue = null,
    ) {
        if (trim($provider) === '' || trim($externalId) === '') {
            throw new InvalidArgumentException('Source provider and external ID are required.');
        }
        if ($url !== null && filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Source URL must be valid.');
        }
    }

    public function provider(): string { return strtolower(trim($this->provider)); }
    public function externalId(): string { return trim($this->externalId); }
    public function url(): ?string { return $this->url; }
    public function checksum(): ?string { return $this->checksum; }
    public function importedAt(): DateTimeImmutable { return $this->importedAtValue ?? new DateTimeImmutable(); }
}
