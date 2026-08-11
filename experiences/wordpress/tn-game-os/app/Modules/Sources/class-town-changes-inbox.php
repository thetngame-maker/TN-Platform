<?php
namespace TNG_OS\Modules\Sources;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Town_Changes_Inbox implements Module_Interface {
    private const HISTORY_OPTION = 'tng_town_scanner_history_v1';
    private const CANDIDATE_CPT = 'tng_local_candidate';
    private const NONCE = 'tng_town_changes_action';

    public function id(): string { return 'town_changes_inbox'; }

    public function register(Container $container): void {
        add_action('admin_menu', [$this, 'admin_menu'], 27);
        add_action('admin_post_tng_town_changes_bulk_add', [$this, 'bulk_add_action']);
        $container->set('town_changes_inbox', $this);
    }

    public function boot(Container $container): void {}

    public function admin_menu(): void {
        add_submenu_page(
            'tng-content-studio',
            'Changes Inbox',
            'Changes Inbox',
            'edit_posts',
            'tng-town-changes',
            [$this, 'render_page']
        );
    }

    private function history(): array {
        $history = get_option(self::HISTORY_OPTION, []);
        return is_array($history) ? $history : [];
    }

    private function save_history(array $history): void {
        update_option(self::HISTORY_OPTION, $history, false);
    }

    private function action_type(array $item): string {
        $change = (string)($item['change_status'] ?? '');
        $status = (string)($item['status'] ?? '');

        if ($change === 'possibly_closed') return 'possibly_closed';
        if ($change === 'missing') return 'missing';
        if ($change === 'changed') return 'changed';
        if ($change === 'returned') return 'returned';
        if ($change === 'new' && $status === 'new') return 'new';
        return '';
    }

    private function rows(): array {
        $rows = [];
        foreach ($this->history() as $town_key => $town_history) {
            if (!is_array($town_history)) continue;
            $town = sanitize_text_field((string)($town_history['town'] ?? $town_key));
            $updated = sanitize_text_field((string)($town_history['updated_at'] ?? ''));
            $snapshot = is_array($town_history['snapshot'] ?? null) ? $town_history['snapshot'] : [];

            foreach ($snapshot as $place_key => $item) {
                if (!is_array($item)) continue;
                $type = $this->action_type($item);
                if ($type === '') continue;
                $item['_inbox_type'] = $type;
                $item['_town'] = $town;
                $item['_town_key'] = (string)$town_key;
                $item['_place_key'] = (string)$place_key;
                $item['_updated_at'] = $updated;
                $rows[] = $item;
            }
        }

        $priority = ['possibly_closed'=>1,'missing'=>2,'changed'=>3,'returned'=>4,'new'=>5];
        usort($rows, static function(array $a, array $b) use ($priority): int {
            $pa = $priority[$a['_inbox_type'] ?? 'new'] ?? 99;
            $pb = $priority[$b['_inbox_type'] ?? 'new'] ?? 99;
            if ($pa !== $pb) return $pa <=> $pb;
            return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });
        return $rows;
    }

