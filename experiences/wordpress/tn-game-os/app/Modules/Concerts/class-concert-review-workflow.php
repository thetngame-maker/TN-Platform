<?php
namespace TNG_OS\Modules\Concerts;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Query;

if (!defined('ABSPATH')) exit;

final class Concert_Review_Workflow implements Module_Interface {
    private Container $container;
    private const QUEUE_TYPE = 'tng_concert_import';
    private const PAGE = 'tng-concert-review-queue';

    public function id(): string { return 'concert_review_workflow'; }

    public function register(Container $container): void {
        $this->container = $container;
        $container->set('concert_review_workflow', $this);

        add_action('admin_menu', [$this, 'menu'], 25);
        add_action('admin_post_tng_ci_review_item', [$this, 'handle_review']);
        add_action('admin_post_tng_ci_review_import_item', [$this, 'handle_review_import']);
        add_action('admin_post_tng_ci_import_reviewed', [$this, 'handle_import_reviewed']);
        add_action('admin_post_tng_ci_ignore_review_item', [$this, 'handle_ignore']);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page(
            'tn-game-os',
            'Concert Review Queue',
            'Review Queue',
            'edit_posts',
            self::PAGE,
            [$this, 'page']
        );
    }

    public function handle_review(): void {
        $item_id = $this->requested_item('tng_ci_review_');
        update_post_meta($item_id, '_tng_ci_queue_status', 'reviewed');
        update_post_meta($item_id, '_tng_ci_reviewed_by', get_current_user_id());
        update_post_meta($item_id, '_tng_ci_reviewed_at', current_time('mysql'));
        wp_update_post(['ID' => $item_id, 'post_status' => 'pending']);
        $this->redirect('Event marked reviewed.');
    }

    public function handle_review_import(): void {
        $item_id = $this->requested_item('tng_ci_review_import_');
        update_post_meta($item_id, '_tng_ci_queue_status', 'reviewed');
        update_post_meta($item_id, '_tng_ci_reviewed_by', get_current_user_id());
        update_post_meta($item_id, '_tng_ci_reviewed_at', current_time('mysql'));
        $activity_id = $this->import_item($item_id);
        $this->redirect($activity_id ? 'Event reviewed and imported.' : 'The event could not be imported.', $activity_id ? 'success' : 'error');
    }

