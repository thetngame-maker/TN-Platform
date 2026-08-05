<?php
/**
 * Plugin Name: TN Game App Router
 * Plugin URI: https://thetngame.com
 * Description: Native TN Game application routes and full-page app shell for Explore, Play, Map, Trips, Profile, trail, and place pages.
 * Version: 1.6.0
 * Author: The TN Game
 * Text Domain: tn-game-app-router
 */
if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', static function (): void {
    if (!defined('TNG_OS_PATH') || !defined('TNG_OS_URL')) return;
    if (!class_exists('TNG_Platform_UI')) require_once TNG_OS_PATH . 'tn-game-platform-ui.php';
    if (!class_exists('TNG_Play_UI')) require_once TNG_OS_PATH . 'tn-game-play-ui.php';
    if (!class_exists('TNG_OS\\Platform\\App_Router')) require_once TNG_OS_PATH . 'app/Platform/class-app-router.php';
    if (!class_exists('TNG_Map_UI')) require_once TNG_OS_PATH . 'tn-game-map-ui.php';
    if (!class_exists('TNG_Trips_UI')) require_once TNG_OS_PATH . 'tn-game-trips-ui.php';
    if (!class_exists('TNG_Profile_UI')) require_once TNG_OS_PATH . 'tn-game-profile-ui.php';
    if (!class_exists('TNG_Trail_UI')) require_once TNG_OS_PATH . 'tn-game-trail-ui.php';
    if (!class_exists('TNG_Place_UI')) require_once TNG_OS_PATH . 'tn-game-place-ui.php';
}, 20);

add_action('wp_enqueue_scripts', static function (): void {
    if (!class_exists('TNG_OS\\Platform\\App_Router') || !\TNG_OS\Platform\App_Router::is_app_request()) return;
    wp_enqueue_style('tng-platform-ui', TNG_OS_URL . 'assets/css/platform-ui.css', [], '1.0.0');
    wp_enqueue_style('tng-platform-ui-refinements', TNG_OS_URL . 'assets/css/platform-ui-refinements.css', ['tng-platform-ui'], '1.0.0');
    wp_enqueue_style('tng-app-router', TNG_OS_URL . 'assets/css/app-router.css', ['tng-platform-ui'], '1.6.0');
    wp_enqueue_style('tng-ui-kit', TNG_OS_URL . 'assets/css/ui-kit.css', ['tng-platform-ui', 'tng-app-router'], '1.5.0');

    $route = \TNG_OS\Platform\App_Router::current_route();
    if ($route === 'play') wp_enqueue_style('tng-play-ui', TNG_OS_URL . 'assets/css/play-ui.css', ['tng-ui-kit'], '0.2.2');
    if ($route === 'map') wp_enqueue_style('tng-map-ui', TNG_OS_URL . 'assets/css/map-ui.css', ['tng-ui-kit'], '0.2.2');
    if ($route === 'trips') wp_enqueue_style('tng-trips-ui', TNG_OS_URL . 'assets/css/trips-ui.css', ['tng-ui-kit'], '0.1.2');
    if ($route === 'profile') wp_enqueue_style('tng-profile-ui', TNG_OS_URL . 'assets/css/profile-ui.css', ['tng-ui-kit'], '0.1.2');

    wp_enqueue_script('tng-platform-ui', TNG_OS_URL . 'assets/js/platform-ui.js', [], '1.0.0', true);
}, 100);

add_action('wp_footer', static function (): void {
    if (is_admin()) return;
    ?>
    <script id="tng-profile-route-fix">
    (() => {
        const profileUrl = <?php echo wp_json_encode(home_url('/profile/')); ?>;
        const fixLinks = () => {
            document.querySelectorAll('.tng-app-nav__item').forEach((link) => {
                const label = (link.textContent || '').trim().toLowerCase();
                if (label.includes('profile')) link.setAttribute('href', profileUrl);
            });
        };
        fixLinks();
        document.addEventListener('click', (event) => {
            const link = event.target.closest('.tng-app-nav__item');
            if (!link) return;
            const label = (link.textContent || '').trim().toLowerCase();
            if (!label.includes('profile')) return;
            event.preventDefault();
            window.location.assign(profileUrl);
        }, true);
        new MutationObserver(fixLinks).observe(document.documentElement, {childList: true, subtree: true});
    })();
    </script>
    <?php
}, 999);

register_activation_hook(__FILE__, static function (): void {
    update_option('tng_os_rewrite_flush_needed', 1, false);
    flush_rewrite_rules(false);
});
register_deactivation_hook(__FILE__, static function (): void { flush_rewrite_rules(false); });
