<?php
namespace TNG_OS\Modules\Admin;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use TNG_OS\Modules\Frontend\Adventure_Rewards;
use WP_REST_Request;

if (!defined('ABSPATH')) exit;

final class Explorer_Test_Lab implements Module_Interface {
    private const PAGE = 'tng-explorer-test-lab';
    private const TEST_META = '_tng_test_player';
    private const PROFILE_META = '_tng_explorer_profile';
    private const WALLET_META = '_tng_daily_missions';
    private const REWARDS_META = '_tng_adventure_rewards';

    public function id(): string { return 'explorer_test_lab'; }

    public function register(Container $container): void {
        $container->set('explorer_test_lab', $this);
        add_action('admin_menu', [$this, 'menu'], 30);
        add_action('admin_post_tng_seed_test_players', [$this, 'seed_players']);
        add_action('admin_post_tng_delete_test_players', [$this, 'delete_players']);
        add_action('admin_post_tng_grant_test_tokens', [$this, 'grant_tokens']);
        add_action('admin_post_tng_verify_reward_system', [$this, 'verify_rewards']);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page(
            'tn-game-os',
            'Explorer Test Lab',
            'Explorer Test Lab',
            'manage_options',
            self::PAGE,
            [$this, 'page']
        );
    }

    public function page(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        $test_users = get_users(['meta_key' => self::TEST_META, 'meta_value' => '1', 'fields' => ['ID', 'display_name', 'user_login']]);
        $wallet = get_user_meta(get_current_user_id(), self::WALLET_META, true);
        $rewards = get_user_meta(get_current_user_id(), self::REWARDS_META, true);
        $tokens = is_array($wallet) ? absint($wallet['tokens'] ?? 0) : 0;
        $unlocked = is_array($rewards) ? array_map('sanitize_key', (array)($rewards['unlocked'] ?? [])) : [];
        $equipped = is_array($rewards) ? sanitize_key((string)($rewards['equipped'] ?? '')) : '';
        $notice = isset($_GET['tng_notice']) ? sanitize_text_field(wp_unslash($_GET['tng_notice'])) : '';
        ?>
        <div class="wrap">
            <h1>Explorer Test Lab</h1>
            <p>Create clearly marked test Explorers, populate community systems, and verify the Adventure Reward transaction path without risking real player data.</p>
            <?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>

            <style>
                .tng-test-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px;max-width:1180px;margin-top:22px}.tng-test-card{background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:22px;box-shadow:0 8px 24px rgba(0,0,0,.04)}.tng-test-card h2{margin-top:0}.tng-test-card .button{margin:4px 8px 4px 0}.tng-test-stat{font-size:32px;font-weight:800;color:#5b35a5}.tng-test-good{color:#118447;font-weight:700}.tng-test-list{max-height:260px;overflow:auto;background:#f6f7f7;border-radius:10px;padding:10px 14px}.tng-test-warning{border-left:4px solid #dba617;padding-left:12px}
            </style>

            <div class="tng-test-grid">
                <section class="tng-test-card">
                    <h2>Fake Explorer Population</h2>
                    <div class="tng-test-stat"><?php echo count($test_users); ?></div>
                    <p>Test players currently contributing to leaderboards, community goals, and social testing.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('tng_seed_test_players'); ?>
                        <input type="hidden" name="action" value="tng_seed_test_players">
                        <label>Players to create <input type="number" name="count" value="12" min="1" max="40" style="width:80px"></label><br><br>
                        <button class="button button-primary">Generate / refresh players</button>
                    </form>
                    <?php if ($test_users): ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Delete every generated TN Game test player?');">
                            <?php wp_nonce_field('tng_delete_test_players'); ?>
                            <input type="hidden" name="action" value="tng_delete_test_players">
                            <button class="button">Delete fake players</button>
                        </form>
                        <div class="tng-test-list">
                            <?php foreach ($test_users as $user): ?><div><strong><?php echo esc_html($user->display_name); ?></strong> <code><?php echo esc_html($user->user_login); ?></code></div><?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="tng-test-card">
                    <h2>Reward Wallet</h2>
                    <div class="tng-test-stat"><?php echo number_format_i18n($tokens); ?> Tokens</div>
                    <p>Unlocked: <strong><?php echo esc_html($unlocked ? implode(', ', $unlocked) : 'None'); ?></strong><br>Equipped: <strong><?php echo esc_html($equipped ?: 'None'); ?></strong></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('tng_grant_test_tokens'); ?>
                        <input type="hidden" name="action" value="tng_grant_test_tokens">
                        <input type="number" name="tokens" value="250" min="1" max="5000" style="width:90px">
                        <button class="button button-primary">Grant test tokens</button>
                    </form>
                    <p class="tng-test-warning">Test tokens are added only to your current administrator account so you can buy and equip each cosmetic in the Reward Vault.</p>
                </section>

                <section class="tng-test-card">
                    <h2>Reward Transaction Audit</h2>
                    <p>This executes the real reward redemption and equip handlers using a temporary wallet snapshot, then restores your original state.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('tng_verify_reward_system'); ?>
                        <input type="hidden" name="action" value="tng_verify_reward_system">
                        <button class="button button-primary">Run reward audit</button>
                    </form>
                    <p class="tng-test-good">Expected checks: token deduction, unlock persistence, equip persistence, duplicate-purchase protection, and insufficient-funds rejection.</p>
                </section>
            </div>
        </div>
        <?php
    }

