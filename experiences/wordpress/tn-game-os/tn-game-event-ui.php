<?php
/**
 * Plugin Name: TN Game Event UI
 * Description: Native TN Game event and concert detail template.
 * Version: 0.1.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Event_UI {
    public static function boot(): void {
        add_filter('template_include', [self::class, 'template'], 99998);
        add_filter('body_class', [self::class, 'body_class'], 999);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue'], 110);
    }

    public static function is_event(): bool {
        if (!is_singular(['st_activity','activity'])) return false;
        $id = get_queried_object_id();
        if (!$id) return false;
        $text = strtolower(get_the_title($id) . ' ' . get_post_field('post_content', $id));
        $has_date = false;
        foreach (['start_date','event_date','date','st_start_date','event_start','start_time'] as $key) {
            if (get_post_meta($id, $key, true)) { $has_date = true; break; }
        }
        $concert = preg_match('/concert|show|festival|live music|the caverns|ticket|doors\s*\d/i', $text);
        $trail = preg_match('/trail|hike|hiking|gpx|elevation gain/i', $text);
        return !$trail && ($has_date || $concert);
    }

    public static function template(string $template): string {
        if (!self::is_event()) return $template;
        $native = TNG_OS_PATH . 'templates/event-shell.php';
        return is_readable($native) ? $native : $template;
    }

    public static function body_class(array $classes): array {
        if (!self::is_event()) return $classes;
        $classes[] = 'tng-platform-ui';
        $classes[] = 'tng-native-event-page';
        $classes[] = 'tng-hide-traveler-chrome';
        return array_values(array_unique($classes));
    }

    public static function enqueue(): void {
        if (!self::is_event()) return;
        wp_enqueue_style('tng-platform-ui', TNG_OS_URL . 'assets/css/platform-ui.css', [], '1.1.0');
        wp_enqueue_style('tng-app-router', TNG_OS_URL . 'assets/css/app-router.css', ['tng-platform-ui'], '1.7.0');
        wp_enqueue_style('tng-ui-kit', TNG_OS_URL . 'assets/css/ui-kit.css', ['tng-platform-ui'], '1.6.0');
        wp_enqueue_style('tng-event-ui', TNG_OS_URL . 'assets/css/event-ui.css', ['tng-ui-kit'], '0.1.0');
    }

    private static function meta(int $id, array $keys): string {
        foreach ($keys as $key) {
            $value = get_post_meta($id, $key, true);
            if (is_scalar($value) && trim((string)$value) !== '') return trim((string)$value);
        }
        return '';
    }

    public static function timestamp(int $id): int {
        $value = self::meta($id, ['start_date','event_date','date','st_start_date','event_start']);
        if (!$value) return 0;
        return is_numeric($value) ? (int)$value : (int)strtotime($value);
    }

    public static function time_label(int $id): string {
        $value = self::meta($id, ['start_time','event_time','time','doors_time']);
        return $value ?: '';
    }

    public static function venue(int $id): string {
        return self::meta($id, ['venue','location','address','st_location']) ?: 'The Caverns';
    }

    public static function ticket_url(int $id): string {
        $url = self::meta($id, ['ticket_url','external_url','booking_url','website','url']);
        return $url && filter_var($url, FILTER_VALIDATE_URL) ? $url : get_permalink($id);
    }

    public static function description(int $id): string {
        $content = strip_shortcodes((string)get_post_field('post_content', $id));
        $content = preg_replace('/\[[^\]]+\]/', ' ', $content);
        $content = wp_strip_all_tags($content);
        $content = preg_replace('/\s+/', ' ', trim($content));
        return $content ?: 'Plan your visit, save this event to a trip, and explore more nearby before or after the show.';
    }

    public static function related(int $id): array {
        $types = array_values(array_filter(['st_activity','activity'], 'post_type_exists'));
        if (!$types) return [];
        $query = new WP_Query([
            'post_type' => $types,
            'post_status' => 'publish',
            'post__not_in' => [$id],
            'posts_per_page' => 3,
            'orderby' => 'date',
            'order' => 'DESC',
            'ignore_sticky_posts' => true,
        ]);
        return array_values(array_filter($query->posts, static function($post): bool {
            $text = strtolower(get_the_title($post) . ' ' . get_post_field('post_content', $post->ID));
            return (bool)preg_match('/concert|show|festival|the caverns|ticket|live music/i', $text);
        }));
    }
}

TNG_Event_UI::boot();
