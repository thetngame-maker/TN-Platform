<?php
namespace TNG_OS\Modules\Admin;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Runtime_Audit implements Module_Interface {
    private static bool $registered = false;

    public function id(): string { return 'runtime_audit'; }

    public function register(Container $container): void {
        if (self::$registered) return;
        self::$registered = true;
        add_action('admin_menu', [$this, 'menu'], 100);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page(
            'tn-game-os',
            'Runtime Audit',
            'Runtime Audit',
            'manage_options',
            'tng-os-runtime-audit',
            [$this, 'page']
        );
    }

    private function callback_name($function): string {
        if (is_string($function)) return $function;
        if (is_array($function) && count($function) === 2) {
            $owner = is_object($function[0]) ? get_class($function[0]) : (string)$function[0];
            return $owner . '::' . (string)$function[1];
        }
        if ($function instanceof \Closure) return 'Closure';
        return 'Unknown callback';
    }

    private function duplicate_callbacks(): array {
        global $wp_filter;
        $duplicates = [];

        foreach ((array)$wp_filter as $hook => $hook_object) {
            if (!is_object($hook_object) || empty($hook_object->callbacks)) continue;
            $seen = [];

            foreach ($hook_object->callbacks as $priority => $callbacks) {
                foreach ((array)$callbacks as $callback) {
                    $name = $this->callback_name($callback['function'] ?? null);
                    $signature = $priority . '|' . $name;
                    if (isset($seen[$signature])) {
                        $duplicates[] = $hook . ' → ' . $name . ' @ ' . $priority;
                    }
                    $seen[$signature] = true;
                }
            }
        }

        return array_values(array_unique($duplicates));
    }

    private function duplicate_menu_slugs(): array {
        global $menu, $submenu;
        $seen = [];
        $duplicates = [];

        foreach ((array)$menu as $item) {
            $slug = isset($item[2]) ? (string)$item[2] : '';
            if (!$slug) continue;
            if (isset($seen['top|' . $slug])) $duplicates[] = 'Top level: ' . $slug;
            $seen['top|' . $slug] = true;
        }

        foreach ((array)$submenu as $parent => $items) {
            foreach ((array)$items as $item) {
                $slug = isset($item[2]) ? (string)$item[2] : '';
                if (!$slug) continue;
                $key = $parent . '|' . $slug;
                if (isset($seen[$key])) $duplicates[] = $parent . ' → ' . $slug;
                $seen[$key] = true;
            }
        }

        return array_values(array_unique($duplicates));
    }

    public function page(): void {
        if (!current_user_can('manage_options')) return;
        $callbacks = $this->duplicate_callbacks();
        $menus = $this->duplicate_menu_slugs();
        ?>
        <div class="wrap tng-os-wrap">
            <header class="tng-os-page-heading"><div><span>SYSTEM DIAGNOSTICS</span><h1>Runtime Audit</h1><p>Checks the active WordPress request for callbacks and menu pages registered more than once.</p></div></header>
            <section class="tng-os-panel">
                <h2>Duplicate callbacks</h2>
                <?php if (!$callbacks): ?><p><strong>Pass.</strong> No identical callback is attached twice at the same priority.</p>
                <?php else: ?><ul><?php foreach ($callbacks as $item): ?><li><code><?php echo esc_html($item); ?></code></li><?php endforeach; ?></ul><?php endif; ?>
            </section>
            <section class="tng-os-panel">
                <h2>Duplicate menu slugs</h2>
                <?php if (!$menus): ?><p><strong>Pass.</strong> No menu page slug is registered twice beneath the same parent.</p>
                <?php else: ?><ul><?php foreach ($menus as $item): ?><li><code><?php echo esc_html($item); ?></code></li><?php endforeach; ?></ul><?php endif; ?>
            </section>
            <section class="tng-os-panel">
                <h2>Resolved in 4.2.0</h2>
                <p><code>tn-game-content-wizard</code> and <code>tn-game-content-dashboard</code> are now owned only by the Content Manager compatibility module. TN Game OS launcher cards still link to them, but no second callback is registered.</p>
            </section>
        </div>
        <?php
    }
}