    public function seed_players(): void {
        $this->guard('tng_seed_test_players');
        $count = max(1, min(40, absint($_POST['count'] ?? 12)));
        $names = ['Avery Ridge','Maya Falls','Eli Summit','Nora Trail','Jasper Cave','Lena Creek','Miles Stone','Willow Peak','Theo River','Ruby Overlook','Finn Forest','Ivy Hollow','Owen Compass','Hazel Bluff','Leo Lantern','Sage Journey','Emma Vista','Noah Rambler','Aria Canyon','Silas Scout'];
        $titles = ['explorer','trailblazer','quest-finisher','pathfinder','on-a-roll'];
        $badges = ['first-step','trailblazer','quest-complete','explorer-10','on-a-roll'];
        $existing = get_users(['meta_key' => self::TEST_META, 'meta_value' => '1', 'fields' => 'ids']);
        foreach ($existing as $id) wp_delete_user((int)$id);

        for ($i = 0; $i < $count; $i++) {
            $name = $names[$i % count($names)];
            $login = 'tng_test_' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) . '_' . wp_generate_password(5, false, false);
            $user_id = wp_insert_user([
                'user_login' => $login,
                'user_pass' => wp_generate_password(32, true, true),
                'display_name' => $name,
                'nickname' => $name,
                'role' => 'subscriber',
                'user_email' => '',
            ]);
            if (is_wp_error($user_id)) continue;
            update_user_meta($user_id, self::TEST_META, '1');
            $checkpoints = 2 + (($i * 3) % 24);
            $quests = (int)floor($checkpoints / 3);
            $xp = ($checkpoints * 25) + ($quests * 50) + (($i % 4) * 15);
            $completed_checkpoints = [];
            for ($c = 1; $c <= $checkpoints; $c++) $completed_checkpoints[] = 'test-' . ($i + 1) . '-checkpoint-' . $c;
            $completed_quests = [];
            for ($q = 1; $q <= $quests; $q++) $completed_quests[] = 'test-' . ($i + 1) . '-quest-' . $q;
            $recent = [[
                'id' => 'test-activity-' . $user_id,
                'kind' => $quests ? 'quest' : 'checkpoint',
                'title' => $quests ? 'Completed a South Cumberland adventure' : 'Discovered a new checkpoint',
                'subtitle' => 'Test Explorer activity',
                'xp' => 25,
                'date' => gmdate('c', time() - ($i * 2700)),
            ]];
            update_user_meta($user_id, self::PROFILE_META, [
                'totalXp' => $xp,
                'completedCheckpoints' => $completed_checkpoints,
                'completedQuests' => $completed_quests,
                'collections' => ['gps-arrival' => $checkpoints, 'quest' => $quests],
                'badges' => array_slice($badges, 0, min(count($badges), 1 + (int)floor($checkpoints / 5))),
                'activityDays' => [gmdate('Y-m-d')],
                'recentActivity' => $recent,
                'selectedTitle' => $titles[$i % count($titles)],
                'featuredBadge' => $badges[min(count($badges) - 1, (int)floor($i / 3))],
                'updatedAt' => current_time('mysql', true),
            ]);
        }
        $this->redirect(sprintf('Created %d test Explorers. Leaderboards and community totals are now populated.', $count));
    }

    public function delete_players(): void {
        $this->guard('tng_delete_test_players');
        $ids = get_users(['meta_key' => self::TEST_META, 'meta_value' => '1', 'fields' => 'ids']);
        foreach ($ids as $id) wp_delete_user((int)$id);
        $this->redirect(sprintf('Deleted %d generated test Explorers.', count($ids)));
    }

    public function grant_tokens(): void {
        $this->guard('tng_grant_test_tokens');
        $amount = max(1, min(5000, absint($_POST['tokens'] ?? 250)));
        $user_id = get_current_user_id();
        $wallet = get_user_meta($user_id, self::WALLET_META, true);
        if (!is_array($wallet)) $wallet = [];
        $wallet['tokens'] = absint($wallet['tokens'] ?? 0) + $amount;
        update_user_meta($user_id, self::WALLET_META, $wallet);
        $this->redirect(sprintf('Added %d test Adventure Tokens. Open the Reward Vault to test purchases and cosmetics.', $amount));
    }

    public function verify_rewards(): void {
        $this->guard('tng_verify_reward_system');
        $user_id = get_current_user_id();
        $wallet_before = get_user_meta($user_id, self::WALLET_META, true);
        $rewards_before = get_user_meta($user_id, self::REWARDS_META, true);
        $passed = [];
        try {
            update_user_meta($user_id, self::WALLET_META, ['tokens' => 200]);
            update_user_meta($user_id, self::REWARDS_META, ['unlocked' => [], 'equipped' => '']);
            $service = new Adventure_Rewards();
            $request = new WP_REST_Request('POST');
            $request->set_body_params(['action' => 'redeem', 'reward' => 'violet_compass']);
            $response = $service->change_state($request);
            $data = is_wp_error($response) ? [] : $response->get_data();
            $passed[] = ((int)($data['tokens'] ?? -1) === 160) ? 'token deduction passed' : 'token deduction FAILED';
            $passed[] = in_array('violet_compass', (array)($data['unlocked'] ?? []), true) ? 'unlock persistence passed' : 'unlock persistence FAILED';
            $passed[] = (($data['equipped'] ?? '') === 'violet_compass') ? 'auto-equip passed' : 'auto-equip FAILED';

            $duplicate = $service->change_state($request);
            $duplicate_data = is_wp_error($duplicate) ? [] : $duplicate->get_data();
            $passed[] = ((int)($duplicate_data['tokens'] ?? -1) === 160) ? 'duplicate-purchase protection passed' : 'duplicate-purchase protection FAILED';

            update_user_meta($user_id, self::WALLET_META, ['tokens' => 0]);
            update_user_meta($user_id, self::REWARDS_META, ['unlocked' => [], 'equipped' => '']);
            $poor = new WP_REST_Request('POST');
            $poor->set_body_params(['action' => 'redeem', 'reward' => 'campfire_glow']);
            $rejected = $service->change_state($poor);
            $passed[] = (is_wp_error($rejected) && $rejected->get_error_code() === 'tng_not_enough_tokens') ? 'insufficient-funds rejection passed' : 'insufficient-funds rejection FAILED';
        } finally {
            if ($wallet_before === '') delete_user_meta($user_id, self::WALLET_META); else update_user_meta($user_id, self::WALLET_META, $wallet_before);
            if ($rewards_before === '') delete_user_meta($user_id, self::REWARDS_META); else update_user_meta($user_id, self::REWARDS_META, $rewards_before);
        }
        $failed = array_filter($passed, static fn(string $line): bool => strpos($line, 'FAILED') !== false);
        $prefix = $failed ? 'Reward audit found a problem: ' : 'Reward audit passed: ';
        $this->redirect($prefix . implode('; ', $passed) . '. Original wallet restored.');
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
