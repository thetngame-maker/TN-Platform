<?php
namespace TNG_OS\Modules\Sources;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Server_Cron_Heartbeat implements Module_Interface {
    private const HOOK = 'tng_server_cron_heartbeat';
    private const OPTION = 'tng_server_cron_heartbeat_v1';
    private const SCHEDULE = 'tng_every_15_minutes';
    private const NONCE = 'tng_server_cron_heartbeat_action';

    public function id(): string { return 'server_cron_heartbeat'; }

    public function register(Container $container): void {
        add_filter('cron_schedules', [$this, 'cron_schedules']);
        add_action(self::HOOK, [$this, 'heartbeat']);
        add_action('admin_menu', [$this, 'admin_menu'], 29);
        add_action('admin_post_tng_cron_heartbeat_test', [$this, 'test_action']);
        $container->set('server_cron_heartbeat', $this);
    }

    public function boot(Container $container): void { $this->ensure_schedule(); }

    public function cron_schedules(array $schedules): array {
        if (!isset($schedules[self::SCHEDULE])) {
            $schedules[self::SCHEDULE] = [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display' => 'Every 15 Minutes (TN Game heartbeat)',
            ];
        }
        return $schedules;
    }

    private function ensure_schedule(): void {
        $event = wp_get_scheduled_event(self::HOOK);
        if ($event && $event->schedule === self::SCHEDULE) return;
        if ($event) wp_clear_scheduled_hook(self::HOOK);
        wp_schedule_event(time() + 60, self::SCHEDULE, self::HOOK);
    }

    public function heartbeat(): void {
        $previous = get_option(self::OPTION, []);
        $previous = is_array($previous) ? $previous : [];
        $count = absint($previous['count'] ?? 0) + 1;
        update_option(self::OPTION, [
            'last_at' => current_time('mysql'),
            'last_timestamp' => time(),
            'count' => $count,
            'request_uri' => sanitize_text_field((string)($_SERVER['REQUEST_URI'] ?? 'cron')),
        ], false);
    }

    public function admin_menu(): void {
        add_submenu_page(
            'tng-content-studio',
            'Cron Reliability',
            'Cron Reliability',
            'manage_options',
            'tng-cron-reliability',
            [$this, 'render_page']
        );
    }

    public function test_action(): void {
        if (!current_user_can('manage_options')) wp_die('You do not have permission to test cron health.');
        check_admin_referer(self::NONCE);
        $this->heartbeat();
        wp_safe_redirect(add_query_arg([
            'page' => 'tng-cron-reliability',
            'tng_notice' => rawurlencode('Heartbeat test recorded. This confirms the module works; configure the Cloudways server cron to verify automatic execution.'),
        ], admin_url('admin.php')));
        exit;
    }

    private function heartbeat_data(): array {
        $value = get_option(self::OPTION, []);
        return is_array($value) ? $value : [];
    }

    private function status(array $heartbeat): array {
        $last = absint($heartbeat['last_timestamp'] ?? 0);
        if (!$last) return ['Waiting', 'No automatic heartbeat has been recorded yet.', '#b26200'];
        $age = time() - $last;
        if ($age <= 45 * MINUTE_IN_SECONDS) return ['Healthy', 'Heartbeat received ' . human_time_diff($last, time()) . ' ago.', '#008a20'];
        if ($age <= 2 * HOUR_IN_SECONDS) return ['Late', 'Last heartbeat was ' . human_time_diff($last, time()) . ' ago.', '#b26200'];
        return ['Stale', 'Last heartbeat was ' . human_time_diff($last, time()) . ' ago.', '#b32d2e'];
    }

    public static function health(): array {
        $heartbeat = get_option(self::OPTION, []);
        $heartbeat = is_array($heartbeat) ? $heartbeat : [];
        $last = absint($heartbeat['last_timestamp'] ?? 0);
        $age = $last ? time() - $last : null;
        return [
            'last_at' => (string)($heartbeat['last_at'] ?? ''),
            'last_timestamp' => $last,
            'count' => absint($heartbeat['count'] ?? 0),
            'age' => $age,
            'healthy' => $last > 0 && $age <= 45 * MINUTE_IN_SECONDS,
            'late' => $last > 0 && $age > 45 * MINUTE_IN_SECONDS,
            'next' => wp_next_scheduled(self::HOOK),
        ];
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) return;
        $heartbeat = $this->heartbeat_data();
        [$label, $detail, $color] = $this->status($heartbeat);
        $next = wp_next_scheduled(self::HOOK);
        $notice = sanitize_text_field(wp_unslash($_GET['tng_notice'] ?? ''));
        $wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        ?>
        <div class="wrap">
            <h1>⏱️ Cron Reliability</h1>
            <p>Verify that WordPress scheduled jobs are actually being executed, independently of Town Monitor's daily or weekly scan cadence.</p>
            <?php if ($notice): ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>

            <div style="background:#fff;border:1px solid #dcdcde;border-left:4px solid <?php echo esc_attr($color); ?>;border-radius:10px;padding:18px;max-width:900px;margin:18px 0">
                <div style="font-size:12px;text-transform:uppercase;color:#646970;font-weight:600">Scheduler heartbeat</div>
                <div style="font-size:30px;font-weight:700;margin-top:4px"><?php echo esc_html($label); ?></div>
                <p style="margin-bottom:0"><?php echo esc_html($detail); ?></p>
            </div>

            <table class="widefat striped" style="max-width:900px">
                <tbody>
                    <tr><td>Heartbeat interval</td><td><strong>15 minutes</strong></td></tr>
                    <tr><td>Healthy threshold</td><td>45 minutes</td></tr>
                    <tr><td>Last heartbeat</td><td><?php echo esc_html((string)($heartbeat['last_at'] ?? 'Never')); ?></td></tr>
                    <tr><td>Heartbeat count</td><td><?php echo absint($heartbeat['count'] ?? 0); ?></td></tr>
                    <tr><td>Next WordPress event</td><td><?php echo $next ? esc_html(wp_date('Y-m-d g:i A', $next)) : 'Not scheduled'; ?></td></tr>
                    <tr><td>DISABLE_WP_CRON</td><td><strong><?php echo $wp_cron_disabled ? 'Enabled' : 'Not enabled'; ?></strong></td></tr>
                </tbody>
            </table>

            <h2 style="margin-top:24px">Cloudways server cron</h2>
            <p>For reliable scheduling, add a Cloudways cron job that runs WordPress due events every 5 minutes. Use the site's real application path shown by <code>pwd</code>.</p>
            <pre style="background:#1d2327;color:#f0f0f1;padding:14px;border-radius:8px;max-width:900px;overflow:auto">cd /YOUR/REAL/PUBLIC_HTML/PATH &amp;&amp; wp cron event run --due-now --quiet</pre>
            <p class="description">A 5-minute server trigger is inexpensive; the heartbeat itself runs every 15 minutes, while Town Monitor still follows its own daily/weekly schedule.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:18px">
                <input type="hidden" name="action" value="tng_cron_heartbeat_test">
                <?php wp_nonce_field(self::NONCE); ?>
                <button class="button">Record a test heartbeat</button>
                <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=tng-content-studio-overview')); ?>">Operations Overview</a>
            </form>

            <div class="notice notice-info inline" style="margin:20px 0 0;max-width:860px"><p><strong>Important:</strong> The manual test only proves this module can write its heartbeat. After configuring Cloudways, wait about 15–20 minutes and return here. A fresh automatic heartbeat is the real reliability check.</p></div>
        </div>
        <?php
    }
}
