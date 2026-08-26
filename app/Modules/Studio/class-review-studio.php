<?php
namespace TNG_OS\Modules\Studio;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Query;

if (!defined('ABSPATH')) exit;

final class Review_Studio implements Module_Interface {
    private const QUEUE_TYPE = 'tng_concert_import';
    private ?Container $container = null;

    public function id(): string { return 'review_studio'; }

    public function register(Container $container): void {
        $this->container = $container;
        add_action('admin_menu', [$this, 'menu'], 22);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_tng_review_bulk', [$this, 'handle_bulk']);
    }

    public function boot(Container $container): void { $this->container = $container; }

    public function menu(): void {
        add_submenu_page(
            'tn-game-os',
            'Review Studio',
            'Review Studio',
            'edit_posts',
            'tng-review-studio',
            [$this, 'page']
        );
    }

    public function assets(string $hook): void {
        if (strpos($hook, 'tng-review-studio') === false) return;
        wp_enqueue_style('tng-review-studio', TNG_OS_URL . 'assets/admin/review-studio.css', [], TNG_OS_VERSION);
        wp_enqueue_script('tng-review-studio', TNG_OS_URL . 'assets/admin/review-studio.js', [], TNG_OS_VERSION, true);
    }

    public function page(): void {
        if (!current_user_can('edit_posts')) wp_die('Not allowed.');

        $status = sanitize_key(wp_unslash($_GET['status'] ?? 'new'));
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $selected_id = absint($_GET['item'] ?? 0);
        $items = $this->items($status, $search);
        if (!$selected_id && $items) $selected_id = (int)$items[0]->ID;
        $selected = $selected_id ? get_post($selected_id) : null;
        if ($selected && $selected->post_type !== self::QUEUE_TYPE) $selected = null;

        $counts = $this->counts();
        $health = $this->health();
        ?>
        <div class="wrap tng-review-studio">
            <header class="tng-review-header">
                <div>
                    <span class="tng-review-eyebrow">TN PLATFORM · MILESTONE 3</span>
                    <h1>Review Studio</h1>
                    <p>Review incoming destination content, understand changes, and publish with confidence.</p>
                </div>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-concert-intelligence')); ?>">Concert Intelligence</a>
            </header>

            <?php if (isset($_GET['review_notice'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['review_notice']))); ?></p></div>
            <?php endif; ?>

            <section class="tng-health-ribbon" aria-label="Platform health">
                <?php foreach ($health as $service => $state): ?>
                    <article class="<?php echo $state['ok'] ? 'is-ok' : 'is-warn'; ?>">
                        <span class="tng-health-dot"></span>
                        <div><small><?php echo esc_html($service); ?></small><strong><?php echo esc_html($state['label']); ?></strong></div>
                    </article>
                <?php endforeach; ?>
            </section>

            <section class="tng-review-stats">
                <?php foreach ([
                    'new' => 'Awaiting review',
                    'imported' => 'Published',
                    'ignored' => 'Ignored',
                    'all' => 'Total discovered',
                ] as $key => $label): ?>
                    <article><strong><?php echo (int)($counts[$key] ?? 0); ?></strong><span><?php echo esc_html($label); ?></span></article>
                <?php endforeach; ?>
                <article><strong><?php echo (int)ceil(($counts['new'] ?? 0) * .4); ?> min</strong><span>Estimated review time</span></article>
            </section>

            <form class="tng-review-toolbar" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="tng-review-studio">
                <div class="tng-review-tabs">
                    <?php foreach (['new'=>'Needs review','imported'=>'Published','ignored'=>'Ignored','all'=>'All'] as $key=>$label): ?>
                        <a class="<?php echo $status === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['page'=>'tng-review-studio','status'=>$key], admin_url('admin.php'))); ?>"><?php echo esc_html($label); ?><span><?php echo (int)($counts[$key] ?? 0); ?></span></a>
                    <?php endforeach; ?>
                </div>
                <div class="tng-review-search"><input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search events, venues, dates"><button class="button">Search</button></div>
            </form>

            <div class="tng-review-layout">
                <aside class="tng-review-queue">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="tng_review_bulk">
                        <?php wp_nonce_field('tng_review_bulk'); ?>
                        <div class="tng-queue-head"><div><h2>Review queue</h2><p><?php echo count($items); ?> visible items</p></div><label><input id="tng-select-all" type="checkbox"> Select all</label></div>
                        <div class="tng-queue-actions"><select name="bulk_action"><option value="">Bulk action</option><option value="publish">Publish selected</option><option value="ignore">Ignore selected</option></select><button class="button">Apply</button></div>
                        <div class="tng-queue-list">
                            <?php if (!$items): ?><div class="tng-review-empty"><span>✓</span><h3>Queue is clear</h3><p>No items match this view.</p></div><?php endif; ?>
                            <?php foreach ($items as $item): $data = $this->data((int)$item->ID); $confidence = $this->confidence($data); ?>
                                <article class="tng-queue-card <?php echo $selected_id === (int)$item->ID ? 'is-selected' : ''; ?>">
                                    <input class="tng-item-check" type="checkbox" name="item_ids[]" value="<?php echo (int)$item->ID; ?>">
                                    <a href="<?php echo esc_url(add_query_arg(['page'=>'tng-review-studio','status'=>$status,'s'=>$search,'item'=>$item->ID], admin_url('admin.php'))); ?>">
                                        <div class="tng-card-thumb"><?php if (!empty($data['image'])): ?><img src="<?php echo esc_url($data['image']); ?>" alt=""><?php else: ?><span>🎵</span><?php endif; ?></div>
                                        <div class="tng-card-copy"><small><?php echo esc_html($this->format_date($data['start'] ?? '')); ?></small><h3><?php echo esc_html($item->post_title); ?></h3><p><?php echo esc_html($data['venue'] ?? 'Venue pending'); ?></p><div class="tng-card-meta"><span><?php echo esc_html($this->status_label((int)$item->ID)); ?></span><b class="<?php echo $confidence < 75 ? 'is-low' : ''; ?>"><?php echo $confidence; ?>%</b></div></div>
                                    </a>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </form>
                </aside>

                <main class="tng-review-workspace">
                    <?php if ($selected): $this->workspace($selected); else: ?>
                        <div class="tng-review-empty tng-review-empty--large"><span>📥</span><h2>Select an item to begin</h2><p>The event preview, changes, and publishing controls will appear here.</p></div>
                    <?php endif; ?>
                </main>
            </div>
        </div>
        <?php
    }

