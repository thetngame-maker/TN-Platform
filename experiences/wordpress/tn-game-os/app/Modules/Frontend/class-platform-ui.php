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

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 30);
        add_filter('body_class', [$this, 'body_classes']);
        add_action('wp_footer', [$this, 'render_mobile_navigation'], 40);
    }

    public function boot(Container $container): void {}

    public function is_enabled(): bool {
        $enabled = (bool) get_option('tng_platform_ui_enabled', false);
        return (bool) apply_filters('tng_platform_ui_enabled', $enabled);
    }

    public function enqueue_assets(): void {
        if (is_admin() || !$this->is_enabled()) return;

        $version = (string) $this->container->get('version');
        wp_enqueue_style(
            'tng-platform-ui',
            TNG_OS_URL . 'assets/css/platform-ui.css',
            [],
            $version
        );
        wp_enqueue_script(
            'tng-platform-ui',
            TNG_OS_URL . 'assets/js/platform-ui.js',
            [],
            $version,
            true
        );
    }

    public function body_classes(array $classes): array {
        if ($this->is_enabled()) $classes[] = 'tng-platform-ui';
        return $classes;
    }

    public function render_mobile_navigation(): void {
        if (!$this->is_enabled() || is_admin()) return;

        $items = apply_filters('tng_platform_navigation_items', [
            ['label' => 'Explore', 'icon' => '⌂', 'url' => home_url('/')],
            ['label' => 'Map', 'icon' => '⌖', 'url' => home_url('/map/')],
            ['label' => 'Play', 'icon' => '▶', 'url' => home_url('/play/'), 'primary' => true],
            ['label' => 'Trips', 'icon' => '◇', 'url' => home_url('/trips/')],
            ['label' => 'Profile', 'icon' => '○', 'url' => is_user_logged_in() ? get_author_posts_url(get_current_user_id()) : wp_login_url()],
        ]);
        ?>
        <nav class="tng-app-nav" aria-label="TN Game navigation">
            <div class="tng-app-nav__inner">
                <?php foreach ($items as $item) :
                    $classes = 'tng-app-nav__item' . (!empty($item['primary']) ? ' is-primary' : '');
                ?>
                    <a class="<?php echo esc_attr($classes); ?>" href="<?php echo esc_url($item['url']); ?>">
                        <span class="tng-app-nav__icon" aria-hidden="true"><?php echo esc_html($item['icon']); ?></span>
                        <span class="tng-app-nav__label"><?php echo esc_html($item['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </nav>
        <?php
    }
}
