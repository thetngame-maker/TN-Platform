<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Runtime_Direct_Bootstrap_Fix implements Module_Interface {
    public function id(): string { return 'runtime_direct_bootstrap_fix'; }

    public function register(Container $container): void {
        $container->set('runtime_direct_bootstrap_fix', $this);
        add_filter('the_content', [$this, 'patch_runtime_bootstrap'], 999);
    }

    public function boot(Container $container): void {}

    public function patch_runtime_bootstrap(string $content): string {
        if (strpos($content, 'class="tng-runtime"') === false) return $content;

        $fragile = "const root=document.currentScript.closest('.tng-runtime'); if(!root)return;";
        $stable = "const root=document.querySelector('.tng-runtime'); if(!root||root.dataset.runtimeBooted)return; root.dataset.runtimeBooted='1';";

        return str_replace($fragile, $stable, $content);
    }
}