    private function workspace(\WP_Post $item): void {
        $data = $this->data((int)$item->ID);
        $activity_id = absint(get_post_meta($item->ID, '_tng_ci_activity_id', true));
        $existing = $activity_id ? get_post($activity_id) : null;
        $confidence = $this->confidence($data);
        $warnings = $this->warnings($data);
        $import_url = wp_nonce_url(admin_url('admin-post.php?action=tng_concert_import_item&item_id='.$item->ID), 'tng_concert_import_'.$item->ID);
        $ignore_url = wp_nonce_url(admin_url('admin-post.php?action=tng_concert_ignore_item&item_id='.$item->ID), 'tng_concert_ignore_'.$item->ID);
        ?>
        <div class="tng-workspace-topbar"><div><span>Reviewing <?php echo (int)$item->ID; ?></span><h2><?php echo esc_html($item->post_title); ?></h2></div><div class="tng-confidence <?php echo $confidence < 75 ? 'is-low' : ''; ?>"><strong><?php echo $confidence; ?>%</strong><span>confidence</span></div></div>
        <div class="tng-workspace-grid">
            <section class="tng-event-preview">
                <div class="tng-event-poster"><?php if (!empty($data['image'])): ?><img src="<?php echo esc_url($data['image']); ?>" alt=""><?php else: ?><span>Poster unavailable</span><?php endif; ?></div>
                <div class="tng-event-summary"><span class="tng-content-type">Concert</span><h2><?php echo esc_html($item->post_title); ?></h2><p class="tng-event-date"><?php echo esc_html($this->format_date($data['start'] ?? '')); ?></p><dl>
                    <?php foreach (['venue'=>'Venue','doors'=>'Doors','price'=>'Price','age'=>'Age','status'=>'Event status'] as $key=>$label): ?><div><dt><?php echo esc_html($label); ?></dt><dd><?php echo esc_html((string)($data[$key] ?? 'Not provided')); ?></dd></div><?php endforeach; ?>
                </dl><?php if (!empty($data['url'])): ?><a href="<?php echo esc_url($data['url']); ?>" target="_blank" rel="noopener">Open official ticket page ↗</a><?php endif; ?></div>
            </section>

            <aside class="tng-review-assistant">
                <h3>Review assistant</h3>
                <?php if ($warnings): ?><div class="tng-assistant-group"><strong>Needs attention</strong><?php foreach ($warnings as $warning): ?><p>⚠ <?php echo esc_html($warning); ?></p><?php endforeach; ?></div><?php else: ?><div class="tng-assistant-group is-success"><strong>Ready to publish</strong><p>✓ Required event details are present.</p></div><?php endif; ?>
                <div class="tng-assistant-group"><strong>Suggested next steps</strong><p>✓ Preserve the official ticket link</p><p>✓ Use venue destination defaults</p><p>○ AI enrichment connects here next</p><p>○ Nearby recommendations connect here next</p></div>
            </aside>
        </div>

        <section class="tng-review-panel">
            <div class="tng-panel-heading"><div><span>DIFFERENCE VIEWER</span><h3><?php echo $existing ? 'Incoming versus published activity' : 'New destination content'; ?></h3></div><span class="tng-change-badge"><?php echo $existing ? 'Update detected' : 'New event'; ?></span></div>
            <div class="tng-diff-grid">
                <article><small>Current activity</small><?php if ($existing): ?><h4><?php echo esc_html($existing->post_title); ?></h4><p><?php echo esc_html(wp_trim_words(wp_strip_all_tags($existing->post_content), 45)); ?></p><a href="<?php echo esc_url(get_edit_post_link($existing->ID)); ?>">Edit current Activity</a><?php else: ?><p>No existing Traveler Activity was linked to this import.</p><?php endif; ?></article>
                <article><small>Incoming source</small><h4><?php echo esc_html($item->post_title); ?></h4><p><?php echo esc_html(wp_trim_words(wp_strip_all_tags((string)($data['description'] ?? $item->post_content)), 45)); ?></p><dl><div><dt>Date</dt><dd><?php echo esc_html($this->format_date($data['start'] ?? '')); ?></dd></div><div><dt>Price</dt><dd><?php echo esc_html((string)($data['price'] ?? 'Not provided')); ?></dd></div></dl></article>
            </div>
        </section>

        <section class="tng-review-panel">
            <div class="tng-panel-heading"><div><span>TRAVELER OUTPUT</span><h3>Publishing preview</h3></div></div>
            <div class="tng-output-grid"><div><small>Post type</small><strong>Traveler Activity</strong></div><div><small>Status</small><strong>Published</strong></div><div><small>Category</small><strong>Concerts</strong></div><div><small>Source tracking</small><strong>Connected</strong></div></div>
        </section>

        <footer class="tng-workspace-actions"><a class="button" href="<?php echo esc_url($ignore_url); ?>">Ignore</a><a class="button" href="<?php echo esc_url(get_edit_post_link($item->ID)); ?>">Edit source data</a><a class="button button-primary button-hero" href="<?php echo esc_url($import_url); ?>"><?php echo $existing ? 'Update existing Activity' : 'Publish Activity'; ?></a></footer>
        <?php
    }

