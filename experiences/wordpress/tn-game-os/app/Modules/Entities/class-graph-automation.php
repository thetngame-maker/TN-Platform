<?php
namespace TNG_OS\Modules\Entities;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Graph_Automation implements Module_Interface {
    private const RULES_OPTION = 'tng_graph_automation_rules';
    private const QUEUE_OPTION = 'tng_graph_automation_queue';
    private const HISTORY_OPTION = 'tng_graph_automation_history';
    private Container $container;

    public function id(): string { return 'graph_automation'; }

    public function register(Container $container): void {
        $this->container = $container;
        $container->set('graph_automation', $this);
        add_action('admin_menu', [$this, 'menu'], 31);
        add_action('admin_post_tng_graph_automation_save', [$this, 'save_rules']);
        add_action('admin_post_tng_graph_automation_run', [$this, 'manual_run']);
        add_action('admin_post_tng_graph_automation_action', [$this, 'queue_action']);
        add_action('tng_os_daily', [$this, 'scheduled_run']);
    }

    public function boot(Container $container): void {
        if (!wp_next_scheduled('tng_os_daily')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'tng_os_daily');
    }

    public function menu(): void {
        add_submenu_page('tn-game-os', 'Graph Automation', 'Graph Automation', 'manage_options', 'tng-graph-automation', [$this, 'page']);
    }

    private function defaults(): array {
        return [
            'enabled'=>0,
            'event_venue'=>1,
            'nearby'=>1,
            'shared_location'=>1,
            'missing_metadata'=>1,
            'duplicates'=>1,
            'minimum_confidence'=>70,
        ];
    }

    private function rules(): array {
        $saved = get_option(self::RULES_OPTION, []);
        return array_merge($this->defaults(), is_array($saved) ? $saved : []);
    }

    public function save_rules(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tng_graph_automation_save');
        $rules = $this->defaults();
        foreach (['enabled','event_venue','nearby','shared_location','missing_metadata','duplicates'] as $key) $rules[$key] = empty($_POST[$key]) ? 0 : 1;
        $rules['minimum_confidence'] = max(50, min(100, absint($_POST['minimum_confidence'] ?? 70)));
        update_option(self::RULES_OPTION, $rules, false);
        $this->redirect('saved');
    }

    public function manual_run(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tng_graph_automation_run');
        $this->run('manual');
        $this->redirect('ran');
    }

    public function scheduled_run(): void {
        $rules = $this->rules();
        if (!empty($rules['enabled'])) $this->run('scheduled');
    }

    private function run(string $trigger): void {
        $engine = $this->container->get('recommendation_engine');
        if (!$engine || !is_callable([$engine, 'entities'])) return;
        $entities = $engine->entities();
        $rules = $this->rules();
        $queue = $this->scan($entities, $rules);
        update_option(self::QUEUE_OPTION, $queue, false);
        $history = get_option(self::HISTORY_OPTION, []);
        $history = is_array($history) ? $history : [];
        array_unshift($history, [
            'time'=>current_time('mysql'),
            'trigger'=>$trigger,
            'entities'=>count($entities),
            'suggestions'=>count(array_filter($queue, fn($q)=>$q['kind']==='relationship')),
            'warnings'=>count(array_filter($queue, fn($q)=>$q['kind']==='warning')),
            'duplicates'=>count(array_filter($queue, fn($q)=>$q['kind']==='duplicate')),
        ]);
        update_option(self::HISTORY_OPTION, array_slice($history,0,30), false);
    }

