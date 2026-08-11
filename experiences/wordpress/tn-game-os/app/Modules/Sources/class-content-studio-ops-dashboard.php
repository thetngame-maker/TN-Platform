<?php
namespace TNG_OS\Modules\Sources;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Content_Studio_Ops_Dashboard implements Module_Interface {
    private const HISTORY_OPTION = 'tng_town_scanner_history_v1';
    private const MONITOR_OPTION = 'tng_town_monitor_settings_v1';
    private const MONITOR_LOG_OPTION = 'tng_town_monitor_log_v1';
    private const HEARTBEAT_OPTION = 'tng_server_cron_heartbeat_v1';
    private const CRON_HOOK = 'tng_town_monitor_cron';
    private const HEARTBEAT_HOOK = 'tng_server_cron_heartbeat';
    private const CANDIDATE_CPT = 'tng_local_candidate';

    public function id(): string { return 'content_studio_ops_dashboard'; }

    public function register(Container $container): void {
        add_action('admin_menu', [$this, 'admin_menu'], 21);
        $container->set('content_studio_ops_dashboard', $this);
    }

    public function boot(Container $container): void {}

    public function admin_menu(): void {
        add_submenu_page('tng-content-studio','Operations Dashboard','Overview','edit_posts','tng-content-studio-overview',[$this,'render_page']);
    }

    private function history(): array { $value=get_option(self::HISTORY_OPTION,[]); return is_array($value)?$value:[]; }
    private function monitor_settings(): array { $value=get_option(self::MONITOR_OPTION,[]); return is_array($value)?$value:[]; }
    private function monitor_log(): array { $value=get_option(self::MONITOR_LOG_OPTION,[]); return is_array($value)?$value:[]; }
    private function heartbeat(): array { $value=get_option(self::HEARTBEAT_OPTION,[]); return is_array($value)?$value:[]; }

    private function actionable_counts(array $history): array {
        $counts=['new'=>0,'changed'=>0,'returned'=>0,'missing'=>0,'possibly_closed'=>0];
        foreach($history as $town_history){
            if(!is_array($town_history)) continue;
            $snapshot=is_array($town_history['snapshot']??null)?$town_history['snapshot']:[];
            foreach($snapshot as $item){
                if(!is_array($item)||!empty($item['_review_status'])) continue;
                $change=(string)($item['change_status']??''); $status=(string)($item['status']??'');
                if($change==='new'&&$status!=='new') continue;
                if(isset($counts[$change])) $counts[$change]++;
            }
        }
        return $counts;
    }

    private function scan_stats(array $history): array {
        $runs=0; $places=0; $latest=''; $cutoff=time()-7*DAY_IN_SECONDS;
        foreach($history as $town_history){
            if(!is_array($town_history)) continue;
            foreach((array)($town_history['scans']??[]) as $scan){
                if(!is_array($scan)) continue;
                $time=strtotime((string)($scan['scanned_at']??''))?:0;
                if($time>0&&($latest===''||$time>(strtotime($latest)?:0))) $latest=(string)$scan['scanned_at'];
                if($time>=$cutoff){$runs++;$places+=absint($scan['total']??0);}
            }
        }
        return ['runs_7d'=>$runs,'places_7d'=>$places,'latest'=>$latest];
    }

    private function failures_24h(array $log): array {
        $cutoff=time()-DAY_IN_SECONDS; $rows=[];
        foreach($log as $row){
            if(!is_array($row)||($row['status']??'')!=='error') continue;
            $time=strtotime((string)($row['time']??''))?:0;
            if($time>=$cutoff) $rows[]=$row;
        }
        return $rows;
    }

    private function candidate_count(): int {
        $counts=wp_count_posts(self::CANDIDATE_CPT); if(!$counts) return 0;
        return absint($counts->publish??0)+absint($counts->draft??0)+absint($counts->pending??0)+absint($counts->private??0);
    }

    private function towns_count(array $history): int { return count(array_filter($history,'is_array')); }

    private function monitor_towns(array $settings): array {
        $raw=preg_split('/\r\n|\r|\n/',(string)($settings['towns']??''));
        return array_values(array_unique(array_filter(array_map('sanitize_text_field',(array)$raw))));
    }