    private function label(string $type): string {
        return [
            'new' => 'New discovery',
            'changed' => 'Details changed',
            'returned' => 'Returned',
            'missing' => 'Not found this scan',
            'possibly_closed' => 'Possibly closed',
        ][$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    private function color(string $type): string {
        return [
            'new'=>'#008a20',
            'changed'=>'#b26200',
            'returned'=>'#2271b1',
            'missing'=>'#996800',
            'possibly_closed'=>'#b32d2e',
        ][$type] ?? '#50575e';
    }

    private function existing_candidate_id(array $item): int {
        $meta = ['relation'=>'OR'];
        if (!empty($item['place_id'])) $meta[] = ['key'=>'_tng_local_place_id','value'=>$item['place_id']];
        if (!empty($item['maps_url'])) $meta[] = ['key'=>'_tng_local_maps_url','value'=>$item['maps_url']];
        if (count($meta) === 1) return 0;
        $ids = get_posts([
            'post_type'=>self::CANDIDATE_CPT,
            'post_status'=>'any',
            'numberposts'=>1,
            'fields'=>'ids',
            'meta_query'=>$meta,
        ]);
        return $ids ? (int)$ids[0] : 0;
    }

    private function create_candidate(array $item, string $town): int {
        if (!empty($item['activity_id'])) return 0;
        $existing = $this->existing_candidate_id($item);
        if ($existing) return $existing;

        $id = wp_insert_post([
            'post_type'=>self::CANDIDATE_CPT,
            'post_status'=>'publish',
            'post_title'=>sanitize_text_field((string)($item['name'] ?? 'Google Maps Place')),
            'post_content'=>'',
        ], true);
        if (is_wp_error($id) || !$id) return 0;

        $meta = [
            '_tng_local_source'=>'google_maps_apify',
            '_tng_local_place_id'=>sanitize_text_field((string)($item['place_id'] ?? '')),
            '_tng_local_maps_url'=>esc_url_raw((string)($item['maps_url'] ?? '')),
            '_tng_local_address'=>sanitize_text_field((string)($item['address'] ?? '')),
            '_tng_local_phone'=>sanitize_text_field((string)($item['phone'] ?? '')),
            '_tng_local_website'=>esc_url_raw((string)($item['website'] ?? '')),
            '_tng_local_category'=>sanitize_text_field((string)($item['category'] ?? '')),
            '_tng_local_rating'=>sanitize_text_field((string)($item['rating'] ?? '')),
            '_tng_local_rating_count'=>sanitize_text_field((string)($item['rating_count'] ?? '')),
            '_tng_local_latitude'=>sanitize_text_field((string)($item['latitude'] ?? '')),
            '_tng_local_longitude'=>sanitize_text_field((string)($item['longitude'] ?? '')),
            '_tng_local_email'=>sanitize_email((string)($item['email'] ?? '')),
            '_tng_local_socials'=>is_array($item['socials'] ?? null) ? $item['socials'] : [],
            '_tng_local_photos'=>array_slice(array_values(array_filter((array)($item['photos'] ?? []))), 0, 10),
            '_tng_local_status'=>'review',
            '_tng_local_service'=>sanitize_key((string)($item['service'] ?? 'food')),
            '_tng_local_scan_town'=>sanitize_text_field($town),
            '_tng_local_discovered_at'=>current_time('mysql'),
        ];
        foreach ($meta as $key=>$value) update_post_meta((int)$id, $key, $value);
        return (int)$id;
    }

    public function bulk_add_action(): void {
        if (!current_user_can('edit_posts')) wp_die('You do not have permission to use Changes Inbox.');
        check_admin_referer(self::NONCE);

        $selected = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array)wp_unslash($_POST['selected_places'] ?? [])))));
        if (!$selected) {
            wp_safe_redirect(add_query_arg(['page'=>'tng-town-changes','tng_notice'=>rawurlencode('Select at least one new discovery.')], admin_url('admin.php')));
            exit;
        }

        $history = $this->history();
        $added = 0;
        $skipped = 0;
        foreach ($selected as $encoded) {
            $parts = explode('|', $encoded, 2);
            if (count($parts) !== 2) { $skipped++; continue; }
            [$town_key, $place_key] = $parts;
            if (empty($history[$town_key]['snapshot'][$place_key]) || !is_array($history[$town_key]['snapshot'][$place_key])) { $skipped++; continue; }
            $item = $history[$town_key]['snapshot'][$place_key];
            if (($this->action_type($item) !== 'new') || !empty($item['activity_id'])) { $skipped++; continue; }

            $candidate_id = $this->create_candidate($item, (string)($history[$town_key]['town'] ?? $town_key));
            if (!$candidate_id) { $skipped++; continue; }

            $history[$town_key]['snapshot'][$place_key]['candidate_id'] = $candidate_id;
            $history[$town_key]['snapshot'][$place_key]['status'] = 'discovery';
            $added++;
        }
        $this->save_history($history);

        $message = $added . ' place' . ($added === 1 ? '' : 's') . ' added to Local Discovery';
        if ($skipped) $message .= ' (' . $skipped . ' skipped)';
        $message .= '.';
        wp_safe_redirect(add_query_arg(['page'=>'tng-town-changes','tng_notice'=>rawurlencode($message)], admin_url('admin.php')));
        exit;
    }

    public function render_page(): void {
        if (!current_user_can('edit_posts')) return;

        $rows = $this->rows();
        $filter = sanitize_key((string)($_GET['change'] ?? 'all'));
        $town_filter = sanitize_text_field((string)($_GET['town'] ?? ''));
        $notice = sanitize_text_field(wp_unslash($_GET['tng_notice'] ?? ''));
        $allowed = ['all','new','changed','returned','missing','possibly_closed'];
        if (!in_array($filter, $allowed, true)) $filter = 'all';

        $counts = array_fill_keys(['new','changed','returned','missing','possibly_closed'], 0);
        $towns = [];
        foreach ($rows as $row) {
            $type = $row['_inbox_type'];
            if (isset($counts[$type])) $counts[$type]++;
            $towns[$row['_town']] = true;
        }
        ksort($towns);

        $visible = array_values(array_filter($rows, static function(array $row) use ($filter, $town_filter): bool {
            if ($filter !== 'all' && ($row['_inbox_type'] ?? '') !== $filter) return false;
            if ($town_filter !== '' && ($row['_town'] ?? '') !== $town_filter) return false;
            return true;
        }));
        ?>
        <div class="wrap">
            <h1>🔔 Changes Inbox</h1>
            <p>Actionable changes found by Town Scanner. New discoveries are limited to places not already in TN Game or Local Discovery.</p>
            <?php if ($notice): ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>

            <div style="display:flex;gap:10px;flex-wrap:wrap;margin:18px 0">
                <?php foreach ($counts as $type=>$count): ?>
                    <a href="<?php echo esc_url(add_query_arg(['page'=>'tng-town-changes','change'=>$type], admin_url('admin.php'))); ?>" style="text-decoration:none;background:#fff;border:1px solid #dcdcde;border-left:4px solid <?php echo esc_attr($this->color($type)); ?>;padding:12px 14px;color:#1d2327">
                        <strong><?php echo absint($count); ?></strong> <?php echo esc_html(strtolower($this->label($type))); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex;gap:10px;align-items:center;margin:16px 0">
                <input type="hidden" name="page" value="tng-town-changes">
                <select name="change">
                    <option value="all" <?php selected($filter,'all'); ?>>All actionable changes</option>
                    <?php foreach ($counts as $type=>$count): ?><option value="<?php echo esc_attr($type); ?>" <?php selected($filter,$type); ?>><?php echo esc_html($this->label($type).' ('.$count.')'); ?></option><?php endforeach; ?>
                </select>
                <select name="town">
                    <option value="">All towns</option>
                    <?php foreach (array_keys($towns) as $town): ?><option value="<?php echo esc_attr($town); ?>" <?php selected($town_filter,$town); ?>><?php echo esc_html($town); ?></option><?php endforeach; ?>
                </select>
                <button class="button">Filter</button>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-town-scanner')); ?>">Run Town Scanner</a>
            </form>

            <?php if (!$visible): ?>
                <div class="notice notice-info inline"><p>No actionable changes match this view.</p></div>
            <?php else: ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="tng_town_changes_bulk_add">
                    <?php wp_nonce_field(self::NONCE); ?>
                    <p>
                        <button type="button" class="button" onclick="document.querySelectorAll('.tng-change-new').forEach(function(c){c.checked=true;});">Select all new</button>
                        <?php submit_button('Add Selected to Discovery','primary','submit',false); ?>
                    </p>
                    <div style="overflow-x:auto">
                    <table class="widefat striped">
                        <thead><tr><th style="width:36px"></th><th>Place</th><th>Town</th><th>Change</th><th>What changed</th><th>TN Game</th><th>Last scan</th></tr></thead>
                        <tbody>
                        <?php foreach ($visible as $item): $type=$item['_inbox_type']; $selectable=($type==='new' && empty($item['activity_id']) && empty($item['candidate_id'])); ?>
                            <tr>
                                <td><?php if ($selectable): ?><input class="tng-change-new" type="checkbox" name="selected_places[]" value="<?php echo esc_attr($item['_town_key'].'|'.$item['_place_key']); ?>"><?php endif; ?></td>
                                <td>
                                    <strong><?php echo esc_html((string)($item['name'] ?? 'Unknown place')); ?></strong>
                                    <?php if (!empty($item['address'])): ?><br><small><?php echo esc_html((string)$item['address']); ?></small><?php endif; ?>
                                    <?php if (!empty($item['maps_url'])): ?><br><a target="_blank" rel="noopener" href="<?php echo esc_url((string)$item['maps_url']); ?>">Google Maps ↗</a><?php endif; ?>
                                </td>
                                <td><?php echo esc_html($item['_town']); ?></td>
                                <td><strong style="color:<?php echo esc_attr($this->color($type)); ?>"><?php echo esc_html($this->label($type)); ?></strong><?php if (!empty($item['miss_count'])): ?><br><small>Missed <?php echo absint($item['miss_count']); ?> scan<?php echo absint($item['miss_count'])===1?'':'s'; ?></small><?php endif; ?></td>
                                <td><?php echo !empty($item['changed_fields']) ? esc_html(implode(', ', array_map('sanitize_text_field', (array)$item['changed_fields']))) : '—'; ?></td>
                                <td>
                                    <?php if (!empty($item['activity_id'])): ?>Already in TN Game<br><a href="<?php echo esc_url(get_edit_post_link((int)$item['activity_id'])); ?>">Open listing ↗</a>
                                    <?php elseif (!empty($item['candidate_id'])): ?>In Local Discovery
                                    <?php else: ?><strong style="color:#008a20">Not added yet</strong><br><a href="<?php echo esc_url(admin_url('admin.php?page=tng-town-scanner')); ?>">Review in Town Scanner →</a><?php endif; ?>
                                </td>
                                <td><?php echo esc_html($item['_updated_at'] ?: '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <p style="margin-top:15px"><?php submit_button('Add Selected to Discovery','primary','submit',false); ?></p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
}
