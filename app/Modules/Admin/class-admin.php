<?php
namespace TNG_OS\Modules\Admin;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Post;

if (!defined('ABSPATH')) exit;

final class Admin implements Module_Interface {
    private Container $container;

    public function id(): string { return 'admin'; }

    public function register(Container $container): void {
        $this->container = $container;

        add_action('admin_menu', [$this, 'menu'], 1);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_bar_menu', [$this, 'admin_bar'], 85);

        add_action('wp_ajax_tng_os_dismiss_notice', [$this, 'dismiss_notice']);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_menu_page(
            'TN Game OS',
            'TN Game OS',
            'edit_posts',
            'tn-game-os',
            [$this, 'dashboard'],
            'dashicons-location-alt',
            58
        );

        add_submenu_page('tn-game-os', 'Dashboard', 'Dashboard', 'edit_posts', 'tn-game-os', [$this, 'dashboard']);
        add_submenu_page('tn-game-os', 'Content Workspace', 'Content', 'edit_posts', 'tng-os-workspace-content', [$this, 'content_workspace']);
        add_submenu_page('tn-game-os', 'Destinations Workspace', 'Destinations', 'edit_posts', 'tng-os-workspace-destinations', [$this, 'destinations_workspace']);
        add_submenu_page('tn-game-os', 'Explorer Workspace', 'Explorer', 'read', 'tng-os-workspace-explorer', [$this, 'explorer_workspace']);
        add_submenu_page('tn-game-os', 'System Workspace', 'System', 'manage_options', 'tng-os-workspace-system', [$this, 'system_workspace']);
        add_submenu_page('tn-game-os', 'Developer Workspace', 'Developer', 'manage_options', 'tng-os-workspace-developer', [$this, 'developer_workspace']);

        /*
         * Only pages owned by this module are registered here. Legacy Content
         * Manager pages register their own slugs once, preventing WordPress from
         * attaching two callbacks to the same admin-page hook.
         */
        add_submenu_page('tn-game-os', 'OS Settings', 'OS Settings', 'manage_options', 'tng-os-settings', [$this, 'settings']);
        add_submenu_page('tn-game-os', 'Asset Library', 'Asset Library', 'upload_files', 'tng-os-assets', [$this, 'assets_page']);
        add_submenu_page('tn-game-os', 'Destination Builder', 'Destination Builder', 'edit_posts', 'tng-os-destinations', [$this, 'destinations_page']);
        add_submenu_page('tn-game-os', 'Recommendations Widget', 'Recommendations Widget', 'edit_pages', 'tng-os-recommendations', [$this, 'recommendations_page']);
        add_submenu_page('tn-game-os', 'Destinations Widget', 'Destinations Widget', 'edit_pages', 'tng-os-destinations-widget', [$this, 'destinations_widget_page']);
        add_submenu_page('tn-game-os', 'System Status', 'System Status', 'manage_options', 'tng-os-status', [$this, 'status']);
    }

    public function cleanup(): void {
        /*
         * Compatibility method retained for older hooks.
         *
         * Do not remove menu or submenu registrations here. WordPress uses
         * those registrations when validating admin-page access. Sidebar
         * cleanup is performed visually in os-admin.js instead.
         */
    }

    public function admin_bar($bar): void {
        if (!is_admin() || !current_user_can('edit_posts')) return;

        $bar->add_node([
            'id' => 'tng-os-command',
            'title' => '<span class="ab-icon dashicons dashicons-search"></span><span class="ab-label">TN Game Search</span>',
            'href' => admin_url('admin.php?page=tn-game-os'),
            'meta' => ['class' => 'tng-os-command-trigger'],
        ]);
    }

