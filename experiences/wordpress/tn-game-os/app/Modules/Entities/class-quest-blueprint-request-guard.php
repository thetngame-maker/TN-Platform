<?php
namespace TNG_OS\Modules\Entities;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

/**
 * Prevent third-party admin handlers from treating the generic `blueprint`
 * query parameter as one of their own actions.
 *
 * Incoming legacy URLs are immediately normalized to a TN Game-specific key.
 * The original key is restored only at the very end of admin_init, after other
 * plugins have completed their request validation, so the existing Studio page
 * can continue reading its original parameter without a large migration.
 */
final class Quest_Blueprint_Request_Guard implements Module_Interface {
    private const PAGE = 'tng-quest-blueprint-studio';
    private const SAFE_KEY = 'tng_blueprint_id';

    public function id(): string { return 'quest_blueprint_request_guard'; }

    public function register(Container $container): void {
        $container->set('quest_blueprint_request_guard', $this);
        add_action('admin_init', [$this, 'normalize_legacy_request'], -9999);
        add_action('admin_init', [$this, 'hydrate_studio_request'], PHP_INT_MAX);
    }

    public function boot(Container $container): void {}

    public function normalize_legacy_request(): void {
        if (!is_admin() || sanitize_key((string)($_GET['page'] ?? '')) !== self::PAGE) return;
        if (!isset($_GET['blueprint']) || isset($_GET[self::SAFE_KEY])) return;

        $id = absint($_GET['blueprint']);
        $args = $_GET;
        unset($args['blueprint']);
        $args[self::SAFE_KEY] = $id;
        $args['page'] = self::PAGE;

        wp_safe_redirect(add_query_arg(array_map('sanitize_text_field', wp_unslash($args)), admin_url('admin.php')));
        exit;
    }

    public function hydrate_studio_request(): void {
        if (!is_admin() || sanitize_key((string)($_GET['page'] ?? '')) !== self::PAGE) return;
        if (!isset($_GET[self::SAFE_KEY])) return;

        // Added only after other plugins have completed admin_init validation.
        $_GET['blueprint'] = absint($_GET[self::SAFE_KEY]);
    }
}
