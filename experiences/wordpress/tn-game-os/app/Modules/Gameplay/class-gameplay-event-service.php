<?php
namespace TNG_OS\Modules\Gameplay;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Gameplay_Event_Service implements Module_Interface {
    private const DB_VERSION = '1';
    private const DB_OPTION = 'tng_gameplay_events_db_version';
    private const PAGE = 'tng-gameplay-events';

    public function id(): string { return 'gameplay_event_service'; }

    public function register(Container $container): void {
        $container->set('gameplay_event_service', $this);
        add_action('init', [$this, 'ensure_table'], 5);
        add_action('tng_gameplay_profile_saved', [$this, 'record_profile_delta'], 10, 3);
        add_action('admin_menu', [$this, 'menu'], 29);
        add_action('admin_post_tng_rebuild_gameplay_events', [$this, 'rebuild']);
    }

    public function boot(Container $container): void {}

    public function ensure_table(): void {
        if (get_option(self::DB_OPTION) === self::DB_VERSION) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $this->table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_key varchar(191) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_type varchar(40) NOT NULL,
            object_type varchar(40) NOT NULL DEFAULT '',
            object_id varchar(191) NOT NULL DEFAULT '',
            xp int unsigned NOT NULL DEFAULT 0,
            payload longtext NULL,
            occurred_at datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_key (event_key),
            KEY user_type (user_id,event_type),
            KEY occurred_at (occurred_at)
        ) {$charset};");
        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    public function record_profile_delta(int $user_id, array $before, array $after): void {
        $this->ensure_table();
        $occurred = current_time('mysql', true);

        $before_checkpoints = $this->keys($before['completedCheckpoints'] ?? []);
        $after_checkpoints = $this->keys($after['completedCheckpoints'] ?? []);
        foreach (array_diff($after_checkpoints, $before_checkpoints) as $id) {
            $activity = $this->find_activity($after, 'checkpoint', $id);
            $this->record($user_id, 'checkpoint_completed', 'checkpoint', $id, absint($activity['xp'] ?? 0), $activity, $activity['date'] ?? $occurred);
        }

        $before_quests = $this->keys($before['completedQuests'] ?? []);
        $after_quests = $this->keys($after['completedQuests'] ?? []);
        foreach (array_diff($after_quests, $before_quests) as $id) {
            $activity = $this->find_activity($after, 'quest', $id);
            $this->record($user_id, 'quest_completed', 'quest', $id, absint($activity['xp'] ?? 0), $activity, $activity['date'] ?? $occurred);
        }

        $before_badges = $this->keys($before['badges'] ?? []);
        $after_badges = $this->keys($after['badges'] ?? []);
        foreach (array_diff($after_badges, $before_badges) as $id) {
            $activity = $this->find_activity($after, 'badge', $id);
            $this->record($user_id, 'badge_unlocked', 'badge', $id, 0, $activity, $activity['date'] ?? $occurred);
        }

        $xp_delta = max(0, absint($after['totalXp'] ?? 0) - absint($before['totalXp'] ?? 0));
        if ($xp_delta > 0) {
            $fingerprint = implode('|', [absint($before['totalXp'] ?? 0), absint($after['totalXp'] ?? 0), count($after_checkpoints), count($after_quests)]);
            $this->record($user_id, 'xp_earned', 'profile', (string)$user_id, $xp_delta, ['from' => absint($before['totalXp'] ?? 0), 'to' => absint($after['totalXp'] ?? 0)], $occurred, $fingerprint);
        }
    }

