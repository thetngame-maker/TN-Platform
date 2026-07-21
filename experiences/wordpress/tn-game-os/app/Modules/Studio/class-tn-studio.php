<?php
namespace TNG_OS\Modules\Studio;
use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
if (!defined('ABSPATH')) exit;

final class TN_Studio implements Module_Interface {
    private const SOURCE_TYPE='tng_concert_source';
    public function id(): string { return 'tn_studio'; }
    public function register(Container $container): void {
        add_action('admin_menu',[$this,'menu'],23);
        add_action('admin_enqueue_scripts',[$this,'assets']);
        foreach(['run_discovery','knowledge_stats','knowledge_entities','knowledge_entity','knowledge_save_entity','knowledge_relationships','knowledge_save_relationship','knowledge_graph','knowledge_seed','platform_config','platform_services','platform_health','platform_logs','platform_metrics'] as $action){
            add_action('wp_ajax_tng_studio_'.$action,[$this,$action]);
        }
    }
    public function boot(Container $container): void {}
    public function menu(): void { add_submenu_page('tn-game-os','TN Studio','TN Studio','manage_options','tng-studio',[$this,'page']); }
    public function assets(string $hook): void {
        if(strpos($hook,'tng-studio')===false)return;
        wp_enqueue_style('tng-studio',TNG_OS_URL.'assets/admin/tn-studio.css',[],TNG_OS_VERSION);
        wp_enqueue_script('tng-studio',TNG_OS_URL.'assets/admin/tn-studio.js',[],TNG_OS_VERSION,true);
        wp_localize_script('tng-studio','TNG_STUDIO',['ajaxUrl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('tng_studio'),'version'=>TNG_OS_VERSION]);
    }
    private function guard(): void { check_ajax_referer('tng_studio','nonce'); if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Administrator access is required.'],403); }
    private function api(string $method,string $path,array $body=[]): array {
        $endpoint=untrailingslashit((string)get_option('tng_ci_api_endpoint','')); $key=(string)get_option('tng_ci_api_key','');
        if(!$endpoint||!$key)return ['ok'=>false,'code'=>400,'message'=>'Intelligence Cloud is not configured.'];
        $args=['method'=>$method,'timeout'=>180,'redirection'=>3,'headers'=>['Accept'=>'application/json','Content-Type'=>'application/json','X-API-Key'=>$key]];
        if($body)$args['body']=wp_json_encode($body);
        $response=wp_remote_request($endpoint.$path,$args);
        if(is_wp_error($response))return ['ok'=>false,'code'=>502,'message'=>$response->get_error_message()];
        $code=wp_remote_retrieve_response_code($response); $data=json_decode(wp_remote_retrieve_body($response),true);
        if($code<200||$code>=300||!is_array($data)||empty($data['ok']))return ['ok'=>false,'code'=>$code?:502,'message'=>(string)($data['detail']??$data['error']??'The API returned an invalid response.')];
        return ['ok'=>true,'code'=>$code,'data'=>$data['data']??[]];
    }
    private function reply(array $result): void { $result['ok']?wp_send_json_success($result['data'],$result['code']):wp_send_json_error(['message'=>$result['message']],$result['code']); }
    public function page(): void {
        if(!current_user_can('manage_options'))wp_die('Not allowed.');
        $sources=get_posts(['post_type'=>self::SOURCE_TYPE,'post_status'=>['publish','draft'],'posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
        $endpoint=untrailingslashit((string)get_option('tng_ci_api_endpoint',''));
        ?>
        <div class="wrap tng-studio">
          <header class="tng-studio__header"><div><span class="tng-studio__eyebrow">TN PLATFORM</span><h1>TN Studio <small>Destination Operating System</small></h1><p>Operate platform infrastructure, canonical knowledge, relationships, graph connections, and discovery from one workspace.</p></div><div class="tng-studio__connection <?php echo $endpoint?'is-connected':'is-missing'; ?>"><span></span><?php echo $endpoint?'Platform Core connected':'API configuration required'; ?></div></header>
          <nav class="tng-studio__nav"><button class="is-active" data-section="dashboard">Dashboard</button><button data-section="platform">Platform</button><button data-section="knowledge">Knowledge</button><button data-section="relationships">Relationships</button><button data-section="graph">Graph Explorer</button><button data-section="discovery">Discovery</button></nav>
          <main class="tng-studio__workspace">
            <section class="tng-section is-active" data-panel="dashboard"><div class="tng-studio__control-card"><div><h2>Knowledge Core</h2><p>The first sprint establishes permanent entity identities, relationship records, revisions, and a graph API.</p></div><button id="tng-refresh-dashboard" class="button button-primary">Refresh</button></div><div id="tng-dashboard-metrics" class="tng-studio__metrics"></div><div class="tng-studio__panel"><h2>Getting started</h2><p>Create your first entity, connect it to another entity, then inspect the connection in Graph Explorer.</p><button id="tng-seed" class="button">Create Pelham + The Caverns sample</button><div id="tng-dashboard-status"></div></div></section>

            <section class="tng-section" data-panel="platform"><div class="tng-studio__control-card"><div><h2>Platform Infrastructure</h2><p>Configuration, service registry, health, structured logs, metrics, and correlation IDs.</p></div><button id="tng-platform-refresh" class="button button-primary">Refresh platform</button></div><div id="tng-platform-summary" class="tng-studio__metrics"></div><div class="tng-two-col"><div class="tng-studio__panel"><h2>Service Registry</h2><div id="tng-platform-services" class="tng-list"><p>Loading services…</p></div></div><div class="tng-studio__panel"><h2>Configuration</h2><pre id="tng-platform-config">Loading configuration…</pre></div></div><div class="tng-two-col"><div class="tng-studio__panel"><h2>Metrics</h2><div id="tng-platform-metrics"></div></div><div class="tng-studio__panel"><div class="tng-panel-title"><h2>Operational Logs</h2><select id="tng-log-level"><option value="">All levels</option><option value="info">Info</option><option value="warning">Warning</option><option value="error">Error</option></select></div><div id="tng-platform-logs" class="tng-log-stream"><p>Loading logs…</p></div></div></div></section>
            <section class="tng-section" data-panel="knowledge"><div class="tng-studio__control-card"><div><h2>Entity Registry</h2><p>Stable IDs remain independent from WordPress post IDs.</p></div><div class="tng-inline"><input id="tng-entity-search" placeholder="Search entities"><button id="tng-new-entity" class="button button-primary">New entity</button></div></div><div class="tng-two-col"><div class="tng-studio__panel"><div id="tng-entity-list" class="tng-list"></div></div><div class="tng-studio__panel"><div id="tng-entity-inspector" class="tng-inspector"><p>Select an entity to inspect it.</p></div></div></div></section>
            <section class="tng-section" data-panel="relationships"><div class="tng-studio__control-card"><div><h2>Relationship Registry</h2><p>Connect entities with typed, auditable edges.</p></div><button id="tng-new-relationship" class="button button-primary">New relationship</button></div><div class="tng-studio__panel"><div id="tng-relationship-list" class="tng-list"></div></div></section>
            <section class="tng-section" data-panel="graph"><div class="tng-studio__control-card"><div><h2>Graph Explorer</h2><p>Explore immediate relationships around a selected entity.</p></div><select id="tng-graph-root"><option value="">Choose an entity</option></select></div><div class="tng-studio__panel"><div id="tng-graph-canvas" class="tng-graph"><p>Select an entity to render its graph.</p></div></div></section>
            <section class="tng-section" data-panel="discovery"><div class="tng-studio__control-card"><div><h2>Discovery Studio</h2><p>Browser diagnostics remain available and do not import content.</p></div><div class="tng-inline"><select id="tng-studio-source"><?php foreach($sources as $source):$url=(string)get_post_meta($source->ID,'_tng_ci_source_url',true);?><option value="<?php echo (int)$source->ID;?>" data-url="<?php echo esc_attr($url);?>"><?php echo esc_html($source->post_title);?></option><?php endforeach;?></select><input id="tng-studio-url" type="url" value="<?php echo $sources?esc_attr((string)get_post_meta($sources[0]->ID,'_tng_ci_source_url',true)):'';?>"><button id="tng-studio-run" class="button button-primary" <?php disabled(!$endpoint);?>>Run Discovery</button></div></div><div id="tng-discovery-output" class="tng-studio__panel"><p>Ready to inspect a source.</p></div></section>
          </main>
          <dialog id="tng-modal"><form method="dialog" id="tng-modal-form"><div class="tng-modal-head"><h2 id="tng-modal-title"></h2><button value="cancel" class="button">Close</button></div><div id="tng-modal-body"></div><div class="tng-modal-actions"><button value="cancel" class="button">Cancel</button><button id="tng-modal-save" value="default" class="button button-primary">Save</button></div></form></dialog>
        </div><?php
    }
    public function run_discovery(): void { $this->guard(); $url=isset($_POST['source_url'])?esc_url_raw(wp_unslash($_POST['source_url'])):''; if(!$url)wp_send_json_error(['message'=>'A source URL is required.'],400); $this->reply($this->api('POST','/v1/discovery/run',['source_url'=>$url])); }
    public function platform_config(): void { $this->guard(); $this->reply($this->api('GET','/v1/platform/config')); }
    public function platform_services(): void { $this->guard(); $this->reply($this->api('GET','/v1/platform/services')); }
    public function platform_health(): void { $this->guard(); $this->reply($this->api('GET','/v1/platform/health')); }
    public function platform_logs(): void { $this->guard(); $level=sanitize_text_field(wp_unslash($_POST['level']??'')); $this->reply($this->api('GET','/v1/platform/logs?limit=100&level='.rawurlencode($level))); }
    public function platform_metrics(): void { $this->guard(); $this->reply($this->api('GET','/v1/platform/metrics')); }
    public function knowledge_stats(): void { $this->guard(); $this->reply($this->api('GET','/v1/knowledge/stats')); }
    public function knowledge_entities(): void { $this->guard(); $q=isset($_POST['q'])?sanitize_text_field(wp_unslash($_POST['q'])):''; $this->reply($this->api('GET','/v1/knowledge/entities?q='.rawurlencode($q).'&limit=500')); }
    public function knowledge_entity(): void { $this->guard(); $id=sanitize_text_field(wp_unslash($_POST['id']??'')); $this->reply($this->api('GET','/v1/knowledge/entities/'.rawurlencode($id))); }
    public function knowledge_save_entity(): void { $this->guard(); $payload=json_decode(wp_unslash($_POST['payload']??'{}'),true); if(!is_array($payload))$payload=[]; $id=sanitize_text_field(wp_unslash($_POST['id']??'')); $this->reply($this->api($id?'PATCH':'POST','/v1/knowledge/entities'.($id?'/'.rawurlencode($id):''),$payload)); }
    public function knowledge_relationships(): void { $this->guard(); $this->reply($this->api('GET','/v1/knowledge/relationships?limit=500')); }
    public function knowledge_save_relationship(): void { $this->guard(); $payload=json_decode(wp_unslash($_POST['payload']??'{}'),true); $this->reply($this->api('POST','/v1/knowledge/relationships',is_array($payload)?$payload:[])); }
    public function knowledge_graph(): void { $this->guard(); $id=sanitize_text_field(wp_unslash($_POST['id']??'')); $this->reply($this->api('GET','/v1/knowledge/graph/'.rawurlencode($id).'?depth=1')); }
    public function knowledge_seed(): void { $this->guard(); $this->reply($this->api('POST','/v1/knowledge/seed')); }
}
