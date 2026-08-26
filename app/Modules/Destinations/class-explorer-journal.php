<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Explorer_Journal implements Module_Interface {
    private const META_RECAPS = '_tng_trip_recaps';
    private const META_REACTIONS = '_tng_journal_reactions';

    public function id(): string { return 'explorer_journal'; }

    public function register(Container $container): void {
        $container->set('explorer_journal', $this);
        add_shortcode('tng_explorer_journal', [$this, 'shortcode']);
        add_shortcode('tng_explorer_profile', [$this, 'profile_shortcode']);
        add_action('wp_ajax_tng_journal_react', [$this, 'ajax_react']);
        add_action('admin_menu', [$this, 'admin_menu'], 82);
    }

    public function boot(Container $container): void {}

    public function admin_menu(): void {
        add_submenu_page('tn-game-os', 'Explorer Journal', 'Explorer Journal', 'manage_options', 'tng-os-explorer-journal', [$this, 'admin_page']);
    }

    public function admin_page(): void {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap"><h1>Explorer Journal</h1>';
        echo '<p>Create a private Journal page with <code>[tng_explorer_journal]</code>.</p>';
        echo '<p>Create a shareable Explorer Profile page with <code>[tng_explorer_profile]</code>. Add <code>?explorer=username</code> to show a specific Explorer.</p>';
        echo '<p>Journal cards now support images, XP, expandable details, reactions, and sharing.</p></div>';
    }

    public function profile_shortcode($atts = []): string {
        $atts = shortcode_atts(['title' => 'Explorer Profile', 'user' => ''], $atts, 'tng_explorer_profile');
        $requested = sanitize_text_field($_GET['explorer'] ?? $atts['user']);
        if (!$requested && is_user_logged_in()) $requested = (string) get_current_user_id();
        return $this->render_journal($atts['title'], $requested, true);
    }

    public function shortcode($atts = []): string {
        $atts = shortcode_atts(['title' => 'Explorer Journal', 'user' => ''], $atts, 'tng_explorer_journal');
        return $this->render_journal($atts['title'], $atts['user'], false);
    }

    private function render_journal(string $title, string $requested_user, bool $public_profile): string {
        $user_id = $this->resolve_user($requested_user);
        if (!$user_id) {
            if (!$public_profile && !is_user_logged_in()) return $this->signed_out();
            return '<section class="tng-journal-shell"><div class="tng-journal-empty"><h2>Explorer not found</h2><p>This Explorer profile is unavailable.</p></div></section>';
        }
        if ($public_profile && get_user_meta($user_id, '_tng_profile_private', true) === '1' && get_current_user_id() !== $user_id) {
            return '<section class="tng-journal-shell"><div class="tng-journal-empty"><h2>Private Explorer</h2><p>This Explorer has chosen to keep their story private.</p></div></section>';
        }

        $user = get_userdata($user_id);
        $events = apply_filters('tng_os_adventure_journal_events', [], $user_id);
        $events = is_array($events) ? $this->normalize_events($events) : [];
        $recaps = get_user_meta($user_id, self::META_RECAPS, true);
        $recaps = is_array($recaps) ? $recaps : [];
        [$trips, $activities, $achievements, $photos] = $this->categorize($events, $recaps);

        $all = array_merge($trips, $activities, $achievements, $photos);
        foreach (['all','trips','activities','achievements','photos'] as $name) {
            if ($name === 'all') usort($all, [$this, 'sort_events']);
            elseif ($name === 'trips') usort($trips, [$this, 'sort_events']);
            elseif ($name === 'activities') usort($activities, [$this, 'sort_events']);
            elseif ($name === 'achievements') usort($achievements, [$this, 'sort_events']);
            else usort($photos, [$this, 'sort_events']);
        }

        $stats = apply_filters('tng_os_explorer_profile_stats', [], $user_id);
        $stats = is_array($stats) ? $stats : [];
        $trip_count = max(count($trips), absint($stats['completed_trips'] ?? 0));
        $checkpoint_count = max(absint($stats['checkpoints'] ?? 0), absint($stats['trip_stops'] ?? 0));
        $xp = absint($stats['xp'] ?? $stats['total_xp'] ?? 0);
        $badge_count = max(count($achievements), absint($stats['achievements'] ?? 0));
        $display_name = $user ? $user->display_name : 'TN Game Explorer';
        $profile_url = add_query_arg('explorer', rawurlencode($user ? $user->user_login : (string) $user_id), get_permalink());
        $reaction_map = get_user_meta($user_id, self::META_REACTIONS, true);
        $reaction_map = is_array($reaction_map) ? $reaction_map : [];

        ob_start(); ?>
        <section class="tng-journal-shell" data-tng-journal data-owner="<?php echo esc_attr($user_id); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('tng_journal_react')); ?>">
            <?php echo $this->styles(); ?>
            <div class="tng-journal-hero">
                <div class="tng-journal-profile-head">
                    <div class="tng-journal-avatar"><?php echo get_avatar($user_id, 88); ?></div>
                    <div><div class="tng-journal-kicker">TN Game Explorer</div><h1><?php echo esc_html($public_profile ? $display_name : $title); ?></h1>
                    <p><?php echo esc_html($public_profile ? 'Explore this Tennessee story, discoveries, and milestones.' : 'Every real-world discovery becomes part of your Tennessee story.'); ?></p></div>
                </div>
                <button type="button" class="tng-journal-share" data-share-profile data-url="<?php echo esc_url($profile_url); ?>">Share profile</button>
            </div>
            <div class="tng-journal-stats">
                <?php echo $this->stat($trip_count, 'Trips'); echo $this->stat($checkpoint_count, 'Places visited'); echo $this->stat($xp, 'XP'); echo $this->stat($badge_count, 'Achievements'); ?>
            </div>
            <div class="tng-journal-tabs" role="tablist">
                <?php foreach (['all'=>'All','trips'=>'Trips','activities'=>'Activities','achievements'=>'Achievements','photos'=>'Photos'] as $key=>$label): ?>
                    <button type="button" class="tng-journal-tab<?php echo $key === 'all' ? ' is-active' : ''; ?>" data-journal-tab="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></button>
                <?php endforeach; ?>
            </div>
            <?php $groups = ['all'=>$all,'trips'=>$trips,'activities'=>$activities,'achievements'=>$achievements,'photos'=>$photos];
            foreach ($groups as $key => $items): ?>
                <div class="tng-journal-panel<?php echo $key === 'all' ? ' is-active' : ''; ?>" data-journal-panel="<?php echo esc_attr($key); ?>">
                    <?php echo $this->render_events($items, $key, $user_id, $reaction_map); ?>
                </div>
            <?php endforeach; ?>
            <?php echo $this->scripts(); ?>
        </section>
        <?php return (string) ob_get_clean();
    }

    private function resolve_user(string $requested): int {
        if (!$requested) return is_user_logged_in() ? get_current_user_id() : 0;
        if (ctype_digit($requested)) return get_userdata(absint($requested)) ? absint($requested) : 0;
        $user = get_user_by('login', $requested);
        if (!$user) $user = get_user_by('slug', $requested);
        return $user ? absint($user->ID) : 0;
    }

    private function signed_out(): string {
        return '<section class="tng-journal-shell"><div class="tng-journal-empty"><h2>Explorer Journal</h2><p>Sign in to view your Tennessee adventure story.</p></div></section>';
    }

    private function categorize(array $events, array $recaps): array {
        $trips=[]; $activities=[]; $achievements=[]; $photos=[];
        foreach ($events as $event) {
            $type = sanitize_key($event['type'] ?? 'activity');
            if ($type === 'trip_completed' || str_contains($type, 'trip')) $trips[]=$event;
            elseif (str_contains($type, 'badge') || str_contains($type, 'achievement') || str_contains($type, 'rank')) $achievements[]=$event;
            elseif (str_contains($type, 'photo') || str_contains($type, 'image')) $photos[]=$event;
            else $activities[]=$event;
        }
        foreach ($recaps as $recap) {
            if (!is_array($recap)) continue;
            $key='trip:'.sanitize_text_field($recap['id'] ?? ''); $exists=false;
            foreach ($trips as $trip) if (($trip['id'] ?? '') === $key) {$exists=true;break;}
            if ($exists) continue;
            $trips[]=['id'=>$key,'type'=>'trip_completed','title'=>sanitize_text_field($recap['title'] ?? 'My Tennessee adventure'),'description'=>sprintf('%d stops · %s',absint($recap['stop_count'] ?? 0),$this->duration(absint($recap['minutes'] ?? 0))),'date'=>sanitize_text_field($recap['date'] ?? ''),'meta'=>$recap];
        }
        return [$trips,$activities,$achievements,$photos];
    }

    private function normalize_events(array $events): array {
        $out=[];
        foreach ($events as $event) {
            if (!is_array($event)) continue;
            $event['id']=sanitize_text_field($event['id'] ?? wp_generate_uuid4());
            $event['type']=sanitize_key($event['type'] ?? 'activity');
            $event['title']=sanitize_text_field($event['title'] ?? 'Explorer activity');
            $event['description']=sanitize_text_field($event['description'] ?? $event['message'] ?? '');
            $event['date']=sanitize_text_field($event['date'] ?? '');
            $event['meta']=is_array($event['meta'] ?? null) ? $event['meta'] : [];
            $out[]=$event;
        }
        return $out;
    }

    private function render_events(array $items, string $group, int $owner_id, array $reaction_map): string {
        if (!$items) {
            $messages=['trips'=>'Completed itineraries will appear here.','activities'=>'Checkpoint claims, quests, and discoveries will appear here.','achievements'=>'Badges, ranks, and milestones will appear here.','photos'=>'Approved Explorer photos will become part of your story here.','all'=>'Start exploring to create your first journal memory.'];
            return '<div class="tng-journal-empty">'.esc_html($messages[$group] ?? $messages['all']).'</div>';
        }
        $html=''; $last_year='';
        foreach ($items as $event) {
            $date=$event['date'] ?? ''; $ts=$date ? strtotime($date) : 0; $year=$ts ? wp_date('Y',$ts) : wp_date('Y');
            if ($year !== $last_year) {$html.='<h2 class="tng-journal-year">'.esc_html($year).'</h2>'; $last_year=$year;}
            $type=sanitize_key($event['type'] ?? 'activity'); $meta=is_array($event['meta'] ?? null)?$event['meta']:[]; $id=sanitize_text_field($event['id'] ?? '');
            $image=$this->event_image($meta); $details=$this->event_details($meta); $reactions=is_array($reaction_map[$id] ?? null)?$reaction_map[$id]:[];
            $html.='<article class="tng-journal-card" data-event-id="'.esc_attr($id).'">';
            if ($image) $html.='<div class="tng-journal-media"><img src="'.esc_url($image).'" alt=""></div>';
            else $html.='<div class="tng-journal-icon">'.esc_html($this->icon($type)).'</div>';
            $html.='<div class="tng-journal-content"><h3>'.esc_html($event['title'] ?? 'Explorer activity').'</h3><p>'.esc_html($event['description'] ?? '').'</p>';
            $chips=[];
            if (!empty($meta['xp'])) $chips[]='+'.absint($meta['xp']).' XP';
            if (!empty($meta['stop_count'])) $chips[]=absint($meta['stop_count']).' stops';
            if (!empty($meta['minutes'])) $chips[]=$this->duration(absint($meta['minutes']));
            if (!empty($meta['streak'])) $chips[]=absint($meta['streak']).' day streak';
            if (!empty($meta['badge'])) $chips[]='★ '.sanitize_text_field($meta['badge']);
            if ($chips) {$html.='<div class="tng-journal-meta">'; foreach($chips as $chip)$html.='<span class="tng-journal-chip">'.esc_html($chip).'</span>'; $html.='</div>';}
            if ($details) $html.='<button type="button" class="tng-journal-details-btn" data-toggle-details>View details</button><div class="tng-journal-details" hidden>'.$details.'</div>';
            $html.=$this->reaction_buttons($id,$reactions,$owner_id);
            $html.='</div><time class="tng-journal-date">'.esc_html($ts?wp_date(get_option('date_format'),$ts):'').'</time></article>';
        }
        return $html;
    }

    private function event_image(array $meta): string {
        if (!empty($meta['image'])) return esc_url_raw($meta['image']);
        $attachment=absint($meta['attachment_id'] ?? 0); if ($attachment) return (string) wp_get_attachment_image_url($attachment,'medium');
        $post_id=absint($meta['post_id'] ?? $meta['object_id'] ?? 0); if ($post_id && has_post_thumbnail($post_id)) return (string) get_the_post_thumbnail_url($post_id,'medium');
        $object=sanitize_text_field($meta['object'] ?? '');
        if (preg_match('/(?:checkpoint|quest|post):(\d+)/',$object,$m) && has_post_thumbnail(absint($m[1]))) return (string)get_the_post_thumbnail_url(absint($m[1]),'medium');
        return '';
    }

    private function event_details(array $meta): string {
        $rows=[];
        if (!empty($meta['stops']) && is_array($meta['stops'])) foreach($meta['stops'] as $i=>$stop) $rows[]=(absint($i)+1).'. '.sanitize_text_field(is_array($stop)?($stop['title']??'Stop'):$stop);
        if (!empty($meta['object'])) $rows[]='Record: '.sanitize_text_field($meta['object']);
        if (!empty($meta['badge'])) $rows[]='Milestone: '.sanitize_text_field($meta['badge']);
        if (!$rows) return '';
        return '<ul><li>'.implode('</li><li>',array_map('esc_html',$rows)).'</li></ul>';
    }

    private function reaction_buttons(string $event_id, array $reactions, int $owner_id): string {
        $types=['cheer'=>'👏 Cheer','fire'=>'🔥 Fire','amazing'=>'✨ Amazing']; $html='<div class="tng-journal-reactions">';
        foreach($types as $key=>$label){$users=is_array($reactions[$key]??null)?$reactions[$key]:[];$active=is_user_logged_in()&&in_array(get_current_user_id(),array_map('absint',$users),true);$html.='<button type="button" class="tng-journal-reaction'.($active?' is-active':'').'" data-reaction="'.esc_attr($key).'" data-owner="'.esc_attr($owner_id).'">'.esc_html($label).' <span>'.count($users).'</span></button>';}
        return $html.'</div>';
    }

    public function ajax_react(): void {
        check_ajax_referer('tng_journal_react','nonce');
        if (!is_user_logged_in()) wp_send_json_error(['message'=>'Sign in to react.'],401);
        $owner=absint($_POST['owner']??0); $event=sanitize_text_field($_POST['event']??''); $reaction=sanitize_key($_POST['reaction']??'');
        if (!$owner || !$event || !in_array($reaction,['cheer','fire','amazing'],true)) wp_send_json_error(['message'=>'Invalid reaction.'],400);
        $map=get_user_meta($owner,self::META_REACTIONS,true); $map=is_array($map)?$map:[]; $user_id=get_current_user_id();
        foreach(['cheer','fire','amazing'] as $type){$list=is_array($map[$event][$type]??null)?array_map('absint',$map[$event][$type]):[];$map[$event][$type]=array_values(array_diff($list,[$user_id]));}
        $map[$event][$reaction][]=$user_id; $map[$event][$reaction]=array_values(array_unique(array_map('absint',$map[$event][$reaction])));
        update_user_meta($owner,self::META_REACTIONS,$map);
        wp_send_json_success(['counts'=>['cheer'=>count($map[$event]['cheer']??[]),'fire'=>count($map[$event]['fire']??[]),'amazing'=>count($map[$event]['amazing']??[])],'active'=>$reaction]);
    }

    private function styles(): string { return '<style>
.tng-journal-shell{max-width:1080px;margin:32px auto;font-family:inherit;color:#17213d}.tng-journal-hero{position:relative;padding:34px;border-radius:28px;background:linear-gradient(135deg,#19254c,#7642a2);color:#fff;box-shadow:0 18px 45px rgba(30,34,70,.16)}.tng-journal-profile-head{display:flex;align-items:center;gap:18px}.tng-journal-avatar img{border-radius:22px}.tng-journal-kicker{font-size:12px;font-weight:800;letter-spacing:.18em;text-transform:uppercase;color:#ffd447}.tng-journal-hero h1{font-size:42px;line-height:1.05;margin:10px 0 8px;color:#fff}.tng-journal-hero p{margin:0;color:rgba(255,255,255,.82);font-size:16px}.tng-journal-share{position:absolute;right:24px;top:24px;border:1px solid rgba(255,255,255,.35);background:rgba(255,255,255,.12);color:#fff;border-radius:12px;padding:10px 14px;font-weight:800;cursor:pointer}.tng-journal-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:18px 0}.tng-journal-stat{background:#fff;border:1px solid #e3e6f1;border-radius:18px;padding:18px;text-align:center}.tng-journal-stat strong{display:block;font-size:28px;color:#6c3fc2}.tng-journal-stat span{font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#6f7890}.tng-journal-tabs{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin:18px 0}.tng-journal-tab{border:1px solid #dfe3ee;background:#fff;color:#39435f;padding:13px 10px;border-radius:14px;font-weight:800;cursor:pointer}.tng-journal-tab.is-active{border-color:#8b56eb;background:#f0e8ff;color:#6a38ba}.tng-journal-panel{display:none}.tng-journal-panel.is-active{display:block}.tng-journal-year{font-size:26px;margin:28px 0 12px}.tng-journal-card{display:grid;grid-template-columns:76px 1fr auto;gap:16px;align-items:start;padding:18px;margin-bottom:12px;background:#fff;border:1px solid #e1e5ef;border-radius:18px}.tng-journal-icon,.tng-journal-media{width:72px;height:72px;border-radius:16px;background:#eee7ff;color:#6e3dcc;display:flex;align-items:center;justify-content:center;font-size:23px;font-weight:900;overflow:hidden}.tng-journal-media img{width:100%;height:100%;object-fit:cover}.tng-journal-card h3{margin:0 0 5px;font-size:19px;color:#17213d}.tng-journal-card p{margin:0;color:#687189}.tng-journal-date{white-space:nowrap;color:#8790a5;font-size:13px}.tng-journal-meta,.tng-journal-reactions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}.tng-journal-chip{padding:7px 10px;border-radius:999px;background:#f0e8ff;color:#683ab8;font-size:12px;font-weight:800}.tng-journal-reaction,.tng-journal-details-btn{border:1px solid #dfe3ee;background:#fff;color:#536079;padding:7px 10px;border-radius:999px;font-size:12px;font-weight:800;cursor:pointer}.tng-journal-reaction.is-active{border-color:#8b56eb;background:#f0e8ff;color:#683ab8}.tng-journal-details-btn{margin-top:12px;border-radius:10px}.tng-journal-details{margin-top:10px;padding:12px 14px;background:#f7f8fc;border-radius:12px;color:#59647b}.tng-journal-details ul{margin:0;padding-left:18px}.tng-journal-empty{padding:36px;text-align:center;background:#fff;border:1px dashed #ccd2e0;border-radius:20px;color:#737c91}@media(max-width:720px){.tng-journal-shell{margin:16px}.tng-journal-hero{padding:26px}.tng-journal-hero h1{font-size:34px}.tng-journal-profile-head{align-items:flex-start}.tng-journal-share{position:static;margin-top:18px}.tng-journal-stats{grid-template-columns:repeat(2,1fr)}.tng-journal-tabs{grid-template-columns:1fr 1fr}.tng-journal-tab:first-child{grid-column:1/-1}.tng-journal-card{grid-template-columns:58px 1fr}.tng-journal-icon,.tng-journal-media{width:54px;height:54px}.tng-journal-date{grid-column:2}.tng-journal-reactions{grid-column:1/-1}}
</style>'; }

    private function scripts(): string { return '<script>(function(){var root=document.currentScript.closest("[data-tng-journal]");if(!root)return;root.querySelectorAll("[data-journal-tab]").forEach(function(btn){btn.addEventListener("click",function(){root.querySelectorAll("[data-journal-tab]").forEach(function(x){x.classList.toggle("is-active",x===btn)});root.querySelectorAll("[data-journal-panel]").forEach(function(x){x.classList.toggle("is-active",x.getAttribute("data-journal-panel")===btn.getAttribute("data-journal-tab"))})})});root.querySelectorAll("[data-toggle-details]").forEach(function(btn){btn.addEventListener("click",function(){var d=btn.nextElementSibling;var open=!d.hidden;d.hidden=open;btn.textContent=open?"View details":"Hide details"})});root.querySelectorAll(".tng-journal-reaction").forEach(function(btn){btn.addEventListener("click",function(){var card=btn.closest("[data-event-id]");var data=new URLSearchParams({action:"tng_journal_react",nonce:root.dataset.nonce,owner:btn.dataset.owner,event:card.dataset.eventId,reaction:btn.dataset.reaction});fetch("'.esc_url(admin_url('admin-ajax.php')).'",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:data}).then(function(r){return r.json()}).then(function(j){if(!j.success)return;card.querySelectorAll(".tng-journal-reaction").forEach(function(x){x.classList.toggle("is-active",x.dataset.reaction===j.data.active);x.querySelector("span").textContent=j.data.counts[x.dataset.reaction]||0})})})});var share=root.querySelector("[data-share-profile]");if(share)share.addEventListener("click",function(){var url=share.dataset.url;if(navigator.share)navigator.share({title:"TN Game Explorer",url:url});else navigator.clipboard.writeText(url).then(function(){share.textContent="Link copied"})})})();</script>'; }

    private function stat(int $value,string $label): string { return '<div class="tng-journal-stat"><strong>'.number_format_i18n($value).'</strong><span>'.esc_html($label).'</span></div>'; }
    private function icon(string $type): string { if(str_contains($type,'trip'))return'✓';if(str_contains($type,'badge')||str_contains($type,'achievement'))return'★';if(str_contains($type,'photo'))return'▣';if(str_contains($type,'checkpoint'))return'◇';if(str_contains($type,'quest'))return'✓';return'•'; }
    public function sort_events(array $a,array $b): int { return (strtotime($b['date']??'')?:0)<=>(strtotime($a['date']??'')?:0); }
    private function duration(int $minutes): string { return $minutes<60?$minutes.' min':(round($minutes/6)/10).' hr'; }
}
