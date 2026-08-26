<?php
namespace TNG_OS\Modules\Admin;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use TNG_OS\Platform\App_Router;

if (!defined('ABSPATH')) exit;

final class Production_Smoke_Tests implements Module_Interface {
    private const PAGE_SLUG = 'tng-production-smoke-tests';
    private const REPORT_OPTION = 'tng_production_smoke_report_v1';
    private const REQUIRED_ROUTES = ['explore','map','play','offline','trips','profile','adventure-ai','adventures','recaps','activity','trails','events','food','top-sights','destinations'];
    private const PRIVATE_ROUTES = ['trips','adventures','profile','recaps','activity'];

    public function id(): string { return 'production_smoke_tests'; }

    public function register(Container $container): void {
        $container->set('production_smoke_tests', $this);
        add_action('admin_menu', [$this, 'menu'], 31);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_tng_production_smoke_run', [$this, 'handle_run']);
        add_action('admin_post_tng_production_smoke_export', [$this, 'handle_export']);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page('tn-game-os','Production Smoke Tests','Smoke Tests','manage_options',self::PAGE_SLUG,[$this,'render']);
    }

    public function assets(): void {
        if (sanitize_key((string)($_GET['page'] ?? '')) !== self::PAGE_SLUG) return;
        wp_enqueue_style('tng-production-smoke-tests', TNG_OS_URL . 'assets/admin/production-smoke-tests.css', [], TNG_OS_VERSION);
    }

    public function handle_run(): void {
        if (!current_user_can('manage_options')) wp_die('You do not have permission to run production smoke tests.');
        check_admin_referer('tng_production_smoke_run');
        $report = $this->run();
        update_option(self::REPORT_OPTION, $report, false);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&ran=1'));
        exit;
    }

