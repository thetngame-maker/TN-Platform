<?php
namespace TNG_OS\Modules\Developer;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use TNG_OS\Modules\Frontend\Community_Expedition;

if (!defined('ABSPATH')) exit;

final class Developer_Manager implements Module_Interface {
    private const LEGACY_AUTO_OPTION = 'tng_auto_developer_mode';
    private const PROFILE_META = '_tng_explorer_profile';
    private const WEEKLY_META = '_tng_weekly_expedition';
    private const DAILY_META = '_tng_daily_missions';
    private const COMMUNITY_CLAIM_META = '_tng_community_expedition_claims';
    private const DEV_GENERATION_META = '_tng_dev_event_generation';

    public function id(): string { return 'developer_manager'; }

    public function register(Container $container): void {
        $container->set('developer_manager', $this);
        add_action('init', [$this, 'disable_legacy_auto_mode'], 1);
        add_action('admin_bar_menu', [$this, 'admin_bar_menu'], 90);
        add_action('wp_footer', [$this, 'render_status_banner'], 9998);
        add_filter('body_class', [$this, 'body_class']);
        add_action('admin_post_tng_dev_reset_checkpoints', [$this, 'reset_checkpoints']);
        add_action('admin_post_tng_dev_reset_daily', [$this, 'reset_daily']);
        add_action('admin_post_tng_dev_reset_weekly', [$this, 'reset_weekly']);
        add_action('admin_post_tng_dev_reset_community', [$this, 'reset_community']);
    }

    public function boot(Container $container): void {}

    public function disable_legacy_auto_mode(): void {
        if ((int)get_option(self::LEGACY_AUTO_OPTION, 0) !== 0) update_option(self::LEGACY_AUTO_OPTION, 0, false);
    }

    public function is_available(): bool { return current_user_can('manage_options'); }

    public function is_enabled(): bool {
        if (!$this->is_available() || !isset($_GET['tng_dev'])) return false;
        return sanitize_text_field(wp_unslash($_GET['tng_dev'])) === '1';
    }

    public function admin_bar_menu($admin_bar): void {
        if (!$this->is_available() || is_admin()) return;
        $enabled = $this->is_enabled();
        $url = $enabled ? remove_query_arg('tng_dev') : add_query_arg('tng_dev', '1');
        $admin_bar->add_node([
            'id' => 'tng-developer-mode',
            'title' => $enabled ? 'TN Developer: ON' : 'TN Developer: OFF',
            'href' => esc_url($url),
            'meta' => [
                'class' => $enabled ? 'tng-dev-is-enabled' : 'tng-dev-is-disabled',
                'title' => $enabled ? 'Disable TN developer tools for this page' : 'Enable TN developer tools for this page',
            ],
        ]);
    }

    public function body_class(array $classes): array {
        if ($this->is_enabled()) $classes[] = 'tng-developer-mode-active';
        return $classes;
    }

