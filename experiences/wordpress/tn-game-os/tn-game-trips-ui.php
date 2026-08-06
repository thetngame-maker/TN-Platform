<?php
/**
 * Plugin Name: TN Game Trips UI
 * Description: Native TN Game trip planning dashboard for the app router.
 * Version: 0.2.0
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

    public static function render(): string {
        $logged_in=is_user_logged_in();
        $saved_posts=($logged_in&&class_exists('TNG_Trip_Data'))?TNG_Trip_Data::posts():[];
        $saved_count=count($saved_posts);
        ob_start(); ?>
        <main class="tng-trips-screen tng-app-shell">
            <section class="tng-trips-hero"><div><span class="tng-eyebrow">Plan your adventure</span><h1>Trips made simple.</h1><p>Save places, organize stops, and turn a day out into a complete TN Game adventure.</p></div><a class="tng-ui-button" href="<?php echo esc_url(home_url('/saved/')); ?>">View saved places</a></section>
            <section class="tng-trip-actions" aria-label="Trip actions">
                <a href="<?php echo esc_url(home_url('/active-trip/')); ?>"><span>▶</span><strong>Active trip</strong><small>Continue your current route</small></a>
                <a href="<?php echo esc_url(home_url('/saved/')); ?>"><span>♡</span><strong>Saved places<?php echo $saved_count?' · '.esc_html((string)$saved_count):''; ?></strong><small>Review places you bookmarked</small></a>
                <a href="<?php echo esc_url(home_url('/completed/')); ?>"><span>↺</span><strong>Past trips</strong><small>Relive completed adventures</small></a>
                <a href="<?php echo esc_url($logged_in?home_url('/profile/'):wp_login_url(home_url('/trips/'))); ?>"><span>★</span><strong>Trip rewards</strong><small>See XP and achievements</small></a>
            </section>
            <?php if ($saved_posts): ?><section class="tng-trips-section"><div class="tng-section__heading"><div><span class="tng-eyebrow">Your route</span><h2>Saved for this trip</h2><p>These stops stay synced to your Explorer account.</p></div><a href="<?php echo esc_url(home_url('/saved/')); ?>">Manage all</a></div><?php echo self::cards(array_slice($saved_posts,0,6),true); ?></section><?php endif; ?>
            <section class="tng-current-trip"><div class="tng-current-trip__copy"><span class="tng-eyebrow">Current adventure</span><h2>Denny Cove Overlook Trail</h2><p>2 of 3 stops complete</p><div class="tng-ui-progress"><span style="width:67%"></span></div></div><div class="tng-current-trip__actions"><a class="tng-ui-button tng-ui-button--secondary" href="<?php echo esc_url(home_url('/active-trip/')); ?>">Continue</a><a class="tng-ui-button" href="<?php echo esc_url(home_url('/trip-mode/')); ?>">Trip mode</a></div></section>
            <section class="tng-trips-section"><div class="tng-section__heading"><div><span class="tng-eyebrow">Ideas for your route</span><h2>Add another stop</h2><p>Popular places and adventures you can build into your next trip.</p></div><a href="<?php echo esc_url(home_url('/explore/')); ?>">Explore all</a></div><?php echo self::cards(self::suggested_places()); ?></section>
            <section class="tng-trip-builder-card"><div><span class="tng-eyebrow">Smart planning</span><h2>Let TN Game organize the day.</h2><p>Choose your interests and available time, then build a route using trails, food, sights, and events.</p></div><a class="tng-ui-button" href="<?php echo esc_url(home_url('/saved/')); ?>">Review my stops</a></section>
        </main>
        <?php return (string)ob_get_clean();
    }
}
