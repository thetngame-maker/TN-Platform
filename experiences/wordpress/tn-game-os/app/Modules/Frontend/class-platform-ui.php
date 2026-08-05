<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Platform_UI implements Module_Interface {
    private Container $container;

    public function id(): string { return 'platform_ui'; }

    public function register(Container $container): void {
        $this->container = $container;
        $container->set('platform_ui', $this);

        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 30);
        add_filter('body_class', [$this, 'body_classes']);
        add_action('wp_body_open', [$this, 'render_header'], 5);
        add_action('wp_footer', [$this, 'render_footer'], 30);
        add_action('wp_footer', [$this, 'render_navigation'], 40);
        add_shortcode('tng_explore_app', [$this, 'render_explore_shortcode']);
    }

    public function boot(Container $container): void {}

    public function is_preview(): bool {
        return current_user_can('manage_options') && isset($_GET['tng_ui_preview']) && '1' === sanitize_text_field(wp_unslash($_GET['tng_ui_preview']));
    }

    public function is_enabled(): bool {
        $enabled = (bool) get_option('tng_platform_ui_enabled', false) || $this->is_preview();
        return (bool) apply_filters('tng_platform_ui_enabled', $enabled);
    }

    public function replaces_theme_chrome(): bool {
        return $this->is_enabled() && ((bool) get_option('tng_platform_ui_replace_chrome', false) || $this->is_preview());
    }

    public function register_settings(): void {
        register_setting('tng_platform_ui', 'tng_platform_ui_enabled', ['type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false]);
        register_setting('tng_platform_ui', 'tng_platform_ui_replace_chrome', ['type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false]);
        register_setting('tng_platform_ui', 'tng_platform_ui_explore_page_id', ['type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0]);

        add_settings_section('tng_platform_ui_main', 'Platform UI rollout', function (): void {
            echo '<p>Enable the new TN Game interface progressively. Preview mode is available to administrators without changing the public site.</p>';
        }, 'tng-platform-ui');

        add_settings_field('tng_platform_ui_enabled', 'Enable Platform UI', [$this, 'checkbox_field'], 'tng-platform-ui', 'tng_platform_ui_main', ['option' => 'tng_platform_ui_enabled', 'description' => 'Loads the TN Game design system and app navigation publicly.']);
        add_settings_field('tng_platform_ui_replace_chrome', 'Replace Traveler header and footer', [$this, 'checkbox_field'], 'tng-platform-ui', 'tng_platform_ui_main', ['option' => 'tng_platform_ui_replace_chrome', 'description' => 'Hides known Traveler chrome and displays the TN Game header and footer.']);
        add_settings_field('tng_platform_ui_explore_page_id', 'Explore page', [$this, 'page_field'], 'tng-platform-ui', 'tng_platform_ui_main');
    }

    public function register_settings_page(): void {
        add_options_page('TN Game Platform UI', 'TN Game Platform UI', 'manage_options', 'tng-platform-ui', [$this, 'render_settings_page']);
    }

    public function checkbox_field(array $args): void {
        $option = (string) $args['option'];
        printf('<label><input type="checkbox" name="%1$s" value="1" %2$s> %3$s</label>', esc_attr($option), checked((bool) get_option($option, false), true, false), esc_html((string) ($args['description'] ?? '')));
    }

    public function page_field(): void {
        wp_dropdown_pages([
            'name' => 'tng_platform_ui_explore_page_id',
            'selected' => absint(get_option('tng_platform_ui_explore_page_id', 0)),
            'show_option_none' => 'Use homepage',
            'option_none_value' => '0',
        ]);
        echo '<p class="description">Place the <code>[tng_explore_app]</code> shortcode on this page.</p>';
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) return;
        $preview_url = add_query_arg('tng_ui_preview', '1', home_url('/'));
        ?>
        <div class="wrap">
            <h1>TN Game Platform UI</h1>
            <p><a class="button button-secondary" target="_blank" rel="noopener" href="<?php echo esc_url($preview_url); ?>">Open private preview</a></p>
            <form method="post" action="options.php">
                <?php settings_fields('tng_platform_ui'); do_settings_sections('tng-platform-ui'); submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function enqueue_assets(): void {
        if (is_admin() || !$this->is_enabled()) return;
        $version = (string) $this->container->get('version');
        wp_enqueue_style('tng-platform-ui', TNG_OS_URL . 'assets/css/platform-ui.css', [], $version);
        wp_enqueue_script('tng-platform-ui', TNG_OS_URL . 'assets/js/platform-ui.js', [], $version, true);
    }

    public function body_classes(array $classes): array {
        if ($this->is_enabled()) $classes[] = 'tng-platform-ui';
        if ($this->replaces_theme_chrome()) $classes[] = 'tng-platform-ui--replace-chrome';
        if ($this->is_preview()) $classes[] = 'tng-platform-ui--preview';
        return $classes;
    }

    private function explore_url(): string {
        $page_id = absint(get_option('tng_platform_ui_explore_page_id', 0));
        return $page_id ? (string) get_permalink($page_id) : home_url('/');
    }

    public function render_header(): void {
        if (!$this->replaces_theme_chrome() || is_admin()) return;
        ?>
        <header class="tng-app-header">
            <div class="tng-app-header__inner">
                <a class="tng-brand" href="<?php echo esc_url($this->explore_url()); ?>" aria-label="TN Game home">
                    <span class="tng-brand__mark" aria-hidden="true">TN</span>
                    <span><strong>The TN Game</strong><small>Explore. Play. Earn XP.</small></span>
                </a>
                <div class="tng-app-header__actions">
                    <a class="tng-icon-button" href="<?php echo esc_url(home_url('/search/')); ?>" aria-label="Search">⌕</a>
                    <a class="tng-avatar-button" href="<?php echo esc_url(is_user_logged_in() ? get_author_posts_url(get_current_user_id()) : wp_login_url()); ?>" aria-label="Profile"><?php echo esc_html(is_user_logged_in() ? strtoupper(substr(wp_get_current_user()->display_name, 0, 1)) : '○'); ?></a>
                </div>
            </div>
        </header>
        <?php
        if ($this->is_preview()) echo '<div class="tng-preview-banner">Private Platform UI preview — only administrators can see this mode.</div>';
    }

    public function render_footer(): void {
        if (!$this->replaces_theme_chrome() || is_admin()) return;
        ?>
        <footer class="tng-app-footer"><div class="tng-app-shell"><strong>The TN Game</strong><span>Built for discovering and playing across Tennessee.</span></div></footer>
        <?php
    }

    public function render_navigation(): void {
        if (!$this->is_enabled() || is_admin()) return;
        $items = apply_filters('tng_platform_navigation_items', [
            ['label' => 'Explore', 'icon' => '⌂', 'url' => $this->explore_url()],
            ['label' => 'Map', 'icon' => '⌖', 'url' => home_url('/map/')],
            ['label' => 'Play', 'icon' => '▶', 'url' => home_url('/play/'), 'primary' => true],
            ['label' => 'Trips', 'icon' => '◇', 'url' => home_url('/trips/')],
            ['label' => 'Profile', 'icon' => '○', 'url' => is_user_logged_in() ? get_author_posts_url(get_current_user_id()) : wp_login_url()],
        ]);
        ?>
        <nav class="tng-app-nav" aria-label="TN Game navigation"><div class="tng-app-nav__inner">
            <?php foreach ($items as $item) : $classes = 'tng-app-nav__item' . (!empty($item['primary']) ? ' is-primary' : ''); ?>
                <a class="<?php echo esc_attr($classes); ?>" href="<?php echo esc_url($item['url']); ?>"><span class="tng-app-nav__icon" aria-hidden="true"><?php echo esc_html($item['icon']); ?></span><span class="tng-app-nav__label"><?php echo esc_html($item['label']); ?></span></a>
            <?php endforeach; ?>
        </div></nav>
        <?php
    }

    public function render_explore_shortcode(): string {
        if (!$this->is_enabled()) return '<p>TN Game Platform UI is not enabled.</p>';
        $categories = [
            ['icon' => '🥾', 'label' => 'Trails', 'url' => home_url('/activity-type/hiking-trails/')],
            ['icon' => '🎯', 'label' => 'Games', 'url' => home_url('/play/')],
            ['icon' => '📍', 'label' => 'Top Sights', 'url' => home_url('/top-sights/')],
            ['icon' => '🎵', 'label' => 'Events', 'url' => home_url('/activity-type/concerts/')],
            ['icon' => '🍴', 'label' => 'Food', 'url' => home_url('/food/')],
            ['icon' => '🗺️', 'label' => 'Destinations', 'url' => home_url('/destinations/')],
        ];
        ob_start(); ?>
        <main class="tng-explore tng-app-shell">
            <section class="tng-explore-hero">
                <span class="tng-eyebrow">Your Tennessee adventure starts here</span>
                <h1>What will you discover today?</h1>
                <p>Find trails, games, local places, events and unforgettable stops—then earn XP as you explore.</p>
                <form class="tng-search-bar" action="<?php echo esc_url(home_url('/')); ?>" method="get"><label class="screen-reader-text" for="tng-explore-search">Search TN Game</label><span aria-hidden="true">⌕</span><input id="tng-explore-search" name="s" type="search" placeholder="Search trails, towns, food and games"><button type="submit">Search</button></form>
            </section>
            <section class="tng-section"><div class="tng-section-heading"><div><span class="tng-eyebrow">Explore</span><h2>Choose your adventure</h2></div></div><div class="tng-category-grid">
                <?php foreach ($categories as $category) : ?><a class="tng-category-card" href="<?php echo esc_url($category['url']); ?>"><span aria-hidden="true"><?php echo esc_html($category['icon']); ?></span><strong><?php echo esc_html($category['label']); ?></strong><small>Explore nearby</small></a><?php endforeach; ?>
            </div></section>
            <section class="tng-quick-start tng-card"><div><span class="tng-eyebrow">Ready to play?</span><h2>Start a nearby adventure</h2><p>Open the game map, find checkpoints and build your Explorer XP.</p></div><a class="tng-button" href="<?php echo esc_url(home_url('/play/')); ?>">Start playing <span aria-hidden="true">→</span></a></section>
            <?php echo apply_filters('tng_platform_explore_sections', ''); ?>
        </main>
        <?php return (string) ob_get_clean();
    }
}
