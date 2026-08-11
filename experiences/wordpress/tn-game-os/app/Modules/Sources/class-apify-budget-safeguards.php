<?php
namespace TNG_OS\Modules\Sources;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Apify_Budget_Safeguards implements Module_Interface {
    private const SETTINGS_OPTION = 'tng_apify_budget_settings_v1';
    private const STATE_OPTION = 'tng_apify_budget_state_v1';
    private const USAGE_OPTION = 'tng_apify_usage_history_v1';
    private const MONITOR_OPTION = 'tng_town_monitor_settings_v1';
    private const NONCE = 'tng_apify_budget_settings';

    public function id(): string { return 'apify_budget_safeguards'; }

    public function register(Container $container): void {
        add_action('admin_menu', [$this, 'admin_menu'], 32);
        add_action('admin_post_tng_apify_budget_save', [$this, 'save_action']);
        add_action('updated_option', [$this, 'updated_option'], 20, 3);
        add_filter('pre_update_option_' . self::MONITOR_OPTION, [$this, 'cap_monitor_settings'], 20, 2);
        $container->set('apify_budget_safeguards', $this);
    }

    public function boot(Container $container): void {
        $this->maybe_roll_month();
        $this->evaluate_budget();
    }

    public function admin_menu(): void {
        add_submenu_page(
            'tng-content-studio',
            'Usage Safeguards',
            'Usage Safeguards',
            'edit_posts',
            'tng-apify-budget',
            [$this, 'render_page']
        );
    }

    private function defaults(): array {
        return [
            'monthly_result_budget' => 10000,
            'per_town_cap' => 100,
            'warn_50' => 1,
            'warn_75' => 1,
            'warn_90' => 1,
            'auto_pause' => 1,
        ];
    }

    private function settings(): array {
        $saved = get_option(self::SETTINGS_OPTION, []);
        return wp_parse_args(is_array($saved) ? $saved : [], $this->defaults());
    }

    private function state(): array {
        $saved = get_option(self::STATE_OPTION, []);
        return wp_parse_args(is_array($saved) ? $saved : [], [
            'month' => wp_date('Y-m'),
            'paused_by_budget' => 0,
            'paused_at' => '',
            'last_level' => 0,
        ]);
    }

    private function month_key(): string { return wp_date('Y-m'); }

