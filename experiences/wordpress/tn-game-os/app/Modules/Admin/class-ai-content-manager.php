<?php
namespace TNG_OS\Modules\Admin;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Error;
use WP_Post;

if (!defined('ABSPATH')) exit;

/**
 * A draft-first natural-language control layer for TN Game content.
 *
 * The model may propose actions, but it can never execute them. Every write is
 * independently approved, permission checked, logged, and reversible.
 */
final class AI_Content_Manager implements Module_Interface {
    private const PAGE = 'tng-ai-content-manager';
    private const KEY_OPTION = 'tng_ai_admin_openai_key';
    private const MODEL_OPTION = 'tng_ai_admin_openai_model';
    private const PLAN_META = '_tng_ai_admin_plan';
    private const LOG_OPTION = 'tng_ai_admin_action_log';
    private const MAX_RECORDS = 120;

    private const CONTENT_TYPES = [
        'st_activity', 'tng_game', 'top_sight', 'tng_destination',
        'tng_social_item', 'tng_entity', 'post', 'page',
    ];

    public function id(): string { return 'ai_content_manager'; }

    public function register(Container $container): void {
        $container->set('ai_content_manager', $this);
        add_action('admin_menu', [$this, 'menu'], 2);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_tng_ai_admin_plan', [$this, 'handle_plan']);
        add_action('admin_post_tng_ai_admin_apply', [$this, 'handle_apply']);
        add_action('admin_post_tng_ai_admin_restore', [$this, 'handle_restore']);
        add_action('admin_post_tng_ai_admin_settings', [$this, 'handle_settings']);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page(
            'tn-game-os',
            'AI Content Manager',
            'AI Content Manager',
            'edit_posts',
            self::PAGE,
            [$this, 'render']
        );
    }

    public function assets(string $hook): void {
        $page = sanitize_key((string)($_GET['page'] ?? ''));
        if ($page !== self::PAGE) return;
        wp_enqueue_style(
            'tng-ai-content-manager',
            TNG_OS_URL . 'assets/admin/ai-content-manager.css',
            [],
            TNG_OS_VERSION
        );
    }

    public function render(): void {
        if (!current_user_can('edit_posts')) return;

        $inventory = $this->inventory();
        $plan = get_user_meta(get_current_user_id(), self::PLAN_META, true);
        $plan = is_array($plan) ? $plan : [];
        $configured = trim((string)get_option(self::KEY_OPTION, '')) !== '';
        $notice = sanitize_key((string)($_GET['tng_ai_notice'] ?? ''));
        ?>
        <div class="wrap tng-ai-admin">
            <section class="tng-ai-hero">
                <div>
                    <p class="tng-ai-eyebrow">TN GAME AI ADMIN</p>
                    <h1>Ask for the outcome. Review every change.</h1>
                    <p>Turn a natural-language request into a safe content plan using live TN Game inventory. Nothing is published, deleted, or changed until you approve one action at a time.</p>
                </div>
                <div class="tng-ai-mode <?php echo $configured ? 'is-connected' : ''; ?>">
                    <span><?php echo $configured ? 'Structured AI connected' : 'Local planner active'; ?></span>
                    <strong><?php echo esc_html($configured ? (string)get_option(self::MODEL_OPTION, 'gpt-5.6') : 'No key required'); ?></strong>
                </div>
            </section>

            <?php $this->render_notice($notice); ?>

            <section class="tng-ai-metrics">
                <?php foreach ([
                    ['Content records', $inventory['counts']['total']],
                    ['Drafts', $inventory['counts']['draft']],
                    ['Missing images', $inventory['counts']['missing_image']],
                    ['Missing excerpts', $inventory['counts']['missing_excerpt']],
                    ['Review flags', $inventory['counts']['flagged']],
                ] as [$label, $value]): ?>
                    <article><strong><?php echo esc_html((string)$value); ?></strong><span><?php echo esc_html($label); ?></span></article>
                <?php endforeach; ?>
            </section>

            <div class="tng-ai-layout">
                <main>
                    <section class="tng-ai-card tng-ai-command-card">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="tng_ai_admin_plan">
                            <?php wp_nonce_field('tng_ai_admin_plan'); ?>
                            <label for="tng-ai-request">What should TN Game work on?</label>
                            <textarea id="tng-ai-request" name="request" rows="4" maxlength="1500" required placeholder="Find published trails missing featured photos and prepare a review plan."><?php echo esc_textarea((string)($plan['request'] ?? '')); ?></textarea>
                            <div class="tng-ai-prompt-row">
                                <button class="button button-primary button-hero" type="submit">Build review plan</button>
                                <span>Read-only analysis first · explicit approval for every write</span>
                            </div>
                        </form>
                        <div class="tng-ai-examples">
                            <span>Try:</span>
                            <code>Find trails missing photos</code>
                            <code>Show draft concerts</code>
                            <code>Create a Foster Falls reel brief</code>
                            <code>Audit Traveler demo wording</code>
                        </div>
                    </section>

                    <?php if (!empty($plan['summary'])): ?>
                        <section class="tng-ai-card tng-ai-plan">
                            <div class="tng-ai-section-head">
                                <div><p class="tng-ai-eyebrow">REVIEW PLAN</p><h2><?php echo esc_html((string)$plan['summary']); ?></h2></div>
                                <span class="tng-ai-source"><?php echo esc_html(($plan['source'] ?? 'local') === 'openai' ? 'Structured AI' : 'Local planner'); ?></span>
                            </div>
                            <?php if (empty($plan['actions'])): ?>
                                <div class="tng-ai-empty">No matching records or safe draft actions were found. Try naming a content type, place, status, or missing field.</div>
                            <?php else: ?>
                                <div class="tng-ai-actions">
                                    <?php foreach ((array)$plan['actions'] as $index => $action): ?>
                                        <?php $this->render_action((int)$index, (array)$action, (string)($plan['id'] ?? '')); ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                </main>

                <aside>
                    <section class="tng-ai-card">
                        <p class="tng-ai-eyebrow">SAFETY CONTRACT</p>
                        <h2>Human approval stays in control.</h2>
                        <ul class="tng-ai-safety">
                            <li>No automatic publishing</li>
                            <li>No permanent deletion</li>
                            <li>No batch execution</li>
                            <li>Permissions rechecked on every action</li>
                            <li>Every content change has an undo record</li>
                        </ul>
                    </section>

                    <?php $this->render_log(); ?>
                    <?php if (current_user_can('manage_options')) $this->render_settings($configured); ?>
                </aside>
            </div>
        </div>
        <?php
    }

