<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class Explorer_Journal implements Module_Interface {
    private const REST_NS = 'tng-game/v1';
    private const PROFILE_KEY = '_tng_explorer_profile';

    public function id(): string { return 'explorer_journal'; }

    public function register(Container $container): void {
        $container->set('explorer_journal', $this);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('rest_api_init', [$this, 'register_rest']);
    }

    public function boot(Container $container): void {}

    public function enqueue(): void {
        if (is_admin() || !isset($_GET['tng_quest_runtime_id'])) return;
        wp_enqueue_style('tng-explorer-journal', TNG_OS_URL . 'assets/frontend/explorer-journal.css', [], TNG_OS_VERSION);
        wp_enqueue_script('tng-explorer-journal', TNG_OS_URL . 'assets/frontend/explorer-journal.js', [], TNG_OS_VERSION, true);
        wp_localize_script('tng-explorer-journal', 'TNGExplorerJournal', [
            'loggedIn' => is_user_logged_in(),
            'stateUrl' => rest_url(self::REST_NS . '/explorer-journal'),
            'restNonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'initialState' => is_user_logged_in() ? $this->state(get_current_user_id()) : ['entries' => [], 'stats' => []],
        ]);
    }

    public function register_rest(): void {
        register_rest_route(self::REST_NS, '/explorer-journal', [
            'methods' => 'GET',
            'callback' => [$this, 'get_state'],
            'permission_callback' => static fn(): bool => is_user_logged_in(),
        ]);
    }

    public function get_state(): WP_REST_Response {
        return new WP_REST_Response($this->state(get_current_user_id()), 200);
    }

    private function state(int $user_id): array {
        $profile = get_user_meta($user_id, self::PROFILE_KEY, true);
        if (!is_array($profile)) $profile = [];
        $entries = [];
        foreach ((array)($profile['recentActivity'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $kind = sanitize_key((string)($item['kind'] ?? 'checkpoint'));
            $entries[] = [
                'id' => sanitize_text_field((string)($item['id'] ?? wp_generate_uuid4())),
                'kind' => $kind,
                'title' => sanitize_text_field((string)($item['title'] ?? 'Adventure memory')),
                'subtitle' => sanitize_text_field((string)($item['subtitle'] ?? '')),
                'xp' => absint($item['xp'] ?? 0),
                'date' => sanitize_text_field((string)($item['date'] ?? current_time('mysql', true))),
            ];
        }
        usort($entries, static fn(array $a, array $b): int => strcmp($b['date'], $a['date']));
        $checkpoints = count((array)($profile['completedCheckpoints'] ?? []));
        $quests = count((array)($profile['completedQuests'] ?? []));
        return [
            'entries' => array_slice($entries, 0, 100),
            'stats' => [
                'xp' => absint($profile['totalXp'] ?? 0),
                'checkpoints' => $checkpoints,
                'quests' => $quests,
                'collections' => count(array_filter((array)($profile['collections'] ?? []))),
                'years' => count(array_unique(array_filter(array_map(static function(array $entry): string {
                    $time = strtotime($entry['date']);
                    return $time ? gmdate('Y', $time) : '';
                }, $entries)))),
            ],
        ];
    }
}