    public function assets(string $hook): void {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $is_os = (
            strpos($hook, 'tn-game') !== false ||
            strpos($hook, 'tng-os') !== false ||
            strpos($page, 'tn-game') === 0 ||
            strpos($page, 'tng-os') === 0
        );

        if (!$is_os) return;

        wp_enqueue_style('tng-os-admin', TNG_OS_URL . 'assets/admin/os-admin.css', [], TNG_OS_VERSION);
        wp_enqueue_script('tng-os-admin', TNG_OS_URL . 'assets/admin/os-admin.js', ['jquery'], TNG_OS_VERSION, true);

        wp_localize_script('tng-os-admin', 'TNG_OS_ADMIN', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'googleNonce' => wp_create_nonce('tng_os_test_google'),
            'mapboxNonce' => wp_create_nonce('tng_os_test_mapbox'),
            'dismissNonce' => wp_create_nonce('tng_os_dismiss_notice'),
            'commands' => $this->commands(),
            'isAdmin' => current_user_can('manage_options'),
            'shortcut' => wp_is_mobile() ? 'Search tools' : '⌘K / Ctrl+K',
            'visibleMenuPages' => [
                'tn-game-os',
                'tng-os-workspace-content',
                'tng-os-workspace-destinations',
                'tng-os-workspace-explorer',
                'tng-os-workspace-system',
                'tng-os-workspace-developer',
            ],
            'legacyParentSlugs' => ['tn-game-core'],
            'focusMenuDefault' => true,
            'focusMenuStorageKey' => 'tng_os_focus_sidebar',
        ]);
    }

    private function commands(): array {
        $commands = [];

        foreach ($this->workspace_definitions() as $workspace) {
            if (!current_user_can($workspace['capability'])) continue;

            $commands[] = [
                'title' => $workspace['label'] . ' Workspace',
                'description' => $workspace['description'],
                'group' => 'Workspaces',
                'icon' => $workspace['icon'],
                'url' => admin_url('admin.php?page=' . $workspace['slug']),
                'keywords' => implode(' ', $workspace['keywords']),
            ];
        }

        foreach ($this->all_tools() as $tool) {
            if (!current_user_can($tool['capability'])) continue;

            $commands[] = [
                'title' => $tool['label'],
                'description' => $tool['description'],
                'group' => $tool['workspace'],
                'icon' => $tool['icon'],
                'url' => $this->tool_url($tool),
                'keywords' => implode(' ', $tool['keywords'] ?? []),
            ];
        }

        $unique = [];
        foreach ($commands as $command) {
            $key = (string)($command['url'] ?? $command['title']);
            if (!isset($unique[$key])) $unique[$key] = $command;
        }

        return array_values($unique);
    }

    private function workspace_definitions(): array {
        return [
            'content' => [
                'slug' => 'tng-os-workspace-content',
                'label' => 'Content',
                'description' => 'Create, import, organize, duplicate, and reuse tourism content.',
                'icon' => 'dashicons-edit-page',
                'capability' => 'edit_posts',
                'keywords' => ['create', 'import', 'assets', 'listing', 'restaurant', 'trail'],
            ],
            'destinations' => [
                'slug' => 'tng-os-workspace-destinations',
                'label' => 'Destinations',
                'description' => 'Manage destination records, maps, recommendations, alerts, and nearby discovery.',
                'icon' => 'dashicons-location-alt',
                'capability' => 'edit_posts',
                'keywords' => ['town', 'city', 'map', 'nearby', 'alert', 'recommendations'],
            ],
            'explorer' => [
                'slug' => 'tng-os-workspace-explorer',
                'label' => 'Explorer',
                'description' => 'Player progress, XP, photos, achievements, trip planning, and leaderboards.',
                'icon' => 'dashicons-awards',
                'capability' => 'read',
                'keywords' => ['xp', 'passport', 'leaderboard', 'photo', 'gamipress', 'trip'],
            ],
            'system' => [
                'slug' => 'tng-os-workspace-system',
                'label' => 'System',
                'description' => 'Integrations, APIs, content sources, diagnostics, assets, and settings.',
                'icon' => 'dashicons-admin-generic',
                'capability' => 'manage_options',
                'keywords' => ['settings', 'api', 'google', 'mapbox', 'database', 'diagnostics'],
            ],
            'developer' => [
                'slug' => 'tng-os-workspace-developer',
                'label' => 'Developer',
                'description' => 'Simulation, map editing, audits, repair utilities, and advanced testing.',
                'icon' => 'dashicons-hammer',
                'capability' => 'manage_options',
                'keywords' => ['simulator', 'audit', 'repair', 'database', 'developer', 'map editor'],
            ],
        ];
    }

    private function all_tools(): array {
        $tools = [
            [
                'label' => 'AI Content Manager',
                'description' => 'Turn natural-language requests into safe, reviewable content actions.',
                'workspace' => 'Content',
                'icon' => 'dashicons-superhero-alt',
                'capability' => 'edit_posts',
                'page' => 'tng-ai-content-manager',
                'keywords' => ['ai', 'assistant', 'audit', 'plan', 'content manager', 'natural language'],
            ],
            [
                'label' => 'Content Wizard',
                'description' => 'Create a new listing using the guided workflow.',
                'workspace' => 'Content',
                'icon' => 'dashicons-welcome-write-blog',
                'capability' => 'edit_posts',
                'page' => 'tn-game-content-wizard',
                'keywords' => ['new', 'create', 'wizard'],
            ],
            [
                'label' => 'Content Manager',
                'description' => 'Browse services and manage content workflows.',
                'workspace' => 'Content',
                'icon' => 'dashicons-screenoptions',
                'capability' => 'edit_posts',
                'page' => 'tn-game-content-dashboard',
                'keywords' => ['dashboard', 'services', 'listings'],
            ],
            [
                'label' => 'Quick Duplicate',
                'description' => 'Clone an existing listing and choose what carries over.',
                'workspace' => 'Content',
                'icon' => 'dashicons-admin-page',
                'capability' => 'edit_posts',
                'page' => 'tn-game-quick-duplicate',
                'keywords' => ['copy', 'clone'],
            ],
            [
                'label' => 'Service Tag Manager',
                'description' => 'Bulk-tag existing Activities for Trails, Waterfalls, Food, Events, and Discovery Search.',
                'workspace' => 'Content',
                'icon' => 'dashicons-tag',
                'capability' => 'edit_posts',
                'page' => 'tng-service-tag-manager',
                'keywords' => ['tags', 'taxonomy', 'trails', 'waterfalls', 'bulk'],
            ],
            [
                'label' => 'Concert Intelligence',
                'description' => 'Sync Tixr venue feeds, review events, publish concerts and generate trip pages.',
                'workspace' => 'Content',
                'icon' => 'dashicons-tickets-alt',
                'capability' => 'edit_posts',
                'page' => 'tng-concert-intelligence',
                'keywords' => ['concert', 'tixr', 'event', 'venue', 'import'],
            ],
            [
                'label' => 'Concert Trip Pages',
                'description' => 'Turn concert Activities into complete destination itineraries with nearby places.',
                'workspace' => 'Content',
                'icon' => 'dashicons-tickets-alt',
                'capability' => 'edit_posts',
                'page' => 'tng-concert-trip-pages',
                'keywords' => ['concert', 'trip', 'itinerary', 'venue', 'event'],
            ],
            [
                'label' => 'Import Center',
                'description' => 'Google Places, concert feeds, and source imports.',
                'workspace' => 'Content',
                'icon' => 'dashicons-download',
                'capability' => 'manage_options',
                'page' => 'tn-game-import-center',
                'keywords' => ['google', 'feed', 'sync'],
            ],
            [
                'label' => 'Asset Library',
                'description' => 'Photos, GPX routes, parks, venues, badges, and shared assets.',
                'workspace' => 'Content',
                'icon' => 'dashicons-images-alt2',
                'capability' => 'upload_files',
                'page' => 'tn-game-asset-library',
                'keywords' => ['image', 'gpx', 'media', 'route'],
            ],
            [
                'label' => 'Destinations',
                'description' => 'Manage towns, parks, districts, and destination pages.',
                'workspace' => 'Destinations',
                'icon' => 'dashicons-location',
                'capability' => 'edit_posts',
                'url' => admin_url('edit.php?post_type=tng_destination'),
                'keywords' => ['city', 'town', 'park'],
            ],
            [
                'label' => 'Build Destination',
                'description' => 'Create a new first-class destination.',
                'workspace' => 'Destinations',
                'icon' => 'dashicons-plus-alt2',
                'capability' => 'edit_posts',
                'url' => admin_url('post-new.php?post_type=tng_destination'),
                'keywords' => ['new', 'create'],
            ],
            [
                'label' => 'Local Alerts',
                'description' => 'Closures, warnings, advisories, and local notices.',
                'workspace' => 'Destinations',
                'icon' => 'dashicons-warning',
                'capability' => 'edit_posts',
                'url' => admin_url('edit.php?post_type=tng_local_alert'),
                'keywords' => ['closure', 'warning'],
            ],
            [
                'label' => 'Recommendations Widget',
                'description' => 'Homepage recommendation shortcode and placement guide.',
                'workspace' => 'Destinations',
                'icon' => 'dashicons-star-filled',
                'capability' => 'edit_pages',
                'page' => 'tng-os-recommendations',
                'keywords' => ['homepage', 'shortcode'],
            ],
            [
                'label' => 'Destinations Widget',
                'description' => 'Homepage destination shortcode and placement guide.',
                'workspace' => 'Destinations',
                'icon' => 'dashicons-grid-view',
                'capability' => 'edit_pages',
                'page' => 'tng-os-destinations-widget',
                'keywords' => ['homepage', 'shortcode'],
            ],
            [
                'label' => 'Photo Library',
                'description' => 'Review and manage player-submitted photos.',
                'workspace' => 'Explorer',
                'icon' => 'dashicons-camera',
                'capability' => 'manage_options',
                'page' => 'tn-game-photo-library',
                'keywords' => ['approval', 'gallery'],
            ],
            [
                'label' => 'Achievements',
                'description' => 'Manage GamiPress achievements.',
                'workspace' => 'Explorer',
                'icon' => 'dashicons-awards',
                'capability' => 'edit_posts',
                'url' => admin_url('edit.php?post_type=achievement-type'),
                'keywords' => ['badge', 'reward'],
            ],
            [
                'label' => 'Ranks',
                'description' => 'Manage player ranks and levels.',
                'workspace' => 'Explorer',
                'icon' => 'dashicons-chart-line',
                'capability' => 'edit_posts',
                'url' => admin_url('edit.php?post_type=rank-type'),
                'keywords' => ['level', 'progress'],
            ],
            [
                'label' => 'GamiPress Audit',
                'description' => 'Inspect XP types, achievements, and integration health.',
                'workspace' => 'Explorer',
                'icon' => 'dashicons-search',
                'capability' => 'manage_options',
                'page' => 'tn-game-gamipress-audit',
                'keywords' => ['xp', 'health'],
            ],
            [
                'label' => 'OS Settings',
                'description' => 'Central API keys, Mapbox, Google Places, and Explorer defaults.',
                'workspace' => 'System',
                'icon' => 'dashicons-admin-settings',
                'capability' => 'manage_options',
                'page' => 'tng-os-settings',
                'keywords' => ['google', 'mapbox', 'api'],
            ],
            [
                'label' => 'Content Sources',
                'description' => 'External source providers and synchronization records.',
                'workspace' => 'System',
                'icon' => 'dashicons-rss',
                'capability' => 'edit_posts',
                'page' => 'tng-os-content-sources',
                'keywords' => ['google places', 'sync'],
            ],
            [
                'label' => 'Runtime Audit',
                'description' => 'Detect duplicate callbacks and admin page registrations in the live site.',
                'workspace' => 'System',
                'icon' => 'dashicons-yes-alt',
                'capability' => 'manage_options',
                'page' => 'tng-os-runtime-audit',
                'keywords' => ['duplicate', 'hook', 'callback', 'menu', 'audit'],
            ],
            [
                'label' => 'System Status',
                'description' => 'Compatibility and integration readiness checks.',
                'workspace' => 'System',
                'icon' => 'dashicons-yes-alt',
                'capability' => 'manage_options',
                'page' => 'tng-os-status',
                'keywords' => ['health', 'diagnostics'],
            ],
            [
                'label' => 'Core Settings',
                'description' => 'Legacy game, progression, and trail settings.',
                'workspace' => 'System',
                'icon' => 'dashicons-admin-tools',
                'capability' => 'manage_options',
                'page' => 'tn-game-core-settings',
                'keywords' => ['legacy', 'progression'],
            ],
            [
                'label' => 'Database Health',
                'description' => 'Inspect database tables, metadata, and consistency.',
                'workspace' => 'System',
                'icon' => 'dashicons-database',
                'capability' => 'manage_options',
                'page' => 'tn-game-database-health',
                'keywords' => ['database', 'metadata'],
            ],
            [
                'label' => 'Gallery Repair',
                'description' => 'Repair malformed Traveler galleries and attachment IDs.',
                'workspace' => 'System',
                'icon' => 'dashicons-format-gallery',
                'capability' => 'manage_options',
                'page' => 'tn-game-gallery-repair',
                'keywords' => ['image', 'fix'],
            ],
            [
                'label' => 'Developer Mode',
                'description' => 'Developer-mode defaults and administrator testing controls.',
                'workspace' => 'Developer',
                'icon' => 'dashicons-admin-tools',
                'capability' => 'manage_options',
                'page' => 'tn-game-developer-mode',
                'keywords' => ['testing', 'admin'],
            ],
            [
                'label' => 'Route Simulator',
                'description' => 'Simulate route movement, check-ins, and odometer updates.',
                'workspace' => 'Developer',
                'icon' => 'dashicons-controls-play',
                'capability' => 'manage_options',
                'page' => 'tn-game-route-simulator',
                'keywords' => ['gps', 'odometer', 'trail'],
            ],
            [
                'label' => 'Developer Map',
                'description' => 'Edit destination and Top Sight map relationships.',
                'workspace' => 'Developer',
                'icon' => 'dashicons-location-alt',
                'capability' => 'manage_options',
                'page' => 'tn-game-core-developer-map',
                'keywords' => ['editor', 'top sight'],
            ],
            [
                'label' => 'Trail Audit',
                'description' => 'Inspect trail records, routes, statistics, and checkpoints.',
                'workspace' => 'Developer',
                'icon' => 'dashicons-chart-area',
                'capability' => 'manage_options',
                'page' => 'tn-game-trail-audit',
                'keywords' => ['gpx', 'checkpoints'],
            ],
            [
                'label' => 'Top Sight Audit',
                'description' => 'Inspect Top Sight coordinates, images, and relationships.',
                'workspace' => 'Developer',
                'icon' => 'dashicons-visibility',
                'capability' => 'manage_options',
                'page' => 'tn-game-top-sight-audit',
                'keywords' => ['checkpoint', 'coordinate'],
            ],
        ];

        $services = $this->container->get('services');
        if ($services && is_callable([$services, 'all'])) {
            foreach ($services->all() as $id => $service) {
                $tools[] = [
                    'label' => (string)$service['label'],
                    'description' => 'Manage ' . strtolower((string)$service['label']) . ' listings and service workflow.',
                    'workspace' => 'Content',
                    'icon' => (string)($service['icon'] ?? 'dashicons-admin-post'),
                    'capability' => 'edit_posts',
                    'page' => 'tn-game-service-' . $this->legacy_service_page_id((string)$id),
                    'keywords' => [(string)$id, 'service', 'listing'],
                    'service' => true,
                    'service_id' => $this->legacy_service_page_id((string)$id),
                ];
            }
        }

        return $tools;
    }

    private function legacy_service_page_id(string $service_id): string {
        $aliases = [
            'trails' => 'trail',
            'trail' => 'trail',
            'food' => 'food',
            'food-and-drink' => 'food',
            'concerts' => 'concert',
            'concert' => 'concert',
            'shops' => 'shop',
            'shop' => 'shop',
            'history' => 'history',
            'historic-sites' => 'history',
            'waterfalls' => 'waterfall',
            'waterfall' => 'waterfall',
            'campgrounds' => 'campground',
            'campground' => 'campground',
            'lodging' => 'lodging',
            'events' => 'event',
            'event' => 'event',
            'scenic' => 'scenic',
            'scenic-views' => 'scenic',
        ];

        $service_id = sanitize_key($service_id);
        return $aliases[$service_id] ?? $service_id;
    }

    private function tool_url(array $tool): string {
        if (!empty($tool['url'])) return (string)$tool['url'];
        return admin_url('admin.php?page=' . $tool['page']);
    }

    public function dashboard(): void {
        $metrics = $this->metrics();
        $notifications = $this->notifications();
        $recent = $this->recent_items(8);
        ?>
        <div class="wrap tng-os-wrap tng-os-app">
            <header class="tng-os-hero tng-os-app-hero">
                <div>
                    <span>TOURISM OPERATING SYSTEM</span>
                    <h1>TN Game OS</h1>
                    <p>Build, operate, and grow the destination platform from one workspace.</p>
                </div>
                <div class="tng-os-hero-actions">
                    <button type="button" class="button tng-os-open-command"><span class="dashicons dashicons-search"></span> Search tools <kbd>⌘K</kbd></button>
                    <?php if (current_user_can('edit_posts')): ?>
                        <a class="button button-primary button-hero" href="<?php echo esc_url(admin_url('admin.php?page=tn-game-content-wizard')); ?>">Create Content</a>
                    <?php endif; ?>
                </div>
            </header>

            <section class="tng-os-metrics">
                <?php foreach ($metrics as $label => $value): ?>
                    <article><strong><?php echo esc_html(number_format_i18n($value)); ?></strong><span><?php echo esc_html($label); ?></span></article>
                <?php endforeach; ?>
            </section>

            <?php if ($notifications): ?>
                <section class="tng-os-panel tng-os-notifications">
                    <div class="tng-os-section-heading"><div><span>NEEDS ATTENTION</span><h2>Notifications</h2></div><strong><?php echo count($notifications); ?></strong></div>
                    <div class="tng-os-notification-list">
                        <?php foreach ($notifications as $notice): ?>
                            <article data-notice-id="<?php echo esc_attr($notice['id']); ?>">
                                <span class="dashicons <?php echo esc_attr($notice['icon']); ?>"></span>
                                <div><strong><?php echo esc_html($notice['title']); ?></strong><p><?php echo esc_html($notice['description']); ?></p></div>
                                <?php if (!empty($notice['url'])): ?><a class="button" href="<?php echo esc_url($notice['url']); ?>">Open</a><?php endif; ?>
                                <button type="button" class="tng-os-dismiss-notice" aria-label="Dismiss">×</button>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="tng-os-panel">
                <div class="tng-os-section-heading"><div><span>WORKSPACES</span><h2>Choose where to work</h2></div></div>
                <div class="tng-os-workspace-grid">
                    <?php foreach ($this->workspace_definitions() as $workspace): if (!current_user_can($workspace['capability'])) continue; ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . $workspace['slug'])); ?>">
                            <span class="dashicons <?php echo esc_attr($workspace['icon']); ?>"></span>
                            <div><strong><?php echo esc_html($workspace['label']); ?></strong><p><?php echo esc_html($workspace['description']); ?></p></div>
                            <i>→</i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="tng-os-dashboard-columns">
                <section class="tng-os-panel">
                    <div class="tng-os-section-heading"><div><span>RECENT</span><h2>Recently edited</h2></div></div>
                    <?php echo $this->recent_items_html($recent); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </section>

                <section class="tng-os-panel">
                    <div class="tng-os-section-heading"><div><span>QUICK START</span><h2>Common actions</h2></div></div>
                    <div class="tng-os-quick-actions">
                        <?php foreach (array_slice(array_filter($this->all_tools(), static fn($tool) => empty($tool['service'])), 0, 8) as $tool): if (!current_user_can($tool['capability'])) continue; ?>
                            <a href="<?php echo esc_url($this->tool_url($tool)); ?>"><span class="dashicons <?php echo esc_attr($tool['icon']); ?>"></span><strong><?php echo esc_html($tool['label']); ?></strong></a>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        </div>
        <?php
        $this->command_palette();
    }

    private function metrics(): array {
        $activity = post_type_exists('st_activity') ? wp_count_posts('st_activity') : null;
        $destination = post_type_exists('tng_destination') ? wp_count_posts('tng_destination') : null;
        $asset = post_type_exists('tng_asset') ? wp_count_posts('tng_asset') : null;

        return [
            'Published Listings' => (int)($activity->publish ?? 0),
            'Draft Listings' => (int)($activity->draft ?? 0),
            'Destinations' => (int)($destination->publish ?? 0),
            'Reusable Assets' => (int)($asset->publish ?? 0),
            'Registered Players' => (int)(count_users()['total_users'] ?? 0),
        ];
    }

    private function notifications(): array {
        $dismissed = (array)get_user_meta(get_current_user_id(), 'tng_os_dismissed_notices', true);
        $notices = [];

        if (current_user_can('manage_options')) {
            if (!$this->container->get('settings')->get('google_places_key')) {
                $notices[] = [
                    'id' => 'google-key',
                    'title' => 'Google Places is not configured',
                    'description' => 'Add an API key before importing restaurant and place details.',
                    'icon' => 'dashicons-google',
                    'url' => admin_url('admin.php?page=tng-os-settings'),
                ];
            }

            if (!$this->container->get('settings')->get('mapbox_token')) {
                $notices[] = [
                    'id' => 'mapbox-token',
                    'title' => 'Mapbox is not configured',
                    'description' => 'Maps and destination tools need an access token.',
                    'icon' => 'dashicons-location-alt',
                    'url' => admin_url('admin.php?page=tng-os-settings'),
                ];
            }

            $missing_coordinates = new \WP_Query([
                'post_type' => 'tng_destination',
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_query' => [
                    'relation' => 'OR',
                    ['key' => '_tng_destination_lat', 'compare' => 'NOT EXISTS'],
                    ['key' => '_tng_destination_lat', 'value' => '', 'compare' => '='],
                    ['key' => '_tng_destination_lng', 'compare' => 'NOT EXISTS'],
                    ['key' => '_tng_destination_lng', 'value' => '', 'compare' => '='],
                ],
            ]);

            if ($missing_coordinates->found_posts) {
                $notices[] = [
                    'id' => 'destination-coordinates',
                    'title' => number_format_i18n($missing_coordinates->found_posts) . ' destinations need coordinates',
                    'description' => 'Near Me and destination maps require latitude and longitude.',
                    'icon' => 'dashicons-location',
                    'url' => admin_url('edit.php?post_type=tng_destination'),
                ];
            }

            $updates = get_site_transient('update_plugins');
            $update_count = is_object($updates) && !empty($updates->response) ? count($updates->response) : 0;
            if ($update_count) {
                $notices[] = [
                    'id' => 'plugin-updates',
                    'title' => number_format_i18n($update_count) . ' plugin updates available',
                    'description' => 'Review updates and test them before applying to production.',
                    'icon' => 'dashicons-update',
                    'url' => admin_url('plugins.php'),
                ];
            }
        }

        $pending_comments = wp_count_comments()->moderated ?? 0;
        if ($pending_comments && current_user_can('moderate_comments')) {
            $notices[] = [
                'id' => 'pending-comments',
                'title' => number_format_i18n($pending_comments) . ' comments need review',
                'description' => 'Moderate pending community responses and reviews.',
                'icon' => 'dashicons-admin-comments',
                'url' => admin_url('edit-comments.php?comment_status=moderated'),
            ];
        }

        return array_values(array_filter(
            $notices,
            static fn(array $notice): bool => !in_array($notice['id'], $dismissed, true)
        ));
    }

    public function dismiss_notice(): void {
        if (!is_user_logged_in()) wp_send_json_error(['message' => 'Not signed in.'], 403);
        check_ajax_referer('tng_os_dismiss_notice', 'nonce');

        $id = sanitize_key($_POST['id'] ?? '');
        if (!$id) wp_send_json_error(['message' => 'Missing notice.'], 400);

        $dismissed = (array)get_user_meta(get_current_user_id(), 'tng_os_dismissed_notices', true);
        $dismissed[] = $id;
        update_user_meta(get_current_user_id(), 'tng_os_dismissed_notices', array_values(array_unique($dismissed)));

        wp_send_json_success();
    }

    private function recent_items(int $limit): array {
        $post_types = [
            'tng_destination',
            'st_activity',
            'st_hotel',
            'st_tours',
            'st_rental',
            'tng_local_alert',
            'tng_asset',
            'top_sight',
        ];

        $post_types = array_values(array_filter($post_types, 'post_type_exists'));
        if (!$post_types) return [];

        return get_posts([
            'post_type' => $post_types,
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => $limit,
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);
    }

    private function recent_items_html(array $items): string {
        ob_start();

        if (!$items) {
            echo '<div class="tng-os-empty">No recent content yet.</div>';
        } else {
            echo '<div class="tng-os-recent-list">';
            foreach ($items as $item) {
                $object = get_post_type_object($item->post_type);
                $edit = get_edit_post_link($item->ID);
                ?>
                <a href="<?php echo esc_url($edit ?: '#'); ?>">
                    <span class="dashicons dashicons-admin-post"></span>
                    <div><strong><?php echo esc_html($item->post_title ?: '(Untitled)'); ?></strong><small><?php echo esc_html($object ? $object->labels->singular_name : $item->post_type); ?> · <?php echo esc_html(human_time_diff(get_post_modified_time('U', true, $item), current_time('timestamp'))); ?> ago</small></div>
                    <i><?php echo esc_html(ucfirst($item->post_status)); ?></i>
                </a>
                <?php
            }
            echo '</div>';
        }

        return (string)ob_get_clean();
    }

    public function content_workspace(): void {
        $this->workspace_page('content', 'Content Workspace', 'Create, import, organize, and reuse every tourism listing.', 'edit_posts');
    }

    public function destinations_workspace(): void {
        $this->workspace_page('destinations', 'Destinations Workspace', 'Connect places, listings, maps, alerts, and discovery tools.', 'edit_posts');
    }

    public function explorer_workspace(): void {
        $this->workspace_page('explorer', 'Explorer Workspace', 'Operate player progress, photos, achievements, and trip planning.', 'read');
    }

    public function system_workspace(): void {
        $this->workspace_page('system', 'System Workspace', 'Configure APIs, integrations, diagnostics, and content infrastructure.', 'manage_options');
    }

    public function developer_workspace(): void {
        $this->workspace_page('developer', 'Developer Workspace', 'Test routes, edit maps, run audits, and repair data.', 'manage_options');
    }

    private function workspace_page(string $workspace, string $title, string $description, string $capability): void {
        if (!current_user_can($capability)) wp_die('You do not have permission to access this workspace.');

        $all_tools = $this->all_tools();
        $tools = array_values(array_filter(
            $all_tools,
            static fn(array $tool): bool => strtolower($tool['workspace']) === $workspace
        ));

        /*
         * Service cards already belong to the Content workspace. Earlier
         * versions prepended them a second time, which caused duplicates.
         * Deduplicate every workspace by its final URL.
         */
        $deduped = [];
        foreach ($tools as $tool) {
            $key = $this->tool_url($tool);
            if (!isset($deduped[$key])) $deduped[$key] = $tool;
        }
        $tools = array_values($deduped);

        ?>
        <div class="wrap tng-os-wrap tng-os-app">
            <header class="tng-os-page-heading tng-os-workspace-header">
                <div><span>TN GAME OS WORKSPACE</span><h1><?php echo esc_html($title); ?></h1><p><?php echo esc_html($description); ?></p></div>
                <button type="button" class="button tng-os-open-command"><span class="dashicons dashicons-search"></span> Search all tools</button>
            </header>

            <section class="tng-os-panel">
                <div class="tng-os-tool-grid">
                    <?php foreach ($tools as $tool): if (!current_user_can($tool['capability'])) continue; ?>
                        <a href="<?php echo esc_url($this->tool_url($tool)); ?>">
                            <span class="dashicons <?php echo esc_attr($tool['icon']); ?>"></span>
                            <div><strong><?php echo esc_html($tool['label']); ?></strong><p><?php echo esc_html($tool['description']); ?></p></div>
                            <i>→</i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php if ($workspace === 'content'): ?>
                <section class="tng-os-panel">
                    <div class="tng-os-section-heading"><div><span>RECENT CONTENT</span><h2>Continue working</h2></div></div>
                    <?php echo $this->recent_items_html($this->recent_items(12)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </section>
            <?php elseif ($workspace === 'system'): ?>
                <?php $this->inline_status(); ?>
            <?php elseif ($workspace === 'developer'): ?>
                <section class="tng-os-panel tng-os-warning-panel"><h2>Developer tools</h2><p>These utilities can modify testing state or repair stored data. Use them on backups and review results before running bulk operations.</p></section>
            <?php endif; ?>
        </div>
        <?php
        $this->command_palette();
    }

    private function command_palette(): void {
        ?>
        <div class="tng-os-command-palette" data-tng-command-palette hidden>
            <div class="tng-os-command-backdrop" data-tng-command-close></div>
            <section role="dialog" aria-modal="true" aria-label="TN Game OS command palette">
                <header><span class="dashicons dashicons-search"></span><input type="search" placeholder="Search tools, services, and actions…" autocomplete="off" data-tng-command-input><kbd>ESC</kbd></header>
                <div class="tng-os-command-results" data-tng-command-results></div>
                <footer><span>↑ ↓ Navigate</span><span>Enter Open</span><span>⌘K / Ctrl+K Toggle</span></footer>
            </section>
        </div>
        <?php
    }

    public function settings(): void {
        $s = $this->container->get('settings')->all();
        ?>
        <div class="wrap tng-os-wrap"><header class="tng-os-page-heading"><div><span>CENTRAL CONFIGURATION</span><h1>OS Settings</h1><p>Google Places, Mapbox, Explorer rewards, and platform defaults.</p></div></header>
        <?php if (!empty($_GET['updated'])): ?><div class="notice notice-success"><p>Settings saved.</p></div><?php endif; ?>
        <form class="tng-os-settings" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="tng_os_save_settings"><?php wp_nonce_field('tng_os_save_settings'); ?>
            <h2>General</h2><?php $this->field('site_name', 'Platform name', $s['site_name']); ?>
            <h2>Google Places</h2><?php $this->field('google_places_key', 'Google Places API key', $s['google_places_key'], 'password'); ?><?php $this->field('google_test_place_id', 'Test Place ID', $s['google_test_place_id']); ?><p><button type="button" class="button" data-test-google>Test Google Places</button> <span data-google-result></span></p>
            <h2>Mapbox</h2><?php $this->field('mapbox_token', 'Mapbox access token', $s['mapbox_token'], 'password'); ?><?php $this->field('mapbox_style', 'Mapbox style URL', $s['mapbox_style']); ?><p><button type="button" class="button" data-test-mapbox>Test Mapbox</button> <span data-mapbox-result></span></p>
            <h2>Explorer Defaults</h2><?php $this->field('default_checkin_xp', 'Default check-in XP', $s['default_checkin_xp'], 'number'); ?><?php $this->field('default_photo_xp', 'Default photo XP', $s['default_photo_xp'], 'number'); ?><?php $this->field('default_radius', 'Default radius in feet', $s['default_radius'], 'number'); ?><?php $this->field('mileage_interval', 'Mileage reward interval', $s['mileage_interval'], 'number', '0.1'); ?><?php $this->field('mileage_xp', 'Mileage reward XP', $s['mileage_xp'], 'number'); ?>
            <?php submit_button('Save OS Settings'); ?>
        </form></div>
        <?php
    }

    private function field(string $key, string $label, $value, string $type = 'text', string $step = '1'): void {
        echo '<label class="tng-os-field"><span>' . esc_html($label) . '</span><input type="' . esc_attr($type) . '" ' . ($type === 'number' ? 'step="' . esc_attr($step) . '"' : '') . ' name="settings[' . esc_attr($key) . ']" id="tng-os-' . esc_attr($key) . '" value="' . esc_attr($value) . '"></label>';
    }

    public function content_wizard(): void { $this->container->get('legacy_content_manager')->wizard_page(); }
    public function content_dashboard(): void { $this->container->get('legacy_content_manager')->dashboard_page(); }

    public function assets_page(): void {
        ?>
        <div class="wrap tng-os-wrap"><header class="tng-os-page-heading"><div><span>REUSABLE CONTENT</span><h1>Asset Library</h1><p>GPX routes, venues, parks, photos, badges, and locations.</p></div><a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=tng_asset')); ?>">Add Asset</a></header><p><a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=tng_asset')); ?>">Browse Assets</a></p></div>
        <?php
    }

    public function destinations_page(): void {
        ?>
        <div class="wrap tng-os-wrap"><header class="tng-os-page-heading"><div><span>CONNECTED EXPERIENCES</span><h1>Destination Builder</h1><p>Create parks, towns, districts, regions, and collections.</p></div><a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=tng_destination')); ?>">Build Destination</a></header><p><a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=tng_destination')); ?>">Manage Destinations</a></p></div>
        <?php
    }

    public function destinations_widget_page(): void {
        ?>
        <div class="wrap tng-os-wrap"><header class="tng-os-page-heading"><div><span>HOMEPAGE COMPONENT</span><h1>TN Game Destinations</h1><p>Use a service-aware TN Game destination widget.</p></div></header><section class="tng-os-panel"><h2>Placement</h2><ol><li>Edit the homepage.</li><li>Remove Traveler's Top destinations element.</li><li>Add a Shortcode element with <code>[tng_destinations]</code>.</li><li>Update and purge caches.</li></ol></section><section class="tng-os-panel"><h2>Examples</h2><p><code>[tng_destinations limit="6" columns="3"]</code></p></section></div>
        <?php
    }

    public function recommendations_page(): void {
        ?>
        <div class="wrap tng-os-wrap"><header class="tng-os-page-heading"><div><span>HOMEPAGE COMPONENT</span><h1>TN Game Recommendations</h1><p>Unified recommendations controlled by TN Game OS.</p></div></header><section class="tng-os-panel"><h2>Shortcode</h2><p><code>[tng_recommendations]</code></p><p><code>[tng_recommendations heading="Explore Tennessee South Cumberland"]</code></p></section></div>
        <?php
    }

    private function inline_status(): void {
        $checks = $this->status_checks();
        ?>
        <section class="tng-os-panel"><div class="tng-os-section-heading"><div><span>READINESS</span><h2>System status</h2></div></div><section class="tng-os-status">
            <?php foreach ($checks as $label => $ok): ?><article><i class="<?php echo $ok ? 'is-good' : 'is-warn'; ?>"></i><strong><?php echo esc_html($label); ?></strong><span><?php echo $ok ? 'Ready' : 'Needs attention'; ?></span></article><?php endforeach; ?>
        </section></section>
        <?php
    }

    private function status_checks(): array {
        return [
            'Traveler Activities' => post_type_exists('st_activity'),
            'GamiPress' => function_exists('gamipress_award_points_to_user'),
            'Asset Library' => post_type_exists('tng_asset'),
            'Destination Platform' => post_type_exists('tng_destination'),
            'Google Places Key' => (bool)$this->container->get('settings')->get('google_places_key'),
            'Mapbox Token' => (bool)$this->container->get('settings')->get('mapbox_token'),
        ];
    }

    public function status(): void {
        ?>
        <div class="wrap tng-os-wrap"><header class="tng-os-page-heading"><div><span>DIAGNOSTICS</span><h1>System Status</h1></div></header>
        <?php $this->inline_status(); ?>
        </div>
        <?php
    }
}
