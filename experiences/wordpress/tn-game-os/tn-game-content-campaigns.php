<?php
/**
 * TN Game Content Campaigns
 * Reusable campaign plans for Content Studio.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Content_Campaigns {
    private const TYPE = 'tng_campaign';
    private const NONCE = 'tng_content_campaign_action';

    public static function boot(): void {
        static $booted = false;
        if ($booted) return;
        $booted = true;
        add_action('init', [__CLASS__, 'register_type']);
        add_action('admin_menu', [__CLASS__, 'menu'], 101);
        add_action('admin_post_tng_campaign_save', [__CLASS__, 'save']);
        add_action('admin_post_tng_campaign_archive', [__CLASS__, 'archive']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function register_type(): void {
        register_post_type(self::TYPE, [
            'labels' => ['name'=>'Content Campaigns','singular_name'=>'Content Campaign'],
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
        ]);
    }

    public static function menu(): void {
        add_submenu_page('tng-content-studio', 'Campaigns', 'Campaigns', 'edit_posts', 'tng-content-campaigns', [__CLASS__, 'render']);
    }

    private static function url(array $args=[]): string {
        return add_query_arg(array_merge(['page'=>'tng-content-campaigns'], $args), admin_url('admin.php'));
    }

    private static function meta(int $id, string $key, string $fallback=''): string {
        $v = trim((string)get_post_meta($id, $key, true));
        return $v !== '' ? $v : $fallback;
    }

    private static function valid_date(string $date): bool {
        if ($date === '') return true;
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        return $dt && $dt->format('Y-m-d') === $date;
    }

    public static function save(): void {
        if (!current_user_can('edit_posts')) wp_die('Not allowed.');
        check_admin_referer(self::NONCE);
        $id = absint($_POST['campaign_id'] ?? 0);
        if ($id && (get_post_type($id) !== self::TYPE || !current_user_can('edit_post', $id))) wp_die('Invalid campaign.');

        $name = sanitize_text_field(wp_unslash($_POST['campaign_name'] ?? ''));
        $goal = sanitize_textarea_field(wp_unslash($_POST['campaign_goal'] ?? ''));
        $start = sanitize_text_field(wp_unslash($_POST['start_date'] ?? ''));
        $end = sanitize_text_field(wp_unslash($_POST['end_date'] ?? ''));
        $target = max(1, min(100, absint($_POST['target_posts'] ?? 5)));
        $formats = array_values(array_intersect((array)($_POST['formats'] ?? []), ['reel','carousel','photo','story','long_video','post']));
        $focus = sanitize_text_field(wp_unslash($_POST['focus'] ?? ''));
        $status = sanitize_key(wp_unslash($_POST['campaign_status'] ?? 'active'));
        if (!in_array($status, ['draft','active','complete','archived'], true)) $status = 'active';

        if ($name === '') wp_safe_redirect(self::url(['tng_notice'=>'Campaign name is required.']));
        if (!self::valid_date($start) || !self::valid_date($end)) wp_safe_redirect(self::url(['tng_notice'=>'Use valid campaign dates.']));
        if ($start && $end && $end < $start) wp_safe_redirect(self::url(['tng_notice'=>'End date must be after the start date.']));

        if (!$id) {
            $id = wp_insert_post(['post_type'=>self::TYPE,'post_status'=>'publish','post_title'=>$name], true);
            if (is_wp_error($id)) wp_die($id->get_error_message());
        } else {
            wp_update_post(['ID'=>$id,'post_title'=>$name]);
        }

        update_post_meta($id, '_tng_campaign_goal', $goal);
        update_post_meta($id, '_tng_campaign_start', $start);
        update_post_meta($id, '_tng_campaign_end', $end);
        update_post_meta($id, '_tng_campaign_target_posts', $target);
        update_post_meta($id, '_tng_campaign_formats', $formats);
        update_post_meta($id, '_tng_campaign_focus', $focus);
        update_post_meta($id, '_tng_campaign_status', $status);
        wp_safe_redirect(self::url(['edit'=>$id,'tng_notice'=>'Campaign saved.']));
        exit;
    }

    public static function archive(): void {
        if (!current_user_can('edit_posts')) wp_die('Not allowed.');
        check_admin_referer(self::NONCE);
        $id = absint($_POST['campaign_id'] ?? 0);
        if (!$id || get_post_type($id) !== self::TYPE || !current_user_can('edit_post', $id)) wp_die('Invalid campaign.');
        update_post_meta($id, '_tng_campaign_status', 'archived');
        wp_safe_redirect(self::url(['tng_notice'=>'Campaign archived.']));
        exit;
    }

    public static function campaigns(bool $include_archived=false): array {
        $items = get_posts(['post_type'=>self::TYPE,'post_status'=>'publish','posts_per_page'=>100,'orderby'=>'date','order'=>'DESC']);
        if ($include_archived) return $items;
        return array_values(array_filter($items, static fn($p)=>(string)get_post_meta($p->ID,'_tng_campaign_status',true) !== 'archived'));
    }

    public static function campaign_stats(WP_Post $campaign): array {
        $name = $campaign->post_title;
        $items = get_posts([
            'post_type'=>'tng_social_item','post_status'=>['publish','draft','private'],'posts_per_page'=>300,
            'meta_query'=>[['key'=>'_tng_campaign','value'=>$name,'compare'=>'=']],
        ]);
        $planned=0; $published=0; $formats=[];
        foreach ($items as $item) {
            $status = (string)get_post_meta($item->ID,'_tng_plan_status',true);
            if (get_post_meta($item->ID,'_tng_planned_date',true)) $planned++;
            if ($status === 'published') $published++;
            $format = (string)get_post_meta($item->ID,'_tng_content_format',true);
            if ($format) $formats[$format]=($formats[$format]??0)+1;
        }
        $target=max(1,(int)get_post_meta($campaign->ID,'_tng_campaign_target_posts',true));
        return ['items'=>count($items),'planned'=>$planned,'published'=>$published,'target'=>$target,'percent'=>min(100,(int)round((count($items)/$target)*100)),'formats'=>$formats];
    }

    public static function active_for_date(string $date): array {
        if (!self::valid_date($date) || $date === '') return [];
        $out=[];
        foreach (self::campaigns() as $c) {
            $status=self::meta($c->ID,'_tng_campaign_status','active');
            if ($status !== 'active') continue;
            $start=self::meta($c->ID,'_tng_campaign_start');
            $end=self::meta($c->ID,'_tng_campaign_end');
            if (($start==='' || $date >= $start) && ($end==='' || $date <= $end)) $out[]=$c;
        }
        return $out;
    }

    private static function date_range(WP_Post $c): string {
        $s=self::meta($c->ID,'_tng_campaign_start'); $e=self::meta($c->ID,'_tng_campaign_end');
        if (!$s && !$e) return 'Ongoing';
        try {
            $a=$s ? new DateTimeImmutable($s) : null; $b=$e ? new DateTimeImmutable($e) : null;
            if ($a && $b) return $a->format('M j') . ' – ' . $b->format('M j, Y');
            return ($a ?: $b)->format('M j, Y');
        } catch (Exception $ex) { return 'Dates not set'; }
    }

    public static function render(): void {
        if (!current_user_can('edit_posts')) return;
        $edit=absint($_GET['edit'] ?? 0);
        $current=$edit && get_post_type($edit)===self::TYPE ? get_post($edit) : null;
        $campaigns=self::campaigns(true);
        $today=current_time('Y-m-d');
        $active=array_filter($campaigns,static fn($c)=>(string)get_post_meta($c->ID,'_tng_campaign_status',true)==='active');
        ?>
        <div class="wrap tng-campaigns">
            <section class="campaign-hero">
                <div><p class="eyebrow">CONTENT STUDIO</p><h1>Campaign Planner</h1><p>Turn a launch, season, event, or destination push into a measurable content plan.</p></div>
                <div class="hero-count"><strong><?php echo count($active); ?></strong><span>active campaign<?php echo count($active)===1?'':'s'; ?></span></div>
            </section>
            <?php if(isset($_GET['tng_notice'])): ?><div class="notice notice-success inline"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['tng_notice']))); ?></p></div><?php endif; ?>
            <div class="campaign-layout">
                <main>
                    <div class="section-head"><div><p class="eyebrow">CAMPAIGNS</p><h2>Content plans</h2></div><a class="button" href="<?php echo esc_url(self::url()); ?>">+ New campaign</a></div>
                    <div class="campaign-grid">
                        <?php if(!$campaigns): ?><div class="empty">No campaigns yet. Create your first campaign to give the calendar a goal.</div><?php endif; ?>
                        <?php foreach($campaigns as $c): $stats=self::campaign_stats($c); $status=self::meta($c->ID,'_tng_campaign_status','draft'); $focus=self::meta($c->ID,'_tng_campaign_focus'); $goal=self::meta($c->ID,'_tng_campaign_goal'); ?>
                            <article class="campaign-card status-<?php echo esc_attr($status); ?>">
                                <div class="card-top"><span class="status"><?php echo esc_html(ucfirst($status)); ?></span><span><?php echo esc_html(self::date_range($c)); ?></span></div>
                                <h3><?php echo esc_html($c->post_title); ?></h3>
                                <?php if($goal): ?><p><?php echo esc_html(wp_trim_words($goal,22)); ?></p><?php endif; ?>
                                <?php if($focus): ?><div class="focus">📍 <?php echo esc_html($focus); ?></div><?php endif; ?>
                                <div class="progress"><span style="width:<?php echo esc_attr($stats['percent']); ?>%"></span></div>
                                <div class="metrics"><div><strong><?php echo $stats['items']; ?></strong><span>created</span></div><div><strong><?php echo $stats['planned']; ?></strong><span>planned</span></div><div><strong><?php echo $stats['target']; ?></strong><span>target</span></div></div>
                                <div class="actions"><a href="<?php echo esc_url(self::url(['edit'=>$c->ID])); ?>">Edit plan</a><a href="<?php echo esc_url(admin_url('admin.php?page=tng-content-calendar')); ?>">Calendar →</a></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </main>
                <aside class="campaign-editor">
                    <p class="eyebrow"><?php echo $current?'EDIT CAMPAIGN':'NEW CAMPAIGN'; ?></p><h2><?php echo $current?esc_html($current->post_title):'Create a campaign'; ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="tng_campaign_save"><input type="hidden" name="campaign_id" value="<?php echo $current?esc_attr($current->ID):0; ?>"><?php wp_nonce_field(self::NONCE); ?>
                        <label>Name<input type="text" name="campaign_name" required value="<?php echo $current?esc_attr($current->post_title):''; ?>" placeholder="Coming Soon"></label>
                        <label>Goal<textarea name="campaign_goal" rows="4" placeholder="What should this campaign accomplish?"><?php echo $current?esc_textarea(self::meta($current->ID,'_tng_campaign_goal')):''; ?></textarea></label>
                        <div class="two"><label>Starts<input type="date" name="start_date" value="<?php echo $current?esc_attr(self::meta($current->ID,'_tng_campaign_start')):''; ?>"></label><label>Ends<input type="date" name="end_date" value="<?php echo $current?esc_attr(self::meta($current->ID,'_tng_campaign_end')):''; ?>"></label></div>
                        <div class="two"><label>Target posts<input type="number" min="1" max="100" name="target_posts" value="<?php echo $current?esc_attr(self::meta($current->ID,'_tng_campaign_target_posts','5')):'5'; ?>"></label><label>Status<select name="campaign_status"><?php $cs=$current?self::meta($current->ID,'_tng_campaign_status','active'):'active'; foreach(['draft','active','complete','archived'] as $s): ?><option value="<?php echo esc_attr($s); ?>" <?php selected($cs,$s); ?>><?php echo esc_html(ucfirst($s)); ?></option><?php endforeach; ?></select></label></div>
                        <label>Focus<input type="text" name="focus" value="<?php echo $current?esc_attr(self::meta($current->ID,'_tng_campaign_focus')):''; ?>" placeholder="South Cumberland, Foster Falls, local food..."></label>
                        <fieldset><legend>Preferred formats</legend><?php $selected=$current?(array)get_post_meta($current->ID,'_tng_campaign_formats',true):['reel','carousel','story']; foreach(['reel'=>'Reels','carousel'=>'Carousels','photo'=>'Photos','story'=>'Stories','long_video'=>'Long video','post'=>'Posts'] as $key=>$label): ?><label class="check"><input type="checkbox" name="formats[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key,$selected,true)); ?>><?php echo esc_html($label); ?></label><?php endforeach; ?></fieldset>
                        <button class="button button-primary button-large" type="submit"><?php echo $current?'Save campaign':'Create campaign'; ?></button>
                    </form>
                </aside>
            </div>
        </div>
        <?php
    }

    public static function assets(): void {
        if (!isset($_GET['page']) || $_GET['page']!=='tng-content-campaigns') return;
        wp_register_style('tng-content-campaigns',false,[],defined('TNG_OS_VERSION')?TNG_OS_VERSION:null); wp_enqueue_style('tng-content-campaigns');
        wp_add_inline_style('tng-content-campaigns', '.tng-campaigns{max-width:1450px}.campaign-hero{margin:20px 0;background:linear-gradient(135deg,#073c2b,#176b45);color:#fff;border-radius:24px;padding:32px;display:flex;justify-content:space-between;gap:24px;align-items:center}.campaign-hero h1{color:#fff;font-size:36px;margin:5px 0 8px}.campaign-hero p{font-size:15px;max-width:720px}.eyebrow{font-size:11px;font-weight:800;letter-spacing:.14em;color:#f05b25;margin:0}.hero-count{background:rgba(255,255,255,.11);border:1px solid rgba(255,255,255,.18);border-radius:18px;padding:18px 24px;text-align:center}.hero-count strong{display:block;font-size:30px}.campaign-layout{display:grid;grid-template-columns:minmax(0,1fr) 390px;gap:20px;align-items:start}.section-head{background:#fff;border:1px solid #dfe5df;border-radius:18px;padding:18px 20px;display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}.section-head h2{margin:3px 0;color:#153e2e}.campaign-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.campaign-card,.campaign-editor{background:#fff;border:1px solid #dfe5df;border-radius:20px;padding:20px}.campaign-card{border-top:4px solid #a8b4ac}.campaign-card.status-active{border-top-color:#176b45}.campaign-card.status-complete{border-top-color:#3157d5}.campaign-card.status-archived{opacity:.65}.card-top{display:flex;justify-content:space-between;gap:8px;color:#718078;font-size:11px}.card-top .status{font-weight:800;text-transform:uppercase;color:#176b45}.campaign-card h3{font-size:22px;color:#143c2c;margin:10px 0 6px}.campaign-card p{color:#69776f;min-height:42px}.focus{font-size:12px;color:#607168;margin:10px 0}.progress{height:8px;background:#e7eee9;border-radius:999px;overflow:hidden}.progress span{height:100%;display:block;background:#f05b25}.metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:14px 0}.metrics div{background:#f6f9f7;border-radius:12px;padding:10px}.metrics strong{display:block;font-size:20px;color:#153e2e}.metrics span{font-size:10px;color:#748178;text-transform:uppercase}.actions{display:flex;gap:14px}.actions a{color:#d94a18;font-weight:700;text-decoration:none}.campaign-editor{position:sticky;top:42px}.campaign-editor h2{color:#153e2e;font-size:26px;margin:5px 0 18px}.campaign-editor label{display:block;font-weight:700;color:#264838;margin:12px 0 5px}.campaign-editor input[type=text],.campaign-editor input[type=date],.campaign-editor input[type=number],.campaign-editor textarea,.campaign-editor select{width:100%;margin-top:6px}.campaign-editor .two{display:grid;grid-template-columns:1fr 1fr;gap:10px}.campaign-editor fieldset{border:1px solid #dfe5df;border-radius:14px;padding:12px;margin:14px 0}.campaign-editor legend{font-weight:800;color:#264838}.campaign-editor .check{font-weight:500;display:inline-flex;gap:5px;margin:5px 12px 5px 0}.empty{background:#fff;border:1px dashed #cad5cc;border-radius:18px;padding:28px;color:#758279;grid-column:1/-1}@media(max-width:1100px){.campaign-layout{grid-template-columns:1fr}.campaign-editor{position:static}.campaign-grid{grid-template-columns:1fr}}');
    }
}
TNG_Content_Campaigns::boot();
