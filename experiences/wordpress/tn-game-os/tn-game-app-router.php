<?php
/**
 * Plugin Name: TN Game App Router
 * Plugin URI: https://thetngame.com
 * Description: Native TN Game routes and full-page app shell for the platform.
 * Version: 3.1.0
 * Author: The TN Game
 * Text Domain: tn-game-app-router
 */
if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', static function (): void {
    if (!defined('TNG_OS_PATH') || !defined('TNG_OS_URL')) return;
    if (!class_exists('TNG_Platform_UI')) require_once TNG_OS_PATH . 'tn-game-platform-ui.php';
    if (!class_exists('TNG_Trip_Data')) require_once TNG_OS_PATH . 'tn-game-trip-data.php';
    if (!class_exists('TNG_Trip_Builder_UI')) require_once TNG_OS_PATH . 'tn-game-trip-builder-ui.php';
    if (!class_exists('TNG_Active_Trip_UI')) require_once TNG_OS_PATH . 'tn-game-active-trip-ui.php';
    if (!class_exists('TNG_Past_Trips_UI')) require_once TNG_OS_PATH . 'tn-game-past-trips-ui.php';
    if (!class_exists('TNG_Trip_Dock')) require_once TNG_OS_PATH . 'tn-game-trip-dock.php';
    if (!class_exists('TNG_Play_UI')) require_once TNG_OS_PATH . 'tn-game-play-ui.php';
    if (!class_exists('TNG_Games_UI')) require_once TNG_OS_PATH . 'tn-game-games-ui.php';
    if (!class_exists('TNG_OS\\Platform\\App_Router')) require_once TNG_OS_PATH . 'app/Platform/class-app-router.php';
    if (!class_exists('TNG_Map_UI')) require_once TNG_OS_PATH . 'tn-game-map-ui.php';
    if (!class_exists('TNG_Trips_UI')) require_once TNG_OS_PATH . 'tn-game-trips-ui.php';
    if (!class_exists('TNG_Profile_UI')) require_once TNG_OS_PATH . 'tn-game-profile-ui.php';
    if (!class_exists('TNG_Settings_UI')) require_once TNG_OS_PATH . 'tn-game-settings-ui.php';
    if (!class_exists('TNG_Search_UI')) require_once TNG_OS_PATH . 'tn-game-search-ui.php';
    if (!class_exists('TNG_Progress_UI')) require_once TNG_OS_PATH . 'tn-game-progress-ui.php';
    if (!class_exists('TNG_Social_UI')) require_once TNG_OS_PATH . 'tn-game-social-ui.php';
    if (!class_exists('TNG_Challenges_UI')) require_once TNG_OS_PATH . 'tn-game-challenges-ui.php';
    if (!class_exists('TNG_Library_UI')) require_once TNG_OS_PATH . 'tn-game-library-ui.php';
    if (!class_exists('TNG_Directory_UI')) require_once TNG_OS_PATH . 'tn-game-directory-ui.php';
    if (!class_exists('TNG_Trail_UI')) require_once TNG_OS_PATH . 'tn-game-trail-ui.php';
    if (!class_exists('TNG_Place_UI')) require_once TNG_OS_PATH . 'tn-game-place-ui.php';
    if (!class_exists('TNG_Event_UI')) require_once TNG_OS_PATH . 'tn-game-event-ui.php';
}, 20);

