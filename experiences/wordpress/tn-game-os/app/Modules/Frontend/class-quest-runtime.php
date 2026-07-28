<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Quest_Runtime implements Module_Interface {
    private const QUERY_KEY = 'tng_quest_runtime_id';

    public function id(): string { return 'quest_runtime'; }

    public function register(Container $container): void {
        $container->set('quest_runtime', $this);
        add_action('template_redirect', [$this, 'maybe_render'], 0);
    }

    public function boot(Container $container): void {}

    public function maybe_render(): void {
        if (is_admin() || !isset($_GET[self::QUERY_KEY])) return;

        $quest_id = absint(wp_unslash($_GET[self::QUERY_KEY]));
        if (!$quest_id) {
            $this->render_error('No quest was selected.');
        }

        $quest = get_post($quest_id);
        if (!$quest || $quest->post_type !== 'tng_quest' || !in_array($quest->post_status, ['publish', 'private'], true)) {
            $this->render_error('This quest could not be found or is not available.');
        }

        $stops = $this->stops($quest_id);
        $xp = absint(get_post_meta($quest_id, '_tng_quest_xp', true));
        if (!$xp) $xp = array_sum(array_map(static fn(array $stop): int => absint($stop['xp'] ?? 0), $stops));
        $duration = sanitize_text_field((string)get_post_meta($quest_id, '_tng_quest_duration', true));
        $summary = has_excerpt($quest_id) ? get_the_excerpt($quest_id) : wp_trim_words(wp_strip_all_tags($quest->post_content), 28);
        $dev = current_user_can('manage_options') && isset($_GET['tng_dev']) && sanitize_text_field(wp_unslash($_GET['tng_dev'])) === '1';

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
<main class="tng-runtime" data-quest-id="<?php echo esc_attr((string)$quest_id); ?>" data-runtime-version="1">
    <header class="tng-runtime-topbar">
        <a href="<?php echo esc_url(remove_query_arg([self::QUERY_KEY, 'tng_dev'])); ?>">The TN Game</a>
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
        <div>
            <span class="tng-runtime-eyebrow">QUEST ACTIVE</span>
            <h2>Your adventure has started.</h2>
            <p>Progress and checkpoints will be added in the next runtime phase.</p>
        </div>
        <button type="button" class="tng-runtime-reset">Reset test state</button>
    </section>

    <?php if ($dev): ?>
        <aside class="tng-runtime-dev">Developer Mode is active for this request.</aside>
    <?php endif; ?>
</main>
<script>window.TNGQuestRuntime=<?php echo wp_json_encode(['questId'=>$quest_id,'storageKey'=>'tngQuestRuntime:'.$quest_id,'developer'=>$dev]); ?>;</script>
<script src="<?php echo esc_url(TNG_OS_URL . 'assets/frontend/quest-runtime.js?ver=' . rawurlencode(TNG_OS_VERSION)); ?>" defer></script>
<?php wp_footer(); ?>
</body>
</html><?php
        exit;
    }

    private function stops(int $quest_id): array {
        foreach (['_tng_quest_stops', '_tng_quest_checkpoints', 'tng_quest_stops'] as $key) {
            $value = get_post_meta($quest_id, $key, true);
            if (is_array($value)) return array_values(array_filter($value, 'is_array'));
        }
        return [];
    }

    private function render_error(string $message): void {
        status_header(404);
        nocache_headers();
        wp_die(esc_html($message), 'Quest Runtime', ['response' => 404, 'back_link' => true]);
    }
}
