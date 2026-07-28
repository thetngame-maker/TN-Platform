<?php
namespace TNG_OS\Modules\Developer;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

/**
 * Keeps the public/player runtime isolated from administrator tooling.
 *
 * Developer mode is intentionally request-scoped and must be enabled with
 * ?tng_dev=1 by an administrator. Merely being logged into WordPress must
 * never change the player experience.
 */
final class Developer_Manager implements Module_Interface {
    private const LEGACY_AUTO_OPTION = 'tng_auto_developer_mode';

    public function id(): string { return 'developer_manager'; }

    public function register(Container $container): void {
        $container->set('developer_manager', $this);

        add_action('init', [$this, 'disable_legacy_auto_mode'], 1);
        add_action('admin_bar_menu', [$this, 'admin_bar_menu'], 90);
        add_action('wp_footer', [$this, 'render_status_banner'], 9998);
        add_filter('body_class', [$this, 'body_class']);
    }

    public function boot(Container $container): void {}

    /**
     * Remove the old persistent auto-enable behavior. Query-string opt-in in
     * the compatibility runtime remains available for explicit test sessions.
     */
    public function disable_legacy_auto_mode(): void {
        if ((int)get_option(self::LEGACY_AUTO_OPTION, 0) !== 0) {
            update_option(self::LEGACY_AUTO_OPTION, 0, false);
        }
    }

    public function is_available(): bool {
        return current_user_can('manage_options');
    }

    public function is_enabled(): bool {
        if (!$this->is_available() || !isset($_GET['tng_dev'])) return false;
        return sanitize_text_field(wp_unslash($_GET['tng_dev'])) === '1';
    }

    public function admin_bar_menu($admin_bar): void {
        if (!$this->is_available() || is_admin()) return;

        $enabled = $this->is_enabled();
        $url = $enabled
            ? remove_query_arg('tng_dev')
            : add_query_arg('tng_dev', '1');

        $admin_bar->add_node([
            'id'    => 'tng-developer-mode',
            'title' => $enabled ? 'TN Developer: ON' : 'TN Developer: OFF',
            'href'  => esc_url($url),
            'meta'  => [
                'class' => $enabled ? 'tng-dev-is-enabled' : 'tng-dev-is-disabled',
                'title' => $enabled
                    ? 'Disable TN developer tools for this page'
                    : 'Enable TN developer tools for this page',
            ],
        ]);
    }

    public function body_class(array $classes): array {
        if ($this->is_enabled()) $classes[] = 'tng-developer-mode-active';
        return $classes;
    }

    public function render_status_banner(): void {
        if (!$this->is_enabled()) return;

        $disable_url = remove_query_arg('tng_dev');
        ?>
        <aside class="tng-developer-status" role="status" aria-live="polite">
            <span><strong>TN Developer Mode</strong> is active for this page only.</span>
            <a href="<?php echo esc_url($disable_url); ?>">Return to player view</a>
        </aside>
        <style>
            .tng-developer-status {
                position: fixed;
                left: 50%;
                bottom: 18px;
                z-index: 2147483000;
                transform: translateX(-50%);
                display: flex;
                align-items: center;
                gap: 14px;
                max-width: calc(100vw - 28px);
                padding: 10px 14px;
                border: 1px solid rgba(255,255,255,.24);
                border-radius: 999px;
                background: #7a2e0e;
                color: #fff;
                box-shadow: 0 8px 30px rgba(0,0,0,.28);
                font-size: 13px;
                line-height: 1.35;
                pointer-events: auto;
            }
            .tng-developer-status a { color: #fff; font-weight: 700; }
            @media (max-width: 600px) {
                .tng-developer-status {
                    right: 12px;
                    bottom: 12px;
                    left: 12px;
                    transform: none;
                    justify-content: space-between;
                    border-radius: 14px;
                }
            }
        </style>
        <?php
    }
}
