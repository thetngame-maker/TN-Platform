<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Explorer_Network_Intelligence implements Module_Interface {
    private const META_FOLLOWING = '_tng_following_explorers';
    private const META_NOTIFICATIONS = '_tng_explorer_network_notifications';
    private const META_PRIVATE = '_tng_profile_private';
    private const META_VISIBILITY = '_tng_explorer_profile_visibility';

    public function id(): string { return 'explorer_network_intelligence'; }

    public function register(Container $container): void {
        $container->set('explorer_network_intelligence', $this);
        add_shortcode('tng_explorer_network', [$this, 'shortcode']);
        add_action('tng_os_explorer_followed', [$this, 'record_follow_notification'], 10, 2);
        add_action('wp_ajax_tng_network_mark_read', [$this, 'ajax_mark_read']);
        add_action('wp_ajax_tng_network_follow_suggestion', [$this, 'ajax_follow_suggestion']);
        add_action('admin_menu', [$this, 'admin_menu'], 88);
    }

    public function boot(Container $container): void {}

    public function admin_menu(): void {
        add_submenu_page('tn-game-os', 'Explorer Network', 'Explorer Network', 'manage_options', 'tng-os-explorer-network', [$this, 'admin_page']);
    }

    public function admin_page(): void {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap"><h1>Explorer Network Intelligence</h1><p>Create a page named <strong>Explorer Network</strong> and add <code>[tng_explorer_network]</code>.</p><p>The page combines follow notifications, mutual connections, and suggested public Explorers based on shared destinations, profile identity, and activity.</p></div>';
    }

    public function record_follow_notification(int $follower_id, int $target_id): void {
        if (!$follower_id || !$target_id || $follower_id === $target_id) return;
        $follower = get_user_by('id', $follower_id);
        if (!$follower) return;
        $items = get_user_meta($target_id, self::META_NOTIFICATIONS, true);
        $items = is_array($items) ? $items : [];
        $key = 'follow:' . $follower_id;
        $items[$key] = [
            'id' => $key,
            'type' => 'follow',
            'actor_id' => $follower_id,
            'title' => $follower->display_name . ' followed you',
            'message' => 'A new Explorer joined your Tennessee adventure network.',
            'date' => current_time('mysql'),
            'read' => false,
        ];
        uasort($items, static fn($a, $b) => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));
        update_user_meta($target_id, self::META_NOTIFICATIONS, array_slice($items, 0, 100, true));
        if (function_exists('TNGNotify')) {
            TNGNotify(['type'=>'community','title'=>'New Explorer follower','message'=>$follower->display_name . ' followed your Explorer profile.']);
        }
        do_action('tng_os_explorer_notification_created', $target_id, $items[$key]);
    }

    public function shortcode($atts = []): string {
        if (!is_user_logged_in()) return '<div class="tng-network-signin"><h2>Explorer Network</h2><p>Sign in to see followers, notifications, and suggested Explorers.</p></div>';
        $user_id = get_current_user_id();
        $notifications = $this->notifications($user_id);
        $suggestions = $this->suggestions($user_id, 12);
        $following = $this->following_ids($user_id);
        $followers = $this->follower_ids($user_id);
        $unread = count(array_filter($notifications, static fn($n) => empty($n['read'])));
        $nonce = wp_create_nonce('tng_explorer_network');
        ob_start(); ?>
        <section class="tng-network-hub" data-tng-network data-nonce="<?php echo esc_attr($nonce); ?>">
            <?php echo $this->styles(); ?>
            <header class="tng-network-hero"><span>TN GAME COMMUNITY</span><h1>Your Explorer Network</h1><p>Meet people who explore the same places, celebrate new connections, and discover your next adventure together.</p></header>
            <div class="tng-network-stats"><div><b><?php echo count($following); ?></b><span>Following</span></div><div><b><?php echo count($followers); ?></b><span>Followers</span></div><div><b><?php echo $unread; ?></b><span>New alerts</span></div><div><b><?php echo count($suggestions); ?></b><span>Suggestions</span></div></div>
            <nav class="tng-network-tabs"><button class="is-active" data-network-tab="suggestions">Suggested Explorers</button><button data-network-tab="notifications">Notifications<?php if($unread): ?><i><?php echo $unread; ?></i><?php endif; ?></button><button data-network-tab="connections">Connections</button></nav>

            <div class="tng-network-panel is-active" data-network-panel="suggestions">
                <div class="tng-network-head"><div><span>PEOPLE YOU MAY KNOW</span><h2>Explore together</h2></div><p>Suggestions are based on mutual connections, shared home destinations, profile interests, trips, and achievements.</p></div>
                <div class="tng-network-grid">
                    <?php if (!$suggestions): ?><div class="tng-network-empty"><h3>No new suggestions right now</h3><p>Your network is caught up. More recommendations will appear as Explorers complete adventures and customize their profiles.</p></div><?php endif; ?>
                    <?php foreach ($suggestions as $row): $u=$row['user']; ?>
                    <article class="tng-network-card">
                        <a class="tng-network-person" href="<?php echo esc_url($this->profile_url($u)); ?>"><img src="<?php echo esc_url(get_avatar_url($u->ID,['size'=>120])); ?>" alt=""><span><small>TN GAME EXPLORER</small><strong><?php echo esc_html($u->display_name); ?></strong><em><?php echo esc_html(get_user_meta($u->ID,'_tng_explorer_title',true) ?: 'Explorer'); ?></em></span></a>
                        <div class="tng-network-reasons"><?php foreach($row['reasons'] as $reason): ?><span><?php echo esc_html($reason); ?></span><?php endforeach; ?></div>
                        <div class="tng-network-score"><b><?php echo absint($row['score']); ?>%</b><span>network match</span></div>
                        <div class="tng-network-actions"><a href="<?php echo esc_url($this->profile_url($u)); ?>">View profile</a><button type="button" data-suggest-follow="<?php echo absint($u->ID); ?>">Follow</button></div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="tng-network-panel" data-network-panel="notifications">
                <div class="tng-network-head"><div><span>NETWORK UPDATES</span><h2>Notifications</h2></div><?php if($unread): ?><button type="button" class="tng-mark-all" data-mark-read="all">Mark all read</button><?php endif; ?></div>
                <div class="tng-network-list">
                    <?php if (!$notifications): ?><div class="tng-network-empty"><h3>No notifications yet</h3><p>New followers, mutual connections, and future community milestones will appear here.</p></div><?php endif; ?>
                    <?php foreach($notifications as $n): $actor=get_user_by('id',absint($n['actor_id']??0)); ?>
                    <article class="tng-network-notice <?php echo empty($n['read'])?'is-unread':''; ?>">
                        <?php if($actor): ?><a href="<?php echo esc_url($this->profile_url($actor)); ?>"><img src="<?php echo esc_url(get_avatar_url($actor->ID,['size'=>72])); ?>" alt=""></a><?php endif; ?>
                        <div><strong><?php echo esc_html($n['title']??'Network update'); ?></strong><p><?php echo esc_html($n['message']??''); ?></p><time><?php echo esc_html($this->time_label($n['date']??'')); ?></time></div>
                        <?php if(empty($n['read'])): ?><button type="button" data-mark-read="<?php echo esc_attr($n['id']??''); ?>">Mark read</button><?php endif; ?>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="tng-network-panel" data-network-panel="connections">
                <div class="tng-network-head"><div><span>YOUR COMMUNITY</span><h2>Connections</h2></div><p>Followers can discover your public profile. Following controls whose Journal activity appears in your network feed.</p></div>
                <div class="tng-network-columns"><div><h3>Followers <b><?php echo count($followers); ?></b></h3><?php echo $this->connection_cards($followers); ?></div><div><h3>Following <b><?php echo count($following); ?></b></h3><?php echo $this->connection_cards($following); ?></div></div>
            </div>
            <?php echo $this->scripts(); ?>
        </section>
        <?php return (string) ob_get_clean();
    }

    public function ajax_mark_read(): void {
        if (!is_user_logged_in()) wp_send_json_error(['message'=>'Sign in required.'], 401);
        check_ajax_referer('tng_explorer_network', 'nonce');
        $id = sanitize_text_field(wp_unslash($_POST['notification_id'] ?? ''));
        $items = get_user_meta(get_current_user_id(), self::META_NOTIFICATIONS, true);
        $items = is_array($items) ? $items : [];
        foreach ($items as $key => &$item) if ($id === 'all' || $key === $id || ($item['id'] ?? '') === $id) $item['read'] = true;
        unset($item);
        update_user_meta(get_current_user_id(), self::META_NOTIFICATIONS, $items);
        wp_send_json_success(['unread'=>count(array_filter($items, static fn($n)=>empty($n['read'])))]);
    }

    public function ajax_follow_suggestion(): void {
        if (!is_user_logged_in()) wp_send_json_error(['message'=>'Sign in required.'], 401);
        check_ajax_referer('tng_explorer_network', 'nonce');
        $viewer = get_current_user_id();
        $target = absint($_POST['user_id'] ?? 0);
        if (!$target || $target === $viewer || $this->is_private($target) || !get_user_by('id',$target)) wp_send_json_error(['message'=>'Explorer unavailable.']);
        $ids = $this->following_ids($viewer);
        if (!in_array($target,$ids,true)) {
            $ids[]=$target;
            update_user_meta($viewer,self::META_FOLLOWING,array_values(array_unique($ids)));
            do_action('tng_os_explorer_followed',$viewer,$target);
        }
        wp_send_json_success(['following'=>true]);
    }

    private function suggestions(int $viewer_id, int $limit): array {
        $viewer = get_user_by('id',$viewer_id); if(!$viewer)return [];
        $following=$this->following_ids($viewer_id); $excluded=array_merge([$viewer_id],$following);
        $users=(new \WP_User_Query(['number'=>100,'exclude'=>$excluded,'orderby'=>'registered','order'=>'DESC','fields'=>'all']))->get_results();
        $viewer_home=$this->norm(get_user_meta($viewer_id,'_tng_home_destination',true));
        $viewer_title=$this->words(get_user_meta($viewer_id,'_tng_explorer_title',true));
        $viewer_badge=$this->norm(get_user_meta($viewer_id,'_tng_featured_badge',true));
        $viewer_followers=$this->follower_ids($viewer_id);
        $rows=[];
        foreach($users as $u){ if(!$u instanceof \WP_User || $this->is_private($u->ID))continue;
            $score=20; $reasons=[];
            $home=$this->norm(get_user_meta($u->ID,'_tng_home_destination',true));
            if($viewer_home && $home && $viewer_home===$home){$score+=35;$reasons[]='Also explores '.get_user_meta($u->ID,'_tng_home_destination',true);}
            $shared=array_intersect($viewer_title,$this->words(get_user_meta($u->ID,'_tng_explorer_title',true)));
            if($shared){$score+=min(20,count($shared)*8);$reasons[]='Shared Explorer interests';}
            $badge=$this->norm(get_user_meta($u->ID,'_tng_featured_badge',true));
            if($viewer_badge && $badge===$viewer_badge){$score+=12;$reasons[]='Same featured milestone';}
            $mutual=count(array_intersect($following,$this->following_ids($u->ID)));
            if($mutual){$score+=min(25,$mutual*10);$reasons[]=$mutual.' mutual connection'.($mutual===1?'':'s');}
            if(in_array($u->ID,$viewer_followers,true)){$score+=18;$reasons[]='Already follows you';}
            $stats=apply_filters('tng_os_explorer_profile_stats',[],$u->ID);$stats=is_array($stats)?$stats:[];
            $activity=absint($stats['completed_trips']??$stats['trips']??0)+absint($stats['checkpoints']??$stats['trip_stops']??0);
            if($activity>0){$score+=min(10,$activity);$reasons[]='Active Tennessee Explorer';}
            if(!$reasons)$reasons[]='New public Explorer';
            $rows[]=['user'=>$u,'score'=>min(99,$score),'reasons'=>array_slice($reasons,0,3)];
        }
        usort($rows,static fn($a,$b)=>$b['score']<=>$a['score']);
        return array_slice($rows,0,$limit);
    }

    private function notifications(int $user_id): array { $n=get_user_meta($user_id,self::META_NOTIFICATIONS,true); if(!is_array($n))return[]; uasort($n,static fn($a,$b)=>strcmp((string)($b['date']??''),(string)($a['date']??''))); return array_values($n); }
    private function following_ids(int $user_id): array { $v=get_user_meta($user_id,self::META_FOLLOWING,true); return is_array($v)?array_values(array_unique(array_filter(array_map('absint',$v)))):[]; }
    private function follower_ids(int $user_id): array { $out=[]; foreach(get_users(['fields'=>'ID']) as $id) if(in_array($user_id,$this->following_ids((int)$id),true))$out[]=(int)$id; return $out; }
    private function is_private(int $user_id): bool { return get_user_meta($user_id,self::META_PRIVATE,true)==='1'||get_user_meta($user_id,self::META_VISIBILITY,true)==='private'; }
    private function profile_url(\WP_User $u): string { $p=get_page_by_path('explorer-profile'); return add_query_arg('explorer',$u->user_nicename,$p?get_permalink($p):home_url('/explorer-profile/')); }
    private function norm($v): string { return mb_strtolower(trim(wp_strip_all_tags((string)$v))); }
    private function words($v): array { return array_values(array_unique(array_filter(preg_split('/[^a-z0-9]+/i',$this->norm($v)),static fn($w)=>strlen($w)>3))); }
    private function time_label($date): string { $t=strtotime((string)$date); return $t?sprintf(__('%s ago','tn-game-os'),human_time_diff($t,current_time('timestamp'))):''; }

    private function connection_cards(array $ids): string { if(!$ids)return '<div class="tng-network-empty small"><p>No Explorers here yet.</p></div>'; ob_start(); echo '<div class="tng-connection-list">'; foreach(array_slice($ids,0,30) as $id){$u=get_user_by('id',$id);if(!$u||$this->is_private($id))continue;echo '<a href="'.esc_url($this->profile_url($u)).'"><img src="'.esc_url(get_avatar_url($id,['size'=>64])).'" alt=""><span><strong>'.esc_html($u->display_name).'</strong><small>'.esc_html(get_user_meta($id,'_tng_explorer_title',true)?:'Explorer').'</small></span></a>'; } echo '</div>'; return (string)ob_get_clean(); }

    private function styles(): string { return '<style>
.tng-network-hub{max-width:1120px;margin:30px auto 80px;color:#17203b;font-family:inherit}.tng-network-hero{padding:42px;border-radius:28px;background:linear-gradient(125deg,#1c274f,#7540a1);color:#fff}.tng-network-hero span,.tng-network-head span,.tng-network-person small{font-size:11px;letter-spacing:.18em;font-weight:900;color:#ffd34e}.tng-network-hero h1{font-size:42px;margin:12px 0 8px;color:#fff}.tng-network-hero p{font-size:17px;margin:0;max-width:720px;color:rgba(255,255,255,.8)}.tng-network-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:18px 0}.tng-network-stats div{padding:20px;border:1px solid #e1e5ef;border-radius:18px;background:#fff;text-align:center}.tng-network-stats b{display:block;font-size:28px;color:#7040c9}.tng-network-stats span{font-size:11px;font-weight:900;letter-spacing:.12em;text-transform:uppercase}.tng-network-tabs{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:18px 0}.tng-network-tabs button{padding:15px;border:1px solid #d9deea;background:#fff;border-radius:14px;font-weight:900;cursor:pointer}.tng-network-tabs button.is-active{background:#efe7ff;border-color:#8c55ef;color:#6135b3}.tng-network-tabs i{display:inline-grid;place-items:center;min-width:22px;height:22px;margin-left:7px;border-radius:50%;background:#7d45de;color:#fff;font-style:normal}.tng-network-panel{display:none}.tng-network-panel.is-active{display:block}.tng-network-head{display:flex;justify-content:space-between;align-items:end;margin:28px 0 16px}.tng-network-head span{color:#7040c9}.tng-network-head h2{margin:5px 0 0;font-size:28px}.tng-network-head p{max-width:520px;margin:0;color:#6f7890}.tng-network-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.tng-network-card{position:relative;padding:20px;border:1px solid #dde2ec;border-radius:22px;background:#fff;box-shadow:0 12px 32px rgba(30,39,79,.06)}.tng-network-person{display:flex;gap:14px;align-items:center;text-decoration:none;color:inherit}.tng-network-person img{width:66px;height:66px;border-radius:20px;object-fit:cover}.tng-network-person span{display:flex;flex-direction:column}.tng-network-person small{color:#7040c9}.tng-network-person strong{font-size:19px}.tng-network-person em{font-style:normal;color:#758099}.tng-network-reasons{display:flex;flex-wrap:wrap;gap:7px;margin:16px 0}.tng-network-reasons span{padding:7px 9px;border-radius:999px;background:#f1ebff;color:#6840ad;font-size:12px;font-weight:800}.tng-network-score{display:flex;align-items:baseline;gap:7px;padding:12px 0;border-top:1px solid #edf0f5}.tng-network-score b{font-size:24px;color:#7040c9}.tng-network-score span{color:#7b8498;font-size:12px}.tng-network-actions{display:grid;grid-template-columns:1fr 1fr;gap:9px}.tng-network-actions a,.tng-network-actions button,.tng-mark-all{padding:12px;border-radius:12px;border:0;text-align:center;text-decoration:none;font-weight:900;cursor:pointer}.tng-network-actions a{background:#182344;color:#fff}.tng-network-actions button,.tng-mark-all{background:#7c45df;color:#fff}.tng-network-list{display:grid;gap:12px}.tng-network-notice{display:grid;grid-template-columns:auto 1fr auto;gap:14px;align-items:center;padding:18px;border:1px solid #dfe4ee;border-radius:18px;background:#fff}.tng-network-notice.is-unread{border-color:#9b68ef;background:#f8f4ff}.tng-network-notice img{width:54px;height:54px;border-radius:17px}.tng-network-notice p{margin:4px 0;color:#6f7890}.tng-network-notice time{font-size:12px;color:#929aae}.tng-network-notice button{border:0;background:#eee6ff;color:#6e3dbe;border-radius:10px;padding:10px;font-weight:800}.tng-network-columns{display:grid;grid-template-columns:1fr 1fr;gap:18px}.tng-network-columns>div{padding:20px;border:1px solid #dfe4ee;border-radius:20px;background:#fff}.tng-network-columns h3{display:flex;justify-content:space-between}.tng-connection-list{display:grid;gap:8px}.tng-connection-list a{display:flex;gap:11px;align-items:center;padding:10px;border-radius:13px;text-decoration:none;color:inherit;background:#f7f8fb}.tng-connection-list img{width:44px;height:44px;border-radius:13px}.tng-connection-list span{display:flex;flex-direction:column}.tng-connection-list small{color:#7a8398}.tng-network-empty{grid-column:1/-1;padding:40px;border:1px dashed #cfd5e2;border-radius:18px;text-align:center;color:#6f7890}.tng-network-empty.small{padding:20px}.tng-network-signin{max-width:760px;margin:40px auto;padding:40px;border-radius:24px;background:#f4efff;text-align:center}
@media(max-width:850px){.tng-network-grid{grid-template-columns:1fr 1fr}.tng-network-stats{grid-template-columns:1fr 1fr}.tng-network-head{display:block}.tng-network-head p{margin-top:8px}}
@media(max-width:600px){.tng-network-hub{margin:15px}.tng-network-hero{padding:28px 22px}.tng-network-hero h1{font-size:32px}.tng-network-tabs{grid-template-columns:1fr}.tng-network-grid,.tng-network-columns{grid-template-columns:1fr}.tng-network-notice{grid-template-columns:auto 1fr}.tng-network-notice button{grid-column:1/-1}.tng-network-stats div{padding:15px}}
</style>'; }

    private function scripts(): string { return '<script>(function(){var root=document.querySelector("[data-tng-network]");if(!root)return;var nonce=root.dataset.nonce;root.querySelectorAll("[data-network-tab]").forEach(function(b){b.addEventListener("click",function(){root.querySelectorAll("[data-network-tab]").forEach(function(x){x.classList.toggle("is-active",x===b)});root.querySelectorAll("[data-network-panel]").forEach(function(p){p.classList.toggle("is-active",p.dataset.networkPanel===b.dataset.networkTab)})})});root.querySelectorAll("[data-suggest-follow]").forEach(function(b){b.addEventListener("click",function(){b.disabled=true;var q=new URLSearchParams({action:"tng_network_follow_suggestion",nonce:nonce,user_id:b.dataset.suggestFollow});fetch("'.esc_url(admin_url('admin-ajax.php')).'",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:q.toString()}).then(function(r){return r.json()}).then(function(j){if(j.success){b.textContent="Following";b.closest(".tng-network-card").style.opacity=".65"}}).finally(function(){b.disabled=false})})});root.querySelectorAll("[data-mark-read]").forEach(function(b){b.addEventListener("click",function(){var q=new URLSearchParams({action:"tng_network_mark_read",nonce:nonce,notification_id:b.dataset.markRead});fetch("'.esc_url(admin_url('admin-ajax.php')).'",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:q.toString()}).then(function(r){return r.json()}).then(function(j){if(j.success){if(b.dataset.markRead==="all")root.querySelectorAll(".tng-network-notice").forEach(function(n){n.classList.remove("is-unread")});else b.closest(".tng-network-notice").classList.remove("is-unread");b.remove()}})})})})();</script>'; }
}
