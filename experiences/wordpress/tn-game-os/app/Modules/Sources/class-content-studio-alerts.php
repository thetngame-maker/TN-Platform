<?php
namespace TNG_OS\Modules\Sources;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Content_Studio_Alerts implements Module_Interface {
    private const USAGE_OPTION = 'tng_apify_usage_history_v1';
    private const BUDGET_OPTION = 'tng_apify_budget_settings_v1';
    private const HEARTBEAT_OPTION = 'tng_server_cron_heartbeat_v1';
    private const MONITOR_LOG_OPTION = 'tng_town_monitor_log_v1';
    private const SCAN_HISTORY_OPTION = 'tng_town_scanner_history_v1';

    public function id(): string { return 'content_studio_alerts'; }

    public function register(Container $container): void {
        add_action('admin_menu', [$this, 'admin_menu'], 33);
        add_action('admin_notices', [$this, 'admin_notices']);
        $container->set('content_studio_alerts', $this);
    }

    public function boot(Container $container): void {}

    public function admin_menu(): void {
        add_submenu_page(
            'tng-content-studio',
            'Alerts',
            'Alerts',
            'edit_posts',
            'tng-content-studio-alerts',
            [$this, 'render_page']
        );
    }

    private function is_content_studio_page(): bool {
        $page = sanitize_key((string)($_GET['page'] ?? ''));
        return $page !== '' && strpos($page, 'tng-') === 0;
    }

    private function month_usage(): int {
        $rows = get_option(self::USAGE_OPTION, []);
        if (!is_array($rows)) return 0;
        $month = wp_date('Y-m');
        $places = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $ts = strtotime((string)($row['time'] ?? '')) ?: 0;
            if (!$ts || wp_date('Y-m', $ts) !== $month) continue;
            $places += absint($row['total'] ?? 0);
        }
        return $places;
    }

    private function budget_alert(): ?array {
        $settings = get_option(self::BUDGET_OPTION, []);
        if (!is_array($settings) || !$settings) return null;
        $budget = absint($settings['monthly_result_budget'] ?? 0);
        if (!$budget) return null;
        $used = $this->month_usage();
        $pct = ($used / $budget) * 100;
        if ($pct < 50) return null;
        $level = $pct >= 100 ? 'error' : ($pct >= 90 ? 'warning' : 'info');
        return [$level, sprintf('Apify result budget is at %.0f%% (%s of %s results this month).', $pct, number_format_i18n($used), number_format_i18n($budget)), admin_url('admin.php?page=tng-apify-budget')];
    }

    private function cron_alert(): ?array {
        $heartbeat = get_option(self::HEARTBEAT_OPTION, []);
        $heartbeat = is_array($heartbeat) ? $heartbeat : [];
        $last = absint($heartbeat['last_timestamp'] ?? 0);
        if (!$last) return ['warning', 'No automatic scheduler heartbeat has been recorded yet.', admin_url('admin.php?page=tng-cron-reliability')];
        $age = time() - $last;
        if ($age <= 45 * MINUTE_IN_SECONDS) return null;
        return [$age > 2 * HOUR_IN_SECONDS ? 'error' : 'warning', 'Scheduler heartbeat is ' . human_time_diff($last, time()) . ' old.', admin_url('admin.php?page=tng-cron-reliability')];
    }

    private function recent_failures(): int {
        $log = get_option(self::MONITOR_LOG_OPTION, []);
        if (!is_array($log)) return 0;
        $cutoff = time() - DAY_IN_SECONDS;
        $count = 0;
        foreach ($log as $row) {
            if (!is_array($row) || (string)($row['status'] ?? '') !== 'error') continue;
            $ts = strtotime((string)($row['time'] ?? '')) ?: 0;
            if ($ts >= $cutoff) $count++;
        }
        return $count;
    }

    private function possible_closures(): int {
        $history = get_option(self::SCAN_HISTORY_OPTION, []);
        if (!is_array($history)) return 0;
        $count = 0;
        foreach ($history as $town) {
            if (!is_array($town)) continue;
            foreach ((array)($town['snapshot'] ?? []) as $item) {
                if (is_array($item) && (string)($item['change_status'] ?? '') === 'possibly_closed') $count++;
            }
        }
        return $count;
    }

    public function alerts(): array {
        $alerts = [];
        if ($budget = $this->budget_alert()) $alerts[] = $budget;
        if ($cron = $this->cron_alert()) $alerts[] = $cron;
        $failures = $this->recent_failures();
        if ($failures) $alerts[] = ['error', sprintf('%d Town Monitoring failure%s recorded in the last 24 hours.', $failures, $failures === 1 ? '' : 's'), admin_url('admin.php?page=tng-town-monitor')];
        $closures = $this->possible_closures();
        if ($closures) $alerts[] = ['warning', sprintf('%d place%s currently flagged as possibly closed.', $closures, $closures === 1 ? '' : 's'), admin_url('admin.php?page=tng-town-changes&change=possibly_closed')];
        return $alerts;
    }

    public function admin_notices(): void {
        if (!current_user_can('edit_posts') || !$this->is_content_studio_page()) return;
        foreach (array_slice($this->alerts(), 0, 4) as [$level, $message, $url]) {
            $class = $level === 'error' ? 'notice-error' : ($level === 'warning' ? 'notice-warning' : 'notice-info');
            echo '<div class="notice ' . esc_attr($class) . '"><p><strong>Content Studio:</strong> ' . esc_html($message) . ' <a href="' . esc_url($url) . '">Review →</a></p></div>';
        }
    }

    public function render_page(): void {
        if (!current_user_can('edit_posts')) return;
        $alerts = $this->alerts();
        ?>
        <div class="wrap">
            <h1>🔔 Content Studio Alerts</h1>
            <p>Operational warnings from scheduled monitoring, scan history, cron health, and Apify usage safeguards.</p>
            <?php if (!$alerts): ?>
                <div class="notice notice-success inline"><p><strong>All clear.</strong> No operational alerts are active.</p></div>
            <?php else: ?>
                <div style="display:grid;gap:12px;max-width:950px;margin-top:18px">
                    <?php foreach ($alerts as [$level, $message, $url]): ?>
                        <div style="background:#fff;border:1px solid #dcdcde;border-left:4px solid <?php echo $level === 'error' ? '#b32d2e' : ($level === 'warning' ? '#dba617' : '#2271b1'); ?>;padding:16px;border-radius:8px">
                            <strong><?php echo esc_html($message); ?></strong><br>
                            <a href="<?php echo esc_url($url); ?>">Review details →</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