    public function render_status_banner(): void {
        if (!$this->is_enabled()) return;
        $disable_url = remove_query_arg('tng_dev');
        $return_url = $this->current_url();
        $notice = isset($_GET['tng_dev_notice']) ? sanitize_text_field(wp_unslash($_GET['tng_dev_notice'])) : '';
        ?>
        <aside class="tng-developer-status" role="status" aria-live="polite">
            <div class="tng-dev-copy">
                <span><strong>TN Developer Mode</strong> is active for this page only.</span>
                <?php if ($notice): ?><small><?php echo esc_html($notice); ?></small><?php endif; ?>
            </div>
            <div class="tng-dev-actions">
                <?php $this->reset_form('tng_dev_reset_checkpoints', 'Reset checkpoints', 'Reset this quest’s checkpoint and completion state for a fresh test run?'); ?>
                <?php $this->reset_form('tng_dev_reset_daily', 'Reset daily', 'Reset today’s Daily Missions, claims, and mission streak while preserving your Adventure Token balance?'); ?>
                <?php $this->reset_form('tng_dev_reset_weekly', 'Reset weekly', 'Reset your Weekly Expedition points and claims from this moment?'); ?>
                <?php $this->reset_form('tng_dev_reset_community', 'Reset community', 'Reset the global Community Expedition and clear community reward claims for testing?'); ?>
                <a href="<?php echo esc_url($disable_url); ?>">Return to player view</a>
            </div>
        </aside>
        <style>
            .tng-developer-status{position:fixed;left:50%;bottom:18px;z-index:2147483000;transform:translateX(-50%);display:flex;align-items:center;gap:14px;max-width:calc(100vw - 28px);padding:10px 14px;border:1px solid rgba(255,255,255,.24);border-radius:18px;background:#7a2e0e;color:#fff;box-shadow:0 8px 30px rgba(0,0,0,.28);font-size:13px;line-height:1.35;pointer-events:auto}.tng-developer-status strong{font-weight:800}.tng-dev-copy{display:grid;gap:2px}.tng-dev-copy small{opacity:.82}.tng-dev-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.tng-dev-actions form{margin:0}.tng-dev-actions button,.tng-developer-status a{appearance:none;border:1px solid rgba(255,255,255,.28);border-radius:999px;background:rgba(255,255,255,.12);color:#fff;padding:7px 10px;font:inherit;font-weight:700;cursor:pointer;text-decoration:none;white-space:nowrap}.tng-developer-status a{background:rgba(255,255,255,.2)}
            @media(max-width:900px){.tng-developer-status{right:12px;bottom:12px;left:12px;transform:none;align-items:stretch;flex-direction:column;border-radius:14px}.tng-dev-actions{display:grid;grid-template-columns:1fr 1fr}.tng-dev-actions a{grid-column:1/-1;text-align:center}.tng-dev-actions button{width:100%}}
        </style>
        <?php if (isset($_GET['tng_dev_clear_runtime']) && sanitize_text_field(wp_unslash($_GET['tng_dev_clear_runtime'])) === '1'): ?>
        <script>
        (()=>{
            const questId=new URLSearchParams(location.search).get('tng_quest_runtime_id')||'';
            const clear=(store)=>{try{for(let i=store.length-1;i>=0;i--){const key=store.key(i)||'';const lower=key.toLowerCase();if((questId&&key.includes(questId))||(lower.includes('tng')&&(lower.includes('quest')||lower.includes('runtime')||lower.includes('checkpoint')))){store.removeItem(key);}}}catch(e){}};
            clear(window.localStorage);clear(window.sessionStorage);
            const url=new URL(location.href);url.searchParams.delete('tng_dev_clear_runtime');location.replace(url.toString());
        })();
        </script>
        <?php endif; ?>
        <?php
    }

    private function reset_form(string $action, string $label, string $confirm): void {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm(<?php echo wp_json_encode($confirm); ?>);">
            <?php wp_nonce_field($action); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <input type="hidden" name="return_url" value="<?php echo esc_attr($this->current_url()); ?>">
            <button type="submit"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    public function reset_checkpoints(): void {
        $this->guard('tng_dev_reset_checkpoints');
        $this->prepare_current_quest_replay(get_current_user_id());
        $this->redirect_back('Checkpoint and quest completion state reset for this test run.', ['tng_dev_clear_runtime' => '1']);
    }

    public function reset_daily(): void {
        $this->guard('tng_dev_reset_daily');
        $user_id = get_current_user_id();
        $state = get_user_meta($user_id, self::DAILY_META, true);
        if (!is_array($state)) $state = [];
        update_user_meta($user_id, self::DAILY_META, [
            'date' => gmdate('Y-m-d'),
            'tokens' => absint($state['tokens'] ?? 0),
            'completed' => [],
            'claimed' => [],
            'missionStreak' => 0,
            'lastCompletedDate' => '',
        ]);
        $this->redirect_back('Daily Missions reset. Adventure Token balance was preserved.');
    }

    public function reset_weekly(): void {
        $this->guard('tng_dev_reset_weekly');
        $user_id = get_current_user_id();
        update_user_meta($user_id, self::WEEKLY_META, [
            'weekKey' => gmdate('o-\\WW'),
            'resetAt' => current_time('mysql', true),
            'claimed' => [],
        ]);
        $this->redirect_back('Weekly Expedition reset to 0 Trail Points and reward claims cleared.');
    }

    public function reset_community(): void {
        $this->guard('tng_dev_reset_community');
        update_option(Community_Expedition::DEV_BASELINE_OPTION, [
            'week' => $this->week_key(),
            'startedAt' => current_time('mysql', true),
            'resetAt' => current_time('mysql', true),
        ], false);
        foreach (get_users(['fields' => 'ids']) as $other_user_id) delete_user_meta((int)$other_user_id, self::COMMUNITY_CLAIM_META);
        Community_Expedition::clear_cache();
        $this->redirect_back('Community Expedition reset to 0 and reward claims cleared.');
    }

    private function prepare_current_quest_replay(int $user_id): void {
        $return_url = isset($_POST['return_url']) ? esc_url_raw(wp_unslash($_POST['return_url'])) : '';
        $query = wp_parse_url($return_url, PHP_URL_QUERY);
        $args = [];
        if (is_string($query)) parse_str($query, $args);
        $quest_id = absint($args['tng_quest_runtime_id'] ?? 0);
        update_user_meta($user_id, self::DEV_GENERATION_META, absint(get_user_meta($user_id, self::DEV_GENERATION_META, true)) + 1);
        if (!$quest_id) return;

        $profile = get_user_meta($user_id, self::PROFILE_META, true);
        if (!is_array($profile)) return;
        $profile['completedCheckpoints'] = array_values(array_filter((array)($profile['completedCheckpoints'] ?? []), fn($value): bool => !$this->belongs_to_quest((string)$value, $quest_id)));
        $profile['completedQuests'] = array_values(array_filter((array)($profile['completedQuests'] ?? []), fn($value): bool => !$this->belongs_to_quest((string)$value, $quest_id)));
        update_user_meta($user_id, self::PROFILE_META, $profile);
    }

    private function belongs_to_quest(string $value, int $quest_id): bool {
        $value = trim($value);
        if ($value === (string)$quest_id || $value === 'quest:' . $quest_id) return true;
        return (bool)preg_match('/(?:^|:)' . preg_quote((string)$quest_id, '/') . '(?::|$)/', $value);
    }

    private function current_url(): string {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? ''));
        $uri = wp_unslash($_SERVER['REQUEST_URI'] ?? '/');
        return esc_url_raw($scheme . $host . $uri);
    }

    private function guard(string $action): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        check_admin_referer($action);
    }

    private function redirect_back(string $notice, array $extra = []): void {
        $return = isset($_POST['return_url']) ? esc_url_raw(wp_unslash($_POST['return_url'])) : home_url('/');
        if (!$return || strpos($return, home_url('/')) !== 0) $return = home_url('/');
        $return = remove_query_arg(['tng_dev_notice', 'tng_dev_clear_runtime'], $return);
        wp_safe_redirect(add_query_arg(array_merge(['tng_dev' => '1', 'tng_dev_notice' => $notice], $extra), $return));
        exit;
    }

    private function week_key(): string { return gmdate('o-\\WW', $this->week_start_timestamp()); }
    private function week_start_timestamp(): int {
        $now = current_time('timestamp', true);
        $day = (int)gmdate('N', $now);
        return strtotime('-' . ($day - 1) . ' days midnight', $now);
    }
}
