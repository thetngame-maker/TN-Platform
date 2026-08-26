<?php
namespace TNG_OS\Modules\Admin;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Cleanup_Audit implements Module_Interface {
    private const BACKUP_OPTION = 'tng_cleanup_audit_last_backup';
    private const NOTICE_OPTION = 'tng_cleanup_audit_notice';

    /** Exact labels/slugs that belong to the old Traveler demo shell. */
    private const DEMO_LABELS = [
        'help center',
        'covid-19 response',
        'cancellation options',
        'careers',
        'traveler',
    ];

    /** Strings that should never be present in the public TN Game shell. */
    private const DEMO_STRINGS = [
        '© Copyright Traveler 2022',
        '&copy; Copyright Traveler 2022',
        'Copyright Traveler 2022',
    ];

    public function id(): string { return 'cleanup_audit'; }

    public function register(Container $container): void {
        $container->set('cleanup_audit', $this);
        add_action('admin_menu', [$this, 'menu'], 31);
        add_action('admin_post_tng_cleanup_audit_run', [$this, 'handle_cleanup']);
        add_action('admin_post_tng_cleanup_audit_restore', [$this, 'handle_restore']);
        add_action('admin_notices', [$this, 'notice']);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page(
            'tn-game-os',
            'Cleanup / Audit',
            'Cleanup / Audit',
            'manage_options',
            'tng-cleanup-audit',
            [$this, 'render']
        );
    }

    public function render(): void {
        if (!current_user_can('manage_options')) return;
        $report = $this->scan();
        $score = max(0, 100 - min(100, $report['total_findings'] * 8));
        $backup = get_option(self::BACKUP_OPTION, []);
        ?>
        <div class="wrap">
            <h1>TN Game Cleanup / Audit Engine</h1>
            <p>Find and safely remove remaining Traveler demo content without touching TN Game content. Cleanup actions create a backup snapshot first and move matching posts to Trash instead of permanently deleting them.</p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;max-width:1180px;margin:20px 0;">
                <?php $cards = [
                    ['Platform cleanliness', $score . '%'],
                    ['Total findings', $report['total_findings']],
                    ['Demo posts/pages', count($report['posts'])],
                    ['Demo menu items', count($report['menu_items'])],
                    ['Legacy Traveler options', count($report['options'])],
                    ['Traveler inventory', $report['traveler_inventory']],
                ]; ?>
                <?php foreach ($cards as [$label, $value]): ?>
                    <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:18px;">
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#646970;font-weight:700;"><?php echo esc_html($label); ?></div>
                        <div style="font-size:30px;font-weight:750;margin-top:8px;"><?php echo esc_html((string)$value); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);gap:18px;max-width:1180px;align-items:start;">
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:22px;">
                    <h2 style="margin-top:0;">Findings</h2>
                    <?php if ($report['total_findings'] === 0): ?>
                        <div style="padding:22px;border-radius:10px;background:#edf7ef;color:#14532d;font-weight:700;">No obvious Traveler demo artifacts were detected.</div>
                    <?php else: ?>
                        <table class="widefat striped">
                            <thead><tr><th>Type</th><th>Item</th><th>Why it was flagged</th></tr></thead>
                            <tbody>
                                <?php foreach ($report['posts'] as $item): ?>
                                    <tr><td>Post/Page</td><td><a href="<?php echo esc_url(get_edit_post_link($item['id'])); ?>"><?php echo esc_html($item['title']); ?></a></td><td><?php echo esc_html($item['reason']); ?></td></tr>
                                <?php endforeach; ?>
                                <?php foreach ($report['menu_items'] as $item): ?>
                                    <tr><td>Menu</td><td><?php echo esc_html($item['title']); ?></td><td>Known Traveler demo navigation label</td></tr>
                                <?php endforeach; ?>
                                <?php foreach ($report['options'] as $item): ?>
                                    <tr><td>Theme option</td><td><?php echo esc_html($item['option']); ?></td><td>Contains Traveler 2022 copyright text</td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <h3 style="margin-top:24px;">Traveler inventory watch</h3>
                    <p><?php echo esc_html((string)$report['traveler_inventory']); ?> legacy Traveler hotel/tour/rental/car records are still present. They are reported separately because TN Game may intentionally reuse those post types for Tennessee inventory. The safe cleanup button does not trash them automatically.</p>
                </div>

