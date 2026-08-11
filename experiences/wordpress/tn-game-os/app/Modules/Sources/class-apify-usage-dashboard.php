<?php
namespace TNG_OS\Modules\Sources;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Apify_Usage_Dashboard implements Module_Interface {
    private const HISTORY_OPTION = 'tng_town_scanner_history_v1';
    private const USAGE_OPTION = 'tng_apify_usage_history_v1';
    private const SETTINGS_OPTION = 'tng_apify_usage_settings_v1';
    private const NONCE = 'tng_apify_usage_settings';
    private const MAX_RECORDS = 500;

    public function id(): string { return 'apify_usage_dashboard'; }

    public function register(Container $container): void {
        add_action('admin_menu', [$this, 'admin_menu'], 31);
        add_action('admin_post_tng_apify_usage_save', [$this, 'save_action']);
        add_action('updated_option', [$this, 'updated_option'], 10, 3);
        $container->set('apify_usage_dashboard', $this);
    }

    public function boot(Container $container): void {
        $history = get_option(self::HISTORY_OPTION, []);
        if (is_array($history)) $this->ingest_history($history);
    }

    public function admin_menu(): void {
        add_submenu_page(
            'tng-content-studio',
            'Apify Usage',
            'Apify Usage',
            'edit_posts',
            'tng-apify-usage',
            [$this, 'render_page']
        );
    }

    public function updated_option(string $option, $old_value, $value): void {
        if ($option !== self::HISTORY_OPTION || !is_array($value)) return;
        $this->ingest_history($value);
    }

    private function settings(): array {
        $saved = get_option(self::SETTINGS_OPTION, []);
        return wp_parse_args(is_array($saved) ? $saved : [], [
            'cost_per_1000' => 0,
        ]);
    }

    private function usage(): array {
        $rows = get_option(self::USAGE_OPTION, []);
        return is_array($rows) ? $rows : [];
    }

    private function record_key(string $town, array $scan): string {
        return md5(strtolower(trim($town)) . '|' . (string)($scan['scanned_at'] ?? '') . '|' . absint($scan['total'] ?? 0) . '|' . (string)($scan['source'] ?? 'scan'));
    }

    private function ingest_history(array $history): void {
        $existing = $this->usage();
        $by_key = [];
        foreach ($existing as $row) {
            if (!is_array($row) || empty($row['key'])) continue;
            $by_key[(string)$row['key']] = $row;
        }

        $changed = false;
        foreach ($history as $town_history) {
            if (!is_array($town_history)) continue;
            $town = sanitize_text_field((string)($town_history['town'] ?? 'Unknown town'));
            foreach ((array)($town_history['scans'] ?? []) as $scan) {
                if (!is_array($scan) || empty($scan['scanned_at'])) continue;
                $key = $this->record_key($town, $scan);
                if (isset($by_key[$key])) continue;

                $types = array_values(array_filter(array_map('sanitize_key', (array)($scan['types'] ?? []))));
                $counts = is_array($scan['counts'] ?? null) ? $scan['counts'] : [];
                $by_key[$key] = [
                    'key' => $key,
                    'time' => sanitize_text_field((string)$scan['scanned_at']),
                    'town' => $town,
                    'source' => sanitize_key((string)($scan['source'] ?? 'scan')),
                    'total' => absint($scan['total'] ?? 0),
                    'types' => $types,
                    'signals' => array_sum(array_map('absint', $counts)),
                ];
                $changed = true;
            }
        }

        if (!$changed && count($existing) === count($by_key)) return;

        $rows = array_values($by_key);
        usort($rows, static function (array $a, array $b): int {
            return (strtotime((string)($b['time'] ?? '')) ?: 0) <=> (strtotime((string)($a['time'] ?? '')) ?: 0);
        });
        update_option(self::USAGE_OPTION, array_slice($rows, 0, self::MAX_RECORDS), false);
    }

    public function save_action(): void {
        if (!current_user_can('edit_posts')) wp_die('You do not have permission to manage Apify Usage settings.');
        check_admin_referer(self::NONCE);
        $rate = isset($_POST['cost_per_1000']) ? (float)wp_unslash($_POST['cost_per_1000']) : 0;
        $rate = max(0, min(1000, $rate));
        update_option(self::SETTINGS_OPTION, ['cost_per_1000' => $rate], false);
        wp_safe_redirect(add_query_arg(['page'=>'tng-apify-usage','tng_notice'=>'Usage settings saved.'], admin_url('admin.php')));
        exit;
    }

