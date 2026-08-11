<?php
/**
 * TN Game Campaign Storyline Intelligence
 * Adds narrative stages, sequence health, and Auto Planner / Calendar guidance.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Content_Storyline {
    private const ITEM = 'tng_social_item';
    private const CAMPAIGN = 'tng_campaign';

    public static function boot(): void {
        static $booted = false;
        if ($booted) return;
        $booted = true;
        add_action('admin_menu', [__CLASS__, 'menu'], 104);
        add_action('admin_footer', [__CLASS__, 'footer']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function menu(): void {
        add_submenu_page('tng-content-studio', 'Campaign Storyline', 'Storyline', 'edit_posts', 'tng-content-storyline', [__CLASS__, 'render']);
    }

    private static function stages(): array {
        return [
            'introduce' => ['label'=>'Introduce','desc'=>'Explain what The TN Game is and why it matters.','pillars'=>['brand_intro']],
            'explain' => ['label'=>'Explain','desc'=>'Reveal the product and the features people need to understand.','pillars'=>['feature_reveal']],
            'demonstrate' => ['label'=>'Demonstrate','desc'=>'Show the game in action so the idea becomes tangible.','pillars'=>['gameplay']],
            'inspire' => ['label'=>'Inspire','desc'=>'Make people want to explore with destinations and local places.','pillars'=>['destination_inspiration','local_business']],
            'anticipation' => ['label'=>'Build anticipation','desc'=>'Use behind-the-scenes and launch progress to create momentum.','pillars'=>['behind_scenes']],
        ];
    }

    private static function stage_for_pillar(string $pillar): string {
        foreach (self::stages() as $key=>$stage) if (in_array($pillar, $stage['pillars'], true)) return $key;
        return 'introduce';
    }

    private static function campaigns(): array {
        return class_exists('TNG_Content_Campaigns') ? TNG_Content_Campaigns::campaigns() : [];
    }

    private static function selected_campaign(): ?WP_Post {
        $id = absint($_GET['campaign_id'] ?? 0);
        if ($id && get_post_type($id) === self::CAMPAIGN) return get_post($id);
        foreach (self::campaigns() as $campaign) {
            if ((string)get_post_meta($campaign->ID, '_tng_campaign_status', true) === 'active') return $campaign;
        }
        $all = self::campaigns();
        return $all[0] ?? null;
    }

    private static function items(WP_Post $campaign): array {
        $items = get_posts([
            'post_type'=>self::ITEM,
            'post_status'=>['publish','draft','private'],
            'posts_per_page'=>400,
            'meta_query'=>[['key'=>'_tng_campaign','value'=>$campaign->post_title,'compare'=>'=']],
        ]);
        usort($items, static function($a,$b){
            $ad=(string)get_post_meta($a->ID,'_tng_planned_date',true); $bd=(string)get_post_meta($b->ID,'_tng_planned_date',true);
            if ($ad === $bd) return $a->ID <=> $b->ID;
            if (!$ad) return 1; if (!$bd) return -1; return strcmp($ad,$bd);
        });
        return $items;
    }

    private static function analysis(WP_Post $campaign): array {
        $stages=self::stages(); $order=array_keys($stages); $rank=array_flip($order);
        $counts=array_fill_keys($order,0); $issues=[]; $timeline=[]; $lastRank=-1; $lastFormat=''; $lastPlace='';
        foreach(self::items($campaign) as $item){
            $pillar=(string)get_post_meta($item->ID,'_tng_content_pillar',true); $stage=self::stage_for_pillar($pillar); $counts[$stage]++;
            $format=(string)get_post_meta($item->ID,'_tng_content_format',true); $place=(string)get_post_meta($item->ID,'_tng_location_name',true); $date=(string)get_post_meta($item->ID,'_tng_planned_date',true);
            if($date && $lastRank>$rank[$stage]) $issues[]='A '.$stages[$stage]['label'].' post is scheduled after a later-stage story beat.';
            if($date && $format && $lastFormat===$format) $issues[]='Two '.$format.' posts appear back-to-back in the storyline.';
            if($date && $place && $lastPlace && strtolower($place)===strtolower($lastPlace)) $issues[]=$place.' appears in consecutive planned posts.';
            if($date){$lastRank=$rank[$stage];$lastFormat=$format;$lastPlace=$place;}
            $timeline[]=['id'=>$item->ID,'title'=>$item->post_title,'stage'=>$stage,'pillar'=>$pillar,'format'=>$format,'place'=>$place,'date'=>$date,'status'=>(string)get_post_meta($item->ID,'_tng_plan_status',true)];
        }
        foreach($counts as $stage=>$count) if(!$count) $issues[]='No '.$stages[$stage]['label'].' story beat has been created yet.';
        $issues=array_values(array_unique($issues));
        $score=max(0,100-(count($issues)*12));
        return compact('stages','counts','issues','timeline','score');
    }

    public static function render(): void {
        if(!current_user_can('edit_posts')) return;
        $campaign=self::selected_campaign();
        echo '<div class="wrap tng-story">';
        echo '<section class="story-hero"><div><p class="eyebrow">CONTENT STUDIO</p><h1>Campaign Storyline</h1><p>Build campaigns as a story, not just a quota. Sequence the introduction, explanation, demonstration, inspiration, and anticipation beats before publishing.</p></div><a class="button" href="'.esc_url(admin_url('admin.php?page=tng-content-auto-planner')).'">Auto Planner</a></section>';
        if(!$campaign){echo '<section class="story-empty">Create a campaign first.</section></div>';return;}
        $a=self::analysis($campaign);
        echo '<section class="story-toolbar"><div><p class="eyebrow">CAMPAIGN</p><h2>'.esc_html($campaign->post_title).'</h2><span>Storyline health: '.intval($a['score']).'/100</span></div><form method="get"><input type="hidden" name="page" value="tng-content-storyline"><select name="campaign_id" onchange="this.form.submit()">';
        foreach(self::campaigns() as $c) echo '<option value="'.intval($c->ID).'" '.selected($campaign->ID,$c->ID,false).'>'.esc_html($c->post_title).'</option>';
        echo '</select></form></section>';
        echo '<section class="story-arc">'; foreach($a['stages'] as $key=>$stage) echo '<article><span>'.esc_html($stage['label']).'</span><strong>'.intval($a['counts'][$key]).'</strong><small>'.esc_html($stage['desc']).'</small></article>'; echo '</section>';
        echo '<div class="story-grid"><section class="story-panel"><p class="eyebrow">SEQUENCE HEALTH</p><h2>'.($a['issues']?'What needs attention':'Storyline looks healthy').'</h2>';
        if($a['issues']){echo '<ul>';foreach($a['issues'] as $issue)echo '<li>'.esc_html($issue).'</li>';echo '</ul>';}else echo '<p>Your planned campaign currently progresses cleanly through the story arc.</p>';
        echo '</section><section class="story-panel"><p class="eyebrow">PLANNED STORY</p><h2>Campaign sequence</h2>';
        if(!$a['timeline']) echo '<p>No campaign content exists yet.</p>'; else {echo '<div class="timeline">';foreach($a['timeline'] as $row){$label=$a['stages'][$row['stage']]['label'];echo '<article><span>'.esc_html($row['date']?:'Unscheduled').'</span><div><b>'.esc_html($row['title']).'</b><small>'.esc_html($label.($row['format']?' · '.ucwords(str_replace('_',' ',$row['format'])):'').($row['place']?' · '.$row['place']:'' )).'</small></div></article>';}echo '</div>';}
        echo '</section></div></div>';
    }

    public static function footer(): void {
        if(!current_user_can('edit_posts')) return;
        $page=sanitize_key(wp_unslash($_GET['page'] ?? ''));
        if(!in_array($page,['tng-content-auto-planner','tng-content-calendar'],true)) return;
        $campaign=self::selected_campaign(); if(!$campaign) return;
        $a=self::analysis($campaign); $stages=self::stages();
        $payload=wp_json_encode(['campaign'=>$campaign->post_title,'score'=>$a['score'],'issues'=>$a['issues'],'counts'=>$a['counts'],'stages'=>$stages]);
        ?>
        <script id="tng-storyline-integration">
        (()=>{
            const page=<?php echo wp_json_encode($page); ?>, data=<?php echo $payload ?: '{}'; ?>;
            const stageFromLabel=(txt)=>{txt=(txt||'').toLowerCase();if(txt.includes('brand introduction'))return'introduce';if(txt.includes('feature reveal'))return'explain';if(txt.includes('gameplay'))return'demonstrate';if(txt.includes('destination inspiration')||txt.includes('local business'))return'inspire';if(txt.includes('behind the scenes'))return'anticipation';return'introduce'};
            const stageOrder=['introduce','explain','demonstrate','inspire','anticipation'];
            const stageLabel=(k)=>data.stages?.[k]?.label||k;
            const panel=()=>{const el=document.createElement('section');el.className='tng-storyline-inline';el.innerHTML='<div><p class="eyebrow">STORYLINE INTELLIGENCE</p><h2>'+data.campaign+'</h2><p>Storyline health <strong>'+data.score+'/100</strong></p></div><div class="tng-story-stages">'+stageOrder.map(k=>'<span><b>'+stageLabel(k)+'</b><em>'+(data.counts?.[k]||0)+'</em></span>').join('')+'</div>'+(data.issues?.length?'<div class="tng-story-warnings"><strong>'+data.issues.length+' sequence note'+(data.issues.length===1?'':'s')+'</strong><small>'+data.issues.slice(0,3).join(' · ')+'</small></div>':'<div class="tng-story-ok">✓ Storyline sequence looks healthy</div>');return el};
            if(page==='tng-content-auto-planner'){
                const grid=document.querySelector('.auto-grid'); if(!grid)return; const cards=[...grid.querySelectorAll('.auto-card')];
                cards.forEach(card=>{const label=card.querySelector('.top span')?.textContent||'';const stage=stageFromLabel(label);card.dataset.storyStage=stage;const badge=document.createElement('div');badge.className='tng-story-badge';badge.textContent=stageLabel(stage);card.prepend(badge)});
                cards.sort((a,b)=>stageOrder.indexOf(a.dataset.storyStage)-stageOrder.indexOf(b.dataset.storyStage)).forEach(c=>grid.append(c));
                grid.parentNode.insertBefore(panel(),grid);
                const scan=()=>{const chosen=[...grid.querySelectorAll('.auto-card')].filter(c=>c.querySelector('input[type=checkbox]')?.checked);let notes=[];for(let i=1;i<chosen.length;i++){const a=chosen[i-1],b=chosen[i];const af=a.querySelector('select[name*="[format]"]')?.value,bf=b.querySelector('select[name*="[format]"]')?.value;const ap=a.querySelector('input[name*="[place]"]')?.value.trim().toLowerCase(),bp=b.querySelector('input[name*="[place]"]')?.value.trim().toLowerCase();if(af&&af===bf)notes.push('Back-to-back '+af+' suggestions');if(ap&&ap===bp)notes.push('Repeated place: '+(a.querySelector('input[name*="[place]"]')?.value||''));}let box=document.querySelector('.tng-live-sequence');if(!box){box=document.createElement('div');box.className='tng-live-sequence';grid.parentNode.insertBefore(box,grid.nextSibling)}box.innerHTML=notes.length?'<strong>Live sequence check</strong><span>'+[...new Set(notes)].join(' · ')+'</span>':'<strong>Live sequence check</strong><span>✓ Proposed order has good variety.</span>'};
                grid.addEventListener('change',scan);grid.addEventListener('input',scan);scan();
            } else {
                const wrap=document.querySelector('.wrap'); if(!wrap)return; const p=panel(); const target=[...wrap.querySelectorAll('section,div')].find(el=>/Campaign balance/i.test(el.textContent||'')); if(target&&target.parentNode)target.parentNode.insertBefore(p,target);else wrap.prepend(p);
            }
        })();
        </script>
        <?php
    }

    public static function assets(): void {
        $page=sanitize_key(wp_unslash($_GET['page'] ?? ''));
        if(!in_array($page,['tng-content-storyline','tng-content-auto-planner','tng-content-calendar'],true)) return;
        wp_register_style('tng-content-storyline',false,[],defined('TNG_OS_VERSION')?TNG_OS_VERSION:null);wp_enqueue_style('tng-content-storyline');
        wp_add_inline_style('tng-content-storyline','.tng-story{max-width:1350px}.story-hero{margin:20px 0;background:linear-gradient(135deg,#073c2b,#176b45);color:#fff;border-radius:24px;padding:30px;display:flex;justify-content:space-between;gap:20px}.story-hero h1{color:#fff;font-size:36px;margin:5px 0}.eyebrow{font-size:11px;font-weight:800;letter-spacing:.13em;color:#f05b25;margin:0}.story-toolbar,.story-panel,.story-arc,.tng-storyline-inline{background:#fff;border:1px solid #dfe5df;border-radius:18px;padding:18px;margin:16px 0}.story-toolbar{display:flex;justify-content:space-between;align-items:center}.story-toolbar h2{margin:3px 0}.story-arc{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}.story-arc article,.tng-story-stages span{background:#f3f7f4;border-radius:12px;padding:13px}.story-arc span,.story-arc strong,.story-arc small{display:block}.story-arc strong{font-size:24px;color:#17402f}.story-arc small{color:#6b7871}.story-grid{display:grid;grid-template-columns:1fr 1.3fr;gap:16px}.story-panel ul{padding-left:18px}.timeline article{display:flex;gap:14px;padding:10px 0;border-top:1px solid #edf0ee}.timeline article>span{min-width:92px;color:#6f7d75}.timeline small{display:block;color:#75827b;margin-top:3px}.tng-storyline-inline{display:grid;grid-template-columns:minmax(180px,.8fr) 2fr 1.2fr;gap:16px;align-items:center}.tng-storyline-inline h2{margin:3px 0;color:#153e2e}.tng-story-stages{display:grid;grid-template-columns:repeat(5,1fr);gap:7px}.tng-story-stages span{display:flex;justify-content:space-between;gap:6px;font-size:11px}.tng-story-stages em{font-style:normal;font-weight:800;color:#f05b25}.tng-story-warnings{background:#fff5df;border:1px solid #efd49a;border-radius:12px;padding:12px}.tng-story-warnings small,.tng-story-warnings strong{display:block}.tng-story-ok{background:#eaf6ee;color:#245c3d;border-radius:12px;padding:12px;font-weight:700}.tng-story-badge{display:inline-block;background:#edf6ef;color:#20563b;border-radius:999px;padding:5px 9px;font-size:10px;font-weight:800;text-transform:uppercase;margin-bottom:8px}.tng-live-sequence{background:#fff;border:1px solid #dfe5df;border-radius:14px;padding:12px 16px;margin:12px 0;display:flex;gap:12px}.tng-live-sequence span{color:#66756d}@media(max-width:1000px){.story-arc,.tng-story-stages{grid-template-columns:1fr 1fr}.story-grid,.tng-storyline-inline{grid-template-columns:1fr}}');
    }
}
TNG_Content_Storyline::boot();
