<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Personalized_Trip_Builder implements Module_Interface {
    private const PAGE = 'tng-personalized-trip-builder';

    public function id(): string { return 'personalized_trip_builder'; }

    public function register(Container $container): void {
        $container->set('personalized_trip_builder', $this);
        add_action('admin_menu', [$this, 'menu'], 33);
        add_shortcode('tng_trip_builder', [$this, 'shortcode']);
        add_action('wp_ajax_tng_build_personalized_trip', [$this, 'ajax_build']);
        add_action('wp_ajax_nopriv_tng_build_personalized_trip', [$this, 'ajax_build']);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page('tn-game-os', 'Personalized Trip Builder', 'Trip Builder', 'manage_options', self::PAGE, [$this, 'page']);
    }

    public function page(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized.');
        echo '<div class="wrap"><h1>Personalized Trip Builder</h1><p>Use <code>[tng_trip_builder]</code> on a public page. Visitors choose time, interests, family needs, weather, accessibility, and budget before Destination AI assembles a trip.</p>';
        echo '<h2>Recommended setup</h2><ol><li>Create a page named <strong>Plan My Trip</strong>.</li><li>Add <code>[tng_trip_builder]</code>.</li><li>Add the page to your primary and mobile navigation.</li></ol>';
        echo '<p>To start from a specific destination or attraction, use <code>[tng_trip_builder id="14982"]</code>.</p></div>';
    }

    public function shortcode(array $atts = []): string {
        $atts = shortcode_atts(['id'=>0,'title'=>'Plan your adventure'], $atts, 'tng_trip_builder');
        $source_id = absint($atts['id']);
        if (!$source_id && is_singular($this->post_types())) $source_id = get_queried_object_id();
        $uid = 'tng-personal-trip-'.wp_rand(1000,99999);
        $nonce = wp_create_nonce('tng_personal_trip');
        $nodes = $this->node_ids();
        ob_start();
        ?>
        <section class="tng-ptb" id="<?php echo esc_attr($uid); ?>">
            <style>
            .tng-ptb{max-width:1180px;margin:38px auto;padding:0 18px;box-sizing:border-box}.tng-ptb-shell{border:1px solid #ded7f2;border-radius:26px;overflow:hidden;background:#fff;box-shadow:0 18px 45px rgba(23,33,63,.1)}.tng-ptb-head{padding:30px;background:linear-gradient(135deg,#17213f,#70429a);color:#fff}.tng-ptb-kicker{color:#ffd34e;font-size:12px;font-weight:800;letter-spacing:.17em;text-transform:uppercase}.tng-ptb-head h2{font-size:clamp(34px,5vw,52px);line-height:1.04;margin:8px 0;color:#fff}.tng-ptb-head p{margin:0;color:rgba(255,255,255,.82);max-width:760px}.tng-ptb-form{padding:26px 30px;background:linear-gradient(145deg,#faf8ff,#fff)}.tng-ptb-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.tng-ptb-field{display:flex;flex-direction:column;gap:7px}.tng-ptb-field label{font-weight:800;color:#17213f;font-size:13px}.tng-ptb-field select{width:100%;min-height:46px;border:1px solid #d9d3e8;border-radius:11px;padding:0 12px;background:#fff}.tng-ptb-checks{display:flex;gap:10px;flex-wrap:wrap;margin:20px 0}.tng-ptb-check{border:1px solid #d9d3e8;border-radius:999px;padding:10px 13px;background:#fff;color:#17213f;font-weight:700;cursor:pointer}.tng-ptb-check input{margin-right:7px}.tng-ptb-submit{border:0;border-radius:12px;padding:13px 20px;background:#7c4ce0;color:#fff;font-weight:800;cursor:pointer;font-size:15px}.tng-ptb-status{display:inline-block;margin-left:12px;color:#667085;font-weight:700}.tng-ptb-results{padding:0 30px 30px}.tng-ptb-summary{display:flex;justify-content:space-between;align-items:end;gap:18px;flex-wrap:wrap;padding:24px 0 15px}.tng-ptb-summary h3{font-size:30px;margin:0;color:#17213f}.tng-ptb-summary p{margin:5px 0 0;color:#667085}.tng-ptb-stops{position:relative}.tng-ptb-stop{display:grid;grid-template-columns:62px 44px 1fr auto;gap:14px;align-items:center;padding:17px 0;border-top:1px solid #e7e4ed}.tng-ptb-time{font-weight:800;color:#17213f}.tng-ptb-number{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#7c4ce0;color:#fff;font-weight:800;box-shadow:0 0 0 6px #f0eaff}.tng-ptb-stop h4{font-size:20px;margin:0 0 4px;color:#17213f}.tng-ptb-meta{font-size:13px;color:#667085}.tng-ptb-reason{display:inline-block;background:#eee8ff;color:#6336ae;border-radius:999px;padding:5px 8px;margin-right:5px;font-size:11px;font-weight:800}.tng-ptb-open{background:#17213f;color:#fff!important;text-decoration:none!important;border-radius:10px;padding:9px 12px;font-size:12px;font-weight:800}.tng-ptb-actions{display:flex;justify-content:flex-end;gap:10px;padding-top:18px}.tng-ptb-actions button{border:0;border-radius:11px;padding:12px 16px;font-weight:800;cursor:pointer}.tng-ptb-save{background:#7c4ce0;color:#fff}.tng-ptb-share{background:#17213f;color:#fff}.tng-ptb-empty{padding:25px;border:1px dashed #d7d0e8;border-radius:14px;text-align:center;color:#667085}@media(max-width:850px){.tng-ptb-grid{grid-template-columns:1fr 1fr}.tng-ptb-stop{grid-template-columns:52px 38px 1fr}.tng-ptb-open{grid-column:3;justify-self:start}}@media(max-width:580px){.tng-ptb{padding:0 12px}.tng-ptb-head,.tng-ptb-form,.tng-ptb-results{padding-left:18px;padding-right:18px}.tng-ptb-grid{grid-template-columns:1fr}.tng-ptb-status{display:block;margin:10px 0 0}}
            </style>
            <div class="tng-ptb-shell">
                <header class="tng-ptb-head"><div class="tng-ptb-kicker">Destination AI</div><h2><?php echo esc_html($atts['title']); ?></h2><p>Tell us what kind of day you want. We will combine destination profiles, geographic relationships, and nearby places into a personalized itinerary.</p></header>
                <form class="tng-ptb-form">
                    <div class="tng-ptb-grid">
                        <div class="tng-ptb-field"><label>Start around</label><select name="source_id"><option value="0">Best available starting point</option><?php foreach($nodes as $id): ?><option value="<?php echo (int)$id; ?>" <?php selected($source_id,$id); ?>><?php echo esc_html(get_the_title($id)); ?></option><?php endforeach; ?></select></div>
                        <div class="tng-ptb-field"><label>Available time</label><select name="hours"><option value="3">3 hours</option><option value="5" selected>5 hours</option><option value="8">Full day</option></select></div>
                        <div class="tng-ptb-field"><label>Main interest</label><select name="interest"><option value="smart">Surprise me</option><option value="adventure">Adventure</option><option value="family">Family</option><option value="photography">Scenic & photography</option><option value="rainy_day">Rainy-day places</option><option value="food_after">Food & drink</option><option value="history">History</option></select></div>
                        <div class="tng-ptb-field"><label>Budget</label><select name="budget"><option value="any">Any budget</option><option value="free">Mostly free</option><option value="low">Under $50</option><option value="medium">Under $150</option></select></div>
                        <div class="tng-ptb-field"><label>Pace</label><select name="pace"><option value="relaxed">Relaxed</option><option value="balanced" selected>Balanced</option><option value="packed">See as much as possible</option></select></div>
                        <div class="tng-ptb-field"><label>Starting time</label><select name="start"><option value="9">9:00 AM</option><option value="10" selected>10:00 AM</option><option value="12">12:00 PM</option><option value="14">2:00 PM</option></select></div>
                    </div>
                    <div class="tng-ptb-checks"><label class="tng-ptb-check"><input type="checkbox" name="family" value="1"> Traveling with children</label><label class="tng-ptb-check"><input type="checkbox" name="accessible" value="1"> Accessibility is important</label><label class="tng-ptb-check"><input type="checkbox" name="rain" value="1"> Plan for rain</label><label class="tng-ptb-check"><input type="checkbox" name="food" value="1" checked> Include food</label></div>
                    <button class="tng-ptb-submit" type="submit">Build my trip</button><span class="tng-ptb-status" aria-live="polite"></span>
                </form>
                <div class="tng-ptb-results" hidden><div class="tng-ptb-summary"><div><h3>Your personalized trip</h3><p data-summary></p></div></div><div class="tng-ptb-stops" data-stops></div><div class="tng-ptb-actions"><button type="button" class="tng-ptb-save">Save to My Trip</button><button type="button" class="tng-ptb-share">Share plan</button></div></div>
            </div>
            <script>
            (function(){const root=document.getElementById(<?php echo wp_json_encode($uid); ?>);if(!root)return;const form=root.querySelector('form'),status=root.querySelector('.tng-ptb-status'),results=root.querySelector('.tng-ptb-results'),list=root.querySelector('[data-stops]'),summary=root.querySelector('[data-summary]');let plan=[];const esc=v=>{const d=document.createElement('div');d.textContent=String(v||'');return d.innerHTML};const duration=m=>m>=60?(Math.round(m/6)/10)+' hr':m+' min';form.addEventListener('submit',async e=>{e.preventDefault();status.textContent='Building your trip…';results.hidden=true;const data=new FormData(form);data.append('action','tng_build_personalized_trip');data.append('nonce',<?php echo wp_json_encode($nonce); ?>);try{const response=await fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,{method:'POST',credentials:'same-origin',body:data});const json=await response.json();if(!json.success)throw new Error(json.data&&json.data.message?json.data.message:'Could not build a trip.');plan=json.data.stops||[];render(json.data);status.textContent='Trip ready.';}catch(err){status.textContent=err.message||'Could not build a trip.';}});function render(data){list.innerHTML='';if(!plan.length){list.innerHTML='<div class="tng-ptb-empty">No strong matches were found yet. Improve AI profiles or choose another starting point.</div>';results.hidden=false;return;}plan.forEach((s,i)=>{const row=document.createElement('article');row.className='tng-ptb-stop';row.innerHTML='<div class="tng-ptb-time">'+esc(s.time)+'</div><div class="tng-ptb-number">'+(i+1)+'</div><div><h4>'+esc(s.title)+'</h4><div class="tng-ptb-meta"><span class="tng-ptb-reason">'+esc(s.label)+'</span>'+esc(s.reason)+' · '+duration(Number(s.minutes||60))+'</div></div><a class="tng-ptb-open" href="'+esc(s.url)+'">View stop</a>';list.appendChild(row)});summary.textContent=plan.length+' stops · '+duration(Number(data.total_minutes||0))+' · '+String(data.explanation||'Built for your preferences');results.hidden=false;}root.querySelector('.tng-ptb-save').onclick=()=>{if(!plan.length)return;if(window.TNGTrip&&window.TNGTrip.add){plan.forEach(s=>window.TNGTrip.add(s));window.TNGTrip.open();status.textContent='Added to My Trip.';}else{localStorage.setItem('tng_saved_day_plan',JSON.stringify({stops:plan,savedAt:Date.now()}));status.textContent='Saved on this device.';}};root.querySelector('.tng-ptb-share').onclick=async()=>{if(!plan.length)return;const text='My TN Game trip:\n'+plan.map((s,i)=>(i+1)+'. '+s.title+' — '+s.url).join('\n');try{if(navigator.share)await navigator.share({title:'My TN Game Trip',text});else{await navigator.clipboard.writeText(text);status.textContent='Trip copied.';}}catch(e){}};})();
            </script>
        </section>
        <?php
        return ob_get_clean();
    }

    public function ajax_build(): void {
        check_ajax_referer('tng_personal_trip', 'nonce');
        $source_id = absint($_POST['source_id'] ?? 0);
        $hours = absint($_POST['hours'] ?? 5); if (!in_array($hours,[3,5,8],true)) $hours=5;
        $interest = sanitize_key($_POST['interest'] ?? 'smart');
        $budget = sanitize_key($_POST['budget'] ?? 'any');
        $pace = sanitize_key($_POST['pace'] ?? 'balanced');
        $start = absint($_POST['start'] ?? 10); if (!in_array($start,[9,10,12,14],true)) $start=10;
        $prefs = ['family'=>!empty($_POST['family']),'accessible'=>!empty($_POST['accessible']),'rain'=>!empty($_POST['rain']),'food'=>!empty($_POST['food']),'interest'=>$interest,'budget'=>$budget,'pace'=>$pace];
        if (!$source_id) $source_id = $this->best_source($prefs);
        if (!$source_id) wp_send_json_error(['message'=>'No destination listings are ready for trip planning yet.'], 404);
        $stops = $this->build($source_id,$hours,$start,$prefs);
        $total = array_sum(array_map(static fn($s)=>(int)$s['minutes'],$stops));
        wp_send_json_success(['source_id'=>$source_id,'stops'=>$stops,'total_minutes'=>$total,'explanation'=>$this->explanation($prefs)]);
    }

    private function build(int $source_id,int $hours,int $start,array $prefs): array {
        $capacity = $hours * 60;
        $pace = $prefs['pace'];
        $target = $pace==='relaxed' ? min(4,$hours) : ($pace==='packed' ? min(7,$hours+2) : min(6,$hours+1));
        $rows = [];
        $anchor = $this->stop($source_id,'Main experience','Your starting point',$this->visit_minutes($source_id));
        if ($this->qualified($source_id,$prefs)) $rows[]=$anchor;
        $scenarios=[];
        if ($prefs['rain']) $scenarios[]='rainy_day';
        if ($prefs['family']) $scenarios[]='family';
        if ($prefs['interest']==='history') $scenarios[]='similar';
        elseif (in_array($prefs['interest'],['adventure','photography','rainy_day','family','food_after'],true)) $scenarios[]=$prefs['interest'];
        else $scenarios[]='similar';
        if ($prefs['food']) $scenarios[]='food_after';
        $scenarios=array_merge($scenarios,['photography','adventure','lodging','similar']);
        $seen=[$source_id=>true];
        foreach(array_unique($scenarios) as $scenario){
            foreach(Smart_Recommendation_Engine::recommend($source_id,$scenario,12) as $rec){
                $id=(int)$rec['id']; if(isset($seen[$id]) || !$this->qualified($id,$prefs)) continue;
                $minutes=$this->visit_minutes($id);
                if(array_sum(array_column($rows,'minutes'))+$minutes>$capacity) continue;
                $rows[]=$this->stop($id,$this->scenario_label($scenario),$rec['reason'],$minutes);
                $seen[$id]=true;
                if(count($rows)>=$target) break 2;
            }
        }
        if (!$rows) $rows[]=$anchor;
        $clock=$start*60;
        foreach($rows as &$row){$row['time']=$this->clock($clock);$clock+=(int)$row['minutes'];} unset($row);
        return $rows;
    }

    private function qualified(int $id,array $prefs): bool {
        $profile = class_exists(Destination_AI_Profiles::class) ? Destination_AI_Profiles::profile($id) : [];
        if ($prefs['family'] && (int)($profile['family']??0)<3) return false;
        if ($prefs['accessible'] && (int)($profile['accessibility']??0)<3) return false;
        if ($prefs['rain'] && (int)($profile['rainy_day']??0)<3) return false;
        if ($prefs['budget']!=='any') {
            $cost=strtolower((string)($profile['cost']??''));
            if ($prefs['budget']==='free' && !preg_match('/free|\$0|no cost/',$cost)) return false;
            if ($prefs['budget']==='low' && preg_match('/luxury|premium|\$\$\$/',$cost)) return false;
        }
        if ($prefs['interest']==='history' && (int)($profile['history']??0)<3 && !str_contains(strtolower((string)($profile['traits']??'')),'history')) return false;
        return true;
    }

    private function best_source(array $prefs): int {
        $best=0;$score=-1;
        foreach($this->node_ids() as $id){
            if(!$this->qualified($id,$prefs)) continue;
            $p=class_exists(Destination_AI_Profiles::class)?Destination_AI_Profiles::profile($id):[];
            $s=(int)($p['confidence']??0)+(int)($p['family']??0)*3+(int)($p['adventure']??0)*2+(int)($p['photography']??0)*2;
            if($s>$score){$score=$s;$best=$id;}
        }
        return $best;
    }

    private function stop(int $id,string $label,string $reason,int $minutes): array {
        return ['id'=>$id,'title'=>get_the_title($id)?:'#'.$id,'url'=>get_permalink($id),'image'=>get_the_post_thumbnail_url($id,'medium')?:'','minutes'=>$minutes,'detail'=>$label,'label'=>$label,'reason'=>$reason,'time'=>''];
    }

    private function visit_minutes(int $id): int {
        $p=class_exists(Destination_AI_Profiles::class)?Destination_AI_Profiles::profile($id):[];
        $raw=strtolower((string)($p['visit_time']??''));
        if(preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(hour|hr)/',$raw,$m)) return max(30,(int)round((float)$m[1]*60));
        if(preg_match('/([0-9]+)\s*(minute|min)/',$raw,$m)) return max(20,(int)$m[1]);
        $type=get_post_type($id); return in_array($type,['st_hotel','st_rental'],true)?30:($type==='st_activity'?90:60);
    }

    private function scenario_label(string $s): string { return ['similar'=>'Recommended stop','family'=>'Family pick','rainy_day'=>'Rainy-day pick','food_after'=>'Food & drink','lodging'=>'Lodging','photography'=>'Scenic stop','adventure'=>'Adventure stop'][$s]??'Recommended stop'; }
    private function explanation(array $p): string { $bits=[]; if($p['family'])$bits[]='family-friendly'; if($p['accessible'])$bits[]='accessibility-aware'; if($p['rain'])$bits[]='rain-ready'; if($p['budget']!=='any')$bits[]='budget-conscious'; if($p['interest']!=='smart')$bits[]=$p['interest'].' focused'; return $bits?'Built as a '.implode(', ',$bits).' itinerary.':'Built from your time, pace, and nearby destination matches.'; }
    private function clock(int $minutes): string { $h=(int)floor(($minutes%1440)/60);$m=$minutes%60;$suffix=$h>=12?'PM':'AM';$display=$h%12?:12;return sprintf('%d:%02d %s',$display,$m,$suffix); }
    private function node_ids(): array { return get_posts(['post_type'=>$this->post_types(),'post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','orderby'=>'title','order'=>'ASC','meta_query'=>[['key'=>'_tng_graph_excluded','compare'=>'NOT EXISTS']]]); }
    private function post_types(): array { return array_values(array_filter(['tng_destination','st_activity','st_hotel','st_tours','st_rental','top_sight'],'post_type_exists')); }
}
