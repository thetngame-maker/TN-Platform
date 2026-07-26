<?php
namespace TNG_OS\Modules\Entities;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class Player_Progress implements Module_Interface {
    private const TABLE_VERSION = '1';
    private const QUEST_TYPE = 'tng_quest';

    public function id(): string { return 'player_progress'; }

    public function register(Container $container): void {
        $container->set('player_progress', $this);
        add_action('init', [$this, 'ensure_schema'], 4);
        add_action('rest_api_init', [$this, 'routes']);
        add_action('admin_menu', [$this, 'menu'], 36);
    }

    public function boot(Container $container): void {}

    public function ensure_schema(): void {
        if (get_option('tng_player_progress_table_version') === self::TABLE_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $this->table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            quest_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'not_started',
            completed_stops longtext NULL,
            xp_earned bigint(20) unsigned NOT NULL DEFAULT 0,
            started_at datetime NULL,
            completed_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_quest (user_id, quest_id),
            KEY quest_id (quest_id),
            KEY status (status)
        ) {$charset};");
        update_option('tng_player_progress_table_version', self::TABLE_VERSION, false);
    }

    public function routes(): void {
        register_rest_route('tng-game/v1', '/quest-progress/(?P<quest_id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_progress'],
                'permission_callback' => static fn(): bool => is_user_logged_in(),
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'save_progress'],
                'permission_callback' => static fn(): bool => is_user_logged_in(),
                'args' => [
                    'quest_id' => ['sanitize_callback' => 'absint'],
                    'started' => ['type' => 'boolean'],
                    'completedStops' => ['type' => 'array'],
                ],
            ],
        ]);
    }

    public function get_progress(WP_REST_Request $request): WP_REST_Response {
        $quest_id = absint($request['quest_id']);
        if (!$this->valid_quest($quest_id)) return new WP_REST_Response(['message' => 'Quest not found.'], 404);
        $row = $this->row(get_current_user_id(), $quest_id);
        return new WP_REST_Response($this->payload($row, $quest_id), 200);
    }

    public function save_progress(WP_REST_Request $request): WP_REST_Response {
        $quest_id = absint($request['quest_id']);
        if (!$this->valid_quest($quest_id)) return new WP_REST_Response(['message' => 'Quest not found.'], 404);

        $user_id = get_current_user_id();
        $allowed = array_values(array_map('strval', (array)get_post_meta($quest_id, '_tng_quest_entity_ids', true)));
        $submitted = array_values(array_unique(array_map('sanitize_text_field', (array)$request->get_param('completedStops'))));
        $done = array_values(array_intersect($submitted, $allowed));
        $started = (bool)$request->get_param('started');
        $required = $this->required($quest_id, count($allowed));
        $complete = $started && count($done) >= $required && $required > 0;
        $xp = $this->earned_xp($quest_id, $done);
        $existing = $this->row($user_id, $quest_id);
        $now = current_time('mysql', true);

        $data = [
            'user_id' => $user_id,
            'quest_id' => $quest_id,
            'status' => $complete ? 'completed' : ($started ? 'in_progress' : 'not_started'),
            'completed_stops' => wp_json_encode($done),
            'xp_earned' => $xp,
            'started_at' => $started ? (($existing->started_at ?? null) ?: $now) : null,
            'completed_at' => $complete ? (($existing->completed_at ?? null) ?: $now) : null,
            'updated_at' => $now,
        ];

        global $wpdb;
        if ($existing) {
            $wpdb->update($this->table(), $data, ['id' => (int)$existing->id]);
        } else {
            $wpdb->insert($this->table(), $data);
        }

        do_action('tng_player_progress_saved', $user_id, $quest_id, $data);
        if ($complete && (!$existing || $existing->status !== 'completed')) {
            do_action('tng_quest_completed', $user_id, $quest_id, $xp);
        }

        return new WP_REST_Response($this->payload($this->row($user_id, $quest_id), $quest_id), 200);
    }

    public function menu(): void {
        add_submenu_page('tn-game-os', 'Player Progress', 'Player Progress', 'manage_options', 'tng-player-progress', [$this, 'page']);
    }

    public function page(): void {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $table = $this->table();
        $rows = $wpdb->get_results("SELECT p.*, u.display_name FROM {$table} p LEFT JOIN {$wpdb->users} u ON u.ID=p.user_id ORDER BY p.updated_at DESC LIMIT 200");
        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $active = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status=%s", 'in_progress'));
        $completed = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status=%s", 'completed'));
        $xp = (int)$wpdb->get_var("SELECT COALESCE(SUM(xp_earned),0) FROM {$table}");
        ?>
        <div class="wrap tng-pp">
            <style>
                .tng-pp{max-width:1500px}.tng-pp-hero{background:linear-gradient(135deg,#14213d,#24576d);color:#fff;border-radius:18px;padding:30px;margin:18px 0}.tng-pp-hero h1{color:#fff;margin:0 0 7px}.tng-pp-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.tng-pp-stat,.tng-pp-card{background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:18px}.tng-pp-stat strong{display:block;font-size:29px;color:#14213d;margin-top:5px}.tng-pp-card{margin-top:18px;overflow:auto}.tng-pp table{width:100%;border-collapse:collapse}.tng-pp th,.tng-pp td{text-align:left;padding:12px;border-bottom:1px solid #edf0f3}.tng-pp-badge{display:inline-flex;border-radius:999px;padding:5px 10px;font-weight:700;font-size:12px;background:#eef2f6}.tng-pp-badge.completed{background:#ecfdf3;color:#067647}.tng-pp-badge.in_progress{background:#fff6ed;color:#b54708}@media(max-width:900px){.tng-pp-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:550px){.tng-pp-stats{grid-template-columns:1fr}}
            </style>
            <div class="tng-pp-hero"><p style="text-transform:uppercase;letter-spacing:.12em;margin:0 0 8px;color:#f6bd3b;font-weight:700">TN Platform · Player State</p><h1>Player Progress</h1><p>Track started quests, checkpoint claims, completion state, earned XP, and resumable player journeys.</p></div>
            <div class="tng-pp-stats"><div class="tng-pp-stat"><span>Player journeys</span><strong><?php echo esc_html(number_format_i18n($total)); ?></strong></div><div class="tng-pp-stat"><span>In progress</span><strong><?php echo esc_html(number_format_i18n($active)); ?></strong></div><div class="tng-pp-stat"><span>Completed</span><strong><?php echo esc_html(number_format_i18n($completed)); ?></strong></div><div class="tng-pp-stat"><span>XP recorded</span><strong><?php echo esc_html(number_format_i18n($xp)); ?></strong></div></div>
            <div class="tng-pp-card"><h2 style="margin-top:0">Recent journeys</h2><table><thead><tr><th>Player</th><th>Quest</th><th>Status</th><th>Checkpoints</th><th>XP</th><th>Started</th><th>Updated</th></tr></thead><tbody>
            <?php if (!$rows): ?><tr><td colspan="7">No player progress has been recorded yet. Start a published quest while logged in to create the first journey.</td></tr><?php endif; ?>
            <?php foreach ($rows as $row): $done = json_decode((string)$row->completed_stops, true); ?>
                <tr><td><?php echo esc_html($row->display_name ?: 'User #'.(int)$row->user_id); ?></td><td><strong><?php echo esc_html(get_the_title((int)$row->quest_id) ?: 'Quest #'.(int)$row->quest_id); ?></strong></td><td><span class="tng-pp-badge <?php echo esc_attr($row->status); ?>"><?php echo esc_html(ucwords(str_replace('_',' ',$row->status))); ?></span></td><td><?php echo esc_html(number_format_i18n(count(is_array($done) ? $done : []))); ?></td><td><?php echo esc_html(number_format_i18n((int)$row->xp_earned)); ?></td><td><?php echo esc_html($row->started_at ?: '—'); ?></td><td><?php echo esc_html($row->updated_at); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </div><?php
    }

    private function table(): string { global $wpdb; return $wpdb->prefix . 'tng_player_progress'; }
    private function valid_quest(int $quest_id): bool { return $quest_id > 0 && get_post_type($quest_id) === self::QUEST_TYPE; }
    private function row(int $user_id, int $quest_id): ?object { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE user_id=%d AND quest_id=%d", $user_id, $quest_id)); }

    private function payload(?object $row, int $quest_id): array {
        $done = $row ? json_decode((string)$row->completed_stops, true) : [];
        return [
            'questId' => $quest_id,
            'started' => $row ? $row->status !== 'not_started' : false,
            'completedStops' => array_values(is_array($done) ? $done : []),
            'status' => $row->status ?? 'not_started',
            'xpEarned' => (int)($row->xp_earned ?? 0),
            'startedAt' => $row->started_at ?? null,
            'completedAt' => $row->completed_at ?? null,
            'updatedAt' => $row->updated_at ?? null,
        ];
    }

    private function required(int $quest_id, int $total): int {
        $mode = sanitize_key((string)get_post_meta($quest_id, '_tng_game_completion_mode', true)) ?: 'all';
        $count = absint(get_post_meta($quest_id, '_tng_game_completion_count', true));
        return $mode === 'count' ? min($total, max(1, $count)) : $total;
    }

    private function earned_xp(int $quest_id, array $done): int {
        $mechanics = (array)get_post_meta($quest_id, '_tng_game_checkpoint_mechanics', true);
        $xp = 0;
        foreach ($done as $id) $xp += absint($mechanics[(string)$id]['xp'] ?? 25);
        return $xp;
    }
}
