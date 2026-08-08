<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Public_Explorer_Profile implements Module_Interface {
    private const META_PRIVACY = '_tng_explorer_profile_visibility';

    public function id(): string { return 'public_explorer_profile'; }

    public function register(Container $container): void {
        $container->set('public_explorer_profile', $this);
        add_shortcode('tng_explorer_profile', [$this, 'shortcode']);
        add_action('admin_menu', [$this, 'admin_menu'], 85);
        add_action('wp_ajax_tng_save_explorer_visibility', [$this, 'save_visibility']);
    }

    public function boot(Container $container): void {}

    public function admin_menu(): void {
        add_submenu_page(
            'tn-game-os',
            'Explorer Profiles',
            'Explorer Profiles',
            'manage_options',
            'tng-os-explorer-profiles',
            [$this, 'admin_page']
        );
    }

    public function admin_page(): void {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap"><h1>Public Explorer Profiles</h1>';
        echo '<p>Create a WordPress page named <strong>Explorer</strong> and add:</p>';
        echo '<p><code>[tng_explorer_profile]</code></p>';
        echo '<p>The page displays the signed-in Explorer by default. Shared links use <code>?explorer=username</code>.</p></div>';
    }

    public function save_visibility(): void {
        check_ajax_referer('tng_explorer_profile', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error(['message' => 'Sign in required.'], 403);
        $visibility = sanitize_key($_POST['visibility'] ?? 'public');
        if (!in_array($visibility, ['public', 'private'], true)) $visibility = 'public';
        update_user_meta(get_current_user_id(), self::META_PRIVACY, $visibility);
        wp_send_json_success(['visibility' => $visibility]);
    }

    public function shortcode($atts = []): string {
        $atts = shortcode_atts(['username' => ''], $atts, 'tng_explorer_profile');
        $requested = sanitize_user($_GET['explorer'] ?? $atts['username']);
        $viewer_id = get_current_user_id();
        $user = $requested ? get_user_by('slug', $requested) : ($viewer_id ? get_user_by('id', $viewer_id) : false);

        if (!$user) {
            return '<section class="tng-public-explorer"><div class="tng-public-explorer-empty"><h2>Explorer profile</h2><p>Sign in or open a valid Explorer link.</p></div></section>';
        }

        $user_id = (int) $user->ID;
        $is_owner = $viewer_id === $user_id;
        $visibility = get_user_meta($user_id, self::META_PRIVACY, true) ?: 'public';
        if ($visibility === 'private' && !$is_owner && !current_user_can('manage_options')) {
            return '<section class="tng-public-explorer"><div class="tng-public-explorer-empty"><h2>Private Explorer profile</h2><p>This Explorer has chosen to keep their travel story private.</p></div></section>';
        }

        $stats = apply_filters('tng_os_explorer_profile_stats', [], $user_id);
        $stats = is_array($stats) ? $stats : [];
        $events = apply_filters('tng_os_adventure_journal_events', [], $user_id);
        $events = is_array($events) ? $events : [];
        usort($events, static fn($a, $b) => (strtotime($b['date'] ?? '') ?: 0) <=> (strtotime($a['date'] ?? '') ?: 0));
        $events = array_slice($events, 0, 6);

        $trips = absint($stats['completed_trips'] ?? $stats['trips'] ?? 0);
        $places = absint($stats['trip_stops'] ?? $stats['checkpoints'] ?? 0);
        $xp = absint($stats['xp'] ?? $stats['total_xp'] ?? 0);
        $achievements = absint($stats['achievements'] ?? 0);
        $streak = absint($stats['travel_streak'] ?? $stats['day_streak'] ?? 0);
        $minutes = absint($stats['trip_minutes'] ?? $stats['total_trip_minutes'] ?? 0);
        $hours = $minutes ? round($minutes / 6) / 10 : 0;
        $display = $user->display_name ?: $user->user_login;
        $avatar = get_avatar_url($user_id, ['size' => 160]);
        $share_url = add_query_arg('explorer', $user->user_nicename, get_permalink());

        ob_start(); ?>
        <section class="tng-public-explorer" data-explorer-profile data-share-url="<?php echo esc_attr($share_url); ?>">
            <style>
                .tng-public-explorer{max-width:1080px;margin:32px auto;color:#17213d;font-family:inherit}
                .tng-public-explorer-hero{position:relative;overflow:hidden;padding:34px;border-radius:28px;background:linear-gradient(135deg,#19254c,#7642a2);color:#fff;box-shadow:0 18px 45px rgba(30,34,70,.16)}
                .tng-public-explorer-hero:after{content:"";position:absolute;width:260px;height:260px;border-radius:50%;right:-85px;bottom:-150px;background:rgba(255,255,255,.08)}
                .tng-public-explorer-head{position:relative;z-index:1;display:grid;grid-template-columns:112px 1fr auto;gap:22px;align-items:center}
                .tng-public-explorer-avatar{width:108px;height:108px;border-radius:28px;object-fit:cover;border:4px solid rgba(255,255,255,.24);background:#fff}
                .tng-public-explorer-kicker{font-size:12px;font-weight:800;letter-spacing:.18em;text-transform:uppercase;color:#ffd447}
                .tng-public-explorer h1{font-size:40px;line-height:1.05;margin:8px 0;color:#fff}.tng-public-explorer-sub{color:rgba(255,255,255,.82)}
                .tng-public-explorer-share{border:0;border-radius:14px;padding:13px 18px;background:#fff;color:#17213d;font-weight:800;cursor:pointer}
                .tng-public-explorer-stats{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin:18px 0}
                .tng-public-explorer-stat{padding:18px 10px;text-align:center;background:#fff;border:1px solid #e1e5ef;border-radius:18px}
                .tng-public-explorer-stat strong{display:block;font-size:27px;color:#6d3dc5}.tng-public-explorer-stat span{font-size:10px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#737c91}
                .tng-public-explorer-section{margin-top:18px;padding:24px;background:#fff;border:1px solid #e1e5ef;border-radius:22px}
                .tng-public-explorer-section h2{margin:0 0 16px;font-size:25px}
                .tng-public-explorer-memory{display:grid;grid-template-columns:48px 1fr auto;gap:14px;padding:15px 0;border-top:1px solid #edf0f6}.tng-public-explorer-memory:first-of-type{border-top:0}
                .tng-public-explorer-icon{width:46px;height:46px;border-radius:14px;background:#eee7ff;color:#6e3dcc;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:20px}
                .tng-public-explorer-memory h3{margin:0 0 4px;font-size:17px}.tng-public-explorer-memory p{margin:0;color:#6f7890}.tng-public-explorer-date{font-size:12px;color:#8b93a6;white-space:nowrap}
                .tng-public-explorer-owner{margin-top:18px;padding:18px;background:#f7f4ff;border:1px solid #e1d5ff;border-radius:18px;display:flex;align-items:center;justify-content:space-between;gap:12px}
                .tng-public-explorer-toggle{display:flex;gap:8px}.tng-public-explorer-toggle button{border:1px solid #d8dcea;background:#fff;border-radius:999px;padding:9px 13px;font-weight:800;cursor:pointer}.tng-public-explorer-toggle button.is-active{background:#7b49df;color:#fff;border-color:#7b49df}
                .tng-public-explorer-empty{padding:40px;text-align:center;background:#fff;border:1px dashed #ccd2e0;border-radius:20px}
                @media(max-width:820px){.tng-public-explorer{margin:16px}.tng-public-explorer-head{grid-template-columns:78px 1fr}.tng-public-explorer-avatar{width:74px;height:74px;border-radius:22px}.tng-public-explorer-share{grid-column:1/-1}.tng-public-explorer h1{font-size:32px}.tng-public-explorer-stats{grid-template-columns:repeat(2,1fr)}.tng-public-explorer-memory{grid-template-columns:42px 1fr}.tng-public-explorer-date{grid-column:2}.tng-public-explorer-owner{align-items:flex-start;flex-direction:column}}
            </style>
            <div class="tng-public-explorer-hero">
                <div class="tng-public-explorer-head">
                    <img class="tng-public-explorer-avatar" src="<?php echo esc_url($avatar); ?>" alt="">
                    <div><div class="tng-public-explorer-kicker">TN Game Explorer</div><h1><?php echo esc_html($display); ?></h1><div class="tng-public-explorer-sub">Exploring Tennessee one real-world discovery at a time.</div></div>
                    <button type="button" class="tng-public-explorer-share" data-profile-share>Share profile</button>
                </div>
            </div>
            <div class="tng-public-explorer-stats">
                <?php echo $this->stat($trips, 'Trips'); ?>
                <?php echo $this->stat($places, 'Places'); ?>
                <?php echo $this->stat($hours, 'Adventure hours'); ?>
                <?php echo $this->stat($xp, 'XP'); ?>
                <?php echo $this->stat($achievements, 'Achievements'); ?>
                <?php echo $this->stat($streak, 'Day streak'); ?>
            </div>
            <div class="tng-public-explorer-section"><h2>Recent memories</h2>
                <?php if (!$events): ?><div class="tng-public-explorer-empty">New trips, checkpoints, achievements, and photos will appear here.</div><?php endif; ?>
                <?php foreach ($events as $event): $type=sanitize_key($event['type'] ?? 'activity'); $ts=strtotime($event['date'] ?? '') ?: 0; ?>
                    <article class="tng-public-explorer-memory"><div class="tng-public-explorer-icon"><?php echo esc_html($this->icon($type)); ?></div><div><h3><?php echo esc_html($event['title'] ?? 'Explorer memory'); ?></h3><p><?php echo esc_html($event['description'] ?? ''); ?></p></div><time class="tng-public-explorer-date"><?php echo esc_html($ts ? wp_date(get_option('date_format'), $ts) : ''); ?></time></article>
                <?php endforeach; ?>
            </div>
            <?php if ($is_owner): ?>
                <div class="tng-public-explorer-owner"><div><strong>Profile visibility</strong><div>Choose whether other Explorers can open your shared profile.</div></div><div class="tng-public-explorer-toggle"><button type="button" data-visibility="public" class="<?php echo $visibility === 'public' ? 'is-active' : ''; ?>">Public</button><button type="button" data-visibility="private" class="<?php echo $visibility === 'private' ? 'is-active' : ''; ?>">Private</button></div></div>
            <?php endif; ?>
            <script>
            (function(){var root=document.currentScript.closest('[data-explorer-profile]');if(!root)return;
                var share=root.querySelector('[data-profile-share]');if(share)share.addEventListener('click',async function(){var url=root.getAttribute('data-share-url');var data={title:'<?php echo esc_js($display); ?> on The TN Game',text:'See my Tennessee Explorer profile.',url:url};try{if(navigator.share)await navigator.share(data);else{await navigator.clipboard.writeText(url);share.textContent='Link copied';}}catch(e){}});
                root.querySelectorAll('[data-visibility]').forEach(function(btn){btn.addEventListener('click',function(){var body=new URLSearchParams({action:'tng_save_explorer_visibility',nonce:'<?php echo esc_js(wp_create_nonce('tng_explorer_profile')); ?>',visibility:btn.getAttribute('data-visibility')});fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()}).then(function(r){return r.json()}).then(function(j){if(!j.success)return;root.querySelectorAll('[data-visibility]').forEach(function(x){x.classList.toggle('is-active',x===btn)});});});});
            })();
            </script>
        </section>
        <?php return (string) ob_get_clean();
    }

    private function stat($value, string $label): string {
        $display = is_float($value) ? number_format_i18n($value, 1) : number_format_i18n((int) $value);
        return '<div class="tng-public-explorer-stat"><strong>' . esc_html($display) . '</strong><span>' . esc_html($label) . '</span></div>';
    }

    private function icon(string $type): string {
        if (str_contains($type, 'trip')) return '✓';
        if (str_contains($type, 'badge') || str_contains($type, 'achievement') || str_contains($type, 'rank')) return '★';
        if (str_contains($type, 'photo')) return '▣';
        if (str_contains($type, 'checkpoint')) return '◇';
        if (str_contains($type, 'quest')) return '✓';
        return '•';
    }
}
