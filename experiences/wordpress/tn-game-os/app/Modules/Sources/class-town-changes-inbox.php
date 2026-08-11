<?php
namespace TNG_OS\Modules\Sources;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Town_Changes_Inbox implements Module_Interface {
    private const HISTORY_OPTION = 'tng_town_scanner_history_v1';

    public function id(): string { return 'town_changes_inbox'; }

    public function register(Container $container): void {
        add_action('admin_menu', [$this, 'admin_menu'], 27);
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

    public function render_page(): void {
        if (!current_user_can('edit_posts')) return;

        $rows = $this->rows();
        $filter = sanitize_key((string)($_GET['change'] ?? 'all'));
        $town_filter = sanitize_text_field((string)($_GET['town'] ?? ''));
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
                <div style="overflow-x:auto">
                <table class="widefat striped">
                    <thead><tr><th>Place</th><th>Town</th><th>Change</th><th>What changed</th><th>TN Game</th><th>Last scan</th></tr></thead>
                    <tbody>
                    <?php foreach ($visible as $item): $type=$item['_inbox_type']; ?>
                        <tr>
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
            <?php endif; ?>
        </div>
        <?php
    }
}
