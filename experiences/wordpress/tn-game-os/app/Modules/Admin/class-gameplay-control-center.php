<?php
namespace TNG_OS\Modules\Admin;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Gameplay_Control_Center implements Module_Interface {
    public const OPTION = 'tng_gameplay_settings';
    private const PAGE = 'tng-gameplay-control-center';
    private const PROFILE_KEY = '_tng_explorer_profile';
    private const FRIENDS_KEY = '_tng_explorer_friends';

    public function id(): string { return 'gameplay_control_center'; }

    public function register(Container $container): void {
        $container->set('gameplay_control_center', $this);
        add_action('admin_menu', [$this, 'menu'], 28);
        add_action('admin_post_tng_save_gameplay_controls', [$this, 'save']);
        add_action('admin_post_tng_repair_gameplay_integrity', [$this, 'repair']);
    }

    public function boot(Container $container): void {}

    public static function defaults(): array {
        return [
            'quest_finale' => 1,
            'quest_memory' => 1,
            'explorer_journal' => 1,
            'daily_missions' => 1,
            'weekly_expedition' => 1,
            'community_expedition' => 1,
            'explorer_leaderboard' => 1,
            'explorer_friends' => 1,
            'explorer_challenges' => 1,
            'adventure_rewards' => 1,
            'lazy_social' => 1,
            'community_cache_seconds' => 60,
            'leaderboard_limit' => 50,
            'activity_feed_limit' => 30,
        ];
    }

    public static function settings(): array {
        return wp_parse_args((array)get_option(self::OPTION, []), self::defaults());
    }

    public static function enabled(string $key): bool {
        $settings = self::settings();
        return !empty($settings[$key]);
    }

    public function menu(): void {
        add_submenu_page(
            'tn-game-os',
            'Gameplay Control Center',
            'Gameplay Controls',
            'manage_options',
            self::PAGE,
            [$this, 'page']
        );
    }

