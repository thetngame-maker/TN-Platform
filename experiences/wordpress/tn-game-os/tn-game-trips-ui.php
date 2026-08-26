<?php
/**
 * Plugin Name: TN Game Trips UI
 * Description: Native TN Game trip planning dashboard for the app router.
 * Version: 0.4.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Trips_UI {
    private static function suggested_places(): array {
        $types = array_values(array_filter(['st_activity','activity','top_sight','tng_destination','st_location'], 'post_type_exists'));
        if (!$types) return [];
        $exclude = class_exists('TNG_Trip_Data') ? TNG_Trip_Data::ids() : [];
        $query = new WP_Query(['post_type'=>$types,'post_status'=>'publish','posts_per_page'=>6,'post__not_in'=>$exclude,'ignore_sticky_posts'=>true,'orderby'=>'modified','order'=>'DESC']);
        return $query->posts;
    }

    private static function cards(array $posts, bool $saved = false): string {
        if (!$posts) return '<div class="tng-trips-empty">' . ($saved ? 'No saved places yet.' : 'Suggested stops will appear here as places are published.') . '</div>';
        ob_start(); echo '<div class="tng-trip-suggestions">';
        foreach ($posts as $post) {
            $id=$post->ID; $image=get_the_post_thumbnail_url($id,'medium_large'); $type=get_post_type_object(get_post_type($id));
            $label=$type&&!empty($type->labels->singular_name)?$type->labels->singular_name:'Place';
            echo '<article class="tng-trip-suggestion" data-tng-saved-card="'.esc_attr((string)$id).'">';
            echo '<a class="tng-trip-suggestion__media" href="'.esc_url(get_permalink($id)).'"'.($image?' style="background-image:url('.esc_url($image).')"':'').'></a>';
            echo '<div><small>'.esc_html($label).'</small><h3><a href="'.esc_url(get_permalink($id)).'">'.esc_html(get_the_title($id)).'</a></h3><button type="button" data-tng-trip-toggle data-post-id="'.esc_attr((string)$id).'"'.($saved?' class="is-saved"':'').'>'.($saved?'✓ Added to trip':'＋ Add to trip').'</button></div>';
            echo '</article>';
        }
        echo '</div>'; return (string)ob_get_clean();
    }

    private static function resume_panel(array $posts): string {
        $total = count($posts);
        $completed = is_user_logged_in() ? get_user_meta(get_current_user_id(), 'tng_active_trip_completed', true) : [];
        $completed = is_array($completed) ? array_values(array_unique(array_map('absint', $completed))) : [];
        $ids = array_map(static fn($post): int => (int)$post->ID, $posts);
        $done = count(array_intersect($ids, $completed));
        $percent = $total ? (int)round(($done / $total) * 100) : 0;
        $source = is_user_logged_in() && class_exists('TNG_Trip_Data') ? TNG_Trip_Data::active_source(get_current_user_id()) : [];
        $source_title = sanitize_text_field((string)($source['title'] ?? ''));
        $next = null;
        foreach ($posts as $post) {
            if (!in_array((int)$post->ID, $completed, true)) { $next = $post; break; }
        }

        ob_start();
        if (!$posts): ?>
            <section class="tng-current-trip tng-current-trip--empty">
                <div class="tng-current-trip__copy"><span class="tng-eyebrow">Your next adventure</span><h2>Plan a Tennessee day.</h2><p>Save a few trails, places, events, or restaurants to create your route.</p></div>
                <div class="tng-current-trip__actions"><a class="tng-ui-button tng-ui-button--secondary" href="<?php echo esc_url(home_url('/explore/')); ?>">Explore places</a><a class="tng-ui-button" href="<?php echo esc_url(home_url('/saved/')); ?>">Saved places</a></div>
            </section>
        <?php else: ?>
            <section class="tng-current-trip">
                <div class="tng-current-trip__copy"><span class="tng-eyebrow"><?php echo $done === $total ? 'Trip ready to archive' : ($source_title !== '' ? 'Saved Adventure · Active trip' : 'Active trip'); ?></span><h2><?php echo esc_html($done === $total ? 'You completed every stop.' : ($source_title !== '' ? $source_title : ($next ? 'Next: '.get_the_title($next) : 'Your Tennessee day'))); ?></h2><p><?php echo esc_html($done.' of '.$total.' stops complete'.($source_title !== '' && $next ? ' · Next: '.get_the_title($next) : '')); ?></p><div class="tng-ui-progress"><span style="width:<?php echo esc_attr((string)$percent); ?>%"></span></div></div>
                <div class="tng-current-trip__actions"><a class="tng-ui-button tng-ui-button--secondary" href="<?php echo esc_url(home_url('/trip-builder/')); ?>">Edit route</a><a class="tng-ui-button" href="<?php echo esc_url(home_url('/active-trip/')); ?>"><?php echo $done === $total ? 'Finish trip' : 'Trip mode'; ?></a></div>
            </section>
        <?php endif;
        return (string)ob_get_clean();
    }

    public static function render(): string {
        $logged_in=is_user_logged_in();
        $saved_posts=($logged_in&&class_exists('TNG_Trip_Data'))?TNG_Trip_Data::posts():[];
        $saved_count=count($saved_posts);
        ob_start(); ?>
        <main class="tng-trips-screen tng-app-shell">
            <section class="tng-trips-hero"><div><span class="tng-eyebrow">Plan your adventure</span><h1>Trips made simple.</h1><p>Save places, organize stops, and turn a day out into a complete TN Game adventure.</p></div><a class="tng-ui-button" href="<?php echo esc_url(home_url('/saved/')); ?>">View saved places</a></section>
            <section class="tng-trip-actions" aria-label="Trip actions">
                <a href="<?php echo esc_url(home_url('/active-trip/')); ?>"><span>▶</span><strong>Active trip</strong><small><?php echo $saved_count ? 'Continue your current route' : 'Plan your first route'; ?></small></a>
                <a href="<?php echo esc_url(home_url('/saved/')); ?>"><span>♡</span><strong>Saved places<?php echo $saved_count?' · '.esc_html((string)$saved_count):''; ?></strong><small>Review places you bookmarked</small></a>
                <a href="<?php echo esc_url(home_url('/recaps/')); ?>"><span>✦</span><strong>Adventure recaps</strong><small>Relive completed adventures</small></a>
                <a href="<?php echo esc_url(home_url('/adventures/')); ?>"><span>◇</span><strong>Saved Adventures</strong><small>Reopen your Adventure AI plans</small></a>
                <a href="<?php echo esc_url($logged_in?home_url('/profile/'):wp_login_url(home_url('/trips/'))); ?>"><span>★</span><strong>Trip rewards</strong><small>See XP and achievements</small></a>
            </section>
            <?php if ($saved_posts): ?><section class="tng-trips-section"><div class="tng-section__heading"><div><span class="tng-eyebrow">Your route</span><h2>Saved for this trip</h2><p>These stops stay synced to your Explorer account.</p></div><a href="<?php echo esc_url(home_url('/saved/')); ?>">Manage all</a></div><?php echo self::cards(array_slice($saved_posts,0,6),true); ?></section><?php endif; ?>
            <?php echo self::resume_panel($saved_posts); ?>
            <section class="tng-trips-section"><div class="tng-section__heading"><div><span class="tng-eyebrow">Ideas for your route</span><h2>Add another stop</h2><p>Popular places and adventures you can build into your next trip.</p></div><a href="<?php echo esc_url(home_url('/explore/')); ?>">Explore all</a></div><?php echo self::cards(self::suggested_places()); ?></section>
            <section class="tng-trip-builder-card"><div><span class="tng-eyebrow">Adventure AI · v2</span><h2>Describe the day. TN Game builds it.</h2><p>Create, edit, and save reusable Tennessee itineraries from real trails, food, sights, destinations, and events.</p></div><div class="tng-current-trip__actions"><a class="tng-ui-button tng-ui-button--secondary" href="<?php echo esc_url(home_url('/adventures/')); ?>">Saved Adventures</a><a class="tng-ui-button" href="<?php echo esc_url(home_url('/adventure-ai/')); ?>">Build a plan</a></div></section>
        </main>
        <?php return (string)ob_get_clean();
    }
}