    private function items(string $status, string $search): array {
        $args = ['post_type'=>self::QUEUE_TYPE,'post_status'=>['pending','publish','draft'],'posts_per_page'=>100,'orderby'=>'modified','order'=>'DESC'];
        if ($search !== '') $args['s'] = $search;
        if ($status !== 'all') $args['meta_query'] = [['key'=>'_tng_ci_queue_status','value'=>$status]];
        return get_posts($args);
    }

    private function counts(): array {
        $counts = ['all'=>0,'new'=>0,'imported'=>0,'ignored'=>0];
        foreach (array_keys($counts) as $status) {
            $args = ['post_type'=>self::QUEUE_TYPE,'post_status'=>['pending','publish','draft'],'posts_per_page'=>1,'fields'=>'ids'];
            if ($status !== 'all') $args['meta_query'] = [['key'=>'_tng_ci_queue_status','value'=>$status]];
            $counts[$status] = (int)(new WP_Query($args))->found_posts;
        }
        return $counts;
    }

    private function data(int $id): array { $data = get_post_meta($id, '_tng_ci_event_data', true); return is_array($data) ? $data : []; }
    private function status_label(int $id): string { return ucfirst((string)get_post_meta($id, '_tng_ci_queue_status', true) ?: 'new'); }
    private function format_date(string $value): string { $time = strtotime($value); return $time ? wp_date('D, M j · g:i A', $time) : 'Date pending'; }

