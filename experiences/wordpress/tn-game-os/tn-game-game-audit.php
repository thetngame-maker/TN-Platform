<?php
/**
 * TN Game Game Audit
 * Admin-only health dashboard and release gate for TN Games.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Game_Game_Audit {
    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu'], 30);
        add_action('admin_post_tng_game_publish_ready', [self::class, 'publish_ready_game']);
    }

    public static function menu(): void {
        add_submenu_page('edit.php?post_type=tng_game','Game Audit','Game Audit','manage_options','tng-game-audit',[self::class, 'render']);
    }

    private static function checkpoints(int $game_id): array {
        $raw = get_post_meta($game_id, 'tng_game_checkpoints', true);
        return is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
    }

    private static function valid_coords($lat, $lng): bool {
        if (!is_numeric($lat) || !is_numeric($lng)) return false;
        $lat = (float) $lat; $lng = (float) $lng;
        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 && !($lat === 0.0 && $lng === 0.0);
    }

    private static function trail_has_route(int $trail_id): bool {
        if (!$trail_id || !get_post($trail_id)) return false;
        foreach (['trail_gpx_url','_trail_gpx_url','gpx_url','_gpx_url'] as $key) {
            if (trim((string) get_post_meta($trail_id, $key, true)) !== '') return true;
        }
        return false;
    }

    private static function audit_game(WP_Post $game): array {
        $errors = []; $warnings = []; $passes = [];
        $checkpoints = self::checkpoints((int) $game->ID);
        $type = strtolower(trim((string) get_post_meta($game->ID, 'game_type', true)));
        $trail_id = absint(get_post_meta($game->ID, 'tng_trail_id', true));
        $saved_xp = absint(get_post_meta($game->ID, 'xp_available', true));
        $calc_xp = 0;

        if (!$checkpoints) $errors[] = 'No structured checkpoints are saved.';
        else $passes[] = count($checkpoints) . ' structured checkpoint' . (count($checkpoints) === 1 ? '' : 's');

        foreach ($checkpoints as $i => $cp) {
            $n = $i + 1;
            $title = trim((string) ($cp['title'] ?? ''));
            $cp_type = sanitize_key((string) ($cp['type'] ?? 'tap'));
            $xp = absint($cp['xp'] ?? 0);
            $calc_xp += $xp;
            if ($title === '') $errors[] = "Checkpoint {$n} has no title.";
            if ($xp < 1) $errors[] = "Checkpoint {$n} has no XP reward.";
            if ($cp_type === 'gps') {
                if (!self::valid_coords($cp['latitude'] ?? null, $cp['longitude'] ?? null)) $errors[] = "Checkpoint {$n} GPS coordinates are missing or invalid.";
                $radius = absint($cp['radius'] ?? 0);
                if ($radius < 1) $warnings[] = "Checkpoint {$n} has no GPS radius; runtime defaults may be used.";
                elseif ($radius > 150) $warnings[] = "Checkpoint {$n} GPS radius is unusually large ({$radius} m).";
            }
            $sight_id = absint($cp['sight_id'] ?? 0);
            if ($sight_id) {
                $sight = get_post($sight_id);
                if (!$sight || $sight->post_status !== 'publish') $errors[] = "Checkpoint {$n} links to missing/unpublished Top Sight #{$sight_id}.";
            }
        }

        if ($checkpoints) {
            if ($saved_xp !== $calc_xp) $errors[] = "XP total mismatch: game says {$saved_xp}, checkpoints total {$calc_xp}.";
            else $passes[] = "XP total matches ({$calc_xp})";
        }

        $is_trail_game = strpos($type, 'trail') !== false;
        if ($trail_id) {
            if (!get_post($trail_id)) $errors[] = "Linked trail #{$trail_id} no longer exists.";
            elseif (!self::trail_has_route($trail_id)) $warnings[] = 'Linked trail has no GPX route URL.';
            else $passes[] = 'Linked trail GPX route found';
        } elseif ($is_trail_game) $errors[] = 'Trail game has no linked trail route source.';
        else $passes[] = 'Standalone checkpoint map allowed';

        if (!has_post_thumbnail($game->ID)) $warnings[] = 'No featured image.';
        else $passes[] = 'Featured image set';

        $last_pass = trim((string) get_post_meta($game->ID, '_tng_last_guided_test_pass', true));
        $receipt = get_post_meta($game->ID, '_tng_guided_test_receipt', true);
        if (!is_array($receipt)) $receipt = [];
        $cert_current = false;
        if ($last_pass !== '') {
            $same_revision = !empty($receipt['game_modified_gmt']) && $receipt['game_modified_gmt'] === $game->post_modified_gmt;
            if ($same_revision) {
                $cert_current = true;
                $passes[] = 'Certified Guided Test is current';
            } else {
                $warnings[] = 'Game changed after its last certified test; a new Guided Test Run is required.';
            }
        } else {
            $warnings[] = 'No recorded full Guided Test Run pass yet.';
        }

        $score = max(0, min(100, 100 - (count($errors) * 20) - (count($warnings) * 5)));
        $release_ready = !$errors && !$warnings && $cert_current;
        if ($errors) {
            $status = 'Needs attention';
            $release_status = 'Blocked';
        } elseif (!$cert_current) {
            $status = 'Needs test';
            $release_status = 'Certified test required';
        } elseif ($warnings) {
            $status = 'Ready with warnings';
            $release_status = 'Review warnings';
        } else {
            $status = $game->post_status === 'publish' ? 'Player ready' : 'Ready to publish';
            $release_status = $status;
        }

        return compact('errors','warnings','passes','score','status','release_status','release_ready','cert_current','checkpoints','calc_xp','saved_xp','last_pass','receipt');
    }

    public static function publish_ready_game(): void {
        if (!current_user_can('publish_posts') || !current_user_can('manage_options')) wp_die('You do not have permission to publish TN Games.');
        $game_id = absint($_POST['game_id'] ?? 0);
        check_admin_referer('tng_publish_ready_' . $game_id);
        $game = $game_id ? get_post($game_id) : null;
        if (!$game || $game->post_type !== 'tng_game') {
            wp_safe_redirect(add_query_arg(['post_type'=>'tng_game','page'=>'tng-game-audit','release_error'=>'missing'], admin_url('edit.php'))); exit;
        }
        $audit = self::audit_game($game);
        if (!$audit['release_ready']) {
            wp_safe_redirect(add_query_arg(['post_type'=>'tng_game','page'=>'tng-game-audit','release_error'=>'not_ready','release_game'=>$game_id], admin_url('edit.php'))); exit;
        }
        $result = wp_update_post(['ID'=>$game_id,'post_status'=>'publish'], true);
        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg(['post_type'=>'tng_game','page'=>'tng-game-audit','release_error'=>'publish','release_game'=>$game_id], admin_url('edit.php'))); exit;
        }
        update_post_meta($game_id, '_tng_release_published_at', current_time('mysql'));
        update_post_meta($game_id, '_tng_release_published_by', get_current_user_id());
        wp_safe_redirect(add_query_arg(['post_type'=>'tng_game','page'=>'tng-game-audit','release_published'=>$game_id], admin_url('edit.php'))); exit;
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) wp_die('You do not have permission to view this page.');
        $games = get_posts(['post_type'=>'tng_game','post_status'=>['publish','draft','pending','private'],'posts_per_page'=>300,'orderby'=>['post_status'=>'ASC','title'=>'ASC']]);
        $audits = []; $ready = $warning = $bad = $certified = $player_ready = 0;
        foreach ($games as $game) {
            $audit = self::audit_game($game); $audits[$game->ID] = $audit;
            if ($audit['errors']) $bad++; elseif ($audit['warnings']) $warning++; else $ready++;
            if ($audit['last_pass']) $certified++;
            if ($audit['release_ready']) $player_ready++;
        }
        $published_notice = absint($_GET['release_published'] ?? 0);
        $release_error = sanitize_key((string) ($_GET['release_error'] ?? ''));
        $release_game = absint($_GET['release_game'] ?? 0);
        ?>
        <div class="wrap tng-game-audit">
            <style>
                .tng-game-audit{max-width:1320px}.tng-audit-hero{margin:18px 0;padding:28px 30px;border-radius:18px;background:linear-gradient(135deg,#0e3b28,#1c5c37);color:#fff;display:flex;justify-content:space-between;gap:24px;align-items:center}.tng-audit-hero h1{color:#fff;font-size:34px;margin:3px 0 6px}.tng-audit-eyebrow{font-size:11px;letter-spacing:.12em;text-transform:uppercase;font-weight:800;color:#ff7a2f}.tng-audit-hero p{margin:0;color:#d9e8df}.tng-audit-summary{display:grid;grid-template-columns:repeat(6,minmax(110px,1fr));gap:12px;margin:18px 0}.tng-audit-stat{background:#fff;border:1px solid #dfe4df;border-radius:14px;padding:18px}.tng-audit-stat strong{display:block;font-size:27px}.tng-audit-stat span{color:#66736c}.tng-audit-stat.is-release{background:#edf7f0;border-color:#c7e3d0}.tng-audit-card{background:#fff;border:1px solid #dfe4df;border-radius:16px;margin:14px 0;overflow:hidden}.tng-audit-card.is-player-ready{border-color:#9fcfb0;box-shadow:0 0 0 2px rgba(63,141,99,.08)}.tng-audit-card__head{display:grid;grid-template-columns:minmax(250px,1fr) 100px 170px auto;gap:18px;align-items:center;padding:18px 20px;border-bottom:1px solid #edf0ed}.tng-audit-card h2{margin:0 0 4px;font-size:20px}.tng-audit-meta{color:#6b746f}.tng-audit-score{font-size:24px;font-weight:800}.tng-audit-status{font-weight:700}.tng-audit-status.is-ready{color:#287a4d}.tng-audit-status.is-warn{color:#9a6816}.tng-audit-status.is-bad{color:#b63a2c}.tng-audit-actions{display:flex;gap:7px;justify-content:flex-end;align-items:center;flex-wrap:wrap}.tng-audit-actions a{text-decoration:none}.tng-audit-actions form{margin:0}.tng-audit-release-button{background:#287a4d!important;border-color:#287a4d!important}.tng-audit-body{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;padding:16px 20px 20px}.tng-audit-list{border-radius:12px;background:#f7f8f6;padding:13px 15px}.tng-audit-list h3{margin:0 0 9px;font-size:13px;text-transform:uppercase;letter-spacing:.06em}.tng-audit-list ul{margin:0;padding-left:18px}.tng-audit-list li{margin:5px 0}.tng-audit-list.is-error{background:#fff1ed}.tng-audit-list.is-warning{background:#fff8e8}.tng-audit-list.is-pass{background:#edf7f0}.tng-audit-empty{color:#7c867f;font-style:italic}.tng-audit-cert{margin-top:12px;padding:10px 12px;border-radius:10px;background:#dff2e5;border:1px solid #b9dfc6}.tng-audit-cert.is-stale{background:#fff4d9;border-color:#eed69c}.tng-audit-cert strong{display:block;color:#247047;margin-bottom:4px}.tng-audit-cert.is-stale strong{color:#946112}.tng-audit-cert span{display:block;color:#52655a;font-size:12px;line-height:1.55}.tng-release-gate{margin:0 20px 18px;padding:12px 14px;border-radius:11px;background:#f5f7f5;border:1px solid #dfe4df;display:flex;justify-content:space-between;gap:15px;align-items:center}.tng-release-gate strong{font-size:13px}.tng-release-gate.is-ready{background:#e8f5ec;border-color:#b9dfc6;color:#246c45}.tng-release-gate.is-blocked{background:#fff1ed;border-color:#f1c8bd;color:#a13d2f}.tng-release-gate.is-review{background:#fff8e8;border-color:#eed69c;color:#8d621d}@media(max-width:1000px){.tng-audit-summary{grid-template-columns:repeat(3,1fr)}}@media(max-width:900px){.tng-audit-summary{grid-template-columns:1fr 1fr}.tng-audit-card__head{grid-template-columns:1fr}.tng-audit-actions{justify-content:flex-start}.tng-audit-body{grid-template-columns:1fr}.tng-release-gate{align-items:flex-start;flex-direction:column}}
            </style>
            <section class="tng-audit-hero"><div><span class="tng-audit-eyebrow">Developer tools</span><h1>Game Audit</h1><p>Validate checkpoint data, XP, GPS, trail routes, Top Sight links, media, and certified Guided Test Runs before players see a game.</p></div><div><strong><?php echo esc_html((string) count($games)); ?></strong> games scanned</div></section>
            <?php if ($published_notice): ?><div class="notice notice-success is-dismissible"><p><strong><?php echo esc_html(get_the_title($published_notice)); ?></strong> passed the release gate and was published.</p></div><?php endif; ?>
            <?php if ($release_error): ?><div class="notice notice-error is-dismissible"><p><?php echo $release_error === 'not_ready' ? esc_html('Game #' . $release_game . ' no longer passes the release gate. Refresh the audit and resolve the listed items before publishing.') : esc_html('The release action could not be completed.'); ?></p></div><?php endif; ?>
            <div class="tng-audit-summary">
                <div class="tng-audit-stat"><strong><?php echo esc_html((string) count($games)); ?></strong><span>Total games</span></div>
                <div class="tng-audit-stat is-release"><strong><?php echo esc_html((string) $player_ready); ?></strong><span>Player-ready</span></div>
                <div class="tng-audit-stat"><strong><?php echo esc_html((string) $ready); ?></strong><span>No issues</span></div>
                <div class="tng-audit-stat"><strong><?php echo esc_html((string) $warning); ?></strong><span>Warnings</span></div>
                <div class="tng-audit-stat"><strong><?php echo esc_html((string) $bad); ?></strong><span>Need attention</span></div>
                <div class="tng-audit-stat"><strong><?php echo esc_html((string) $certified); ?></strong><span>Certified tests</span></div>
            </div>
            <?php if (!$games): ?><div class="notice notice-info"><p>No TN Games exist yet.</p></div><?php endif; ?>
            <?php foreach ($games as $game): $a = $audits[$game->ID]; $r = $a['receipt'];
                $status_class = $a['errors'] ? 'is-bad' : ($a['warnings'] ? 'is-warn' : 'is-ready');
                $play_url = $game->post_status === 'publish' ? add_query_arg('game', $game->ID, home_url('/game-play/')) : '';
                $gate_class = $a['release_ready'] ? 'is-ready' : ($a['errors'] ? 'is-blocked' : 'is-review');
            ?>
                <section class="tng-audit-card <?php echo $a['release_ready'] ? 'is-player-ready' : ''; ?>">
                    <div class="tng-audit-card__head">
                        <div><h2><?php echo esc_html(get_the_title($game)); ?></h2><div class="tng-audit-meta">#<?php echo esc_html((string) $game->ID); ?> · <?php echo esc_html(ucfirst($game->post_status)); ?> · <?php echo esc_html((string) count($a['checkpoints'])); ?> checkpoints · <?php echo esc_html((string) $a['calc_xp']); ?> XP</div></div>
                        <div class="tng-audit-score"><?php echo esc_html((string) $a['score']); ?>/100</div>
                        <div class="tng-audit-status <?php echo esc_attr($status_class); ?>"><?php echo esc_html($a['status']); ?></div>
                        <div class="tng-audit-actions">
                            <a class="button" href="<?php echo esc_url(get_edit_post_link($game->ID)); ?>">Edit</a>
                            <?php if ($play_url): ?><a class="button button-primary" href="<?php echo esc_url($play_url); ?>" target="_blank" rel="noopener">Test game</a><?php endif; ?>
                            <?php if ($game->post_status !== 'publish' && $a['release_ready'] && current_user_can('publish_posts')): ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('tng_publish_ready_' . $game->ID); ?><input type="hidden" name="action" value="tng_game_publish_ready"><input type="hidden" name="game_id" value="<?php echo esc_attr((string) $game->ID); ?>"><button type="submit" class="button button-primary tng-audit-release-button">Publish game</button></form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="tng-audit-body">
                        <div class="tng-audit-list is-error"><h3>Errors · <?php echo esc_html((string) count($a['errors'])); ?></h3><?php if ($a['errors']): ?><ul><?php foreach ($a['errors'] as $item): ?><li><?php echo esc_html($item); ?></li><?php endforeach; ?></ul><?php else: ?><div class="tng-audit-empty">No blocking errors.</div><?php endif; ?></div>
                        <div class="tng-audit-list is-warning"><h3>Warnings · <?php echo esc_html((string) count($a['warnings'])); ?></h3><?php if ($a['warnings']): ?><ul><?php foreach ($a['warnings'] as $item): ?><li><?php echo esc_html($item); ?></li><?php endforeach; ?></ul><?php else: ?><div class="tng-audit-empty">No warnings.</div><?php endif; ?></div>
                        <div class="tng-audit-list is-pass"><h3>Passed · <?php echo esc_html((string) count($a['passes'])); ?></h3><ul><?php foreach ($a['passes'] as $item): ?><li><?php echo esc_html($item); ?></li><?php endforeach; ?></ul>
                            <?php if ($a['last_pass']): ?><div class="tng-audit-cert <?php echo $a['cert_current'] ? '' : 'is-stale'; ?>"><strong><?php echo $a['cert_current'] ? '✓ Certified PASS' : '↻ Certification needs refresh'; ?></strong><span>Tested: <?php echo esc_html((string) ($r['tested_at'] ?? $a['last_pass'])); ?></span><?php if (!empty($r['tester_name'])): ?><span>Tester: <?php echo esc_html((string) $r['tester_name']); ?></span><?php endif; ?><?php if (!empty($r['checkpoint_count'])): ?><span><?php echo esc_html((string) $r['checkpoint_count']); ?> checkpoints · <?php echo esc_html((string) ($r['expected_xp'] ?? 0)); ?> expected XP</span><?php endif; ?><?php if (!empty($r['runtime_version'])): ?><span>TN Game OS <?php echo esc_html((string) $r['runtime_version']); ?></span><?php endif; ?></div><?php endif; ?>
                        </div>
                    </div>
                    <div class="tng-release-gate <?php echo esc_attr($gate_class); ?>"><strong>Release gate: <?php echo esc_html($a['release_status']); ?></strong><span><?php echo $a['release_ready'] ? esc_html($game->post_status === 'publish' ? 'This game currently passes every release check and is ready for players.' : 'Every required check passed. This draft can now be published.') : esc_html('Resolve the errors/warnings and complete a current certified Guided Test before release.'); ?></span></div>
                </section>
            <?php endforeach; ?>
        </div>
        <?php
    }
}

TNG_Game_Game_Audit::boot();
