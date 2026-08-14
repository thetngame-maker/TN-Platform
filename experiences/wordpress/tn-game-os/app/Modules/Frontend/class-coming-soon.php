<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Coming_Soon implements Module_Interface {
    private const OPTION = 'tng_os_public_mode';
    private const NONCE = 'tng_os_public_mode_toggle';

    public function id(): string { return 'coming_soon'; }
    public function register(Container $container): void {}

    public function boot(Container $container): void {
        add_action('template_redirect', [$this, 'maybe_render'], 0);
        add_action('admin_bar_menu', [$this, 'admin_bar'], 95);
        add_action('admin_post_tng_os_toggle_public_mode', [$this, 'toggle']);
    }

    private function is_coming_soon(): bool {
        return get_option(self::OPTION, 'coming_soon') !== 'live';
    }

    private function admin_bypass(): bool {
        return is_user_logged_in() && current_user_can('manage_options');
    }

    public function maybe_render(): void {
        if (!$this->is_coming_soon() || $this->admin_bypass()) return;
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) return;
        if (defined('REST_REQUEST') && REST_REQUEST) return;

        status_header(200);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        include TNG_OS_PATH . 'app/Modules/Frontend/views/coming-soon.php';
        exit;
    }

    public function admin_bar(\WP_Admin_Bar $bar): void {
        if (!current_user_can('manage_options')) return;
        $coming = $this->is_coming_soon();
        $target = $coming ? 'live' : 'coming_soon';
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=tng_os_toggle_public_mode&mode=' . $target),
            self::NONCE
        );
        $bar->add_node([
            'id' => 'tng-public-mode',
            'title' => $coming ? '🔒 Public: Coming Soon' : '🌎 Public: Live',
            'href' => $url,
        ]);
    }

    public function toggle(): void {
        if (!current_user_can('manage_options')) return;
        check_admin_referer(self::NONCE);
        $mode = (isset($_GET['mode']) && $_GET['mode'] === 'live') ? 'live' : 'coming_soon';
        update_option(self::OPTION, $mode, false);
        wp_safe_redirect(wp_get_referer() ?: home_url('/'));
        exit;
    }
}
