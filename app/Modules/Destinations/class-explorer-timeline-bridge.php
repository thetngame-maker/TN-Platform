<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Explorer_Timeline_Bridge implements Module_Interface {
    private const META_EVENTS = '_tng_explorer_timeline_events';

    public function id(): string { return 'explorer_timeline_bridge'; }

    public function register(Container $container): void {
        $container->set('explorer_timeline_bridge', $this);
        add_filter('tng_os_adventure_journal_events', [$this, 'journal_events'], 40, 2);
        add_filter('tng_os_explorer_profile_stats', [$this, 'profile_stats'], 40, 2);
        add_action('tng_os_explorer_activity_created', [$this, 'capture_activity'], 30, 2);
        add_action('tng_os_gameplay_event_recorded', [$this, 'capture_gameplay'], 30, 2);
        add_action('tng_gameplay_event_recorded', [$this, 'capture_gameplay'], 30, 2);
        add_action('admin_menu', [$this, 'admin_menu'], 83);
    }

    public function boot(Container $container): void {}

    public function journal_events($events, $user_id) {
        $events = is_array($events) ? $events : [];
        $saved = get_user_meta(absint($user_id), self::META_EVENTS, true);
        if (is_array($saved)) $events = array_merge($events, $saved);
        return $this->dedupe($events);
    }

    public function profile_stats($stats, $user_id) {
        $stats = is_array($stats) ? $stats : [];
        $user_id = absint($user_id);
        $events = get_user_meta($user_id, self::META_EVENTS, true);
        $events = is_array($events) ? $events : [];
        $badges = 0;
        $photos = 0;
        foreach ($events as $event) {
            $type = sanitize_key($event['type'] ?? '');
            if (str_contains($type, 'badge') || str_contains($type, 'achievement') || str_contains($type, 'rank')) $badges++;
            if (str_contains($type, 'photo') || str_contains($type, 'image')) $photos++;
        }
        $xp = $this->user_xp($user_id);
        if ($xp > 0) {
            $stats['xp'] = max(absint($stats['xp'] ?? 0), $xp);
            $stats['total_xp'] = max(absint($stats['total_xp'] ?? 0), $xp);
        }
        $stats['achievements'] = max(absint($stats['achievements'] ?? 0), $badges);
        $stats['photos'] = max(absint($stats['photos'] ?? 0), $photos);
        return $stats;
    }

    public function capture_activity($user_id, $activity): void {
        if (!is_array($activity)) return;
        $this->store(absint($user_id), $this->normalize($activity));
    }

    public function capture_gameplay($user_id, $event = []): void {
        if (is_array($user_id) && !$event) {
            $event = $user_id;
            $user_id = absint($event['user_id'] ?? 0);
        }
        if (!is_array($event)) return;
        $this->store(absint($user_id), $this->normalize($event));
    }

    private function store(int $user_id, array $event): void {
        if ($user_id < 1 || empty($event['id'])) return;
        $items = get_user_meta($user_id, self::META_EVENTS, true);
        $items = is_array($items) ? $items : [];
        foreach ($items as $item) if (($item['id'] ?? '') === $event['id']) return;
        array_unshift($items, $event);
        update_user_meta($user_id, self::META_EVENTS, array_slice($items, 0, 500));
    }

    private function normalize(array $event): array {
        $type = sanitize_key($event['type'] ?? $event['event'] ?? 'activity');
        $object = sanitize_text_field($event['object'] ?? $event['object_id'] ?? '');
        $id = sanitize_text_field($event['id'] ?? $event['key'] ?? ($type . ':' . $object . ':' . ($event['timestamp'] ?? current_time('timestamp'))));
        $date = sanitize_text_field($event['date'] ?? '');
        if (!$date && !empty($event['timestamp'])) $date = wp_date('Y-m-d H:i:s', absint($event['timestamp']));
        if (!$date) $date = current_time('mysql');
        return [
            'id' => $id,
            'type' => $type,
            'title' => sanitize_text_field($event['title'] ?? $this->title_for($type, $object)),
            'description' => sanitize_text_field($event['description'] ?? $event['message'] ?? ''),
            'date' => $date,
            'meta' => [
                'xp' => absint($event['xp'] ?? $event['points'] ?? 0),
                'object' => $object,
                'badge' => sanitize_text_field($event['badge'] ?? ''),
            ],
        ];
    }

    private function title_for(string $type, string $object): string {
        if (str_contains($type, 'checkpoint')) return $object ? 'Checkpoint discovered' : 'Discovered a checkpoint';
        if (str_contains($type, 'quest')) return $object ? 'Quest completed' : 'Completed a quest';
        if (str_contains($type, 'badge') || str_contains($type, 'achievement')) return 'Achievement unlocked';
        if (str_contains($type, 'photo')) return 'Photo added to the journey';
        return 'Explorer activity';
    }

    public function admin_menu(): void {
        add_submenu_page('tn-game-os', 'Journal Timeline', 'Journal Timeline', 'manage_options', 'tng-os-journal-timeline', [$this, 'admin_page']);
    }

    public function admin_page(): void {
        if (!current_user_can('manage_options')) return;
        $message = '';
        if (!empty($_POST['tng_journal_backfill']) && check_admin_referer('tng_journal_backfill')) {
            $count = 0;
            foreach (get_users(['fields' => 'ID']) as $user_id) $count += $this->backfill_user((int) $user_id);
            $message = sprintf('Added %d Explorer Journal event%s.', $count, $count === 1 ? '' : 's');
        }
        echo '<div class="wrap"><h1>Explorer Journal Timeline</h1>';
        if ($message) echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        echo '<p>Imports existing GamiPress achievements, progression logs, and approved Explorer photos into the unified Journal. Future TN Game events are captured automatically.</p>';
        echo '<form method="post">'; wp_nonce_field('tng_journal_backfill');
        echo '<p><button class="button button-primary" name="tng_journal_backfill" value="1">Backfill existing Journal events</button></p></form></div>';
    }

    private function backfill_user(int $user_id): int {
        $before = get_user_meta($user_id, self::META_EVENTS, true);
        $before_count = is_array($before) ? count($before) : 0;
        $this->backfill_gamipress($user_id);
        $this->backfill_photos($user_id);
        $after = get_user_meta($user_id, self::META_EVENTS, true);
        return max(0, (is_array($after) ? count($after) : 0) - $before_count);
    }

    private function backfill_gamipress(int $user_id): void {
        global $wpdb;
        $earnings = $wpdb->prefix . 'gamipress_user_earnings';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $earnings)) === $earnings) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT user_earning_id, post_id, post_type, points, points_type, date FROM {$earnings} WHERE user_id=%d ORDER BY date DESC LIMIT 250", $user_id), ARRAY_A);
            foreach ((array) $rows as $row) {
                $post_id = absint($row['post_id'] ?? 0);
                $title = $post_id ? get_the_title($post_id) : '';
                $type = sanitize_key($row['post_type'] ?? 'achievement_unlocked');
                $this->store($user_id, [
                    'id' => 'gamipress-earning:' . absint($row['user_earning_id'] ?? 0),
                    'type' => str_contains($type, 'rank') ? 'rank_earned' : 'achievement_unlocked',
                    'title' => $title ?: 'Achievement unlocked',
                    'description' => !empty($row['points']) ? absint($row['points']) . ' XP earned' : 'Explorer milestone earned',
                    'date' => sanitize_text_field($row['date'] ?? current_time('mysql')),
                    'meta' => ['xp' => absint($row['points'] ?? 0), 'badge' => $title],
                ]);
            }
        }

        $logs = $wpdb->prefix . 'gamipress_logs';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $logs)) === $logs) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT log_id, title, type, trigger_type, post_id, points, date FROM {$logs} WHERE user_id=%d ORDER BY date DESC LIMIT 300", $user_id), ARRAY_A);
            foreach ((array) $rows as $row) {
                $trigger = sanitize_key($row['trigger_type'] ?? $row['type'] ?? 'activity');
                if (!str_contains($trigger, 'checkpoint') && !str_contains($trigger, 'quest') && !str_contains($trigger, 'award') && !str_contains($trigger, 'complete')) continue;
                $this->store($user_id, [
                    'id' => 'gamipress-log:' . absint($row['log_id'] ?? 0),
                    'type' => str_contains($trigger, 'quest') ? 'quest_completed' : (str_contains($trigger, 'checkpoint') ? 'checkpoint_completed' : 'xp_earned'),
                    'title' => sanitize_text_field($row['title'] ?? $this->title_for($trigger, '')),
                    'description' => !empty($row['points']) ? absint($row['points']) . ' XP earned' : '',
                    'date' => sanitize_text_field($row['date'] ?? current_time('mysql')),
                    'meta' => ['xp' => absint($row['points'] ?? 0)],
                ]);
            }
        }
    }

    private function backfill_photos(int $user_id): void {
        $photos = get_posts([
            'post_type' => 'attachment', 'post_status' => 'inherit', 'author' => $user_id,
            'post_mime_type' => 'image', 'posts_per_page' => 100, 'orderby' => 'date', 'order' => 'DESC',
        ]);
        foreach ($photos as $photo) {
            $approved = get_post_meta($photo->ID, '_tng_photo_approved', true);
            if ($approved !== '' && !in_array((string) $approved, ['1', 'yes', 'approved'], true)) continue;
            $this->store($user_id, [
                'id' => 'photo:' . $photo->ID,
                'type' => 'photo_added',
                'title' => get_the_title($photo) ?: 'Explorer photo',
                'description' => 'Photo added to the Explorer story.',
                'date' => $photo->post_date,
                'meta' => ['attachment_id' => $photo->ID, 'image' => wp_get_attachment_image_url($photo->ID, 'medium')],
            ]);
        }
    }

    private function user_xp(int $user_id): int {
        if (function_exists('gamipress_get_user_points')) {
            foreach (['xp', 'explorer-xp', 'points'] as $slug) {
                $value = absint(gamipress_get_user_points($user_id, $slug));
                if ($value > 0) return $value;
            }
        }
        foreach (['_tng_xp', 'tng_xp', '_gamipress_xp', 'gamipress_xp'] as $key) {
            $value = absint(get_user_meta($user_id, $key, true));
            if ($value > 0) return $value;
        }
        return 0;
    }

    private function dedupe(array $events): array {
        $out = [];
        $seen = [];
        foreach ($events as $event) {
            if (!is_array($event)) continue;
            $key = sanitize_text_field($event['id'] ?? (($event['type'] ?? '') . ':' . ($event['title'] ?? '') . ':' . ($event['date'] ?? '')));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $event;
        }
        return $out;
    }
}
