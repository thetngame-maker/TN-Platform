<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use TNG_OS\Platform\Universal_Map_Registry;

if (!defined('ABSPATH')) exit;

final class Community_Photos implements Module_Interface {
    private const META_FLAG = '_tng_community_photo';
    private const META_STATUS = '_tng_photo_status';
    private const META_OBJECT = '_tng_photo_object_id';
    private const META_CHECKPOINT = '_tng_photo_checkpoint';
    private const META_CAPTION = '_tng_photo_caption';
    private const META_XP = '_tng_photo_xp_awarded';
    private const PHOTO_XP = 25;
    private const ADMIN_PAGE = 'tng-community-photos';

    public function id(): string { return 'community_photos'; }

    public function register(Container $container): void {
        $container->set('community_photos', $this);
        add_action('admin_menu', [$this, 'admin_menu'], 28);
        add_action('admin_post_tng_community_photo_submit', [$this, 'submit']);
        add_action('admin_post_tng_community_photo_moderate', [$this, 'moderate']);
    }

    public function boot(Container $container): void {}

    public static function register_checkpoint_photo(int $attachment_id, int $object_id, int $checkpoint): void {
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') return;
        update_post_meta($attachment_id, self::META_FLAG, 1);
        update_post_meta($attachment_id, self::META_STATUS, 'pending');
        update_post_meta($attachment_id, self::META_OBJECT, absint($object_id));
        update_post_meta($attachment_id, self::META_CHECKPOINT, absint($checkpoint));
    }

