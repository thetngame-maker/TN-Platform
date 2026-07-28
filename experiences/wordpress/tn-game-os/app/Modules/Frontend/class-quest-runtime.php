<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class Quest_Runtime implements Module_Interface {
    private const QUERY_KEY = 'tng_quest_runtime_id';
    private const REST_NS = 'tng-game/v1';

    public function id(): string { return 'quest_runtime'; }

    public function register(Container $container): void {
        $container->set('quest_runtime', $this);
        add_action('template_redirect', [$this, 'maybe_render'], 0);
        add_action('rest_api_init', [$this, 'register_rest']);
    }

    public function boot(Container $container): void {}

    public function register_rest(): void {
        register_rest_route(self::REST_NS, '/quest-progress/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_progress'],
                'permission_callback' => static fn(): bool => is_user_logged_in(),
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'save_progress'],
                'permission_callback' => static fn(): bool => is_user_logged_in(),
            ],
        ]);
    }

    public function get_progress(WP_REST_Request $request): WP_REST_Response {
        $quest_id = absint($request['id']);
        return new WP_REST_Response($this->progress(get_current_user_id(), $quest_id), 200);
    }

    public function save_progress(WP_REST_Request $request): WP_REST_Response {
        $quest_id = absint($request['id']);
        $stops = $this->stops($quest_id);
        $allowed = array_map(static fn(array $stop): string => (string)($stop['id'] ?? ''), $stops);
        $completed = array_values(array_intersect(array_map('strval', (array)$request->get_param('completedStops')), $allowed));
        $state = [
            'started' => (bool)$request->get_param('started'),
            'completedStops' => $completed,
            'status' => count($completed) >= count($stops) && count($stops) > 0 ? 'complete' : ((bool)$request->get_param('started') ? 'in_progress' : 'not_started'),
            'updatedAt' => current_time('mysql', true),
        ];
        update_user_meta(get_current_user_id(), '_tng_quest_progress_' . $quest_id, $state);
        return new WP_REST_Response($state, 200);
    }

    public function maybe_render(): void {
        if (is_admin() || !isset($_GET[self::QUERY_KEY])) return;

        $quest_id = absint(wp_unslash($_GET[self::QUERY_KEY]));
        if (!$quest_id) $this->render_error('No quest was selected.');

        $quest = get_post($quest_id);
        if (!$quest || $quest->post_type !== 'tng_quest' || !in_array($quest->post_status, ['publish', 'private'], true)) {
            $this->render_error('This quest could not be found or is not available.');
        }

        $stops = $this->stops($quest_id);
        $xp = absint(get_post_meta($quest_id, '_tng_quest_xp', true) ?: get_post_meta($quest_id, '_tng_quest_estimated_xp', true));
        if (!$xp) $xp = array_sum(array_map(static fn(array $stop): int => absint($stop['xp'] ?? 0), $stops));
        $minutes = absint(get_post_meta($quest_id, '_tng_quest_estimated_minutes', true));
        $duration = $minutes ? $this->duration_label($minutes) : sanitize_text_field((string)get_post_meta($quest_id, '_tng_quest_duration', true));
        $summary = sanitize_text_field((string)get_post_meta($quest_id, '_tng_quest_summary', true));
        if (!$summary) $summary = has_excerpt($quest_id) ? get_the_excerpt($quest_id) : wp_trim_words(wp_strip_all_tags($quest->post_content), 28);
        $dev = current_user_can('manage_options') && isset($_GET['tng_dev']) && sanitize_text_field(wp_unslash($_GET['tng_dev'])) === '1';

        $config = [
            'questId' => $quest_id,
            'storageKey' => 'tngQuestRuntime:' . $quest_id,
            'developer' => $dev,
            'loggedIn' => is_user_logged_in(),
            'progressUrl' => rest_url(self::REST_NS . '/quest-progress/' . $quest_id),
            'restNonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'stops' => $stops,
            'rewardXp' => $xp,
        ];

        status_header(200);
        nocache_headers();
        ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?php echo esc_html(get_the_title($quest_id)); ?> · <?php bloginfo('name'); ?></title>
<link rel="stylesheet" href="<?php echo esc_url(TNG_OS_URL . 'assets/frontend/quest-runtime.css?ver=' . rawurlencode(TNG_OS_VERSION)); ?>">
<?php wp_head(); ?>
</head>
<body class="tng-quest-runtime-page<?php echo $dev ? ' tng-developer-mode-active' : ''; ?>">
<main class="tng-runtime" data-quest-id="<?php echo esc_attr((string)$quest_id); ?>" data-runtime-version="2">
    <header class="tng-runtime-topbar">
        <a href="<?php echo esc_url(home_url('/?tng_world=1')); ?>">The TN Game</a>
        <a href="<?php echo esc_url(home_url('/')); ?>">Exit quest</a>
    </header>

    <section class="tng-runtime-hero">
        <span class="tng-runtime-eyebrow">TN GAME QUEST</span>
        <h1><?php echo esc_html(get_the_title($quest_id)); ?></h1>
        <?php if ($summary): ?><p><?php echo esc_html($summary); ?></p><?php endif; ?>
        <div class="tng-runtime-facts">
            <span><?php echo esc_html(sprintf(_n('%d checkpoint', '%d checkpoints', count($stops), 'tn-game-os'), count($stops))); ?></span>
            <span><?php echo esc_html(number_format_i18n($xp)); ?> XP</span>
            <?php if ($duration): ?><span><?php echo esc_html($duration); ?></span><?php endif; ?>
        </div>
        <button type="button" class="tng-runtime-start">Start Quest</button>
        <p class="tng-runtime-js-status" role="status">Preparing quest controls…</p>
    </section>

    <section class="tng-runtime-active" hidden>
        <div class="tng-runtime-progress-head">
            <div><span class="tng-runtime-eyebrow">QUEST ACTIVE</span><h2>Your journey</h2></div>
            <strong><span data-completed>0</span> / <?php echo esc_html((string)count($stops)); ?> complete</strong>
        </div>
        <div class="tng-runtime-progress"><span></span></div>
        <div class="tng-runtime-checkpoints"></div>
        <div class="tng-runtime-actions"><button type="button" class="tng-runtime-reset">Reset progress</button></div>
    </section>

    <?php if (!$stops): ?><aside class="tng-runtime-warning">No checkpoints were found for this quest. Open the Quest editor and confirm its linked entities are saved.</aside><?php endif; ?>
    <?php if ($dev): ?><aside class="tng-runtime-dev">Developer Mode is active. Current checkpoint claims may be tested without location validation.</aside><?php endif; ?>
</main>
<script>window.TNGQuestRuntime=<?php echo wp_json_encode($config); ?>;</script>
<script src="<?php echo esc_url(TNG_OS_URL . 'assets/frontend/quest-runtime.js?ver=' . rawurlencode(TNG_OS_VERSION)); ?>" defer></script>
<?php wp_footer(); ?>
</body>
</html><?php
        exit;
    }

    private function progress(int $user_id, int $quest_id): array {
        $state = get_user_meta($user_id, '_tng_quest_progress_' . $quest_id, true);
        if (!is_array($state)) $state = [];
        return [
            'started' => !empty($state['started']),
            'completedStops' => array_values(array_map('strval', (array)($state['completedStops'] ?? []))),
            'status' => sanitize_key((string)($state['status'] ?? 'not_started')),
        ];
    }

    private function stops(int $quest_id): array {
        $ids = (array)get_post_meta($quest_id, '_tng_quest_entity_ids', true);
        $notes = (array)get_post_meta($quest_id, '_tng_quest_checkpoint_instructions', true);
        $mechanics = (array)get_post_meta($quest_id, '_tng_game_checkpoint_mechanics', true);
        $stops = [];
        foreach ($ids as $entity_id) {
            $key = (string)$entity_id;
            $post_id = absint($entity_id);
            $entity = $post_id ? get_post($post_id) : null;
            $m = is_array($mechanics[$key] ?? null) ? $mechanics[$key] : [];
            $stops[] = [
                'id' => $key,
                'title' => $entity ? get_the_title($entity) : ('Checkpoint ' . (count($stops) + 1)),
                'type' => sanitize_key((string)($m['type'] ?? 'manual')) ?: 'manual',
                'instruction' => sanitize_textarea_field((string)($m['challenge'] ?? $notes[$key] ?? 'Complete this checkpoint to continue.')),
                'hint' => sanitize_text_field((string)($m['hint'] ?? '')),
                'xp' => absint($m['xp'] ?? 25),
            ];
        }
        return $stops;
    }

    private function duration_label(int $minutes): string {
        if ($minutes < 60) return $minutes . ' min';
        $hours = round($minutes / 60, 1);
        return rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.') . ' hr';
    }

    private function render_error(string $message): void {
        status_header(404);
        nocache_headers();
        wp_die(esc_html($message), 'Quest Runtime', ['response' => 404, 'back_link' => true]);
    }
}
