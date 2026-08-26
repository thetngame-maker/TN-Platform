<?php
/**
 * Plugin Name: TN Game Past Trips UI
 * Description: Archived Explorer trip history and completed itinerary summaries.
 * Version: 0.1.1
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Past_Trips_UI {
    private const META_KEY = 'tng_past_trips';

    public static function boot(): void {
        add_action('wp_ajax_tng_archive_active_trip', [self::class, 'ajax_archive']);
        add_action('wp_enqueue_scripts', [self::class, 'assets'], 120);
    }

    public static function assets(): void {
        if (is_admin()) return;
        wp_enqueue_script('tng-past-trips', TNG_OS_URL . 'assets/js/past-trips.js', [], '0.1.0', true);
        wp_localize_script('tng-past-trips', 'TNGPastTrips', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tng_archive_trip'),
            'historyUrl' => home_url('/past-trips/'),
        ]);
    }

    public static function history(int $user_id = 0): array {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) return [];
        $history = get_user_meta($user_id, self::META_KEY, true);
        return is_array($history) ? $history : [];
    }

    public static function ajax_archive(): void {
        check_ajax_referer('tng_archive_trip', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error(['code' => 'login_required'], 401);
        $user_id = get_current_user_id();
        $posts = class_exists('TNG_Trip_Data') ? TNG_Trip_Data::posts($user_id) : [];
        if (!$posts) wp_send_json_error(['code' => 'empty_trip'], 400);
        $saved_ids = array_map(static fn($post) => (int)$post->ID, $posts);
        $completed = get_user_meta($user_id, 'tng_active_trip_completed', true);
        $completed = is_array($completed) ? array_map('absint', $completed) : [];
        if (count(array_intersect($saved_ids, $completed)) !== count($saved_ids)) wp_send_json_error(['code' => 'trip_incomplete'], 400);
        $trip = [
            'id' => wp_generate_uuid4(),
            'completed_at' => current_time('mysql'),
            'items' => array_map(static function ($post): array {
                return ['id'=>(int)$post->ID,'title'=>get_the_title($post),'url'=>get_permalink($post),'image'=>get_the_post_thumbnail_url($post->ID,'medium_large') ?: ''];
            }, $posts),
        ];
        $history = self::history($user_id);
        array_unshift($history, $trip);
        update_user_meta($user_id, self::META_KEY, array_slice($history, 0, 50));
        update_user_meta($user_id, 'tng_active_trip_completed', []);
        update_user_meta($user_id, 'tng_saved_trip_items', []);
        wp_send_json_success(['redirect'=>home_url('/past-trips/'),'trip'=>$trip]);
    }

    public static function render(): string {
        $logged_in = is_user_logged_in();
        $history = $logged_in ? self::history() : [];
        ob_start(); ?>
        <main class="tng-past-trips-screen tng-app-shell">
            <section class="tng-past-trips-hero"><div><span class="tng-eyebrow">Adventure history</span><h1>Past trips</h1><p>Relive completed days, revisit favorite stops, and plan your next Tennessee adventure.</p></div><div class="tng-past-trips-count"><strong><?php echo esc_html((string)count($history)); ?></strong><small>Trips</small></div></section>
            <nav class="tng-trip-tabs" aria-label="Trip planning"><a href="<?php echo esc_url(home_url('/trips/')); ?>">🗺 Trips</a><a href="<?php echo esc_url(home_url('/saved/')); ?>">♡ Saved places</a><a href="<?php echo esc_url(home_url('/trip-builder/')); ?>">☰ Trip builder</a><a class="is-active" href="<?php echo esc_url(home_url('/past-trips/')); ?>">↺ Past trips</a></nav>
            <section class="tng-past-trips-content">
                <div class="tng-section__heading"><div><span class="tng-eyebrow">Your history</span><h2><?php echo $history ? 'Completed Tennessee days' : 'Your first trip is waiting'; ?></h2><p><?php echo $history ? 'Every archived itinerary stays connected to your Explorer account.' : 'Complete every stop in Trip Mode, then archive the day here.'; ?></p></div><a href="<?php echo esc_url(home_url('/explore/')); ?>">Plan another</a></div>
                <?php if (!$logged_in): ?><div class="tng-past-trips-empty"><h3>Sign in to see past trips.</h3><a class="tng-ui-button" href="<?php echo esc_url(wp_login_url(home_url('/past-trips/'))); ?>">Sign in</a></div>
                <?php elseif (!$history): ?><div class="tng-past-trips-empty"><span>↺</span><h3>No archived trips yet.</h3><p>Finish your active itinerary and it will appear here.</p><a class="tng-ui-button" href="<?php echo esc_url(home_url('/active-trip/')); ?>">Open trip mode</a></div>
                <?php else: ?><div class="tng-past-trips-list"><?php foreach ($history as $index => $trip): $items = is_array($trip['items'] ?? null) ? $trip['items'] : []; ?><article class="tng-past-trip-card"><div class="tng-past-trip-card__head"><div><small><?php echo esc_html(mysql2date('M j, Y', (string)($trip['completed_at'] ?? ''))); ?></small><h3>Tennessee Trip <?php echo esc_html((string)(count($history)-$index)); ?></h3></div><strong><?php echo esc_html((string)count($items)); ?> stops</strong></div><div class="tng-past-trip-stops"><?php foreach ($items as $item): ?><a href="<?php echo esc_url((string)($item['url'] ?? '#')); ?>"><span<?php echo !empty($item['image']) ? ' style="background-image:url(' . esc_url((string)$item['image']) . ')"' : ''; ?>></span><b><?php echo esc_html((string)($item['title'] ?? 'Stop')); ?></b></a><?php endforeach; ?></div></article><?php endforeach; ?></div><?php endif; ?>
            </section>
        </main>
        <?php return (string)ob_get_clean();
    }
}
TNG_Past_Trips_UI::boot();
