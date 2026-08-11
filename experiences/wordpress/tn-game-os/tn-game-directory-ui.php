<?php
/**
 * Plugin Name: TN Game Directory UI
 * Description: Native collection screens for trails, events, food, Top Sights, and destinations.
 * Version: 0.1.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Directory_UI {
    private static function config(string $route): array {
        $configs = [
            'trails' => ['eyebrow'=>'Get outside','title'=>'Explore trails','copy'=>'Find hikes, waterfalls, overlooks, and trail games across Tennessee South Cumberland.','icon'=>'🥾'],
            'events' => ['eyebrow'=>'Plan ahead','title'=>'Upcoming events','copy'=>'Concerts, festivals, and experiences worth building a trip around.','icon'=>'🎵'],
            'food' => ['eyebrow'=>'Eat local','title'=>'Food and drink','copy'=>'Discover restaurants, cafés, bakeries, and local favorites near your adventure.','icon'=>'🍽️'],
            'top-sights' => ['eyebrow'=>'Worth the stop','title'=>'Top Sights','copy'=>'Waterfalls, overlooks, historic places, and memorable stops to collect along the way.','icon'=>'📍'],
            'destinations' => ['eyebrow'=>'Choose a basecamp','title'=>'Destinations','copy'=>'Explore the towns, communities, and regions that make up The TN Game.','icon'=>'🗺️'],
        ];
        return $configs[$route] ?? $configs['trails'];
    }

    private static function post_types(): array {
        return array_values(array_filter(['st_activity','activity','top_sight','tng_destination','st_location'], 'post_type_exists'));
    }

    private static function haystack(int $id): string {
        $parts = [get_the_title($id), get_post_type($id), get_post_field('post_excerpt',$id), get_post_field('post_content',$id)];
        foreach (get_object_taxonomies(get_post_type($id)) as $tax) {
            $terms = wp_get_post_terms($id, $tax, ['fields'=>'names']);
            if (!is_wp_error($terms)) $parts = array_merge($parts, $terms);
        }
        foreach (['activity_type','type','category','location','address','venue','trail_type','difficulty'] as $key) $parts[] = (string) get_post_meta($id,$key,true);
        return strtolower(wp_strip_all_tags(implode(' ', array_filter($parts))));
    }

    private static function event_timestamp(int $id): int {
        foreach (['start_date','event_date','date','st_start_date'] as $key) {
            $value = get_post_meta($id,$key,true);
            if (!$value) continue;
            $time = is_numeric($value) ? (int)$value : strtotime((string)$value);
            if ($time) return $time;
        }
        return 0;
    }

    private static function matches(int $id, string $route): bool {
        $type = get_post_type($id);
        $text = self::haystack($id);
        if ($route === 'top-sights') return $type === 'top_sight' || preg_match('/top sight|waterfall|overlook|viewpoint|historic site/', $text);
        if ($route === 'destinations') return in_array($type,['tng_destination','st_location'],true);
        if ($route === 'events') return self::event_timestamp($id) > 0 || preg_match('/concert|event|festival|show|caverns/', $text);
        if ($route === 'trails') return preg_match('/hiking.?trail|trail|hike|waterfall walk|loop trail|out-and-back/', $text) && !self::event_timestamp($id);
        if ($route === 'food') return preg_match('/food|restaurant|cafe|café|coffee|bakery|barbecue|bbq|burger|pizza|dining|drink|brewery|ice cream/', $text) && !self::event_timestamp($id);
        return false;
    }

    private static function posts(string $route): array {
        $types = self::post_types();
        if (!$types) return [];
        $q = new WP_Query(['post_type'=>$types,'post_status'=>'publish','posts_per_page'=>80,'ignore_sticky_posts'=>true,'orderby'=>'modified','order'=>'DESC']);
        $posts = array_values(array_filter($q->posts, static fn($p)=>self::matches($p->ID,$route)));
        if ($route === 'events') {
            usort($posts, static function($a,$b){
                $at=self::event_timestamp($a->ID) ?: PHP_INT_MAX; $bt=self::event_timestamp($b->ID) ?: PHP_INT_MAX;
                return $at <=> $bt;
            });
        }
        return $posts;
    }

    private static function clean_excerpt(int $id): string {
        $source = has_excerpt($id) ? get_post_field('post_excerpt',$id) : get_post_field('post_content',$id);
        $source = strip_shortcodes((string)$source);
        $source = preg_replace('/\[[^\]]+\]/',' ',$source);
        return wp_trim_words(preg_replace('/\s+/',' ',trim(wp_strip_all_tags((string)$source))),18,'…');
    }

    private static function card(WP_Post $post, string $route): string {
        $id=$post->ID; $image=get_the_post_thumbnail_url($id,'large'); $excerpt=self::clean_excerpt($id);
        $meta=[];
        if ($route==='events' && ($time=self::event_timestamp($id))) $meta[]='📅 '.wp_date('M j, Y',$time);
        foreach (['difficulty','trail_difficulty','address','location','venue'] as $key) { $value=get_post_meta($id,$key,true); if ($value) { $meta[]=wp_trim_words(wp_strip_all_tags((string)$value),6,''); break; } }
        $label=['trails'=>'Trail','events'=>'Event','food'=>'Food & Drink','top-sights'=>'Top Sight','destinations'=>'Destination'][$route] ?? 'Explore';
        ob_start(); ?>
        <article class="tng-directory-card" data-title="<?php echo esc_attr(strtolower(get_the_title($id).' '.implode(' ',$meta))); ?>">
            <a class="tng-directory-card__media<?php echo $image?'':' is-placeholder'; ?>" href="<?php echo esc_url(get_permalink($id)); ?>"<?php if($image): ?> style="background-image:url('<?php echo esc_url($image); ?>')"<?php endif; ?>><span><?php echo esc_html($label); ?></span></a>
            <div class="tng-directory-card__body">
                <h2><a href="<?php echo esc_url(get_permalink($id)); ?>"><?php echo esc_html(get_the_title($id)); ?></a></h2>
                <?php if($meta): ?><div class="tng-directory-card__meta"><?php foreach(array_slice($meta,0,2) as $item): ?><span><?php echo esc_html($item); ?></span><?php endforeach; ?></div><?php endif; ?>
                <?php if($excerpt): ?><p><?php echo esc_html($excerpt); ?></p><?php endif; ?>
                <a class="tng-directory-card__link" href="<?php echo esc_url(get_permalink($id)); ?>">View details →</a>
            </div>
        </article>
        <?php return (string)ob_get_clean();
    }

    public static function render(string $route): string {
        $config=self::config($route); $posts=self::posts($route); $count=count($posts);
        ob_start(); ?>
        <main class="tng-directory tng-app-shell" data-directory="<?php echo esc_attr($route); ?>">
            <section class="tng-directory-hero">
                <div><span class="tng-eyebrow"><?php echo esc_html($config['eyebrow']); ?></span><h1><?php echo esc_html($config['title']); ?></h1><p><?php echo esc_html($config['copy']); ?></p></div>
                <span class="tng-directory-hero__icon" aria-hidden="true"><?php echo esc_html($config['icon']); ?></span>
            </section>
            <section class="tng-directory-tools">
                <label class="tng-directory-search"><span>⌕</span><input type="search" placeholder="Search <?php echo esc_attr(strtolower($config['title'])); ?>" data-directory-search></label>
                <div class="tng-directory-count"><strong data-directory-count><?php echo esc_html((string)$count); ?></strong><span><?php echo $count===1?'result':'results'; ?></span></div>
            </section>
            <?php if($posts): ?><section class="tng-directory-grid"><?php foreach($posts as $post) echo self::card($post,$route); ?></section>
            <section class="tng-directory-empty" hidden data-directory-empty><span>⌕</span><h2>No matches yet</h2><p>Try another search or explore a different category.</p></section>
            <?php else: ?><section class="tng-directory-empty"><span><?php echo esc_html($config['icon']); ?></span><h2>More adventures are coming</h2><p>Published content matching this collection will appear here automatically.</p></section><?php endif; ?>
        </main>
        <?php return (string)ob_get_clean();
    }
}