    public static function feed_items(string $filter = 'all', int $limit = 24): array {
        $filter = in_array($filter, ['all','photos','discoveries','milestones'], true) ? $filter : 'all';
        $items = [];
        if (in_array($filter, ['all','photos'], true)) $items = array_merge($items, self::photo_feed($limit));
        if ($filter !== 'photos') $items = array_merge($items, self::gameplay_feed($filter, $limit));
        usort($items, static fn(array $a, array $b): int => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));
        return array_slice($items, 0, max(1, min(50, $limit)));
    }

    public static function upload_places(): array {
        if (!class_exists(Universal_Map_Registry::class)) return [];
        $dataset = Universal_Map_Registry::dataset();
        $places = [];
        foreach ((array) ($dataset['items'] ?? []) as $item) {
            $id = absint($item['id'] ?? 0);
            if (!$id) continue;
            $places[] = [
                'id' => $id,
                'title' => sanitize_text_field($item['title'] ?? ''),
                'label' => sanitize_text_field($item['label'] ?? 'Place'),
            ];
        }
        usort($places, static fn(array $a, array $b): int => strcasecmp($a['title'], $b['title']));
        return array_slice($places, 0, 250);
    }

    private static function photo_feed(int $limit): array {
        $photos = get_posts([
            'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image',
            'posts_per_page' => $limit, 'orderby' => 'date', 'order' => 'DESC',
            'meta_query' => [
                ['key' => self::META_FLAG, 'value' => '1'],
                ['key' => self::META_STATUS, 'value' => 'approved'],
            ],
        ]);
        $items = [];
        foreach ($photos as $photo) {
            $user = get_userdata((int) $photo->post_author);
            $object_id = absint(get_post_meta($photo->ID, self::META_OBJECT, true));
            $place = $object_id ? get_the_title($object_id) : '';
            $caption = sanitize_text_field((string) get_post_meta($photo->ID, self::META_CAPTION, true));
            $items[] = [
                'type' => 'photo', 'timestamp' => get_post_time('U', true, $photo),
                'user' => $user ? ($user->display_name ?: $user->user_login) : 'TN Explorer',
                'avatar' => $user ? get_avatar_url($user->ID, ['size' => 96]) : '',
                'title' => $place ? 'Shared a photo from ' . $place : 'Shared a Tennessee photo',
                'copy' => $caption, 'media' => wp_get_attachment_image_url($photo->ID, 'large') ?: '',
                'url' => $object_id ? (get_permalink($object_id) ?: home_url('/map/')) : (wp_get_attachment_url($photo->ID) ?: home_url('/activity/')),
                'time' => human_time_diff(get_post_time('U', true, $photo), current_time('timestamp', true)) . ' ago',
                'xp' => self::PHOTO_XP,
            ];
        }
        return $items;
    }

    private static function gameplay_feed(string $filter, int $limit): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tng_gameplay_events';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return [];
        $types = $filter === 'discoveries'
            ? ['checkpoint_completed','quest_completed','nearby_discovery']
            : ($filter === 'milestones' ? ['badge_unlocked','quest_completed','game_completed'] : ['checkpoint_completed','quest_completed','badge_unlocked','nearby_discovery','game_completed']);
        $placeholders = implode(',', array_fill(0, count($types), '%s'));
        $sql = $wpdb->prepare("SELECT e.*,u.display_name FROM {$table} e LEFT JOIN {$wpdb->users} u ON u.ID=e.user_id WHERE e.event_type IN ({$placeholders}) ORDER BY e.occurred_at DESC,e.id DESC LIMIT %d", ...array_merge($types, [max(1, min(50, $limit))]));
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $items = [];
        foreach ((array) $rows as $row) {
            $payload = json_decode((string) ($row['payload'] ?? ''), true);
            $payload = is_array($payload) ? $payload : [];
            $type = sanitize_key((string) ($row['event_type'] ?? ''));
            $object_id = absint($row['object_id'] ?? 0);
            $object = $object_id ? get_post($object_id) : null;
            $subject = sanitize_text_field((string) ($payload['title'] ?? $payload['name'] ?? $payload['label'] ?? 'a Tennessee adventure'));
            if ($object) $subject = get_the_title($object_id) ?: $subject;
            $labels = [
                'checkpoint_completed' => ['Completed a checkpoint', '◇'],
                'quest_completed' => ['Completed ' . $subject, '✓'],
                'badge_unlocked' => ['Unlocked ' . $subject, '★'],
                'nearby_discovery' => ['Discovered ' . $subject, '📍'],
                'game_completed' => ['Finished ' . $subject, '🏁'],
            ];
            [$title, $icon] = $labels[$type] ?? ['Made Explorer progress', '•'];
            $time = strtotime((string) ($row['occurred_at'] ?? '')) ?: current_time('timestamp', true);
            $items[] = [
                'type' => $type, 'icon' => $icon, 'timestamp' => $time,
                'user' => sanitize_text_field((string) ($row['display_name'] ?: 'TN Explorer')),
                'avatar' => get_avatar_url(absint($row['user_id'] ?? 0), ['size' => 96]),
                'title' => $title, 'copy' => sanitize_text_field((string) ($payload['description'] ?? $payload['message'] ?? '')),
                'media' => $object ? (get_the_post_thumbnail_url($object_id, 'medium_large') ?: '') : '',
                'url' => $object ? (get_permalink($object_id) ?: home_url('/activity/')) : home_url('/activity/'),
                'time' => human_time_diff($time, current_time('timestamp', true)) . ' ago',
                'xp' => absint($row['xp'] ?? 0),
            ];
        }
        return $items;
    }

    public function submit(): void {
        if (!is_user_logged_in()) auth_redirect();
        check_admin_referer('tng_community_photo_submit');
        $return = home_url('/activity/');
        $object_id = absint($_POST['object_id'] ?? 0);
        $allowed = array_column(self::upload_places(), 'id');
        if (!$object_id || !in_array($object_id, $allowed, true)) $this->redirect($return, 'photo_error', 'place');
        if (empty($_FILES['community_photo']['tmp_name'])) $this->redirect($return, 'photo_error', 'missing');
        $file = $_FILES['community_photo'];
        if (!empty($file['size']) && (int) $file['size'] > 8 * 1024 * 1024) $this->redirect($return, 'photo_error', 'size');
        $checked = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
        if (empty($checked['type']) || strpos($checked['type'], 'image/') !== 0) $this->redirect($return, 'photo_error', 'type');
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $caption = sanitize_textarea_field(wp_unslash($_POST['caption'] ?? ''));
        $attachment_id = media_handle_upload('community_photo', $object_id, [
            'post_title' => sanitize_text_field(get_the_title($object_id) . ' Explorer photo'),
            'post_content' => $caption,
        ], ['test_form' => false]);
        if (is_wp_error($attachment_id)) $this->redirect($return, 'photo_error', 'upload');
        update_post_meta($attachment_id, self::META_FLAG, 1);
        update_post_meta($attachment_id, self::META_STATUS, 'pending');
        update_post_meta($attachment_id, self::META_OBJECT, $object_id);
        update_post_meta($attachment_id, self::META_CAPTION, $caption);
        do_action('tng_gameplay_external_event', get_current_user_id(), 'photo_submitted', 'photo', (string) $attachment_id, 0, ['title' => get_the_title($object_id)]);
        $this->redirect($return, 'photo_submitted', '1');
    }

    public function moderate(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        $attachment_id = absint($_GET['photo_id'] ?? 0);
        $status = sanitize_key(wp_unslash($_GET['status'] ?? ''));
        check_admin_referer('tng_community_photo_' . $attachment_id);
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment' || !in_array($status, ['approved','rejected'], true)) wp_die('Invalid photo action.');
        update_post_meta($attachment_id, self::META_STATUS, $status);
        if ($status === 'approved' && !get_post_meta($attachment_id, self::META_XP, true)) {
            $photo = get_post($attachment_id);
            $user_id = $photo ? absint($photo->post_author) : 0;
            if ($user_id) {
                $this->award_xp($user_id, self::PHOTO_XP);
                update_post_meta($attachment_id, self::META_XP, self::PHOTO_XP);
                update_user_meta($user_id, '_tng_photo_count', max(0, (int) get_user_meta($user_id, '_tng_photo_count', true)) + 1);
                $object_id = absint(get_post_meta($attachment_id, self::META_OBJECT, true));
                do_action('tng_gameplay_external_event', $user_id, 'photo_approved', 'photo', (string) $attachment_id, self::PHOTO_XP, ['title' => $object_id ? get_the_title($object_id) : 'Tennessee photo', 'object_id' => $object_id]);
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=' . self::ADMIN_PAGE . '&moderated=' . $status)); exit;
    }

    private function award_xp(int $user_id, int $amount): void {
        $type = sanitize_key((string) get_option('tng_gamipress_points_type', ''));
        if (!$type && function_exists('gamipress_get_points_types')) {
            $types = gamipress_get_points_types();
            foreach (['explorer-xp','xp','points'] as $candidate) if (isset($types[$candidate])) { $type = $candidate; break; }
        }
        if ($type && function_exists('gamipress_award_points_to_user')) gamipress_award_points_to_user($user_id, $amount, $type);
        else update_user_meta($user_id, 'tng_xp', max(0, (int) get_user_meta($user_id, 'tng_xp', true)) + $amount);
    }

    private function redirect(string $url, string $key, string $value): void {
        wp_safe_redirect(add_query_arg($key, sanitize_key($value), $url)); exit;
    }

    public function admin_menu(): void {
        add_submenu_page('tn-game-os', 'Community Photos', 'Community Photos', 'manage_options', self::ADMIN_PAGE, [$this, 'admin_page']);
    }

    public function admin_page(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        $status = sanitize_key(wp_unslash($_GET['status'] ?? 'pending'));
        if (!in_array($status, ['pending','approved','rejected'], true)) $status = 'pending';
        $photos = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'posts_per_page' => 100, 'orderby' => 'date', 'order' => 'DESC', 'meta_query' => [['key' => self::META_FLAG, 'value' => '1'], ['key' => self::META_STATUS, 'value' => $status]]]);
        ?>
        <div class="wrap tng-community-photo-admin"><h1>Community Photos</h1><p>Approve Explorer submissions before they appear in the public activity feed. Approved photos earn <?php echo esc_html((string) self::PHOTO_XP); ?> XP once.</p>
            <?php if (isset($_GET['moderated'])): ?><div class="notice notice-success inline"><p>Photo moderation updated.</p></div><?php endif; ?>
            <nav class="nav-tab-wrapper"><?php foreach (['pending' => 'Pending','approved' => 'Approved','rejected' => 'Rejected'] as $key => $label): ?><a class="nav-tab <?php echo $status === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['page' => self::ADMIN_PAGE, 'status' => $key], admin_url('admin.php'))); ?>"><?php echo esc_html($label); ?></a><?php endforeach; ?></nav>
            <style>.tng-photo-admin-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:18px;margin-top:22px}.tng-photo-admin-card{overflow:hidden;border:1px solid #dcdcde;border-radius:16px;background:#fff}.tng-photo-admin-card img{display:block;width:100%;aspect-ratio:4/3;object-fit:cover}.tng-photo-admin-card div{padding:15px}.tng-photo-admin-card h3{margin:0 0 6px}.tng-photo-admin-card p{min-height:38px}.tng-photo-admin-actions{display:flex;gap:8px;flex-wrap:wrap}</style>
            <div class="tng-photo-admin-grid">
                <?php foreach ($photos as $photo): $object_id = absint(get_post_meta($photo->ID, self::META_OBJECT, true)); $user = get_userdata((int) $photo->post_author); ?>
                    <article class="tng-photo-admin-card"><?php echo wp_get_attachment_image($photo->ID, 'medium_large'); ?><div><h3><?php echo esc_html($object_id ? (get_the_title($object_id) ?: 'TN Game place') : 'TN Game photo'); ?></h3><small><?php echo esc_html($user ? ($user->display_name ?: $user->user_login) : 'Explorer'); ?> · <?php echo esc_html(get_the_date('M j, Y', $photo)); ?></small><p><?php echo esc_html((string) get_post_meta($photo->ID, self::META_CAPTION, true)); ?></p><div class="tng-photo-admin-actions"><?php foreach (['approved' => 'Approve','rejected' => 'Reject'] as $next => $label): if ($next === $status) continue; $url = wp_nonce_url(add_query_arg(['action' => 'tng_community_photo_moderate','photo_id' => $photo->ID,'status' => $next], admin_url('admin-post.php')), 'tng_community_photo_' . $photo->ID); ?><a class="button <?php echo $next === 'approved' ? 'button-primary' : ''; ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a><?php endforeach; ?></div></div></article>
                <?php endforeach; ?>
                <?php if (!$photos): ?><div class="notice notice-info inline"><p>No <?php echo esc_html($status); ?> community photos.</p></div><?php endif; ?>
            </div>
        </div><?php
    }
}
