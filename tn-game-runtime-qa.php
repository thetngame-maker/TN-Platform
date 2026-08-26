<?php
/**
 * TN Game Runtime QA
 * Admin-only mixed-interaction smoke-test fixture for the native game runtime.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Game_Runtime_QA {
    private const META_FIXTURE = '_tng_runtime_qa_fixture';
    private const OPTION_FIXTURE_ID = 'tng_runtime_qa_fixture_id';

    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu'], 35);
        add_action('admin_post_tng_runtime_qa_create', [self::class, 'create_or_refresh']);
        add_action('admin_post_tng_runtime_qa_reset', [self::class, 'reset_current_user']);
    }

    public static function menu(): void {
        add_submenu_page(
            'edit.php?post_type=tng_game',
            'Runtime QA',
            'Runtime QA',
            'manage_options',
            'tng-runtime-qa',
            [self::class, 'render']
        );
    }

    private static function page_url(array $args = []): string {
        return add_query_arg(array_merge([
            'post_type' => 'tng_game',
            'page' => 'tng-runtime-qa',
        ], $args), admin_url('edit.php'));
    }

    private static function fixture_id(): int {
        $id = absint(get_option(self::OPTION_FIXTURE_ID, 0));
        if ($id) {
            $post = get_post($id);
            if ($post && $post->post_type === 'tng_game' && get_post_meta($id, self::META_FIXTURE, true)) return $id;
        }
        $posts = get_posts([
            'post_type' => 'tng_game',
            'post_status' => ['publish','draft','private'],
            'posts_per_page' => 1,
            'meta_key' => self::META_FIXTURE,
            'meta_value' => '1',
            'orderby' => 'ID',
            'order' => 'DESC',
        ]);
        if ($posts) {
            $id = (int) $posts[0]->ID;
            update_option(self::OPTION_FIXTURE_ID, $id, false);
            return $id;
        }
        return 0;
    }

    private static function checkpoints(): array {
        return [
            [
                'title' => 'Welcome Check-in',
                'instructions' => 'Use the normal check-in button to verify a simple tap checkpoint.',
                'type' => 'tap',
                'xp' => 10,
            ],
            [
                'title' => 'Answer Test',
                'instructions' => 'Enter the word orange to verify question validation.',
                'type' => 'question',
                'answer' => 'orange',
                'xp' => 50,
            ],
            [
                'title' => 'Photo Test',
                'instructions' => 'Upload any temporary test photo to verify image submission.',
                'type' => 'photo',
                'xp' => 40,
            ],
            [
                'title' => 'GPS Test',
                'instructions' => 'Use Developer GPS to simulate this location and verify distance validation.',
                'type' => 'gps',
                'latitude' => 35.250000,
                'longitude' => -85.750000,
                'radius' => 30,
                'xp' => 25,
            ],
        ];
    }

    private static function apply_fixture(int $id): void {
        $checkpoints = self::checkpoints();
        wp_update_post([
            'ID' => $id,
            'post_status' => 'publish',
            'post_title' => 'TN Game Runtime QA — Mixed Interactions',
            'post_content' => 'Internal developer smoke test for the native TN Game runtime. Complete tap, question, photo, and GPS checkpoints in order.',
            'post_excerpt' => 'Internal mixed-interaction runtime test fixture.',
        ]);
        update_post_meta($id, self::META_FIXTURE, '1');
        update_post_meta($id, '_tng_game_archived', '1');
        update_post_meta($id, 'playable', '1');
        update_post_meta($id, 'game_type', 'Quick Play');
        update_post_meta($id, 'difficulty', 'Developer QA');
        update_post_meta($id, 'estimated_time', '5–10 min');
        update_post_meta($id, 'players', '1 tester');
        update_post_meta($id, 'tng_game_checkpoints', $checkpoints);
        update_post_meta($id, 'checkpoint_count', count($checkpoints));
        update_post_meta($id, 'xp_available', 125);
        delete_post_meta($id, 'tng_trail_id');
        update_option(self::OPTION_FIXTURE_ID, $id, false);
    }

    public static function create_or_refresh(): void {
        if (!current_user_can('manage_options')) wp_die('You do not have permission to manage Runtime QA.');
        check_admin_referer('tng_runtime_qa_create');
        $id = self::fixture_id();
        if (!$id) {
            $id = wp_insert_post([
                'post_type' => 'tng_game',
                'post_status' => 'publish',
                'post_title' => 'TN Game Runtime QA — Mixed Interactions',
                'post_author' => get_current_user_id(),
            ], true);
            if (is_wp_error($id)) {
                wp_safe_redirect(self::page_url(['qa_error' => 'create'])); exit;
            }
            $id = (int) $id;
        }
        self::apply_fixture($id);
        wp_safe_redirect(self::page_url(['qa_ready' => $id])); exit;
    }

    private static function clear_user_state(int $game_id, int $user_id): void {
        delete_user_meta($user_id, '_tng_game_progress_' . $game_id);
        delete_user_meta($user_id, '_tng_game_completed_at_' . $game_id);
        for ($i = 0; $i < 4; $i++) {
            delete_user_meta($user_id, '_tng_game_photo_' . $game_id . '_' . $i);
        }
        $games = get_user_meta($user_id, '_tng_completed_games', true);
        if (is_array($games)) {
            $games = array_values(array_filter(array_map('absint', $games), static fn($id) => $id !== $game_id));
            update_user_meta($user_id, '_tng_completed_games', $games);
        }
    }

    public static function reset_current_user(): void {
        if (!current_user_can('manage_options')) wp_die('You do not have permission to reset Runtime QA.');
        $id = self::fixture_id();
        check_admin_referer('tng_runtime_qa_reset_' . $id);
        if ($id) self::clear_user_state($id, get_current_user_id());
        wp_safe_redirect(self::page_url(['qa_reset' => $id])); exit;
    }

    private static function progress(int $game_id): array {
        if (!$game_id) return [];
        $progress = get_user_meta(get_current_user_id(), '_tng_game_progress_' . $game_id, true);
        return is_array($progress) ? array_values(array_unique(array_map('absint', $progress))) : [];
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) wp_die('You do not have permission to view Runtime QA.');
        $id = self::fixture_id();
        $post = $id ? get_post($id) : null;
        $progress = self::progress($id);
        $count = count(self::checkpoints());
        $done = count(array_intersect(range(0, $count - 1), $progress));
        $complete = $id && $done >= $count;
        $receipt = $id ? get_post_meta($id, '_tng_guided_test_receipt', true) : [];
        if (!is_array($receipt)) $receipt = [];
        $play_url = $id ? add_query_arg('game', $id, home_url('/game-play/')) : '';
        ?>
        <div class="wrap tng-runtime-qa" style="max-width:1080px">
            <style>
                .tng-runtime-qa__hero{margin:18px 0;padding:28px 30px;border-radius:18px;background:linear-gradient(135deg,#14213d,#244a62);color:#fff}.tng-runtime-qa__hero h1{color:#fff;margin:4px 0 7px;font-size:32px}.tng-runtime-qa__hero p{margin:0;color:#dbe7ef;max-width:760px}.tng-runtime-qa__eyebrow{font-size:11px;font-weight:900;letter-spacing:.13em;text-transform:uppercase;color:#ff8a3d}.tng-runtime-qa__grid{display:grid;grid-template-columns:1.2fr .8fr;gap:16px}.tng-runtime-qa__card{background:#fff;border:1px solid #dfe4e8;border-radius:15px;padding:20px}.tng-runtime-qa__card h2{margin-top:0}.tng-runtime-qa__steps{display:grid;gap:9px;margin-top:14px}.tng-runtime-qa__step{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:11px 13px;border-radius:10px;background:#f6f8f9}.tng-runtime-qa__step.is-done{background:#edf7f0}.tng-runtime-qa__badge{font-weight:800;font-size:12px}.tng-runtime-qa__actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:17px}.tng-runtime-qa__status{padding:12px;border-radius:11px;background:#fff8e8;margin-bottom:12px}.tng-runtime-qa__status.is-pass{background:#e8f5ec;color:#246c45}.tng-runtime-qa__small{color:#66737c;font-size:12px;line-height:1.55}@media(max-width:800px){.tng-runtime-qa__grid{grid-template-columns:1fr}}
            </style>
            <section class="tng-runtime-qa__hero"><span class="tng-runtime-qa__eyebrow">Final infrastructure check</span><h1>Runtime QA</h1><p>Verify the native TN Game runtime independently of trails and GPX data. This hidden fixture exercises tap, question, photo, GPS, Explorer XP, checkpoint order, Guided Test, and completion.</p></section>

            <?php if (!empty($_GET['qa_ready'])): ?><div class="notice notice-success is-dismissible"><p>Mixed-interaction QA fixture created/refreshed.</p></div><?php endif; ?>
            <?php if (!empty($_GET['qa_reset'])): ?><div class="notice notice-success is-dismissible"><p>Your QA route progress was reset. Previously awarded XP receipts remain protected from duplicate awards.</p></div><?php endif; ?>
            <?php if (!empty($_GET['qa_error'])): ?><div class="notice notice-error is-dismissible"><p>The QA fixture could not be created.</p></div><?php endif; ?>

            <div class="tng-runtime-qa__grid">
                <section class="tng-runtime-qa__card">
                    <h2>Mixed interaction smoke test</h2>
                    <?php if (!$post): ?>
                        <p>Create the hidden QA fixture, then run it with Developer Mode.</p>
                    <?php else: ?>
                        <div class="tng-runtime-qa__status <?php echo $complete ? 'is-pass' : ''; ?>"><strong><?php echo $complete ? '✓ Route completed' : esc_html($done . '/' . $count . ' checkpoints completed'); ?></strong></div>
                        <div class="tng-runtime-qa__steps">
                            <?php $labels = ['Tap check-in · 10 XP','Question · answer “orange” · 50 XP','Photo upload · 40 XP','GPS radius check · 25 XP']; foreach ($labels as $i => $label): ?>
                                <div class="tng-runtime-qa__step <?php echo in_array($i, $progress, true) ? 'is-done' : ''; ?>"><span><?php echo esc_html(($i + 1) . '. ' . $label); ?></span><span class="tng-runtime-qa__badge"><?php echo in_array($i, $progress, true) ? 'PASS' : 'Pending'; ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="tng-runtime-qa__actions">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('tng_runtime_qa_create'); ?><input type="hidden" name="action" value="tng_runtime_qa_create"><button class="button button-primary" type="submit"><?php echo $id ? 'Refresh QA fixture' : 'Create QA fixture'; ?></button></form>
                        <?php if ($id): ?><a class="button button-primary" target="_blank" rel="noopener" href="<?php echo esc_url($play_url); ?>">Run mixed test</a><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('tng_runtime_qa_reset_' . $id); ?><input type="hidden" name="action" value="tng_runtime_qa_reset"><button class="button" type="submit">Reset my route</button></form><?php endif; ?>
                    </div>
                </section>
                <aside class="tng-runtime-qa__card">
                    <h2>What passes this milestone?</h2>
                    <p class="tng-runtime-qa__small">Complete all four checkpoints in order. Confirm the wrong question answer is rejected, upload a real test image, use Developer GPS for the final stop, and finish the Guided Test Run.</p>
                    <p class="tng-runtime-qa__small"><strong>Expected total:</strong> 125 XP. Because duplicate-award receipts are preserved, rerunning the same fixture may show less newly awarded XP after the first successful test; that is expected anti-farming behavior.</p>
                    <?php if (!empty($receipt['status']) && $receipt['status'] === 'pass'): ?><div class="tng-runtime-qa__status is-pass"><strong>✓ Certified Guided Test receipt exists</strong><br><span class="tng-runtime-qa__small"><?php echo esc_html((string) ($receipt['tested_at'] ?? '')); ?> · <?php echo esc_html((string) ($receipt['checkpoint_count'] ?? 0)); ?> checkpoints</span></div><?php else: ?><div class="tng-runtime-qa__status"><strong>Guided Test certification pending</strong></div><?php endif; ?>
                    <?php if ($id): ?><p class="tng-runtime-qa__small">Fixture game #<?php echo esc_html((string) $id); ?> is deliberately marked internal/archived and published only so the real runtime and certification endpoint can test it. It is excluded from the player Games directory.</p><?php endif; ?>
                </aside>
            </div>
        </div>
        <?php
    }
}

TNG_Game_Runtime_QA::boot();