    public function record(int $user_id, string $type, string $object_type, string $object_id, int $xp = 0, array $payload = [], string $occurred_at = '', string $fingerprint = ''): bool {
        global $wpdb;
        $type = sanitize_key($type);
        $object_type = sanitize_key($object_type);
        $object_id = sanitize_text_field($object_id);
        $occurred_at = $this->mysql_date($occurred_at ?: current_time('mysql', true));
        $fingerprint = $fingerprint !== '' ? $fingerprint : $object_id;
        $event_key = hash('sha256', implode('|', [$user_id, $type, $object_type, $fingerprint]));
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->table()} (event_key,user_id,event_type,object_type,object_id,xp,payload,occurred_at,created_at) VALUES (%s,%d,%s,%s,%s,%d,%s,%s,%s)",
            $event_key, $user_id, $type, $object_type, $object_id, max(0, $xp), wp_json_encode($payload), $occurred_at, current_time('mysql', true)
        ));
        if ($inserted) do_action('tng_gameplay_event_recorded', $type, $user_id, $object_id, $payload);
        return (bool)$inserted;
    }

    public function menu(): void {
        add_submenu_page('tn-game-os', 'Gameplay Event Ledger', 'Gameplay Events', 'manage_options', self::PAGE, [$this, 'page']);
    }

    public function page(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        $this->ensure_table();
        global $wpdb;
        $table = $this->table();
        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $today = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE occurred_at >= %s", gmdate('Y-m-d 00:00:00')));
        $users = (int)$wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$table}");
        $rows = $wpdb->get_results("SELECT e.*,u.display_name FROM {$table} e LEFT JOIN {$wpdb->users} u ON u.ID=e.user_id ORDER BY e.occurred_at DESC,e.id DESC LIMIT 100", ARRAY_A);
        ?>
        <div class="wrap"><h1>Gameplay Event Ledger</h1><p>This is the canonical, append-only record of Explorer progression events.</p>
        <?php if (isset($_GET['rebuilt'])): ?><div class="notice notice-success"><p>Gameplay event ledger rebuilt from current Explorer profiles.</p></div><?php endif; ?>
        <div style="display:grid;grid-template-columns:repeat(3,minmax(160px,1fr));gap:14px;max-width:850px;margin:22px 0">
            <?php foreach ([['All events',$total],['Events today',$today],['Explorers recorded',$users]] as [$label,$value]): ?><div style="background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:18px"><strong style="display:block;font-size:30px;color:#6438b3"><?php echo number_format_i18n($value); ?></strong><span><?php echo esc_html($label); ?></span></div><?php endforeach; ?>
        </div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Rebuild the canonical event ledger from all current Explorer profiles? Existing event keys will not be duplicated.');" style="margin-bottom:20px">
            <?php wp_nonce_field('tng_rebuild_gameplay_events'); ?><input type="hidden" name="action" value="tng_rebuild_gameplay_events"><button class="button button-secondary">Rebuild from profiles</button>
        </form>
        <table class="widefat striped"><thead><tr><th>Time</th><th>Explorer</th><th>Event</th><th>Object</th><th>XP</th></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="5">No canonical gameplay events have been recorded yet.</td></tr><?php endif; ?>
        <?php foreach ($rows as $row): ?><tr><td><?php echo esc_html(get_date_from_gmt($row['occurred_at'], 'M j, Y g:i a')); ?></td><td><?php echo esc_html($row['display_name'] ?: ('User #' . $row['user_id'])); ?></td><td><code><?php echo esc_html($row['event_type']); ?></code></td><td><?php echo esc_html($row['object_id']); ?></td><td><?php echo number_format_i18n((int)$row['xp']); ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php
    }

    public function rebuild(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        check_admin_referer('tng_rebuild_gameplay_events');
        $this->ensure_table();
        foreach (get_users(['fields' => 'ids', 'meta_key' => '_tng_explorer_profile']) as $user_id) {
            $profile = get_user_meta((int)$user_id, '_tng_explorer_profile', true);
            if (!is_array($profile)) continue;
            foreach ((array)($profile['recentActivity'] ?? []) as $activity) {
                if (!is_array($activity)) continue;
                $kind = sanitize_key((string)($activity['kind'] ?? 'checkpoint'));
                $id = sanitize_text_field((string)($activity['id'] ?? ''));
                if ($id === '') continue;
                $type = $kind === 'quest' ? 'quest_completed' : ($kind === 'badge' ? 'badge_unlocked' : 'checkpoint_completed');
                $this->record((int)$user_id, $type, $kind, $id, absint($activity['xp'] ?? 0), $activity, (string)($activity['date'] ?? ''));
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE . '&rebuilt=1'));
        exit;
    }

    private function find_activity(array $profile, string $kind, string $id): array {
        foreach ((array)($profile['recentActivity'] ?? []) as $activity) {
            if (!is_array($activity)) continue;
            if (sanitize_key((string)($activity['kind'] ?? '')) !== $kind) continue;
            $activity_id = sanitize_text_field((string)($activity['id'] ?? ''));
            if ($activity_id === $id || str_contains($activity_id, $id)) return $activity;
        }
        return ['id' => $id, 'kind' => $kind, 'date' => current_time('mysql', true)];
    }

    private function keys($values): array { return array_values(array_unique(array_filter(array_map('strval', (array)$values)))); }
    private function table(): string { global $wpdb; return $wpdb->prefix . 'tng_gameplay_events'; }
    private function mysql_date(string $value): string { $time = strtotime($value); return $time ? gmdate('Y-m-d H:i:s', $time) : current_time('mysql', true); }
}