    public function handle_import_reviewed(): void {
        if (!current_user_can('edit_posts')) wp_die('Not allowed.');
        check_admin_referer('tng_ci_import_reviewed');

        $items = get_posts([
            'post_type' => self::QUEUE_TYPE,
            'post_status' => ['pending', 'draft'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => '_tng_ci_queue_status',
            'meta_value' => 'reviewed',
        ]);

        $imported = 0;
        $failed = 0;
        foreach ($items as $item_id) {
            if ($this->import_item((int)$item_id)) $imported++;
            else $failed++;
        }

        $this->redirect(sprintf('%d reviewed events imported; %d failed.', $imported, $failed), $failed ? 'warning' : 'success');
    }

    public function handle_ignore(): void {
        $item_id = $this->requested_item('tng_ci_ignore_review_');
        update_post_meta($item_id, '_tng_ci_queue_status', 'ignored');
        update_post_meta($item_id, '_tng_ci_reviewed_by', get_current_user_id());
        update_post_meta($item_id, '_tng_ci_reviewed_at', current_time('mysql'));
        wp_update_post(['ID' => $item_id, 'post_status' => 'draft']);
        $this->redirect('Event ignored.');
    }

    private function requested_item(string $nonce_action): int {
        $item_id = isset($_GET['item_id']) ? absint($_GET['item_id']) : 0;
        if (!$item_id || !current_user_can('edit_posts')) wp_die('Not allowed.');
        check_admin_referer($nonce_action . $item_id);
        if (get_post_type($item_id) !== self::QUEUE_TYPE) wp_die('Invalid queue item.');
        return $item_id;
    }

    private function import_item(int $item_id): int {
        $concerts = $this->container->get('concert_intelligence');
        if (!$concerts || !is_callable([$concerts, 'import_item'])) return 0;

        $activity_id = (int)$concerts->import_item($item_id);
        if (!$activity_id) return 0;

        $event = get_post_meta($item_id, '_tng_ci_event_data', true);
        $event = is_array($event) ? $event : [];
        $provider = sanitize_key((string)($event['provider'] ?? '')) ?: 'unknown';
        update_post_meta($activity_id, '_tng_source_provider', $provider);

        if (!empty($event['image'])) {
            if (has_post_thumbnail($activity_id)) {
                update_post_meta($activity_id, '_tng_source_image_status', 'sideloaded');
                delete_post_meta($activity_id, '_tng_source_image_error');
            } else {
                update_post_meta($activity_id, '_tng_source_image_status', 'failed');
                update_post_meta($activity_id, '_tng_source_image_error', 'Poster URL was present but WordPress did not create a featured image.');
            }
        }

        update_post_meta($item_id, '_tng_ci_imported_at', current_time('mysql'));
        update_post_meta($item_id, '_tng_ci_imported_by', get_current_user_id());
        return $activity_id;
    }

    private function redirect(string $message, string $type = 'success'): void {
        wp_safe_redirect(add_query_arg([
            'page' => self::PAGE,
            'tng_ci_message' => rawurlencode($message),
            'tng_ci_type' => sanitize_key($type),
        ], admin_url('admin.php')));
        exit;
    }

    public function page(): void {
        if (!current_user_can('edit_posts')) return;

        $view = isset($_GET['queue_status']) ? sanitize_key(wp_unslash($_GET['queue_status'])) : 'open';
        $allowed = ['open', 'new', 'reviewed', 'imported', 'ignored', 'all'];
        if (!in_array($view, $allowed, true)) $view = 'open';

        $meta_query = [];
        if ($view === 'open') {
            $meta_query = [[
                'key' => '_tng_ci_queue_status',
                'value' => ['new', 'reviewed'],
                'compare' => 'IN',
            ]];
        } elseif ($view !== 'all') {
            $meta_query = [[
                'key' => '_tng_ci_queue_status',
                'value' => $view,
            ]];
        }

        $query = new WP_Query([
            'post_type' => self::QUEUE_TYPE,
            'post_status' => ['pending', 'draft', 'publish'],
            'posts_per_page' => 100,
            'orderby' => 'meta_value',
            'meta_key' => '_tng_ci_event_data',
            'order' => 'ASC',
            'meta_query' => $meta_query,
        ]);

        $counts = $this->counts();
        ?>
        <div class="wrap tng-ci-review-wrap">
            <style>
                .tng-ci-review-wrap{max-width:1500px}.tng-ci-review-head{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;margin:22px 0}.tng-ci-review-head h1{margin:2px 0 8px}.tng-ci-review-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0}.tng-ci-review-tabs a{padding:8px 12px;border:1px solid #c3c4c7;border-radius:999px;text-decoration:none;background:#fff}.tng-ci-review-tabs a.current{background:#1d2327;color:#fff;border-color:#1d2327}.tng-ci-review-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:18px}.tng-ci-review-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.04)}.tng-ci-review-card img{width:100%;height:190px;object-fit:cover;background:#f0f0f1}.tng-ci-review-card-body{padding:16px}.tng-ci-review-card h2{font-size:18px;line-height:1.25;margin:0 0 10px}.tng-ci-review-meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:12px 0}.tng-ci-review-meta div{background:#f6f7f7;border-radius:8px;padding:8px}.tng-ci-review-meta span{display:block;color:#646970;font-size:11px;text-transform:uppercase;letter-spacing:.06em}.tng-ci-review-meta strong{display:block;margin-top:3px}.tng-ci-review-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:14px}.tng-ci-status{display:inline-block;padding:4px 8px;border-radius:999px;background:#f0f0f1;font-size:12px;text-transform:capitalize}.tng-ci-status.reviewed{background:#fff3cd}.tng-ci-status.imported{background:#d1e7dd}.tng-ci-status.ignored{background:#e2e3e5}.tng-ci-linked{margin-top:12px;padding-top:12px;border-top:1px solid #eee}.tng-ci-empty{background:#fff;border:1px dashed #c3c4c7;border-radius:12px;padding:48px;text-align:center}
            </style>

            <div class="tng-ci-review-head">
                <div><span>CONCERT INTELLIGENCE</span><h1>Review Queue</h1><p>Review provider data before creating or updating Traveler Activities.</p></div>
                <?php if (!empty($counts['reviewed'])): ?>
                    <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tng_ci_import_reviewed'), 'tng_ci_import_reviewed')); ?>">Import <?php echo (int)$counts['reviewed']; ?> reviewed events</a>
                <?php endif; ?>
            </div>

            <?php if (isset($_GET['tng_ci_message'])): $notice_type = sanitize_key((string)($_GET['tng_ci_type'] ?? 'success')); ?>
                <div class="notice notice-<?php echo esc_attr($notice_type); ?> is-dismissible"><p><?php echo esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['tng_ci_message'])))); ?></p></div>
            <?php endif; ?>

            <nav class="tng-ci-review-tabs">
                <?php foreach (['open'=>'Open','new'=>'New','reviewed'=>'Reviewed','imported'=>'Imported','ignored'=>'Ignored','all'=>'All'] as $key=>$label): ?>
                    <a class="<?php echo $view === $key ? 'current' : ''; ?>" href="<?php echo esc_url(add_query_arg(['page'=>self::PAGE,'queue_status'=>$key],admin_url('admin.php'))); ?>"><?php echo esc_html($label); ?><?php if (isset($counts[$key])) echo ' ('.(int)$counts[$key].')'; ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if (!$query->have_posts()): ?>
                <div class="tng-ci-empty"><h2>No events in this view</h2><p>Run a source sync or choose another queue status.</p></div>
            <?php else: ?>
                <div class="tng-ci-review-grid">
                    <?php while ($query->have_posts()): $query->the_post(); $this->card(get_the_ID()); endwhile; wp_reset_postdata(); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function card(int $item_id): void {
        $event = get_post_meta($item_id, '_tng_ci_event_data', true);
        $event = is_array($event) ? $event : [];
        $status = (string)get_post_meta($item_id, '_tng_ci_queue_status', true) ?: 'new';
        $activity_id = absint(get_post_meta($item_id, '_tng_ci_activity_id', true));
        $start = !empty($event['start']) ? strtotime((string)$event['start']) : false;
        $provider = sanitize_key((string)($event['provider'] ?? 'unknown'));
        $venue_id = absint(get_post_meta($item_id, '_tng_ci_venue_id', true));
        $venue = $venue_id ? get_the_title($venue_id) : (string)($event['venue'] ?? '—');
        ?>
        <article class="tng-ci-review-card">
            <?php if (!empty($event['image'])): ?><img src="<?php echo esc_url((string)$event['image']); ?>" alt=""><?php else: ?><div style="height:190px;background:#f0f0f1;display:grid;place-items:center;font-size:42px">🎟️</div><?php endif; ?>
            <div class="tng-ci-review-card-body">
                <span class="tng-ci-status <?php echo esc_attr($status); ?>"><?php echo esc_html($status); ?></span>
                <h2><?php echo esc_html((string)($event['title'] ?? get_the_title($item_id))); ?></h2>
                <div class="tng-ci-review-meta">
                    <div><span>Date</span><strong><?php echo esc_html($start ? wp_date('M j, Y g:i a', $start) : 'Not provided'); ?></strong></div>
                    <div><span>Doors</span><strong><?php echo esc_html((string)($event['doors'] ?? '') ?: '—'); ?></strong></div>
                    <div><span>Venue</span><strong><?php echo esc_html($venue ?: '—'); ?></strong></div>
                    <div><span>Provider</span><strong><?php echo esc_html(str_replace('-', ' ', $provider)); ?></strong></div>
                    <div><span>Event status</span><strong><?php echo esc_html((string)($event['status'] ?? 'scheduled')); ?></strong></div>
                    <div><span>Price from</span><strong><?php echo !empty($event['price']) ? esc_html('$'.(string)$event['price']) : '—'; ?></strong></div>
                </div>
                <div class="tng-ci-review-actions">
                    <?php if ($status === 'new'): ?>
                        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tng_ci_review_item&item_id='.$item_id), 'tng_ci_review_'.$item_id)); ?>">Mark reviewed</a>
                    <?php endif; ?>
                    <?php if (in_array($status, ['new','reviewed'], true)): ?>
                        <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tng_ci_review_import_item&item_id='.$item_id), 'tng_ci_review_import_'.$item_id)); ?>">Review &amp; import</a>
                    <?php endif; ?>
                    <?php if ($status !== 'ignored' && $status !== 'imported'): ?>
                        <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tng_ci_ignore_review_item&item_id='.$item_id), 'tng_ci_ignore_review_'.$item_id)); ?>">Ignore</a>
                    <?php endif; ?>
                    <?php if (!empty($event['url'])): ?><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url((string)$event['url']); ?>">Tickets ↗</a><?php endif; ?>
                </div>
                <?php if ($activity_id): ?><div class="tng-ci-linked"><strong>Traveler Activity:</strong> <a href="<?php echo esc_url(get_edit_post_link($activity_id)); ?>"><?php echo esc_html(get_the_title($activity_id)); ?></a></div><?php endif; ?>
            </div>
        </article>
        <?php
    }

    private function counts(): array {
        $counts = ['new'=>0,'reviewed'=>0,'imported'=>0,'ignored'=>0,'all'=>0,'open'=>0];
        foreach (['new','reviewed','imported','ignored'] as $status) {
            $query = new WP_Query([
                'post_type' => self::QUEUE_TYPE,
                'post_status' => ['pending','draft','publish'],
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_key' => '_tng_ci_queue_status',
                'meta_value' => $status,
            ]);
            $counts[$status] = (int)$query->found_posts;
        }
        $counts['open'] = $counts['new'] + $counts['reviewed'];
        $counts['all'] = array_sum([$counts['new'],$counts['reviewed'],$counts['imported'],$counts['ignored']]);
        return $counts;
    }
}