    private function render_notice(string $notice): void {
        $messages = [
            'planned' => ['success', 'Review plan created. No content was changed.'],
            'fallback' => ['warning', 'The model connection was unavailable, so the local planner created this review plan.'],
            'applied' => ['success', 'Approved action completed and added to the reversible activity log.'],
            'opened' => ['success', 'The selected record is ready for review.'],
            'restored' => ['success', 'The selected AI Admin change was restored.'],
            'settings' => ['success', 'AI Content Manager settings saved.'],
            'error' => ['error', 'The requested action could not be completed safely.'],
        ];
        if (!isset($messages[$notice])) return;
        [$class, $text] = $messages[$notice];
        echo '<div class="notice notice-' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';
    }

    private function render_action(int $index, array $action, string $plan_id): void {
        $target_id = absint($action['target_id'] ?? 0);
        $type = sanitize_key((string)($action['type'] ?? 'review_item'));
        $write = in_array($type, ['create_social_brief', 'set_excerpt_from_content', 'move_to_draft'], true);
        $applied = !empty($action['applied_at']);
        $label = [
            'review_item' => 'Open record',
            'create_social_brief' => 'Create draft brief',
            'set_excerpt_from_content' => 'Add proposed excerpt',
            'move_to_draft' => 'Move to draft',
        ][$type] ?? 'Review';
        ?>
        <article class="tng-ai-action">
            <div class="tng-ai-action-top">
                <span class="tng-ai-action-type"><?php echo esc_html(ucwords(str_replace('_', ' ', $type))); ?></span>
                <span class="tng-ai-confidence"><?php echo esc_html(ucfirst((string)($action['confidence'] ?? 'medium'))); ?> confidence</span>
            </div>
            <h3><?php echo esc_html((string)($action['title'] ?? 'Review item')); ?></h3>
            <p><?php echo esc_html((string)($action['description'] ?? '')); ?></p>
            <?php if (!empty($action['preview'])): ?><blockquote><?php echo esc_html((string)$action['preview']); ?></blockquote><?php endif; ?>
            <div class="tng-ai-action-foot">
                <span><?php echo $target_id ? 'Record #' . esc_html((string)$target_id) : 'New draft'; ?></span>
                <?php if ($applied): ?>
                    <span class="tng-ai-approved">✓ Approved</span>
                <?php else: ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="tng_ai_admin_apply">
                        <input type="hidden" name="plan_id" value="<?php echo esc_attr($plan_id); ?>">
                        <input type="hidden" name="action_index" value="<?php echo esc_attr((string)$index); ?>">
                        <?php wp_nonce_field('tng_ai_admin_apply'); ?>
                        <button class="button <?php echo $write ? 'button-primary' : ''; ?>" type="submit"><?php echo esc_html($label); ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </article>
        <?php
    }

