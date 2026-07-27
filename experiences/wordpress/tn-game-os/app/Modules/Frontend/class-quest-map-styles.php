<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Quest_Map_Styles implements Module_Interface {
    public function id(): string { return 'quest_map_styles'; }

    public function register(Container $container): void {
        $container->set('quest_map_styles', $this);
        add_action('wp_head', [$this, 'print_styles'], 99);
    }

    public function boot(Container $container): void {}

    public function print_styles(): void {
        $runtime_id = absint($_GET['tng_quest_runtime_id'] ?? 0);
        if (!$runtime_id && !is_singular('tng_quest')) return;
        ?>
        <style id="tng-leaflet-layout-fallback">
        .tng-live-map.leaflet-container{position:relative!important;overflow:hidden!important;outline-offset:1px;background:#ddd!important;-webkit-tap-highlight-color:transparent}
        .tng-live-map .leaflet-pane,.tng-live-map .leaflet-tile,.tng-live-map .leaflet-marker-icon,.tng-live-map .leaflet-marker-shadow,.tng-live-map .leaflet-tile-container,.tng-live-map .leaflet-pane>svg,.tng-live-map .leaflet-pane>canvas,.tng-live-map .leaflet-zoom-box{position:absolute!important;left:0!important;top:0!important}
        .tng-live-map .leaflet-map-pane,.tng-live-map svg.leaflet-zoom-animated{transform-origin:0 0!important}
        .tng-live-map .leaflet-tile{width:256px!important;height:256px!important;max-width:none!important;max-height:none!important;margin:0!important;padding:0!important;border:0!important;object-fit:fill!important;visibility:inherit!important}
        .tng-live-map .leaflet-tile-container{pointer-events:none!important}
        .tng-live-map .leaflet-pane{z-index:400!important}.tng-live-map .leaflet-tile-pane{z-index:200!important}.tng-live-map .leaflet-overlay-pane{z-index:400!important}.tng-live-map .leaflet-shadow-pane{z-index:500!important}.tng-live-map .leaflet-marker-pane{z-index:600!important}.tng-live-map .leaflet-tooltip-pane{z-index:650!important}.tng-live-map .leaflet-popup-pane{z-index:700!important}
        .tng-live-map .leaflet-zoom-animated{transform-origin:0 0!important}.tng-live-map .leaflet-zoom-hide{visibility:hidden!important}
        .tng-live-map .leaflet-control{position:relative!important;z-index:800!important;pointer-events:auto!important}.tng-live-map .leaflet-top,.tng-live-map .leaflet-bottom{position:absolute!important;z-index:1000!important;pointer-events:none!important}.tng-live-map .leaflet-top{top:0!important}.tng-live-map .leaflet-right{right:0!important}.tng-live-map .leaflet-bottom{bottom:0!important}.tng-live-map .leaflet-left{left:0!important}
        .tng-live-map .leaflet-control-zoom a{display:block!important;width:30px!important;height:30px!important;line-height:30px!important;text-align:center!important;text-decoration:none!important;background:#fff!important;color:#18213d!important}
        .tng-live-map .leaflet-control-attribution{font-size:11px!important;background:rgba(255,255,255,.8)!important;padding:0 5px!important}
        .tng-live-map img.leaflet-marker-icon,.tng-live-map img.leaflet-marker-shadow{max-width:none!important}
        </style>
        <?php
    }
}
