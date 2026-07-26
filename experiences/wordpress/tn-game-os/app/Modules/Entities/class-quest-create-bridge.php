<?php
namespace TNG_OS\Modules\Entities;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Quest_Create_Bridge implements Module_Interface {
    private const BLUEPRINT_TYPE = 'tng_quest_blueprint';

    public function id(): string { return 'quest_create_bridge'; }

    public function register(Container $container): void {
        $container->set('quest_create_bridge', $this);
        add_action('admin_init', [$this, 'handle'], 1);
        add_action('admin_footer', [$this, 'inject_bridge_script']);
    }

    public function boot(Container $container): void {}

    public function handle(): void {
        $is_post_create = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && sanitize_key((string)($_POST['action'] ?? '')) === 'tng_qi_create';
        $is_get_bridge = ($_GET['page'] ?? '') === 'tng-quest-intelligence'
            && ($_GET['tng_qi_bridge'] ?? '') === 'create';

        if (!is_admin() || (!$is_post_create && !$is_get_bridge)) return;
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $request = $is_post_create ? $_POST : $_GET;
        $nonce = sanitize_text_field(wp_unslash($request['_wpnonce'] ?? ''));
        if (!$nonce || !wp_verify_nonce($nonce, 'tng_qi_create')) {
            $this->redirect_error('nonce');
        }

        $key = sanitize_text_field(wp_unslash($request['candidate_key'] ?? ''));
        if ($key === '') $this->redirect_error('missing_candidate');

        $candidate = $this->candidate($key);
        if (!$candidate) $this->redirect_error('candidate_not_found');

        $existing = get_posts([
            'post_type' => self::BLUEPRINT_TYPE,
            'post_status' => ['draft','private','publish','pending'],
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => '_tng_quest_candidate_key',
            'meta_value' => $key,
        ]);
        if ($existing) $this->redirect_success((int)$existing[0], 'reused');

        /*
         * Do not fire the normal after-insert hooks for this internal blueprint.
         * WooCommerce inspects the current admin POST during save_post and rejects
         * our quest nonce as though it were a WooCommerce product nonce. The post
         * was being inserted, but execution stopped before quest metadata and the
         * redirect were written. Passing false as the third wp_insert_post()
         * argument prevents that unrelated save-hook collision.
         */
        $post_id = wp_insert_post([
            'post_type' => self::BLUEPRINT_TYPE,
            'post_status' => 'draft',
            'post_title' => sanitize_text_field((string)($candidate['title'] ?? 'Untitled Quest Blueprint')),
            'post_content' => $this->content($candidate),
        ], true, false);
        if (is_wp_error($post_id)) $this->redirect_error('insert_failed');

        update_post_meta($post_id, '_tng_quest_candidate_key', $key);
        update_post_meta($post_id, '_tng_quest_template', sanitize_key((string)($candidate['template'] ?? 'generated')));
        update_post_meta($post_id, '_tng_quest_entity_ids', array_values(array_map('sanitize_text_field', (array)($candidate['entity_ids'] ?? []))));
        update_post_meta($post_id, '_tng_quest_quality_score', absint($candidate['quality'] ?? 0));
        update_post_meta($post_id, '_tng_quest_estimated_xp', absint($candidate['xp'] ?? 0));
        update_post_meta($post_id, '_tng_quest_estimated_minutes', absint($candidate['minutes'] ?? 0));
        update_post_meta($post_id, '_tng_quest_summary', sanitize_textarea_field((string)($candidate['summary'] ?? '')));
        update_post_meta($post_id, '_tng_quest_status', 'blueprint');

        clean_post_cache((int)$post_id);
        $this->redirect_success((int)$post_id, 'created');
    }

    public function inject_bridge_script(): void {
        if (($_GET['page'] ?? '') !== 'tng-quest-intelligence') return;
        $base = admin_url('admin.php');
        ?>
        <script>
        (() => {
          document.querySelectorAll('form').forEach(form => {
            const action = form.querySelector('input[name="action"][value="tng_qi_create"]');
            if (!action) return;
            form.addEventListener('submit', event => {
              event.preventDefault();
              const key = form.querySelector('input[name="candidate_key"]')?.value || '';
              const nonce = form.querySelector('input[name="_wpnonce"]')?.value || '';
              const url = new URL(<?php echo wp_json_encode($base); ?>, window.location.origin);
              url.searchParams.set('page', 'tng-quest-intelligence');
              url.searchParams.set('tng_qi_bridge', 'create');
              url.searchParams.set('candidate_key', key);
              url.searchParams.set('_wpnonce', nonce);
              window.location.assign(url.toString());
            });
          });
        })();
        </script>
        <?php
    }

    private function candidate(string $key): ?array {
        $items = get_option('tng_quest_intelligence_candidates', []);
        foreach (is_array($items) ? $items : [] as $candidate) {
            if (is_array($candidate) && hash_equals((string)($candidate['key'] ?? ''), $key)) return $candidate;
        }
        return null;
    }

    private function content(array $candidate): string {
        $lines = ['Quest generated by TN Game OS Quest Intelligence.', '', 'Template: '.sanitize_text_field((string)($candidate['template_label'] ?? 'Generated Quest')), 'Quality score: '.absint($candidate['quality'] ?? 0).'/100', 'Estimated reward: '.absint($candidate['xp'] ?? 0).' XP', '', 'Proposed checkpoints:'];
        foreach ((array)($candidate['entities'] ?? []) as $i => $entity) {
            if (is_array($entity)) $lines[] = ($i + 1).'. '.sanitize_text_field((string)($entity['title'] ?? 'Checkpoint')).' ('.sanitize_key((string)($entity['type'] ?? 'entity')).')';
        }
        return implode("\n", $lines);
    }

    private function redirect_success(int $id, string $status): void {
        wp_safe_redirect(add_query_arg(['page'=>'tng-quest-blueprint-studio','blueprint'=>$id,'pipeline'=>$status], admin_url('admin.php')));
        exit;
    }

    private function redirect_error(string $code): void {
        wp_safe_redirect(add_query_arg(['page'=>'tng-quest-intelligence','pipeline_error'=>$code], admin_url('admin.php')));
        exit;
    }
}
