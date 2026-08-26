<?php
namespace TNG_OS\Core;
if (!defined('ABSPATH')) exit;

final class Container {
    private array $values = [];
    public function set(string $key, $value): void { $this->values[$key] = $value; }
    public function get(string $key, $default = null) { return $this->values[$key] ?? $default; }
    public function has(string $key): bool { return array_key_exists($key, $this->values); }
}
