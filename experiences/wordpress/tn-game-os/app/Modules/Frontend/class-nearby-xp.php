<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use TNG_OS\Platform\App_Router;
use TNG_OS\Platform\Explorer_Profile_V2;
use TNG_OS\Platform\Universal_Map_Registry;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class Nearby_XP implements Module_Interface {
    private const REST_NAMESPACE = 'tng-os/v1';
    private const REST_ROUTE = '/nearby-xp';
    private const META_LEDGER = '_tng_nearby_xp_ledger';
    private const META_TOTAL = '_tng_nearby_xp_total';
    private const OPEN_XP = 5;
    private const DISCOVERY_XP = 10;
    private const RADIUS_MILES = 0.25;
    private array $request_award = [];

    public function id(): string { return 'nearby_xp'; }

    public function register(Container $container): void {
        $container->set('nearby_xp', $this);
        add_action('template_redirect', [$this, 'daily_open_bonus'], 12);
        add_action('rest_api_init', [$this, 'routes']);
        add_action('wp_enqueue_scripts', [$this, 'assets'], 145);
        add_action('wp_footer', [$this, 'render'], 175);
        add_filter('tng_os_explorer_profile_stats', [$this, 'profile_stats'], 95, 2);
    }

    public function boot(Container $container): void {}

    private function is_app(): bool {
        return !is_admin() && class_exists(App_Router::class) && App_Router::is_app_request();
    }

    public function daily_open_bonus(): void {
        if (!$this->is_app() || !is_user_logged_in()) return;
        $user_id = get_current_user_id();
        $key = 'open:' . current_time('Y-m-d');
        $award = $this->award($user_id, $key, self::OPEN_XP, 'Daily Explorer boost', 0);
        if (!empty($award['awarded'])) $this->request_award = $award;
    }

    public function routes(): void {
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, [
            'methods' => 'POST',
            'callback' => [$this, 'nearby'],
            'permission_callback' => static fn(): bool => is_user_logged_in(),
            'args' => [
                'lat' => ['required' => true, 'type' => 'number'],
                'lng' => ['required' => true, 'type' => 'number'],
            ],
        ]);
    }

    public function nearby(WP_REST_Request $request): WP_REST_Response {
        $lat = (float) $request->get_param('lat');
        $lng = (float) $request->get_param('lng');
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) {
            return new WP_REST_Response(['ok' => false, 'message' => 'Location was unavailable. Try again when your device has a GPS fix.'], 400);
        }
        $nearest = $this->nearest($lat, $lng);
        if (!$nearest) return new WP_REST_Response(['ok' => false, 'message' => 'No map-ready TN Game discoveries are available yet.'], 404);

        $distance = (float) $nearest['distance'];
        $item = $nearest['item'];
        if ($distance > (float) apply_filters('tng_nearby_xp_radius_miles', self::RADIUS_MILES, $item)) {
            return new WP_REST_Response([
                'ok' => true,
                'nearby' => false,
                'message' => sprintf('%s is the closest discovery, about %s away.', $item['title'], $this->distance_label($distance)),
                'item' => $this->public_item($item, $distance),
            ]);
        }

        $user_id = get_current_user_id();
        $key = 'discovery:' . absint($item['id']);
        $award = $this->award($user_id, $key, self::DISCOVERY_XP, 'Nearby discovery: ' . $item['title'], absint($item['id']));
        if (class_exists(Explorer_Profile_V2::class)) Explorer_Profile_V2::record($user_id, [absint($item['id'])]);
        $message = !empty($award['awarded'])
            ? sprintf('Discovered %s · +%d XP', $item['title'], self::DISCOVERY_XP)
            : sprintf('%s is already in your discoveries.', $item['title']);
        return new WP_REST_Response([
            'ok' => true,
            'nearby' => true,
            'awarded' => !empty($award['awarded']),
            'amount' => !empty($award['awarded']) ? self::DISCOVERY_XP : 0,
            'message' => $message,
            'item' => $this->public_item($item, $distance),
            'total' => absint(get_user_meta($user_id, self::META_TOTAL, true)),
        ]);
    }

    private function nearest(float $lat, float $lng): array {
        if (!class_exists(Universal_Map_Registry::class)) return [];
        $dataset = Universal_Map_Registry::dataset();
        $best = [];
        $best_distance = INF;
        foreach ((array) ($dataset['items'] ?? []) as $item) {
            $item_lat = (float) ($item['lat'] ?? 0);
            $item_lng = (float) ($item['lng'] ?? 0);
            if (!$item_lat || !$item_lng) continue;
            $distance = $this->distance($lat, $lng, $item_lat, $item_lng);
            if ($distance >= $best_distance) continue;
            $best_distance = $distance;
            $best = ['item' => $item, 'distance' => $distance];
        }
        return $best;
    }

    private function public_item(array $item, float $distance): array {
        return [
            'id' => absint($item['id'] ?? 0),
            'title' => sanitize_text_field($item['title'] ?? 'TN Game discovery'),
            'label' => sanitize_text_field($item['label'] ?? 'Discovery'),
            'icon' => sanitize_text_field($item['icon'] ?? '📍'),
            'url' => esc_url_raw($item['actionUrl'] ?? $item['url'] ?? home_url('/map/')),
            'distance' => $this->distance_label($distance),
        ];
    }

    private function award(int $user_id, string $key, int $amount, string $reason, int $object_id): array {
        $ledger = get_user_meta($user_id, self::META_LEDGER, true);
        $ledger = is_array($ledger) ? $ledger : [];
        if (isset($ledger[$key])) return ['awarded' => false, 'amount' => 0, 'reason' => $reason];
        $awarded = false;
        $type = $this->points_type();
        if ($type !== '' && function_exists('gamipress_award_points_to_user')) {
            $awarded = gamipress_award_points_to_user($user_id, $amount, $type) !== false;
        } else {
            $current = max(0, (int) get_user_meta($user_id, 'tng_xp', true));
            update_user_meta($user_id, 'tng_xp', $current + $amount);
            $awarded = true;
        }
        if (!$awarded) return ['awarded' => false, 'amount' => 0, 'reason' => $reason];
        $entry = ['amount' => $amount, 'reason' => sanitize_text_field($reason), 'object_id' => $object_id, 'date' => current_time('mysql')];
        $ledger[$key] = $entry;
        if (count($ledger) > 1000) $ledger = array_slice($ledger, -1000, null, true);
        update_user_meta($user_id, self::META_LEDGER, $ledger);
        update_user_meta($user_id, self::META_TOTAL, max(0, (int) get_user_meta($user_id, self::META_TOTAL, true)) + $amount);
        do_action('tng_os_nearby_xp_awarded', $user_id, $amount, $key, $entry);
        return ['awarded' => true, 'amount' => $amount, 'reason' => $reason, 'object_id' => $object_id];
    }

    private function points_type(): string {
        $configured = sanitize_key((string) get_option('tng_gamipress_points_type', ''));
        if ($configured !== '') return $configured;
        if (!function_exists('gamipress_get_points_types')) return '';
        $types = gamipress_get_points_types();
        if (!is_array($types) || !$types) return '';
        foreach (['explorer-xp','xp','points'] as $preferred) if (isset($types[$preferred])) return $preferred;
        foreach ($types as $slug => $data) {
            $text = strtolower($slug . ' ' . wp_json_encode($data));
            if (str_contains($text, 'explorer') && str_contains($text, 'xp')) return sanitize_key((string) $slug);
        }
        return count($types) === 1 ? sanitize_key((string) array_key_first($types)) : '';
    }

    private function distance(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $earth = 3958.7613;
        $lat1 = deg2rad($lat1); $lat2 = deg2rad($lat2);
        $dlat = $lat2 - $lat1; $dlng = deg2rad($lng2 - $lng1);
        $a = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlng / 2) ** 2;
        return $earth * 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
    }

    private function distance_label(float $miles): string {
        if ($miles < 0.1) return max(1, (int) round($miles * 5280)) . ' ft';
        if ($miles < 10) return number_format_i18n($miles, 1) . ' mi';
        return number_format_i18n($miles, 0) . ' mi';
    }

    public function assets(): void {
        if (!$this->is_app()) return;
        wp_enqueue_style('tng-nearby-xp', TNG_OS_URL . 'assets/frontend/nearby-xp.css', [], TNG_OS_VERSION);
        wp_enqueue_script('tng-nearby-xp', TNG_OS_URL . 'assets/frontend/nearby-xp.js', [], TNG_OS_VERSION, true);
        wp_localize_script('tng-nearby-xp', 'TNG_NEARBY_XP', [
            'endpoint' => rest_url(self::REST_NAMESPACE . self::REST_ROUTE),
            'nonce' => wp_create_nonce('wp_rest'),
            'loggedIn' => is_user_logged_in(),
            'loginUrl' => wp_login_url(home_url('/explore/')),
            'mapUrl' => home_url('/map/'),
            'openAward' => $this->request_award,
        ]);
    }

    public function render(): void {
        if (!$this->is_app()) return;
        ?>
        <aside class="tng-nearby-xp" data-tng-nearby-xp hidden aria-live="polite">
            <span class="tng-nearby-xp__icon">⚡</span>
            <div class="tng-nearby-xp__copy"><small>Nearby XP</small><strong data-tng-nearby-title><?php echo $this->request_award ? esc_html('Daily Explorer boost · +' . absint($this->request_award['amount'] ?? self::OPEN_XP) . ' XP') : esc_html('Turn where you are into progress'); ?></strong><p data-tng-nearby-status><?php echo is_user_logged_in() ? 'Check for a map-ready discovery near you. Your coordinates are never stored.' : 'Sign in to earn a daily Explorer boost and nearby discovery XP.'; ?></p></div>
            <?php if (is_user_logged_in()): ?><button type="button" data-tng-nearby-check>Check nearby</button><?php else: ?><a href="<?php echo esc_url(wp_login_url(home_url('/explore/'))); ?>">Sign in</a><?php endif; ?>
            <button class="tng-nearby-xp__dismiss" type="button" data-tng-nearby-dismiss aria-label="Dismiss Nearby XP">×</button>
        </aside>
        <?php
    }

    public function profile_stats($stats, $user_id) {
        $stats = is_array($stats) ? $stats : [];
        $stats['nearby_xp'] = max(0, (int) get_user_meta(absint($user_id), self::META_TOTAL, true));
        return $stats;
    }
}
