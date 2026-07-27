<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Quest_Map_Layout_Fix implements Module_Interface {
    public function id(): string { return 'quest_map_layout_fix'; }

    public function register(Container $container): void {
        $container->set('quest_map_layout_fix', $this);
        add_action('wp_footer', [$this, 'render_fix'], 99);
    }

    public function boot(Container $container): void {}

    public function render_fix(): void {
        if (!isset($_GET['tng_quest_runtime_id']) && !is_singular('tng_quest')) return;
        ?>
        <style id="tng-quest-map-layout-fix">
            .tng-live-map.leaflet-container { width:100%; position:relative; overflow:hidden; }
            .tng-live-map .leaflet-pane,
            .tng-live-map .leaflet-tile,
            .tng-live-map .leaflet-marker-icon,
            .tng-live-map .leaflet-marker-shadow,
            .tng-live-map .leaflet-tile-container { max-width:none !important; max-height:none !important; }
            .tng-live-map .leaflet-tile { width:256px !important; height:256px !important; padding:0 !important; margin:0 !important; border:0 !important; }
            .tng-live-map img { max-width:none !important; }
        </style>
        <script id="tng-quest-map-layout-fix-script">
        (() => {
            const refresh = () => window.dispatchEvent(new Event('resize'));
            const attach = root => {
                if (!root || root.dataset.mapLayoutFix === '1') return;
                root.dataset.mapLayoutFix = '1';
                const map = root.querySelector('.tng-live-map');
                if (!map) return;
                const resizeObserver = new ResizeObserver(() => requestAnimationFrame(refresh));
                resizeObserver.observe(map);
                const classObserver = new MutationObserver(() => {
                    setTimeout(refresh, 60);
                    setTimeout(refresh, 300);
                });
                classObserver.observe(root, { attributes:true, attributeFilter:['class'] });
                setTimeout(refresh, 100);
                setTimeout(refresh, 500);
            };
            document.querySelectorAll('.tng-runtime').forEach(attach);
            new MutationObserver(() => document.querySelectorAll('.tng-runtime').forEach(attach))
                .observe(document.body, { childList:true, subtree:true });
        })();
        </script>
        <?php
    }
}
