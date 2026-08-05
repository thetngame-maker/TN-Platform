<?php
namespace TNG_OS\Platform;

if (!defined('ABSPATH')) exit;

final class App_Router {
    private const ROUTES = ['explore', 'play', 'map', 'trips', 'profile'];
    private static string $route = '';

    public static function boot(): void {
        add_action('init', [self::class, 'register_rewrites'], 20);
        add_filter('query_vars', [self::class, 'query_vars']);
        add_action('template_redirect', [self::class, 'resolve_route'], 0);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets'], 90);
        add_filter('template_include', [self::class, 'template'], 99999);
        add_filter('body_class', [self::class, 'body_classes'], 999);
        add_filter('document_title_parts', [self::class, 'document_title']);
    }

    public static function register_rewrites(): void {
        foreach (self::ROUTES as $route) add_rewrite_rule('^' . preg_quote($route, '/') . '/?$', 'index.php?tng_app_route=' . $route, 'top');
    }

    public static function query_vars(array $vars): array { $vars[] = 'tng_app_route'; return $vars; }

    public static function resolve_route(): void {
        $route = sanitize_key((string) get_query_var('tng_app_route'));
        if (!$route) {
            $path = trim((string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
            $candidate = explode('/', $path)[0] ?? '';
            if (in_array($candidate, self::ROUTES, true)) $route = $candidate;
        }
        if (!in_array($route, self::ROUTES, true)) return;
        self::$route = $route;
        global $wp_query;
        $wp_query->is_404 = false;
        $wp_query->is_page = true;
        status_header(200);
    }

    public static function current_route(): string { return self::$route; }
    public static function is_app_request(): bool { return self::$route !== ''; }

    public static function enqueue_assets(): void {
        if (self::is_app_request()) wp_enqueue_style('tng-app-router', TNG_OS_URL . 'assets/css/app-router.css', ['tng-platform-ui'], TNG_OS_VERSION);
    }

    public static function template(string $template): string {
        if (!self::is_app_request()) return $template;
        $app_template = TNG_OS_PATH . 'templates/app-shell.php';
        return is_readable($app_template) ? $app_template : $template;
    }

    public static function body_classes(array $classes): array {
        if (!self::is_app_request()) return $classes;
        $classes[] = 'tng-platform-ui';
        $classes[] = 'tng-app-route';
        $classes[] = 'tng-app-route--' . self::$route;
        $classes[] = 'tng-hide-traveler-chrome';
        return array_values(array_unique($classes));
    }

    public static function document_title(array $parts): array {
        if (!self::is_app_request()) return $parts;
        $titles = ['explore'=>'Explore','play'=>'Play','map'=>'Map','trips'=>'Trips','profile'=>'Explorer Profile'];
        $parts['title'] = $titles[self::$route] ?? 'The TN Game';
        return $parts;
    }

    public static function render_screen(): string {
        switch (self::$route) {
            case 'explore': return class_exists('TNG_Platform_UI') ? \TNG_Platform_UI::explore() : self::fallback('Explore', 'Discover Tennessee adventures, places, and games.');
            case 'play': return class_exists('TNG_Play_UI') ? \TNG_Play_UI::render() : self::fallback('Play', 'Choose a game and start your next adventure.');
            case 'map': return class_exists('TNG_Map_UI') ? \TNG_Map_UI::render() : self::fallback('Map', 'Explore trails, games, sights, food, and local places around you.');
            case 'trips': return class_exists('TNG_Trips_UI') ? \TNG_Trips_UI::render() : self::fallback('Trips', 'Save places, organize stops, and continue active adventures.');
            case 'profile':
                $logged_in = is_user_logged_in();
                return self::screen('Explorer', $logged_in ? wp_get_current_user()->display_name : 'Your adventure starts here', $logged_in ? 'See your XP, achievements, completed adventures, photos, and friends.' : 'Create an account to earn XP and save your progress.', [
                    ['⭐',$logged_in ? 'My XP' : 'Create account',$logged_in ? '/achievements/' : wp_registration_url()],['🏆','Achievements','/achievements/'],['📸','My photos','/my-photos/'],['👥','Friends','/friends/'],
                ]);
        }
        return '';
    }

    private static function fallback(string $title, string $copy): string { return self::screen($title, $title, $copy, []); }

    private static function screen(string $eyebrow, string $title, string $copy, array $actions): string {
        ob_start(); ?>
        <main class="tng-native-screen tng-app-shell">
            <section class="tng-native-hero"><span class="tng-eyebrow"><?php echo esc_html($eyebrow); ?></span><h1><?php echo esc_html($title); ?></h1><p><?php echo esc_html($copy); ?></p></section>
            <?php if ($actions): ?><section class="tng-native-actions"><?php foreach ($actions as $action): $url = is_string($action[2]) && str_starts_with($action[2], 'http') ? $action[2] : home_url($action[2]); ?><a href="<?php echo esc_url($url); ?>"><span><?php echo esc_html($action[0]); ?></span><strong><?php echo esc_html($action[1]); ?></strong><small>Open</small></a><?php endforeach; ?></section><?php endif; ?>
            <section class="tng-native-placeholder"><span>TN</span><div><h2>This screen is connected to the new app shell.</h2><p>Its full feature interface will be built here without Traveler templates or shortcodes.</p></div></section>
        </main>
        <?php return (string) ob_get_clean();
    }
}

App_Router::boot();
