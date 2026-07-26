<?php
namespace TNG_OS\Modules\Entities;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Relationship_Suggestions implements Module_Interface {
    private const ENTITY_TYPE = 'tng_entity';
    private Container $container;

    public function id(): string { return 'relationship_suggestions'; }

    public function register(Container $container): void {
        $this->container = $container;
        $container->set('relationship_suggestions', $this);
        add_action('admin_menu', [$this, 'menu'], 30);
        add_action('admin_post_tng_approve_relationship_suggestion', [$this, 'approve']);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page('tn-game-os', 'Relationship Suggestions', 'Relationship Suggestions', 'manage_options', 'tng-relationship-suggestions', [$this, 'page']);
    }

    public function page(): void {
        if (!current_user_can('manage_options')) return;
        $engine = $this->container->get('recommendation_engine');
        if (!$engine || !is_callable([$engine, 'entities'])) {
            echo '<div class="wrap"><h1>Relationship Suggestions</h1><div class="notice notice-error"><p>Entity data is unavailable.</p></div></div>';
            return;
        }
        $entities = $engine->entities();
        $minimum = max(50, min(100, absint($_GET['minimum'] ?? 70)));
        $suggestions = array_values(array_filter($this->suggest($entities), static fn(array $s): bool => $s['confidence'] * 100 >= $minimum));
        ?>
        <div class="wrap tng-rsg">
            <style>
                .tng-rsg{max-width:1450px}.tng-rsg-hero{background:linear-gradient(135deg,#152a46,#245c67);color:#fff;border-radius:18px;padding:28px 30px;margin:18px 0;box-shadow:0 12px 35px rgba(21,42,70,.18)}.tng-rsg-hero h1{color:#fff;margin:0 0 6px;font-size:30px}.tng-rsg-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.tng-rsg-card{background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:18px}.tng-rsg-stat strong{display:block;font-size:28px;color:#152a46;margin-top:4px}.tng-rsg-toolbar{display:flex;justify-content:space-between;align-items:end;gap:14px;flex-wrap:wrap;margin:18px 0}.tng-rsg-toolbar form{display:flex;align-items:end;gap:10px}.tng-rsg-toolbar label{display:grid;gap:5px;font-weight:600}.tng-rsg-list{display:grid;gap:14px}.tng-rsg-item{display:grid;grid-template-columns:minmax(0,1fr) 155px;gap:18px;align-items:center}.tng-rsg-path{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:10px 0}.tng-rsg-node{background:#f3f5f7;border-radius:8px;padding:7px 10px;font-weight:700}.tng-rsg-edge{color:#475467}.tng-rsg-confidence{font-size:28px;font-weight:800;color:#152a46}.tng-rsg-meter{height:8px;background:#edf1f5;border-radius:999px;overflow:hidden;margin:8px 0}.tng-rsg-meter span{display:block;height:100%;background:#0e9384}.tng-rsg-reason{color:#475467}.tng-rsg-badge{display:inline-flex;border-radius:999px;padding:5px 10px;background:#ecfdf3;color:#067647;font-size:12px;font-weight:700}.tng-rsg-empty{text-align:center;padding:44px 20px}@media(max-width:850px){.tng-rsg-grid{grid-template-columns:1fr}.tng-rsg-item{grid-template-columns:1fr}}
            </style>
            <div class="tng-rsg-hero"><p style="text-transform:uppercase;letter-spacing:.12em;margin:0 0 8px;color:#f6bd3b;font-weight:700">TN Platform · Destination Intelligence</p><h1>Relationship Suggestions</h1><p>Discover likely graph connections using canonical references, venue and destination names, coordinates, entity types, and existing graph patterns.</p></div>
            <?php if (isset($_GET['approved'])): ?><div class="notice notice-success inline"><p>Relationship approved and added to the destination graph.</p></div><?php endif; ?>
            <?php if (isset($_GET['invalid'])): ?><div class="notice notice-error inline"><p>The suggestion could not be approved.</p></div><?php endif; ?>
            <div class="tng-rsg-grid">
                <div class="tng-rsg-card tng-rsg-stat"><span>Entities scanned</span><strong><?php echo esc_html(number_format_i18n(count($entities))); ?></strong></div>
                <div class="tng-rsg-card tng-rsg-stat"><span>Suggestions found</span><strong><?php echo esc_html(number_format_i18n(count($suggestions))); ?></strong></div>
                <div class="tng-rsg-card tng-rsg-stat"><span>Minimum confidence</span><strong><?php echo esc_html((string)$minimum); ?>%</strong></div>
            </div>
            <div class="tng-rsg-toolbar"><div><h2 style="margin:0">Proposed connections</h2><p style="margin:5px 0 0;color:#646970">Existing relationships are excluded automatically.</p></div><form method="get"><input type="hidden" name="page" value="tng-relationship-suggestions"><label>Minimum confidence<input type="number" name="minimum" min="50" max="100" value="<?php echo esc_attr((string)$minimum); ?>"></label><button class="button">Rescan</button></form></div>
            <div class="tng-rsg-list">
                <?php if (!$suggestions): ?><div class="tng-rsg-card tng-rsg-empty"><span class="tng-rsg-badge">Graph is caught up</span><h2>No new suggestions at this confidence level</h2><p>Add coordinates, venue names, destination references, or more entities to unlock additional suggestions.</p></div><?php endif; ?>
                <?php foreach ($suggestions as $suggestion): ?>
                    <article class="tng-rsg-card tng-rsg-item"><div><small><?php echo esc_html($suggestion['source_type']); ?> → <?php echo esc_html($suggestion['target_type']); ?></small><div class="tng-rsg-path"><span class="tng-rsg-node"><?php echo esc_html($suggestion['source_title']); ?></span><span class="tng-rsg-edge">→ <code><?php echo esc_html($suggestion['type']); ?></code> →</span><span class="tng-rsg-node"><?php echo esc_html($suggestion['target_title']); ?></span></div><p class="tng-rsg-reason"><?php echo esc_html($suggestion['evidence']); ?></p><?php if ($suggestion['distance'] !== null): ?><span class="tng-rsg-badge"><?php echo esc_html(number_format_i18n($suggestion['distance'], 1)); ?> miles apart</span><?php endif; ?></div><div><div class="tng-rsg-confidence"><?php echo esc_html((string)round($suggestion['confidence'] * 100)); ?>%</div><div class="tng-rsg-meter"><span style="width:<?php echo esc_attr((string)round($suggestion['confidence'] * 100)); ?>%"></span></div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="tng_approve_relationship_suggestion"><?php wp_nonce_field('tng_approve_relationship_suggestion'); ?><input type="hidden" name="source" value="<?php echo esc_attr($suggestion['source_id']); ?>"><input type="hidden" name="target" value="<?php echo esc_attr($suggestion['target_id']); ?>"><input type="hidden" name="relationship_type" value="<?php echo esc_attr($suggestion['type']); ?>"><input type="hidden" name="confidence" value="<?php echo esc_attr((string)$suggestion['confidence']); ?>"><input type="hidden" name="evidence" value="<?php echo esc_attr($suggestion['evidence']); ?>"><button class="button button-primary" style="width:100%">Approve relationship</button></form></div></article>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    public function approve(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tng_approve_relationship_suggestion');
        $source = sanitize_text_field(wp_unslash($_POST['source'] ?? ''));
        $target = sanitize_text_field(wp_unslash($_POST['target'] ?? ''));
        $type = sanitize_key(wp_unslash($_POST['relationship_type'] ?? ''));
        $confidence = max(0, min(1, (float)($_POST['confidence'] ?? 0)));
        $evidence = sanitize_text_field(wp_unslash($_POST['evidence'] ?? ''));
        $vocabulary = Relationship_Manager::vocabulary();
        $source_post = $this->post_id_for_entity($source);
        if (!$source_post || !$this->post_id_for_entity($target) || $source === $target || !isset($vocabulary[$type])) $this->redirect('invalid');
        $relationships = get_post_meta($source_post, '_tng_entity_relationships', true);
        $relationships = is_array($relationships) ? $relationships : [];
        $key = $source . '|' . $type . '|' . $target;
        foreach ($relationships as $relationship) if ($this->relationship_key((array)$relationship) === $key) $this->redirect('approved');
        $relationships[] = ['relationship_id'=>'rel_'.strtoupper(wp_generate_password(20,false,false)),'source_entity_id'=>$source,'target_entity_id'=>$target,'type'=>$type,'confidence'=>$confidence,'source_provider'=>'tn-suggestion-engine','evidence'=>$evidence,'created_at'=>current_time('mysql',true)];
        update_post_meta($source_post, '_tng_entity_relationships', array_values($relationships));
        $this->redirect('approved');
    }

    /** @return array<int,array<string,mixed>> */
    private function suggest(array $entities): array {
        $existing = $this->existing_keys($entities); $suggestions = [];
        foreach ($entities as $source_id => $source) foreach ($entities as $target_id => $target) {
            if ($source_id === $target_id) continue;
            $proposal = $this->proposal($source, $target);
            if (!$proposal) continue;
            $key = $source_id.'|'.$proposal['type'].'|'.$target_id;
            if (isset($existing[$key])) continue;
            $suggestions[$key] = array_merge($proposal, ['source_id'=>$source_id,'source_title'=>$source['title'],'source_type'=>$source['type'],'target_id'=>$target_id,'target_title'=>$target['title'],'target_type'=>$target['type']]);
        }
        uasort($suggestions, static fn(array $a,array $b): int => $b['confidence'] <=> $a['confidence']);
        return array_slice(array_values($suggestions), 0, 100);
    }

    private function proposal(array $source, array $target): ?array {
        $sp=(array)$source['payload']; $tp=(array)$target['payload']; $stype=sanitize_key((string)$source['type']); $ttype=sanitize_key((string)$target['type']);
        foreach (['venue_entity_id'=>'held_at','location_entity_id'=>'located_in','destination_entity_id'=>'located_in','parent_entity_id'=>'part_of'] as $field=>$type) if (($sp[$field]??'')===$target['id']) return ['type'=>$type,'confidence'=>.99,'evidence'=>'The source entity contains a direct canonical reference to the target.','distance'=>$this->distance($sp,$tp)];
        $source_text=$this->normalized(implode(' ',array_filter([(string)$source['title'],(string)($sp['venue']??''),(string)($sp['venue_name']??''),(string)($sp['location']??''),(string)($sp['destination']??'')]))); $target_title=$this->normalized((string)$target['title']);
        if (in_array($stype,['event','concert','festival'],true) && in_array($ttype,['venue','theater','amphitheater'],true) && $target_title!=='' && str_contains($source_text,$target_title)) return ['type'=>'held_at','confidence'=>.96,'evidence'=>'The venue name appears in the event title or venue metadata.','distance'=>$this->distance($sp,$tp)];
        if (in_array($ttype,['destination','location','city','park'],true) && $target_title!=='' && $source_text!==$target_title && str_contains($source_text,$target_title)) return ['type'=>'located_in','confidence'=>.90,'evidence'=>'The destination or location name appears in the source metadata.','distance'=>$this->distance($sp,$tp)];
        $distance=$this->distance($sp,$tp);
        if ($distance!==null && $distance<=1) return ['type'=>'near','confidence'=>.95,'evidence'=>'Both entities have coordinates and are within one mile.','distance'=>$distance];
        if ($distance!==null && $distance<=5) return ['type'=>'near','confidence'=>.86,'evidence'=>'Both entities have coordinates and are within five miles.','distance'=>$distance];
        if ($distance!==null && $distance<=15) return ['type'=>'near','confidence'=>.72,'evidence'=>'Both entities have coordinates and are within fifteen miles.','distance'=>$distance];
        $sloc=$this->normalized((string)($sp['location']??$sp['destination']??$sp['city']??'')); $tloc=$this->normalized((string)($tp['location']??$tp['destination']??$tp['city']??''));
        if ($sloc!=='' && $sloc===$tloc && $source_id=$source['id']) return ['type'=>'related_to','confidence'=>.70,'evidence'=>'Both entities share the same normalized destination or city metadata.','distance'=>$distance];
        return null;
    }

    private function existing_keys(array $entities): array { $keys=[]; foreach($entities as $entity) foreach((array)$entity['relationships'] as $r){if(!is_array($r))continue;$s=(string)($r['source_entity_id']??$entity['id']);$t=(string)($r['target_entity_id']??'');$type=sanitize_key((string)($r['type']??'related_to'));if($s!==''&&$t!=='')$keys[$s.'|'.$type.'|'.$t]=true;} return $keys; }
    private function post_id_for_entity(string $id): int { $posts=get_posts(['post_type'=>self::ENTITY_TYPE,'post_status'=>['publish','draft','private'],'posts_per_page'=>1,'fields'=>'ids','meta_key'=>'_tng_entity_id','meta_value'=>$id]); return $posts?(int)$posts[0]:0; }
    private function relationship_key(array $r): string { return (string)($r['source_entity_id']??'').'|'.sanitize_key((string)($r['type']??'related_to')).'|'.(string)($r['target_entity_id']??''); }
    private function normalized(string $value): string { return trim(preg_replace('/[^a-z0-9]+/',' ',strtolower(remove_accents($value))) ?? ''); }
    private function coordinates(array $p): ?array { $lat=$p['latitude']??$p['lat']??null;$lng=$p['longitude']??$p['lng']??$p['lon']??null;if((!is_numeric($lat)||!is_numeric($lng))&&isset($p['coordinates'])&&is_array($p['coordinates'])){$lat=$p['coordinates']['lat']??$p['coordinates']['latitude']??null;$lng=$p['coordinates']['lng']??$p['coordinates']['longitude']??null;}return is_numeric($lat)&&is_numeric($lng)?[(float)$lat,(float)$lng]:null; }
    private function distance(array $a,array $b): ?float { $one=$this->coordinates($a);$two=$this->coordinates($b);if(!$one||!$two)return null;[$la1,$lo1]=array_map('deg2rad',$one);[$la2,$lo2]=array_map('deg2rad',$two);$dlat=$la2-$la1;$dlon=$lo2-$lo1;$h=sin($dlat/2)**2+cos($la1)*cos($la2)*sin($dlon/2)**2;return round(3958.7613*2*atan2(sqrt($h),sqrt(1-$h)),1); }
    private function redirect(string $notice): void { wp_safe_redirect(add_query_arg(['page'=>'tng-relationship-suggestions',$notice=>'1'],admin_url('admin.php'))); exit; }
}
