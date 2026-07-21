<?php
namespace TNG_OS\Modules\Sources;
if (!defined('ABSPATH')) exit;

interface Provider_Interface {
    public function id(): string;
    public function label(): string;
    public function capabilities(): array;
    public function fetch(string $external_id, array $context = []);
    public function normalize(array $remote): array;
}
