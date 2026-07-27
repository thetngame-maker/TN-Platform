<?php
namespace TNG_OS\Modules\Concerts;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Concert_Source_Manager implements Module_Interface {
    private const SOURCE_TYPE = 'tng_concert_source';
    private const VENUE_TYPE = 'tng_venue';
    private const QUEUE_TYPE = 'tng_concert_import';
    private const CRON = 'tng_os_concert_sync';

    public function id(): string { return 'concert_source_manager'; }

    public function register(Container $container): void {
        $container->set('concert_source_manager', $this);
        add_action('admin_menu', [$this, 'menu'], 23);
        add_action('admin_post_tng_concert_manager_save', [$this, 'save']);
        add_filter('cron_schedules', [$this, 'schedules']);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page(
            'tn-game-os',
            'Concert Manager',
            'Concert Manager',
            'manage_options',
            'tng-concert-manager',
            [$this, 'page']
        );
    }

    public function schedules(array $schedules): array {
        $schedules['tng_hourly'] = ['interval' => HOUR_IN_SECONDS, 'display' => 'Hourly'];
        $schedules['tng_daily'] = ['interval' => DAY_IN_SECONDS, 'display' => 'Daily'];
        return $schedules;
    }

    public function save(): void {
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        check_admin_referer('tng_concert_manager_save');

        $endpoint = isset($_POST['api_endpoint']) ? untrailingslashit(esc_url_raw(wp_unslash($_POST['api_endpoint']))) : '';
        update_option('tng_ci_api_endpoint', $endpoint, false);
        if (isset($_POST['api_key']) && trim((string)$_POST['api_key']) !== '') {
            update_option('tng_ci_api_key', sanitize_text_field(wp_unslash($_POST['api_key'])), false);
        }

        $source_id = isset($_POST['source_id']) ? absint($_POST['source_id']) : 0;
        $source_title = isset($_POST['source_title']) ? sanitize_text_field(wp_unslash($_POST['source_title'])) : 'The Caverns — Tixr';
        $source_url = isset($_POST['source_url']) ? esc_url_raw(wp_unslash($_POST['source_url'])) : '';
        $venue_id = isset($_POST['venue_id']) ? absint($_POST['venue_id']) : 0;

        if (!$source_id && $source_url) {
            $source_id = wp_insert_post([
                'post_type' => self::SOURCE_TYPE,
                'post_status' => 'publish',
                'post_title' => $source_title ?: 'Concert Source',
            ]);
            if (is_wp_error($source_id)) $source_id = 0;
        } elseif ($source_id) {
            wp_update_post(['ID' => $source_id, 'post_title' => $source_title ?: get_the_title($source_id)]);
        }

        if ($source_id) {
            update_post_meta($source_id, '_tng_ci_provider', 'tixr');
            update_post_meta($source_id, '_tng_ci_source_url', $source_url);
            update_post_meta($source_id, '_tng_ci_venue_id', $venue_id);
            update_post_meta($source_id, '_tng_ci_enabled', isset($_POST['enabled']) ? '1' : '0');
            update_post_meta($source_id, '_tng_ci_auto_import', isset($_POST['auto_import']) ? '1' : '0');
        }

        $frequency = isset($_POST['frequency']) ? sanitize_key(wp_unslash($_POST['frequency'])) : 'tng_six_hours';
        if (!in_array($frequency, ['tng_hourly', 'tng_six_hours', 'tng_daily'], true)) $frequency = 'tng_six_hours';
        update_option('tng_ci_sync_frequency', $frequency, false);
        wp_clear_scheduled_hook(self::CRON);
        wp_schedule_event(time() + 300, $frequency, self::CRON);

        wp_safe_redirect(add_query_arg(['page' => 'tng-concert-manager', 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    public function page(): void {
        if (!current_user_can('manage_options')) return;

        $sources = get_posts(['post_type' => self::SOURCE_TYPE, 'post_status' => ['publish','draft'], 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC']);
        $venues = get_posts(['post_type' => self::VENUE_TYPE, 'post_status' => ['publish','draft'], 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC']);
        $selected = $sources ? $sources[0] : null;
        $source_id = $selected ? (int)$selected->ID : 0;
        $queue_new = $this->count_posts(self::QUEUE_TYPE, 'pending', '_tng_ci_queue_status', 'new');
        $queue_imported = $this->count_posts(self::QUEUE_TYPE, ['publish','pending'], '_tng_ci_queue_status', 'imported');
        $generated = $this->generated_concerts();
        $published = count(array_filter($generated, static fn($post) => $post->post_status === 'publish'));
        $endpoint = (string)get_option('tng_ci_api_endpoint', '');
        $has_key = (string)get_option('tng_ci_api_key', '') !== '';
        $frequency = (string)get_option('tng_ci_sync_frequency', 'tng_six_hours');
        $next_sync = wp_next_scheduled(self::CRON);
        ?>
        <div class="wrap tng-concert-manager">
            <style>
                .tng-concert-manager{max-width:1280px}.tng-cm-hero{background:linear-gradient(135deg,#18213d,#633b78);color:#fff;border-radius:22px;padding:28px 30px;margin:18px 0}.tng-cm-hero span{color:#f6bd3b;font-weight:900;letter-spacing:.13em;font-size:12px}.tng-cm-hero h1{color:#fff;margin:8px 0;font-size:30px}.tng-cm-hero p{margin:0;color:rgba(255,255,255,.8)}.tng-cm-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:16px 0}.tng-cm-stat,.tng-cm-panel{background:#fff;border:1px solid #dfe3e8;border-radius:16px;padding:18px}.tng-cm-stat strong{display:block;font-size:28px;color:#18213d;margin-top:5px}.tng-cm-layout{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(360px,.8fr);gap:18px}.tng-cm-panel h2{margin-top:0}.tng-cm-fields{display:grid;grid-template-columns:1fr 1fr;gap:14px}.tng-cm-fields label{display:flex;flex-direction:column;gap:6px;font-weight:700}.tng-cm-fields .wide{grid-column:1/-1}.tng-cm-fields input,.tng-cm-fields select{width:100%}.tng-cm-checks{display:flex;gap:20px;flex-wrap:wrap;margin:15px 0}.tng-cm-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:15px}.tng-cm-status{display:grid;gap:10px}.tng-cm-status div{display:flex;justify-content:space-between;gap:12px;padding:11px 0;border-bottom:1px solid #edf0f3}.tng-cm-good{color:#067647;font-weight:800}.tng-cm-warn{color:#b54708;font-weight:800}.tng-cm-table{width:100%;border-collapse:collapse}.tng-cm-table th,.tng-cm-table td{text-align:left;padding:11px 9px;border-bottom:1px solid #edf0f3}.tng-cm-pill{display:inline-block;border-radius:999px;background:#ecfdf3;color:#067647;padding:4px 8px;font-size:11px;font-weight:800}.tng-cm-pill.draft{background:#fff4e5;color:#b54708}@media(max-width:900px){.tng-cm-grid{grid-template-columns:1fr 1fr}.tng-cm-layout{grid-template-columns:1fr}}@media(max-width:600px){.tng-cm-grid,.tng-cm-fields{grid-template-columns:1fr}.tng-cm-fields .wide{grid-column:auto}}
            </style>
            <header class="tng-cm-hero"><span>TN GAME OS · CONTENT OPERATIONS</span><h1>Concert Manager</h1><p>Configure concert sources, control imports, review generated events, and monitor provider health from one place.</p></header>
            <?php if (isset($_GET['updated'])): ?><div class="notice notice-success"><p>Concert settings saved.</p></div><?php endif; ?>
            <section class="tng-cm-grid">
                <div class="tng-cm-stat">Sources<strong><?php echo esc_html((string)count($sources)); ?></strong></div>
                <div class="tng-cm-stat">Awaiting review<strong><?php echo esc_html((string)$queue_new); ?></strong></div>
                <div class="tng-cm-stat">Generated concerts<strong><?php echo esc_html((string)count($generated)); ?></strong></div>
                <div class="tng-cm-stat">Published<strong><?php echo esc_html((string)$published); ?></strong></div>
            </section>
            <div class="tng-cm-layout">
                <section class="tng-cm-panel">
                    <h2>Source configuration</h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="tng_concert_manager_save">
                        <input type="hidden" name="source_id" value="<?php echo esc_attr((string)$source_id); ?>">
                        <?php wp_nonce_field('tng_concert_manager_save'); ?>
                        <div class="tng-cm-fields">
                            <label><span>Provider</span><select disabled><option>Tixr</option></select></label>
                            <label><span>Venue defaults</span><select name="venue_id"><option value="0">— Select venue —</option><?php foreach ($venues as $venue): ?><option value="<?php echo (int)$venue->ID; ?>" <?php selected($source_id ? absint(get_post_meta($source_id,'_tng_ci_venue_id',true)) : 0, $venue->ID); ?>><?php echo esc_html($venue->post_title); ?></option><?php endforeach; ?></select></label>
                            <label class="wide"><span>Source name</span><input name="source_title" value="<?php echo esc_attr($selected ? $selected->post_title : 'The Caverns — Tixr'); ?>"></label>
                            <label class="wide"><span>Tixr group or events URL</span><input type="url" name="source_url" value="<?php echo esc_attr($source_id ? (string)get_post_meta($source_id,'_tng_ci_source_url',true) : ''); ?>" placeholder="https://www.tixr.com/groups/thecaverns"></label>
                            <label class="wide"><span>Concert API base URL</span><input type="url" name="api_endpoint" value="<?php echo esc_attr($endpoint); ?>" placeholder="https://concert-api.example.com"></label>
                            <label><span>API key</span><input type="password" name="api_key" value="" placeholder="<?php echo $has_key ? 'Saved — leave blank to keep' : 'Required'; ?>"></label>
                            <label><span>Import schedule</span><select name="frequency"><option value="tng_hourly" <?php selected($frequency,'tng_hourly'); ?>>Hourly</option><option value="tng_six_hours" <?php selected($frequency,'tng_six_hours'); ?>>Every six hours</option><option value="tng_daily" <?php selected($frequency,'tng_daily'); ?>>Daily</option></select></label>
                        </div>
                        <div class="tng-cm-checks"><label><input type="checkbox" name="enabled" value="1" <?php checked(!$source_id || get_post_meta($source_id,'_tng_ci_enabled',true) !== '0'); ?>> Enable scheduled sync</label><label><input type="checkbox" name="auto_import" value="1" <?php checked($source_id && get_post_meta($source_id,'_tng_ci_auto_import',true) === '1'); ?>> Automatically publish new concerts</label></div>
                        <p class="description">Keep automatic publishing disabled until the source has completed several clean test imports.</p>
                        <div class="tng-cm-actions"><button class="button button-primary">Save concert settings</button><?php if ($source_id): ?><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tng_concert_sync_source&source_id='.$source_id),'tng_concert_sync_'.$source_id)); ?>">Run source now</a><?php endif; ?><a class="button" href="<?php echo esc_url(admin_url('post-new.php?post_type='.self::SOURCE_TYPE)); ?>">Add another source</a></div>
                    </form>
                </section>
                <aside class="tng-cm-panel">
                    <h2>System status</h2>
                    <div class="tng-cm-status">
                        <div><span>API endpoint</span><strong class="<?php echo $endpoint ? 'tng-cm-good' : 'tng-cm-warn'; ?>"><?php echo $endpoint ? 'Configured' : 'Missing'; ?></strong></div>
                        <div><span>API key</span><strong class="<?php echo $has_key ? 'tng-cm-good' : 'tng-cm-warn'; ?>"><?php echo $has_key ? 'Configured' : 'Missing'; ?></strong></div>
                        <div><span>Next scheduled sync</span><strong><?php echo $next_sync ? esc_html(wp_date('M j, g:i a', $next_sync)) : 'Not scheduled'; ?></strong></div>
                        <div><span>Imported queue items</span><strong><?php echo esc_html((string)$queue_imported); ?></strong></div>
                    </div>
                    <div class="tng-cm-actions"><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-concert-api-settings')); ?>">Advanced API settings</a><a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type='.self::QUEUE_TYPE)); ?>">Review import queue</a><a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type='.self::VENUE_TYPE)); ?>">Manage venues</a></div>
                </aside>
            </div>
            <section class="tng-cm-panel" style="margin-top:18px">
                <h2>Generated concerts</h2>
                <p>These Traveler activities were created or updated by Concert Intelligence.</p>
                <table class="tng-cm-table"><thead><tr><th>Concert</th><th>Date</th><th>Venue</th><th>Status</th><th>Last sync</th><th></th></tr></thead><tbody>
                <?php foreach (array_slice($generated, 0, 20) as $concert): ?>
                    <tr><td><strong><?php echo esc_html($concert->post_title); ?></strong></td><td><?php echo esc_html((string)get_post_meta($concert->ID,'_tng_trip_date',true)); ?></td><td><?php echo esc_html((string)get_post_meta($concert->ID,'_tng_trip_venue',true)); ?></td><td><span class="tng-cm-pill <?php echo $concert->post_status === 'publish' ? '' : 'draft'; ?>"><?php echo esc_html(ucfirst($concert->post_status)); ?></span></td><td><?php echo esc_html((string)get_post_meta($concert->ID,'_tng_source_last_sync',true)); ?></td><td><a class="button button-small" href="<?php echo esc_url(get_edit_post_link($concert->ID)); ?>">Edit</a></td></tr>
                <?php endforeach; if (!$generated): ?><tr><td colspan="6">No generated concerts yet. Configure the source and run a sync.</td></tr><?php endif; ?>
                </tbody></table>
            </section>
        </div>
        <?php
    }

    private function generated_concerts(): array {
        return get_posts([
            'post_type' => 'st_activity',
            'post_status' => ['publish','draft','pending','private'],
            'posts_per_page' => 100,
            'orderby' => 'meta_value',
            'meta_key' => '_tng_trip_date',
            'order' => 'DESC',
            'meta_query' => [[
                'key' => '_tng_source_provider',
                'compare' => 'EXISTS',
            ]],
        ]);
    }

    private function count_posts(string $post_type, $status, string $meta_key, string $meta_value): int {
        $query = new \WP_Query([
            'post_type' => $post_type,
            'post_status' => $status,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => $meta_key,
            'meta_value' => $meta_value,
            'no_found_rows' => false,
        ]);
        return (int)$query->found_posts;
    }
}
