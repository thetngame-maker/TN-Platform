<?php
/**
 * Plugin Name: TN Game Adventure Recaps
 * Description: Private, visual Explorer memories generated from completed trips and games.
 * Version: 1.0.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Adventure_Recaps {
    private const META_KEY = '_tng_adventure_recaps';
    private const MAX_RECAPS = 100;

    public static function boot(): void {
        add_action('tng_os_game_completed', [self::class, 'capture_game'], 35, 3);
        add_action('tng_os_trip_archived', [self::class, 'capture_trip'], 10, 2);
        add_action('admin_post_tng_adventure_recap_update', [self::class, 'handle_update']);
    }

    public static function all(int $user_id = 0): array {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) return [];
        $recaps = get_user_meta($user_id, self::META_KEY, true);
        $recaps = is_array($recaps) ? array_values(array_filter($recaps, 'is_array')) : [];
        usort($recaps, static fn(array $a, array $b): int => (strtotime((string)($b['completed_at'] ?? '')) ?: 0) <=> (strtotime((string)($a['completed_at'] ?? '')) ?: 0));
        return array_slice($recaps, 0, self::MAX_RECAPS);
    }

    public static function capture_game(int $user_id, int $game_id, array $data = []): void {
        if ($user_id < 1 || $game_id < 1 || !get_post($game_id)) return;
        $checkpoints = get_post_meta($game_id, 'tng_game_checkpoints', true);
        $checkpoints = is_array($checkpoints) ? array_values(array_filter($checkpoints, 'is_array')) : [];
        $items = [];
        $xp = 0;
        foreach ($checkpoints as $index => $checkpoint) {
            $sight_id = absint($checkpoint['sight_id'] ?? $checkpoint['top_sight_id'] ?? 0);
            $title = sanitize_text_field((string)($checkpoint['title'] ?? 'Checkpoint ' . ((int)$index + 1)));
            $reward = absint($checkpoint['xp'] ?? 0);
            $xp += $reward;
            $items[] = [
                'id' => $sight_id,
                'title' => $title,
                'type' => sanitize_key((string)($checkpoint['type'] ?? 'checkpoint')),
                'url' => $sight_id ? (get_permalink($sight_id) ?: '') : '',
                'image' => $sight_id ? (get_the_post_thumbnail_url($sight_id, 'medium_large') ?: '') : '',
                'xp' => $reward,
            ];
        }
        if (!$xp) $xp = absint(get_post_meta($game_id, 'xp_available', true));
        $title = sanitize_text_field((string)($data['title'] ?? get_the_title($game_id) ?: 'TN Game adventure'));
        self::upsert($user_id, [
            'id' => wp_generate_uuid4(),
            'source_key' => 'game:' . $game_id,
            'kind' => 'game',
            'source_id' => $game_id,
            'title' => $title,
            'completed_at' => sanitize_text_field((string)($data['date'] ?? current_time('mysql'))),
            'cover' => get_the_post_thumbnail_url($game_id, 'full') ?: self::first_image($items),
            'items' => $items,
            'stats' => ['stops' => count($items), 'xp' => $xp, 'distance_m' => 0, 'duration_s' => 0],
            'story' => sprintf('Completed %d checkpoint%s and earned %d Explorer XP across a Tennessee game adventure.', count($items), count($items) === 1 ? '' : 's', $xp),
            'note' => '',
        ]);
    }

    public static function capture_trip(int $user_id, array $trip): void {
        if ($user_id < 1 || !$trip) return;
        $items = is_array($trip['items'] ?? null) ? array_values(array_filter($trip['items'], 'is_array')) : [];
        $stats = is_array($trip['stats'] ?? null) ? $trip['stats'] : [];
        $xp = 0;
        $completed_count = 0;
        $skipped_count = 0;
        foreach ($items as &$item) {
            $id = absint($item['id'] ?? 0);
            $status = sanitize_key((string)($item['status'] ?? 'completed'));
            if (!in_array($status, ['completed','skipped'], true)) $status = 'completed';
            $item['status'] = $status;
            if ($status === 'skipped') $skipped_count++;
            else $completed_count++;
            $reward = $status === 'completed' ? self::post_xp($id) : 0;
            $item['xp'] = $reward;
            $item['type'] = $id ? sanitize_key((string)get_post_type($id)) : 'stop';
            $xp += $reward;
        }
        unset($item);
        $stats = [
            'stops' => count($items),
            'completed' => $completed_count,
            'skipped' => $skipped_count,
            'xp' => $xp,
            'distance_m' => absint($stats['distance_m'] ?? 0),
            'duration_s' => absint($stats['duration_s'] ?? 0),
        ];
        $date = sanitize_text_field((string)($trip['completed_at'] ?? current_time('mysql')));
        $title = sanitize_text_field((string)($trip['title'] ?? 'Tennessee Day · ' . mysql2date('M j', $date)));
        self::upsert($user_id, [
            'id' => sanitize_text_field((string)($trip['id'] ?? wp_generate_uuid4())),
            'source_key' => 'trip:' . sanitize_text_field((string)($trip['id'] ?? '')),
            'kind' => 'trip',
            'source_id' => 0,
            'title' => $title,
            'completed_at' => $date,
            'cover' => self::first_image($items),
            'items' => $items,
            'stats' => $stats,
            'story' => self::trip_story($stats),
            'note' => '',
        ]);
    }

    private static function upsert(int $user_id, array $recap): void {
        $recaps = self::all($user_id);
        $key = sanitize_text_field((string)($recap['source_key'] ?? ''));
        foreach ($recaps as $existing) if ($key !== '' && ($existing['source_key'] ?? '') === $key) return;
        array_unshift($recaps, self::normalize($recap));
        update_user_meta($user_id, self::META_KEY, array_slice($recaps, 0, self::MAX_RECAPS));
    }

    private static function normalize(array $recap): array {
        $stats = is_array($recap['stats'] ?? null) ? $recap['stats'] : [];
        return [
            'id' => sanitize_text_field((string)($recap['id'] ?? wp_generate_uuid4())),
            'source_key' => sanitize_text_field((string)($recap['source_key'] ?? '')),
            'kind' => in_array(($recap['kind'] ?? ''), ['game','trip'], true) ? $recap['kind'] : 'trip',
            'source_id' => absint($recap['source_id'] ?? 0),
            'title' => sanitize_text_field((string)($recap['title'] ?? 'My Tennessee adventure')),
            'completed_at' => sanitize_text_field((string)($recap['completed_at'] ?? current_time('mysql'))),
            'cover' => esc_url_raw((string)($recap['cover'] ?? '')),
            'items' => array_slice(is_array($recap['items'] ?? null) ? $recap['items'] : [], 0, 50),
            'stats' => [
                'stops' => absint($stats['stops'] ?? 0),
                'completed' => absint($stats['completed'] ?? $stats['stops'] ?? 0),
                'skipped' => absint($stats['skipped'] ?? 0),
                'xp' => absint($stats['xp'] ?? 0),
                'distance_m' => absint($stats['distance_m'] ?? 0),
                'duration_s' => absint($stats['duration_s'] ?? 0),
            ],
            'story' => sanitize_textarea_field((string)($recap['story'] ?? 'A Tennessee adventure worth remembering.')),
            'note' => sanitize_textarea_field((string)($recap['note'] ?? '')),
        ];
    }

    private static function post_xp(int $post_id): int {
        if (!$post_id) return 0;
        foreach (['xp_available','xp_reward','tng_xp','xp'] as $key) {
            $xp = absint(get_post_meta($post_id, $key, true));
            if ($xp) return $xp;
        }
        return 0;
    }

    private static function first_image(array $items): string {
        foreach ($items as $item) if (!empty($item['image'])) return esc_url_raw((string)$item['image']);
        return '';
    }

    private static function trip_story(array $stats): string {
        $stops = absint($stats['completed'] ?? $stats['stops'] ?? 0);
        $skipped = absint($stats['skipped'] ?? 0);
        $xp = absint($stats['xp'] ?? 0);
        $miles = self::miles(absint($stats['distance_m'] ?? 0));
        $parts = [sprintf('%d Tennessee stop%s completed', $stops, $stops === 1 ? '' : 's')];
        if ($skipped > 0) $parts[] = sprintf('%d intentionally skipped', $skipped);
        if ($miles > 0) $parts[] = number_format_i18n($miles, 1) . ' route miles';
        if ($xp > 0) $parts[] = $xp . ' Explorer XP';
        return implode(' · ', $parts) . '. One complete Tennessee day, saved to your Explorer story.';
    }

    private static function find(string $id, int $user_id): ?array {
        foreach (self::all($user_id) as $recap) if (hash_equals((string)($recap['id'] ?? ''), $id)) return $recap;
        return null;
    }

    private static function photos(array $recap, int $user_id): array {
        $ids = array_values(array_unique(array_filter(array_merge(
            [absint($recap['source_id'] ?? 0)],
            array_map(static fn(array $item): int => absint($item['id'] ?? 0), (array)($recap['items'] ?? []))
        ))));
        if (!$ids) return [];
        return get_posts([
            'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image',
            'author' => $user_id, 'posts_per_page' => 8, 'orderby' => 'date', 'order' => 'ASC',
            'meta_query' => [
                ['key' => '_tng_community_photo', 'value' => '1'],
                ['key' => '_tng_photo_status', 'value' => 'approved'],
                ['key' => '_tng_photo_object_id', 'value' => $ids, 'compare' => 'IN', 'type' => 'NUMERIC'],
            ],
        ]);
    }

    public static function handle_update(): void {
        if (!is_user_logged_in()) auth_redirect();
        check_admin_referer('tng_adventure_recap_update');
        $user_id = get_current_user_id();
        $id = sanitize_text_field(wp_unslash($_POST['recap_id'] ?? ''));
        $recaps = self::all($user_id);
        $updated = false;
        foreach ($recaps as &$recap) {
            if (!hash_equals((string)($recap['id'] ?? ''), $id)) continue;
            $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
            $note = sanitize_textarea_field(wp_unslash($_POST['note'] ?? ''));
            $recap['title'] = $title !== '' ? (function_exists('mb_substr') ? mb_substr($title, 0, 100) : substr($title, 0, 100)) : $recap['title'];
            $recap['note'] = function_exists('mb_substr') ? mb_substr($note, 0, 1200) : substr($note, 0, 1200);
            $updated = true;
            break;
        }
        unset($recap);
        if ($updated) update_user_meta($user_id, self::META_KEY, $recaps);
        wp_safe_redirect(add_query_arg(['recap' => $id, 'saved' => $updated ? '1' : '0'], home_url('/recaps/')));
        exit;
    }

    public static function render(): string {
        if (!is_user_logged_in()) return self::signed_out();
        $user_id = get_current_user_id();
        $id = sanitize_text_field(wp_unslash($_GET['recap'] ?? ''));
        $recap = $id !== '' ? self::find($id, $user_id) : null;
        return $recap ? self::detail($recap, $user_id) : self::index(self::all($user_id));
    }

    private static function signed_out(): string {
        ob_start(); ?><main class="tng-recaps-screen tng-app-shell"><section class="tng-recaps-empty"><span>✦</span><h1>Your adventure stories live here.</h1><p>Sign in to see private recaps generated from completed Tennessee trips and games.</p><a class="tng-ui-button" href="<?php echo esc_url(wp_login_url(home_url('/recaps/'))); ?>">Sign in</a></section></main><?php return (string)ob_get_clean();
    }

    private static function index(array $recaps): string {
        ob_start(); ?>
        <main class="tng-recaps-screen tng-app-shell">
            <section class="tng-recaps-hero"><div><span class="tng-eyebrow">Your Tennessee story</span><h1>Adventure Recaps</h1><p>Turn finished trips and games into private, visual memories with stops, XP, photos, and the details worth remembering.</p></div><div class="tng-recaps-count"><strong><?php echo esc_html((string)count($recaps)); ?></strong><small>Memories</small></div></section>
            <nav class="tng-trip-tabs" aria-label="Trip planning"><a href="<?php echo esc_url(home_url('/trips/')); ?>">🗺 Trips</a><a href="<?php echo esc_url(home_url('/past-trips/')); ?>">↺ Past trips</a><a class="is-active" href="<?php echo esc_url(home_url('/recaps/')); ?>">✦ Recaps</a></nav>
            <?php if (!$recaps): ?><section class="tng-recaps-empty"><span>✦</span><h2>Your first recap is waiting.</h2><p>Finish and archive a trip or complete a TN Game adventure. Your recap will build itself automatically.</p><a class="tng-ui-button" href="<?php echo esc_url(home_url('/play/')); ?>">Find an adventure</a></section>
            <?php else: ?><section class="tng-recaps-grid"><?php foreach ($recaps as $recap): $stats=(array)($recap['stats']??[]); ?><a class="tng-recap-card" href="<?php echo esc_url(add_query_arg('recap', (string)$recap['id'], home_url('/recaps/'))); ?>"><span class="tng-recap-card__media"<?php echo !empty($recap['cover']) ? ' style="background-image:url(\'' . esc_url((string)$recap['cover']) . '\')"' : ''; ?>><b><?php echo esc_html(($recap['kind']??'trip') === 'game' ? 'Game complete' : 'Trip complete'); ?></b></span><div><small><?php echo esc_html(mysql2date('M j, Y', (string)($recap['completed_at']??''))); ?></small><h2><?php echo esc_html((string)$recap['title']); ?></h2><p><?php echo esc_html((string)$recap['story']); ?></p><footer><span><?php echo esc_html((string)absint($stats['stops']??0)); ?> stops</span><span>+<?php echo esc_html((string)absint($stats['xp']??0)); ?> XP</span><strong>Open recap →</strong></footer></div></a><?php endforeach; ?></section><?php endif; ?>
        </main>
        <?php return (string)ob_get_clean();
    }

    private static function detail(array $recap, int $user_id): string {
        $stats = (array)($recap['stats'] ?? []);
        $photos = self::photos($recap, $user_id);
        $share = self::share_text($recap, count($photos));
        ob_start(); ?>
        <main class="tng-recaps-screen tng-app-shell">
            <a class="tng-recap-back" href="<?php echo esc_url(home_url('/recaps/')); ?>">← All recaps</a>
            <section class="tng-recap-detail-hero"<?php echo !empty($recap['cover']) ? ' style="--tng-recap-cover:url(\'' . esc_url((string)$recap['cover']) . '\')"' : ''; ?>><div><span class="tng-eyebrow"><?php echo esc_html(($recap['kind']??'trip') === 'game' ? 'Completed game' : 'Completed trip'); ?> · <?php echo esc_html(mysql2date('M j, Y', (string)$recap['completed_at'])); ?></span><h1><?php echo esc_html((string)$recap['title']); ?></h1><p><?php echo esc_html((string)$recap['story']); ?></p><div class="tng-recap-actions"><button type="button" data-tng-share-recap data-share-text="<?php echo esc_attr($share); ?>">Share recap</button><a href="<?php echo esc_url(home_url('/explore/')); ?>">Plan another</a></div></div></section>
            <?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?><div class="tng-recap-notice">Memory saved.</div><?php endif; ?>
            <section class="tng-recap-stats"><article><strong><?php echo esc_html((string)absint($stats['stops']??0)); ?></strong><span>Stops</span></article><article><strong>+<?php echo esc_html(number_format_i18n(absint($stats['xp']??0))); ?></strong><span>XP earned</span></article><article><strong><?php echo esc_html((string)self::miles(absint($stats['distance_m']??0))); ?></strong><span>Route miles</span></article><article><strong><?php echo esc_html((string)self::minutes(absint($stats['duration_s']??0))); ?></strong><span>Minutes</span></article><article><strong><?php echo esc_html((string)count($photos)); ?></strong><span>Photos</span></article></section>
            <div class="tng-recap-layout"><section class="tng-recap-panel"><div class="tng-section__heading"><div><span class="tng-eyebrow">The adventure</span><h2>Your route and checkpoints</h2></div></div><ol class="tng-recap-timeline"><?php foreach ((array)$recap['items'] as $index=>$item): ?><li><span class="tng-recap-stop-media"<?php echo !empty($item['image']) ? ' style="background-image:url(\'' . esc_url((string)$item['image']) . '\')"' : ''; ?>></span><div><small>Stop <?php echo esc_html((string)((int)$index+1)); ?><?php echo !empty($item['xp']) ? ' · +' . esc_html((string)absint($item['xp'])) . ' XP' : ''; ?></small><h3><?php echo esc_html((string)($item['title']??'Tennessee stop')); ?></h3><?php if (!empty($item['url'])): ?><a href="<?php echo esc_url((string)$item['url']); ?>">View place →</a><?php endif; ?></div></li><?php endforeach; ?></ol></section>
                <aside><section class="tng-recap-panel"><span class="tng-eyebrow">Explorer memory</span><h2>Make it yours</h2><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="tng_adventure_recap_update"><input type="hidden" name="recap_id" value="<?php echo esc_attr((string)$recap['id']); ?>"><?php wp_nonce_field('tng_adventure_recap_update'); ?><label>Recap title<input type="text" name="title" maxlength="100" value="<?php echo esc_attr((string)$recap['title']); ?>"></label><label>What do you want to remember?<textarea name="note" maxlength="1200" rows="7" placeholder="Favorite moment, trail condition, food stop, who came along…"><?php echo esc_textarea((string)$recap['note']); ?></textarea></label><button class="tng-ui-button" type="submit">Save memory</button></form></section></aside></div>
            <?php if ($photos): ?><section class="tng-recap-panel"><div class="tng-section__heading"><div><span class="tng-eyebrow">Explorer photos</span><h2>Moments from this adventure</h2></div><a href="<?php echo esc_url(home_url('/my-photos/')); ?>">All photos</a></div><div class="tng-recap-photos"><?php foreach ($photos as $photo): $src=wp_get_attachment_image_url($photo->ID,'large'); if(!$src)continue; ?><a href="<?php echo esc_url(wp_get_attachment_url($photo->ID)); ?>" style="background-image:url('<?php echo esc_url($src); ?>')"><span><?php echo esc_html((string)get_post_meta($photo->ID,'_tng_photo_caption',true)); ?></span></a><?php endforeach; ?></div></section><?php endif; ?>
            <div class="tng-recap-share-status" data-tng-share-status role="status" aria-live="polite"></div>
        </main>
        <?php return (string)ob_get_clean();
    }

    private static function share_text(array $recap, int $photos): string {
        $stats = (array)($recap['stats'] ?? []);
        $parts = [absint($stats['stops']??0) . ' stops', '+' . absint($stats['xp']??0) . ' XP'];
        if ($photos) $parts[] = $photos . ' photo' . ($photos === 1 ? '' : 's');
        return 'I completed ' . sanitize_text_field((string)$recap['title']) . ' in The TN Game — ' . implode(' · ', $parts) . '.';
    }

    private static function miles(int $meters): float { return round($meters / 1609.344, 1); }
    private static function minutes(int $seconds): int { return (int)round($seconds / 60); }
}
TNG_Adventure_Recaps::boot();
