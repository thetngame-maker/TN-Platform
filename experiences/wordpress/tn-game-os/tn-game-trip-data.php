<?php
/**
 * Plugin Name: TN Game Trip Data
 * Description: Persistent saved places and trip actions for The TN Game app.
 * Version: 0.2.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Trip_Data {
    private const META_KEY = 'tng_saved_trip_items';

    public static function boot(): void {
        add_action('wp_ajax_tng_toggle_saved', [self::class, 'ajax_toggle']);
        add_action('wp_ajax_tng_reorder_saved', [self::class, 'ajax_reorder']);
        add_action('wp_enqueue_scripts', [self::class, 'assets'], 110);
    }

    public static function assets(): void {
        if (is_admin()) return;
        wp_enqueue_script('tng-trip-data', TNG_OS_URL . 'assets/js/trip-data.js', [], '0.2.0', true);
        wp_localize_script('tng-trip-data', 'TNGTripData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tng_trip_data'),
            'loggedIn' => is_user_logged_in(),
            'loginUrl' => wp_login_url((is_ssl() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/')),
            'savedIds' => is_user_logged_in() ? self::ids(get_current_user_id()) : [],
            'savedUrl' => home_url('/saved/'),
        ]);
    }

    public static function ids(int $user_id = 0): array {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) return [];
        $ids = get_user_meta($user_id, self::META_KEY, true);
        if (!is_array($ids)) $ids = [];
        return array_values(array_unique(array_filter(array_map('absint', $ids), static fn($id) => $id > 0 && get_post_status($id) === 'publish')));
    }

    public static function is_saved(int $post_id, int $user_id = 0): bool { return in_array($post_id, self::ids($user_id), true); }

    public static function toggle(int $post_id, int $user_id): array {
        $ids = self::ids($user_id);
        $saved = in_array($post_id, $ids, true);
        if ($saved) $ids = array_values(array_diff($ids, [$post_id]));
        else { array_unshift($ids, $post_id); $ids = array_slice(array_values(array_unique($ids)), 0, 100); }
        update_user_meta($user_id, self::META_KEY, $ids);
        return ['postId' => $post_id, 'saved' => !$saved, 'count' => count($ids), 'ids' => $ids];
    }

    public static function ajax_toggle(): void {
        check_ajax_referer('tng_trip_data', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error(['code' => 'login_required'], 401);
        $post_id = absint($_POST['postId'] ?? 0);
        if (!$post_id || get_post_status($post_id) !== 'publish') wp_send_json_error(['code' => 'invalid_post'], 400);
        wp_send_json_success(self::toggle($post_id, get_current_user_id()));
    }

    public static function ajax_reorder(): void {
        check_ajax_referer('tng_trip_data', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error(['code' => 'login_required'], 401);
        $submitted = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('absint', wp_unslash($_POST['ids'])) : [];
        $allowed = self::ids(get_current_user_id());
        $ordered = array_values(array_filter($submitted, static fn($id) => in_array($id, $allowed, true)));
        foreach ($allowed as $id) if (!in_array($id, $ordered, true)) $ordered[] = $id;
        update_user_meta(get_current_user_id(), self::META_KEY, $ordered);
        wp_send_json_success(['ids' => $ordered, 'count' => count($ordered)]);
    }

    public static function posts(int $user_id = 0): array {
        $ids = self::ids($user_id);
        if (!$ids) return [];
        $posts = get_posts(['post_type'=>'any','post_status'=>'publish','post__in'=>$ids,'orderby'=>'post__in','posts_per_page'=>100]);
        return is_array($posts) ? $posts : [];
    }

    public static function render_saved(): string {
        $logged_in = is_user_logged_in();
        $posts = $logged_in ? self::posts() : [];
        ob_start(); ?>
        <main class="tng-saved-screen tng-app-shell">
            <section class="tng-trips-hero">
                <div><span class="tng-eyebrow">Your trip collection</span><h1>Saved places</h1><p>Keep trails, events, food, sights, and destinations together while you plan your next day out.</p></div>
                <div class="tng-saved-count"><strong><?php echo esc_html((string) count($posts)); ?></strong><small>Saved</small></div>
            </section>
            <nav class="tng-trip-tabs" aria-label="Trip planning"><a href="<?php echo esc_url(home_url('/trips/')); ?>">🗺 Trips</a><a class="is-active" href="<?php echo esc_url(home_url('/saved/')); ?>">♡ Saved places</a><a href="<?php echo esc_url(home_url('/trip-builder/')); ?>">☰ Trip builder</a><a href="<?php echo esc_url(home_url('/completed/')); ?>">🥾 Completed</a></nav>
            <section class="tng-trips-section">
                <div class="tng-section__heading"><div><span class="tng-eyebrow">Build your route</span><h2><?php echo $posts ? 'Places you saved' : 'Start saving your favorites'; ?></h2><p><?php echo $posts ? 'Remove a stop at any time or open it to continue planning.' : 'Use Add to trip on trails, events, restaurants, sights, and destinations.'; ?></p></div><a href="<?php echo esc_url(home_url('/explore/')); ?>">Explore all</a></div>
                <?php if (!$logged_in): ?><div class="tng-trips-empty"><h3>Sign in to save places.</h3><p>Your saved trip will stay synced across phones, desktop, and TV games.</p><a class="tng-ui-button" href="<?php echo esc_url(wp_login_url(home_url('/saved/'))); ?>">Sign in</a></div>
                <?php elseif (!$posts): ?><div class="tng-trips-empty"><h3>Your trip is ready for its first stop.</h3><p>Explore the platform and add the places you want to visit.</p><a class="tng-ui-button" href="<?php echo esc_url(home_url('/explore/')); ?>">Find places</a></div>
                <?php else: ?><div class="tng-trip-suggestions">
                    <?php foreach ($posts as $post): $image=get_the_post_thumbnail_url($post->ID,'medium_large');$type=get_post_type_object(get_post_type($post->ID));$label=$type&&!empty($type->labels->singular_name)?$type->labels->singular_name:'Place'; ?>
                    <article class="tng-trip-suggestion" data-tng-saved-card="<?php echo esc_attr((string)$post->ID); ?>"><a class="tng-trip-suggestion__media" href="<?php echo esc_url(get_permalink($post)); ?>"<?php echo $image?' style="background-image:url('.esc_url($image).')"':''; ?>></a><div><small><?php echo esc_html($label); ?></small><h3><a href="<?php echo esc_url(get_permalink($post)); ?>"><?php echo esc_html(get_the_title($post)); ?></a></h3><button type="button" class="tng-trip-toggle is-saved" data-tng-trip-toggle data-post-id="<?php echo esc_attr((string)$post->ID); ?>">✓ Added to trip</button></div></article>
                    <?php endforeach; ?>
                </div><?php endif; ?>
            </section>
        </main>
        <?php return (string) ob_get_clean();
    }
}
TNG_Trip_Data::boot();
