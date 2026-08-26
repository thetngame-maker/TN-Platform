<?php
/**
 * TN Game Instagram Browse
 * Internal visual browser for saved Instagram searches and captured inspiration.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Instagram_Browser {
    private const WATCH = 'tng_social_watch';
    private const CANDIDATE = 'tng_social_candidate';
    private const ITEM = 'tng_social_item';
    private const NONCE = 'tng_instagram_browser_action';

    public static function boot(): void {
        static $booted = false;
        if ($booted) return;
        $booted = true;
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 25);
        add_action('admin_post_tng_browser_save', [__CLASS__, 'save']);
        add_action('admin_post_tng_browser_dismiss', [__CLASS__, 'dismiss']);
        add_action('admin_post_tng_browser_idea', [__CLASS__, 'create_idea']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function admin_menu(): void {
        add_submenu_page('tng-content-studio', 'Instagram Browse', 'Browse Instagram', 'edit_posts', 'tng-instagram-browser', [__CLASS__, 'render']);
    }

    private static function meta(int $id, string $key, string $fallback=''): string {
        $v = trim((string)get_post_meta($id,$key,true));
        return $v !== '' ? $v : $fallback;
    }

    private static function guard(): int {
        if (!current_user_can('edit_posts')) wp_die('Not allowed.');
        check_admin_referer(self::NONCE);
        $id = absint($_GET['candidate'] ?? $_POST['candidate'] ?? 0);
        if (!$id || get_post_type($id) !== self::CANDIDATE || !current_user_can('edit_post',$id)) wp_die('Invalid candidate.');
        return $id;
    }

    private static function redirect(string $notice=''): void {
        wp_safe_redirect(add_query_arg(['page'=>'tng-instagram-browser','tng_notice'=>$notice],admin_url('admin.php')));
        exit;
    }

    private static function action_url(string $action, int $id): string {
        return wp_nonce_url(add_query_arg(['action'=>$action,'candidate'=>$id],admin_url('admin-post.php')),self::NONCE);
    }

    private static function instagram_search_url(int $watch_id): string {
        $value = self::meta($watch_id,'_tng_watch_value',get_the_title($watch_id));
        $type = self::meta($watch_id,'_tng_watch_type','topic');
        $clean = ltrim(trim($value),'#@');
        if ($type === 'hashtag') return 'https://www.instagram.com/explore/tags/' . rawurlencode(strtolower(str_replace(' ','',$clean))) . '/';
        if ($type === 'account') return 'https://www.instagram.com/' . rawurlencode($clean) . '/';
        return 'https://www.instagram.com/explore/search/keyword/?q=' . rawurlencode($clean);
    }

    private static function watches(): array {
        return get_posts([
            'post_type'=>self::WATCH,'post_status'=>['publish','draft','private'],'posts_per_page'=>100,
            'orderby'=>'title','order'=>'ASC',
            'meta_query'=>[
                'relation'=>'AND',
                ['key'=>'_tng_watch_status','value'=>'active'],
                ['relation'=>'OR',['key'=>'_tng_watch_platform','value'=>'instagram'],['key'=>'_tng_watch_platform','value'=>'all']],
            ],
        ]);
    }

    private static function candidates(): array {
        $args = ['post_type'=>self::CANDIDATE,'post_status'=>'publish','posts_per_page'=>200,'orderby'=>'date','order'=>'DESC'];
        $watch = absint($_GET['watch'] ?? 0);
        if ($watch) $args['meta_query'] = [['key'=>'_tng_candidate_watch_id','value'=>$watch]];
        $items = get_posts($args);
        usort($items, static fn($a,$b)=>(int)get_post_meta($b->ID,'_tng_candidate_trend_score',true)<=>(int)get_post_meta($a->ID,'_tng_candidate_trend_score',true));
        return $items;
    }

    public static function save(): void {
        $id = self::guard();
        $source = self::meta($id,'_tng_candidate_source_url');
        $new = wp_insert_post(['post_type'=>self::ITEM,'post_status'=>'draft','post_title'=>get_the_title($id),'post_content'=>get_post_field('post_content',$id)]);
        if (is_wp_error($new) || !$new) wp_die('Could not save inspiration.');
        $map = [
            '_tng_candidate_source_url'=>'_tng_source_url','_tng_candidate_creator'=>'_tng_creator_handle',
            '_tng_candidate_format'=>'_tng_content_format','_tng_candidate_hashtags'=>'_tng_hashtags',
            '_tng_candidate_location'=>'_tng_location_name','_tng_candidate_trend_score'=>'_tng_source_opportunity_score',
            '_tng_candidate_relevance'=>'_tng_source_relevance','_tng_candidate_engagement'=>'_tng_source_engagement',
            '_tng_candidate_freshness'=>'_tng_source_freshness'
        ];
        foreach($map as $from=>$to){$v=get_post_meta($id,$from,true);if($v!=='')update_post_meta($new,$to,$v);}
        update_post_meta($new,'_tng_plan_status','inspiration');
        update_post_meta($new,'_tng_permission_status','unknown');
        update_post_meta($new,'_tng_content_notes','Saved from Instagram Browse. Source remains linked for review and attribution.');
        if ($source) update_post_meta($new,'_tng_source_url',$source);
        wp_trash_post($id);
        self::redirect('Saved to Inspiration.');
    }

    public static function dismiss(): void {
        $id = self::guard();
        wp_trash_post($id);
        self::redirect('Dismissed from Browse.');
    }

    public static function create_idea(): void {
        $id = self::guard();
        $place = self::meta($id,'_tng_candidate_location','Tennessee');
        $creator = self::meta($id,'_tng_candidate_creator');
        $format = self::meta($id,'_tng_candidate_format','reel');
        $hashtags = self::meta($id,'_tng_candidate_hashtags','#Tennessee #TheTNGame');
        $source = self::meta($id,'_tng_candidate_source_url');
        $title = 'TN Game angle: ' . ($place !== 'Tennessee' ? $place : get_the_title($id));
        $angle = 'Create an original TN Game ' . ucwords(str_replace('_',' ',$format)) . ' inspired by the pattern in this source post. Focus on a useful local detail, discovery, route, checkpoint, or nearby stop rather than reproducing the creator’s execution.';
        $new = wp_insert_post(['post_type'=>self::ITEM,'post_status'=>'draft','post_title'=>$title,'post_content'=>$angle]);
        if (is_wp_error($new) || !$new) wp_die('Could not create idea.');
        update_post_meta($new,'_tng_original_angle',$angle);
        update_post_meta($new,'_tng_hook','What makes ' . $place . ' worth adding to your next Tennessee adventure?');
        update_post_meta($new,'_tng_content_format',$format ?: 'reel');
        update_post_meta($new,'_tng_location_name',$place);
        update_post_meta($new,'_tng_hashtags',$hashtags);
        update_post_meta($new,'_tng_plan_status','idea');
        update_post_meta($new,'_tng_permission_status','not_needed');
        update_post_meta($new,'_tng_source_url',$source);
        update_post_meta($new,'_tng_creator_handle',$creator);
        update_post_meta($new,'_tng_content_notes','Idea created from Instagram Browse. Use the source only as inspiration; create original media and copy.');
        wp_safe_redirect(add_query_arg(['page'=>'tng-content-post-builder','idea'=>(int)$new],admin_url('admin.php')));
        exit;
    }

    private static function card(WP_Post $p): string {
        $id=(int)$p->ID;
        $url=self::meta($id,'_tng_candidate_source_url');
        $creator=self::meta($id,'_tng_candidate_creator','Instagram creator');
        $format=self::meta($id,'_tng_candidate_format','post');
        $place=self::meta($id,'_tng_candidate_location');
        $tags=self::meta($id,'_tng_candidate_hashtags');
        $score=(int)self::meta($id,'_tng_candidate_trend_score','0');
        $likes=(int)self::meta($id,'_tng_candidate_likes','0');
        $comments=(int)self::meta($id,'_tng_candidate_comments','0');
        $views=(int)self::meta($id,'_tng_candidate_views','0');
        $thumb=self::meta($id,'_tng_candidate_thumbnail');
        $watch=(int)self::meta($id,'_tng_candidate_watch_id','0');
        $watchLabel=$watch?get_the_title($watch):'';
        $body=wp_trim_words(wp_strip_all_tags($p->post_content),24);
        $visual=$thumb?'<img src="'.esc_url($thumb).'" alt="">':'<div class="tng-ib-placeholder"><span>◎</span><strong>'.esc_html(ucwords($format)).'</strong><small>Source-linked Instagram post</small></div>';
        return '<article class="tng-ib-card">'.$visual.'<div class="tng-ib-card-body"><div class="tng-ib-score">'.$score.'<small>opportunity</small></div><p class="tng-ib-creator">'.esc_html('@'.ltrim($creator,'@')).'</p><h3>'.esc_html($p->post_title).'</h3>'.($body?'<p class="excerpt">'.esc_html($body).'</p>':'').'<div class="meta">'.esc_html(ucwords($format)).($place?' · '.esc_html($place):'').($watchLabel?' · '.esc_html($watchLabel):'').'</div><div class="signals"><span>♥ '.number_format_i18n($likes).'</span><span>💬 '.number_format_i18n($comments).'</span>'.($views?'<span>▶ '.number_format_i18n($views).'</span>':'').'</div>'.($tags?'<p class="tags">'.esc_html($tags).'</p>':'').'<div class="actions"><a class="button button-primary" href="'.esc_url($url).'" target="_blank" rel="noopener">View source ↗</a><a class="button" href="'.esc_url(self::action_url('tng_browser_save',$id)).'">Save</a><a class="button" href="'.esc_url(self::action_url('tng_browser_idea',$id)).'">Create idea</a><a class="button link-delete" href="'.esc_url(self::action_url('tng_browser_dismiss',$id)).'">Dismiss</a></div></div></article>';
    }

    public static function render(): void {
        if(!current_user_can('edit_posts'))return;
        $watches=self::watches();$candidates=self::candidates();$active=absint($_GET['watch']??0);
        echo '<div class="wrap tng-ib"><section class="tng-ib-hero"><div><p class="eyebrow">INSTAGRAM INTELLIGENCE</p><h1>Browse your discovery searches in one place.</h1><p>Open a saved Instagram search, capture the posts worth studying, then review them here as a visual inspiration feed.</p></div><div><a class="button" href="'.esc_url(admin_url('admin.php?page=tng-social-discovery')).'">Discovery Inbox</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=tng-social-intelligence-patterns')).'">Intelligence</a></div></section>';
        if(isset($_GET['tng_notice']))echo '<div class="notice notice-success inline"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['tng_notice']))).'</p></div>';
        echo '<section class="tng-ib-searches"><div class="head"><div><p class="eyebrow">SAVED SEARCHES</p><h2>Instagram browse collections</h2></div><a class="button" href="'.esc_url(admin_url('post-new.php?post_type='.self::WATCH)).'">+ Add watch</a></div><div class="chips"><a class="chip '.(!$active?'active':'').'" href="'.esc_url(admin_url('admin.php?page=tng-instagram-browser')).'">All captured</a>';
        foreach($watches as $w){$id=(int)$w->ID;$type=self::meta($id,'_tng_watch_type','topic');echo '<span class="watch-wrap"><a class="chip '.($active===$id?'active':'').'" href="'.esc_url(add_query_arg(['page'=>'tng-instagram-browser','watch'=>$id],admin_url('admin.php'))).'">'.esc_html(get_the_title($id)).'<small>'.esc_html(ucwords($type)).'</small></a><a class="open" href="'.esc_url(self::instagram_search_url($id)).'" target="_blank" rel="noopener">Open ↗</a></span>';}
        echo '</div></section>';
        echo '<section class="tng-ib-feed"><div class="head"><div><p class="eyebrow">VISUAL REVIEW</p><h2>'.count($candidates).' captured post'.(count($candidates)===1?'':'s').'</h2></div><p>Capture posts in Discovery Inbox; Browse keeps the strongest source-linked inspiration together.</p></div>';
        if(!$candidates)echo '<div class="empty"><strong>No captured posts in this view yet.</strong><p>Open one of the Instagram searches above, then use Quick Capture in Discovery Inbox for the posts you want to study.</p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=tng-social-discovery')).'">Open Discovery Inbox</a></div>';
        else {echo '<div class="tng-ib-grid">';foreach($candidates as $p)echo self::card($p);echo '</div>';}
        echo '</section></div>';
    }

    public static function assets(): void {
        if(!isset($_GET['page'])||$_GET['page']!=='tng-instagram-browser')return;
        wp_register_style('tng-instagram-browser',false,[],defined('TNG_OS_VERSION')?TNG_OS_VERSION:null);wp_enqueue_style('tng-instagram-browser');
        wp_add_inline_style('tng-instagram-browser','.tng-ib{max-width:1240px}.tng-ib-hero{margin:20px 0;background:linear-gradient(135deg,#073c2b,#176b45);color:#fff;border-radius:24px;padding:32px;display:flex;justify-content:space-between;gap:24px;align-items:flex-start}.tng-ib-hero h1{color:#fff;font-size:34px;margin:5px 0 8px}.tng-ib-hero p{max-width:720px;font-size:15px}.eyebrow{font-size:11px;font-weight:800;letter-spacing:.13em;color:#f05b25}.tng-ib-searches,.tng-ib-feed{background:#fff;border:1px solid #dfe5e1;border-radius:20px;padding:22px;margin:18px 0}.head{display:flex;justify-content:space-between;gap:20px;align-items:flex-start}.head h2{margin:2px 0;font-size:26px;color:#173629}.head>p{color:#6f7c75;max-width:430px}.chips{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.watch-wrap{display:flex;align-items:stretch}.chip,.open{border:1px solid #d9e1dc;background:#fff;text-decoration:none;color:#203d31;padding:10px 13px}.chip{border-radius:999px}.watch-wrap .chip{border-radius:999px 0 0 999px}.watch-wrap .open{border-left:0;border-radius:0 999px 999px 0;font-size:12px;display:flex;align-items:center}.chip.active{background:#153f2f;color:#fff;border-color:#153f2f}.chip small{display:block;opacity:.65;font-size:9px;margin-top:2px}.tng-ib-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:18px}.tng-ib-card{border:1px solid #dfe5e1;border-radius:18px;overflow:hidden;background:#fff;box-shadow:0 8px 24px rgba(20,57,42,.05)}.tng-ib-card>img,.tng-ib-placeholder{width:100%;aspect-ratio:1/1;object-fit:cover}.tng-ib-placeholder{background:linear-gradient(145deg,#0b3f2d,#1d6848);color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px}.tng-ib-placeholder span{font-size:48px}.tng-ib-placeholder strong{font-size:20px}.tng-ib-placeholder small{opacity:.72}.tng-ib-card-body{padding:17px;position:relative}.tng-ib-score{position:absolute;right:15px;top:15px;background:#fff3cf;border-radius:12px;padding:8px 10px;font-size:21px;font-weight:800;color:#264c3a;text-align:center}.tng-ib-score small{display:block;font-size:8px;text-transform:uppercase;letter-spacing:.08em}.tng-ib-creator{color:#e75a24;font-weight:700;margin:0 70px 5px 0}.tng-ib-card h3{font-size:20px;margin:0 70px 8px 0;color:#18372a}.excerpt,.meta,.tags{color:#68766f}.meta{font-size:12px;font-weight:700}.signals{display:flex;gap:12px;margin:12px 0;font-size:12px;color:#4d6358}.tags{font-size:12px}.actions{display:flex;flex-wrap:wrap;gap:6px;margin-top:14px}.empty{text-align:center;padding:50px 20px;border:1px dashed #cfdad3;border-radius:16px;margin-top:18px;color:#617168}@media(max-width:1000px){.tng-ib-grid{grid-template-columns:1fr 1fr}.tng-ib-hero{flex-direction:column}}@media(max-width:700px){.tng-ib-grid{grid-template-columns:1fr}.head{flex-direction:column}}');
    }
}
TNG_Instagram_Browser::boot();
