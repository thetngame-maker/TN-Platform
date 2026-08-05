<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Explorer_Journal implements Module_Interface {
    private const META_RECAPS = '_tng_trip_recaps';

    public function id(): string { return 'explorer_journal'; }

    public function register(Container $container): void {
        $container->set('explorer_journal', $this);
        add_shortcode('tng_explorer_journal', [$this, 'shortcode']);
        add_action('admin_menu', [$this, 'admin_menu'], 82);
    }

    public function boot(Container $container): void {}

    public function admin_menu(): void {
        add_submenu_page(
            'tn-game-os',
            'Explorer Journal',
            'Explorer Journal',
            'manage_options',
            'tng-os-explorer-journal',
            [$this, 'admin_page']
        );
    }

    public function admin_page(): void {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap"><h1>Explorer Journal</h1>';
        echo '<p>Create a public WordPress page named <strong>Explorer Journal</strong> and add:</p>';
        echo '<p><code>[tng_explorer_journal]</code></p>';
        echo '<p>This unified timeline combines completed trips, checkpoints, quests, badges, and future photo memories.</p></div>';
    }

    public function shortcode($atts = []): string {
        $atts = shortcode_atts(['title' => 'Explorer Journal'], $atts, 'tng_explorer_journal');
        if (!is_user_logged_in()) {
            return '<section class="tng-journal-shell"><div class="tng-journal-empty"><h2>Explorer Journal</h2><p>Sign in to view your Tennessee adventure story.</p></div></section>';
        }

        $user_id = get_current_user_id();
        $events = apply_filters('tng_os_adventure_journal_events', [], $user_id);
        $events = is_array($events) ? $events : [];
        $events = $this->normalize_events($events);
        $recaps = get_user_meta($user_id, self::META_RECAPS, true);
        $recaps = is_array($recaps) ? $recaps : [];

        $trips = [];
        $activities = [];
        $achievements = [];
        $photos = [];

        foreach ($events as $event) {
            $type = sanitize_key($event['type'] ?? 'activity');
            if ($type === 'trip_completed' || str_contains($type, 'trip')) $trips[] = $event;
            elseif (str_contains($type, 'badge') || str_contains($type, 'achievement') || str_contains($type, 'rank')) $achievements[] = $event;
            elseif (str_contains($type, 'photo') || str_contains($type, 'image')) $photos[] = $event;
            else $activities[] = $event;
        }

        // Ensure saved trip recaps remain visible even if another module's filter is unavailable.
        foreach ($recaps as $recap) {
            if (!is_array($recap)) continue;
            $key = 'trip:' . sanitize_text_field($recap['id'] ?? '');
            $exists = false;
            foreach ($trips as $trip) if (($trip['id'] ?? '') === $key) { $exists = true; break; }
            if ($exists) continue;
            $trips[] = [
                'id' => $key,
                'type' => 'trip_completed',
                'title' => sanitize_text_field($recap['title'] ?? 'My Tennessee adventure'),
                'description' => sprintf('%d stops · %s', absint($recap['stop_count'] ?? 0), $this->duration(absint($recap['minutes'] ?? 0))),
                'date' => sanitize_text_field($recap['date'] ?? ''),
                'meta' => $recap,
            ];
        }

        $all = array_merge($trips, $activities, $achievements, $photos);
        usort($all, [$this, 'sort_events']);
        usort($trips, [$this, 'sort_events']);
        usort($activities, [$this, 'sort_events']);
        usort($achievements, [$this, 'sort_events']);
        usort($photos, [$this, 'sort_events']);

        $stats = apply_filters('tng_os_explorer_profile_stats', [], $user_id);
        $stats = is_array($stats) ? $stats : [];
        $trip_count = max(count($trips), absint($stats['completed_trips'] ?? 0));
        $checkpoint_count = absint($stats['checkpoints'] ?? $stats['trip_stops'] ?? 0);
        $xp = absint($stats['xp'] ?? $stats['total_xp'] ?? 0);
        $badge_count = count($achievements);

        ob_start();
        ?>
        <section class="tng-journal-shell" data-tng-journal>
            <style>
                .tng-journal-shell{max-width:1080px;margin:32px auto;font-family:inherit;color:#17213d}
                .tng-journal-hero{padding:34px;border-radius:28px;background:linear-gradient(135deg,#19254c,#7642a2);color:#fff;box-shadow:0 18px 45px rgba(30,34,70,.16)}
                .tng-journal-kicker{font-size:12px;font-weight:800;letter-spacing:.18em;text-transform:uppercase;color:#ffd447}
                .tng-journal-hero h1{font-size:42px;line-height:1.05;margin:12px 0 8px;color:#fff}
                .tng-journal-hero p{margin:0;color:rgba(255,255,255,.82);font-size:16px}
                .tng-journal-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:18px 0}
                .tng-journal-stat{background:#fff;border:1px solid #e3e6f1;border-radius:18px;padding:18px;text-align:center}
                .tng-journal-stat strong{display:block;font-size:28px;color:#6c3fc2}
                .tng-journal-stat span{font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#6f7890}
                .tng-journal-tabs{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin:18px 0}
                .tng-journal-tab{border:1px solid #dfe3ee;background:#fff;color:#39435f;padding:13px 10px;border-radius:14px;font-weight:800;cursor:pointer}
                .tng-journal-tab.is-active{border-color:#8b56eb;background:#f0e8ff;color:#6a38ba}
                .tng-journal-panel{display:none}.tng-journal-panel.is-active{display:block}
                .tng-journal-year{font-size:26px;margin:28px 0 12px}
                .tng-journal-card{display:grid;grid-template-columns:54px 1fr auto;gap:16px;align-items:start;padding:20px;margin-bottom:12px;background:#fff;border:1px solid #e1e5ef;border-radius:18px}
                .tng-journal-icon{width:50px;height:50px;border-radius:16px;background:#eee7ff;color:#6e3dcc;display:flex;align-items:center;justify-content:center;font-size:23px;font-weight:900}
                .tng-journal-card h3{margin:0 0 5px;font-size:19px;color:#17213d}.tng-journal-card p{margin:0;color:#687189}
                .tng-journal-date{white-space:nowrap;color:#8790a5;font-size:13px}
                .tng-journal-meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
                .tng-journal-chip{padding:7px 10px;border-radius:999px;background:#f0e8ff;color:#683ab8;font-size:12px;font-weight:800}
                .tng-journal-empty{padding:36px;text-align:center;background:#fff;border:1px dashed #ccd2e0;border-radius:20px;color:#737c91}
                @media(max-width:720px){.tng-journal-shell{margin:16px}.tng-journal-hero{padding:26px}.tng-journal-hero h1{font-size:34px}.tng-journal-stats{grid-template-columns:repeat(2,1fr)}.tng-journal-tabs{grid-template-columns:1fr 1fr}.tng-journal-tab:first-child{grid-column:1/-1}.tng-journal-card{grid-template-columns:44px 1fr}.tng-journal-icon{width:42px;height:42px}.tng-journal-date{grid-column:2}}
            </style>
            <div class="tng-journal-hero">
                <div class="tng-journal-kicker">TN Game Explorer</div>
                <h1><?php echo esc_html($atts['title']); ?></h1>
                <p>Every real-world discovery becomes part of your Tennessee story.</p>
            </div>
            <div class="tng-journal-stats">
                <?php echo $this->stat($trip_count, 'Trips'); ?>
                <?php echo $this->stat($checkpoint_count, 'Places visited'); ?>
                <?php echo $this->stat($xp, 'XP'); ?>
                <?php echo $this->stat($badge_count, 'Achievements'); ?>
            </div>
            <div class="tng-journal-tabs" role="tablist">
                <?php foreach (['all'=>'All','trips'=>'Trips','activities'=>'Activities','achievements'=>'Achievements','photos'=>'Photos'] as $key=>$label): ?>
                    <button type="button" class="tng-journal-tab<?php echo $key === 'all' ? ' is-active' : ''; ?>" data-journal-tab="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></button>
                <?php endforeach; ?>
            </div>
            <?php
            $groups = ['all'=>$all,'trips'=>$trips,'activities'=>$activities,'achievements'=>$achievements,'photos'=>$photos];
            foreach ($groups as $key => $items): ?>
                <div class="tng-journal-panel<?php echo $key === 'all' ? ' is-active' : ''; ?>" data-journal-panel="<?php echo esc_attr($key); ?>">
                    <?php echo $this->render_events($items, $key); ?>
                </div>
            <?php endforeach; ?>
            <script>
                (function(){
                    var root=document.currentScript.closest('[data-tng-journal]'); if(!root)return;
                    root.querySelectorAll('[data-journal-tab]').forEach(function(btn){btn.addEventListener('click',function(){
                        root.querySelectorAll('[data-journal-tab]').forEach(function(x){x.classList.toggle('is-active',x===btn)});
                        root.querySelectorAll('[data-journal-panel]').forEach(function(x){x.classList.toggle('is-active',x.getAttribute('data-journal-panel')===btn.getAttribute('data-journal-tab'))});
                    })});
                })();
            </script>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private function normalize_events(array $events): array {
        $out = [];
        foreach ($events as $event) {
            if (!is_array($event)) continue;
            $event['id'] = sanitize_text_field($event['id'] ?? wp_generate_uuid4());
            $event['type'] = sanitize_key($event['type'] ?? 'activity');
            $event['title'] = sanitize_text_field($event['title'] ?? 'Explorer activity');
            $event['description'] = sanitize_text_field($event['description'] ?? $event['message'] ?? '');
            $event['date'] = sanitize_text_field($event['date'] ?? '');
            $event['meta'] = is_array($event['meta'] ?? null) ? $event['meta'] : [];
            $out[] = $event;
        }
        return $out;
    }

    private function render_events(array $items, string $group): string {
        if (!$items) {
            $messages = [
                'trips' => 'Completed itineraries will appear here.',
                'activities' => 'Checkpoint claims, quests, and discoveries will appear here.',
                'achievements' => 'Badges, ranks, and milestones will appear here.',
                'photos' => 'Approved Explorer photos will become part of your story here.',
                'all' => 'Start exploring to create your first journal memory.',
            ];
            return '<div class="tng-journal-empty">' . esc_html($messages[$group] ?? $messages['all']) . '</div>';
        }
        $html = '';
        $last_year = '';
        foreach ($items as $event) {
            $date = $event['date'] ?? '';
            $ts = $date ? strtotime($date) : 0;
            $year = $ts ? wp_date('Y', $ts) : wp_date('Y');
            if ($year !== $last_year) {
                $html .= '<h2 class="tng-journal-year">' . esc_html($year) . '</h2>';
                $last_year = $year;
            }
            $type = sanitize_key($event['type'] ?? 'activity');
            $icon = $this->icon($type);
            $meta = is_array($event['meta'] ?? null) ? $event['meta'] : [];
            $html .= '<article class="tng-journal-card">';
            $html .= '<div class="tng-journal-icon">' . esc_html($icon) . '</div>';
            $html .= '<div><h3>' . esc_html($event['title'] ?? 'Explorer activity') . '</h3><p>' . esc_html($event['description'] ?? '') . '</p>';
            $chips = [];
            if (!empty($meta['stop_count'])) $chips[] = absint($meta['stop_count']) . ' stops';
            if (!empty($meta['minutes'])) $chips[] = $this->duration(absint($meta['minutes']));
            if (!empty($meta['streak'])) $chips[] = absint($meta['streak']) . ' day streak';
            if (!empty($meta['badge'])) $chips[] = '★ ' . sanitize_text_field($meta['badge']);
            if ($chips) {
                $html .= '<div class="tng-journal-meta">';
                foreach ($chips as $chip) $html .= '<span class="tng-journal-chip">' . esc_html($chip) . '</span>';
                $html .= '</div>';
            }
            $html .= '</div><time class="tng-journal-date">' . esc_html($ts ? wp_date(get_option('date_format'), $ts) : '') . '</time></article>';
        }
        return $html;
    }

    private function stat(int $value, string $label): string {
        return '<div class="tng-journal-stat"><strong>' . number_format_i18n($value) . '</strong><span>' . esc_html($label) . '</span></div>';
    }

    private function icon(string $type): string {
        if (str_contains($type, 'trip')) return '✓';
        if (str_contains($type, 'badge') || str_contains($type, 'achievement')) return '★';
        if (str_contains($type, 'photo')) return '▣';
        if (str_contains($type, 'checkpoint')) return '◇';
        if (str_contains($type, 'quest')) return '✓';
        return '•';
    }

    public function sort_events(array $a, array $b): int {
        return (strtotime($b['date'] ?? '') ?: 0) <=> (strtotime($a['date'] ?? '') ?: 0);
    }

    private function duration(int $minutes): string {
        if ($minutes < 60) return $minutes . ' min';
        return (round($minutes / 6) / 10) . ' hr';
    }
}
