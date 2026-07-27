<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class Dynamic_Challenge_Claims implements Module_Interface {
    private const META = '_tng_dynamic_challenge_claims';
    private const DISCOVERY_META = '_tng_world_discovery_claims';
    private Container $container;

    public function id(): string { return 'dynamic_challenge_claims'; }

    public function register(Container $container): void {
        $this->container = $container;
        $container->set('dynamic_challenge_claims', $this);
        add_action('rest_api_init', [$this, 'routes']);
        add_action('wp_footer', [$this, 'enhance_world'], 160);
        add_action('admin_menu', [$this, 'menu'], 41);
    }

    public function boot(Container $container): void {}

    public function routes(): void {
        register_rest_route('tng/v1', '/dynamic-challenges', [
            'methods' => 'GET',
            'callback' => [$this, 'status'],
            'permission_callback' => static fn(): bool => is_user_logged_in(),
        ]);
        register_rest_route('tng/v1', '/dynamic-challenges/claim', [
            'methods' => 'POST',
            'callback' => [$this, 'claim'],
            'permission_callback' => static fn(): bool => is_user_logged_in(),
            'args' => ['challenge_id' => ['required' => true, 'sanitize_callback' => 'sanitize_key']],
        ]);
    }

    public function status(): WP_REST_Response {
        return new WP_REST_Response(['challenges' => $this->challenge_payload(get_current_user_id())], 200);
    }

    public function claim(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $id = sanitize_key((string)$request['challenge_id']);
        $definitions = $this->definitions();
        if (!isset($definitions[$id])) return new WP_Error('challenge_not_found', 'This challenge is no longer available.', ['status' => 404]);

        $claims = (array)get_user_meta($user_id, self::META, true);
        $claim_key = $definitions[$id]['claim_key'];
        if (isset($claims[$claim_key])) {
            return new WP_REST_Response(['claimed' => true, 'already_claimed' => true, 'claim' => $claims[$claim_key]], 200);
        }

        $verification = $this->verify($user_id, $definitions[$id]);
        if (!$verification['complete']) {
            return new WP_Error('challenge_incomplete', $verification['message'], ['status' => 409]);
        }

        $xp = (int)$definitions[$id]['xp'];
        $claim = [
            'challenge_id' => $id,
            'claim_key' => $claim_key,
            'title' => $definitions[$id]['title'],
            'xp' => $xp,
            'completed_by' => $verification['source'],
            'claimed_at' => current_time('mysql', true),
        ];
        $claims[$claim_key] = $claim;
        update_user_meta($user_id, self::META, $claims);
        $this->award_xp($user_id, $xp, $definitions[$id]['title']);
        do_action('tng_dynamic_challenge_claimed', $user_id, $id, $claim);

        return new WP_REST_Response(['claimed' => true, 'already_claimed' => false, 'xp' => $xp, 'claim' => $claim], 200);
    }

    private function challenge_payload(int $user_id): array {
        $claims = (array)get_user_meta($user_id, self::META, true);
        $out = [];
        foreach ($this->definitions() as $id => $definition) {
            $verification = $this->verify($user_id, $definition);
            $claim = $claims[$definition['claim_key']] ?? null;
            $out[$id] = [
                'id' => $id,
                'title' => $definition['title'],
                'xp' => (int)$definition['xp'],
                'expires_at' => $definition['expires_at'],
                'complete' => (bool)$verification['complete'],
                'claimed' => is_array($claim),
                'message' => is_array($claim) ? 'Bonus claimed.' : $verification['message'],
            ];
        }
        return $out;
    }

    private function definitions(): array {
        $now = current_datetime();
        $date = $now->format('Y-m-d');
        $hour = (int)$now->format('G');
        $day = (int)$now->format('w');
        $end_day = $now->setTime(23, 59, 59);
        $definitions = [];

        if ($hour < 9) {
            $definitions['morning_explorer'] = $this->definition('morning_explorer', 'Morning Explorer', 35, $date, $now->setTime(9, 0, 0), 'discovery_window', ['start' => $now->setTime(0, 0, 0), 'end' => $now->setTime(9, 0, 0)]);
        } elseif ($hour >= 18) {
            $definitions['night_explorer'] = $this->definition('night_explorer', 'Night Explorer', 40, $date, $end_day, 'discovery_window', ['start' => $now->setTime(18, 0, 0), 'end' => $end_day]);
        } else {
            $definitions['daylight_discovery'] = $this->definition('daylight_discovery', 'Daylight Discovery', 25, $date, $now->setTime(18, 0, 0), 'discovery_window', ['start' => $now->setTime(9, 0, 0), 'end' => $now->setTime(18, 0, 0)]);
        }

        if ($day === 0 || $day === 6) {
            $week = $now->format('o-W');
            $end = $day === 6 ? $now->modify('+1 day')->setTime(23, 59, 59) : $end_day;
            $definitions['weekend_adventure'] = $this->definition('weekend_adventure', 'Weekend Adventure', 50, $week, $end, 'weekend_activity');
        }

        $event = $this->first_event();
        if ($event) {
            $definitions['event_spotlight'] = $this->definition('event_spotlight', 'Event Spotlight', 45, $date . ':' . $event['id'], $end_day, 'specific_discovery', ['entity_id' => $event['id']]);
        }

        $quest_id = $this->first_quest_id();
        if ($quest_id) {
            $definitions['quest_of_the_day'] = $this->definition('quest_of_the_day', 'Quest of the Day', 75, $date . ':' . $quest_id, $end_day, 'quest_complete', ['quest_id' => $quest_id]);
        }

        if (count($definitions) < 3) {
            $definitions['first_visit_bonus'] = $this->definition('first_visit_bonus', 'First Visit Bonus', 25, $date, $end_day, 'any_discovery_today');
        }
        return array_slice($definitions, 0, 3, true);
    }

    private function definition(string $id, string $title, int $xp, string $period, \DateTimeImmutable $expires, string $rule, array $args = []): array {
        return array_merge($args, [
            'id' => $id,
            'title' => $title,
            'xp' => $xp,
            'rule' => $rule,
            'claim_key' => $id . ':' . $period,
            'expires_at' => $expires->format(DATE_ATOM),
        ]);
    }

    private function verify(int $user_id, array $definition): array {
        $rule = $definition['rule'];
        if ($rule === 'specific_discovery') {
            $claim = $this->discovery_claim($user_id, (string)$definition['entity_id']);
            $complete = $claim && $this->is_today((string)($claim['claimed_at'] ?? ''));
            return ['complete' => $complete, 'message' => $complete ? 'Discovery completed. Claim your bonus.' : 'Claim the featured event discovery today first.', 'source' => 'discovery:' . $definition['entity_id']];
        }
        if ($rule === 'quest_complete') {
            $complete = $this->quest_completed_today($user_id, (int)$definition['quest_id']);
            return ['complete' => $complete, 'message' => $complete ? 'Quest completed. Claim your bonus.' : 'Complete the featured quest today first.', 'source' => 'quest:' . (int)$definition['quest_id']];
        }
        if ($rule === 'weekend_activity') {
            $complete = $this->has_discovery_since($user_id, current_datetime()->modify('last saturday')->setTime(0, 0, 0));
            return ['complete' => $complete, 'message' => $complete ? 'Weekend activity verified. Claim your bonus.' : 'Complete a discovery this weekend first.', 'source' => 'weekend_discovery'];
        }
        if ($rule === 'discovery_window') {
            $complete = $this->has_discovery_between($user_id, $definition['start'], $definition['end']);
            return ['complete' => $complete, 'message' => $complete ? 'Timed discovery verified. Claim your bonus.' : 'Complete a discovery during the active time window.', 'source' => 'timed_discovery'];
        }
        $complete = $this->has_discovery_today($user_id);
        return ['complete' => $complete, 'message' => $complete ? 'First visit verified. Claim your bonus.' : 'Claim any new discovery today first.', 'source' => 'daily_discovery'];
    }

    private function discovery_claim(int $user_id, string $entity_id): ?array {
        $claims = (array)get_user_meta($user_id, self::DISCOVERY_META, true);
        return isset($claims[$entity_id]) && is_array($claims[$entity_id]) ? $claims[$entity_id] : null;
    }

    private function has_discovery_today(int $user_id): bool {
        foreach ((array)get_user_meta($user_id, self::DISCOVERY_META, true) as $claim) {
            if (is_array($claim) && $this->is_today((string)($claim['claimed_at'] ?? ''))) return true;
        }
        return false;
    }

    private function has_discovery_since(int $user_id, \DateTimeImmutable $start): bool {
        return $this->has_discovery_between($user_id, $start, current_datetime()->setTime(23, 59, 59));
    }

    private function has_discovery_between(int $user_id, \DateTimeImmutable $start, \DateTimeImmutable $end): bool {
        foreach ((array)get_user_meta($user_id, self::DISCOVERY_META, true) as $claim) {
            if (!is_array($claim) || empty($claim['claimed_at'])) continue;
            $local = $this->local_datetime((string)$claim['claimed_at']);
            if ($local && $local >= $start && $local <= $end) return true;
        }
        return false;
    }

    private function is_today(string $gmt): bool {
        $local = $this->local_datetime($gmt);
        return $local && $local->format('Y-m-d') === current_datetime()->format('Y-m-d');
    }

    private function local_datetime(string $gmt): ?\DateTimeImmutable {
        if ($gmt === '') return null;
        try { return new \DateTimeImmutable(get_date_from_gmt($gmt, 'Y-m-d H:i:s'), wp_timezone()); }
        catch (\Throwable $e) { return null; }
    }

    private function quest_completed_today(int $user_id, int $quest_id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'tng_player_progress';
        $completed = $wpdb->get_var($wpdb->prepare("SELECT completed_at FROM {$table} WHERE user_id=%d AND quest_id=%d AND status=%s", $user_id, $quest_id, 'completed'));
        return is_string($completed) && $this->is_today($completed);
    }

    private function first_event(): ?array {
        $engine = $this->container->get('recommendation_engine');
        $entities = $engine && is_callable([$engine, 'entities']) ? $engine->entities() : [];
        foreach ($entities as $id => $entity) {
            $type = sanitize_key((string)($entity['type'] ?? ''));
            if (in_array($type, ['event', 'concert'], true)) return ['id' => (string)$id, 'title' => (string)($entity['title'] ?? 'Featured event')];
        }
        return null;
    }

    private function first_quest_id(): int {
        $ids = get_posts(['post_type' => 'tng_quest', 'post_status' => 'publish', 'posts_per_page' => 1, 'orderby' => 'date', 'order' => 'ASC', 'fields' => 'ids']);
        return $ids ? (int)$ids[0] : 0;
    }

    private function award_xp(int $user_id, int $xp, string $title): void {
        if (function_exists('gamipress_award_points_to_user')) {
            gamipress_award_points_to_user($user_id, $xp, 'xp', ['reason' => 'Dynamic challenge: ' . $title]);
            return;
        }
        $current = absint(get_user_meta($user_id, '_gamipress_xp', true));
        update_user_meta($user_id, '_gamipress_xp', $current + $xp);
    }

    public function enhance_world(): void {
        if (!isset($_GET['tng_world'])) return;
        $logged_in = is_user_logged_in();
        ?>
        <style>
            .tng-dynamic-card{display:flex;flex-direction:column}.tng-challenge-action{margin-top:auto;padding-top:12px}.tng-challenge-button{width:100%;border:1px solid rgba(255,255,255,.25);background:#7f56d9;color:#fff;border-radius:11px;padding:9px 11px;font:inherit;font-weight:900;cursor:pointer}.tng-challenge-button[disabled]{opacity:.65;cursor:not-allowed}.tng-challenge-button.is-complete{background:#12b76a}.tng-challenge-message{display:block;margin-top:7px;color:rgba(255,255,255,.7);font-size:11px;line-height:1.35}.tng-challenge-expiry{display:block;margin-top:4px;color:#f6bd3b;font-size:10px;font-weight:800}.tng-challenge-toast{position:fixed;z-index:1700;top:20px;left:50%;transform:translate(-50%,-140%);background:#067647;color:#fff;padding:13px 18px;border-radius:999px;font-weight:900;box-shadow:0 12px 30px rgba(0,0,0,.22);transition:.25s}.tng-challenge-toast.is-visible{transform:translate(-50%,0)}
        </style>
        <script>
        (()=>{
            const section=document.querySelector('.tng-dynamic-world');if(!section||section.dataset.claimsEnhanced)return;section.dataset.claimsEnhanced='1';
            const loggedIn=<?php echo $logged_in ? 'true' : 'false'; ?>;
            const statusUrl=<?php echo wp_json_encode(rest_url('tng/v1/dynamic-challenges')); ?>;
            const claimUrl=<?php echo wp_json_encode(rest_url('tng/v1/dynamic-challenges/claim')); ?>;
            const nonce=<?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
            const titleKey=t=>String(t||'').toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_|_$/g,'');
            const toast=document.createElement('div');toast.className='tng-challenge-toast';document.body.append(toast);
            const showToast=t=>{toast.textContent=t;toast.classList.add('is-visible');setTimeout(()=>toast.classList.remove('is-visible'),2800)};
            let challenges={};
            const render=()=>section.querySelectorAll('.tng-dynamic-card').forEach(card=>{
                const heading=card.querySelector('h3');if(!heading)return;
                const title=heading.childNodes[0]?.textContent.trim()||heading.textContent.trim();
                const id=titleKey(title),challenge=challenges[id];if(!challenge)return;
                let action=card.querySelector('.tng-challenge-action');if(!action){action=document.createElement('div');action.className='tng-challenge-action';action.innerHTML='<button type="button" class="tng-challenge-button"></button><span class="tng-challenge-message"></span><span class="tng-challenge-expiry"></span>';card.append(action);}
                const button=action.querySelector('button'),message=action.querySelector('.tng-challenge-message'),expiry=action.querySelector('.tng-challenge-expiry');
                button.dataset.challengeId=id;button.disabled=challenge.claimed||!loggedIn;button.classList.toggle('is-complete',challenge.complete&&!challenge.claimed);
                button.textContent=challenge.claimed?'Bonus claimed ✓':!loggedIn?'Log in to participate':challenge.complete?'Claim +'+challenge.xp+' XP':'Check progress';
                message.textContent=challenge.message||'';
                const expires=new Date(challenge.expires_at);expiry.textContent=Number.isNaN(expires.getTime())?'':'Expires '+expires.toLocaleString([], {hour:'numeric',minute:'2-digit',month:'short',day:'numeric'});
            });
            const load=async()=>{if(!loggedIn){render();return;}try{const r=await fetch(statusUrl,{headers:{'X-WP-Nonce':nonce}});const d=await r.json();challenges=d.challenges||{};render();}catch(e){}};
            section.addEventListener('click',async e=>{const button=e.target.closest('[data-challenge-id]');if(!button||button.disabled)return;button.disabled=true;button.textContent='Verifying…';try{const r=await fetch(claimUrl,{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify({challenge_id:button.dataset.challengeId})});const d=await r.json();if(!r.ok)throw new Error(d.message||'Challenge is not complete yet.');showToast('+'+(d.xp||d.claim?.xp||0)+' XP · Challenge complete');if(navigator.vibrate)navigator.vibrate([90,50,140]);await load();}catch(err){button.disabled=false;button.textContent='Check progress';const action=button.closest('.tng-challenge-action');action.querySelector('.tng-challenge-message').textContent=err.message;}});
            const wait=setInterval(()=>{if(section.querySelector('.tng-dynamic-card')){clearInterval(wait);load();}},100);setTimeout(()=>clearInterval(wait),5000);
        })();
        </script><?php
    }

    public function menu(): void {
        add_submenu_page('tn-game-os', 'Dynamic Challenge Claims', 'Challenge Claims', 'manage_options', 'tng-dynamic-challenge-claims', [$this, 'page']);
    }

    public function page(): void {
        if (!current_user_can('manage_options')) return;
        $rows = [];
        foreach (get_users(['meta_key' => self::META]) as $user) {
            foreach ((array)get_user_meta($user->ID, self::META, true) as $claim) if (is_array($claim)) $rows[] = array_merge($claim, ['user' => $user->display_name]);
        }
        usort($rows, static fn($a, $b) => strcmp((string)($b['claimed_at'] ?? ''), (string)($a['claimed_at'] ?? '')));
        echo '<div class="wrap"><h1>Dynamic Challenge Claims</h1><p>Verified daily, timed, event, weekend, and quest bonus awards.</p><table class="widefat striped"><thead><tr><th>Player</th><th>Challenge</th><th>XP</th><th>Verified by</th><th>Claimed</th></tr></thead><tbody>';
        foreach ($rows as $row) echo '<tr><td>'.esc_html($row['user']).'</td><td>'.esc_html($row['title']).'</td><td>'.esc_html((string)$row['xp']).'</td><td>'.esc_html((string)$row['completed_by']).'</td><td>'.esc_html((string)$row['claimed_at']).'</td></tr>';
        if (!$rows) echo '<tr><td colspan="5">No dynamic challenge bonuses have been claimed yet.</td></tr>';
        echo '</tbody></table></div>';
    }
}