    private function render_settings(bool $configured): void {
        $model = (string)get_option(self::MODEL_OPTION, 'gpt-5.6');
        ?>
        <section class="tng-ai-card tng-ai-settings">
            <p class="tng-ai-eyebrow">OPTIONAL AI CONNECTION</p>
            <h2><?php echo $configured ? 'Structured AI is connected.' : 'Connect structured planning.'; ?></h2>
            <p>The API receives only a limited inventory summary: record IDs, titles, types, statuses, and quality flags. It never receives WordPress passwords or private user data.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tng_ai_admin_settings">
                <?php wp_nonce_field('tng_ai_admin_settings'); ?>
                <label>OpenAI API key<input type="password" name="openai_key" value="" autocomplete="new-password" placeholder="<?php echo esc_attr($configured ? 'Key saved — enter only to replace' : 'sk-…'); ?>"></label>
                <label>Model<input type="text" name="openai_model" value="<?php echo esc_attr($model); ?>"></label>
                <label class="tng-ai-remove-key"><input type="checkbox" name="remove_key" value="1"> Remove saved key and use local planner</label>
                <button class="button" type="submit">Save connection</button>
            </form>
        </section>
        <?php
    }

    private function render_log(): void {
        $log = get_option(self::LOG_OPTION, []);
        $log = is_array($log) ? array_slice($log, 0, 6) : [];
        ?>
        <section class="tng-ai-card">
            <p class="tng-ai-eyebrow">RECENT APPROVALS</p>
            <h2>Reversible activity</h2>
            <?php if (!$log): ?>
                <p class="tng-ai-muted">Approved changes will appear here with an undo control.</p>
            <?php else: ?>
                <div class="tng-ai-log">
                    <?php foreach ($log as $entry): ?>
                        <article>
                            <strong><?php echo esc_html((string)($entry['label'] ?? 'Content change')); ?></strong>
                            <span><?php echo esc_html((string)($entry['created_at'] ?? '')); ?></span>
                            <?php if (empty($entry['restored_at'])): ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="tng_ai_admin_restore">
                                    <input type="hidden" name="log_id" value="<?php echo esc_attr((string)($entry['id'] ?? '')); ?>">
                                    <?php wp_nonce_field('tng_ai_admin_restore'); ?>
                                    <button class="button-link" type="submit">Undo</button>
                                </form>
                            <?php else: ?><em>Restored</em><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }

    public function handle_plan(): void {
        if (!current_user_can('edit_posts')) wp_die('Insufficient permissions.');
        check_admin_referer('tng_ai_admin_plan');

        $request = sanitize_textarea_field(wp_unslash($_POST['request'] ?? ''));
        $request = function_exists('mb_substr') ? mb_substr($request, 0, 1500) : substr($request, 0, 1500);
        if (trim($request) === '') $this->redirect('error');

        $inventory = $this->inventory();
        $source = 'local';
        $notice = 'planned';
        $plan = null;

        if (trim((string)get_option(self::KEY_OPTION, '')) !== '') {
            $plan = $this->openai_plan($request, $inventory);
            if (is_wp_error($plan)) {
                $plan = null;
                $notice = 'fallback';
            } else {
                $source = 'openai';
            }
        }

        if (!is_array($plan)) $plan = $this->local_plan($request, $inventory);
        $plan = $this->normalize_plan($plan, $inventory);
        $plan['id'] = wp_generate_uuid4();
        $plan['request'] = $request;
        $plan['source'] = $source;
        $plan['created_at'] = current_time('mysql');
        update_user_meta(get_current_user_id(), self::PLAN_META, $plan);
        $this->redirect($notice);
    }

