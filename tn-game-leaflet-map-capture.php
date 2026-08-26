<?php
/**
 * TN Game Leaflet Map Capture
 * Captures gameplay Leaflet map instances before the gameplay runtime initializes.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Leaflet_Map_Capture {
    public static function boot(): void {
        add_action('wp_enqueue_scripts',[__CLASS__,'enqueue'],50);
    }

    private static function is_gameplay(): bool {
        if (is_admin()) return false;
        $uri=(string)($_SERVER['REQUEST_URI']??'');
        return strpos($uri,'/game-play/')!==false || (function_exists('is_page') && is_page('game-play'));
    }

    public static function enqueue(): void {
        if(!self::is_gameplay()) return;
        if(!wp_script_is('leaflet','registered') && !wp_script_is('leaflet','enqueued')) return;

        wp_add_inline_script('leaflet', <<<'JS'
(()=>{
 if(!window.L||!L.map||L.map.__tngEarlyCaptured)return;
 window.TNG_LIVE_GAME_MAPS=window.TNG_LIVE_GAME_MAPS||[];
 const original=L.map;
 const wrapped=function(){
   const map=original.apply(this,arguments);
   if(!window.TNG_LIVE_GAME_MAPS.includes(map))window.TNG_LIVE_GAME_MAPS.push(map);
   try{
     const c=map.getContainer&&map.getContainer();
     if(c&&c.closest&&c.closest('.tng-game-runtime'))window.TNG_LIVE_GAME_MAP=map;
   }catch(e){}
   return map;
 };
 Object.keys(original).forEach(k=>{try{wrapped[k]=original[k]}catch(e){}});
 wrapped.__tngCaptured=true;
 wrapped.__tngEarlyCaptured=true;
 L.map=wrapped;
})();
JS
        ,'after');
    }
}
TNG_Leaflet_Map_Capture::boot();