    public function page(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        $settings = self::settings();
        $audit = $this->audit();
        $notice = isset($_GET['tng_notice']) ? sanitize_text_field(wp_unslash($_GET['tng_notice'])) : '';
        $features = [
            'quest_finale' => ['Quest Finale', 'Completion celebration and final quest summary.'],
            'quest_memory' => ['Adventure Memory', 'Persistent recap shown after a completed quest.'],
            'explorer_journal' => ['Adventure Journal', 'Lifetime quest, checkpoint, and badge history.'],
            'daily_missions' => ['Daily Missions', 'Daily tasks and Adventure Token rewards.'],
            'weekly_expedition' => ['Weekly Expedition', 'Weekly Trail Point reward path.'],
            'community_expedition' => ['Community Expedition', 'Shared regional goals and rewards.'],
            'explorer_leaderboard' => ['Regional Leaderboard', 'XP, checkpoint, and quest rankings.'],
            'explorer_friends' => ['Friends & Activity', 'Friend network, activity feed, and reactions.'],
            'explorer_challenges' => ['Friend Challenges', 'Seven-day head-to-head competitions.'],
            'adventure_rewards' => ['Reward Vault', 'Token-funded cosmetic reward system.'],
        ];
        ?>
        <div class="wrap tng-gameplay-controls">
            <h1>Gameplay Control Center</h1>
            <p>Manage the Journey Engine, community systems, performance limits, and player-data integrity from one place.</p>
            <?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <style>
                .tng-gc-grid{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(320px,.8fr);gap:22px;max-width:1280px;margin-top:22px}.tng-gc-card{background:#fff;border:1px solid #dcdcde;border-radius:18px;padding:22px;box-shadow:0 10px 30px rgba(0,0,0,.04)}.tng-gc-card h2{margin-top:0}.tng-gc-features{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.tng-gc-feature{display:flex;gap:12px;padding:15px;border:1px solid #e1e3e8;border-radius:14px;background:#fafbfc}.tng-gc-feature input{margin-top:3px}.tng-gc-feature strong,.tng-gc-feature span{display:block}.tng-gc-feature span{margin-top:4px;color:#646970;font-size:13px;line-height:1.4}.tng-gc-fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-top:18px}.tng-gc-fields label{display:block;font-weight:700}.tng-gc-fields input{width:100%;margin-top:7px}.tng-gc-stat-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}.tng-gc-stat{padding:16px;border-radius:14px;background:#f4f1ff}.tng-gc-stat strong{display:block;font-size:28px;color:#6438b3}.tng-gc-stat span{color:#646970;font-size:12px;text-transform:uppercase;letter-spacing:.08em}.tng-gc-good{color:#118447}.tng-gc-warn{color:#b26200}.tng-gc-actions{display:flex;gap:10px;align-items:center;margin-top:20px}@media(max-width:900px){.tng-gc-grid{grid-template-columns:1fr}.tng-gc-features,.tng-gc-fields{grid-template-columns:1fr}}
            </style>

            <div class="tng-gc-grid">
                <form class="tng-gc-card" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <h2>Journey Engine features</h2>
                    <p>Disabled systems are not loaded on quest pages, reducing scripts, REST requests, and interface complexity.</p>
                    <?php wp_nonce_field('tng_save_gameplay_controls'); ?>
                    <input type="hidden" name="action" value="tng_save_gameplay_controls">
                    <div class="tng-gc-features">
                        <?php foreach ($features as $key => [$label, $description]): ?>
                            <label class="tng-gc-feature">
                                <input type="checkbox" name="settings[<?php echo esc_attr($key); ?>]" value="1" <?php checked(!empty($settings[$key])); ?>>
                                <span><strong><?php echo esc_html($label); ?></strong><span><?php echo esc_html($description); ?></span></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <h2 style="margin-top:24px">Performance limits</h2>
                    <label class="tng-gc-feature" style="margin-bottom:14px">
                        <input type="checkbox" name="settings[lazy_social]" value="1" <?php checked(!empty($settings['lazy_social'])); ?>>
                        <span><strong>Lazy-load social drawers</strong><span>Keep social interfaces dormant until a player opens them.</span></span>
                    </label>
                    <div class="tng-gc-fields">
                        <label>Community cache (seconds)<input type="number" min="15" max="3600" name="settings[community_cache_seconds]" value="<?php echo esc_attr((string)$settings['community_cache_seconds']); ?>"></label>
                        <label>Leaderboard players<input type="number" min="10" max="200" name="settings[leaderboard_limit]" value="<?php echo esc_attr((string)$settings['leaderboard_limit']); ?>"></label>
                        <label>Activity feed entries<input type="number" min="10" max="100" name="settings[activity_feed_limit]" value="<?php echo esc_attr((string)$settings['activity_feed_limit']); ?>"></label>
                    </div>
                    <div class="tng-gc-actions"><button class="button button-primary button-large">Save gameplay controls</button></div>
                </form>

                <aside class="tng-gc-card">
                    <h2>Player-data integrity</h2>
                    <p>The audit checks permanent Explorer records without changing quest test progress.</p>
                    <div class="tng-gc-stat-grid">
                        <div class="tng-gc-stat"><strong><?php echo number_format_i18n($audit['profiles']); ?></strong><span>Explorer profiles</span></div>
                        <div class="tng-gc-stat"><strong><?php echo number_format_i18n($audit['friendships']); ?></strong><span>Friend links</span></div>
                        <div class="tng-gc-stat"><strong class="<?php echo $audit['duplicate_checkpoints'] ? 'tng-gc-warn' : 'tng-gc-good'; ?>"><?php echo number_format_i18n($audit['duplicate_checkpoints']); ?></strong><span>Duplicate checkpoints</span></div>
                        <div class="tng-gc-stat"><strong class="<?php echo $audit['orphan_friends'] ? 'tng-gc-warn' : 'tng-gc-good'; ?>"><?php echo number_format_i18n($audit['orphan_friends']); ?></strong><span>Orphan friend links</span></div>
                    </div>
                    <p><strong>Status:</strong> <?php echo $audit['issues'] ? '<span class="tng-gc-warn">Repair recommended</span>' : '<span class="tng-gc-good">Healthy</span>'; ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Repair duplicate progression entries and invalid friend links?');">
                        <?php wp_nonce_field('tng_repair_gameplay_integrity'); ?>
                        <input type="hidden" name="action" value="tng_repair_gameplay_integrity">
                        <button class="button button-secondary">Repair integrity issues</button>
                    </form>
                    <p style="margin-top:16px;color:#646970">Repairs only deduplicate permanent IDs and remove references to deleted users. It does not erase XP, rewards, journal history, or valid friendships.</p>
                </aside>
            </div>
        </div>
        <?php
    }

    public function save(): void {
        $this->guard('tng_save_gameplay_controls');
        $input = (array)wp_unslash($_POST['settings'] ?? []);
        $clean = self::defaults();
        foreach (array_keys($clean) as $key) {
            if (in_array($key, ['community_cache_seconds', 'leaderboard_limit', 'activity_feed_limit'], true)) continue;
            $clean[$key] = !empty($input[$key]) ? 1 : 0;
        }
        $clean['community_cache_seconds'] = max(15, min(3600, absint($input['community_cache_seconds'] ?? 60)));
        $clean['leaderboard_limit'] = max(10, min(200, absint($input['leaderboard_limit'] ?? 50)));
        $clean['activity_feed_limit'] = max(10, min(100, absint($input['activity_feed_limit'] ?? 30)));
        update_option(self::OPTION, $clean, false);
        $this->redirect('Gameplay controls saved. Disabled systems will stop loading on new quest requests.');
    }

    public function repair(): void {
        $this->guard('tng_repair_gameplay_integrity');
        $users = get_users(['fields' => 'ids']);
        $valid = array_fill_keys(array_map('intval', $users), true);
        $duplicates = 0;
        $orphans = 0;
        foreach ($users as $user_id) {
            $profile = get_user_meta($user_id, self::PROFILE_KEY, true);
            if (is_array($profile)) {
                foreach (['completedCheckpoints', 'completedQuests', 'badges', 'activityDays'] as $field) {
                    $before = array_values((array)($profile[$field] ?? []));
                    $after = array_values(array_unique(array_filter(array_map('strval', $before), static fn(string $v): bool => $v !== '')));
                    $duplicates += max(0, count($before) - count($after));
                    $profile[$field] = $after;
                }
                update_user_meta($user_id, self::PROFILE_KEY, $profile);
            }
            $friends = array_values(array_unique(array_filter(array_map('absint', (array)get_user_meta($user_id, self::FRIENDS_KEY, true)))));
            $after = [];
            foreach ($friends as $friend_id) {
                if ($friend_id !== (int)$user_id && isset($valid[$friend_id])) $after[] = $friend_id; else $orphans++;
            }
            update_user_meta($user_id, self::FRIENDS_KEY, array_values($after));
        }
        $this->redirect(sprintf('Integrity repair complete: removed %d duplicate progression entries and %d invalid friend links.', $duplicates, $orphans));
    }

    private function audit(): array {
        $users = get_users(['fields' => 'ids']);
        $valid = array_fill_keys(array_map('intval', $users), true);
        $profiles = 0;
        $friendships = 0;
        $duplicates = 0;
        $orphans = 0;
        foreach ($users as $user_id) {
            $profile = get_user_meta($user_id, self::PROFILE_KEY, true);
            if (is_array($profile) && $profile) {
                $profiles++;
                foreach (['completedCheckpoints', 'completedQuests', 'badges', 'activityDays'] as $field) {
                    $items = array_values(array_filter(array_map('strval', (array)($profile[$field] ?? [])), static fn(string $v): bool => $v !== ''));
                    $duplicates += max(0, count($items) - count(array_unique($items)));
                }
            }
            $friends = array_values(array_unique(array_filter(array_map('absint', (array)get_user_meta($user_id, self::FRIENDS_KEY, true)))));
            $friendships += count($friends);
            foreach ($friends as $friend_id) if ($friend_id === (int)$user_id || !isset($valid[$friend_id])) $orphans++;
        }
        return [
            'profiles' => $profiles,
            'friendships' => (int)floor($friendships / 2),
            'duplicate_checkpoints' => $duplicates,
            'orphan_friends' => $orphans,
            'issues' => $duplicates + $orphans,
        ];
    }

    private function guard(string $action): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        check_admin_referer($action);
    }

    private function redirect(string $notice): void {
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'tng_notice' => $notice], admin_url('admin.php')));
        exit;
    }
}