                <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:22px;position:sticky;top:46px;">
                    <h2 style="margin-top:0;">Safe cleanup</h2>
                    <p>This will:</p>
                    <ul style="list-style:disc;padding-left:20px;">
                        <li>move exact Traveler demo pages/posts to Trash,</li>
                        <li>disable exact Traveler demo menu items,</li>
                        <li>strip the old Traveler 2022 copyright from known theme-option arrays,</li>
                        <li>save a backup snapshot before changing anything.</li>
                    </ul>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="tng_cleanup_audit_run">
                        <?php wp_nonce_field('tng_cleanup_audit_run'); ?>
                        <button class="button button-primary button-hero" type="submit" <?php disabled($report['safe_findings'], 0); ?>>Run safe cleanup</button>
                    </form>
                    <p style="margin-bottom:0;color:#646970;font-size:12px;">Safe findings ready: <?php echo esc_html((string)$report['safe_findings']); ?></p>
                    <?php if (is_array($backup) && !empty($backup['created_at'])): ?>
                        <hr style="margin:20px 0;">
                        <h3>Latest backup</h3>
                        <p>Created <?php echo esc_html((string)$backup['created_at']); ?>. Restore the posts, menu items, and theme options changed by that cleanup run.</p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="tng_cleanup_audit_restore">
                            <?php wp_nonce_field('tng_cleanup_audit_restore'); ?>
                            <button class="button" type="submit">Restore latest cleanup</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function handle_cleanup(): void {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('tng_cleanup_audit_run');

        $report = $this->scan();
        $backup = [
            'created_at' => current_time('mysql'),
            'posts' => array_map(static function (array $item): array {
                $item['status'] = (string)get_post_status($item['id']);
                return $item;
            }, $report['posts']),
            'menu_items' => array_map(static function (array $item): array {
                $item['status'] = (string)get_post_status($item['id']);
                return $item;
            }, $report['menu_items']),
            'options' => [],
        ];

        foreach ($report['options'] as $item) {
            $backup['options'][$item['option']] = get_option($item['option']);
        }
        update_option(self::BACKUP_OPTION, $backup, false);

        $changed = 0;
        foreach ($report['posts'] as $item) {
            if (get_post_status($item['id']) !== 'trash' && wp_trash_post($item['id'])) $changed++;
        }
        foreach ($report['menu_items'] as $item) {
            $result = wp_update_post(['ID' => (int)$item['id'], 'post_status' => 'draft'], true);
            if (!is_wp_error($result) && $result) $changed++;
        }
        foreach ($report['options'] as $item) {
            $value = get_option($item['option']);
            $cleaned = $this->strip_demo_strings($value);
            if ($cleaned !== $value) {
                update_option($item['option'], $cleaned);
                $changed++;
            }
        }

        update_option(self::NOTICE_OPTION, sprintf('Cleanup complete. %d safe Traveler/demo artifacts were changed. A backup snapshot was saved.', $changed), false);
        wp_safe_redirect(admin_url('admin.php?page=tng-cleanup-audit'));
        exit;
    }

    public function handle_restore(): void {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('tng_cleanup_audit_restore');

        $backup = get_option(self::BACKUP_OPTION, []);
        if (!is_array($backup) || empty($backup['created_at'])) {
            update_option(self::NOTICE_OPTION, 'No cleanup backup was available to restore.', false);
            wp_safe_redirect(admin_url('admin.php?page=tng-cleanup-audit'));
            exit;
        }

        $restored = 0;
        foreach ((array)($backup['posts'] ?? []) as $item) {
            $id = absint($item['id'] ?? 0);
            if (!$id || !get_post($id)) continue;
            if (get_post_status($id) === 'trash') wp_untrash_post($id);
            $status = sanitize_key((string)($item['status'] ?? 'draft')) ?: 'draft';
            $result = wp_update_post(['ID' => $id, 'post_status' => $status], true);
            if (!is_wp_error($result) && $result) $restored++;
        }
        foreach ((array)($backup['menu_items'] ?? []) as $item) {
            $id = absint($item['id'] ?? 0);
            if (!$id || !get_post($id)) continue;
            $status = sanitize_key((string)($item['status'] ?? 'publish')) ?: 'publish';
            $result = wp_update_post(['ID' => $id, 'post_status' => $status], true);
            if (!is_wp_error($result) && $result) $restored++;
        }
        foreach ((array)($backup['options'] ?? []) as $option_name => $value) {
            if (update_option(sanitize_key((string)$option_name), $value)) $restored++;
        }

        $backup['restored_at'] = current_time('mysql');
        update_option(self::BACKUP_OPTION, $backup, false);
        update_option(self::NOTICE_OPTION, sprintf('Cleanup backup restored. %d items were returned to their previous state.', $restored), false);
        wp_safe_redirect(admin_url('admin.php?page=tng-cleanup-audit'));
        exit;
    }