    private function scan(array $entities, array $rules): array {
        $items=[]; $existing=$this->existing_keys($entities); $min=$rules['minimum_confidence']/100;
        foreach ($entities as $id=>$entity) {
            $payload=(array)$entity['payload'];
            if (!empty($rules['missing_metadata'])) {
                foreach ($this->metadata_warnings($entity) as $warning) $items[$warning['id']]=$warning;
            }
            if (!empty($rules['duplicates'])) {
                foreach ($entities as $other_id=>$other) {
                    if ($id >= $other_id) continue;
                    if ($this->normalized($entity['title']) !== '' && $this->normalized($entity['title']) === $this->normalized($other['title'])) {
                        $key='dup|'.$id.'|'.$other_id;
                        $items[$key]=['id'=>$key,'kind'=>'duplicate','title'=>'Possible duplicate entities','message'=>$entity['title'].' and '.$other['title'].' have matching normalized titles.','source'=>$id,'target'=>$other_id,'confidence'=>.95];
                    }
                }
            }
            foreach ($entities as $target_id=>$target) {
                if ($id===$target_id) continue;
                $proposal=$this->proposal($entity,$target,$rules);
                if (!$proposal || $proposal['confidence']<$min) continue;
                $key=$id.'|'.$proposal['type'].'|'.$target_id;
                if (isset($existing[$key])) continue;
                $items[$key]=array_merge($proposal,['id'=>$key,'kind'=>'relationship','source'=>$id,'source_title'=>$entity['title'],'target'=>$target_id,'target_title'=>$target['title']]);
            }
        }
        uasort($items, fn($a,$b)=>($b['confidence']??0)<=>($a['confidence']??0));
        return array_slice(array_values($items),0,200);
    }

    private function proposal(array $source,array $target,array $rules): ?array {
        $sp=(array)$source['payload']; $tp=(array)$target['payload'];
        $stype=sanitize_key((string)$source['type']); $ttype=sanitize_key((string)$target['type']);
        $source_text=$this->normalized(implode(' ',array_filter([(string)$source['title'],(string)($sp['venue']??''),(string)($sp['venue_name']??''),(string)($sp['location']??''),(string)($sp['destination']??'')])));
        $target_title=$this->normalized((string)$target['title']);
        if (!empty($rules['event_venue']) && in_array($stype,['event','concert','festival'],true) && in_array($ttype,['venue','theater','amphitheater'],true) && $target_title!=='' && str_contains($source_text,$target_title)) return ['type'=>'held_at','confidence'=>.96,'message'=>'Venue name appears in event title or metadata.'];
        $distance=$this->distance((array)$source['payload'],(array)$target['payload']);
        if (!empty($rules['nearby']) && $distance!==null) {
            if ($distance<=1) return ['type'=>'near','confidence'=>.95,'message'=>'Entities are within one mile.','distance'=>$distance];
            if ($distance<=5) return ['type'=>'near','confidence'=>.86,'message'=>'Entities are within five miles.','distance'=>$distance];
        }
        if (!empty($rules['shared_location'])) {
            $sl=$this->normalized((string)($sp['location']??$sp['destination']??$sp['city']??''));
            $tl=$this->normalized((string)($tp['location']??$tp['destination']??$tp['city']??''));
            if ($sl!=='' && $sl===$tl) return ['type'=>'related_to','confidence'=>.72,'message'=>'Entities share the same destination or city metadata.'];
        }
        return null;
    }

    private function metadata_warnings(array $entity): array {
        $p=(array)$entity['payload']; $warnings=[];
        $checks=[
            'coordinates'=>$this->coordinates($p)!==null,
            'image'=>!empty($p['image'])||!empty($p['image_url'])||!empty($p['featured_image']),
            'description'=>!empty($p['description'])||!empty($p['summary'])||!empty($p['excerpt']),
        ];
        foreach($checks as $field=>$ok) if(!$ok){$id='warn|'.$entity['id'].'|'.$field;$warnings[]=['id'=>$id,'kind'=>'warning','title'=>'Missing '.ucfirst($field),'message'=>$entity['title'].' is missing '.$field.'.','source'=>$entity['id'],'confidence'=>1];}
        return $warnings;
    }

    public function queue_action(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tng_graph_automation_action');
        $id=sanitize_text_field(wp_unslash($_POST['item_id']??'')); $action=sanitize_key(wp_unslash($_POST['queue_action']??''));
        $queue=get_option(self::QUEUE_OPTION,[]); $queue=is_array($queue)?$queue:[];
        foreach($queue as $index=>$item){if(($item['id']??'')!==$id)continue;if($action==='approve'&&($item['kind']??'')==='relationship')$this->approve_relationship($item);unset($queue[$index]);break;}
        update_option(self::QUEUE_OPTION,array_values($queue),false); $this->redirect($action==='approve'?'approved':'dismissed');
    }

