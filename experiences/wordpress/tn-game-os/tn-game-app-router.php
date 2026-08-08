<?php
/**
 * Plugin Name: TN Game App Router
 * Plugin URI: https://thetngame.com
 * Description: Native TN Game routes and full-page app shell for the platform.
 * Version: 3.8.2
 * Author: The TN Game
 * Text Domain: tn-game-app-router
 */
if (!defined('ABSPATH')) exit;
add_action('plugins_loaded',static function():void{
    if(!defined('TNG_OS_PATH')||!defined('TNG_OS_URL'))return;
    foreach([
        'TNG_Launch_Gate'=>'tn-game-launch-gate.php','TNG_Platform_UI'=>'tn-game-platform-ui.php','TNG_Trip_Data'=>'tn-game-trip-data.php','TNG_Trip_Builder_UI'=>'tn-game-trip-builder-ui.php','TNG_Active_Trip_UI'=>'tn-game-active-trip-ui.php','TNG_Past_Trips_UI'=>'tn-game-past-trips-ui.php','TNG_Trip_Dock'=>'tn-game-trip-dock.php','TNG_Play_UI'=>'tn-game-play-ui.php','TNG_Games_UI'=>'tn-game-games-ui.php','TNG_Game_Builder_UI'=>'tn-game-builder-ui.php','TNG_Game_Visual_Builder'=>'tn-game-visual-builder.php','TNG_Game_Runtime_UI'=>'tn-game-runtime-ui.php','TNG_Game_Runtime_Map'=>'tn-game-runtime-map.php','TNG_Game_Developer_GPS'=>'tn-game-developer-gps.php','TNG_Game_Progression'=>'tn-game-game-progression.php','TNG_Map_UI'=>'tn-game-map-ui.php','TNG_Trips_UI'=>'tn-game-trips-ui.php','TNG_Profile_UI'=>'tn-game-profile-ui.php','TNG_Settings_UI'=>'tn-game-settings-ui.php','TNG_Search_UI'=>'tn-game-search-ui.php','TNG_Progress_UI'=>'tn-game-progress-ui.php','TNG_Social_UI'=>'tn-game-social-ui.php','TNG_Challenges_UI'=>'tn-game-challenges-ui.php','TNG_Library_UI'=>'tn-game-library-ui.php','TNG_Directory_UI'=>'tn-game-directory-ui.php','TNG_Trail_UI'=>'tn-game-trail-ui.php','TNG_Place_UI'=>'tn-game-place-ui.php','TNG_Event_UI'=>'tn-game-event-ui.php'
    ] as$class=>$file){if(!class_exists($class)&&is_readable(TNG_OS_PATH.$file))require_once TNG_OS_PATH.$file;}
    if(!class_exists('TNG_OS\\Platform\\App_Router'))require_once TNG_OS_PATH.'app/Platform/class-app-router.php';
},20);
add_action('wp_enqueue_scripts',static function():void{
    if(!class_exists('TNG_OS\\Platform\\App_Router')||!\\TNG_OS\\Platform\\App_Router::is_app_request())return;
    wp_enqueue_style('tng-platform-ui',TNG_OS_URL.'assets/css/platform-ui.css',[],'2.2.0');
    wp_enqueue_style('tng-platform-ui-refinements',TNG_OS_URL.'assets/css/platform-ui-refinements.css',['tng-platform-ui'],'2.2.0');
    wp_enqueue_style('tng-app-router',TNG_OS_URL.'assets/css/app-router.css',['tng-platform-ui'],'3.8.2');
    wp_enqueue_style('tng-ui-kit',TNG_OS_URL.'assets/css/ui-kit.css',['tng-platform-ui','tng-app-router'],'2.7.0');
    $route=\\TNG_OS\\Platform\\App_Router::current_route();
    if($route==='play')wp_enqueue_style('tng-play-ui',TNG_OS_URL.'assets/css/play-ui.css',['tng-ui-kit'],'0.3.4');
    if($route==='games')wp_enqueue_style('tng-games-ui',TNG_OS_URL.'assets/css/games-ui.css',['tng-ui-kit'],'0.3.0');
    if($route==='game-builder')wp_enqueue_style('tng-game-builder-ui',TNG_OS_URL.'assets/css/game-builder-ui.css',['tng-ui-kit'],'0.5.0');
    if($route==='game-play')wp_enqueue_style('tng-game-runtime-ui',TNG_OS_URL.'assets/css/game-runtime-ui.css',['tng-ui-kit'],'0.2.0');
    if($route==='map')wp_enqueue_style('tng-map-ui',TNG_OS_URL.'assets/css/map-ui.css',['tng-ui-kit'],'0.3.4');
    if(in_array($route,['trips','saved','trip-builder','active-trip','trip-mode','past-trips'],true))wp_enqueue_style('tng-trips-ui',TNG_OS_URL.'assets/css/trips-ui.css',['tng-ui-kit'],'0.3.0');
    if(in_array($route,['saved','trip-builder','active-trip','trip-mode','past-trips'],true))wp_enqueue_style('tng-trip-builder-ui',TNG_OS_URL.'assets/css/trip-builder-ui.css',['tng-trips-ui'],'0.3.0');
    if(in_array($route,['active-trip','trip-mode'],true))wp_enqueue_style('tng-active-trip-ui',TNG_OS_URL.'assets/css/active-trip-ui.css',['tng-trip-builder-ui','tng-active-trip-leaflet'],'0.3.0');
    if($route==='past-trips')wp_enqueue_style('tng-past-trips-ui',TNG_OS_URL.'assets/css/past-trips-ui.css',['tng-trip-builder-ui'],'0.1.1');
    if($route==='profile')wp_enqueue_style('tng-profile-ui',TNG_OS_URL.'assets/css/profile-ui.css',['tng-ui-kit'],'0.2.4');
    if($route==='profile-settings')wp_enqueue_style('tng-settings-ui',TNG_OS_URL.'assets/css/settings-ui.css',['tng-ui-kit'],'0.1.4');
    if($route==='search')wp_enqueue_style('tng-search-ui',TNG_OS_URL.'assets/css/search-ui.css',['tng-ui-kit'],'0.1.9');
    if(in_array($route,['leaderboard','achievements'],true))wp_enqueue_style('tng-progress-ui',TNG_OS_URL.'assets/css/progress-ui.css',['tng-ui-kit'],'0.1.8');
    if(in_array($route,['friends','activity'],true))wp_enqueue_style('tng-social-ui',TNG_OS_URL.'assets/css/social-ui.css',['tng-ui-kit'],'0.1.7');
    if($route==='challenges')wp_enqueue_style('tng-challenges-ui',TNG_OS_URL.'assets/css/challenges-ui.css',['tng-ui-kit'],'0.1.6');
    if(in_array($route,['journal','explorer-journal','completed','my-photos'],true))wp_enqueue_style('tng-library-ui',TNG_OS_URL.'assets/css/library-ui.css',['tng-ui-kit'],'0.1.5');
    if(in_array($route,['trails','events','food','top-sights','destinations'],true))wp_enqueue_style('tng-directory-ui',TNG_OS_URL.'assets/css/directory-ui.css',['tng-ui-kit'],'0.2.0');
    wp_enqueue_script('tng-platform-ui',TNG_OS_URL.'assets/js/platform-ui.js',[],'2.2.0',true);
    if($route==='game-play')wp_enqueue_script('tng-game-runtime-ui',TNG_OS_URL.'assets/js/game-runtime-ui.js',[],'0.2.0',true);
    if($route==='trip-builder')wp_enqueue_script('tng-trip-builder',TNG_OS_URL.'assets/js/trip-builder.js',['tng-trip-data','tng-trip-builder-leaflet'],'0.5.0',true);
    if(in_array($route,['active-trip','trip-mode'],true))wp_enqueue_script('tng-active-trip',TNG_OS_URL.'assets/js/active-trip.js',['tng-trip-data','tng-active-trip-leaflet'],'0.3.0',true);
},100);
add_action('wp_footer', static function (): void {
    if (is_admin()) return;
    $profile_url = wp_json_encode(home_url('/profile/'));
    $search_url  = wp_json_encode(home_url('/search/'));
    echo '<script id="tng-platform-route-fixes">';
    echo '(() => {';
    echo 'const profileUrl=' . $profile_url . ',searchUrl=' . $search_url . ';';
    echo 'const hideTravelerContactBar=()=>{';
    echo 'if(!document.querySelector(".tng-app-nav,.tng-router-shell,.tng-native-screen"))return;';
    echo 'const normalize=s=>(s||"").replace(/\\s+/g," ").trim().toLowerCase();';
    echo 'const digits=s=>(s||"").replace(/\\D/g,"");';
    echo 'let emailNode=null,phoneNode=null;';
    echo 'const walker=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT);';
    echo 'let n;while((n=walker.nextNode())){';
    echo 'const t=normalize(n.nodeValue);';
    echo 'if(!emailNode&&t.includes("travelerwp@gmail.com"))emailNode=n.parentElement;';
    echo 'if(!phoneNode&&digits(t).includes("999656888"))phoneNode=n.parentElement;';
    echo 'if(emailNode&&phoneNode)break;';
    echo '}';
    echo 'const seed=emailNode||phoneNode;if(!seed)return;';
    echo 'let row=seed;';
    echo 'for(let i=0;i<10&&row&&row!==document.body;i++,row=row.parentElement){';
    echo 'const r=row.getBoundingClientRect(),txt=normalize(row.textContent),d=digits(txt);';
    echo 'const hasEmail=txt.includes("travelerwp@gmail.com"),hasPhone=d.includes("999656888");';
    echo 'if((hasEmail||hasPhone)&&r.width>=Math.min(window.innerWidth*.65,700)&&r.height>=24&&r.height<=110){';
    echo 'row.style.setProperty("display","none","important");';
    echo 'row.style.setProperty("height","0","important");';
    echo 'row.style.setProperty("min-height","0","important");';
    echo 'row.style.setProperty("margin","0","important");';
    echo 'row.style.setProperty("padding","0","important");';
    echo 'row.setAttribute("data-tng-hidden-traveler-contact","1");return;';
    echo '}';
    echo '}';
    echo '};';
    echo 'const fix=()=>{';
    echo 'document.querySelectorAll(".tng-app-nav__item").forEach(link=>{';
    echo 'const label=(link.textContent||"").trim().toLowerCase();';
    echo 'if(label.includes("profile"))link.setAttribute("href",profileUrl);';
    echo '});';
    echo 'document.querySelectorAll(".tng-topbar__action").forEach(link=>link.setAttribute("href",searchUrl));';
    echo 'hideTravelerContactBar();';
    echo '};';
    echo 'let queued=false;const queueFix=()=>{if(queued)return;queued=true;requestAnimationFrame(()=>{queued=false;fix();});};';
    echo 'fix();';
    echo 'new MutationObserver(queueFix).observe(document.documentElement,{childList:true,subtree:true});';
    echo '})();';
    echo '</script>';
}, 999);
register_activation_hook(__FILE__,static function():void{update_option('tng_os_rewrite_flush_needed',1,false);flush_rewrite_rules(false);});
register_deactivation_hook(__FILE__,static function():void{flush_rewrite_rules(false);});