add_action('wp_enqueue_scripts', static function (): void {
    if (!class_exists('TNG_OS\\Platform\\App_Router') || !\TNG_OS\Platform\App_Router::is_app_request()) return;
    wp_enqueue_style('tng-platform-ui', TNG_OS_URL . 'assets/css/platform-ui.css', [], '2.2.0');
    wp_enqueue_style('tng-platform-ui-refinements', TNG_OS_URL . 'assets/css/platform-ui-refinements.css', ['tng-platform-ui'], '2.2.0');
    wp_enqueue_style('tng-app-router', TNG_OS_URL . 'assets/css/app-router.css', ['tng-platform-ui'], '3.1.0');
    wp_enqueue_style('tng-ui-kit', TNG_OS_URL . 'assets/css/ui-kit.css', ['tng-platform-ui', 'tng-app-router'], '2.7.0');
    $route = \TNG_OS\Platform\App_Router::current_route();
    if ($route === 'play') wp_enqueue_style('tng-play-ui', TNG_OS_URL . 'assets/css/play-ui.css', ['tng-ui-kit'], '0.3.4');
    if ($route === 'games') wp_enqueue_style('tng-games-ui', TNG_OS_URL . 'assets/css/games-ui.css', ['tng-ui-kit'], '0.1.0');
    if ($route === 'map') wp_enqueue_style('tng-map-ui', TNG_OS_URL . 'assets/css/map-ui.css', ['tng-ui-kit'], '0.3.4');
    if (in_array($route, ['trips','saved','trip-builder','active-trip','trip-mode','past-trips'], true)) wp_enqueue_style('tng-trips-ui', TNG_OS_URL . 'assets/css/trips-ui.css', ['tng-ui-kit'], '0.3.0');
    if (in_array($route, ['saved','trip-builder','active-trip','trip-mode','past-trips'], true)) wp_enqueue_style('tng-trip-builder-ui', TNG_OS_URL . 'assets/css/trip-builder-ui.css', ['tng-trips-ui'], '0.1.3');
    if (in_array($route, ['active-trip','trip-mode'], true)) wp_enqueue_style('tng-active-trip-ui', TNG_OS_URL . 'assets/css/active-trip-ui.css', ['tng-trip-builder-ui'], '0.1.2');
    if ($route === 'past-trips') wp_enqueue_style('tng-past-trips-ui', TNG_OS_URL . 'assets/css/past-trips-ui.css', ['tng-trip-builder-ui'], '0.1.1');
    if ($route === 'profile') wp_enqueue_style('tng-profile-ui', TNG_OS_URL . 'assets/css/profile-ui.css', ['tng-ui-kit'], '0.2.4');
    if ($route === 'profile-settings') wp_enqueue_style('tng-settings-ui', TNG_OS_URL . 'assets/css/settings-ui.css', ['tng-ui-kit'], '0.1.4');
    if ($route === 'search') wp_enqueue_style('tng-search-ui', TNG_OS_URL . 'assets/css/search-ui.css', ['tng-ui-kit'], '0.1.9');
    if (in_array($route, ['leaderboard','achievements'], true)) wp_enqueue_style('tng-progress-ui', TNG_OS_URL . 'assets/css/progress-ui.css', ['tng-ui-kit'], '0.1.8');
    if (in_array($route, ['friends','activity'], true)) wp_enqueue_style('tng-social-ui', TNG_OS_URL . 'assets/css/social-ui.css', ['tng-ui-kit'], '0.1.7');
    if ($route === 'challenges') wp_enqueue_style('tng-challenges-ui', TNG_OS_URL . 'assets/css/challenges-ui.css', ['tng-ui-kit'], '0.1.6');
    if (in_array($route, ['journal','explorer-journal','completed','my-photos'], true)) wp_enqueue_style('tng-library-ui', TNG_OS_URL . 'assets/css/library-ui.css', ['tng-ui-kit'], '0.1.5');
    if (in_array($route, ['trails','events','food','top-sights','destinations'], true)) wp_enqueue_style('tng-directory-ui', TNG_OS_URL . 'assets/css/directory-ui.css', ['tng-ui-kit'], '0.2.0');
    wp_enqueue_script('tng-platform-ui', TNG_OS_URL . 'assets/js/platform-ui.js', [], '2.2.0', true);
    if ($route === 'trip-builder') wp_enqueue_script('tng-trip-builder', TNG_OS_URL . 'assets/js/trip-builder.js', ['tng-trip-data'], '0.1.3', true);
    if (in_array($route, ['active-trip','trip-mode'], true)) wp_enqueue_script('tng-active-trip', TNG_OS_URL . 'assets/js/active-trip.js', ['tng-trip-data'], '0.1.2', true);
}, 100);

add_action('wp_footer', static function (): void {
    if (is_admin()) return; ?>
    <script id="tng-platform-route-fixes">(() => {const profileUrl=<?php echo wp_json_encode(home_url('/profile/')); ?>,searchUrl=<?php echo wp_json_encode(home_url('/search/')); ?>;const fix=()=>{document.querySelectorAll('.tng-app-nav__item').forEach(link=>{const label=(link.textContent||'').trim().toLowerCase();if(label.includes('profile'))link.setAttribute('href',profileUrl)});document.querySelectorAll('.tng-topbar__action').forEach(link=>link.setAttribute('href',searchUrl))};fix();new MutationObserver(fix).observe(document.documentElement,{childList:true,subtree:true})})();</script>
    <?php
}, 999);

register_activation_hook(__FILE__, static function (): void { update_option('tng_os_rewrite_flush_needed', 1, false); flush_rewrite_rules(false); });
register_deactivation_hook(__FILE__, static function (): void { flush_rewrite_rules(false); });
