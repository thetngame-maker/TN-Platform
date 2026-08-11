<?php
/**
 * Plugin Name: TN Game Discovery Media Importer
 * Description: Captures up to 10 discovery photos and imports approved Local Discovery photos into WordPress Media, using the first image as featured and the rest as the Activity gallery.
 * Version: 0.2.0
 * Author: The TN Game
 */

if (!defined('ABSPATH')) exit;

final class TNG_Discovery_Media_Importer {
    private const IMPORTED_META = '_tng_discovery_media_imported';
    private const SOURCE_META = '_tng_discovery_source_url';

    public static function boot(): void {
        add_filter('http_request_args', [__CLASS__, 'request_ten_photos'], 20, 2);
        add_action('added_post_meta', [__CLASS__, 'maybe_import'], 20, 4);
        add_action('updated_post_meta', [__CLASS__, 'maybe_import'], 20, 4);
    }

    public static function request_ten_photos(array $args, string $url): array {
        if (strpos($url, 'https://api.apify.com/v2/acts/') !== 0 || strpos($url, '/run-sync-get-dataset-items') === false) return $args;
        if (empty($args['body']) || !is_string($args['body'])) return $args;

        $body = json_decode($args['body'], true);
        if (!is_array($body) || empty($body['searchStringsArray']) || !array_key_exists('locationQuery', $body)) return $args;

        $body['includeImages'] = true;
        $body['maxImagesPerPlace'] = 10;
        $args['body'] = wp_json_encode($body);
        return $args;
    }

    public static function maybe_import($meta_id, $post_id, $meta_key, $meta_value): void {
        if ($meta_key !== '_tng_discovery_candidate_id') return;
        $post_id = absint($post_id);
        $candidate_id = absint($meta_value);
        if (!$post_id || !$candidate_id || get_post_type($post_id) !== 'st_activity' || get_post_type($candidate_id) !== 'tng_local_candidate') return;
        if (get_post_meta($post_id, self::IMPORTED_META, true)) return;

        $photos = (array)get_post_meta($candidate_id, '_tng_local_photos', true);
        $photos = array_slice(array_values(array_unique(array_filter(array_map('esc_url_raw', $photos)))), 0, 10);
        if (!$photos) {
            update_post_meta($post_id, self::IMPORTED_META, 'no_photos');
            return;
        }

        if (!function_exists('download_url')) require_once ABSPATH . 'wp-admin/includes/file.php';
        if (!function_exists('media_handle_sideload')) require_once ABSPATH . 'wp-admin/includes/media.php';
        if (!function_exists('wp_generate_attachment_metadata')) require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_ids = [];
        $errors = [];
        foreach ($photos as $index => $url) {
            $attachment_id = self::existing_attachment_for_source($url);
            if (!$attachment_id) $attachment_id = self::sideload($url, $post_id, $index + 1);
            if (is_wp_error($attachment_id)) {
                $errors[] = $attachment_id->get_error_message();
                continue;
            }
            if ($attachment_id) $attachment_ids[] = (int)$attachment_id;
        }

        if (!$attachment_ids) {
            update_post_meta($post_id, self::IMPORTED_META, 'failed');
            if ($errors) update_post_meta($post_id, '_tng_discovery_media_errors', array_values(array_unique($errors)));
            return;
        }

        $featured = array_shift($attachment_ids);
        if ($featured) set_post_thumbnail($post_id, $featured);

        $gallery_ids = array_values(array_filter(array_map('absint', $attachment_ids)));
        update_post_meta($post_id, '_tng_gallery_image_ids', $gallery_ids);
        update_post_meta($post_id, 'gallery', implode(',', $gallery_ids));
        update_post_meta($post_id, '_tng_discovery_featured_attachment_id', $featured);
        update_post_meta($post_id, '_tng_discovery_gallery_attachment_ids', $gallery_ids);
        update_post_meta($post_id, self::IMPORTED_META, current_time('mysql'));
        if ($errors) update_post_meta($post_id, '_tng_discovery_media_errors', array_values(array_unique($errors)));
        else delete_post_meta($post_id, '_tng_discovery_media_errors');
    }

    private static function existing_attachment_for_source(string $url): int {
        $ids = get_posts([
            'post_type'=>'attachment',
            'post_status'=>'inherit',
            'posts_per_page'=>1,
            'fields'=>'ids',
            'meta_key'=>self::SOURCE_META,
            'meta_value'=>$url,
        ]);
        return $ids ? (int)$ids[0] : 0;
    }

    private static function sideload(string $url, int $post_id, int $position) {
        $tmp = download_url($url, 30);
        if (is_wp_error($tmp)) return $tmp;

        $path = (string)parse_url($url, PHP_URL_PATH);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) $ext = 'jpg';
        $base = sanitize_file_name(get_the_title($post_id));
        if (!$base) $base = 'tn-game-place';
        $filename = $base . '-' . str_pad((string)$position, 2, '0', STR_PAD_LEFT) . '.' . $ext;

        $file = ['name'=>$filename, 'tmp_name'=>$tmp];
        $attachment_id = media_handle_sideload($file, $post_id, get_the_title($post_id));
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return $attachment_id;
        }

        update_post_meta((int)$attachment_id, self::SOURCE_META, esc_url_raw($url));
        update_post_meta((int)$attachment_id, '_wp_attachment_image_alt', sanitize_text_field(get_the_title($post_id)));
        return (int)$attachment_id;
    }
}

TNG_Discovery_Media_Importer::boot();
