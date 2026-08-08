<?php
/**
 * TN Game Launch Gate
 * Branded coming-soon screen for public visitors while the platform is in private build mode.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Launch_Gate {
    private const OPTION = 'tng_launch_gate_enabled';

    public static function boot(): void {
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('admin_init', [self::class, 'register_setting']);
        add_action('admin_bar_menu', [self::class, 'admin_bar'], 95);
        add_action('template_redirect', [self::class, 'maybe_render'], 0);
    }

    public static function enabled(): bool {
        return (bool) get_option(self::OPTION, false);
    }

    public static function register_setting(): void {
        register_setting('tng_launch_gate', self::OPTION, [
            'type' => 'boolean',
            'sanitize_callback' => static fn($value) => !empty($value) ? 1 : 0,
            'default' => 0,
        ]);
    }

    public static function admin_menu(): void {
        add_options_page(
            'TN Game Launch Gate',
            'TN Game Launch Gate',
            'manage_options',
            'tng-launch-gate',
            [self::class, 'settings_page']
        );
    }

    public static function settings_page(): void {
        if (!current_user_can('manage_options')) return;
        $enabled = self::enabled();
        ?>
        <div class="wrap">
            <h1>TN Game Launch Gate</h1>
            <p>Hide the public site behind the branded TN Game coming-soon experience while administrators continue to use the full platform normally.</p>
            <form method="post" action="options.php">
                <?php settings_fields('tng_launch_gate'); ?>
                <table class="form-table" role="presentation"><tbody><tr>
                    <th scope="row">Public launch gate</th>
                    <td>
                        <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>" value="1" <?php checked($enabled); ?>> Show the Coming Soon page to public visitors</label>
                        <p class="description">Administrators, WordPress admin/login, REST requests, AJAX, cron, and CLI remain available.</p>
                    </td>
                </tr></tbody></table>
                <?php submit_button($enabled ? 'Save launch-gate settings' : 'Enable when ready'); ?>
            </form>
        </div>
        <?php
    }

    public static function admin_bar($bar): void {
        if (!current_user_can('manage_options')) return;
        $bar->add_node([
            'id' => 'tng-launch-gate',
            'title' => self::enabled() ? '🔒 Public: Coming Soon' : '🌐 Public: Live',
            'href' => admin_url('options-general.php?page=tng-launch-gate'),
            'meta' => ['title' => 'TN Game Launch Gate'],
        ]);
    }

    private static function bypass(): bool {
        if (!self::enabled()) return true;
        if (is_user_logged_in() && current_user_can('manage_options')) return true;
        if (is_admin()) return true;
        if (defined('REST_REQUEST') && REST_REQUEST) return true;
        if (wp_doing_ajax()) return true;
        if (wp_doing_cron()) return true;
        if (defined('WP_CLI') && WP_CLI) return true;
        return false;
    }

    public static function maybe_render(): void {
        if (self::bypass()) return;

        status_header(503);
        nocache_headers();
        header('Retry-After: 3600');
        header('X-Robots-Tag: noindex, nofollow', true);

        $site_name = get_bloginfo('name') ?: 'The TN Game';
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?php echo esc_html($site_name); ?> — Coming Soon</title>
<meta name="theme-color" content="#143d2a">
<style>
:root{--green:#143d2a;--green2:#1e5638;--ink:#18251e;--orange:#ef6324;--cream:#f6f3ea;--card:#fff;--muted:#6d786f;--line:#dfe4dc}*{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:var(--cream);color:var(--ink)}body{min-height:100vh;overflow-x:hidden}.gate{min-height:100vh;display:grid;grid-template-rows:auto 1fr auto;position:relative}.gate:before{content:"";position:absolute;width:540px;height:540px;border-radius:50%;background:rgba(239,99,36,.09);right:-180px;top:-230px;pointer-events:none}.top{display:flex;align-items:center;justify-content:space-between;padding:26px clamp(22px,5vw,72px);position:relative;z-index:2}.brand{display:flex;align-items:center;gap:12px;font-weight:900;letter-spacing:-.02em}.mark{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;background:var(--orange);color:#fff;font-size:14px;box-shadow:0 8px 26px rgba(239,99,36,.24)}.status{font-size:12px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:var(--green);background:#e7efe9;border:1px solid #cedbd2;border-radius:999px;padding:10px 14px}.hero{width:min(1180px,calc(100% - 44px));margin:auto;display:grid;grid-template-columns:minmax(0,1.08fr) minmax(360px,.92fr);gap:clamp(34px,6vw,86px);align-items:center;padding:40px 0 70px;position:relative;z-index:1}.eyebrow{color:var(--orange);font-size:13px;font-weight:900;letter-spacing:.16em;text-transform:uppercase;margin-bottom:18px}.hero h1{font-size:clamp(56px,7vw,104px);line-height:.91;letter-spacing:-.065em;margin:0;max-width:760px}.hero h1 span{color:var(--green)}.lead{font-size:clamp(18px,2vw,23px);line-height:1.55;color:#556158;max-width:680px;margin:28px 0 32px}.chips{display:flex;gap:10px;flex-wrap:wrap}.chip{border:1px solid var(--line);background:#fff;border-radius:999px;padding:11px 14px;font-weight:750;font-size:13px;box-shadow:0 5px 20px rgba(26,47,35,.04)}.preview{background:linear-gradient(145deg,var(--green),#0d2f20);border-radius:34px;padding:26px;box-shadow:0 32px 80px rgba(20,61,42,.2);color:#fff;position:relative;overflow:hidden}.preview:after{content:"";position:absolute;width:280px;height:280px;border-radius:50%;right:-105px;bottom:-150px;border:45px solid rgba(255,255,255,.05)}.preview-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:26px}.preview-head small{color:#d2e2d7;font-weight:800;text-transform:uppercase;letter-spacing:.14em}.play{width:50px;height:50px;border-radius:17px;background:var(--orange);display:grid;place-items:center;font-size:21px;box-shadow:0 12px 32px rgba(239,99,36,.3)}.route{background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.13);border-radius:22px;padding:20px;margin-bottom:14px}.route small{color:#bad1c2;font-size:11px;font-weight:850;text-transform:uppercase;letter-spacing:.12em}.route h3{font-size:26px;margin:8px 0 4px}.route p{margin:0;color:#d5e1d8;font-size:14px}.progress{height:7px;background:rgba(255,255,255,.12);border-radius:99px;margin:18px 0 7px;overflow:hidden}.progress i{display:block;height:100%;width:68%;background:var(--orange);border-radius:99px}.features{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}.feature{background:#fff;color:var(--ink);border-radius:18px;padding:18px;min-height:112px}.feature b{display:block;font-size:25px;margin-bottom:12px}.feature strong{display:block;font-size:15px}.feature span{display:block;color:var(--muted);font-size:12px;line-height:1.4;margin-top:4px}.foot{padding:24px clamp(22px,5vw,72px) 30px;color:#7a827b;font-size:12px;display:flex;justify-content:space-between;gap:20px;position:relative;z-index:1}.foot strong{color:var(--green)}@media(max-width:850px){.hero{grid-template-columns:1fr;padding-top:24px}.preview{max-width:620px}.hero h1{font-size:clamp(52px,14vw,82px)}.status{display:none}}@media(max-width:520px){.top{padding:20px}.hero{width:calc(100% - 30px);padding-bottom:42px}.preview{padding:18px;border-radius:26px}.features{grid-template-columns:1fr}.lead{font-size:17px}.foot{padding:20px;flex-direction:column}.mark{width:40px;height:40px}}
</style>
</head>
<body>
<main class="gate">
<header class="top"><div class="brand"><span class="mark">TN</span><span>The TN Game</span></div><span class="status">Building the adventure</span></header>
<section class="hero">
<div>
<div class="eyebrow">Something new is coming to Tennessee</div>
<h1>Explore more.<br><span>Play everywhere.</span></h1>
<p class="lead">The TN Game is turning Tennessee into an adventure you can play — with trails, local places, road trips, challenges, checkpoints, achievements, and new ways to explore together.</p>
<div class="chips"><span class="chip">🥾 Trails</span><span class="chip">📍 Local places</span><span class="chip">🏆 Challenges</span><span class="chip">🗺 Road trips</span><span class="chip">🎮 Games</span></div>
</div>
<div class="preview" aria-label="TN Game preview">
<div class="preview-head"><small>Your Tennessee adventure</small><span class="play">▶</span></div>
<div class="route"><small>Trip mode</small><h3>Make a day of it.</h3><p>Build a route, visit each stop, and turn the places around you into progress.</p><div class="progress"><i></i></div></div>
<div class="features"><div class="feature"><b>📍</b><strong>Discover</strong><span>Trails, waterfalls, food, events, sights, and hidden favorites.</span></div><div class="feature"><b>⚡</b><strong>Earn XP</strong><span>Complete adventures, unlock achievements, and build your Explorer story.</span></div><div class="feature"><b>🗺️</b><strong>Build trips</strong><span>Save stops, organize your day, then take Trip Mode on the road.</span></div><div class="feature"><b>🎮</b><strong>Play together</strong><span>Games, challenges, leaderboards, and experiences built for the places you visit.</span></div></div>
</div>
</section>
<footer class="foot"><span><strong>The TN Game</strong> · Tennessee is the game board.</span><span>We’re getting the first adventures ready.</span></footer>
</main>
</body>
</html>
        <?php
        exit;
    }
}

TNG_Launch_Gate::boot();
