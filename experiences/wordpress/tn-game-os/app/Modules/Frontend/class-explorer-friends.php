<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use TNG_OS\Modules\Admin\Gameplay_Control_Center;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class Explorer_Friends implements Module_Interface {
    private const REST_NS = 'tng-game/v1';
    private const FRIENDS_KEY = '_tng_explorer_friends';
    private const PROFILE_KEY = '_tng_explorer_profile';
    private const REACTIONS_KEY = 'tng_explorer_activity_reactions';
    private const REACTIONS = ['cheer', 'fire', 'wow'];

    public function id(): string { return 'explorer_friends'; }

    public function register(Container $container): void {
        $container->set('explorer_friends', $this);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('rest_api_init', [$this, 'register_rest']);
    }

    public function boot(Container $container): void {}

    public function enqueue(): void {
        if (is_admin() || !isset($_GET['tng_quest_runtime_id'])) return;
        wp_enqueue_style('tng-explorer-friends', TNG_OS_URL . 'assets/frontend/explorer-friends.css', [], TNG_OS_VERSION);
        wp_enqueue_script('tng-explorer-friends', TNG_OS_URL . 'assets/frontend/explorer-friends.js', [], TNG_OS_VERSION, true);
        $user_id = get_current_user_id();
        wp_localize_script('tng-explorer-friends', 'TNGExplorerFriends', [
            'loggedIn' => is_user_logged_in(),
            'stateUrl' => rest_url(self::REST_NS . '/explorer-friends'),
            'acceptUrl' => rest_url(self::REST_NS . '/explorer-friends/accept'),
            'removeUrl' => rest_url(self::REST_NS . '/explorer-friends/remove'),
            'reactUrl' => rest_url(self::REST_NS . '/explorer-friends/react'),
            'restNonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'inviteUrl' => $user_id ? add_query_arg('tng_friend', $user_id . '.' . $this->invite_token($user_id), home_url('/')) : '',
        ]);
    }

    public function register_rest(): void {
        register_rest_route(self::REST_NS, '/explorer-friends', [
            'methods' => 'GET',
            'callback' => [$this, 'get_state'],
            'permission_callback' => static fn(): bool => is_user_logged_in(),
        ]);
        register_rest_route(self::REST_NS, '/explorer-friends/accept', [
            'methods' => 'POST',
            'callback' => [$this, 'accept_friend'],
            'permission_callback' => static fn(): bool => is_user_logged_in(),
        ]);
        register_rest_route(self::REST_NS, '/explorer-friends/remove', [
            'methods' => 'POST',
            'callback' => [$this, 'remove_friend'],
            'permission_callback' => static fn(): bool => is_user_logged_in(),
        ]);
        register_rest_route(self::REST_NS, '/explorer-friends/react', [
            'methods' => 'POST',
            'callback' => [$this, 'react'],
            'permission_callback' => static fn(): bool => is_user_logged_in(),
        ]);
    }

    public function get_state(): WP_REST_Response {
        return new WP_REST_Response($this->state(get_current_user_id()), 200);
    }

    public function accept_friend(WP_REST_Request $request): WP_REST_Response {
        $value = sanitize_text_field((string)$request->get_param('invite'));
        [$friend_id, $token] = array_pad(explode('.', $value, 2), 2, '');
        $friend_id = absint($friend_id);
        $user_id = get_current_user_id();
        if (!$friend_id || $friend_id === $user_id || !hash_equals($this->invite_token($friend_id), $token) || !get_userdata($friend_id)) {
            return new WP_REST_Response(['message' => 'This friend invitation is not valid.'], 400);
        }
        $this->connect($user_id, $friend_id);
        return new WP_REST_Response($this->state($user_id), 200);
    }

    public function remove_friend(WP_REST_Request $request): WP_REST_Response {
        $friend_id = absint($request->get_param('friendId'));
        $user_id = get_current_user_id();
        if ($friend_id) $this->disconnect($user_id, $friend_id);
        return new WP_REST_Response($this->state($user_id), 200);
    }

    public function react(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $activity_id = sanitize_text_field((string)$request->get_param('activityId'));
        $friend_id = absint($request->get_param('friendId'));
        $reaction = sanitize_key((string)$request->get_param('reaction'));
        if ($activity_id === '' || !$friend_id || !in_array($reaction, self::REACTIONS, true) || !in_array($friend_id, $this->friend_ids($user_id), true)) {
            return new WP_REST_Response(['message' => 'Unable to react to this activity.'], 400);
        }
        $store = get_option(self::REACTIONS_KEY, []);
        if (!is_array($store)) $store = [];
        $entry = isset($store[$activity_id]) && is_array($store[$activity_id]) ? $store[$activity_id] : [];
        foreach (self::REACTIONS as $key) {
            $entry[$key] = array_values(array_filter(array_map('absint', (array)($entry[$key] ?? [])), static fn(int $id): bool => $id !== $user_id));
        }
        $entry[$reaction][] = $user_id;
        $entry[$reaction] = array_values(array_unique($entry[$reaction]));
        $entry['updatedAt'] = time();
        $store[$activity_id] = $entry;
        if (count($store) > 500) {
            uasort($store, static fn(array $a, array $b): int => (int)($b['updatedAt'] ?? 0) <=> (int)($a['updatedAt'] ?? 0));
            $store = array_slice($store, 0, 500, true);
        }
        update_option(self::REACTIONS_KEY, $store, false);
        return new WP_REST_Response($this->state($user_id), 200);
    }

    private function invite_token(int $user_id): string {
        return substr(hash_hmac('sha256', 'tng-friend:' . $user_id, wp_salt('auth')), 0, 24);
    }

    private function connect(int $a, int $b): void {
        foreach ([[$a, $b], [$b, $a]] as [$owner, $friend]) {
            $ids = $this->friend_ids($owner);
            $ids[] = $friend;
            update_user_meta($owner, self::FRIENDS_KEY, array_values(array_unique(array_map('absint', $ids))));
        }
    }

    private function disconnect(int $a, int $b): void {
        foreach ([[$a, $b], [$b, $a]] as [$owner, $friend]) {
            $ids = array_values(array_filter($this->friend_ids($owner), static fn(int $id): bool => $id !== $friend));
            update_user_meta($owner, self::FRIENDS_KEY, $ids);
        }
    }

    private function friend_ids(int $user_id): array {
        return array_values(array_unique(array_filter(array_map('absint', (array)get_user_meta($user_id, self::FRIENDS_KEY, true)))));
    }

    private function reaction_summary(string $activity_id, int $user_id, array $store): array {
        $entry = isset($store[$activity_id]) && is_array($store[$activity_id]) ? $store[$activity_id] : [];
        $summary = [];
        foreach (self::REACTIONS as $reaction) {
            $ids = array_values(array_unique(array_filter(array_map('absint', (array)($entry[$reaction] ?? [])))));
            $summary[$reaction] = ['count' => count($ids), 'mine' => in_array($user_id, $ids, true)];
        }
        return $summary;
    }

    private function state(int $user_id): array {
        $friends = [];
        $activity = [];
        $settings = Gameplay_Control_Center::settings();
        $feed_limit = max(10, min(100, absint($settings['activity_feed_limit'] ?? 30)));
        $reaction_store = get_option(self::REACTIONS_KEY, []);
        if (!is_array($reaction_store)) $reaction_store = [];
        foreach ($this->friend_ids($user_id) as $friend_id) {
            $user = get_userdata($friend_id);
            if (!$user) continue;
            $profile = get_user_meta($friend_id, self::PROFILE_KEY, true);
            if (!is_array($profile)) $profile = [];
            $friends[] = [
                'id' => $friend_id,
                'name' => $user->display_name,
                'avatar' => get_avatar_url($friend_id, ['size' => 96]),
                'title' => sanitize_key((string)($profile['selectedTitle'] ?? 'explorer')),
                'xp' => absint($profile['totalXp'] ?? 0),
                'checkpoints' => count(array_unique((array)($profile['completedCheckpoints'] ?? []))),
                'quests' => count(array_unique((array)($profile['completedQuests'] ?? []))),
            ];
            foreach (array_slice((array)($profile['recentActivity'] ?? []), 0, 8) as $item) {
                if (!is_array($item)) continue;
                $activity_id = $friend_id . ':' . sanitize_text_field((string)($item['id'] ?? uniqid('', true)));
                $activity[] = [
                    'id' => $activity_id,
                    'friendId' => $friend_id,
                    'name' => $user->display_name,
                    'avatar' => get_avatar_url($friend_id, ['size' => 64]),
                    'kind' => sanitize_key((string)($item['kind'] ?? 'checkpoint')),
                    'title' => sanitize_text_field((string)($item['title'] ?? 'Adventure activity')),
                    'subtitle' => sanitize_text_field((string)($item['subtitle'] ?? '')),
                    'xp' => absint($item['xp'] ?? 0),
                    'date' => sanitize_text_field((string)($item['date'] ?? '')),
                    'reactions' => $this->reaction_summary($activity_id, $user_id, $reaction_store),
                ];
            }
        }
        usort($friends, static fn(array $a, array $b): int => $b['xp'] <=> $a['xp']);
        usort($activity, static fn(array $a, array $b): int => strcmp($b['date'], $a['date']));
        return ['friends' => $friends, 'activity' => array_slice($activity, 0, $feed_limit)];
    }
}
