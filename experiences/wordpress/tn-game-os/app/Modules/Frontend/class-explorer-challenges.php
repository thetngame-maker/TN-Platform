<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class Explorer_Challenges implements Module_Interface {
    private const REST_NS = 'tng-game/v1';
    private const META_KEY = '_tng_explorer_challenges';
    private const PROFILE_KEY = '_tng_explorer_profile';
    private const OPTION_PREFIX = 'tng_explorer_challenge_';

    public function id(): string { return 'explorer_challenges'; }

    public function register(Container $container): void {
        $container->set('explorer_challenges', $this);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('rest_api_init', [$this, 'register_rest']);
    }

    public function boot(Container $container): void {}

    public function enqueue(): void {
        if (is_admin() || !isset($_GET['tng_quest_runtime_id'])) return;
        wp_enqueue_style('tng-explorer-challenges', TNG_OS_URL . 'assets/frontend/explorer-challenges.css', [], TNG_OS_VERSION);
        wp_enqueue_script('tng-explorer-challenges', TNG_OS_URL . 'assets/frontend/explorer-challenges.js', [], TNG_OS_VERSION, true);
        wp_localize_script('tng-explorer-challenges', 'TNGExplorerChallenges', [
            'loggedIn' => is_user_logged_in(),
            'displayName' => is_user_logged_in() ? wp_get_current_user()->display_name : 'Explorer',
            'challengeUrl' => rest_url(self::REST_NS . '/explorer-challenges'),
            'restNonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'incomingToken' => sanitize_key((string)($_GET['tng_challenge'] ?? '')),
            'questId' => absint($_GET['tng_quest_runtime_id'] ?? 0),
        ]);
    }

    public function register_rest(): void {
        register_rest_route(self::REST_NS, '/explorer-challenges', [
            ['methods' => 'GET', 'callback' => [$this, 'list_challenges'], 'permission_callback' => static fn(): bool => is_user_logged_in()],
            ['methods' => 'POST', 'callback' => [$this, 'create_challenge'], 'permission_callback' => static fn(): bool => is_user_logged_in()],
        ]);
        register_rest_route(self::REST_NS, '/explorer-challenges/(?P<token>[a-z0-9]{20,64})', [
            ['methods' => 'GET', 'callback' => [$this, 'get_challenge'], 'permission_callback' => '__return_true'],
            ['methods' => 'POST', 'callback' => [$this, 'accept_challenge'], 'permission_callback' => static fn(): bool => is_user_logged_in()],
        ]);
    }

    public function create_challenge(WP_REST_Request $request): WP_REST_Response {
        $body = (array)$request->get_json_params();
        $type = sanitize_key((string)($body['type'] ?? 'checkpoint'));
        $definitions = $this->definitions();
        if (!isset($definitions[$type])) return new WP_REST_Response(['message' => 'Invalid challenge type.'], 400);

        $user_id = get_current_user_id();
        $token = strtolower(wp_generate_password(28, false, false));
        $profile = $this->profile($user_id);
        $challenge = [
            'token' => $token,
            'type' => $type,
            'target' => $definitions[$type]['target'],
            'title' => $definitions[$type]['title'],
            'creatorId' => $user_id,
            'creatorName' => wp_get_current_user()->display_name,
            'creatorBaseline' => $this->metric($profile, $type),
            'opponentId' => 0,
            'opponentName' => '',
            'opponentBaseline' => 0,
            'createdAt' => time(),
            'expiresAt' => time() + (7 * DAY_IN_SECONDS),
            'winnerId' => 0,
            'status' => 'open',
            'questId' => absint($body['questId'] ?? 0),
        ];
        update_option(self::OPTION_PREFIX . $token, $challenge, false);
        $this->attach($user_id, $token);
        return new WP_REST_Response($this->present($challenge), 201);
    }

    public function list_challenges(): WP_REST_Response {
        $tokens = (array)get_user_meta(get_current_user_id(), self::META_KEY, true);
        $items = [];
        foreach ($tokens as $token) {
            $challenge = get_option(self::OPTION_PREFIX . sanitize_key((string)$token));
            if (is_array($challenge)) $items[] = $this->present($challenge);
        }
        usort($items, static fn(array $a, array $b): int => ($b['createdAt'] ?? 0) <=> ($a['createdAt'] ?? 0));
        return new WP_REST_Response(array_slice($items, 0, 12), 200);
    }

    public function get_challenge(WP_REST_Request $request): WP_REST_Response {
        $challenge = $this->load((string)$request['token']);
        if (!$challenge) return new WP_REST_Response(['message' => 'Challenge not found.'], 404);
        return new WP_REST_Response($this->present($challenge), 200);
    }

    public function accept_challenge(WP_REST_Request $request): WP_REST_Response {
        $challenge = $this->load((string)$request['token']);
        if (!$challenge) return new WP_REST_Response(['message' => 'Challenge not found.'], 404);
        if ((int)$challenge['creatorId'] === get_current_user_id()) return new WP_REST_Response(['message' => 'You created this challenge.'], 400);
        if ((int)($challenge['opponentId'] ?? 0) && (int)$challenge['opponentId'] !== get_current_user_id()) return new WP_REST_Response(['message' => 'This challenge already has an opponent.'], 409);
        if ((int)$challenge['expiresAt'] < time()) return new WP_REST_Response(['message' => 'This challenge has expired.'], 410);

        $challenge['opponentId'] = get_current_user_id();
        $challenge['opponentName'] = wp_get_current_user()->display_name;
        $challenge['opponentBaseline'] = $this->metric($this->profile(get_current_user_id()), (string)$challenge['type']);
        $challenge['status'] = 'active';
        update_option(self::OPTION_PREFIX . $challenge['token'], $challenge, false);
        $this->attach(get_current_user_id(), $challenge['token']);
        return new WP_REST_Response($this->present($challenge), 200);
    }

    private function load(string $token): ?array {
        $challenge = get_option(self::OPTION_PREFIX . sanitize_key($token));
        return is_array($challenge) ? $challenge : null;
    }

    private function attach(int $user_id, string $token): void {
        $tokens = (array)get_user_meta($user_id, self::META_KEY, true);
        $tokens[] = sanitize_key($token);
        update_user_meta($user_id, self::META_KEY, array_slice(array_values(array_unique($tokens)), -30));
    }

    private function definitions(): array {
        return [
            'checkpoint' => ['title' => 'Checkpoint Sprint', 'target' => 3],
            'quest' => ['title' => 'Quest Race', 'target' => 1],
            'xp' => ['title' => 'XP Dash', 'target' => 100],
        ];
    }

    private function profile(int $user_id): array {
        $profile = get_user_meta($user_id, self::PROFILE_KEY, true);
        return is_array($profile) ? $profile : [];
    }

    private function metric(array $profile, string $type): int {
        if ($type === 'quest') return count((array)($profile['completedQuests'] ?? []));
        if ($type === 'xp') return absint($profile['totalXp'] ?? 0);
        return count((array)($profile['completedCheckpoints'] ?? []));
    }

    private function present(array $challenge): array {
        $type = (string)$challenge['type'];
        $creatorProgress = max(0, $this->metric($this->profile((int)$challenge['creatorId']), $type) - (int)$challenge['creatorBaseline']);
        $opponentProgress = (int)($challenge['opponentId'] ?? 0) ? max(0, $this->metric($this->profile((int)$challenge['opponentId']), $type) - (int)$challenge['opponentBaseline']) : 0;
        $target = max(1, (int)$challenge['target']);
        $winner = (int)($challenge['winnerId'] ?? 0);
        if (!$winner && ($creatorProgress >= $target || $opponentProgress >= $target)) {
            $winner = $creatorProgress >= $target && $opponentProgress >= $target ? -1 : ($creatorProgress >= $target ? (int)$challenge['creatorId'] : (int)$challenge['opponentId']);
            $challenge['winnerId'] = $winner;
            $challenge['status'] = 'complete';
            update_option(self::OPTION_PREFIX . $challenge['token'], $challenge, false);
        }
        $expired = (int)$challenge['expiresAt'] < time();
        return [
            'token' => $challenge['token'],
            'type' => $type,
            'title' => $challenge['title'],
            'target' => $target,
            'creatorId' => (int)$challenge['creatorId'],
            'creatorName' => $challenge['creatorName'],
            'creatorProgress' => min($target, $creatorProgress),
            'opponentId' => (int)($challenge['opponentId'] ?? 0),
            'opponentName' => (string)($challenge['opponentName'] ?? ''),
            'opponentProgress' => min($target, $opponentProgress),
            'winnerId' => $winner,
            'status' => $expired && $challenge['status'] !== 'complete' ? 'expired' : $challenge['status'],
            'createdAt' => (int)$challenge['createdAt'],
            'expiresAt' => (int)$challenge['expiresAt'],
            'questId' => (int)($challenge['questId'] ?? 0),
            'currentUserId' => get_current_user_id(),
        ];
    }
}