    private function window_stats(array $rows, int $days): array {
        $cutoff = time() - ($days * DAY_IN_SECONDS);
        $runs = 0; $places = 0; $signals = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $ts = strtotime((string)($row['time'] ?? '')) ?: 0;
            if ($ts < $cutoff) continue;
            $runs++;
            $places += absint($row['total'] ?? 0);
            $signals += absint($row['signals'] ?? 0);
        }
        return ['runs'=>$runs,'places'=>$places,'signals'=>$signals];
    }

    private function town_stats(array $rows, int $days=30): array {
        $cutoff = time() - ($days * DAY_IN_SECONDS);
        $stats = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $ts = strtotime((string)($row['time'] ?? '')) ?: 0;
            if ($ts < $cutoff) continue;
            $town = (string)($row['town'] ?? 'Unknown town');
            if (!isset($stats[$town])) $stats[$town] = ['runs'=>0,'places'=>0,'signals'=>0,'latest'=>''];
            $stats[$town]['runs']++;
            $stats[$town]['places'] += absint($row['total'] ?? 0);
            $stats[$town]['signals'] += absint($row['signals'] ?? 0);
            if ($stats[$town]['latest'] === '' || $ts > (strtotime($stats[$town]['latest']) ?: 0)) $stats[$town]['latest'] = (string)($row['time'] ?? '');
        }
        uasort($stats, static fn(array $a, array $b): int => $b['places'] <=> $a['places']);
        return $stats;
    }

    private function estimate(int $places, float $rate): string {
        if ($rate <= 0) return 'Not configured';
        return '$' . number_format(($places / 1000) * $rate, 2);
    }

    private function card(string $label, string $value, string $detail=''): void {
        ?>
        <div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;min-width:180px;flex:1">
            <div style="font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#646970;font-weight:600"><?php echo esc_html($label); ?></div>
            <div style="font-size:28px;font-weight:700;line-height:1.2;margin-top:6px"><?php echo esc_html($value); ?></div>
            <?php if ($detail !== ''): ?><div style="margin-top:6px;color:#646970"><?php echo esc_html($detail); ?></div><?php endif; ?>
        </div>
        <?php
    }

    public function render_page(): void {
        if (!current_user_can('edit_posts')) return;
        $rows = $this->usage();
        $settings = $this->settings();
        $rate = (float)($settings['cost_per_1000'] ?? 0);
        $seven = $this->window_stats($rows, 7);
        $thirty = $this->window_stats($rows, 30);
        $towns = $this->town_stats($rows, 30);
        $notice = sanitize_text_field(wp_unslash($_GET['tng_notice'] ?? ''));
        ?>
        <div class="wrap">
            <h1>📈 Apify Usage</h1>
            <p>Track Town Scanner and Town Monitoring volume. Dollar amounts are estimates based on the rate you enter below, not Apify billing records.</p>
            <?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>

            <div style="display:flex;gap:12px;flex-wrap:wrap;margin:20px 0">
                <?php $this->card('Runs · 7 days',(string)$seven['runs'],number_format_i18n($seven['places']).' places processed'); ?>
                <?php $this->card('Runs · 30 days',(string)$thirty['runs'],number_format_i18n($thirty['places']).' places processed'); ?>
                <?php $this->card('Estimated · 7 days',$this->estimate($seven['places'],$rate),$rate > 0 ? '$'.number_format($rate,2).' / 1,000 results' : 'Set an estimate below'); ?>
                <?php $this->card('Estimated · 30 days',$this->estimate($thirty['places'],$rate),number_format_i18n($thirty['signals']).' change signals'); ?>
            </div>

            <div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px;max-width:900px;margin-bottom:24px">
                <h2 style="margin-top:0">Cost estimate settings</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="tng_apify_usage_save">
                    <?php wp_nonce_field(self::NONCE); ?>
                    <label><strong>Estimated cost per 1,000 place results</strong><br>
                        $ <input type="number" name="cost_per_1000" min="0" max="1000" step="0.01" value="<?php echo esc_attr(number_format($rate,2,'.','')); ?>" style="width:110px">
                    </label>
                    <p class="description">Optional. Use this only for budgeting. It does not change Apify settings or claim to reflect your exact invoice.</p>
                    <?php submit_button('Save Estimate', 'secondary', 'submit', false); ?>
                </form>
            </div>

            <h2>Town volume · last 30 days</h2>
            <?php if (!$towns): ?>
                <p>No scan history has been recorded yet.</p>
            <?php else: ?>
                <table class="widefat striped" style="max-width:1000px">
                    <thead><tr><th>Town</th><th>Runs</th><th>Places</th><th>Change signals</th><th>Estimated cost</th><th>Latest</th></tr></thead>
                    <tbody>
                    <?php foreach ($towns as $town=>$stat): ?>
                        <tr>
                            <td><strong><?php echo esc_html($town); ?></strong></td>
                            <td><?php echo absint($stat['runs']); ?></td>
                            <td><?php echo number_format_i18n(absint($stat['places'])); ?></td>
                            <td><?php echo number_format_i18n(absint($stat['signals'])); ?></td>
                            <td><?php echo esc_html($this->estimate(absint($stat['places']),$rate)); ?></td>
                            <td><?php echo esc_html((string)$stat['latest']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2 style="margin-top:26px">Recent scan usage</h2>
            <?php if (!$rows): ?>
                <p>No usage records yet.</p>
            <?php else: ?>
                <table class="widefat striped" style="max-width:1100px">
                    <thead><tr><th>Time</th><th>Town</th><th>Source</th><th>Places</th><th>Categories</th><th>Signals</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($rows,0,30) as $row): ?>
                        <tr>
                            <td><?php echo esc_html((string)($row['time'] ?? '')); ?></td>
                            <td><?php echo esc_html((string)($row['town'] ?? '')); ?></td>
                            <td><?php echo esc_html((string)($row['source'] ?? 'scan')); ?></td>
                            <td><?php echo number_format_i18n(absint($row['total'] ?? 0)); ?></td>
                            <td><?php echo esc_html(implode(', ', (array)($row['types'] ?? []))); ?></td>
                            <td><?php echo number_format_i18n(absint($row['signals'] ?? 0)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
