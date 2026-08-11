<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class Trip_Explorer_Integration implements Module_Interface {
    private const META_RECAPS = '_tng_trip_recaps';
    private const META_ACTIVITY = '_tng_os_trip_activity';
    private const META_STOPS = '_tng_trip_stops_total';
    private const META_MINUTES = '_tng_trip_minutes_total';

    public function id(): string { return 'trip_explorer_integration'; }

    public function register(Container $container): void {
        $container->set('trip_explorer_integration', $this);
        add_action('tng_os_trip_completed', [$this, 'sync_trip'], 20, 2);
        add_action('rest_api_init', [$this, 'routes']);
        add_action('admin_menu', [$this, 'admin_menu'], 80);
        add_filter('tng_os_explorer_profile_stats', [$this, 'profile_stats'], 20, 2);
        add_filter('tng_os_adventure_journal_events', [$this, 'journal_events'], 20, 2);
        add_filter('tng_os_network_activity_items', [$this, 'network_items'], 20, 2);
        add_shortcode('tng_trip_activity', [$this, 'shortcode']);
    }

    public function boot(Container $container): void {}

    public function sync_trip(int $user_id, array $recap): void {
        if ($user_id < 1 || empty($recap['id'])) return;
        $items = get_user_meta($user_id, self::META_ACTIVITY, true);
        $items = is_array($items) ? $items : [];
        $key = 'trip:' . sanitize_text_field((string) $recap['id']);
        foreach ($items as $item) {
            if (($item['key'] ?? '') === $key) return;
        }

        $stop_count = max(0, absint($recap['stop_count'] ?? count($recap['stops'] ?? [])));
        $minutes = max(0, absint($recap['minutes'] ?? 0));
        $activity = [
            'key' => $key,
            'type' => 'trip_completed',
            'user_id' => $user_id,
            'title' => sanitize_text_field($recap['title'] ?? 'Completed a Tennessee adventure'),
            'message' => sprintf('Completed a travel day with %d stop%s.', $stop_count, $stop_count === 1 ? '' : 's'),
            'date' => sanitize_text_field($recap['date'] ?? current_time('mysql')),
            'timestamp' => current_time('timestamp'),
            'stop_count' => $stop_count,
            'minutes' => $minutes,
            'streak' => max(0, absint($recap['streak'] ?? 0)),
            'badge' => sanitize_text_field($recap['badge'] ?? ''),
            'stops' => $this->clean_stops($recap['stops'] ?? []),
            'reactions' => ['cheer' => [], 'fire' => [], 'amazing' => []],
        ];
        array_unshift($items, $activity);
        update_user_meta($user_id, self::META_ACTIVITY, array_slice($items, 0, 100));
        update_user_meta($user_id, self::META_STOPS, max(0, (int) get_user_meta($user_id, self::META_STOPS, true)) + $stop_count);
        update_user_meta($user_id, self::META_MINUTES, max(0, (int) get_user_meta($user_id, self::META_MINUTES, true)) + $minutes);

        do_action('tng_os_explorer_activity_created', $user_id, $activity);
        do_action('tng_os_network_activity_created', $user_id, $activity);
        do_action('tng_os_explorer_profile_updated', $user_id, $this->get_stats($user_id));
    }

    private function clean_stops($stops): array {
        if (!is_array($stops)) return [];
        $out = [];
        foreach (array_slice($stops, 0, 20) as $stop) {
            if (!is_array($stop)) continue;
            $out[] = [
                'id' => absint($stop['id'] ?? 0),
                'title' => html_entity_decode(sanitize_text_field($stop['title'] ?? 'Trip stop'), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'minutes' => max(0, absint($stop['minutes'] ?? 0)),
            ];
        }
        return $out;
    }

    private function get_stats(int $user_id): array {
        return [
            'completed_trips' => max(0, (int) get_user_meta($user_id, '_tng_completed_trips', true)),
            'trip_stops' => max(0, (int) get_user_meta($user_id, self::META_STOPS, true)),
            'trip_minutes' => max(0, (int) get_user_meta($user_id, self::META_MINUTES, true)),
            'travel_day_streak' => max(0, (int) get_user_meta($user_id, '_tng_travel_day_streak', true)),
        ];
    }

    public function profile_stats($stats, $user_id) {
        $stats = is_array($stats) ? $stats : [];
        return array_merge($stats, $this->get_stats(absint($user_id)));
    }

    public function journal_events($events, $user_id) {
        $events = is_array($events) ? $events : [];
        $recaps = get_user_meta(absint($user_id), self::META_RECAPS, true);
        if (!is_array($recaps)) return $events;
        foreach ($recaps as $recap) {
            $events[] = [
                'id' => 'trip:' . sanitize_text_field($recap['id'] ?? ''),
                'type' => 'trip_completed',
                'title' => sanitize_text_field($recap['title'] ?? 'Tennessee adventure'),
                'description' => sprintf('%d stops · %s', absint($recap['stop_count'] ?? 0), $this->duration(absint($recap['minutes'] ?? 0))),
                'date' => sanitize_text_field($recap['date'] ?? ''),
                'meta' => $recap,
            ];
        }
        return $events;
    }

    public function network_items($items, $user_id) {
        $items = is_array($items) ? $items : [];
        $trip_items = get_user_meta(absint($user_id), self::META_ACTIVITY, true);
        if (!is_array($trip_items)) return $items;
        return array_merge($items, $trip_items);
    }

    public function routes(): void {
        register_rest_route('tng-os/v1', '/trip-activity/(?P<user_id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'activity'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('tng-os/v1', '/trip-activity/react', [
            'methods' => 'POST',
            'callback' => [$this, 'react'],
            'permission_callback' => static fn() => is_user_logged_in(),
        ]);
    }

    public function activity(WP_REST_Request $request): WP_REST_Response {
        $user_id = absint($request['user_id']);
        if (!$user_id || !get_userdata($user_id)) return new WP_REST_Response(['items' => []], 200);
        $items = get_user_meta($user_id, self::META_ACTIVITY, true);
        return new WP_REST_Response(['items' => is_array($items) ? array_slice($items, 0, 30) : [], 'stats' => $this->get_stats($user_id)], 200);
    }

    public function react(WP_REST_Request $request): WP_REST_Response {
        $owner_id = absint($request->get_param('owner_id'));
        $key = sanitize_text_field($request->get_param('key'));
        $reaction = sanitize_key($request->get_param('reaction'));
        if (!$owner_id || !$key || !in_array($reaction, ['cheer', 'fire', 'amazing'], true)) return new WP_REST_Response(['message' => 'Invalid reaction.'], 400);
        $items = get_user_meta($owner_id, self::META_ACTIVITY, true);
        if (!is_array($items)) return new WP_REST_Response(['message' => 'Activity not found.'], 404);
        $viewer = get_current_user_id();
        $counts = [];
        foreach ($items as &$item) {
            if (($item['key'] ?? '') !== $key) continue;
            $item['reactions'] = is_array($item['reactions'] ?? null) ? $item['reactions'] : ['cheer' => [], 'fire' => [], 'amazing' => []];
            foreach (['cheer', 'fire', 'amazing'] as $type) {
                $users = array_values(array_unique(array_map('absint', (array) ($item['reactions'][$type] ?? []))));
                $users = array_values(array_diff($users, [$viewer]));
                if ($type === $reaction) $users[] = $viewer;
                $item['reactions'][$type] = array_values(array_unique($users));
                $counts[$type] = count($item['reactions'][$type]);
            }
            break;
        }
        unset($item);
        update_user_meta($owner_id, self::META_ACTIVITY, $items);
        return new WP_REST_Response(['saved' => true, 'counts' => $counts], 200);
    }

    public function admin_menu(): void {
        add_submenu_page('tn-game-os', 'Trip Community', 'Trip Community', 'manage_options', 'tng-os-trip-community', [$this, 'admin_page']);
    }

    public function admin_page(): void {
        if (!current_user_can('manage_options')) return;
        $message = '';
        if (!empty($_POST['tng_trip_backfill']) && check_admin_referer('tng_trip_backfill')) {
            $users = get_users(['fields' => 'ID']);
            $count = 0;
            foreach ($users as $user_id) $count += $this->backfill_user((int) $user_id);
            $message = sprintf('Backfilled %d completed trip activit%s.', $count, $count === 1 ? 'y' : 'ies');
        }
        echo '<div class="wrap"><h1>Trip Community Integration</h1>';
        if ($message) echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        echo '<p>Connects completed travel days to Explorer totals, Adventure Journal events, Friends Activity, reactions, and profile statistics.</p><form method="post">';
        wp_nonce_field('tng_trip_backfill');
        echo '<p><button class="button button-primary" name="tng_trip_backfill" value="1">Backfill existing trip recaps</button></p></form></div>';
    }

    private function backfill_user(int $user_id): int {
        $recaps = get_user_meta($user_id, self::META_RECAPS, true);
        if (!is_array($recaps)) return 0;
        $before = get_user_meta($user_id, self::META_ACTIVITY, true);
        $before_count = is_array($before) ? count($before) : 0;
        foreach (array_reverse($recaps) as $recap) $this->sync_trip($user_id, is_array($recap) ? $recap : []);
        $after = get_user_meta($user_id, self::META_ACTIVITY, true);
        return max(0, (is_array($after) ? count($after) : 0) - $before_count);
    }

    public function shortcode(): string {
        if (!is_user_logged_in()) return '<p>Sign in to view your completed travel days.</p>';
        $items = get_user_meta(get_current_user_id(), self::META_ACTIVITY, true);
        $items = is_array($items) ? $items : [];
        ob_start();
        echo '<div class="tng-trip-activity"><h2>Completed travel days</h2>';
        if (!$items) echo '<p>Your completed trips will appear here.</p>';
        foreach (array_slice($items, 0, 20) as $item) {
            echo '<article style="padding:16px;margin:0 0 12px;border:1px solid #e1e5ef;border-radius:14px"><strong>' . esc_html($item['title'] ?? 'Tennessee adventure') . '</strong><div>' . esc_html($item['message'] ?? '') . '</div><small>' . esc_html(mysql2date(get_option('date_format'), $item['date'] ?? '')) . '</small></article>';
        }
        echo '</div>';
        return (string) ob_get_clean();
    }

    private function duration(int $minutes): string {
        if ($minutes < 60) return $minutes . ' min';
        return (round($minutes / 6) / 10) . ' hr';
    }
}
