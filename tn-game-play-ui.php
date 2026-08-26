<?php
/**
 * Plugin Name: TN Game Play UI
 * Plugin URI: https://thetngame.com
 * Description: Native TN Game Play dashboard for starting, resuming, and discovering games.
 * Version: 0.1.0
 * Author: The TN Game
 * Text Domain: tn-game-play-ui
 */
if (!defined('ABSPATH')) exit;

final class TNG_Play_UI {
    public static function boot(): void {
        add_shortcode('tng_play_app', [self::class, 'render']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue'], 55);
        add_filter('body_class', [self::class, 'body_class']);
    }

    private static function is_play_page(): bool {
        global $post;
        return is_page('play') || ($post instanceof WP_Post && has_shortcode((string) $post->post_content, 'tng_play_app'));
    }

    public static function enqueue(): void {
        if (!self::is_play_page()) return;
        wp_enqueue_style(
            'tng-play-ui',
            plugin_dir_url(__FILE__) . 'assets/css/play-ui.css',
            ['tng-platform-ui'],
            '0.1.0'
        );
    }

    public static function body_class(array $classes): array {
        if (self::is_play_page()) $classes[] = 'tng-play-dashboard-page';
        return $classes;
    }

    private static function game_posts(): array {
        $types = array_values(array_filter(['st_activity', 'activity', 'tng_game', 'game'], 'post_type_exists'));
        if (!$types) return [];

        $query = new WP_Query([
            'post_type' => $types,
            'post_status' => 'publish',
            'posts_per_page' => 8,
            'ignore_sticky_posts' => true,
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);

        $games = [];
        foreach ($query->posts as $post) {
            $title = get_the_title($post);
            $content = strtolower($title . ' ' . get_post_field('post_content', $post->ID));
            $looks_playable = preg_match('/game|quest|challenge|scavenger|color clash|checkpoint|trail/', $content);
            if ($looks_playable) $games[] = $post;
        }

        return $games ?: array_slice($query->posts, 0, 4);
    }

    private static function render_game_cards(): string {
        $games = self::game_posts();
        if (!$games) {
            return '<div class="tng-play-empty"><span>🎮</span><h3>Your first games will appear here.</h3><p>Publish a playable activity or quest and it will be surfaced automatically.</p></div>';
        }

        ob_start();
        echo '<div class="tng-game-grid">';
        foreach ($games as $game) {
            $id = $game->ID;
            $image = get_the_post_thumbnail_url($id, 'large');
            $excerpt = strip_shortcodes((string) get_post_field('post_excerpt', $id));
            if (!$excerpt) $excerpt = strip_shortcodes((string) get_post_field('post_content', $id));
            $excerpt = wp_trim_words(wp_strip_all_tags($excerpt), 13, '…');
            $difficulty = get_post_meta($id, 'difficulty', true) ?: get_post_meta($id, 'trail_difficulty', true);
            $xp = get_post_meta($id, 'xp', true) ?: get_post_meta($id, 'xp_available', true);
            echo '<article class="tng-game-card">';
            echo '<a class="tng-game-card__media' . ($image ? '' : ' is-placeholder') . '" href="' . esc_url(get_permalink($id)) . '"' . ($image ? ' style="background-image:url(' . esc_url($image) . ')"' : '') . '><span>Playable</span></a>';
            echo '<div class="tng-game-card__body"><h3><a href="' . esc_url(get_permalink($id)) . '">' . esc_html(get_the_title($id)) . '</a></h3>';
            echo '<div class="tng-game-card__meta">';
            if ($difficulty) echo '<span>⚡ ' . esc_html($difficulty) . '</span>';
            if ($xp) echo '<span>⭐ ' . esc_html($xp) . ' XP</span>';
            if (!$difficulty && !$xp) echo '<span>📍 Explore & play</span>';
            echo '</div>';
            if ($excerpt) echo '<p>' . esc_html($excerpt) . '</p>';
            echo '<a class="tng-game-card__button" href="' . esc_url(get_permalink($id)) . '">View game <span aria-hidden="true">→</span></a></div></article>';
        }
        echo '</div>';
        return (string) ob_get_clean();
    }

    public static function render(): string {
        $cards = self::render_game_cards();
        $logged_in = is_user_logged_in();
        ob_start(); ?>
        <main class="tng-play-dashboard tng-app-shell">
            <section class="tng-play-hero">
                <div class="tng-play-hero__copy">
                    <span class="tng-eyebrow">Choose your next adventure</span>
                    <h1>Ready to play?</h1>
                    <p>Start a nearby challenge, continue your active adventure, or jump into a quick game.</p>
                </div>
                <a class="tng-play-primary" href="<?php echo esc_url(home_url('/map/')); ?>"><span>▶</span><strong>Find a game near me</strong><small>Open the game map</small></a>
            </section>

            <section class="tng-play-modes" aria-label="Ways to play">
                <a href="<?php echo esc_url(home_url('/map/')); ?>"><span>📍</span><strong>Nearby</strong><small>Location-based adventures</small></a>
                <a href="<?php echo esc_url(home_url('/games/')); ?>"><span>⚡</span><strong>Quick Play</strong><small>Start playing immediately</small></a>
                <a href="<?php echo esc_url(home_url('/trips/')); ?>"><span>🗺️</span><strong>Trip Games</strong><small>Play along your route</small></a>
                <a href="<?php echo esc_url($logged_in ? home_url('/friends/') : wp_login_url(home_url('/play/'))); ?>"><span>👥</span><strong>With Friends</strong><small>Challenge your group</small></a>
            </section>

            <section class="tng-active-adventure">
                <div><span class="tng-eyebrow">Active adventure</span><h2>Continue where you left off</h2><p>Your current game progress stays synced with your Explorer profile.</p></div>
                <div class="tng-active-adventure__actions"><a href="<?php echo esc_url(home_url('/active-trip/')); ?>">Resume</a><a href="<?php echo esc_url(home_url('/trips/')); ?>">My games</a></div>
            </section>

            <section class="tng-play-section">
                <div class="tng-section__heading"><div><span class="tng-eyebrow">Playable now</span><h2>Pick a game</h2><p>Challenges and adventures available across The TN Game.</p></div><a href="<?php echo esc_url(home_url('/games/')); ?>">View all</a></div>
                <?php echo $cards; ?>
            </section>

            <section class="tng-play-create">
                <div><span class="tng-eyebrow">Make it social</span><h2>Bring the game to the big screen.</h2><p>Use your account to join TV games, play against bots, and compete with friends.</p></div>
                <div class="tng-play-create__actions"><a class="tng-button" href="<?php echo esc_url(home_url('/tv/')); ?>">TV Games</a><a href="<?php echo esc_url($logged_in ? home_url('/profile/') : wp_registration_url()); ?>">Explorer account</a></div>
            </section>
        </main>
        <?php return (string) ob_get_clean();
    }
}

TNG_Play_UI::boot();
