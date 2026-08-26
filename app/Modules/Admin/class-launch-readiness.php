<?php
namespace TNG_OS\Modules\Admin;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Launch_Readiness implements Module_Interface {
    private const PAGE_SLUG = 'tng-launch-readiness';
    private const CACHE_KEY = 'tng_launch_readiness_v1';
    private const CACHE_TTL = 10 * MINUTE_IN_SECONDS;

    private const LISTING_TYPES = ['st_hotel','st_tours','st_rental','st_cars','st_activity','product'];
    private const DEMO_GEO_TERMS = ['California','Los Angeles','San Francisco','San Diego','New York','Las Vegas','Nevada','Miami','Florida','Chicago','Illinois','London','Paris','Dubai','Rome','Barcelona','Tokyo'];
    private const LEGACY_BRAND_TERMS = ['Traveler','travelerwp','COVID-19 Response','Cancellation options','Help Center','© Copyright Traveler','Copyright Traveler 2022'];
    private const PLACEHOLDER_TERMS = ['Lorem ipsum','Sample Page','Demo','Dummy','Test Listing'];

    public function id(): string { return 'launch_readiness'; }

    public function register(Container $container): void {
        $container->set('launch_readiness', $this);
        add_action('admin_menu', [$this, 'menu'], 30);
        add_action('admin_post_tng_launch_readiness_rescan', [$this, 'rescan']);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page('tn-game-os','Launch Readiness','Launch Readiness','manage_options',self::PAGE_SLUG,[$this,'render']);
    }

    public function rescan(): void {
        if (!current_user_can('manage_options')) wp_die('You do not have permission to run this audit.');
        check_admin_referer('tng_launch_readiness_rescan');
        delete_transient(self::CACHE_KEY);
        $this->scan(true);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&rescanned=1'));
        exit;
    }

