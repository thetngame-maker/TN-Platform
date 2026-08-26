<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Live_Trip_Optimizer implements Module_Interface {
    public function id(): string { return 'live_trip_optimizer'; }

    public function register(Container $container): void {
        $container->set('live_trip_optimizer', $this);
        add_action('wp_ajax_tng_build_personalized_trip', [$this, 'ajax_build'], 1);
        add_action('wp_ajax_nopriv_tng_build_personalized_trip', [$this, 'ajax_build'], 1);
        add_action('wp_footer', [$this, 'footer'], 95);
    }

    public function boot(Container $container): void {}

    public function ajax_build(): void {
        check_ajax_referer('tng_personal_trip', 'nonce');
        $source_id = absint($_POST['source_id'] ?? 0);
        $hours = absint($_POST['hours'] ?? 5); if (!in_array($hours, [3,5,8], true)) $hours = 5;
        $interest = sanitize_key($_POST['interest'] ?? 'smart');
        $budget = sanitize_key($_POST['budget'] ?? 'any');
        $pace = sanitize_key($_POST['pace'] ?? 'balanced');
        $start = absint($_POST['start'] ?? 10); if (!in_array($start, [9,10,12,14], true)) $start = 10;
        $prefs = [
            'family' => !empty($_POST['family']),
            'accessible' => !empty($_POST['accessible']),
            'rain' => !empty($_POST['rain']),
            'food' => !empty($_POST['food']),
            'interest' => $interest,
            'budget' => $budget,
            'pace' => $pace,
        ];
        if (!$source_id) $source_id = $this->best_source($prefs);
        if (!$source_id) wp_send_json_error(['message'=>'No destination listings are ready for trip planning yet.'], 404);

        $stops = $this->build($source_id, $hours, $start, $prefs);
        $total = array_sum(array_map(static fn($s)=>(int)$s['minutes'] + (int)($s['travel_minutes'] ?? 0), $stops));
        $food_added = (bool)array_filter($stops, static fn($s)=>($s['label'] ?? '') === 'Food & drink');
        $explanation = $this->explanation($prefs);
        if ($prefs['food']) $explanation .= $food_added ? ' A dining stop is included.' : ' No qualified nearby dining stop fit the available time.';
        wp_send_json_success(['source_id'=>$source_id,'stops'=>$stops,'total_minutes'=>$total,'explanation'=>$explanation,'food_included'=>$food_added]);
    }

    private function build(int $source_id, int $hours, int $start, array $prefs): array {
        $capacity = $hours * 60;
        $target = $prefs['pace']==='relaxed' ? min(4,$hours) : ($prefs['pace']==='packed' ? min(7,$hours+2) : min(6,$hours+1));
        $rows = [];
        $seen = [$source_id=>true];
        $anchor_minutes = $this->visit_minutes($source_id);
        $rows[] = $this->stop($source_id, 'Main experience', 'Your starting point', $anchor_minutes, 0);
        $used = $anchor_minutes;

        // Reserve a dining stop before scenic/adventure candidates consume the time budget.
        if ($prefs['food']) {
            foreach (Smart_Recommendation_Engine::recommend($source_id, 'food_after', 20) as $rec) {
                $id = (int)$rec['id'];
                if (!$id || isset($seen[$id]) || !$this->qualified($id, $prefs, true)) continue;
                $visit = min(75, max(45, $this->visit_minutes($id)));
                $travel = $this->travel_minutes((float)$rec['distance']);
                if ($used + $travel + $visit > $capacity) continue;
                $rows[] = $this->stop($id, 'Food & drink', $rec['reason'], $visit, $travel);
                $seen[$id] = true;
                $used += $travel + $visit;
                break;
            }
        }

        $scenarios = [];
        if ($prefs['rain']) $scenarios[] = 'rainy_day';
        if ($prefs['family']) $scenarios[] = 'family';
        if ($prefs['interest']==='history') $scenarios[] = 'similar';
        elseif (in_array($prefs['interest'], ['adventure','photography','rainy_day','family','food_after'], true)) $scenarios[] = $prefs['interest'];
        else $scenarios[] = 'similar';
        $scenarios = array_values(array_unique(array_merge($scenarios, ['photography','adventure','similar','lodging'])));

        $candidates = [];
        foreach ($scenarios as $scenario) {
            foreach (Smart_Recommendation_Engine::recommend($source_id, $scenario, 18) as $rec) {
                $id = (int)$rec['id'];
                if (!$id || isset($seen[$id]) || isset($candidates[$id]) || !$this->qualified($id, $prefs, false)) continue;
                $candidates[$id] = $rec + ['scenario'=>$scenario];
            }
        }
        uasort($candidates, static fn($a,$b)=>(int)$b['score'] <=> (int)$a['score']);
        foreach ($candidates as $id=>$rec) {
            if (count($rows) >= $target) break;
            $visit = $this->visit_minutes((int)$id);
            $travel = $this->travel_minutes((float)$rec['distance']);
            if ($used + $travel + $visit > $capacity) continue;
            $rows[] = $this->stop((int)$id, $this->scenario_label((string)$rec['scenario']), (string)$rec['reason'], $visit, $travel);
            $seen[(int)$id] = true;
            $used += $travel + $visit;
        }

        $rows = $this->route_order($source_id, $rows);
        $clock = $start * 60;
        foreach ($rows as &$row) {
            $clock += (int)($row['travel_minutes'] ?? 0);
            $row['time'] = $this->clock($clock);
            if (!empty($row['travel_minutes'])) $row['reason'] .= ' · about '.(int)$row['travel_minutes'].' min travel';
            $clock += (int)$row['minutes'];
        }
        unset($row);
        return $rows;
    }

    private function route_order(int $source_id, array $rows): array {
        if (count($rows) < 3) return $rows;
        $anchor = array_shift($rows);
        $food = [];
        foreach ($rows as $k=>$row) if (($row['label'] ?? '') === 'Food & drink') { $food[]=$row; unset($rows[$k]); }
        $ordered = [$anchor];
        $current = $source_id;
        while ($rows) {
            $best_key = null; $best_distance = PHP_FLOAT_MAX;
            foreach ($rows as $k=>$row) {
                $d = $this->graph_distance($current, (int)$row['id']);
                if ($d < $best_distance) { $best_distance=$d; $best_key=$k; }
            }
            if ($best_key === null) break;
            $ordered[] = $rows[$best_key];
            $current = (int)$rows[$best_key]['id'];
            unset($rows[$best_key]);
        }
        // Place dining near the middle of the day instead of always last.
        foreach ($food as $meal) array_splice($ordered, max(1, (int)ceil(count($ordered)/2)), 0, [$meal]);
        return $ordered;
    }

    private function graph_distance(int $source, int $target): float {
        global $wpdb;
        $table = $wpdb->prefix.'tng_knowledge_graph';
        $value = $wpdb->get_var($wpdb->prepare("SELECT distance_miles FROM {$table} WHERE source_id=%d AND target_id=%d LIMIT 1", $source, $target));
        return $value === null ? 9999.0 : (float)$value;
    }

    private function travel_minutes(float $miles): int {
        if ($miles <= .2) return 3;
        if ($miles <= 1) return 5;
        return max(6, min(45, (int)ceil(($miles / 28) * 60 + 4)));
    }

    private function qualified(int $id, array $prefs, bool $is_food): bool {
        $profile = class_exists(Destination_AI_Profiles::class) ? Destination_AI_Profiles::profile($id) : [];
        // Do not reject restaurants because their family/rain scores have not been completed yet.
        if (!$is_food && $prefs['family'] && (int)($profile['family']??0)<3) return false;
        if (!$is_food && $prefs['accessible'] && (int)($profile['accessibility']??0)<3) return false;
        if (!$is_food && $prefs['rain'] && (int)($profile['rainy_day']??0)<3) return false;
        if ($prefs['budget']!=='any') {
            $cost = strtolower((string)($profile['cost']??''));
            if ($prefs['budget']==='free' && !$is_food && !preg_match('/free|\$0|no cost/',$cost)) return false;
            if ($prefs['budget']==='low' && preg_match('/luxury|premium|\$\$\$/',$cost)) return false;
        }
        if (!$is_food && $prefs['interest']==='history' && (int)($profile['history']??0)<3 && !str_contains(strtolower((string)($profile['traits']??'')),'history')) return false;
        return true;
    }

    private function best_source(array $prefs): int {
        $best=0; $score=-1;
        foreach ($this->node_ids() as $id) {
            if (!$this->qualified($id,$prefs,false)) continue;
            $p = class_exists(Destination_AI_Profiles::class) ? Destination_AI_Profiles::profile($id) : [];
            $s = (int)($p['confidence']??0)+(int)($p['family']??0)*3+(int)($p['adventure']??0)*2+(int)($p['photography']??0)*2;
            if ($s>$score) { $score=$s; $best=$id; }
        }
        return $best;
    }

    private function stop(int $id,string $label,string $reason,int $minutes,int $travel): array {
        return ['id'=>$id,'title'=>html_entity_decode(get_the_title($id)?:'#'.$id,ENT_QUOTES|ENT_HTML5,'UTF-8'),'url'=>get_permalink($id),'image'=>get_the_post_thumbnail_url($id,'medium')?:'','minutes'=>$minutes,'travel_minutes'=>$travel,'detail'=>$label,'label'=>$label,'reason'=>$reason,'time'=>''];
    }

    private function visit_minutes(int $id): int {
        $p = class_exists(Destination_AI_Profiles::class) ? Destination_AI_Profiles::profile($id) : [];
        $raw = strtolower((string)($p['visit_time']??''));
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(hour|hr)/',$raw,$m)) return max(30,(int)round((float)$m[1]*60));
        if (preg_match('/([0-9]+)\s*(minute|min)/',$raw,$m)) return max(20,(int)$m[1]);
        $type=get_post_type($id);
        return in_array($type,['st_hotel','st_rental'],true)?30:($type==='st_activity'?90:60);
    }

    private function scenario_label(string $s): string { return ['similar'=>'Recommended stop','family'=>'Family pick','rainy_day'=>'Rainy-day pick','food_after'=>'Food & drink','lodging'=>'Lodging','photography'=>'Scenic stop','adventure'=>'Adventure stop'][$s]??'Recommended stop'; }
    private function explanation(array $p): string { $bits=[]; if($p['family'])$bits[]='family-friendly'; if($p['accessible'])$bits[]='accessibility-aware'; if($p['rain'])$bits[]='rain-ready'; if($p['budget']!=='any')$bits[]='budget-conscious'; if($p['interest']!=='smart')$bits[]=$p['interest'].' focused'; return $bits?'Built as a '.implode(', ',$bits).' itinerary.':'Built from your time, pace, and nearby destination matches.'; }
    private function clock(int $minutes): string { $h=(int)floor(($minutes%1440)/60); $m=$minutes%60; $suffix=$h>=12?'PM':'AM'; $display=$h%12?:12; return sprintf('%d:%02d %s',$display,$m,$suffix); }
    private function node_ids(): array { return get_posts(['post_type'=>$this->post_types(),'post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','orderby'=>'title','order'=>'ASC','meta_query'=>[['key'=>'_tng_graph_excluded','compare'=>'NOT EXISTS']]]); }
    private function post_types(): array { return array_values(array_filter(['tng_destination','st_activity','st_hotel','st_tours','st_rental','top_sight'],'post_type_exists')); }

    public function footer(): void {
        if (is_admin()) return;
        ?><script>(function(){try{const key='tng_my_trip_v1',items=JSON.parse(localStorage.getItem(key)||'[]');if(!Array.isArray(items))return;let changed=false;const box=document.createElement('textarea');items.forEach(i=>{if(!i||!i.title)return;box.innerHTML=String(i.title);const clean=box.value;if(clean!==i.title){i.title=clean;changed=true;}});if(changed)localStorage.setItem(key,JSON.stringify(items));}catch(e){}})();</script><?php
    }
}
