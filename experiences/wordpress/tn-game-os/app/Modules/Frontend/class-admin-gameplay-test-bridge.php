<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class Admin_Gameplay_Test_Bridge implements Module_Interface {
    private const CHALLENGE_META = '_tng_dynamic_challenge_claims';

    public function id(): string { return 'admin_gameplay_test_bridge'; }

    public function register(Container $container): void {
        $container->set('admin_gameplay_test_bridge', $this);
        add_action('rest_api_init', [$this, 'routes']);
        add_action('wp_footer', [$this, 'runtime_override'], 175);
        add_action('wp_footer', [$this, 'challenge_override'], 176);
    }

    public function boot(Container $container): void {}

    public function routes(): void {
        register_rest_route('tng/v1', '/admin-test/dynamic-challenge', [
            'methods' => 'POST',
            'callback' => [$this, 'claim_test_challenge'],
            'permission_callback' => static fn(): bool => current_user_can('manage_options'),
            'args' => [
                'challenge_id' => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
            ],
        ]);
    }

    public function claim_test_challenge(WP_REST_Request $request) {
        $id = sanitize_key((string)$request['challenge_id']);
        $definitions = [
            'morning_explorer' => ['title' => 'Morning Explorer', 'xp' => 35],
            'daylight_discovery' => ['title' => 'Daylight Discovery', 'xp' => 25],
            'night_explorer' => ['title' => 'Night Explorer', 'xp' => 40],
            'weekend_adventure' => ['title' => 'Weekend Adventure', 'xp' => 50],
            'event_spotlight' => ['title' => 'Event Spotlight', 'xp' => 45],
            'quest_of_the_day' => ['title' => 'Quest of the Day', 'xp' => 75],
            'first_visit_bonus' => ['title' => 'First Visit Bonus', 'xp' => 25],
        ];
        if (!isset($definitions[$id])) return new WP_Error('challenge_not_found', 'Challenge is not available.', ['status' => 404]);

        $user_id = get_current_user_id();
        $period = $id === 'weekend_adventure' ? current_datetime()->format('o-W') : current_datetime()->format('Y-m-d');
        $key = $id . ':' . $period . ':admin-test';
        $claims = (array)get_user_meta($user_id, self::CHALLENGE_META, true);
        if (isset($claims[$key])) {
            return new WP_REST_Response(['claimed' => true, 'already_claimed' => true, 'xp' => 0, 'claim' => $claims[$key]], 200);
        }

        $definition = $definitions[$id];
        $claim = [
            'challenge_id' => $id,
            'claim_key' => $key,
            'title' => $definition['title'],
            'xp' => $definition['xp'],
            'completed_by' => 'administrator_test_override',
            'claimed_at' => current_time('mysql', true),
            'admin_override' => true,
        ];
        $claims[$key] = $claim;
        update_user_meta($user_id, self::CHALLENGE_META, $claims);
        $this->award_xp($user_id, (int)$definition['xp'], (string)$definition['title']);
        do_action('tng_dynamic_challenge_claimed', $user_id, $id, $claim);

        return new WP_REST_Response(['claimed' => true, 'already_claimed' => false, 'xp' => (int)$definition['xp'], 'claim' => $claim], 200);
    }

    private function award_xp(int $user_id, int $xp, string $title): void {
        if (function_exists('gamipress_award_points_to_user')) {
            gamipress_award_points_to_user($user_id, $xp, 'xp', ['reason' => 'Admin test challenge: ' . $title]);
            return;
        }
        $current = absint(get_user_meta($user_id, '_gamipress_xp', true));
        update_user_meta($user_id, '_gamipress_xp', $current + $xp);
    }

    public function runtime_override(): void {
        if (!current_user_can('manage_options')) return;
        if (!isset($_GET['tng_quest_runtime_id']) && !is_singular('tng_quest')) return;
        ?>
        <script>
        (()=>{
            const apply=()=>{
                const root=document.querySelector('.tng-runtime');
                if(!root)return;
                const button=root.querySelector('.tng-next-claim[data-claim-current]');
                if(button){
                    button.disabled=false;
                    button.title='Administrator testing override';
                }
                const status=root.querySelector('.tng-location-status');
                const detail=root.querySelector('.tng-location-detail');
                if(status&&button){status.textContent='Admin test ready';status.classList.remove('tng-location-far');status.classList.add('tng-location-ready');}
                if(detail&&button&&!detail.textContent.includes('Admin override'))detail.textContent+=' · Admin override enabled';
            };
            apply();
            const observer=new MutationObserver(apply);
            const root=document.querySelector('.tng-runtime');
            if(root)observer.observe(root,{subtree:true,childList:true});
            setTimeout(()=>observer.disconnect(),120000);
        })();
        </script>
        <?php
    }

    public function challenge_override(): void {
        if (!current_user_can('manage_options') || !isset($_GET['tng_world'])) return;
        $endpoint = rest_url('tng/v1/admin-test/dynamic-challenge');
        $nonce = wp_create_nonce('wp_rest');
        ?>
        <style>.tng-admin-test-note{display:block;margin-top:5px;color:#f6bd3b;font-size:10px;font-weight:900}</style>
        <script>
        (()=>{
            const section=document.querySelector('.tng-dynamic-world');if(!section)return;
            const endpoint=<?php echo wp_json_encode($endpoint); ?>,nonce=<?php echo wp_json_encode($nonce); ?>;
            const titleKey=t=>String(t||'').toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_|_$/g,'');
            const decorate=()=>section.querySelectorAll('.tng-dynamic-card').forEach(card=>{
                const button=card.querySelector('.tng-challenge-button');if(!button)return;
                button.disabled=false;
                if(!card.querySelector('.tng-admin-test-note'))button.insertAdjacentHTML('afterend','<span class="tng-admin-test-note">Administrator test override enabled</span>');
            });
            decorate();
            new MutationObserver(decorate).observe(section,{subtree:true,childList:true});
            section.addEventListener('click',async e=>{
                const button=e.target.closest('.tng-challenge-button');if(!button)return;
                e.preventDefault();e.stopPropagation();e.stopImmediatePropagation();
                const card=button.closest('.tng-dynamic-card'),heading=card?.querySelector('h3');
                const title=heading?.childNodes[0]?.textContent.trim()||heading?.textContent.trim()||'';
                const challengeId=button.dataset.challengeId||titleKey(title);
                const message=card?.querySelector('.tng-challenge-message');
                button.disabled=true;button.textContent='Verifying admin test…';
                const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),12000);
                try{
                    const r=await fetch(endpoint,{method:'POST',credentials:'same-origin',signal:controller.signal,headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify({challenge_id:challengeId})});
                    const d=await r.json();if(!r.ok)throw new Error(d.message||'Admin test verification failed.');
                    button.textContent=d.already_claimed?'Already tested ✓':'Claimed +'+(d.xp||0)+' XP ✓';
                    button.classList.add('is-complete');
                    if(message)message.textContent=d.already_claimed?'This challenge was already tested for this period.':'Administrator test claim recorded.';
                    if(navigator.vibrate)navigator.vibrate([80,50,120]);
                }catch(err){
                    button.disabled=false;button.textContent='Retry admin test';
                    if(message)message.textContent=err.name==='AbortError'?'Verification timed out. Please retry.':err.message;
                }finally{clearTimeout(timer);}
            },true);
        })();
        </script>
        <?php
    }
}
