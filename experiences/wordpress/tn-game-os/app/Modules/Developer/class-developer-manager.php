<?php
namespace TNG_OS\Modules\Developer;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use TNG_OS\Modules\Frontend\Community_Expedition;

if (!defined('ABSPATH')) exit;

final class Developer_Manager implements Module_Interface {
    private const LEGACY_AUTO_OPTION = 'tng_auto_developer_mode';
    private const WEEKLY_META = '_tng_weekly_expedition';
    private const COMMUNITY_CLAIM_META = '_tng_community_expedition_claims';

    public function id(): string { return 'developer_manager'; }

    public function register(Container $container): void {
        $container->set('developer_manager', $this);
        add_action('init', [$this, 'disable_legacy_auto_mode'], 1);
        add_action('admin_bar_menu', [$this, 'admin_bar_menu'], 90);
        add_action('wp_footer', [$this, 'render_status_banner'], 9998);
        add_filter('body_class', [$this, 'body_class']);
        add_action('admin_post_tng_dev_reset_weekly', [$this, 'reset_weekly']);
        add_action('admin_post_tng_dev_reset_community', [$this, 'reset_community']);
    }

    public function boot(Container $container): void {}

    public function disable_legacy_auto_mode(): void {
        if ((int)get_option(self::LEGACY_AUTO_OPTION, 0) !== 0) update_option(self::LEGACY_AUTO_OPTION, 0, false);
    }

    public function is_available(): bool { return current_user_can('manage_options'); }

    public function is_enabled(): bool {
        if (!$this->is_available() || !isset($_GET['tng_dev'])) return false;
        return sanitize_text_field(wp_unslash($_GET['tng_dev'])) === '1';
    }

    public function admin_bar_menu($admin_bar): void {
        if (!$this->is_available() || is_admin()) return;
        $enabled = $this->is_enabled();
        $url = $enabled ? remove_query_arg('tng_dev') : add_query_arg('tng_dev', '1');
        $admin_bar->add_node([
            'id' => 'tng-developer-mode',
            'title' => $enabled ? 'TN Developer: ON' : 'TN Developer: OFF',
            'href' => esc_url($url),
            'meta' => ['class' => $enabled ? 'tng-dev-is-enabled' : 'tng-dev-is-disabled', 'title' => $enabled ? 'Disable TN developer tools for this page' : 'Enable TN developer tools for this page'],
        ]);
    }

    public function body_class(array $classes): array {
        if ($this->is_enabled()) $classes[] = 'tng-developer-mode-active';
        return $classes;
    }

    public function render_status_banner(): void {
        if (!$this->is_enabled()) return;
        $disable_url = remove_query_arg('tng_dev');
        $return_url = $this->current_url();
        $notice = isset($_GET['tng_dev_notice']) ? sanitize_text_field(wp_unslash($_GET['tng_dev_notice'])) : '';
        ?>
        <aside class="tng-developer-status" role="status" aria-live="polite">
            <div class="tng-dev-copy"><span><strong>TN Developer Mode</strong> is active for this page only.</span><?php if ($notice): ?><small><?php echo esc_html($notice); ?></small><?php endif; ?></div>
            <div class="tng-dev-actions">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Reset your Weekly Expedition event window and claims from this point?');">
                    <?php wp_nonce_field('tng_dev_reset_weekly'); ?><input type="hidden" name="action" value="tng_dev_reset_weekly"><input type="hidden" name="return_url" value="<?php echo esc_attr($return_url); ?>"><button type="submit">Reset weekly</button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Reset the global Community Expedition event window and clear community reward claims for testing?');">
                    <?php wp_nonce_field('tng_dev_reset_community'); ?><input type="hidden" name="action" value="tng_dev_reset_community"><input type="hidden" name="return_url" value="<?php echo esc_attr($return_url); ?>"><button type="submit">Reset community</button>
                </form>
                <a href="<?php echo esc_url($disable_url); ?>">Return to player view</a>
            </div>
        </aside>
        <style>
            .tng-developer-status{position:fixed;left:50%;bottom:18px;z-index:2147483000;transform:translateX(-50%);display:flex;align-items:center;gap:14px;max-width:calc(100vw - 28px);padding:10px 14px;border:1px solid rgba(255,255,255,.24);border-radius:18px;background:#7a2e0e;color:#fff;box-shadow:0 8px 30px rgba(0,0,0,.28);font-size:13px;line-height:1.35;pointer-events:auto}.tng-developer-status strong{font-weight:800}.tng-dev-copy{display:grid;gap:2px}.tng-dev-copy small{opacity:.82}.tng-dev-actions{display:flex;align-items:center;gap:8px}.tng-dev-actions form{margin:0}.tng-dev-actions button,.tng-developer-status a{appearance:none;border:1px solid rgba(255,255,255,.28);border-radius:999px;background:rgba(255,255,255,.12);color:#fff;padding:7px 10px;font:inherit;font-weight:700;cursor:pointer;text-decoration:none;white-space:nowrap}.tng-developer-status a{background:rgba(255,255,255,.2)}
            @media(max-width:760px){.tng-developer-status{right:12px;bottom:12px;left:12px;transform:none;align-items:stretch;flex-direction:column;border-radius:14px}.tng-dev-actions{display:grid;grid-template-columns:1fr 1fr}.tng-dev-actions a{grid-column:1/-1;text-align:center}.tng-dev-actions button{width:100%}}
        </style>
        <?php
    }

    public function reset_weekly(): void {
        $this->guard('tng_dev_reset_weekly');
        update_user_meta(get_current_user_id(), self::WEEKLY_META, [
            'weekKey' => $this->week_key(),
            'resetAt' => current_time('mysql', true),
            'claimed' => [],
        ]);
        $this->redirect_back('Weekly Expedition event window reset to 0 Trail Points.');
    }

    public function reset_community(): void {
        $this->guard('tng_dev_reset_community');
        update_option(Community_Expedition::DEV_BASELINE_OPTION, [
            'week' => $this->week_key(),
            'resetAt' => current_time('mysql', true),
        ], false);
        foreach (get_users(['fields' => 'ids']) as $user_id) delete_user_meta((int)$user_id, self::COMMUNITY_CLAIM_META);
        Community_Expedition::clear_cache();
        $this->redirect_back('Community Expedition event window reset and reward claims cleared.');
    }

    private function current_url(): string {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? ''));
        $uri = wp_unslash($_SERVER['REQUEST_URI'] ?? '/');
        return esc_url_raw($scheme . $host . $uri);
    }

    private function guard(string $action): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        check_admin_referer($action);
    }

    private function redirect_back(string $notice): void {
        $return = isset($_POST['return_url']) ? esc_url_raw(wp_unslash($_POST['return_url'])) : home_url('/');
        if (!$return || strpos($return, home_url('/')) !== 0) $return = home_url('/');
        $return = remove_query_arg('tng_dev_notice', $return);
        wp_safe_redirect(add_query_arg(['tng_dev' => '1', 'tng_dev_notice' => $notice], $return));
        exit;
    }

    private function week_key(): string { return gmdate('o-\WW', $this->week_start_timestamp()); }
    private function week_start_timestamp(): int {
        $now = current_time('timestamp', true);
        $day = (int)gmdate('N', $now);
        return strtotime('-' . ($day - 1) . ' days midnight', $now);
    }
}