    public function handle_apply(): void {
        if (!current_user_can('edit_posts')) wp_die('Insufficient permissions.');
        check_admin_referer('tng_ai_admin_apply');

        $plan = get_user_meta(get_current_user_id(), self::PLAN_META, true);
        $plan = is_array($plan) ? $plan : [];
        $plan_id = sanitize_text_field(wp_unslash($_POST['plan_id'] ?? ''));
        $raw_index = (string)wp_unslash($_POST['action_index'] ?? '');
        if ($raw_index === '' || !ctype_digit($raw_index)) $this->redirect('error');
        $index = (int)$raw_index;
        if (!$plan_id || !hash_equals((string)($plan['id'] ?? ''), $plan_id) || !isset($plan['actions'][$index]) || !empty($plan['actions'][$index]['applied_at'])) $this->redirect('error');

        $action = (array)$plan['actions'][$index];
        $type = sanitize_key((string)($action['type'] ?? 'review_item'));
        $target_id = absint($action['target_id'] ?? 0);

        if ($type === 'review_item') {
            if (!$target_id || !current_user_can('edit_post', $target_id)) $this->redirect('error');
            $url = get_edit_post_link($target_id, '');
            if (!$url) $this->redirect('error');
            wp_safe_redirect($url);
            exit;
        }

        if ($type === 'create_social_brief') {
            $result = $this->create_social_brief($action);
        } elseif ($type === 'set_excerpt_from_content') {
            $result = $this->set_excerpt($target_id, (string)($action['preview'] ?? ''));
        } elseif ($type === 'move_to_draft') {
            $result = $this->move_to_draft($target_id);
        } else {
            $result = new WP_Error('unsupported_action', 'Unsupported action.');
        }

        if (is_wp_error($result)) $this->redirect('error');
        $this->record_log($result);
        $plan['actions'][$index]['applied_at'] = current_time('mysql');
        update_user_meta(get_current_user_id(), self::PLAN_META, $plan);
        $this->redirect('applied');
    }

    public function handle_restore(): void {
        if (!current_user_can('edit_posts')) wp_die('Insufficient permissions.');
        check_admin_referer('tng_ai_admin_restore');

        $id = sanitize_text_field(wp_unslash($_POST['log_id'] ?? ''));
        $log = get_option(self::LOG_OPTION, []);
        $log = is_array($log) ? $log : [];
        foreach ($log as &$entry) {
            if (!hash_equals((string)($entry['id'] ?? ''), $id) || !empty($entry['restored_at'])) continue;
            $post_id = absint($entry['post_id'] ?? 0);
            if (!$post_id || !current_user_can('edit_post', $post_id) || !get_post($post_id)) $this->redirect('error');
            $kind = sanitize_key((string)($entry['kind'] ?? ''));
            if ($kind === 'create_social_brief') {
                if (!current_user_can('delete_post', $post_id)) $this->redirect('error');
                if (get_post_status($post_id) !== 'trash' && !wp_trash_post($post_id)) $this->redirect('error');
            } elseif ($kind === 'set_excerpt_from_content') {
                $result = wp_update_post(['ID' => $post_id, 'post_excerpt' => (string)($entry['before'] ?? '')], true);
                if (is_wp_error($result)) $this->redirect('error');
            } elseif ($kind === 'move_to_draft') {
                $result = wp_update_post(['ID' => $post_id, 'post_status' => sanitize_key((string)($entry['before'] ?? 'draft'))], true);
                if (is_wp_error($result)) $this->redirect('error');
            } else {
                $this->redirect('error');
            }
            $entry['restored_at'] = current_time('mysql');
            update_option(self::LOG_OPTION, $log, false);
            $this->redirect('restored');
        }
        unset($entry);
        $this->redirect('error');
    }

    public function handle_settings(): void {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
        check_admin_referer('tng_ai_admin_settings');
        if (!empty($_POST['remove_key'])) {
            delete_option(self::KEY_OPTION);
        } else {
            $key = trim((string)wp_unslash($_POST['openai_key'] ?? ''));
            if ($key !== '') update_option(self::KEY_OPTION, sanitize_text_field($key), false);
        }
        $model = sanitize_text_field(wp_unslash($_POST['openai_model'] ?? 'gpt-5.6'));
        update_option(self::MODEL_OPTION, $model ?: 'gpt-5.6', false);
        $this->redirect('settings');
    }

