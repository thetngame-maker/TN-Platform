<?php
/**
 * Plugin Name: TN Game Search UI
 * Description: Unified native search across TN Game trails, events, food, sights, destinations, and games.
 * Version: 0.1.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Search_UI {
    private static function post_types(): array {
        return array_values(array_filter([
            'st_activity','activity','top_sight','tng_destination','st_location','post'
        ], 'post_type_exists'));
    }

    private static function clean_excerpt(int $id): string {
        $source = has_excerpt($id) ? get_post_field('post_excerpt', $id) : get_post_field('post_content', $id);
        $source = strip_shortcodes((string) $source);
        $source = preg_replace('/\[[^\]]+\]/', ' ', $source);
        $source = wp_strip_all_tags((string) $source);
        $source = preg_replace('/\s+/', ' ', trim((string) $source));
        return wp_trim_words($source, 18, '…');
    }

    private static function label(int $id): string {
        $type = get_post_type($id);
        $title = strtolower(get_the_title($id));
        $terms = wp_get_post_terms($id, get_object_taxonomies($type), ['fields' => 'names']);
        $haystack = strtolower($title . ' ' . implode(' ', is_wp_error($terms) ? [] : $terms));
        if (str_contains($haystack, 'trail') || str_contains($haystack, 'hiking')) return 'Trail';
        if (str_contains($haystack, 'concert') || str_contains($haystack, 'caverns') || get_post_meta($id, 'event_date', true) || get_post_meta($id, 'start_date', true)) return 'Event';
        if (preg_match('/restaurant|food|drink|cafe|coffee|bakery|kitchen|grill|pizza|taco|burger/', $haystack)) return 'Food & Drink';
        if ($type === 'top_sight') return 'Top Sight';
        if (in_array($type, ['tng_destination','st_location'], true)) return 'Destination';
        return 'Explore';
    }

    private static function results(string $query): array {
        $types = self::post_types();
        if (!$types) return [];
        $args = [
            'post_type' => $types,
            'post_status' => 'publish',
            'posts_per_page' => 24,
            'ignore_sticky_posts' => true,
            'orderby' => $query ? 'relevance' : 'modified',
            'order' => 'DESC',
        ];
        if ($query !== '') $args['s'] = $query;
        return (new WP_Query($args))->posts;
    }

    public static function render(): string {
        $query = sanitize_text_field(wp_unslash($_GET['q'] ?? $_GET['s'] ?? ''));
        $posts = self::results($query);
        $categories = [
            ['🥾','Trails','/trails/'],['🎵','Events','/events/'],['🍽️','Food','/food/'],
            ['📍','Top Sights','/top-sights/'],['🗺️','Destinations','/destinations/'],['🎮','Games','/play/']
        ];
        ob_start(); ?>
        <main class="tng-search-screen tng-app-shell">
            <section class="tng-search-hero">
                <span class="tng-eyebrow">Search The TN Game</span>
                <h1>What do you want to explore?</h1>
                <p>Find trails, local places, events, games, food, and destinations across Tennessee South Cumberland.</p>
                <form class="tng-search-form" method="get" action="<?php echo esc_url(home_url('/search/')); ?>">
                    <span aria-hidden="true">⌕</span>
                    <input autofocus type="search" name="q" value="<?php echo esc_attr($query); ?>" placeholder="Search trails, waterfalls, restaurants, concerts…">
                    <button type="submit">Search</button>
                </form>
            </section>

            <nav class="tng-search-categories" aria-label="Browse categories">
                <?php foreach ($categories as $category): ?>
                    <a href="<?php echo esc_url(home_url($category[2])); ?>"><span><?php echo esc_html($category[0]); ?></span><strong><?php echo esc_html($category[1]); ?></strong></a>
                <?php endforeach; ?>
            </nav>

            <section class="tng-search-results">
                <div class="tng-search-results__heading">
                    <div><span class="tng-eyebrow"><?php echo $query ? 'Search results' : 'Recently updated'; ?></span><h2><?php echo $query ? esc_html('Results for “' . $query . '”') : 'Start exploring'; ?></h2></div>
                    <strong><?php echo esc_html((string) count($posts)); ?> results</strong>
                </div>

                <?php if ($posts): ?>
                    <div class="tng-search-grid">
                        <?php foreach ($posts as $post): $id = $post->ID; $image = get_the_post_thumbnail_url($id, 'large'); ?>
                            <article class="tng-search-card" data-search-text="<?php echo esc_attr(strtolower(get_the_title($id) . ' ' . self::clean_excerpt($id))); ?>">
                                <a class="tng-search-card__media<?php echo $image ? '' : ' is-placeholder'; ?>" href="<?php echo esc_url(get_permalink($id)); ?>"<?php echo $image ? ' style="background-image:url(' . esc_url($image) . ')"' : ''; ?>><span><?php echo esc_html(self::label($id)); ?></span></a>
                                <div class="tng-search-card__body">
                                    <h3><a href="<?php echo esc_url(get_permalink($id)); ?>"><?php echo esc_html(get_the_title($id)); ?></a></h3>
                                    <?php $excerpt = self::clean_excerpt($id); if ($excerpt): ?><p><?php echo esc_html($excerpt); ?></p><?php endif; ?>
                                    <a class="tng-search-card__link" href="<?php echo esc_url(get_permalink($id)); ?>">View details →</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="tng-search-empty"><span>⌕</span><h2>No matches yet</h2><p>Try a broader phrase or browse one of the categories above.</p></div>
                <?php endif; ?>
            </section>
        </main>
        <?php return (string) ob_get_clean();
    }
}
