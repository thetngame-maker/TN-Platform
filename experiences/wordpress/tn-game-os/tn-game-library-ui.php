<?php
/**
 * Plugin Name: TN Game Explorer Library UI
 * Description: Native Explorer Journal, Completed Adventures, and My Photos screens.
 * Version: 0.1.1
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Library_UI {
    private static function clean_excerpt(int $post_id, int $words = 22): string {
        $text = (string) get_post_field('post_excerpt', $post_id);
        if (!$text) $text = (string) get_post_field('post_content', $post_id);
        $text = strip_shortcodes($text);
        $text = preg_replace('/\[[^\]]+\]/', '', $text) ?: $text;
        return wp_trim_words(wp_strip_all_tags($text), $words, '…');
    }

    private static function recent_items(int $limit = 12): array {
        $types = array_values(array_filter(['st_activity','activity','top_sight','tng_destination'], 'post_type_exists'));
        if (!$types) return [];
        $q = new WP_Query(['post_type'=>$types,'post_status'=>'publish','posts_per_page'=>$limit,'orderby'=>'modified','order'=>'DESC','ignore_sticky_posts'=>true]);
        return $q->posts;
    }

    private static function completed_ids(int $user_id): array {
        if (!$user_id) return [];
        $ids = [];
        foreach (['_tng_completed_games','tng_completed_adventures','tng_completed_trails','completed_trails','tng_completed_posts'] as $key) {
            $value = get_user_meta($user_id, $key, true);
            if (is_array($value)) $ids = array_merge($ids, array_map('absint', $value));
            elseif (is_numeric($value) && get_post(absint($value))) $ids[] = absint($value);
        }
        return array_values(array_unique(array_filter($ids)));
    }

    private static function trip_recaps(int $user_id): array {
        if (!$user_id) return [];
        foreach (['_tng_trip_recaps','tng_trip_recaps'] as $key) {
            $value = get_user_meta($user_id, $key, true);
            if (is_array($value) && $value) return array_values(array_filter($value, 'is_array'));
        }
        return [];
    }

    private static function photo_items(int $user_id): array {
        if (!$user_id) return [];
        $items = get_posts(['post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>'image','author'=>$user_id,'posts_per_page'=>24,'orderby'=>'date','order'=>'DESC']);
        foreach (['tng_photo','top_sight_photo','explorer_photo'] as $type) {
            if (!post_type_exists($type)) continue;
            $items = array_merge($items, get_posts(['post_type'=>$type,'post_status'=>'publish','author'=>$user_id,'posts_per_page'=>24,'orderby'=>'date','order'=>'DESC']));
        }
        return array_slice($items, 0, 24);
    }

    private static function tabs(string $active): void { ?>
        <nav class="tng-library-tabs" aria-label="Explorer library">
            <a class="<?php echo $active==='journal'?'is-active':''; ?>" href="<?php echo esc_url(home_url('/journal/')); ?>">📖 Journal</a>
            <a class="<?php echo $active==='completed'?'is-active':''; ?>" href="<?php echo esc_url(home_url('/completed/')); ?>">🥾 Completed</a>
            <a class="<?php echo $active==='my-photos'?'is-active':''; ?>" href="<?php echo esc_url(home_url('/my-photos/')); ?>">📸 My Photos</a>
        </nav>
    <?php }

    public static function journal(): string {
        $items = self::recent_items();
        ob_start(); ?>
        <main class="tng-library-screen tng-app-shell">
            <section class="tng-library-hero"><div><span class="tng-eyebrow">Your Tennessee story</span><h1>Explorer Journal</h1><p>Keep your adventures, discoveries, photos, and milestones together in one place.</p></div><div class="tng-library-hero__badge"><span>📖</span><strong><?php echo esc_html((string) count($items)); ?></strong><small>Entries</small></div></section>
            <?php self::tabs('journal'); ?>
            <section class="tng-library-panel"><div class="tng-library-heading"><div><span class="tng-eyebrow">Recent journey</span><h2>Your activity timeline</h2></div><a href="<?php echo esc_url(home_url('/explore/')); ?>">Keep exploring</a></div>
                <div class="tng-journal-list">
                    <?php foreach ($items as $item): $image=get_the_post_thumbnail_url($item->ID,'medium'); ?>
                        <a href="<?php echo esc_url(get_permalink($item->ID)); ?>" class="tng-journal-item"><span class="tng-journal-media"<?php echo $image?' style="background-image:url('.esc_url($image).')"':''; ?>></span><div><small><?php echo esc_html(get_the_modified_date('M j, Y',$item)); ?></small><h3><?php echo esc_html(get_the_title($item)); ?></h3><p><?php echo esc_html(self::clean_excerpt((int)$item->ID)); ?></p></div><em>→</em></a>
                    <?php endforeach; ?>
                    <?php if (!$items): ?><div class="tng-library-empty"><span>📖</span><h3>Your journal is ready.</h3><p>Completed adventures and Explorer activity will appear here.</p></div><?php endif; ?>
                </div>
            </section>
        </main>
        <?php return (string) ob_get_clean();
    }

    public static function completed(): string {
        $user_id = get_current_user_id();
        $ids = self::completed_ids($user_id);
        $items = $ids ? get_posts(['post_type'=>'any','post_status'=>'publish','post__in'=>$ids,'orderby'=>'post__in','posts_per_page'=>50]) : [];
        $recaps = self::trip_recaps($user_id);
        $total = count($items) + count($recaps);
        ob_start(); ?>
        <main class="tng-library-screen tng-app-shell">
            <section class="tng-library-hero"><div><span class="tng-eyebrow">Adventure history</span><h1>Completed Adventures</h1><p>Relive the trails, games, checkpoints, and places you have completed.</p></div><div class="tng-library-hero__badge"><span>🥾</span><strong><?php echo esc_html((string) $total); ?></strong><small>Completed</small></div></section>
            <?php self::tabs('completed'); ?>
            <section class="tng-library-panel"><div class="tng-library-heading"><div><span class="tng-eyebrow">Your accomplishments</span><h2>Adventure history</h2></div><a href="<?php echo esc_url(home_url('/play/')); ?>">Start another</a></div>
                <div class="tng-completed-grid">
                    <?php foreach ($items as $item): $image=get_the_post_thumbnail_url($item->ID,'large'); $game_url=add_query_arg(['game'=>$item->ID],home_url('/game-play/')); ?>
                        <a href="<?php echo esc_url($game_url); ?>" class="tng-completed-card"><span class="tng-completed-media"<?php echo $image?' style="background-image:url('.esc_url($image).')"':''; ?>><b>Completed</b></span><div><h3><?php echo esc_html(get_the_title($item)); ?></h3><p><?php echo esc_html(self::clean_excerpt((int)$item->ID,16)); ?></p><strong>Review adventure →</strong></div></a>
                    <?php endforeach; ?>
                    <?php foreach ($recaps as $recap): $title=sanitize_text_field($recap['title']??'My Tennessee adventure'); $stops=absint($recap['stop_count']??0); $minutes=absint($recap['minutes']??0); $date=sanitize_text_field($recap['date']??''); ?>
                        <a href="<?php echo esc_url(home_url('/past-trips/')); ?>" class="tng-completed-card"><span class="tng-completed-media"><b>Trip complete</b></span><div><h3><?php echo esc_html($title); ?></h3><p><?php echo esc_html(trim(($stops?$stops.' stops':'').($minutes?' · '.$minutes.' min':'').($date?' · '.$date:''))); ?></p><strong>View trip recap →</strong></div></a>
                    <?php endforeach; ?>
                    <?php if (!$total): ?><div class="tng-library-empty tng-library-empty--wide"><span>🥾</span><h3>Your first completion is waiting.</h3><p>Finish a trail or game and it will appear in your adventure history.</p><a class="tng-ui-button" href="<?php echo esc_url(home_url('/play/')); ?>">Find an adventure</a></div><?php endif; ?>
                </div>
            </section>
        </main>
        <?php return (string) ob_get_clean();
    }

    public static function photos(): string {
        $user_id = get_current_user_id();
        $items = self::photo_items($user_id);
        ob_start(); ?>
        <main class="tng-library-screen tng-app-shell">
            <section class="tng-library-hero"><div><span class="tng-eyebrow">Explorer memories</span><h1>My Photos</h1><p>See the moments you have shared from trails, Top Sights, games, and local places.</p></div><div class="tng-library-hero__badge"><span>📸</span><strong><?php echo esc_html((string) count($items)); ?></strong><small>Photos</small></div></section>
            <?php self::tabs('my-photos'); ?>
            <section class="tng-library-panel"><div class="tng-library-heading"><div><span class="tng-eyebrow">Your gallery</span><h2>Explorer memories</h2></div><a href="<?php echo esc_url(home_url('/map/')); ?>">Find a photo spot</a></div>
                <div class="tng-photo-grid">
                    <?php foreach ($items as $item): $src=get_the_post_thumbnail_url($item->ID,'large'); if (!$src && $item->post_type==='attachment') $src=wp_get_attachment_image_url($item->ID,'large'); if (!$src) continue; ?>
                        <a href="<?php echo esc_url($item->post_type==='attachment' ? wp_get_attachment_url($item->ID) : get_permalink($item->ID)); ?>" class="tng-photo-card" style="background-image:url('<?php echo esc_url($src); ?>')"><span><?php echo esc_html($item->post_title ?: 'Explorer photo'); ?></span></a>
                    <?php endforeach; ?>
                    <?php if (!$items): ?><div class="tng-library-empty tng-library-empty--wide"><span>📸</span><h3>Your photo gallery is ready.</h3><p>Approved Explorer photos will appear here automatically.</p><a class="tng-ui-button" href="<?php echo esc_url(home_url('/map/')); ?>">Explore photo spots</a></div><?php endif; ?>
                </div>
            </section>
        </main>
        <?php return (string) ob_get_clean();
    }
}