    private function inventory(): array {
        $types = array_values(array_filter(self::CONTENT_TYPES, 'post_type_exists'));
        $records = [];
        $counts = ['total' => 0, 'draft' => 0, 'missing_image' => 0, 'missing_excerpt' => 0, 'flagged' => 0];
        if (!$types) return ['counts' => $counts, 'records' => []];

        $posts = get_posts([
            'post_type' => $types,
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => self::MAX_RECORDS,
            'orderby' => 'modified',
            'order' => 'DESC',
            'suppress_filters' => false,
        ]);
        foreach ($posts as $post) {
            if (!$post instanceof WP_Post) continue;
            $flags = [];
            if (!has_post_thumbnail($post->ID)) $flags[] = 'missing_image';
            if (trim((string)$post->post_excerpt) === '') $flags[] = 'missing_excerpt';
            if ($this->is_demo_wording($post)) $flags[] = 'traveler_demo_wording';
            if ($post->post_status === 'draft') $counts['draft']++;
            if (in_array('missing_image', $flags, true)) $counts['missing_image']++;
            if (in_array('missing_excerpt', $flags, true)) $counts['missing_excerpt']++;
            if (in_array('traveler_demo_wording', $flags, true)) $counts['flagged']++;
            $records[] = [
                'id' => (int)$post->ID,
                'title' => wp_strip_all_tags((string)$post->post_title),
                'type' => (string)$post->post_type,
                'status' => (string)$post->post_status,
                'modified' => (string)$post->post_modified_gmt,
                'flags' => $flags,
                'terms' => $this->record_terms($post),
                'excerpt_candidate' => $this->excerpt_candidate($post),
            ];
        }
        $counts['total'] = count($records);
        return ['counts' => $counts, 'records' => $records];
    }

    private function is_demo_wording(WP_Post $post): bool {
        $haystack = strtolower(wp_strip_all_tags($post->post_title . ' ' . $post->post_content));
        foreach (['traveler demo', 'traveler 2022', '© copyright traveler', 'lorem ipsum'] as $term) {
            if (strpos($haystack, $term) !== false) return true;
        }
        return false;
    }

    private function excerpt_candidate(WP_Post $post): string {
        $text = trim(wp_strip_all_tags(strip_shortcodes((string)$post->post_content)));
        if ($text === '') return '';
        return wp_trim_words($text, 28, '…');
    }

