<?php
/**
 * Plugin Name: TN Game Trip Builder UI
 * Description: Native route builder for saved TN Game places.
 * Version: 0.2.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Trip_Builder_UI {
    public static function render(): string {
        $logged_in = is_user_logged_in();
        $posts = ($logged_in && class_exists('TNG_Trip_Data')) ? TNG_Trip_Data::posts() : [];
        ob_start(); ?>
        <main class="tng-builder-screen tng-app-shell">
            <section class="tng-builder-hero">
                <div><span class="tng-eyebrow">Plan the day</span><h1>Build your trip.</h1><p>Put saved stops in the order you want to visit them, then start Trip Mode when you are ready to go.</p></div>
                <div class="tng-builder-hero__count"><strong><?php echo esc_html((string) count($posts)); ?></strong><small>Stops</small></div>
            </section>

            <nav class="tng-trip-tabs" aria-label="Trip planning">
                <a href="<?php echo esc_url(home_url('/trips/')); ?>">🗺 Trips</a>
                <a href="<?php echo esc_url(home_url('/saved/')); ?>">♡ Saved places</a>
                <a class="is-active" href="<?php echo esc_url(home_url('/trip-builder/')); ?>">☰ Trip builder</a>
                <a href="<?php echo esc_url(home_url('/active-trip/')); ?>">▶ Trip mode</a>
            </nav>

            <?php if (!$logged_in): ?>
                <section class="tng-builder-empty"><span>🗺</span><h2>Sign in to build a trip.</h2><p>Your route will stay synced to your Explorer account.</p><a class="tng-ui-button" href="<?php echo esc_url(wp_login_url(home_url('/trip-builder/'))); ?>">Sign in</a></section>
            <?php elseif (!$posts): ?>
                <section class="tng-builder-empty"><span>＋</span><h2>Add a few places first.</h2><p>Save trails, food, sights, destinations, and events, then arrange them here.</p><a class="tng-ui-button" href="<?php echo esc_url(home_url('/explore/')); ?>">Explore places</a></section>
            <?php else: ?>
                <div class="tng-builder-layout">
                    <section class="tng-builder-route">
                        <div class="tng-builder-heading"><div><span class="tng-eyebrow">Your route</span><h2>Arrange your stops</h2><p>Drag cards or use the arrow buttons to change the order.</p></div><span class="tng-builder-status" data-tng-builder-status>Saved</span></div>
                        <ol class="tng-builder-list" data-tng-builder-list>
                            <?php foreach ($posts as $index => $post): $image = get_the_post_thumbnail_url($post->ID, 'medium'); ?>
                                <li class="tng-builder-stop" draggable="true" data-post-id="<?php echo esc_attr((string)$post->ID); ?>">
                                    <span class="tng-builder-stop__number"><?php echo esc_html((string)($index + 1)); ?></span>
                                    <span class="tng-builder-stop__media"<?php echo $image ? ' style="background-image:url(' . esc_url($image) . ')"' : ''; ?>></span>
                                    <div class="tng-builder-stop__copy"><small><?php echo esc_html(get_post_type_object(get_post_type($post->ID))->labels->singular_name ?? 'Place'); ?></small><h3><a href="<?php echo esc_url(get_permalink($post)); ?>"><?php echo esc_html(get_the_title($post)); ?></a></h3></div>
                                    <div class="tng-builder-stop__actions"><button type="button" data-move="up" aria-label="Move up">↑</button><button type="button" data-move="down" aria-label="Move down">↓</button><button type="button" class="is-remove" data-tng-trip-toggle data-post-id="<?php echo esc_attr((string)$post->ID); ?>" aria-label="Remove stop">×</button></div>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </section>

                    <aside class="tng-builder-summary">
                        <span class="tng-eyebrow">Trip summary</span><h2>Your Tennessee day</h2>
                        <dl><div><dt>Stops</dt><dd data-tng-builder-count><?php echo esc_html((string)count($posts)); ?></dd></div><div><dt>Suggested time</dt><dd><?php echo esc_html((string) max(2, count($posts) * 2)); ?>–<?php echo esc_html((string) max(4, count($posts) * 3)); ?> hr</dd></div></dl>
                        <p>Trip Mode keeps the route, directions, and completion status together while you explore.</p>
                        <a class="tng-ui-button" href="<?php echo esc_url(home_url('/active-trip/')); ?>">Start trip mode</a>
                        <a class="tng-ui-button tng-ui-button--secondary" href="<?php echo esc_url(home_url('/saved/')); ?>">Add more stops</a>
                    </aside>
                </div>
            <?php endif; ?>
        </main>
        <?php return (string) ob_get_clean();
    }
}
