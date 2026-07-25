<?php

declare(strict_types=1);

namespace TN\Contracts\Source;

use DateTimeImmutable;

interface SourceReferenceInterface
{
    public function provider(): string;

    public function externalId(): string;

    public function url(): ?string;

    public function checksum(): ?string;

    public function importedAt(): DateTimeImmutable;
}
