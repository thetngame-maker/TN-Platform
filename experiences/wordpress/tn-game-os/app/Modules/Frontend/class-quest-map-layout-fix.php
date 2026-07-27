<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Quest_Map_Layout_Fix implements Module_Interface {
    public function id(): string { return 'quest_map_layout_fix'; }

    public function register(Container $container): void {
        $container->set('quest_map_layout_fix', $this);
        add_action('wp_head', [$this, 'render_styles'], 99);
        add_action('wp_footer', [$this, 'render_script'], 99);
    }

    public function boot(Container $container): void {}

    private function active(): bool {
        return isset($_GET['tng_quest_runtime_id']) || is_singular('tng_quest');
    }

    public function render_styles(): void {
        if (!$this->active()) return;
        ?>
        <style id="tng-quest-map-layout-fix">
        .tng-live-map.leaflet-container{position:relative!important;overflow:hidden!important;width:100%!important;outline-offset:1px;background:#ddd!important;-webkit-tap-highlight-color:transparent}
        .tng-live-map .leaflet-pane,.tng-live-map .leaflet-tile,.tng-live-map .leaflet-marker-icon,.tng-live-map .leaflet-marker-shadow,.tng-live-map .leaflet-tile-container,.tng-live-map .leaflet-pane>svg,.tng-live-map .leaflet-pane>canvas,.tng-live-map .leaflet-zoom-box{position:absolute!important;left:0!important;top:0!important}
        .tng-live-map .leaflet-map-pane,.tng-live-map svg.leaflet-zoom-animated{transform-origin:0 0!important}
        .tng-live-map .leaflet-tile{width:256px!important;height:256px!important;max-width:none!important;max-height:none!important;margin:0!important;padding:0!important;border:0!important;object-fit:fill!important;visibility:inherit!important}
        .tng-live-map .leaflet-tile-container{pointer-events:none!important}
        .tng-live-map .leaflet-pane{z-index:400!important}.tng-live-map .leaflet-tile-pane{z-index:200!important}.tng-live-map .leaflet-overlay-pane{z-index:400!important}.tng-live-map .leaflet-shadow-pane{z-index:500!important}.tng-live-map .leaflet-marker-pane{z-index:600!important}.tng-live-map .leaflet-tooltip-pane{z-index:650!important}.tng-live-map .leaflet-popup-pane{z-index:700!important}
        .tng-live-map .leaflet-zoom-animated{transform-origin:0 0!important}.tng-live-map .leaflet-zoom-hide{visibility:hidden!important}
        .tng-live-map .leaflet-top,.tng-live-map .leaflet-bottom{position:absolute!important;z-index:1000!important;pointer-events:none!important}.tng-live-map .leaflet-top{top:0!important}.tng-live-map .leaflet-right{right:0!important}.tng-live-map .leaflet-bottom{bottom:0!important}.tng-live-map .leaflet-left{left:0!important}
        .tng-live-map .leaflet-control{position:relative!important;z-index:800!important;pointer-events:auto!important;float:left!important;clear:both!important}
        .tng-live-map .leaflet-right .leaflet-control{float:right!important}.tng-live-map .leaflet-top .leaflet-control{margin-top:10px!important}.tng-live-map .leaflet-bottom .leaflet-control{margin-bottom:10px!important}.tng-live-map .leaflet-left .leaflet-control{margin-left:10px!important}.tng-live-map .leaflet-right .leaflet-control{margin-right:10px!important}
        .tng-live-map .leaflet-control-zoom a{display:block!important;width:30px!important;height:30px!important;line-height:30px!important;text-align:center!important;text-decoration:none!important;background:#fff!important;color:#18213d!important}
        .tng-live-map .leaflet-control-attribution{font-size:11px!important;background:rgba(255,255,255,.8)!important;padding:0 5px!important}
        .tng-live-map img,.tng-live-map img.leaflet-marker-icon,.tng-live-map img.leaflet-marker-shadow{max-width:none!important;max-height:none!important}
        </style>
        <?php
    }

    public function render_script(): void {
        if (!$this->active()) return;
        ?>
        <script id="tng-quest-map-layout-fix-script">
        (()=>{
            const refresh=()=>window.dispatchEvent(new Event('resize'));
            const attach=root=>{
                if(!root||root.dataset.mapLayoutFix==='2')return;
                root.dataset.mapLayoutFix='2';
                const map=root.querySelector('.tng-live-map');
                if(!map)return;
                const run=()=>{requestAnimationFrame(refresh);setTimeout(refresh,80);setTimeout(refresh,350);};
                if(window.ResizeObserver)new ResizeObserver(run).observe(map);
                new MutationObserver(run).observe(root,{attributes:true,attributeFilter:['class']});
                run();
            };
            document.querySelectorAll('.tng-runtime').forEach(attach);
            new MutationObserver(()=>document.querySelectorAll('.tng-runtime').forEach(attach)).observe(document.body,{childList:true,subtree:true});
        })();
        </script>
        <?php
    }
}