    private function heartbeat_status(array $heartbeat): array {
        $last=absint($heartbeat['last_timestamp']??0);
        if(!$last) return ['Waiting','No heartbeat recorded yet','#b26200'];
        $age=time()-$last;
        if($age<=45*MINUTE_IN_SECONDS) return ['Healthy',human_time_diff($last,time()).' ago','#008a20'];
        if($age<=2*HOUR_IN_SECONDS) return ['Late',human_time_diff($last,time()).' ago','#b26200'];
        return ['Stale',human_time_diff($last,time()).' ago','#b32d2e'];
    }

    private function card(string $label,string $value,string $detail='',string $accent='#2271b1'): void {
        ?><div style="background:#fff;border:1px solid #dcdcde;border-left:4px solid <?php echo esc_attr($accent); ?>;border-radius:8px;padding:16px;min-width:180px;flex:1"><div style="font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#646970;font-weight:600"><?php echo esc_html($label); ?></div><div style="font-size:28px;font-weight:700;line-height:1.2;margin-top:6px"><?php echo esc_html($value); ?></div><?php if($detail!==''): ?><div style="margin-top:6px;color:#646970"><?php echo esc_html($detail); ?></div><?php endif; ?></div><?php
    }

    public function render_page(): void {
        if(!current_user_can('edit_posts')) return;
        $history=$this->history(); $settings=$this->monitor_settings(); $log=$this->monitor_log(); $heartbeat=$this->heartbeat();
        $actions=$this->actionable_counts($history); $scans=$this->scan_stats($history); $failures=$this->failures_24h($log);
        $next=wp_next_scheduled(self::CRON_HOOK); $heartbeat_next=wp_next_scheduled(self::HEARTBEAT_HOOK);
        $enabled=!empty($settings['enabled']); $wp_cron_disabled=defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON;
        $monitor_towns=$this->monitor_towns($settings); $candidate_count=$this->candidate_count();
        $last_log=!empty($log[0])&&is_array($log[0])?$log[0]:[];
        [$heartbeat_label,$heartbeat_detail,$heartbeat_color]=$this->heartbeat_status($heartbeat);

        if(!$enabled){$cron_label='Off';$cron_detail='Automatic monitoring disabled';$cron_color='#646970';}
        elseif(!$next){$cron_label='Needs attention';$cron_detail='Monitoring enabled but no event is scheduled';$cron_color='#b32d2e';}
        elseif($wp_cron_disabled){$cron_label='Server cron needed';$cron_detail='WP-Cron trigger is disabled';$cron_color='#b26200';}
        else{$cron_label='Scheduled';$cron_detail='Next '.wp_date('M j, g:i A',$next);$cron_color='#008a20';}
        ?>
        <div class="wrap">
            <h1>📊 Content Studio Operations</h1>
            <p>Health and activity across Local Discovery, Town Scanner, Changes Inbox, Town Monitoring, and scheduled jobs.</p>

            <div style="display:flex;gap:12px;flex-wrap:wrap;margin:20px 0">
                <?php $this->card('Scheduler heartbeat',$heartbeat_label,$heartbeat_detail,$heartbeat_color); ?>
                <?php $this->card('Town monitor',$cron_label,$cron_detail,$cron_color); ?>
                <?php $this->card('Monitored towns',(string)count($monitor_towns),$enabled?ucfirst((string)($settings['cadence']??'weekly')).' schedule':'Monitoring off','#2271b1'); ?>
                <?php $this->card('Discovery queue',(string)$candidate_count,'Candidates awaiting review','#8c4b9b'); ?>
                <?php $this->card('Failures · 24h',(string)count($failures),count($failures)?'Review recent errors':'No monitor errors','#b32d2e'); ?>
            </div>

            <h2>Actionable changes</h2>
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin:12px 0 22px">
                <?php $this->card('New',(string)$actions['new'],'New discoveries','#008a20'); ?>
                <?php $this->card('Changed',(string)$actions['changed'],'Business details changed','#b26200'); ?>
                <?php $this->card('Missing once',(string)$actions['missing'],'Not found in latest scan','#996800'); ?>
                <?php $this->card('Possibly closed',(string)$actions['possibly_closed'],'Missing in repeated scans','#b32d2e'); ?>
                <?php $this->card('Returned',(string)$actions['returned'],'Back after an absence','#2271b1'); ?>
            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:24px">
                <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=tng-town-changes')); ?>">Open Changes Inbox</a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-town-monitor')); ?>">Town Monitoring</a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-cron-reliability')); ?>">Cron Reliability</a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-town-scanner')); ?>">Run Town Scanner</a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-local-discovery')); ?>">Local Discovery</a>
            </div>

            <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;max-width:1100px">
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px">
                    <h2 style="margin-top:0">Scanner activity</h2>
                    <table class="widefat striped"><tbody>
                        <tr><td>Town histories</td><td><strong><?php echo absint($this->towns_count($history)); ?></strong></td></tr>
                        <tr><td>Recorded scan runs · 7 days</td><td><strong><?php echo absint($scans['runs_7d']); ?></strong></td></tr>
                        <tr><td>Places processed · 7 days</td><td><strong><?php echo absint($scans['places_7d']); ?></strong></td></tr>
                        <tr><td>Latest scanner history</td><td><?php echo esc_html($scans['latest']?:'No scans yet'); ?></td></tr>
                        <tr><td>Last monitor log</td><td><?php echo esc_html((string)($last_log['time']??'No runs yet')); ?></td></tr>
                    </tbody></table>
                </div>

                <div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px">
                    <h2 style="margin-top:0">Cron health</h2>
                    <table class="widefat striped"><tbody>
                        <tr><td>Scheduler heartbeat</td><td><strong><?php echo esc_html($heartbeat_label); ?></strong></td></tr>
                        <tr><td>Last heartbeat</td><td><?php echo esc_html((string)($heartbeat['last_at']??'Never')); ?></td></tr>
                        <tr><td>Next heartbeat event</td><td><?php echo $heartbeat_next?esc_html(wp_date('Y-m-d g:i A',$heartbeat_next)):'Not scheduled'; ?></td></tr>
                        <tr><td>Automatic monitoring</td><td><strong><?php echo $enabled?'Enabled':'Disabled'; ?></strong></td></tr>
                        <tr><td>WP-Cron trigger</td><td><strong><?php echo $wp_cron_disabled?'Disabled':'Available'; ?></strong></td></tr>
                        <tr><td>Next town monitor event</td><td><?php echo $next?esc_html(wp_date('Y-m-d g:i A',$next)):'Not scheduled'; ?></td></tr>
                        <tr><td>Cadence</td><td><?php echo esc_html(ucfirst((string)($settings['cadence']??'weekly'))); ?></td></tr>
                    </tbody></table>
                    <?php if($heartbeat_label==='Late'||$heartbeat_label==='Stale'): ?><div class="notice notice-error inline" style="margin:14px 0 0"><p><strong>Scheduler heartbeat is <?php echo esc_html(strtolower($heartbeat_label)); ?>.</strong> Check the Cloudways server cron.</p></div><?php endif; ?>
                    <?php if($wp_cron_disabled): ?><div class="notice notice-warning inline" style="margin:14px 0 0"><p><strong>WP-Cron is disabled.</strong> This is fine when a real Cloudways server cron runs WordPress due events regularly.</p></div><?php endif; ?>
                </div>
            </div>

            <h2 style="margin-top:26px">Recent monitor activity</h2>
            <?php if(!$log): ?><p>No monitoring runs have been recorded yet.</p><?php else: ?>
            <table class="widefat striped" style="max-width:1100px"><thead><tr><th>Time</th><th>Town</th><th>Run</th><th>Result</th></tr></thead><tbody>
            <?php foreach(array_slice($log,0,10) as $row): ?><tr><td><?php echo esc_html((string)($row['time']??'')); ?></td><td><?php echo esc_html((string)($row['town']??'')); ?></td><td><?php echo esc_html((string)($row['status']??'')); ?></td><td><?php echo esc_html((string)($row['message']??'')); ?></td></tr><?php endforeach; ?>
            </tbody></table><?php endif; ?>

            <?php if($failures): ?><h2 style="margin-top:26px;color:#b32d2e">Failures in the last 24 hours</h2><table class="widefat striped" style="max-width:1100px"><thead><tr><th>Time</th><th>Town</th><th>Error</th></tr></thead><tbody><?php foreach($failures as $row): ?><tr><td><?php echo esc_html((string)($row['time']??'')); ?></td><td><?php echo esc_html((string)($row['town']??'')); ?></td><td><?php echo esc_html((string)($row['message']??'')); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
        </div>
        <?php
    }
}