    private function approve_relationship(array $item): void {
        $posts=get_posts(['post_type'=>'tng_entity','post_status'=>['publish','draft','private'],'posts_per_page'=>1,'fields'=>'ids','meta_key'=>'_tng_entity_id','meta_value'=>$item['source']]);
        if(!$posts)return; $post_id=(int)$posts[0]; $rels=get_post_meta($post_id,'_tng_entity_relationships',true); $rels=is_array($rels)?$rels:[];
        $rels[]=['relationship_id'=>'rel_'.strtoupper(wp_generate_password(20,false,false)),'source_entity_id'=>$item['source'],'target_entity_id'=>$item['target'],'type'=>$item['type'],'confidence'=>$item['confidence'],'source_provider'=>'tn-graph-automation','evidence'=>$item['message'],'created_at'=>current_time('mysql',true)];
        update_post_meta($post_id,'_tng_entity_relationships',array_values($rels));
    }

    public function page(): void {
        if (!current_user_can('manage_options')) return;
        $rules=$this->rules(); $queue=get_option(self::QUEUE_OPTION,[]); $queue=is_array($queue)?$queue:[]; $history=get_option(self::HISTORY_OPTION,[]); $history=is_array($history)?$history:[];
        ?>
        <div class="wrap tng-ga"><style>.tng-ga{max-width:1450px}.tng-ga-hero{background:linear-gradient(135deg,#14213d,#315c7d);color:#fff;border-radius:18px;padding:28px 30px;margin:18px 0}.tng-ga-hero h1{color:#fff;margin:0 0 6px}.tng-ga-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}.tng-ga-card{background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:18px}.tng-ga-layout{display:grid;grid-template-columns:minmax(0,1fr) 390px;gap:18px;margin-top:18px}.tng-ga-rule{display:flex;justify-content:space-between;gap:12px;padding:11px 0;border-bottom:1px solid #eee}.tng-ga-item{display:grid;grid-template-columns:1fr 150px;gap:16px;align-items:center;padding:15px 0;border-bottom:1px solid #eee}.tng-ga-badge{display:inline-flex;border-radius:999px;padding:4px 9px;background:#eef2ff;font-size:12px}.tng-ga-actions{display:flex;gap:7px}.tng-ga-stat strong{display:block;font-size:28px;color:#14213d}.tng-ga-table{width:100%;border-collapse:collapse}.tng-ga-table td,.tng-ga-table th{padding:9px;border-bottom:1px solid #eee;text-align:left}@media(max-width:1000px){.tng-ga-grid{grid-template-columns:repeat(2,1fr)}.tng-ga-layout{grid-template-columns:1fr}}@media(max-width:650px){.tng-ga-grid{grid-template-columns:1fr}.tng-ga-item{grid-template-columns:1fr}}</style>
        <div class="tng-ga-hero"><p style="text-transform:uppercase;letter-spacing:.12em;color:#f6bd3b;font-weight:700">TN Platform · Automation Engine</p><h1>Graph Automation</h1><p>Schedule graph scans, review proposed actions, and track destination intelligence over time.</p></div>
        <?php if(isset($_GET['notice'])):?><div class="notice notice-success inline"><p>Automation action completed.</p></div><?php endif;?>
        <div class="tng-ga-grid"><div class="tng-ga-card tng-ga-stat"><span>Automation</span><strong><?php echo $rules['enabled']?'On':'Off';?></strong></div><div class="tng-ga-card tng-ga-stat"><span>Pending actions</span><strong><?php echo count($queue);?></strong></div><div class="tng-ga-card tng-ga-stat"><span>Last scan</span><strong style="font-size:18px"><?php echo esc_html($history[0]['time']??'Never');?></strong></div><div class="tng-ga-card tng-ga-stat"><span>Runs logged</span><strong><?php echo count($history);?></strong></div></div>
        <div class="tng-ga-layout"><main><section class="tng-ga-card"><h2>Pending queue</h2><?php if(!$queue):?><p>No pending actions. Run a scan or wait for the next scheduled run.</p><?php endif;?><?php foreach($queue as $item):?><div class="tng-ga-item"><div><span class="tng-ga-badge"><?php echo esc_html($item['kind']);?></span><h3><?php echo esc_html($item['title']??(($item['source_title']??'').' → '.($item['type']??'').' → '.($item['target_title']??'')));?></h3><p><?php echo esc_html($item['message']??'');?></p><?php if(isset($item['confidence'])):?><small><?php echo esc_html((string)round($item['confidence']*100));?>% confidence</small><?php endif;?></div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><?php wp_nonce_field('tng_graph_automation_action');?><input type="hidden" name="action" value="tng_graph_automation_action"><input type="hidden" name="item_id" value="<?php echo esc_attr($item['id']);?>"><div class="tng-ga-actions"><?php if(($item['kind']??'')==='relationship'):?><button class="button button-primary" name="queue_action" value="approve">Approve</button><?php endif;?><button class="button" name="queue_action" value="dismiss">Dismiss</button></div></form></div><?php endforeach;?></section>
        <section class="tng-ga-card" style="margin-top:18px"><h2>Automation history</h2><table class="tng-ga-table"><thead><tr><th>Time</th><th>Trigger</th><th>Entities</th><th>Suggestions</th><th>Warnings</th><th>Duplicates</th></tr></thead><tbody><?php foreach($history as $run):?><tr><td><?php echo esc_html($run['time']);?></td><td><?php echo esc_html($run['trigger']);?></td><td><?php echo esc_html((string)$run['entities']);?></td><td><?php echo esc_html((string)$run['suggestions']);?></td><td><?php echo esc_html((string)$run['warnings']);?></td><td><?php echo esc_html((string)$run['duplicates']);?></td></tr><?php endforeach;?></tbody></table></section></main>
        <aside class="tng-ga-card"><h2>Automation rules</h2><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><?php wp_nonce_field('tng_graph_automation_save');?><input type="hidden" name="action" value="tng_graph_automation_save"><?php foreach(['enabled'=>'Enable nightly automation','event_venue'=>'Connect events to venues','nearby'=>'Suggest nearby entities','shared_location'=>'Connect shared locations','missing_metadata'=>'Flag missing metadata','duplicates'=>'Detect duplicate entities'] as $key=>$label):?><label class="tng-ga-rule"><span><?php echo esc_html($label);?></span><input type="checkbox" name="<?php echo esc_attr($key);?>" value="1" <?php checked($rules[$key],1);?>></label><?php endforeach;?><p><label><strong>Minimum confidence</strong><br><input type="number" min="50" max="100" name="minimum_confidence" value="<?php echo esc_attr((string)$rules['minimum_confidence']);?>">%</label></p><button class="button button-primary">Save rules</button></form><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" style="margin-top:10px"><?php wp_nonce_field('tng_graph_automation_run');?><input type="hidden" name="action" value="tng_graph_automation_run"><button class="button" style="width:100%">Run scan now</button></form></aside></div></div><?php
    }

    private function existing_keys(array $entities): array {$keys=[];foreach($entities as $e)foreach((array)$e['relationships'] as $r){if(!is_array($r))continue;$s=(string)($r['source_entity_id']??$e['id']);$t=(string)($r['target_entity_id']??'');$type=sanitize_key((string)($r['type']??'related_to'));if($s&&$t)$keys[$s.'|'.$type.'|'.$t]=1;}return$keys;}
    private function normalized(string $v): string {return trim(preg_replace('/[^a-z0-9]+/',' ',strtolower(remove_accents($v)))??'');}
    private function coordinates(array $p): ?array {$lat=$p['latitude']??$p['lat']??null;$lng=$p['longitude']??$p['lng']??$p['lon']??null;return is_numeric($lat)&&is_numeric($lng)?[(float)$lat,(float)$lng]:null;}
    private function distance(array $a,array $b): ?float {$x=$this->coordinates($a);$y=$this->coordinates($b);if(!$x||!$y)return null;[$la1,$lo1]=array_map('deg2rad',$x);[$la2,$lo2]=array_map('deg2rad',$y);$h=sin(($la2-$la1)/2)**2+cos($la1)*cos($la2)*sin(($lo2-$lo1)/2)**2;return round(3958.7613*2*atan2(sqrt($h),sqrt(1-$h)),1);}
    private function redirect(string $notice): void {wp_safe_redirect(add_query_arg(['page'=>'tng-graph-automation','notice'=>$notice],admin_url('admin.php')));exit;}
}
