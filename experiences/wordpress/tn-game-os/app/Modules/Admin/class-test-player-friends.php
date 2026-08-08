<?php
namespace TNG_OS\Modules\Admin;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Test_Player_Friends implements Module_Interface {
    private const TEST_META = '_tng_test_player';
    private const FRIENDS_META = '_tng_explorer_friends';

    public function id(): string { return 'test_player_friends'; }

    public function register(Container $container): void {
        $container->set('test_player_friends', $this);
        add_action('admin_init', [$this, 'sync']);
    }

    public function boot(Container $container): void {}

    public function sync(): void {
        if (!current_user_can('manage_options')) return;
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== 'tng-explorer-test-lab') return;

        $admin_id = get_current_user_id();
        $test_ids = array_values(array_filter(array_map('absint', get_users([
            'meta_key' => self::TEST_META,
            'meta_value' => '1',
            'fields' => 'ids',
        ]))));
        if (!$admin_id || !$test_ids) return;

        $admin_friends = $this->friend_ids($admin_id);
        foreach ($test_ids as $test_id) {
            $admin_friends[] = $test_id;
            $test_friends = $this->friend_ids($test_id);
            $test_friends[] = $admin_id;
            update_user_meta($test_id, self::FRIENDS_META, array_values(array_unique($test_friends)));
        }
        update_user_meta($admin_id, self::FRIENDS_META, array_values(array_unique($admin_friends)));
    }

    private function friend_ids(int $user_id): array {
        return array_values(array_unique(array_filter(array_map('absint', (array)get_user_meta($user_id, self::FRIENDS_META, true)))));
    }
}
