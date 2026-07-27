<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (!defined('ABSPATH')) exit;

final class World_Discovery_Claims implements Module_Interface {
    private const ENTITY_TYPE = 'tng_entity';
    private const META = '_tng_world_discovery_claims';
    private const MAX_DISTANCE_FEET = 500;
    private const PAGE = 'tng-discovery-claims';

    public function id(): string { return 'world_discovery_claims'; }

    public function register(Container $container): void {
        $container->set('world_discovery_claims', $this);
        add_action('rest_api_init', [$this, 'routes']);
        add_action('wp_footer', [$this, 'enhance_world'], 95);
        add_action('admin_menu', [$this, 'menu'], 40);
    }

    public function boot(Container $container): void {}

    public function routes(): void {
        register_rest_route('tng/v1', '/world/claim', [
            'methods' => 'POST',
            'callback' => [$this, 'claim'],
            'permission_callback' => static function (): bool { return is_user_logged_in(); },
            'args' => [
                'entity_id' => ['required'=>true, 'sanitize_callback'=>'sanitize_text_field'],
                'latitude' => ['required'=>true, 'validate_callback'=>'is_numeric'],
                'longitude' => ['required'=>true, 'validate_callback'=>'is_numeric'],
                'accuracy' => ['required'=>false, 'validate_callback'=>'is_numeric'],
            ],
        ]);
        register_rest_route('tng/v1', '/world/claims', [
            'methods' => 'GET',
            'callback' => [$this, 'claims'],
            'permission_callback' => static function (): bool { return is_user_logged_in(); },
        ]);
    }

    public function claim(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $entity_id = sanitize_text_field((string)$request['entity_id']);
        $entity = $this->entity($entity_id);
        if (!$entity) return new WP_Error('not_found', 'Discovery was not found.', ['status'=>404]);
        if (in_array($entity['type'], ['quest'], true)) return new WP_Error('quest_not_claimable', 'Start the quest to earn its rewards.', ['status'=>400]);

        $claims = $this->user_claims($user_id);
        if (isset($claims[$entity_id])) return new WP_REST_Response(['claimed'=>true,'already_claimed'=>true,'claim'=>$claims[$entity_id]], 200);

        $lat = (float)$request['latitude'];
        $lng = (float)$request['longitude'];
        $accuracy = max(0, (float)($request['accuracy'] ?? 0));
        $distance = $this->distance_feet($lat, $lng, $entity['lat'], $entity['lng']);
        $is_admin = current_user_can('manage_options');
        if (!$is_admin && $distance > self::MAX_DISTANCE_FEET) {
            return new WP_Error('too_far', sprintf('Move within %d ft to claim this discovery.', self::MAX_DISTANCE_FEET), ['status'=>403,'distance_feet'=>round($distance)]);
        }

        $xp = in_array($entity['type'], ['event','concert'], true) ? 100 : 25;
        $claim = [
            'entity_id'=>$entity_id,
            'title'=>$entity['title'],
            'type'=>$entity['type'],
            'xp'=>$xp,
            'distance_feet'=>round($distance),
            'accuracy_feet'=>round($accuracy * 3.28084),
            'claimed_at'=>current_time('mysql', true),
            'admin_override'=>$is_admin && $distance > self::MAX_DISTANCE_FEET,
        ];
        $claims[$entity_id] = $claim;
        update_user_meta($user_id, self::META, $claims);
        $this->award_xp($user_id, $xp, $entity['title']);
        do_action('tng_world_discovery_claimed', $user_id, $entity_id, $claim);

        return new WP_REST_Response(['claimed'=>true,'already_claimed'=>false,'xp'=>$xp,'claim'=>$claim,'total_claims'=>count($claims)], 200);
    }

    public function claims(): WP_REST_Response {
        return new WP_REST_Response(['claims'=>$this->user_claims(get_current_user_id())], 200);
    }