    private function month_usage(): int {
        $rows = get_option(self::USAGE_OPTION, []);
        if (!is_array($rows)) return 0;
        $month = $this->month_key();
        $places = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $time = (string)($row['time'] ?? '');
            $ts = strtotime($time) ?: 0;
            if (!$ts || wp_date('Y-m', $ts) !== $month) continue;
            $places += absint($row['total'] ?? 0);
        }
        return $places;
    }

    private function level(int $used, int $budget): int {
        if ($budget <= 0) return 0;
        $pct = ($used / $budget) * 100;
        if ($pct >= 100) return 100;
        if ($pct >= 90) return 90;
        if ($pct >= 75) return 75;
        if ($pct >= 50) return 50;
        return 0;
    }

    public function cap_monitor_settings($new_value, $old_value) {
        if (!is_array($new_value)) return $new_value;
        $settings = $this->settings();
        $cap = max(5, min(100, absint($settings['per_town_cap'] ?? 100)));
        if (isset($new_value['max_items'])) {
            $new_value['max_items'] = min($cap, max(5, absint($new_value['max_items'])));
        }
        return $new_value;
    }

    public function updated_option(string $option, $old_value, $value): void {
        if ($option === self::USAGE_OPTION) {
            $this->evaluate_budget();
        }
    }

    private function maybe_roll_month(): void {
        $state = $this->state();
        $month = $this->month_key();
        if (($state['month'] ?? '') === $month) return;

        if (!empty($state['paused_by_budget'])) {
            $monitor = get_option(self::MONITOR_OPTION, []);
            if (is_array($monitor)) {
                $monitor['enabled'] = 1;
                update_option(self::MONITOR_OPTION, $monitor, false);
            }
        }

        update_option(self::STATE_OPTION, [
            'month' => $month,
            'paused_by_budget' => 0,
            'paused_at' => '',
            'last_level' => 0,
        ], false);
    }

    private function evaluate_budget(): void {
        $settings = $this->settings();
        $budget = absint($settings['monthly_result_budget'] ?? 0);
        if ($budget <= 0) return;

        $used = $this->month_usage();
        $level = $this->level($used, $budget);
        $state = $this->state();
        $state['month'] = $this->month_key();
        $state['last_level'] = $level;

        if ($used >= $budget && !empty($settings['auto_pause']) && empty($state['paused_by_budget'])) {
            $monitor = get_option(self::MONITOR_OPTION, []);
            if (is_array($monitor) && !empty($monitor['enabled'])) {
                $monitor['enabled'] = 0;
                update_option(self::MONITOR_OPTION, $monitor, false);
                wp_clear_scheduled_hook('tng_town_monitor_cron');
                $state['paused_by_budget'] = 1;
                $state['paused_at'] = current_time('mysql');
            }
        }

        update_option(self::STATE_OPTION, $state, false);
    }

    public function save_action(): void {
        if (!current_user_can('edit_posts')) wp_die('You do not have permission to manage usage safeguards.');
        check_admin_referer(self::NONCE);

        $settings = [
            'monthly_result_budget' => max(0, min(1000000, absint($_POST['monthly_result_budget'] ?? 10000))),
            'per_town_cap' => max(5, min(100, absint($_POST['per_town_cap'] ?? 100))),
            'warn_50' => !empty($_POST['warn_50']) ? 1 : 0,
            'warn_75' => !empty($_POST['warn_75']) ? 1 : 0,
            'warn_90' => !empty($_POST['warn_90']) ? 1 : 0,
            'auto_pause' => !empty($_POST['auto_pause']) ? 1 : 0,
        ];
        update_option(self::SETTINGS_OPTION, $settings, false);

        // Immediately enforce the configured cap on Town Monitoring.
        $monitor = get_option(self::MONITOR_OPTION, []);
        if (is_array($monitor) && isset($monitor['max_items'])) {
            $monitor['max_items'] = min((int)$settings['per_town_cap'], max(5, absint($monitor['max_items'])));
            update_option(self::MONITOR_OPTION, $monitor, false);
        }

        $this->evaluate_budget();
        wp_safe_redirect(add_query_arg(['page'=>'tng-apify-budget','tng_notice'=>'Usage safeguards saved.'], admin_url('admin.php')));
        exit;
    }

    private function warning_message(int $level, int $used, int $budget, array $settings): string {
        if ($level === 100) return sprintf('Monthly budget reached: %s of %s results used.', number_format_i18n($used), number_format_i18n($budget));
        if ($level === 90 && !empty($settings['warn_90'])) return sprintf('90%% budget warning: %s of %s results used.', number_format_i18n($used), number_format_i18n($budget));
        if ($level === 75 && !empty($settings['warn_75'])) return sprintf('75%% budget warning: %s of %s results used.', number_format_i18n($used), number_format_i18n($budget));
        if ($level === 50 && !empty($settings['warn_50'])) return sprintf('50%% budget warning: %s of %s results used.', number_format_i18n($used), number_format_i18n($budget));
        return '';
    }

    public function render_page(): void {
        if (!current_user_can('edit_posts')) return;
        $settings = $this->settings();
        $state = $this->state();
        $used = $this->month_usage();
        $budget = absint($settings['monthly_result_budget'] ?? 0);
        $remaining = $budget > 0 ? max(0, $budget - $used) : 0;
        $pct = $budget > 0 ? min(100, ($used / $budget) * 100) : 0;
        $level = $this->level($used, $budget);
        $warning = $this->warning_message($level, $used, $budget, $settings);
        $notice = sanitize_text_field(wp_unslash($_GET['tng_notice'] ?? ''));
        ?>
        <div class="wrap">
            <h1>🛡️ Usage Safeguards</h1>
            <p>Budget and rate-limit scheduled Google Maps discovery without blocking intentional manual scans.</p>
            <?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <?php if ($warning): ?><div class="notice <?php echo $level >= 100 ? 'notice-error' : 'notice-warning'; ?> inline"><p><strong><?php echo esc_html($warning); ?></strong></p></div><?php endif; ?>
            <?php if (!empty($state['paused_by_budget'])): ?><div class="notice notice-error inline"><p><strong>Scheduled monitoring is paused by the monthly budget.</strong> It will be eligible for automatic restoration when the calendar month changes.</p></div><?php endif; ?>

            <div style="display:flex;gap:12px;flex-wrap:wrap;margin:20px 0;max-width:1000px">
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;min-width:200px;flex:1"><div style="font-size:12px;text-transform:uppercase;color:#646970;font-weight:600">Used this month</div><div style="font-size:28px;font-weight:700;margin-top:6px"><?php echo number_format_i18n($used); ?></div><div style="color:#646970;margin-top:6px"><?php echo number_format($pct,1); ?>% of configured budget</div></div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;min-width:200px;flex:1"><div style="font-size:12px;text-transform:uppercase;color:#646970;font-weight:600">Monthly budget</div><div style="font-size:28px;font-weight:700;margin-top:6px"><?php echo $budget ? number_format_i18n($budget) : 'Unlimited'; ?></div><div style="color:#646970;margin-top:6px">place results</div></div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;min-width:200px;flex:1"><div style="font-size:12px;text-transform:uppercase;color:#646970;font-weight:600">Remaining</div><div style="font-size:28px;font-weight:700;margin-top:6px"><?php echo $budget ? number_format_i18n($remaining) : '—'; ?></div><div style="color:#646970;margin-top:6px">before monthly ceiling</div></div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;min-width:200px;flex:1"><div style="font-size:12px;text-transform:uppercase;color:#646970;font-weight:600">Per-town cap</div><div style="font-size:28px;font-weight:700;margin-top:6px"><?php echo absint($settings['per_town_cap']); ?></div><div style="color:#646970;margin-top:6px">scheduled results / town</div></div>
            </div>

            <?php if ($budget > 0): ?>
                <div style="background:#dcdcde;border-radius:999px;height:18px;max-width:1000px;overflow:hidden;margin:0 0 22px"><div style="height:100%;width:<?php echo esc_attr(number_format($pct,2,'.','')); ?>%;background:<?php echo $pct>=100?'#b32d2e':($pct>=75?'#b26200':'#2271b1'); ?>"></div></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;max-width:1000px">
                <input type="hidden" name="action" value="tng_apify_budget_save">
                <?php wp_nonce_field(self::NONCE); ?>
                <table class="form-table">
                    <tr><th>Monthly result budget</th><td><input type="number" name="monthly_result_budget" min="0" max="1000000" step="100" value="<?php echo absint($settings['monthly_result_budget']); ?>"><p class="description">Total place results across manual and scheduled Town Scanner history. Set 0 for no ceiling.</p></td></tr>
                    <tr><th>Per-town scheduled cap</th><td><input type="number" name="per_town_cap" min="5" max="100" value="<?php echo absint($settings['per_town_cap']); ?>"><p class="description">Town Monitoring cannot request more than this many results per town.</p></td></tr>
                    <tr><th>Warnings</th><td><label style="margin-right:18px"><input type="checkbox" name="warn_50" value="1" <?php checked(!empty($settings['warn_50'])); ?>> 50%</label><label style="margin-right:18px"><input type="checkbox" name="warn_75" value="1" <?php checked(!empty($settings['warn_75'])); ?>> 75%</label><label><input type="checkbox" name="warn_90" value="1" <?php checked(!empty($settings['warn_90'])); ?>> 90%</label></td></tr>
                    <tr><th>Automatic safeguard</th><td><label><input type="checkbox" name="auto_pause" value="1" <?php checked(!empty($settings['auto_pause'])); ?>> Pause scheduled Town Monitoring when the monthly result budget is reached</label><p class="description">Manual Town Scanner runs remain available. A budget pause never deletes data or listings.</p></td></tr>
                </table>
                <?php submit_button('Save Safeguards'); ?>
            </form>

            <p style="margin-top:16px"><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-apify-usage')); ?>">Open Apify Usage</a> <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-town-monitor')); ?>">Town Monitoring</a></p>
        </div>
        <?php
    }
}