    public function notice(): void {
        if (!current_user_can('manage_options')) return;
        $notice = (string)get_option(self::NOTICE_OPTION, '');
        if ($notice === '') return;
        delete_option(self::NOTICE_OPTION);
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
    }

    private function scan(): array {
        $posts = [];
        $menu_items = [];
        $options = [];

        $query = new \WP_Query([
            'post_type' => 'any',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        foreach ($query->posts as $post_id) {
            $post = get_post($post_id);
            if (!$post || $post->post_type === 'nav_menu_item') continue;
            $title = strtolower(trim(wp_strip_all_tags($post->post_title)));
            $slug = strtolower(trim((string)$post->post_name));
            $reason = '';
            if (in_array($title, self::DEMO_LABELS, true)) $reason = 'Known Traveler demo title';
            elseif (in_array(str_replace('-', ' ', $slug), self::DEMO_LABELS, true)) $reason = 'Known Traveler demo slug';
            elseif ($this->contains_demo_string($post->post_content)) $reason = 'Contains Traveler 2022 copyright text';
            if ($reason !== '') {
                $posts[] = ['id' => $post_id, 'title' => $post->post_title ?: '(untitled)', 'reason' => $reason];
            }
        }

        foreach (wp_get_nav_menus() as $menu) {
            foreach (wp_get_nav_menu_items($menu->term_id) ?: [] as $item) {
                $label = strtolower(trim(wp_strip_all_tags($item->title)));
                if (in_array($label, self::DEMO_LABELS, true)) {
                    $menu_items[] = ['id' => $item->ID, 'title' => $item->title, 'menu' => $menu->name];
                }
            }
        }

        foreach ($this->candidate_options() as $option_name) {
            $value = get_option($option_name, null);
            if ($value !== null && $this->contains_demo_string($value)) {
                $options[] = ['option' => $option_name];
            }
        }

        $traveler_inventory = 0;
        foreach (['st_hotel', 'st_tours', 'st_rental', 'st_cars'] as $post_type) {
            if (!post_type_exists($post_type)) continue;
            $counts = wp_count_posts($post_type);
            foreach (['publish', 'draft', 'pending', 'private'] as $status) {
                $traveler_inventory += isset($counts->{$status}) ? (int)$counts->{$status} : 0;
            }
        }

        return [
            'posts' => $posts,
            'menu_items' => $menu_items,
            'options' => $options,
            'traveler_inventory' => $traveler_inventory,
            'safe_findings' => count($posts) + count($menu_items) + count($options),
            'total_findings' => count($posts) + count($menu_items) + count($options) + ($traveler_inventory > 0 ? 1 : 0),
        ];
    }

    private function candidate_options(): array {
        $theme = wp_get_theme();
        return array_values(array_unique([
            'theme_mods_' . $theme->get_stylesheet(),
            'theme_mods_' . $theme->get_template(),
            'st_options',
            'option_tree',
            'traveler_options',
        ]));
    }

    private function contains_demo_string($value): bool {
        if (is_array($value) || is_object($value)) {
            foreach ((array)$value as $child) if ($this->contains_demo_string($child)) return true;
            return false;
        }
        if (!is_string($value)) return false;
        foreach (self::DEMO_STRINGS as $needle) if (stripos($value, $needle) !== false) return true;
        return false;
    }

    private function strip_demo_strings($value) {
        if (is_array($value)) {
            foreach ($value as $key => $child) $value[$key] = $this->strip_demo_strings($child);
            return $value;
        }
        if (is_object($value)) {
            foreach ($value as $key => $child) $value->{$key} = $this->strip_demo_strings($child);
            return $value;
        }
        if (!is_string($value)) return $value;
        return str_ireplace(self::DEMO_STRINGS, '', $value);
    }
}