    private function award_xp(int $user_id, int $xp, string $title): void {
        if (function_exists('gamipress_award_points_to_user')) {
            gamipress_award_points_to_user($user_id, $xp, 'xp', ['reason'=>'World discovery: '.$title]);
            return;
        }
        $current = absint(get_user_meta($user_id, '_gamipress_xp', true));
        update_user_meta($user_id, '_gamipress_xp', $current + $xp);
    }

    private function entity(string $entity_id): ?array {
        $posts = get_posts(['post_type'=>self::ENTITY_TYPE,'post_status'=>['publish','draft','private'],'posts_per_page'=>1,'meta_key'=>'_tng_entity_id','meta_value'=>$entity_id]);
        if (!$posts) return null;
        $post = $posts[0];
        $payload = (array)get_post_meta($post->ID, '_tng_entity_payload', true);
        $lat = $payload['latitude'] ?? $payload['lat'] ?? ($payload['coordinates']['lat'] ?? null);
        $lng = $payload['longitude'] ?? $payload['lng'] ?? ($payload['coordinates']['lng'] ?? null);
        if (!is_numeric($lat) || !is_numeric($lng)) return null;
        return ['id'=>$entity_id,'title'=>$post->post_title,'type'=>sanitize_key((string)get_post_meta($post->ID,'_tng_entity_type',true)) ?: 'place','lat'=>(float)$lat,'lng'=>(float)$lng];
    }

    private function user_claims(int $user_id): array { return $user_id ? (array)get_user_meta($user_id, self::META, true) : []; }

    private function distance_feet(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $earth = 3958.8; $p = M_PI / 180;
        $dlat = ($lat2-$lat1)*$p; $dlng = ($lng2-$lng1)*$p;
        $a = sin($dlat/2)**2 + cos($lat1*$p)*cos($lat2*$p)*sin($dlng/2)**2;
        return $earth * 2 * atan2(sqrt($a), sqrt(1-$a)) * 5280;
    }