    public function handle_export(): void {
        if (!current_user_can('manage_options')) wp_die('You do not have permission to export production smoke tests.');
        check_admin_referer('tng_production_smoke_export');
        $report = get_option(self::REPORT_OPTION, []);
        if (!is_array($report) || !$report) $report = $this->run();
        nocache_headers();
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="tn-game-production-smoke-' . gmdate('Y-m-d-His') . '.json"');
        echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function render(): void {
        if (!current_user_can('manage_options')) return;
        $report = get_option(self::REPORT_OPTION, []);
        $has_report = is_array($report) && !empty($report['checks']);
        $summary = $has_report && is_array($report['summary'] ?? null) ? $report['summary'] : ['pass'=>0,'warn'=>0,'fail'=>0,'info'=>0];
        $score = $has_report ? (int)($report['score'] ?? 0) : 0;
        ?>
        <div class="wrap tng-smoke">
            <section class="tng-smoke__hero">
                <div><p class="tng-smoke__eyebrow">TN GAME OS <?php echo esc_html(TNG_OS_VERSION); ?></p><h1>Production Smoke Tests</h1><p>Run a read-only verification of the routes, privacy boundaries, offline endpoints, launch gate, and critical modules that keep The TN Game healthy.</p></div>
                <div class="tng-smoke__actions">
                    <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tng_production_smoke_run'), 'tng_production_smoke_run')); ?>"><?php echo $has_report ? 'Run tests again' : 'Run production tests'; ?></a>
                    <?php if ($has_report): ?><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tng_production_smoke_export'), 'tng_production_smoke_export')); ?>">Export JSON</a><?php endif; ?>
                </div>
            </section>

            <?php if (isset($_GET['ran'])): ?><div class="notice notice-success is-dismissible"><p>Production smoke tests completed.</p></div><?php endif; ?>

            <?php if (!$has_report): ?>
                <section class="tng-smoke__empty"><span>◇</span><h2>Ready for the first production scan</h2><p>No settings, content, XP, photos, trips, or Explorer data will be changed.</p></section>
            <?php else: ?>
                <section class="tng-smoke__summary">
                    <article class="tng-smoke__score"><span>Production confidence</span><strong><?php echo esc_html((string)$score); ?></strong><em>/ 100</em></article>
                    <?php foreach ([['pass','Passed'],['warn','Warnings'],['fail','Failed'],['info','Information']] as [$key,$label]): ?>
                        <article><span><?php echo esc_html($label); ?></span><strong><?php echo esc_html((string)($summary[$key] ?? 0)); ?></strong></article>
                    <?php endforeach; ?>
                </section>
                <div class="tng-smoke__meta">Last run <?php echo esc_html((string)($report['generated_at'] ?? '')); ?> · <?php echo esc_html((string)($report['duration_ms'] ?? 0)); ?> ms · <?php echo esc_html((string)($report['environment'] ?? 'WordPress')); ?></div>
                <section class="tng-smoke__panel">
                    <div class="tng-smoke__panel-head"><div><h2>Application contract</h2><p>Failures are launch blockers. Warnings need review but do not automatically mean the site is broken.</p></div></div>
                    <div class="tng-smoke__checks">
                        <?php foreach ($report['checks'] as $check): $status=sanitize_key((string)($check['status'] ?? 'info')); ?>
                            <article class="tng-smoke__check is-<?php echo esc_attr($status); ?>">
                                <span class="tng-smoke__badge"><?php echo esc_html($status); ?></span>
                                <div><h3><?php echo esc_html((string)($check['title'] ?? 'Check')); ?></h3><p><?php echo esc_html((string)($check['detail'] ?? '')); ?></p></div>
                                <small><?php echo esc_html((string)($check['area'] ?? 'Platform')); ?></small>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>
        <?php
    }

    private function run(): array {
        $started = microtime(true);
        $checks = [];
        $checks[] = $this->check('version','pass','TN Game OS release loaded','WordPress is running TN Game OS ' . TNG_OS_VERSION . '.','Release');

        $https = wp_parse_url(home_url('/'), PHP_URL_SCHEME) === 'https';
        $checks[] = $this->check('https',$https ? 'pass' : 'fail',$https ? 'HTTPS is active' : 'HTTPS is not active',$https ? 'App, location, install, and service-worker features have a secure origin.' : 'The home URL must use HTTPS for geolocation and Offline Mode.','Environment');

        $template = TNG_OS_PATH . 'templates/app-shell.php';
        $checks[] = $this->check('app-shell',is_readable($template) ? 'pass' : 'fail','Native app shell',is_readable($template) ? 'The shared app template is readable.' : 'templates/app-shell.php is missing or unreadable.','Routes');

        $routes = method_exists(App_Router::class, 'routes') ? App_Router::routes() : [];
        $missing_routes = array_values(array_diff(self::REQUIRED_ROUTES, $routes));
        $checks[] = $this->check('routes',$missing_routes ? 'fail' : 'pass','Required app routes',$missing_routes ? 'Missing routes: ' . implode(', ', $missing_routes) . '.' : count(self::REQUIRED_ROUTES) . ' launch-critical routes are registered.','Routes');

        $required_classes = [
            'Universal Map' => 'TNG_OS\\Platform\\Universal_Map_Registry',
            'Adventure AI' => 'TNG_OS\\Modules\\Destinations\\Adventure_AI',
            'AI Content Manager' => 'TNG_OS\\Modules\\Admin\\AI_Content_Manager',
            'Adventure Recaps' => 'TNG_Adventure_Recaps',
            'Offline Mode' => 'TNG_Offline_Mode',
        ];
        $missing_modules = [];
        foreach ($required_classes as $label => $class) if (!class_exists($class)) $missing_modules[] = $label;
        $checks[] = $this->check('modules',$missing_modules ? 'fail' : 'pass','Milestone modules',$missing_modules ? 'Not loaded: ' . implode(', ', $missing_modules) . '.' : 'Universal Map, Adventure AI, AI Admin, Recaps, and Offline Mode are loaded.','Modules');

        $assets = ['assets/css/ui-kit.css','assets/css/app-router.css','assets/css/offline-mode.css','assets/js/offline-mode.js','assets/css/adventure-recaps.css','assets/js/adventure-recaps.js'];
        $missing_assets = array_values(array_filter($assets, static fn(string $path): bool => !is_readable(TNG_OS_PATH . $path)));
        $checks[] = $this->check('assets',$missing_assets ? 'fail' : 'pass','Critical app assets',$missing_assets ? 'Missing: ' . implode(', ', $missing_assets) . '.' : count($assets) . ' critical CSS and JavaScript files are readable.','Assets');

        $rewrite_pending = (bool)get_option('tng_os_rewrite_flush_needed', false);
        $checks[] = $this->check('rewrites',$rewrite_pending ? 'warn' : 'pass','Rewrite rules',$rewrite_pending ? 'A rewrite refresh is pending; load one WordPress request and run the scan again.' : 'No TN Game rewrite refresh is pending.','Routes');

        $gate = class_exists('TNG_Launch_Gate') && \TNG_Launch_Gate::enabled();
        $checks[] = $this->check('launch-gate','info','Public launch gate',$gate ? 'Coming Soon is enabled. A public 503 response is expected during private build mode.' : 'The public TN Game application is live.','Launch');

        $checks[] = $this->offline_endpoint('manifest', home_url('/tn-game.webmanifest'), 'application/manifest+json', ['"name":"The TN Game"','"display":"standalone"']);
        $checks[] = $this->offline_endpoint('service-worker', home_url('/tn-game-sw.js'), 'application/javascript', ['const VERSION=','self.addEventListener(\'fetch\'']);

        $public = $this->request(home_url('/explore/?tng_smoke=1'));
        if (!empty($public['error'])) {
            $checks[] = $this->check('public-route','warn','Public Explore route','The server could not perform a loopback request: ' . $public['error'],'Routes');
        } else {
            $expected_code = $gate ? 503 : 200;
            $safe = strtolower((string)($public['headers']['x-tng-offline-safe'] ?? '')) === '1';
            $ok = (int)$public['code'] === $expected_code && $safe;
            $detail = sprintf('HTTP %d; offline-safe header %s; launch gate %s.', (int)$public['code'], $safe ? 'present' : 'missing', $gate ? 'enabled' : 'disabled');
            $checks[] = $this->check('public-route',$ok ? 'pass' : 'fail','Public Explore contract',$detail,'Routes');
        }

        foreach (self::PRIVATE_ROUTES as $route) {
            $response = $this->request(home_url('/' . $route . '/?tng_smoke=1'));
            if (!empty($response['error'])) {
                $checks[] = $this->check('private-' . $route,'warn',ucwords(str_replace('-',' ',$route)) . ' privacy boundary','Loopback request unavailable: ' . $response['error'],'Privacy');
                continue;
            }
            $safe = strtolower((string)($response['headers']['x-tng-offline-safe'] ?? '')) === '1';
            $checks[] = $this->check('private-' . $route,$safe ? 'fail' : 'pass',ucwords(str_replace('-',' ',$route)) . ' privacy boundary',$safe ? 'Private route incorrectly advertises offline page caching.' : 'Private route is not marked for anonymous page caching.','Privacy');
        }

        $counts = ['pass'=>0,'warn'=>0,'fail'=>0,'info'=>0];
        foreach ($checks as $check) if (isset($counts[$check['status']])) $counts[$check['status']]++;
        $score = max(0, 100 - ($counts['fail'] * 12) - ($counts['warn'] * 3));
        return [
            'version' => TNG_OS_VERSION,
            'generated_at' => wp_date('M j, Y g:i:s a T'),
            'generated_at_gmt' => gmdate('c'),
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            'environment' => sprintf('WordPress %s · PHP %s', get_bloginfo('version'), PHP_VERSION),
            'launch_gate_enabled' => $gate,
            'score' => $score,
            'summary' => $counts,
            'checks' => $checks,
        ];
    }

    private function offline_endpoint(string $id, string $url, string $content_type, array $needles): array {
        $response = $this->request($url);
        $title = $id === 'manifest' ? 'Installable app manifest' : 'Root service worker';
        if (!empty($response['error'])) return $this->check($id,'warn',$title,'Loopback request unavailable: ' . $response['error'],'Offline');
        $actual_type = strtolower((string)($response['headers']['content-type'] ?? ''));
        $body = (string)($response['body'] ?? '');
        $missing = array_values(array_filter($needles, static fn(string $needle): bool => strpos($body, $needle) === false));
        $ok = (int)$response['code'] === 200 && strpos($actual_type, strtolower($content_type)) !== false && !$missing;
        $detail = sprintf('HTTP %d · %s%s', (int)$response['code'], $actual_type ?: 'content type missing', $missing ? ' · response signature missing' : '');
        return $this->check($id,$ok ? 'pass' : 'fail',$title,$detail,'Offline');
    }

    private function request(string $url): array {
        $response = wp_safe_remote_get($url, ['timeout'=>12,'redirection'=>2,'user-agent'=>'TN-Game-OS-Smoke/' . TNG_OS_VERSION,'headers'=>['Cache-Control'=>'no-cache']]);
        if (is_wp_error($response)) return ['error'=>$response->get_error_message(),'code'=>0,'headers'=>[],'body'=>''];
        $headers = [];
        foreach (wp_remote_retrieve_headers($response) as $key => $value) $headers[strtolower((string)$key)] = is_array($value) ? implode(', ', $value) : (string)$value;
        return ['error'=>'','code'=>(int)wp_remote_retrieve_response_code($response),'headers'=>$headers,'body'=>(string)wp_remote_retrieve_body($response)];
    }

    private function check(string $id,string $status,string $title,string $detail,string $area): array {
        return compact('id','status','title','detail','area');
    }
}
