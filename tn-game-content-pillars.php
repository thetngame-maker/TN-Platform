<?php
/**
 * TN Game Content Pillars
 * Adds campaign pillar targets, coverage intelligence, and pillar-aware content creation.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Content_Pillars {
    private const CAMPAIGN = 'tng_campaign';
    private const ITEM = 'tng_social_item';
    private const NONCE = 'tng_content_pillars_action';

    private static function definitions(): array {
        return [
            'brand_intro' => ['label'=>'Brand introduction','desc'=>'Explain what The TN Game is, who it is for, and why it exists.'],
            'feature_reveal' => ['label'=>'Feature reveal','desc'=>'Show Trip Mode, games, XP, maps, checkpoints, profiles, or another product feature.'],
            'destination_inspiration' => ['label'=>'Destination inspiration','desc'=>'Give people a Tennessee place, trail, sight, or itinerary worth saving.'],
            'local_business' => ['label'=>'Local business','desc'=>'Feature restaurants, shops, events, stays, and locally owned favorites.'],
            'gameplay' => ['label'=>'Gameplay','desc'=>'Show how challenges, checkpoints, rewards, competition, or exploration works.'],
            'behind_scenes' => ['label'=>'Behind the scenes','desc'=>'Show the build, field testing, research, photography, route creation, or launch process.'],
        ];
    }

    public static function boot(): void {
        static $booted = false;
        if ($booted) return;
        $booted = true;
        add_action('admin_menu', [__CLASS__, 'menu'], 102);
        add_action('admin_post_tng_save_campaign_pillars', [__CLASS__, 'save_campaign']);
        add_action('save_post_' . self::ITEM, [__CLASS__, 'capture_idea_pillar'], 10, 3);
        add_action('admin_post_tng_save_content_production', [__CLASS__, 'capture_builder_pillar'], 1);
        add_action('admin_footer', [__CLASS__, 'footer']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function menu(): void {
        add_submenu_page('tng-content-studio', 'Campaign Pillars', 'Campaign Pillars', 'edit_posts', 'tng-content-pillars', [__CLASS__, 'render']);
    }

    private static function page(): string {
        return sanitize_key(wp_unslash($_GET['page'] ?? ''));
    }

    private static function campaigns(): array {
        if (!class_exists('TNG_Content_Campaigns')) return [];
        return TNG_Content_Campaigns::campaigns();
    }

    private static function campaign_by_name(string $name): ?WP_Post {
        foreach (self::campaigns() as $campaign) if ($campaign->post_title === $name) return $campaign;
        return null;
    }

    private static function selected_campaign(): ?WP_Post {
        $id = absint($_GET['campaign_id'] ?? 0);
        if ($id && get_post_type($id) === self::CAMPAIGN) return get_post($id);
        $name = sanitize_text_field(wp_unslash($_GET['campaign'] ?? ''));
        if ($name) return self::campaign_by_name($name);
        $campaigns = self::campaigns();
        foreach ($campaigns as $campaign) {
            if ((string)get_post_meta($campaign->ID, '_tng_campaign_status', true) === 'active') return $campaign;
        }
        return $campaigns[0] ?? null;
    }

    private static function default_targets(WP_Post $campaign): array {
        $defs = self::definitions();
        $target = max(1, (int)get_post_meta($campaign->ID, '_tng_campaign_target_posts', true));
        $keys = array_keys($defs);
        $base = intdiv($target, count($keys));
        $extra = $target % count($keys);
        $out = [];
        foreach ($keys as $i => $key) $out[$key] = $base + ($i < $extra ? 1 : 0);
        return $out;
    }

    public static function targets(WP_Post $campaign): array {
        $saved = get_post_meta($campaign->ID, '_tng_campaign_pillar_targets', true);
        if (!is_array($saved) || !$saved) return self::default_targets($campaign);
        $out = [];
        foreach (self::definitions() as $key => $def) $out[$key] = max(0, (int)($saved[$key] ?? 0));
        return $out;
    }

    private static function items_for_campaign(WP_Post $campaign): array {
        return get_posts([
            'post_type'=>self::ITEM,
            'post_status'=>['publish','draft','private'],
            'posts_per_page'=>300,
            'meta_query'=>[['key'=>'_tng_campaign','value'=>$campaign->post_title,'compare'=>'=']],
        ]);
    }

    public static function coverage(WP_Post $campaign): array {
        $targets = self::targets($campaign);
        $coverage = [];
        foreach (self::definitions() as $key=>$def) $coverage[$key] = ['target'=>$targets[$key] ?? 0,'created'=>0,'planned'=>0,'published'=>0];
        $unassigned = 0;
        foreach (self::items_for_campaign($campaign) as $item) {
            $pillar = (string)get_post_meta($item->ID, '_tng_content_pillar', true);
            if (!$pillar || !isset($coverage[$pillar])) { $unassigned++; continue; }
            $coverage[$pillar]['created']++;
            if (get_post_meta($item->ID, '_tng_planned_date', true)) $coverage[$pillar]['planned']++;
            if ((string)get_post_meta($item->ID, '_tng_plan_status', true) === 'published') $coverage[$pillar]['published']++;
        }
        return ['pillars'=>$coverage,'unassigned'=>$unassigned];
    }

    public static function save_campaign(): void {
        if (!current_user_can('edit_posts')) wp_die('Not allowed.');
        check_admin_referer(self::NONCE);
        $id = absint($_POST['campaign_id'] ?? 0);
        if (!$id || get_post_type($id) !== self::CAMPAIGN || !current_user_can('edit_post', $id)) wp_die('Invalid campaign.');
        $targets = [];
        foreach (self::definitions() as $key=>$def) $targets[$key] = max(0, min(100, absint($_POST['pillar_' . $key] ?? 0)));
        update_post_meta($id, '_tng_campaign_pillar_targets', $targets);
        wp_safe_redirect(add_query_arg(['page'=>'tng-content-pillars','campaign_id'=>$id,'saved'=>1], admin_url('admin.php')));
        exit;
    }

    public static function capture_idea_pillar(int $post_id, WP_Post $post, bool $update): void {
        if (!isset($_POST['idea_pillar'])) return;
        $pillar = sanitize_key(wp_unslash($_POST['idea_pillar']));
        if (isset(self::definitions()[$pillar])) update_post_meta($post_id, '_tng_content_pillar', $pillar);
    }

    public static function capture_builder_pillar(): void {
        if (!current_user_can('edit_posts')) return;
        $id = absint($_POST['idea_id'] ?? 0);
        $pillar = sanitize_key(wp_unslash($_POST['pillar'] ?? ''));
        if ($id && get_post_type($id) === self::ITEM && current_user_can('edit_post', $id) && isset(self::definitions()[$pillar])) {
            update_post_meta($id, '_tng_content_pillar', $pillar);
        }
    }

    private static function idea_url(WP_Post $campaign, string $pillar): string {
        return add_query_arg(['page'=>'tng-content-idea-generator','campaign'=>$campaign->post_title,'pillar'=>$pillar], admin_url('admin.php'));
    }

    public static function render(): void {
        if (!current_user_can('edit_posts')) return;
        $campaign = self::selected_campaign();
        $defs = self::definitions();
        ?>
        <div class="wrap tng-pillars">
            <section class="pillar-hero">
                <div><p class="eyebrow">CONTENT STUDIO</p><h1>Campaign Content Pillars</h1><p>Decide what stories a campaign needs, then let TN Game show you which beats are covered and which still need content.</p></div>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-content-campaigns')); ?>">Campaign Planner</a>
            </section>
            <?php if(isset($_GET['saved'])): ?><div class="notice notice-success inline"><p>Campaign pillar targets saved.</p></div><?php endif; ?>
            <?php if(!$campaign): ?><section class="pillar-empty">Create a campaign first, then return here to define its content pillars.</section></div><?php return; endif;
            $targets = self::targets($campaign); $coverage = self::coverage($campaign); $total_target = array_sum($targets); $total_created = array_sum(array_column($coverage['pillars'],'created'));
            ?>
            <section class="pillar-toolbar">
                <div><p class="eyebrow">CAMPAIGN</p><h2><?php echo esc_html($campaign->post_title); ?></h2><span><?php echo esc_html($total_created); ?> pillar-assigned posts · <?php echo esc_html($total_target); ?> pillar targets</span></div>
                <form method="get"><input type="hidden" name="page" value="tng-content-pillars"><select name="campaign_id" onchange="this.form.submit()"><?php foreach(self::campaigns() as $c): ?><option value="<?php echo (int)$c->ID; ?>" <?php selected($campaign->ID,$c->ID); ?>><?php echo esc_html($c->post_title); ?></option><?php endforeach; ?></select></form>
            </section>
            <?php if($coverage['unassigned']): ?><div class="pillar-unassigned"><strong><?php echo (int)$coverage['unassigned']; ?> campaign post<?php echo $coverage['unassigned']===1?'':'s'; ?> need a pillar.</strong><span>Open them in Post Builder and assign the story role so campaign intelligence can count them correctly.</span></div><?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tng_save_campaign_pillars"><input type="hidden" name="campaign_id" value="<?php echo (int)$campaign->ID; ?>"><?php wp_nonce_field(self::NONCE); ?>
                <div class="pillar-grid">
                    <?php foreach($defs as $key=>$def): $row=$coverage['pillars'][$key]; $remaining=max(0,$row['target']-$row['created']); $percent=$row['target']?min(100,(int)round(($row['created']/$row['target'])*100)):($row['created']?100:0); ?>
                    <article class="pillar-card <?php echo $remaining===0&&$row['target']>0?'complete':''; ?>">
                        <div class="pillar-top"><div><span class="state"><?php echo $remaining===0&&$row['target']>0?'Covered':($remaining.' needed'); ?></span><h3><?php echo esc_html($def['label']); ?></h3></div><label>Target<input type="number" min="0" max="100" name="pillar_<?php echo esc_attr($key); ?>" value="<?php echo (int)$row['target']; ?>"></label></div>
                        <p><?php echo esc_html($def['desc']); ?></p>
                        <div class="bar"><span style="width:<?php echo esc_attr($percent); ?>%"></span></div>
                        <div class="pillar-metrics"><span><strong><?php echo (int)$row['created']; ?></strong> created</span><span><strong><?php echo (int)$row['planned']; ?></strong> planned</span><span><strong><?php echo (int)$row['published']; ?></strong> published</span></div>
                        <?php if($remaining>0): ?><a class="generate" href="<?php echo esc_url(self::idea_url($campaign,$key)); ?>">Generate missing idea →</a><?php else: ?><span class="covered">✓ Target covered</span><?php endif; ?>
                    </article>
                    <?php endforeach; ?>
                </div>
                <div class="pillar-save"><span>Targets total: <strong id="tng-pillar-total"><?php echo (int)$total_target; ?></strong> · Campaign post target: <strong><?php echo (int)get_post_meta($campaign->ID,'_tng_campaign_target_posts',true); ?></strong></span><button class="button button-primary button-large">Save pillar plan</button></div>
            </form>
        </div>
        <?php
    }

    private static function page_items(): array {
        $items = get_posts(['post_type'=>self::ITEM,'post_status'=>['publish','draft','private'],'posts_per_page'=>300,'meta_query'=>[['key'=>'_tng_content_pillar','compare'=>'EXISTS']]]);
        $out=[]; foreach($items as $item) $out[(int)$item->ID]=(string)get_post_meta($item->ID,'_tng_content_pillar',true); return $out;
    }

    public static function footer(): void {
        if (!current_user_can('edit_posts')) return;
        $page=self::page();
        if (!in_array($page,['tng-content-idea-generator','tng-content-post-builder','tng-content-calendar'],true)) return;
        $defs=self::definitions(); $defs_json=wp_json_encode($defs); $item_json=wp_json_encode(self::page_items());
        $campaign_rows=[];
        foreach(self::campaigns() as $c){$cov=self::coverage($c);$campaign_rows[]=['name'=>$c->post_title,'status'=>(string)get_post_meta($c->ID,'_tng_campaign_status',true),'pillars'=>$cov['pillars'],'unassigned'=>$cov['unassigned']];}
        $campaign_json=wp_json_encode($campaign_rows);
        ?>
        <script id="tng-content-pillars-js">
        (()=>{
            const page=<?php echo wp_json_encode($page); ?>, defs=<?php echo $defs_json ?: '{}'; ?>, items=<?php echo $item_json ?: '{}'; ?>, campaigns=<?php echo $campaign_json ?: '[]'; ?>;
            const qs=new URLSearchParams(location.search), requested=qs.get('pillar')||'';
            const makeSelect=(name,current)=>{const s=document.createElement('select');s.name=name;s.className='tng-pillar-select';const b=document.createElement('option');b.value='';b.textContent='Choose pillar';s.append(b);Object.entries(defs).forEach(([k,d])=>{const o=document.createElement('option');o.value=k;o.textContent=d.label;if(k===current)o.selected=true;s.append(o)});return s};
            if(page==='tng-content-idea-generator'){
                document.querySelectorAll('.tng-cig-card').forEach(card=>{if(card.querySelector('[name="idea_pillar"]'))return;const fields=card.querySelector('.fields');if(!fields)return;const box=document.createElement('div');box.innerHTML='<label>CONTENT PILLAR</label>';box.append(makeSelect('idea_pillar',requested));fields.insertAdjacentElement('afterend',box);});
                if(requested&&defs[requested]){
                    document.querySelectorAll('.tng-cig-card').forEach((card,i)=>{if(i>0)return;const place=card.querySelector('[name="idea_place"]')?.value||'Tennessee';const title=card.querySelector('[name="idea_title"]'),hook=card.querySelector('[name="idea_hook"]'),angle=card.querySelector('[name="idea_angle"]');const t=templates(requested,place);if(title)title.value=t.title;if(hook)hook.value=t.hook;if(angle)angle.value=t.angle;});
                }
            }
            if(page==='tng-content-post-builder'){
                const form=document.querySelector('.tng-cpb-builder form');if(form&&!form.querySelector('[name="pillar"]')){const id=form.querySelector('[name="idea_id"]')?.value||'';const current=items[id]||'';const grid=form.querySelector('.tng-cpb-mini-grid');if(grid){const box=document.createElement('div');box.innerHTML='<label>CONTENT PILLAR</label>';box.append(makeSelect('pillar',current));grid.append(box);}}
            }
            if(page==='tng-content-calendar'){
                document.querySelectorAll('.tng-cal-card[data-item-id]').forEach(card=>{const pillar=items[card.dataset.itemId];if(!pillar||!defs[pillar]||card.querySelector('.tng-pillar-badge'))return;const meta=card.querySelector('.meta');if(meta){const badge=document.createElement('span');badge.className='tng-pillar-badge';badge.textContent='◆ '+defs[pillar].label;meta.append(badge);}});
                const anchor=document.querySelector('.tng-calendar-campaigns')||document.querySelector('.tng-cal-toolbar');if(anchor&&!document.querySelector('.tng-pillar-calendar')){const active=campaigns.filter(c=>c.status==='active');if(active.length){const section=document.createElement('section');section.className='tng-pillar-calendar';section.innerHTML='<div class="tng-pillar-cal-head"><div><p class="eyebrow">CAMPAIGN PILLARS</p><h2>Missing campaign beats</h2></div><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-content-pillars')); ?>">Manage pillars</a></div><div class="tng-pillar-cal-grid"></div>';const grid=section.querySelector('.tng-pillar-cal-grid');active.forEach(c=>{const missing=Object.entries(c.pillars).filter(([k,v])=>v.target>v.created).sort((a,b)=>(b[1].target-b[1].created)-(a[1].target-a[1].created));if(!missing.length)return;const card=document.createElement('article');card.innerHTML='<strong>'+esc(c.name)+'</strong><div></div>';const list=card.querySelector('div');missing.slice(0,4).forEach(([k,v])=>{const a=document.createElement('a');a.href='<?php echo esc_js(admin_url('admin.php?page=tng-content-idea-generator')); ?>&campaign='+encodeURIComponent(c.name)+'&pillar='+encodeURIComponent(k);a.innerHTML='<span>'+esc(defs[k]?.label||k)+'</span><b>'+Math.max(0,v.target-v.created)+' needed →</b>';list.append(a)});grid.append(card)});if(grid.children.length)anchor.insertAdjacentElement('afterend',section);}}
            }
            function templates(p,place){const rows={brand_intro:{title:'What is The TN Game?',hook:'Tennessee is already an adventure. We are turning it into one you can play.',angle:'Introduce The TN Game in a simple, visual way. Show how people can discover places, build trips, play challenges, and earn progress without trying to explain every feature at once.'},feature_reveal:{title:'One TN Game feature that changes a day out',hook:'This is what happens when your Tennessee road trip becomes interactive.',angle:'Reveal one specific TN Game feature and demonstrate the real-world benefit. Use '+place+' as the setting if it fits, then end with a clear reason to follow or save for launch.'},destination_inspiration:{title:'Add '+place+' to your Tennessee list',hook:'Save this Tennessee stop for the next day you need an adventure.',angle:'Build an original destination-first post around '+place+'. Give one strong reason to visit, one useful planning detail, and one nearby bonus discovery.'},local_business:{title:'The local stop worth adding to your route',hook:'Do not make this Tennessee trip without a local stop like this.',angle:'Feature a locally owned restaurant, shop, event, stay, or experience as part of the larger TN Game trip. Focus on what makes the stop useful or memorable rather than making a generic promotion.'},gameplay:{title:'How exploring Tennessee becomes a game',hook:'What if finding the next stop was only half the challenge?',angle:'Demonstrate a gameplay behavior: checking in, completing a challenge, earning XP, finding a checkpoint, competing, or progressing. Keep the mechanics visual and easy to understand.'},behind_scenes:{title:'Building The TN Game in the real world',hook:'A lot of this app gets built nowhere near a desk.',angle:'Show the work behind The TN Game: scouting, testing GPS, photographing a place, building routes, researching stops, or fixing something in the field. Make the build process part of the brand story.'}};return rows[p]||rows.brand_intro}
            function esc(v){const d=document.createElement('div');d.textContent=v||'';return d.innerHTML}
        })();
        </script>
        <?php
    }

    public static function assets(): void {
        $page=self::page();
        if (!in_array($page,['tng-content-pillars','tng-content-idea-generator','tng-content-post-builder','tng-content-calendar'],true)) return;
        wp_register_style('tng-content-pillars',false,[],defined('TNG_OS_VERSION')?TNG_OS_VERSION:null);wp_enqueue_style('tng-content-pillars');
        wp_add_inline_style('tng-content-pillars','.tng-pillars{max-width:1380px}.pillar-hero{margin:20px 0;background:linear-gradient(135deg,#073c2b,#176b45);color:#fff;border-radius:24px;padding:30px;display:flex;justify-content:space-between;gap:20px;align-items:center}.pillar-hero h1{color:#fff;font-size:34px;margin:4px 0 8px}.pillar-hero p{max-width:760px}.eyebrow{font-size:11px;font-weight:800;letter-spacing:.14em;color:#f05b25;margin:0}.pillar-toolbar{display:flex;justify-content:space-between;align-items:center;gap:16px;background:#fff;border:1px solid #dfe6e1;border-radius:18px;padding:18px 20px;margin:16px 0}.pillar-toolbar h2{margin:3px 0;color:#163c2d}.pillar-toolbar span{color:#708078}.pillar-toolbar select{min-width:240px}.pillar-unassigned{display:flex;gap:12px;align-items:center;background:#fff6e9;border:1px solid #f1d49c;border-radius:14px;padding:13px 16px;margin:12px 0;color:#79531c}.pillar-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.pillar-card{background:#fff;border:1px solid #dfe6e1;border-radius:18px;padding:18px;border-top:4px solid #f05b25}.pillar-card.complete{border-top-color:#238252}.pillar-top{display:flex;justify-content:space-between;gap:14px}.pillar-top h3{font-size:20px;color:#163c2d;margin:5px 0}.pillar-top .state{font-size:10px;font-weight:800;text-transform:uppercase;color:#d34e1f}.pillar-card.complete .state{color:#238252}.pillar-top label{font-size:10px;font-weight:800;text-transform:uppercase;color:#6c7c73}.pillar-top input{width:65px;display:block;margin-top:4px}.pillar-card>p{color:#6c7b73;min-height:40px}.pillar-card .bar{height:8px;background:#edf2ee;border-radius:999px;overflow:hidden}.pillar-card .bar span{display:block;height:100%;background:#f05b25}.pillar-card.complete .bar span{background:#238252}.pillar-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin:12px 0}.pillar-metrics span{background:#f5f8f6;border-radius:10px;padding:8px;color:#718078;font-size:10px}.pillar-metrics strong{display:block;color:#173b2c;font-size:18px}.generate{color:#d94d19;font-weight:700;text-decoration:none}.covered{color:#238252;font-weight:700}.pillar-save{position:sticky;bottom:12px;background:#fff;border:1px solid #dce4df;box-shadow:0 8px 28px rgba(0,0,0,.08);border-radius:14px;padding:12px 16px;margin:16px 0;display:flex;justify-content:space-between;align-items:center}.pillar-empty{background:#fff;border:1px dashed #cad5cc;border-radius:16px;padding:28px}.tng-pillar-select{width:100%;box-sizing:border-box;border:1px solid #d8e0db;border-radius:9px;padding:10px;margin:5px 0 14px;background:#fff}.tng-pillar-badge{font-size:10px;color:#8a6022}.tng-pillar-calendar{background:#fff;border:1px solid #dfe5df;border-radius:18px;padding:18px 20px;margin:16px 0}.tng-pillar-cal-head{display:flex;justify-content:space-between;align-items:center}.tng-pillar-cal-head h2{margin:3px 0;color:#173a2c}.tng-pillar-cal-grid{display:grid;gap:9px;margin-top:12px}.tng-pillar-cal-grid article{border:1px solid #e1e7e3;border-radius:13px;padding:13px}.tng-pillar-cal-grid article>strong{display:block;color:#173a2c;margin-bottom:8px}.tng-pillar-cal-grid article div{display:flex;gap:8px;flex-wrap:wrap}.tng-pillar-cal-grid a{display:flex;gap:10px;background:#f7f9f7;border-radius:999px;padding:7px 10px;text-decoration:none;color:#375347}.tng-pillar-cal-grid a b{color:#d94d19}@media(max-width:900px){.pillar-grid{grid-template-columns:1fr}.pillar-hero,.pillar-toolbar,.pillar-save{display:block}.pillar-toolbar form,.pillar-save button{margin-top:12px}.pillar-unassigned{display:block}}');
        if($page==='tng-content-pillars') wp_add_inline_script('jquery-core','jQuery(function($){function total(){let n=0;jQuery(".pillar-top input[type=number]").each(function(){n+=parseInt(this.value||0,10)});jQuery("#tng-pillar-total").text(n)}jQuery(document).on("input",".pillar-top input[type=number]",total);});');
    }
}
TNG_Content_Pillars::boot();
