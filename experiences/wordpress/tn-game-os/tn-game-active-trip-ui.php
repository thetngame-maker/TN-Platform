<?php
/**
 * Plugin Name: TN Game Active Trip UI
 * Description: Live itinerary and stop progress for saved TN Game trips.
 * Version: 0.1.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Active_Trip_UI {
    private const META_KEY = 'tng_active_trip_completed';

    public static function boot(): void {
        add_action('wp_ajax_tng_trip_stop_status', [self::class, 'ajax_status']);
    }

    private static function completed_ids(int $user_id = 0): array {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) return [];
        $ids = get_user_meta($user_id, self::META_KEY, true);
        return is_array($ids) ? array_values(array_unique(array_map('absint', $ids))) : [];
    }

    public static function ajax_status(): void {
        check_ajax_referer('tng_active_trip', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error(['code' => 'login_required'], 401);
        $post_id = absint($_POST['postId'] ?? 0);
        $complete = !empty($_POST['complete']);
        if (!$post_id || get_post_status($post_id) !== 'publish') wp_send_json_error(['code' => 'invalid_post'], 400);
        $ids = self::completed_ids();
        if ($complete && !in_array($post_id, $ids, true)) $ids[] = $post_id;
        if (!$complete) $ids = array_values(array_diff($ids, [$post_id]));
        update_user_meta(get_current_user_id(), self::META_KEY, $ids);
        $saved = class_exists('TNG_Trip_Data') ? TNG_Trip_Data::ids() : [];
        $done = count(array_intersect($saved, $ids));
        wp_send_json_success(['complete' => $complete, 'done' => $done, 'total' => count($saved)]);
    }

    private static function directions(int $id): string {
        $lat = get_post_meta($id, 'latitude', true) ?: get_post_meta($id, 'lat', true);
        $lng = get_post_meta($id, 'longitude', true) ?: get_post_meta($id, 'lng', true);
        $address = get_post_meta($id, 'address', true) ?: get_post_meta($id, 'st_address', true);
        if ($lat && $lng) return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($lat . ',' . $lng);
        if ($address) return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode((string)$address);
        return home_url('/map/');
    }

    public static function render(): string {
        $logged_in = is_user_logged_in();
        $posts = ($logged_in && class_exists('TNG_Trip_Data')) ? TNG_Trip_Data::posts() : [];
        $completed = $logged_in ? self::completed_ids() : [];
        $done = count(array_intersect(array_map(static fn($p) => $p->ID, $posts), $completed));
        $total = count($posts);
        $percent = $total ? (int) round(($done / $total) * 100) : 0;
        $next = null;
        foreach ($posts as $post) { if (!in_array($post->ID, $completed, true)) { $next = $post; break; } }
        wp_localize_script('tng-active-trip', 'TNGActiveTrip', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tng_active_trip'),
        ]);
        ob_start(); ?>
        <main class="tng-active-trip-screen tng-app-shell">
            <section class="tng-active-trip-hero">
                <div><span class="tng-eyebrow">Trip mode</span><h1><?php echo $total && $done === $total ? 'Trip complete!' : 'Your Tennessee day.'; ?></h1><p>Follow your saved route, check off each stop, and keep the day moving.</p></div>
                <div class="tng-active-trip-score"><strong data-tng-trip-progress><?php echo esc_html($done . '/' . $total); ?></strong><small>Stops complete</small></div>
            </section>

            <nav class="tng-trip-tabs" aria-label="Trip planning">
                <a href="<?php echo esc_url(home_url('/trips/')); ?>">🗺 Trips</a>
                <a href="<?php echo esc_url(home_url('/saved/')); ?>">♡ Saved places</a>
                <a href="<?php echo esc_url(home_url('/trip-builder/')); ?>">☰ Trip builder</a>
                <a class="is-active" href="<?php echo esc_url(home_url('/active-trip/')); ?>">▶ Trip mode</a>
            </nav>

            <?php if (!$logged_in): ?>
                <section class="tng-active-trip-empty"><h2>Sign in to start trip mode.</h2><p>Your itinerary and progress will stay synced to your Explorer account.</p><a class="tng-ui-button" href="<?php echo esc_url(wp_login_url(home_url('/active-trip/'))); ?>">Sign in</a></section>
            <?php elseif (!$posts): ?>
                <section class="tng-active-trip-empty"><h2>Your route needs a few stops.</h2><p>Save places, arrange them, then return here to begin the day.</p><a class="tng-ui-button" href="<?php echo esc_url(home_url('/explore/')); ?>">Find places</a></section>
            <?php else: ?>
                <section class="tng-active-trip-progress-card">
                    <div><span class="tng-eyebrow">Today’s progress</span><h2><?php echo esc_html($done === $total ? 'You finished every stop.' : ($next ? 'Next: ' . get_the_title($next) : 'Keep exploring')); ?></h2></div>
                    <div class="tng-ui-progress"><span data-tng-trip-progress-bar style="width:<?php echo esc_attr((string)$percent); ?>%"></span></div>
                </section>

                <div class="tng-active-trip-layout">
                    <section class="tng-active-trip-route">
                        <div class="tng-section__heading"><div><span class="tng-eyebrow">Your itinerary</span><h2>Stops for today</h2></div><a href="<?php echo esc_url(home_url('/trip-builder/')); ?>">Edit route</a></div>
                        <ol class="tng-active-trip-list">
                            <?php foreach ($posts as $index => $post): $is_done = in_array($post->ID, $completed, true); $image = get_the_post_thumbnail_url($post->ID, 'medium'); ?>
                                <li class="tng-active-trip-stop<?php echo $is_done ? ' is-complete' : ''; ?>" data-trip-stop="<?php echo esc_attr((string)$post->ID); ?>">
                                    <span class="tng-active-trip-stop__number"><?php echo $is_done ? '✓' : esc_html((string)($index + 1)); ?></span>
                                    <span class="tng-active-trip-stop__media"<?php echo $image ? ' style="background-image:url(' . esc_url($image) . ')"' : ''; ?>></span>
                                    <div class="tng-active-trip-stop__copy"><small><?php echo esc_html(get_post_type_object(get_post_type($post->ID))->labels->singular_name ?? 'Stop'); ?></small><h3><?php echo esc_html(get_the_title($post)); ?></h3><a href="<?php echo esc_url(get_permalink($post)); ?>">View details</a></div>
                                    <div class="tng-active-trip-stop__actions"><a href="<?php echo esc_url(self::directions($post->ID)); ?>" target="_blank" rel="noopener">Directions</a><button type="button" data-trip-complete data-post-id="<?php echo esc_attr((string)$post->ID); ?>" aria-pressed="<?php echo $is_done ? 'true' : 'false'; ?>"><?php echo $is_done ? 'Undo' : 'Mark complete'; ?></button></div>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </section>

                    <aside class="tng-active-trip-next">
                        <span class="tng-eyebrow">Next stop</span>
                        <?php if ($next): ?>
                            <h2><?php echo esc_html(get_the_title($next)); ?></h2><p>Open directions when you are ready to continue your trip.</p>
                            <a class="tng-ui-button" href="<?php echo esc_url(self::directions($next->ID)); ?>" target="_blank" rel="noopener">Get directions</a>
                            <a class="tng-ui-button tng-ui-button--secondary" href="<?php echo esc_url(get_permalink($next)); ?>">View stop</a>
                        <?php else: ?>
                            <h2>Adventure complete.</h2><p>You visited every stop in this trip.</p><a class="tng-ui-button" href="<?php echo esc_url(home_url('/completed/')); ?>">View history</a>
                        <?php endif; ?>
                    </aside>
                </div>
            <?php endif; ?>
        </main>
        <?php return (string) ob_get_clean();
    }
}

TNG_Active_Trip_UI::boot();