    public function render(): void {
        if (!current_user_can('manage_options')) return;
        $report = $this->scan(false);
        $score = (int)($report['score'] ?? 0);
        $issues = is_array($report['issues'] ?? null) ? $report['issues'] : [];
        $counts = is_array($report['counts'] ?? null) ? $report['counts'] : [];
        $grade = $score >= 90 ? 'Launch ready' : ($score >= 75 ? 'Almost ready' : ($score >= 55 ? 'Needs cleanup' : 'Not launch ready'));
        $tone = $score >= 90 ? '#18794e' : ($score >= 75 ? '#8a6400' : '#b42318');
        ?>
        <div class="wrap">
            <h1>TN Game Launch Readiness</h1>
            <p>Scans the public content layer for leftover Traveler/demo content, non-Tennessee inventory, placeholder records, outdated navigation, and other launch blockers. This audit never deletes content automatically.</p>
            <?php if (isset($_GET['rescanned'])): ?><div class="notice notice-success is-dismissible"><p>Launch-readiness audit refreshed.</p></div><?php endif; ?>

            <div style="display:grid;grid-template-columns:minmax(250px,1.2fr) repeat(4,minmax(150px,1fr));gap:14px;max-width:1250px;margin:22px 0;align-items:stretch;">
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:22px;box-shadow:0 6px 20px rgba(0,0,0,.04);">
                    <div style="font-size:12px;text-transform:uppercase;letter-spacing:.08em;font-weight:700;color:#646970;">Platform score</div>
                    <div style="display:flex;align-items:end;gap:14px;margin-top:8px;"><strong style="font-size:48px;line-height:1;color:<?php echo esc_attr($tone); ?>;"><?php echo esc_html((string)$score); ?></strong><span style="font-size:18px;color:#646970;margin-bottom:5px;">/ 100</span></div>
                    <div style="font-size:18px;font-weight:700;margin-top:10px;color:<?php echo esc_attr($tone); ?>;"><?php echo esc_html($grade); ?></div>
                </div>
                <?php foreach ([['High priority',(int)($counts['high'] ?? 0)],['Medium',(int)($counts['medium'] ?? 0)],['Low',(int)($counts['low'] ?? 0)],['Public items scanned',(int)($report['scanned'] ?? 0)]] as [$label,$value]): ?>
                    <div style="background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:20px;">
                        <div style="font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#646970;font-weight:700;"><?php echo esc_html($label); ?></div>
                        <div style="font-size:32px;font-weight:750;margin-top:9px;"><?php echo esc_html((string)$value); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="display:flex;gap:10px;align-items:center;margin:0 0 18px;">
                <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tng_launch_readiness_rescan'), 'tng_launch_readiness_rescan')); ?>">Rescan now</a>
                <span style="color:#646970;">Last scan: <?php echo esc_html((string)($report['generated_at'] ?? '')); ?></span>
            </div>

            <div style="background:#fff;border:1px solid #dcdcde;border-radius:14px;overflow:hidden;max-width:1250px;">
                <div style="padding:20px 22px;border-bottom:1px solid #eee;"><h2 style="margin:0;">Launch blockers & recommendations</h2><p style="margin:6px 0 0;color:#646970;">Review high-priority items first. Nothing here is removed or unpublished automatically.</p></div>
                <?php if (!$issues): ?>
                    <div style="padding:28px;"><strong>No launch blockers detected.</strong><p style="margin-bottom:0;color:#646970;">The public content layer passed the current audit rules.</p></div>
                <?php else: ?>
                    <table class="widefat striped" style="border:0;box-shadow:none;">
                        <thead><tr><th style="width:110px;">Priority</th><th>Issue</th><th style="width:170px;">Area</th><th style="width:140px;">Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($issues as $issue): $priority=(string)($issue['priority'] ?? 'medium'); $badge=$priority==='high'?'#b42318':($priority==='low'?'#475467':'#8a6400'); ?>
                            <tr>
                                <td><span style="display:inline-block;padding:4px 8px;border-radius:999px;background:<?php echo esc_attr($badge); ?>;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;"><?php echo esc_html($priority); ?></span></td>
                                <td><strong><?php echo esc_html((string)($issue['title'] ?? 'Issue')); ?></strong><br><span style="color:#646970;"><?php echo esc_html((string)($issue['detail'] ?? '')); ?></span></td>
                                <td><?php echo esc_html((string)($issue['area'] ?? 'Content')); ?></td>
                                <td><?php if (!empty($issue['url'])): ?><a class="button button-small" href="<?php echo esc_url((string)$issue['url']); ?>">Review</a><?php else: ?>—<?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;max-width:1250px;margin-top:18px;">
                <?php foreach ([['1. Clean public inventory','Remove or unpublish demo/foreign Traveler listings and obsolete footer/menu content before launch.'],['2. Verify mobile shell','Test Explore, Map, Play, Trips, and Profile at narrow mobile widths after cleanup.'],['3. Turn on intelligence','Once the public data layer is clean, Adventure AI and recommendation systems can safely use it as their source of truth.']] as [$title,$text]): ?>
                    <div style="background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:20px;"><strong><?php echo esc_html($title); ?></strong><p style="margin-bottom:0;color:#646970;line-height:1.5;"><?php echo esc_html($text); ?></p></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private function scan(bool $force): array {
        if (!$force) { $cached=get_transient(self::CACHE_KEY); if (is_array($cached)) return $cached; }
        $issues=[];
        $public_types=get_post_types(['public'=>true],'names');
        $post_types=array_values(array_unique(array_merge(array_values($public_types),self::LISTING_TYPES)));
        $ids=get_posts(['post_type'=>$post_types,'post_status'=>['publish','draft','pending','private'],'posts_per_page'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC']);
        $scanned=count($ids);
        $listing_type_map=array_fill_keys(self::LISTING_TYPES,true);

        foreach($ids as $post_id){
            $post=get_post($post_id); if(!$post) continue;
            $haystack=strtolower(wp_strip_all_tags($post->post_title.' '.$post->post_excerpt.' '.$post->post_content));
            $is_listing=isset($listing_type_map[$post->post_type]);
            foreach(self::LEGACY_BRAND_TERMS as $term){ if($term!=='' && strpos($haystack,strtolower($term))!==false){ $issues[]=$this->issue('high','Traveler/demo branding found',sprintf('%s contains “%s”.',$post->post_title ?: ('Post #'.$post_id),$term),'Brand cleanup',get_edit_post_link($post_id,'')); break; } }
            if($is_listing){ foreach(self::DEMO_GEO_TERMS as $term){ if(strpos($haystack,strtolower($term))!==false){ $issues[]=$this->issue('high','Possible non-Tennessee demo inventory',sprintf('%s references %s.',$post->post_title ?: ('Listing #'.$post_id),$term),'Inventory',get_edit_post_link($post_id,'')); break; } } }
            foreach(self::PLACEHOLDER_TERMS as $term){ if(strpos($haystack,strtolower($term))!==false){ $issues[]=$this->issue('medium','Placeholder/test content detected',sprintf('%s contains “%s”.',$post->post_title ?: ('Post #'.$post_id),$term),'Content quality',get_edit_post_link($post_id,'')); break; } }
            if($post->post_status==='publish' && trim(wp_strip_all_tags($post->post_content))==='' && in_array($post->post_type,['page','post','st_activity','st_hotel','st_tours','st_rental'],true)) $issues[]=$this->issue('low','Published item has no body content',$post->post_title ?: ('Post #'.$post_id),'Content quality',get_edit_post_link($post_id,''));
        }

        foreach(wp_get_nav_menus() as $menu){ foreach((wp_get_nav_menu_items($menu->term_id) ?: []) as $item){ $label=strtolower((string)$item->title.' '.(string)$item->url); foreach(self::LEGACY_BRAND_TERMS as $term){ if(strpos($label,strtolower($term))!==false){ $issues[]=$this->issue('high','Legacy Traveler navigation link',sprintf('%s → %s',$menu->name,$item->title),'Navigation',admin_url('nav-menus.php?action=edit&menu='.(int)$menu->term_id)); break; } } } }
        foreach(['widget_text','widget_custom_html','widget_nav_menu'] as $option_name){ $value=get_option($option_name,[]); if(!is_array($value)) continue; $serialized=strtolower(wp_json_encode($value)); foreach(self::LEGACY_BRAND_TERMS as $term){ if(strpos($serialized,strtolower($term))!==false){ $issues[]=$this->issue('high','Legacy Traveler footer/widget content',sprintf('%s contains “%s”.',$option_name,$term),'Footer / widgets',admin_url('widgets.php')); break; } } }

        foreach(get_posts(['post_type'=>'tng_destination','post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids']) as $destination_id){
            $title=get_the_title($destination_id); $related=0; $meta=get_post_meta($destination_id);
            foreach($meta as $key=>$values){ if(strpos((string)$key,'count')===false && strpos((string)$key,'related')===false && strpos((string)$key,'relationship')===false) continue; foreach((array)$values as $raw){ $maybe=maybe_unserialize($raw); if(is_numeric($maybe)) $related=max($related,(int)$maybe); if(is_array($maybe)) $related=max($related,count($maybe)); } }
            if($related===0) $issues[]=$this->issue('medium','Destination may have no connected experiences',$title ?: ('Destination #'.$destination_id),'Destinations',get_edit_post_link($destination_id,''));
        }

        $issues=$this->dedupe($issues);
        usort($issues,static function(array $a,array $b):int{$rank=['high'=>0,'medium'=>1,'low'=>2];return($rank[$a['priority']]??9)<=>($rank[$b['priority']]??9);});
        $counts=['high'=>0,'medium'=>0,'low'=>0]; foreach($issues as $issue){$p=$issue['priority']??'medium';if(isset($counts[$p]))$counts[$p]++;}
        $score=100-min(60,$counts['high']*10)-min(30,$counts['medium']*4)-min(10,$counts['low']); $score=max(0,min(100,$score));
        $report=['score'=>$score,'counts'=>$counts,'issues'=>$issues,'scanned'=>$scanned,'generated_at'=>wp_date('M j, Y g:i a')];
        set_transient(self::CACHE_KEY,$report,self::CACHE_TTL); return $report;
    }

    private function issue(string $priority,string $title,string $detail,string $area,string $url=''):array { return compact('priority','title','detail','area','url'); }
    private function dedupe(array $issues):array { $out=[];$seen=[];foreach($issues as $issue){$key=md5(wp_json_encode([$issue['priority']??'',$issue['title']??'',$issue['detail']??'',$issue['area']??'']));if(isset($seen[$key]))continue;$seen[$key]=true;$out[]=$issue;}return$out; }
}
