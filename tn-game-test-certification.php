<?php
/**
 * TN Game Guided Test Certification
 * Persists verified developer test passes to game post meta.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Game_Test_Certification {
    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('wp_footer', [self::class, 'render_client'], 1220);
    }

    public static function register_routes(): void {
        register_rest_route('tng-game/v1', '/test-certification', [
            'methods' => 'POST',
            'callback' => [self::class, 'save_certification'],
            'permission_callback' => static function () {
                return is_user_logged_in() && current_user_can('manage_options');
            },
        ]);
    }

    private static function checkpoints(int $game_id): array {
        $raw = get_post_meta($game_id, 'tng_game_checkpoints', true);
        return is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
    }

    private static function expected_xp(array $checkpoints): int {
        $total = 0;
        $defaults = ['tap' => 10, 'gps' => 25, 'question' => 50, 'photo' => 40];
        foreach ($checkpoints as $cp) {
            $type = sanitize_key((string) ($cp['type'] ?? 'tap'));
            $xp = absint($cp['xp'] ?? 0);
            if ($xp < 1) $xp = $defaults[$type] ?? 25;
            $total += $xp;
        }
        return $total;
    }

    public static function save_certification(WP_REST_Request $request) {
        $game_id = absint($request->get_param('gameId'));
        $post = $game_id ? get_post($game_id) : null;
        if (!$post || $post->post_type !== 'tng_game' || $post->post_status !== 'publish') {
            return new WP_Error('invalid_game', 'A published TN Game is required.', ['status' => 400]);
        }

        $checkpoints = self::checkpoints($game_id);
        if (!$checkpoints) {
            return new WP_Error('no_checkpoints', 'The game has no structured checkpoints.', ['status' => 400]);
        }

        $progress = get_user_meta(get_current_user_id(), '_tng_game_progress_' . $game_id, true);
        if (!is_array($progress)) $progress = [];
        $progress = array_values(array_unique(array_map('absint', $progress)));

        $missing = [];
        foreach (array_keys($checkpoints) as $index) {
            if (!in_array((int) $index, $progress, true)) $missing[] = (int) $index;
        }
        if ($missing) {
            return new WP_Error('route_incomplete', 'The server does not show every checkpoint as completed.', ['status' => 409, 'missing' => $missing]);
        }

        $sequence_pass = filter_var($request->get_param('sequencePass'), FILTER_VALIDATE_BOOLEAN);
        if (!$sequence_pass) {
            return new WP_Error('sequence_failed', 'The guided run did not pass progression order.', ['status' => 409]);
        }

        $user = wp_get_current_user();
        $tested_at = current_time('mysql');
        $receipt = [
            'status' => 'pass',
            'tested_at' => $tested_at,
            'tested_at_gmt' => current_time('mysql', true),
            'tester_id' => get_current_user_id(),
            'tester_name' => $user ? $user->display_name : '',
            'checkpoint_count' => count($checkpoints),
            'expected_xp' => self::expected_xp($checkpoints),
            'observed_xp' => absint($request->get_param('observedXp')),
            'sequence_pass' => true,
            'game_modified_gmt' => get_post_field('post_modified_gmt', $game_id),
            'runtime_version' => defined('TNG_OS_VERSION') ? TNG_OS_VERSION : '',
        ];

        update_post_meta($game_id, '_tng_last_guided_test_pass', $tested_at);
        update_post_meta($game_id, '_tng_guided_test_receipt', $receipt);

        return rest_ensure_response([
            'ok' => true,
            'gameId' => $game_id,
            'testedAt' => $tested_at,
            'checkpointCount' => $receipt['checkpoint_count'],
            'expectedXp' => $receipt['expected_xp'],
            'tester' => $receipt['tester_name'],
        ]);
    }

    private static function is_game_play(): bool {
        return class_exists('TNG_OS\\Platform\\App_Router')
            && TNG_OS\Platform\App_Router::current_route() === 'game-play';
    }

    public static function render_client(): void {
        if (is_admin() || !is_user_logged_in() || !current_user_can('manage_options') || !self::is_game_play()) return;
        $game_id = absint($_GET['game'] ?? 0);
        if (!$game_id) return;
        $endpoint = rest_url('tng-game/v1/test-certification');
        $nonce = wp_create_nonce('wp_rest');
        ?>
        <style>
            .tng-dev-cert{margin-top:8px;padding:9px 10px;border-radius:10px;background:rgba(63,141,99,.18);color:#d8f3e3;font-size:10px;line-height:1.45}
            .tng-dev-cert.is-saving{background:rgba(255,255,255,.07);color:#cbd5e1}.tng-dev-cert.is-error{background:rgba(180,58,44,.2);color:#ffd9d4}.tng-dev-cert b{color:#fff}
        </style>
        <script>
        (()=>{
            const gameId=<?php echo (int) $game_id; ?>;
            const endpoint=<?php echo wp_json_encode($endpoint); ?>;
            const nonce=<?php echo wp_json_encode($nonce); ?>;
            const reportKey=`tng_dev_report_${gameId}`;
            const certKey=`tng_dev_cert_saved_${gameId}`;

            const readReport=()=>{try{return JSON.parse(sessionStorage.getItem(reportKey)||'null');}catch(e){return null;}};
            const isPassing=(r)=>{
                if(!r||!r.finished||r.sequencePass===false||!r.steps) return false;
                const steps=Object.values(r.steps);
                return steps.length>0 && steps.every(step=>step&&step.passed===true);
            };
            const mountStatus=()=>{
                const report=document.querySelector('.tng-dev-report');
                if(!report) return null;
                let node=report.querySelector('.tng-dev-cert');
                if(!node){node=document.createElement('div');node.className='tng-dev-cert is-saving';const checks=report.querySelector('.tng-dev-report__checks');(checks||report).insertAdjacentElement('afterend',node);}
                return node;
            };
            const save=async()=>{
                const r=readReport();
                if(!isPassing(r)) return;
                const fingerprint=String(r.finishedAt||'pass');
                const node=mountStatus();
                if(sessionStorage.getItem(certKey)===fingerprint){if(node){node.className='tng-dev-cert';node.innerHTML='<b>Certified ✓</b> This passing run is saved to Game Audit.';}return;}
                if(node){node.className='tng-dev-cert is-saving';node.textContent='Saving verified PASS to Game Audit…';}
                try{
                    const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify({gameId,sequencePass:r.sequencePass!==false,observedXp:Number(r.xpObserved||0)})});
                    const data=await response.json();
                    if(!response.ok||!data?.ok) throw new Error(data?.message||'Certification failed');
                    sessionStorage.setItem(certKey,fingerprint);
                    if(node){node.className='tng-dev-cert';node.innerHTML=`<b>Certified ✓</b> Saved ${data.checkpointCount} checkpoints · ${data.expectedXp} expected XP · ${data.testedAt}`;}
                }catch(err){if(node){node.className='tng-dev-cert is-error';node.textContent='Certification was not saved: '+String(err?.message||err);}}
            };

            let tries=0;
            const timer=setInterval(()=>{tries++;const r=readReport();if(isPassing(r)){clearInterval(timer);save();}else if(tries>=20){clearInterval(timer);}},150);
        })();
        </script>
        <?php
    }
}

TNG_Game_Test_Certification::boot();