    public function enhance_world(): void {
        if (!isset($_GET['tng_world'])) return;
        $logged_in = is_user_logged_in();
        ?>
        <style>.tng-world-sheet-claim{border:0;background:#12b76a;color:#fff}.tng-world-sheet-claim[disabled]{background:#98a2b3;cursor:not-allowed}.tng-world-claim-status{margin:10px 0 0;font-size:13px;font-weight:800;color:#475467}.tng-world-item.is-claimed{border-color:#6ce9a6;background:#ecfdf3}.tng-world-marker.is-claimed{background:#12b76a!important}.tng-world-claim-toast{position:fixed;z-index:1500;top:20px;left:50%;transform:translate(-50%,-140%);background:#067647;color:#fff;padding:13px 18px;border-radius:999px;font-weight:900;box-shadow:0 12px 30px rgba(0,0,0,.2);transition:.25s}.tng-world-claim-toast.is-visible{transform:translate(-50%,0)}</style>
        <script>
        (()=>{
            const world=document.querySelector('.tng-world'); if(!world) return;
            const loggedIn=<?php echo $logged_in ? 'true' : 'false'; ?>;
            const endpoint=<?php echo wp_json_encode(rest_url('tng/v1/world/claim')); ?>;
            const claimsEndpoint=<?php echo wp_json_encode(rest_url('tng/v1/world/claims')); ?>;
            const nonce=<?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
            const toast=document.createElement('div');toast.className='tng-world-claim-toast';document.body.append(toast);
            const showToast=t=>{toast.textContent=t;toast.classList.add('is-visible');setTimeout(()=>toast.classList.remove('is-visible'),2600)};
            let position=null,accuracy=0,claims={};
            if(navigator.geolocation) navigator.geolocation.watchPosition(p=>{position={lat:p.coords.latitude,lng:p.coords.longitude};accuracy=p.coords.accuracy||0;},{},{enableHighAccuracy:true,maximumAge:3000,timeout:15000});
            const markClaimed=()=>{world.querySelectorAll('.tng-world-item').forEach(card=>{const title=card.querySelector('strong')?.textContent.trim();if(Object.values(claims).some(c=>c.title===title))card.classList.add('is-claimed');});};
            if(loggedIn) fetch(claimsEndpoint,{headers:{'X-WP-Nonce':nonce}}).then(r=>r.json()).then(d=>{claims=d.claims||{};markClaimed();}).catch(()=>{});
            const observer=new MutationObserver(()=>{
                const sheet=document.querySelector('.tng-world-discovery-sheet.is-open');if(!sheet)return;
                const primary=sheet.querySelector('[data-sheet-primary]');if(!primary||sheet.querySelector('[data-world-claim]'))return;
                const title=sheet.querySelector('[data-sheet-title]')?.textContent.trim();
                const dataNode=world.querySelector('.tng-world-data');let data={};try{data=JSON.parse(dataNode?.textContent||'{}')}catch(e){}
                const item=[...(data.entities||[]),...(data.quests||[])].find(x=>x.title===title);if(!item)return;
                const kind=item.kind||(['event','concert'].includes(item.type)?'event':((data.quests||[]).includes(item)?'quest':'place'));
                if(kind==='quest')return;
                const button=document.createElement('button');button.className='tng-world-sheet-claim';button.dataset.worldClaim='1';button.textContent=claims[item.id]?'Claimed ✓':'Claim discovery';button.disabled=!!claims[item.id];
                const status=document.createElement('div');status.className='tng-world-claim-status';status.textContent=claims[item.id]?`Claimed · +${claims[item.id].xp} XP`:`Get within <?php echo self::MAX_DISTANCE_FEET; ?> ft to claim.`;
                primary.replaceWith(button);sheet.querySelector('.tng-world-sheet-actions').insertAdjacentElement('afterend',status);
                button.onclick=async()=>{
                    if(!loggedIn){status.textContent='Log in to save discoveries and earn XP.';return;}
                    if(!position){status.textContent='Waiting for your GPS location…';return;}
                    button.disabled=true;button.textContent='Verifying location…';
                    try{const r=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify({entity_id:item.id,latitude:position.lat,longitude:position.lng,accuracy})});const d=await r.json();if(!r.ok)throw new Error(d.message||'Unable to claim discovery.');claims[item.id]=d.claim;button.textContent='Claimed ✓';status.textContent=`Discovery claimed · +${d.xp||d.claim?.xp||0} XP`;markClaimed();showToast(`+${d.xp||d.claim?.xp||0} XP · ${item.title}`);if(navigator.vibrate)navigator.vibrate([80,50,120]);}catch(e){button.disabled=false;button.textContent='Claim discovery';status.textContent=e.message;}
                };
            });observer.observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['class']});
        })();
        </script><?php
    }

    public function menu(): void { add_submenu_page('tn-game-os','Discovery Claims','Discovery Claims','manage_options',self::PAGE,[$this,'page']); }
    public function page(): void {
        if (!current_user_can('manage_options')) return;
        $users=get_users(['meta_key'=>self::META]); $rows=[];
        foreach($users as $user) foreach($this->user_claims($user->ID) as $claim) $rows[]=array_merge($claim,['user'=>$user->display_name]);
        usort($rows,static fn($a,$b)=>strcmp((string)($b['claimed_at']??''),(string)($a['claimed_at']??'')));
        echo '<div class="wrap"><h1>Discovery Claims</h1><p>Verified, one-time World Engine discoveries and XP awards.</p><table class="widefat striped"><thead><tr><th>Player</th><th>Discovery</th><th>Type</th><th>XP</th><th>Distance</th><th>Claimed</th></tr></thead><tbody>';
        foreach($rows as $r) echo '<tr><td>'.esc_html($r['user']).'</td><td>'.esc_html($r['title']).'</td><td>'.esc_html($r['type']).'</td><td>'.esc_html((string)$r['xp']).'</td><td>'.esc_html((string)$r['distance_feet']).' ft</td><td>'.esc_html($r['claimed_at']).'</td></tr>';
        if(!$rows)echo '<tr><td colspan="6">No discovery claims yet.</td></tr>';
        echo '</tbody></table></div>';
    }
}
