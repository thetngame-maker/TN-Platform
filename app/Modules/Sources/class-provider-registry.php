<?php
namespace TNG_OS\Modules\Sources;
if (!defined('ABSPATH')) exit;

final class Provider_Registry {
    private array $providers = [];

    public function register(Provider_Interface $provider): void {
        $this->providers[$provider->id()] = $provider;
    }
    public function get(string $id): ?Provider_Interface { return $this->providers[$id] ?? null; }
    public function all(): array { return $this->providers; }
}