    private function record_terms(WP_Post $post): array {
        $terms = [];
        foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
            $found = wp_get_object_terms($post->ID, $taxonomy, ['fields' => 'slugs']);
            if (is_wp_error($found)) continue;
            foreach ((array)$found as $slug) $terms[] = sanitize_key((string)$slug);
            if (count($terms) >= 12) break;
        }
        $service = sanitize_key((string)get_post_meta($post->ID, '_tng_content_service', true));
        if ($service !== '') $terms[] = $service;
        return array_slice(array_values(array_unique($terms)), 0, 12);
    }

    private function local_plan(string $request, array $inventory): array {
        $needle = strtolower($request);
        $actions = [];
        $wants_image = preg_match('/\b(image|images|photo|photos|thumbnail|featured)\b/', $needle);
        $wants_excerpt = preg_match('/\b(excerpt|summary|description|copy)\b/', $needle);
        $wants_draft = preg_match('/\b(draft|drafts|unpublished|pending)\b/', $needle);
        $wants_demo = preg_match('/\b(traveler|demo|artifact|lorem|cleanup)\b/', $needle);
        $wants_brief = preg_match('/\b(create|make|write|generate|prepare|build)\b/', $needle)
            && preg_match('/\b(brief|reel|post|carousel|story|content|caption)\b/', $needle);
        $requested_types = $this->requested_types($needle);

        if ($wants_brief) {
            $topic = trim((string)preg_replace('/\b(create|make|write|generate|prepare|build|a|an|the|brief|reel|post|carousel|story|content|caption|for|about)\b/i', ' ', $request));
            $topic = preg_replace('/\s+/', ' ', $topic) ?: 'Tennessee discovery';
            $format = preg_match('/\breel\b/', $needle) ? 'reel' : (preg_match('/\bcarousel\b/', $needle) ? 'carousel' : 'post');
            $actions[] = [
                'type' => 'create_social_brief',
                'target_id' => 0,
                'title' => ucwords($topic) . ' ' . ucfirst($format) . ' brief',
                'description' => 'Create an original draft brief in Content Studio. It will not be scheduled or published.',
                'preview' => 'Open with why ' . $topic . ' belongs on a Tennessee explorer’s list, show the experience clearly, and end with a save-or-explore call to action.',
                'confidence' => 'high',
            ];
        }

        foreach ($inventory['records'] as $record) {
            $match = false;
            if ($wants_image && in_array('missing_image', $record['flags'], true)) $match = true;
            if ($wants_excerpt && in_array('missing_excerpt', $record['flags'], true)) $match = true;
            if ($wants_draft && $record['status'] === 'draft') $match = true;
            if ($wants_demo && in_array('traveler_demo_wording', $record['flags'], true)) $match = true;

            if ($match && $requested_types && !$this->record_matches_types($record, $requested_types)) continue;

            $type_terms = $this->type_terms($record['type']);
            $type_requested = false;
            foreach ($type_terms as $term) if (strpos($needle, $term) !== false) $type_requested = true;
            if ($type_requested && !$wants_image && !$wants_excerpt && !$wants_draft && !$wants_demo) $match = true;

            if (!$match) continue;
            $action_type = 'review_item';
            $preview = '';
            if ($wants_excerpt && $record['excerpt_candidate'] !== '') {
                $action_type = 'set_excerpt_from_content';
                $preview = $record['excerpt_candidate'];
            } elseif ($wants_demo && $record['status'] === 'publish') {
                $action_type = 'move_to_draft';
            }
            $actions[] = [
                'type' => $action_type,
                'target_id' => (int)$record['id'],
                'title' => $record['title'] ?: ('Record #' . $record['id']),
                'description' => $this->record_description($record),
                'preview' => $preview,
                'confidence' => $wants_demo ? 'high' : 'medium',
            ];
            if (count($actions) >= 12) break;
        }

        if (!$actions) {
            foreach ($inventory['records'] as $record) {
                if ($record['title'] !== '' && stripos($request, $record['title']) !== false) {
                    $actions[] = [
                        'type' => 'review_item', 'target_id' => (int)$record['id'],
                        'title' => $record['title'], 'description' => $this->record_description($record),
                        'preview' => '', 'confidence' => 'high',
                    ];
                    break;
                }
            }
        }

        $summary = $actions
            ? sprintf('%d safe recommendation%s ready for review', count($actions), count($actions) === 1 ? '' : 's')
            : 'No safe matches found';
        return ['summary' => $summary, 'actions' => $actions];
    }

    private function type_terms(string $type): array {
        $map = [
            'st_activity' => ['activity', 'activities', 'trail', 'trails', 'concert', 'concerts'],
            'tng_game' => ['game', 'games', 'adventure', 'adventures'],
            'top_sight' => ['top sight', 'top sights', 'waterfall', 'waterfalls', 'sight', 'sights'],
            'tng_destination' => ['destination', 'destinations', 'town', 'towns'],
            'tng_social_item' => ['social', 'post', 'posts', 'content'],
            'tng_entity' => ['entity', 'entities', 'venue', 'venues', 'event', 'events'],
            'post' => ['blog', 'blogs', 'article', 'articles'],
            'page' => ['page', 'pages'],
        ];
        return $map[$type] ?? [$type];
    }

    private function requested_types(string $request): array {
        $requested = [];
        $signals = [
            'trail' => ['trail', 'trails', 'hike', 'hiking'],
            'concert' => ['concert', 'concerts', 'show', 'shows'],
            'game' => ['game', 'games', 'adventure', 'adventures'],
            'top_sight' => ['top sight', 'top sights', 'waterfall', 'waterfalls', 'sight', 'sights'],
            'destination' => ['destination', 'destinations', 'town', 'towns', 'city', 'cities'],
            'social' => ['social post', 'social posts', 'content idea', 'content ideas'],
            'event' => ['event', 'events', 'venue', 'venues'],
            'page' => ['page', 'pages'],
        ];
        foreach ($signals as $type => $terms) {
            foreach ($terms as $term) {
                if (strpos($request, $term) !== false) {
                    $requested[] = $type;
                    break;
                }
            }
        }
        return array_values(array_unique($requested));
    }

    private function record_matches_types(array $record, array $requested): bool {
        $type = (string)($record['type'] ?? '');
        $terms = array_map('strtolower', (array)($record['terms'] ?? []));
        foreach ($requested as $request_type) {
            if ($request_type === 'trail' && $type === 'st_activity' && $this->terms_contain($terms, ['trail', 'hiking'])) return true;
            if ($request_type === 'concert' && $type === 'st_activity' && $this->terms_contain($terms, ['concert', 'event'])) return true;
            if ($request_type === 'game' && $type === 'tng_game') return true;
            if ($request_type === 'top_sight' && $type === 'top_sight') return true;
            if ($request_type === 'destination' && $type === 'tng_destination') return true;
            if ($request_type === 'social' && $type === 'tng_social_item') return true;
            if ($request_type === 'event' && $type === 'tng_entity') return true;
            if ($request_type === 'page' && $type === 'page') return true;
        }
        return false;
    }

    private function terms_contain(array $terms, array $needles): bool {
        foreach ($terms as $term) foreach ($needles as $needle) if (strpos($term, $needle) !== false) return true;
        return false;
    }

    private function record_description(array $record): string {
        $flags = array_map(static fn(string $flag): string => str_replace('_', ' ', $flag), (array)$record['flags']);
        $detail = $flags ? implode(', ', $flags) : 'no quality flags';
        return sprintf('%s · %s · %s', $record['type'], $record['status'], $detail);
    }

    private function openai_plan(string $request, array $inventory) {
        $key = trim((string)get_option(self::KEY_OPTION, ''));
        if ($key === '') return new WP_Error('missing_key', 'No OpenAI key configured.');

        $records = array_map(static function (array $record): array {
            unset($record['excerpt_candidate']);
            return $record;
        }, $inventory['records']);

        $schema = [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'actions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['review_item', 'create_social_brief', 'set_excerpt_from_content', 'move_to_draft']],
                            'target_id' => ['type' => 'integer'],
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'preview' => ['type' => 'string'],
                            'confidence' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                        ],
                        'required' => ['type', 'target_id', 'title', 'description', 'preview', 'confidence'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['summary', 'actions'],
            'additionalProperties' => false,
        ];

        $instructions = implode(' ', [
            'You are the TN Game WordPress content operations planner for Tennessee tourism content.',
            'Return a concise review plan grounded only in the supplied inventory.',
            'Never invent a record ID, fact, date, coordinate, venue, or claim.',
            'Never propose publishing, deleting, bulk execution, credential changes, or edits to user data.',
            'Prefer review_item for uncertainty.',
            'create_social_brief must use target_id 0 and produce original high-level creative direction without factual claims.',
            'set_excerpt_from_content is allowed only for a supplied record flagged missing_excerpt.',
            'move_to_draft is allowed only for a published record flagged traveler_demo_wording.',
            'Return at most 12 actions. If the request is unrelated, return an empty actions array.',
        ]);
        $input = wp_json_encode(['request' => $request, 'inventory_counts' => $inventory['counts'], 'records' => $records]);
        $response = wp_remote_post('https://api.openai.com/v1/responses', [
            'timeout' => 45,
            'redirection' => 0,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => (string)get_option(self::MODEL_OPTION, 'gpt-5.6'),
                'instructions' => $instructions,
                'input' => $input,
                'store' => false,
                'max_output_tokens' => 2200,
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'tn_game_admin_plan',
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
            ]),
        ]);
        if (is_wp_error($response)) return $response;
        $status = (int)wp_remote_retrieve_response_code($response);
        $data = json_decode((string)wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            return new WP_Error('openai_error', 'The structured planner request failed.');
        }
        $text = '';
        foreach ((array)($data['output'] ?? []) as $item) {
            foreach ((array)($item['content'] ?? []) as $part) {
                if (($part['type'] ?? '') === 'output_text') $text .= (string)($part['text'] ?? '');
            }
        }
        $plan = json_decode($text, true);
        return is_array($plan) ? $plan : new WP_Error('invalid_plan', 'The structured planner returned an invalid plan.');
    }

    private function normalize_plan(array $plan, array $inventory): array {
        $records = [];
        foreach ($inventory['records'] as $record) $records[(int)$record['id']] = $record;
        $normalized = [];
        foreach (array_slice((array)($plan['actions'] ?? []), 0, 12) as $action) {
            if (!is_array($action)) continue;
            $type = sanitize_key((string)($action['type'] ?? 'review_item'));
            $target_id = absint($action['target_id'] ?? 0);
            if (!in_array($type, ['review_item', 'create_social_brief', 'set_excerpt_from_content', 'move_to_draft'], true)) continue;
            if ($type === 'create_social_brief') {
                $target_id = 0;
            } else {
                if (!$target_id || !isset($records[$target_id])) continue;
                if ($type === 'set_excerpt_from_content') {
                    if (!in_array('missing_excerpt', $records[$target_id]['flags'], true)) $type = 'review_item';
                    if (trim((string)($action['preview'] ?? '')) === '') $action['preview'] = $records[$target_id]['excerpt_candidate'];
                }
                if ($type === 'move_to_draft' && ($records[$target_id]['status'] !== 'publish' || !in_array('traveler_demo_wording', $records[$target_id]['flags'], true))) {
                    $type = 'review_item';
                }
            }
            $confidence = sanitize_key((string)($action['confidence'] ?? 'medium'));
            if (!in_array($confidence, ['low', 'medium', 'high'], true)) $confidence = 'medium';
            $normalized[] = [
                'type' => $type,
                'target_id' => $target_id,
                'title' => sanitize_text_field((string)($action['title'] ?? 'Review item')),
                'description' => sanitize_textarea_field((string)($action['description'] ?? '')),
                'preview' => sanitize_textarea_field((string)($action['preview'] ?? '')),
                'confidence' => $confidence,
            ];
        }
        $summary = sanitize_text_field((string)($plan['summary'] ?? 'Review plan ready'));
        return ['summary' => $summary ?: 'Review plan ready', 'actions' => $normalized];
    }

    private function create_social_brief(array $action) {
        if (!post_type_exists('tng_social_item')) return new WP_Error('missing_content_type', 'Content Studio is unavailable.');
        $title = sanitize_text_field((string)($action['title'] ?? 'TN Game content brief'));
        $preview = sanitize_textarea_field((string)($action['preview'] ?? ''));
        $post_id = wp_insert_post([
            'post_type' => 'tng_social_item',
            'post_status' => 'draft',
            'post_title' => $title ?: 'TN Game content brief',
            'post_content' => $preview,
            'post_author' => get_current_user_id(),
        ], true);
        if (is_wp_error($post_id)) return $post_id;
        update_post_meta($post_id, '_tng_plan_status', 'idea');
        update_post_meta($post_id, '_tng_permission_status', 'not_needed');
        update_post_meta($post_id, '_tng_content_notes', 'Created as a draft by TN Game AI Admin. Verify all facts, rights, and media before scheduling or publishing.');
        update_post_meta($post_id, '_tng_ai_admin_created', '1');
        return [
            'id' => wp_generate_uuid4(), 'kind' => 'create_social_brief', 'post_id' => (int)$post_id,
            'before' => null, 'label' => 'Created draft: ' . get_the_title($post_id),
        ];
    }

    private function set_excerpt(int $post_id, string $excerpt) {
        $post = get_post($post_id);
        if (!$post || !current_user_can('edit_post', $post_id) || trim((string)$post->post_excerpt) !== '') return new WP_Error('unsafe_excerpt', 'Excerpt cannot be changed safely.');
        $excerpt = sanitize_textarea_field($excerpt);
        if ($excerpt === '') return new WP_Error('empty_excerpt', 'No excerpt was proposed.');
        $result = wp_update_post(['ID' => $post_id, 'post_excerpt' => $excerpt], true);
        if (is_wp_error($result)) return $result;
        return [
            'id' => wp_generate_uuid4(), 'kind' => 'set_excerpt_from_content', 'post_id' => $post_id,
            'before' => '', 'label' => 'Added excerpt: ' . get_the_title($post_id),
        ];
    }

    private function move_to_draft(int $post_id) {
        $post = get_post($post_id);
        if (!$post || !current_user_can('edit_post', $post_id) || $post->post_status !== 'publish' || !$this->is_demo_wording($post)) {
            return new WP_Error('unsafe_status', 'Status cannot be changed safely.');
        }
        $result = wp_update_post(['ID' => $post_id, 'post_status' => 'draft'], true);
        if (is_wp_error($result)) return $result;
        return [
            'id' => wp_generate_uuid4(), 'kind' => 'move_to_draft', 'post_id' => $post_id,
            'before' => 'publish', 'label' => 'Moved to draft: ' . get_the_title($post_id),
        ];
    }

    private function record_log(array $entry): void {
        $entry['created_at'] = current_time('mysql');
        $entry['user_id'] = get_current_user_id();
        $log = get_option(self::LOG_OPTION, []);
        $log = is_array($log) ? $log : [];
        array_unshift($log, $entry);
        update_option(self::LOG_OPTION, array_slice($log, 0, 50), false);
    }

    private function redirect(string $notice): void {
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'tng_ai_notice' => $notice], admin_url('admin.php')));
        exit;
    }
}