    private function confidence(array $data): int {
        $score = 100;
        foreach (['title'=>22,'start'=>18,'venue'=>14,'url'=>14,'image'=>12,'description'=>10,'price'=>5,'doors'=>5] as $field=>$penalty) if (empty($data[$field])) $score -= $penalty;
        return max(0, min(100, $score));
    }

    private function warnings(array $data): array {
        $warnings = [];
        foreach (['start'=>'Event date is missing.','venue'=>'Venue is missing.','image'=>'Poster image is missing.','description'=>'Description is missing.','url'=>'Official ticket link is missing.'] as $field=>$message) if (empty($data[$field])) $warnings[] = $message;
        return $warnings;
    }

    private function health(): array {
        $endpoint = untrailingslashit((string)get_option('tng_ci_api_endpoint', ''));
        $has_key = (string)get_option('tng_ci_api_key', '') !== '';
        $api = ['ok'=>false,'label'=>'Not configured'];
        $browser = ['ok'=>false,'label'=>'Unknown'];
        $provider = ['ok'=>false,'label'=>'Unknown'];
        if ($endpoint && $has_key) {
            $cached = get_transient('tng_review_health');
            if (!is_array($cached)) {
                $response = wp_remote_get($endpoint.'/health', ['timeout'=>8,'headers'=>['Accept'=>'application/json']]);
                $cached = is_wp_error($response) ? [] : json_decode(wp_remote_retrieve_body($response), true);
                set_transient('tng_review_health', is_array($cached) ? $cached : [], 60);
            }
            $api = ['ok'=>!empty($cached['ok']),'label'=>!empty($cached['ok']) ? 'Online' : 'Needs attention'];
            $browser = ['ok'=>!empty($cached['browser']['ok']),'label'=>!empty($cached['browser']['ok']) ? 'Chromium ready' : 'Unavailable'];
            $providers = is_array($cached['providers'] ?? null) ? $cached['providers'] : [];
            $ready = false; foreach ($providers as $entry) if (!empty($entry['ok'])) { $ready = true; break; }
            $provider = ['ok'=>$ready,'label'=>$ready ? 'Provider ready' : 'Unavailable'];
        }
        return ['API'=>$api,'Browser'=>$browser,'Providers'=>$provider,'Media'=>['ok'=>true,'label'=>'WordPress ready'],'Traveler'=>['ok'=>post_type_exists('st_activity'),'label'=>post_type_exists('st_activity') ? 'Connected' : 'Unavailable']];
    }

    public function handle_bulk(): void {
        if (!current_user_can('edit_posts')) wp_die('Not allowed.');
        check_admin_referer('tng_review_bulk');
        $action = sanitize_key(wp_unslash($_POST['bulk_action'] ?? ''));
        $ids = array_filter(array_map('absint', (array)($_POST['item_ids'] ?? [])));
        $completed = 0;
        $concerts = $this->container ? $this->container->get('concert_intelligence') : null;
        foreach ($ids as $id) {
            if (get_post_type($id) !== self::QUEUE_TYPE) continue;
            if ($action === 'publish' && $concerts && is_callable([$concerts, 'import_item'])) { if ($concerts->import_item($id)) $completed++; }
            if ($action === 'ignore') { update_post_meta($id, '_tng_ci_queue_status', 'ignored'); wp_update_post(['ID'=>$id,'post_status'=>'draft']); $completed++; }
        }
        wp_safe_redirect(add_query_arg(['page'=>'tng-review-studio','review_notice'=>sprintf('%d item(s) processed.', $completed)], admin_url('admin.php')));
        exit;
    }
}
