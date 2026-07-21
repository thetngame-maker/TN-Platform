<?php
namespace TNG_OS\Modules\Admin;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Query;

if (!defined('ABSPATH')) exit;

final class Service_Tag_Manager implements Module_Interface {
    private Container $container;

    public function id(): string { return 'service_tag_manager'; }

    public function register(Container $container): void {
        $this->container = $container;
        $container->set('service_tag_manager', $this);

        add_action('admin_menu', [$this, 'menu'], 38);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_tng_service_tags_apply', [$this, 'apply']);

        add_filter('bulk_actions-edit-st_activity', [$this, 'bulk_actions']);
        add_filter('handle_bulk_actions-edit-st_activity', [$this, 'handle_bulk_action'], 10, 3);
        add_action('admin_notices', [$this, 'bulk_notice']);

        add_filter('manage_st_activity_posts_columns', [$this, 'columns'], 30);
        add_action('manage_st_activity_posts_custom_column', [$this, 'column'], 30, 2);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page(
            'tn-game-os',
            'Service Tag Manager',
            'Service Tags',
            'edit_posts',
            'tng-service-tag-manager',
            [$this, 'page']
        );
    }

    public function assets(string $hook): void {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== 'tng-service-tag-manager') return;

        wp_enqueue_style(
            'tng-service-tag-manager',
            TNG_OS_URL . 'assets/admin/service-tag-manager.css',
            [],
            TNG_OS_VERSION
        );
    }

    private function taxonomy(): string {
        $registry = $this->container->get('services');
        if ($registry && is_callable([$registry, 'taxonomy'])) return (string)$registry->taxonomy();

        foreach (['st_activity_type', 'activity_type', 'st_activity_types'] as $taxonomy) {
            if (taxonomy_exists($taxonomy)) return $taxonomy;
        }
        return '';
    }

    private function service_terms(): array {
        $taxonomy = $this->taxonomy();
        if (!$taxonomy) return [];

        $definitions = [
            'hiking-trails' => ['label' => 'Trails', 'icon' => '🥾'],
            'waterfalls' => ['label' => 'Waterfalls', 'icon' => '💧'],
            'food-and-drink' => ['label' => 'Food & Drink', 'icon' => '🍽'],
            'concerts' => ['label' => 'Concerts', 'icon' => '🎵'],
            'shops' => ['label' => 'Shops', 'icon' => '🛍'],
            'historic-sites' => ['label' => 'Historic Sites', 'icon' => '🏛'],
            'campgrounds' => ['label' => 'Campgrounds', 'icon' => '⛺'],
            'events' => ['label' => 'Events', 'icon' => '📅'],
            'scenic-views' => ['label' => 'Scenic Views', 'icon' => '🌄'],
        ];

        $output = [];
        foreach ($definitions as $slug => $definition) {
            $term = get_term_by('slug', $slug, $taxonomy);
            if (!$term || is_wp_error($term)) {
                $created = wp_insert_term($definition['label'], $taxonomy, ['slug' => $slug]);
                if (!is_wp_error($created)) $term = get_term((int)$created['term_id'], $taxonomy);
            }
            if ($term && !is_wp_error($term)) {
                $output[$slug] = [
                    'id' => (int)$term->term_id,
                    'name' => (string)$term->name,
                    'label' => $definition['label'],
                    'icon' => $definition['icon'],
                ];
            }
        }
        return $output;
    }

    public function bulk_actions(array $actions): array {
        $actions['tng_add_trails'] = 'TN Game: Add Trails tag';
        $actions['tng_add_waterfalls'] = 'TN Game: Add Waterfalls tag';
        $actions['tng_add_trails_waterfalls'] = 'TN Game: Add Trails + Waterfalls';
        return $actions;
    }

    public function handle_bulk_action(string $redirect, string $action, array $post_ids): string {
        $map = [
            'tng_add_trails' => ['hiking-trails'],
            'tng_add_waterfalls' => ['waterfalls'],
            'tng_add_trails_waterfalls' => ['hiking-trails', 'waterfalls'],
        ];
        if (!isset($map[$action])) return $redirect;

        $updated = $this->assign($post_ids, $map[$action], false);
        return add_query_arg([
            'tng_service_tagged' => $updated,
            'tng_service_action' => sanitize_key($action),
        ], $redirect);
    }

    public function bulk_notice(): void {
        if (empty($_GET['tng_service_tagged'])) return;
        $count = absint($_GET['tng_service_tagged']);
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
            _n('TN Game service tags updated on %d activity.', 'TN Game service tags updated on %d activities.', $count),
            $count
        )) . '</p></div>';
    }

    public function columns(array $columns): array {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'title') $new['tng_service_tags'] = 'TN Game Tags';
        }
        return $new;
    }

    public function column(string $column, int $post_id): void {
        if ($column !== 'tng_service_tags') return;
        $taxonomy = $this->taxonomy();
        if (!$taxonomy) { echo '—'; return; }

        $terms = wp_get_post_terms($post_id, $taxonomy);
        if (!$terms || is_wp_error($terms)) { echo '—'; return; }

        $managed = array_keys($this->service_terms());
        $badges = [];
        foreach ($terms as $term) {
            if (in_array($term->slug, $managed, true)) {
                $badges[] = '<span class="tng-service-mini-tag">' . esc_html($term->name) . '</span>';
            }
        }
        echo $badges ? implode(' ', $badges) : '—';
    }

    private function assign(array $post_ids, array $slugs, bool $replace): int {
        $taxonomy = $this->taxonomy();
        if (!$taxonomy) return 0;
        $available = $this->service_terms();
        $term_ids = [];
        foreach ($slugs as $slug) {
            if (isset($available[$slug])) $term_ids[] = (int)$available[$slug]['id'];
        }
        if (!$term_ids) return 0;

        $updated = 0;
        foreach (array_unique(array_map('absint', $post_ids)) as $post_id) {
            if (!$post_id || get_post_type($post_id) !== 'st_activity') continue;
            if (!current_user_can('edit_post', $post_id)) continue;

            $result = wp_set_object_terms($post_id, $term_ids, $taxonomy, !$replace);
            if (!is_wp_error($result)) $updated++;
        }
        return $updated;
    }

    public function apply(): void {
        if (!current_user_can('edit_posts')) wp_die('You are not allowed to update activities.');
        check_admin_referer('tng_service_tags_apply', 'tng_service_tags_nonce');

        $post_ids = isset($_POST['activity_ids']) ? array_map('absint', (array)$_POST['activity_ids']) : [];
        $slugs = isset($_POST['service_tags']) ? array_map('sanitize_key', (array)$_POST['service_tags']) : [];
        $mode = isset($_POST['tag_mode']) ? sanitize_key($_POST['tag_mode']) : 'add';

        $updated = $this->assign($post_ids, $slugs, $mode === 'replace');
        wp_safe_redirect(admin_url('admin.php?page=tng-service-tag-manager&updated=' . $updated));
        exit;
    }

    public function page(): void {
        if (!current_user_can('edit_posts')) return;

        $taxonomy = $this->taxonomy();
        $terms = $this->service_terms();
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $current = isset($_GET['current_tag']) ? sanitize_key(wp_unslash($_GET['current_tag'])) : '';
        $paged = max(1, absint($_GET['paged'] ?? 1));

        $args = [
            'post_type' => 'st_activity',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => 30,
            'paged' => $paged,
            'orderby' => 'title',
            'order' => 'ASC',
        ];
        if ($search) $args['s'] = $search;
        if ($current && isset($terms[$current])) {
            $args['tax_query'] = [[
                'taxonomy' => $taxonomy,
                'field' => 'slug',
                'terms' => [$current],
            ]];
        }
        $query = new WP_Query($args);
        ?>
        <div class="wrap tng-service-manager">
            <header class="tng-service-manager-header">
                <div>
                    <span>CONTENT ORGANIZATION</span>
                    <h1>Service Tag Manager</h1>
                    <p>Add multiple TN Game discovery tags to existing Traveler Activities without removing their current classifications.</p>
                </div>
                <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=st_activity')); ?>">Open Activity list</a>
            </header>

            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(absint($_GET['updated'])); ?> activities updated.</p></div>
            <?php endif; ?>

            <?php if (!$taxonomy): ?>
                <div class="notice notice-error"><p>Traveler's Activity Type taxonomy could not be found.</p></div>
            <?php else: ?>
                <section class="tng-service-help">
                    <article><strong>Trails only</strong><p>Use for every hiking route so it appears under the Trails discovery filter.</p></article>
                    <article><strong>Trails + Waterfalls</strong><p>Use both for a hiking route whose experience prominently includes one or more waterfalls.</p></article>
                    <article><strong>Additive by default</strong><p>Existing tags such as Hiking Trails remain attached unless you explicitly choose Replace.</p></article>
                </section>

                <form class="tng-service-filter" method="get">
                    <input type="hidden" name="page" value="tng-service-tag-manager">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search existing activities…">
                    <select name="current_tag">
                        <option value="">All current tags</option>
                        <?php foreach ($terms as $slug => $term): ?>
                            <option value="<?php echo esc_attr($slug); ?>" <?php selected($current, $slug); ?>><?php echo esc_html($term['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button">Filter</button>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="tng_service_tags_apply">
                    <?php wp_nonce_field('tng_service_tags_apply', 'tng_service_tags_nonce'); ?>

                    <section class="tng-service-actionbar">
                        <div class="tng-service-tag-options">
                            <?php foreach ($terms as $slug => $term): ?>
                                <label><input type="checkbox" name="service_tags[]" value="<?php echo esc_attr($slug); ?>"><span><?php echo esc_html($term['icon']); ?> <?php echo esc_html($term['label']); ?></span></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="tng-service-apply-controls">
                            <select name="tag_mode">
                                <option value="add">Add to existing tags</option>
                                <option value="replace">Replace all Activity Types</option>
                            </select>
                            <button type="submit" class="button button-primary">Apply to selected</button>
                        </div>
                    </section>

                    <div class="tng-service-table-wrap">
                        <table class="widefat striped tng-service-table">
                            <thead><tr>
                                <td class="check-column"><input type="checkbox" data-tng-check-all></td>
                                <th>Activity</th><th>Status</th><th>Current Activity Types</th><th>Destination</th><th></th>
                            </tr></thead>
                            <tbody>
                            <?php if (!$query->posts): ?>
                                <tr><td colspan="6"><div class="tng-service-empty">No matching activities found.</div></td></tr>
                            <?php else: foreach ($query->posts as $post):
                                $post_terms = wp_get_post_terms($post->ID, $taxonomy);
                                $destination_id = absint(get_post_meta($post->ID, '_tng_destination_id', true));
                            ?>
                                <tr>
                                    <th class="check-column"><input type="checkbox" name="activity_ids[]" value="<?php echo absint($post->ID); ?>" data-tng-row-check></th>
                                    <td class="column-primary"><strong><?php echo esc_html($post->post_title ?: '(Untitled)'); ?></strong></td>
                                    <td><span class="tng-status tng-status-<?php echo esc_attr($post->post_status); ?>"><?php echo esc_html(ucfirst($post->post_status)); ?></span></td>
                                    <td><div class="tng-current-tags">
                                        <?php if ($post_terms && !is_wp_error($post_terms)): foreach ($post_terms as $term): ?>
                                            <span><?php echo esc_html($term->name); ?></span>
                                        <?php endforeach; else: ?>—<?php endif; ?>
                                    </div></td>
                                    <td><?php echo $destination_id ? esc_html(get_the_title($destination_id)) : '—'; ?></td>
                                    <td><a class="button button-small" href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>">Edit</a></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>

                <?php
                $pages = max(1, (int)$query->max_num_pages);
                if ($pages > 1):
                    echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(paginate_links([
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'current' => $paged,
                        'total' => $pages,
                        'type' => 'plain',
                    ])) . '</div></div>';
                endif;
                ?>

                <script>
                document.addEventListener('DOMContentLoaded',function(){
                  const all=document.querySelector('[data-tng-check-all]');
                  if(!all)return;
                  all.addEventListener('change',()=>document.querySelectorAll('[data-tng-row-check]').forEach(box=>box.checked=all.checked));
                });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }
}
