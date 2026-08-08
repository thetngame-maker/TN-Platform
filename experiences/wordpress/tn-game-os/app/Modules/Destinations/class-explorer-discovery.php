<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Explorer_Discovery implements Module_Interface {
    private const META_FOLLOWING = '_tng_following_explorers';
    private const META_PRIVATE = '_tng_profile_private';
    private const META_VISIBILITY = '_tng_explorer_profile_visibility';

    public function id(): string { return 'explorer_discovery'; }

    public function register(Container $container): void {
        $container->set('explorer_discovery', $this);
        add_shortcode('tng_explorer_discovery', [$this, 'shortcode']);
        add_action('wp_ajax_tng_toggle_explorer_follow', [$this, 'ajax_toggle_follow']);
        add_filter('do_shortcode_tag', [$this, 'enhance_profile'], 60, 4);
        add_action('admin_menu', [$this, 'admin_menu'], 87);
    }

    public function boot(Container $container): void {}

    public function admin_menu(): void {
        add_submenu_page('tn-game-os', 'Explorer Discovery', 'Explorer Discovery', 'manage_options', 'tng-os-explorer-discovery', [$this, 'admin_page']);
    }

    public function admin_page(): void {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap"><h1>Explorer Discovery</h1><p>Create a page named <strong>Find Explorers</strong> and add <code>[tng_explorer_discovery]</code>.</p><p>Public Explorers can be searched, followed, opened, and viewed in a following-only activity stream.</p></div>';
    }

    public function shortcode($atts = []): string {
        $atts = shortcode_atts(['limit' => 18], $atts, 'tng_explorer_discovery');
        $viewer_id = get_current_user_id();
        $query = sanitize_text_field(wp_unslash($_GET['explorer_search'] ?? ''));
        $tab = sanitize_key($_GET['network_tab'] ?? 'discover');
        if (!in_array($tab, ['discover','following','activity'], true)) $tab = 'discover';
        $following = $viewer_id ? $this->following_ids($viewer_id) : [];
        $users = $this->find_users($query, $tab, $following, absint($atts['limit']) ?: 18, $viewer_id);
        $activity = $tab === 'activity' ? $this->following_activity($following) : [];
        $base = get_permalink();

        ob_start(); ?>
        <section class="tng-explorer-discovery" data-tng-explorer-discovery data-nonce="<?php echo esc_attr(wp_create_nonce('tng_toggle_explorer_follow')); ?>">
            <?php echo $this->styles(); ?>
            <div class="tng-ed-hero"><span>TN GAME COMMUNITY</span><h1>Find your Explorer community</h1><p>Discover public profiles, follow Tennessee adventures, and build a network around the places you love.</p></div>
            <div class="tng-ed-toolbar">
                <nav class="tng-ed-tabs">
                    <a class="<?php echo $tab==='discover'?'is-active':''; ?>" href="<?php echo esc_url(add_query_arg('network_tab','discover',$base)); ?>">Discover</a>
                    <a class="<?php echo $tab==='following'?'is-active':''; ?>" href="<?php echo esc_url(add_query_arg('network_tab','following',$base)); ?>">Following <b><?php echo count($following); ?></b></a>
                    <a class="<?php echo $tab==='activity'?'is-active':''; ?>" href="<?php echo esc_url(add_query_arg('network_tab','activity',$base)); ?>">Following activity</a>
                </nav>
                <?php if ($tab !== 'activity'): ?><form method="get" class="tng-ed-search"><input type="hidden" name="network_tab" value="<?php echo esc_attr($tab); ?>"><input type="search" name="explorer_search" value="<?php echo esc_attr($query); ?>" placeholder="Search Explorers, titles, or destinations"><button type="submit">Search</button></form><?php endif; ?>
            </div>

            <?php if (!is_user_logged_in()): ?><div class="tng-ed-notice">Sign in to follow Explorers and build a personalized community feed.</div><?php endif; ?>

            <?php if ($tab === 'activity'): ?>
                <div class="tng-ed-section-head"><div><span>YOUR NETWORK</span><h2>Latest adventures</h2></div><strong><?php echo count($activity); ?> updates</strong></div>
                <div class="tng-ed-feed">
                    <?php if (!$activity): ?><div class="tng-ed-empty"><h3>No following activity yet</h3><p>Follow public Explorers to see their trips, checkpoints, achievements, and photos here.</p></div><?php endif; ?>
                    <?php foreach ($activity as $item): $user=$item['user']; $event=$item['event']; $ts=strtotime($event['date']??'')?:0; ?>
                        <article class="tng-ed-feed-card"><a class="tng-ed-feed-user" href="<?php echo esc_url($this->profile_url($user)); ?>"><img src="<?php echo esc_url(get_avatar_url($user->ID,['size'=>80])); ?>" alt=""><span><strong><?php echo esc_html($user->display_name); ?></strong><small><?php echo esc_html(get_user_meta($user->ID,'_tng_explorer_title',true) ?: 'TN Game Explorer'); ?></small></span></a><div class="tng-ed-feed-event"><span class="tng-ed-event-icon"><?php echo esc_html($this->icon(sanitize_key($event['type']??''))); ?></span><div><h3><?php echo esc_html($event['title']??'Explorer update'); ?></h3><p><?php echo esc_html($event['description']??''); ?></p></div><time><?php echo esc_html($ts?wp_date(get_option('date_format'),$ts):''); ?></time></div></article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="tng-ed-section-head"><div><span><?php echo $tab==='following'?'YOUR NETWORK':'PROFILE DISCOVERY'; ?></span><h2><?php echo $tab==='following'?'Explorers you follow':'Meet Tennessee Explorers'; ?></h2></div><strong><?php echo count($users); ?> profiles</strong></div>
                <div class="tng-ed-grid">
                    <?php if (!$users): ?><div class="tng-ed-empty"><h3><?php echo $tab==='following'?'You are not following anyone yet':'No public Explorers found'; ?></h3><p><?php echo $tab==='following'?'Open Discover to find Explorers whose adventures inspire you.':'Try a different name, title, or destination.'; ?></p></div><?php endif; ?>
                    <?php foreach ($users as $user): echo $this->profile_card($user, in_array($user->ID,$following,true), $viewer_id); endforeach; ?>
                </div>
            <?php endif; ?>
            <?php echo $this->scripts(); ?>
        </section>
        <?php return (string)ob_get_clean();
    }

    public function ajax_toggle_follow(): void {
        if (!is_user_logged_in()) wp_send_json_error(['message'=>'Sign in to follow Explorers.'], 401);
        check_ajax_referer('tng_toggle_explorer_follow', 'nonce');
        $viewer_id = get_current_user_id();
        $target_id = absint($_POST['user_id'] ?? 0);
        if (!$target_id || $target_id === $viewer_id || !get_user_by('id',$target_id)) wp_send_json_error(['message'=>'Explorer unavailable.']);
        if ($this->is_private($target_id)) wp_send_json_error(['message'=>'This Explorer profile is private.']);
        $following = $this->following_ids($viewer_id);
        $is_following = in_array($target_id, $following, true);
        if ($is_following) $following = array_values(array_diff($following, [$target_id]));
        else $following[] = $target_id;
        update_user_meta($viewer_id, self::META_FOLLOWING, array_values(array_unique(array_map('absint',$following))));
        do_action($is_following ? 'tng_os_explorer_unfollowed' : 'tng_os_explorer_followed', $viewer_id, $target_id);
        wp_send_json_success(['following'=>!$is_following,'count'=>count($following),'message'=>$is_following?'Explorer unfollowed.':'Explorer followed.']);
    }

    public function enhance_profile(string $output, string $tag, array $attr, array $m): string {
        if ($tag !== 'tng_explorer_profile' || !$output || str_contains($output,'data-tng-profile-follow')) return $output;
        $target = $this->resolve_profile_user($attr);
        if (!$target || !is_user_logged_in() || get_current_user_id()===$target->ID || $this->is_private($target->ID)) return $output;
        $following = in_array($target->ID, $this->following_ids(get_current_user_id()), true);
        $button = '<button type="button" class="tng-profile-follow '.($following?'is-following':'').'" data-tng-profile-follow data-user-id="'.absint($target->ID).'">'.($following?'Following':'Follow Explorer').'</button>';
        $output = str_replace('<button type="button" class="tng-journal-share"', $button.'<button type="button" class="tng-journal-share"', $output);
        $output = str_replace('<button type="button" class="tng-public-explorer-share"', $button.'<button type="button" class="tng-public-explorer-share"', $output);
        $nonce = wp_create_nonce('tng_toggle_explorer_follow');
        return '<style>.tng-profile-follow{border:1px solid rgba(255,255,255,.45);border-radius:14px;padding:13px 18px;background:transparent;color:#fff;font-weight:900;cursor:pointer;margin-right:9px}.tng-profile-follow.is-following{background:#fff;color:#44296f}.tng-profile-follow:disabled{opacity:.6}@media(max-width:700px){.tng-profile-follow{width:100%;margin:10px 0 0}}</style>'.$output.'<script>(function(){document.querySelectorAll("[data-tng-profile-follow]").forEach(function(b){if(b.dataset.bound)return;b.dataset.bound="1";b.addEventListener("click",function(){b.disabled=true;var body=new URLSearchParams({action:"tng_toggle_explorer_follow",nonce:"'.esc_js($nonce).'",user_id:b.dataset.userId});fetch("'.esc_url(admin_url('admin-ajax.php')).'",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:body.toString()}).then(function(r){return r.json()}).then(function(j){if(j.success){b.classList.toggle("is-following",j.data.following);b.textContent=j.data.following?"Following":"Follow Explorer"}}).finally(function(){b.disabled=false})})})})();</script>';
    }

    private function find_users(string $query, string $tab, array $following, int $limit, int $viewer_id): array {
        if ($tab === 'following' && !$following) return [];
        $args = ['number'=>$limit,'orderby'=>'registered','order'=>'DESC','fields'=>'all'];
        if ($query !== '') { $args['search']='*'.$query.'*'; $args['search_columns']=['user_login','user_nicename','display_name']; }
        if ($tab === 'following') $args['include']=$following;
        if ($viewer_id) $args['exclude']=[$viewer_id];
        $users = (new \WP_User_Query($args))->get_results();
        $users = array_values(array_filter($users, fn($u)=>$u instanceof \WP_User && !$this->is_private($u->ID)));
        if ($query !== '') {
            $needle = mb_strtolower($query);
            $users = array_values(array_filter($users, function($u) use ($needle) {
                $hay = mb_strtolower(implode(' ',[$u->display_name,$u->user_login,get_user_meta($u->ID,'_tng_explorer_title',true),get_user_meta($u->ID,'_tng_home_destination',true)]));
                return str_contains($hay,$needle);
            }));
        }
        return $users;
    }

    private function profile_card(\WP_User $user, bool $following, int $viewer_id): string {
        $stats = apply_filters('tng_os_explorer_profile_stats', [], $user->ID); $stats=is_array($stats)?$stats:[];
        $title = sanitize_text_field(get_user_meta($user->ID,'_tng_explorer_title',true)) ?: 'TN Game Explorer';
        $home = sanitize_text_field(get_user_meta($user->ID,'_tng_home_destination',true));
        $badge = sanitize_text_field(get_user_meta($user->ID,'_tng_featured_badge',true));
        $trips = absint($stats['completed_trips']??$stats['trips']??0); $places=absint($stats['trip_stops']??$stats['checkpoints']??0); $xp=absint($stats['xp']??$stats['total_xp']??0);
        ob_start(); ?><article class="tng-ed-card"><div class="tng-ed-card-top"><img src="<?php echo esc_url(get_avatar_url($user->ID,['size'=>140])); ?>" alt=""><div><span>TN GAME EXPLORER</span><h3><?php echo esc_html($user->display_name); ?></h3><p><?php echo esc_html($title); ?></p></div></div><div class="tng-ed-chips"><?php if($home):?><span>⌂ <?php echo esc_html($home); ?></span><?php endif; ?><?php if($badge):?><span>★ <?php echo esc_html($badge); ?></span><?php endif; ?></div><div class="tng-ed-stats"><span><b><?php echo $trips; ?></b> Trips</span><span><b><?php echo $places; ?></b> Places</span><span><b><?php echo $xp; ?></b> XP</span></div><div class="tng-ed-actions"><a href="<?php echo esc_url($this->profile_url($user)); ?>">View profile</a><?php if($viewer_id):?><button type="button" class="<?php echo $following?'is-following':''; ?>" data-follow-user="<?php echo absint($user->ID); ?>"><?php echo $following?'Following':'Follow'; ?></button><?php endif; ?></div></article><?php return (string)ob_get_clean();
    }

    private function following_activity(array $following): array {
        $items=[];
        foreach (array_slice($following,0,30) as $user_id) {
            if ($this->is_private($user_id)) continue;
            $user=get_user_by('id',$user_id); if(!$user)continue;
            $events=apply_filters('tng_os_adventure_journal_events',[],$user_id); if(!is_array($events))continue;
            foreach(array_slice($events,0,8) as $event) if(is_array($event))$items[]=['user'=>$user,'event'=>$event];
        }
        usort($items,static fn($a,$b)=>(strtotime($b['event']['date']??'')?:0)<=>(strtotime($a['event']['date']??'')?:0));
        return array_slice($items,0,40);
    }

    private function following_ids(int $user_id): array { $ids=get_user_meta($user_id,self::META_FOLLOWING,true); return is_array($ids)?array_values(array_unique(array_filter(array_map('absint',$ids)))):[]; }
    private function is_private(int $user_id): bool { return get_user_meta($user_id,self::META_PRIVATE,true)==='1' || get_user_meta($user_id,self::META_VISIBILITY,true)==='private'; }
    private function resolve_profile_user(array $attr): ?\WP_User { $r=sanitize_text_field($_GET['explorer']??($attr['user']??$attr['username']??'')); if(!$r)return is_user_logged_in()?wp_get_current_user():null; $u=ctype_digit($r)?get_user_by('id',absint($r)):get_user_by('login',$r); if(!$u)$u=get_user_by('slug',$r); return $u instanceof \WP_User?$u:null; }
    private function profile_url(\WP_User $user): string { $page=get_page_by_path('explorer-profile'); $base=$page?get_permalink($page):home_url('/explorer-profile/'); return add_query_arg('explorer',$user->user_nicename,$base); }
    private function icon(string $type): string { if(str_contains($type,'trip')||str_contains($type,'quest'))return '✓'; if(str_contains($type,'photo'))return '▣'; if(str_contains($type,'badge')||str_contains($type,'achievement'))return '★'; if(str_contains($type,'checkpoint'))return '⌖'; return '◆'; }

    private function styles(): string { return '<style>
.tng-explorer-discovery{max-width:1120px;margin:34px auto 80px;color:#17213d}.tng-ed-hero{padding:38px;border-radius:28px;background:linear-gradient(125deg,#202954,#7841a5);color:#fff}.tng-ed-hero span,.tng-ed-section-head span,.tng-ed-card-top span{font-size:11px;letter-spacing:.18em;font-weight:900;color:#ffd54a}.tng-ed-hero h1{font-size:42px;margin:8px 0}.tng-ed-hero p{margin:0;opacity:.82}.tng-ed-toolbar{display:flex;justify-content:space-between;align-items:center;gap:16px;margin:20px 0}.tng-ed-tabs{display:flex;gap:8px}.tng-ed-tabs a{padding:12px 15px;border:1px solid #dce1ed;border-radius:13px;background:#fff;color:#26304d;text-decoration:none;font-weight:900}.tng-ed-tabs a.is-active{background:#eee6ff;border-color:#8855ec;color:#6735bd}.tng-ed-tabs b{display:inline-flex;min-width:20px;height:20px;align-items:center;justify-content:center;border-radius:99px;background:#17213d;color:#fff;font-size:11px}.tng-ed-search{display:flex;gap:8px;flex:1;max-width:460px}.tng-ed-search input{flex:1;border:1px solid #d8deeb;border-radius:12px;padding:12px}.tng-ed-search button,.tng-ed-actions button{border:0;border-radius:12px;background:#8050df;color:#fff;padding:12px 16px;font-weight:900;cursor:pointer}.tng-ed-notice,.tng-ed-empty{padding:22px;border:1px dashed #cad2e2;border-radius:18px;background:#fafbfe;text-align:center}.tng-ed-section-head{display:flex;justify-content:space-between;align-items:end;margin:26px 0 14px}.tng-ed-section-head h2{margin:5px 0 0;font-size:28px}.tng-ed-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.tng-ed-grid>.tng-ed-empty{grid-column:1/-1}.tng-ed-card{padding:20px;border:1px solid #dfe4ef;border-radius:22px;background:#fff;box-shadow:0 12px 32px rgba(25,33,63,.05)}.tng-ed-card-top{display:grid;grid-template-columns:72px 1fr;gap:14px;align-items:center}.tng-ed-card-top img{width:72px;height:72px;object-fit:cover;border-radius:20px;background:#eee}.tng-ed-card-top h3{font-size:21px;margin:4px 0}.tng-ed-card-top p{margin:0;color:#6e7890}.tng-ed-chips{display:flex;flex-wrap:wrap;gap:7px;margin:16px 0}.tng-ed-chips span{padding:7px 9px;border-radius:99px;background:#f1ebff;color:#6838bd;font-size:11px;font-weight:800}.tng-ed-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:14px 0}.tng-ed-stats span{text-align:center;padding:11px 5px;background:#f7f8fb;border-radius:12px;font-size:10px;text-transform:uppercase;color:#737c91}.tng-ed-stats b{display:block;font-size:19px;color:#26304d}.tng-ed-actions{display:grid;grid-template-columns:1fr 1fr;gap:9px}.tng-ed-actions a{display:flex;align-items:center;justify-content:center;border-radius:12px;background:#17213d;color:#fff;text-decoration:none;font-weight:900}.tng-ed-actions button.is-following{background:#eee6ff;color:#6735bd}.tng-ed-feed{display:grid;gap:14px}.tng-ed-feed-card{padding:18px;border:1px solid #dfe4ef;border-radius:20px;background:#fff}.tng-ed-feed-user{display:flex;align-items:center;gap:10px;text-decoration:none;color:#17213d}.tng-ed-feed-user img{width:48px;height:48px;border-radius:14px;object-fit:cover}.tng-ed-feed-user small{display:block;color:#788196}.tng-ed-feed-event{display:grid;grid-template-columns:48px 1fr auto;gap:13px;align-items:center;margin-top:14px;padding-top:14px;border-top:1px solid #edf0f5}.tng-ed-event-icon{width:46px;height:46px;border-radius:14px;background:#eee6ff;color:#7341cc;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:900}.tng-ed-feed-event h3{margin:0 0 4px}.tng-ed-feed-event p{margin:0;color:#6f7890}.tng-ed-feed-event time{color:#9299aa;font-size:12px}@media(max-width:850px){.tng-explorer-discovery{margin:16px}.tng-ed-toolbar{align-items:stretch;flex-direction:column}.tng-ed-tabs{width:100%;overflow:auto}.tng-ed-tabs a{white-space:nowrap}.tng-ed-search{max-width:none;width:100%}.tng-ed-grid{grid-template-columns:1fr}.tng-ed-hero h1{font-size:34px}.tng-ed-feed-event{grid-template-columns:42px 1fr}.tng-ed-feed-event time{grid-column:2}}
</style>'; }

    private function scripts(): string { return '<script>(function(){var root=document.currentScript.closest("[data-tng-explorer-discovery]");if(!root)return;root.querySelectorAll("[data-follow-user]").forEach(function(btn){btn.addEventListener("click",function(){btn.disabled=true;var body=new URLSearchParams({action:"tng_toggle_explorer_follow",nonce:root.dataset.nonce,user_id:btn.dataset.followUser});fetch("'.esc_url(admin_url('admin-ajax.php')).'",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:body.toString()}).then(function(r){return r.json()}).then(function(j){if(!j.success){alert(j.data&&j.data.message?j.data.message:"Unable to update follow.");return}btn.classList.toggle("is-following",j.data.following);btn.textContent=j.data.following?"Following":"Follow"}).finally(function(){btn.disabled=false})})})})();</script>'; }
}
