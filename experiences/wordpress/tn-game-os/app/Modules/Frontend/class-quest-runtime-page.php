<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Quest_Runtime_Page implements Module_Interface {
    private const QUEST_TYPE = 'tng_quest';

    public function id(): string { return 'quest_runtime_page'; }

    public function register(Container $container): void {
        $container->set('quest_runtime_page', $this);
        add_action('template_redirect', [$this, 'render'], 0);
    }

    public function boot(Container $container): void {}

    public function render(): void {
        $quest_id = absint($_GET['tng_quest_runtime_id'] ?? 0);
        if (!$quest_id) return;

        $quest = get_post($quest_id);
        if (!$quest || $quest->post_type !== self::QUEST_TYPE) {
            status_header(404);
            wp_die('Quest not found.');
        }

        if ($quest->post_status !== 'publish' && !current_user_can('edit_post', $quest_id)) {
            status_header(403);
            wp_die('This quest is not available.');
        }

        status_header(200);
        nocache_headers();
        $title = get_the_title($quest_id) ?: 'TN Game Quest';
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html($title); ?> · The TN Game</title>
            <?php wp_head(); ?>
            <style>
                body.tng-runtime-page{margin:0;background:#f4f6f8;color:#18213d}
                .tng-runtime-page-shell{min-height:100vh;padding:24px 16px 48px}
                .tng-runtime-page-top{max-width:860px;margin:0 auto 8px;display:flex;justify-content:space-between;align-items:center;gap:12px}
                .tng-runtime-page-brand{font-weight:800;color:#18213d;text-decoration:none}
                .tng-runtime-page-back{font-size:14px;color:#475467;text-decoration:none}
                @media(max-width:650px){.tng-runtime-page-shell{padding:12px 12px 32px}.tng-runtime-page-top{padding:4px 2px}}
            </style>
        </head>
        <body <?php body_class('tng-runtime-page'); ?>>
            <?php wp_body_open(); ?>
            <main class="tng-runtime-page-shell">
                <div class="tng-runtime-page-top">
                    <a class="tng-runtime-page-brand" href="<?php echo esc_url(home_url('/')); ?>">The TN Game</a>
                    <?php if (current_user_can('manage_options')): ?>
                        <a class="tng-runtime-page-back" href="<?php echo esc_url(admin_url('admin.php?page=tng-quest-library')); ?>">← Quest Library</a>
                    <?php endif; ?>
                </div>
                <?php echo do_shortcode('[tng_quest_runtime id="' . absint($quest_id) . '"]'); ?>
            </main>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
        exit;
    }
}
