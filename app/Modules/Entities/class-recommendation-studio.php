<?php
namespace TNG_OS\Modules\Entities;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Recommendation_Studio implements Module_Interface {
    private Container $container;

    public function id(): string { return 'recommendation_studio'; }

    public function register(Container $container): void {
        $this->container = $container;
        $container->set('recommendation_studio', $this);
        add_action('admin_menu', [$this, 'menu'], 28);
        add_action('admin_post_tng_save_recommendation_weights', [$this, 'save_weights']);
        add_action('admin_post_tng_reset_recommendation_weights', [$this, 'reset_weights']);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page('tn-game-os', 'Recommendation Studio', 'Recommendation Studio', 'manage_options', 'tng-recommendation-studio', [$this, 'page']);
    }

    public function save_weights(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tng_save_recommendation_weights');
        $engine = $this->engine();
        if ($engine) $engine->save_relationship_weights((array)($_POST['weights'] ?? []));
        wp_safe_redirect(add_query_arg(['page'=>'tng-recommendation-studio','updated'=>'1'], admin_url('admin.php')));
        exit;
    }

    public function reset_weights(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tng_reset_recommendation_weights');
        $engine = $this->engine();
        if ($engine) $engine->reset_relationship_weights();
        wp_safe_redirect(add_query_arg(['page'=>'tng-recommendation-studio','reset'=>'1'], admin_url('admin.php')));
        exit;
    }

    public function page(): void {
        if (!current_user_can('manage_options')) return;
        $engine = $this->engine();
        if (!$engine) { echo '<div class="wrap"><h1>Recommendation Studio</h1><div class="notice notice-error"><p>Recommendation Engine is unavailable.</p></div></div>'; return; }

        $entities = $engine->entities();
        $selected = isset($_GET['entity']) ? sanitize_text_field(wp_unslash($_GET['entity'])) : '';
        if ($selected === '' && $entities) $selected = (string)array_key_first($entities);
        $depth = max(1, min(3, absint($_GET['depth'] ?? 2)));
        $limit = max(1, min(30, absint($_GET['limit'] ?? 12)));
        $types = isset($_GET['types']) ? array_values(array_filter(array_map('sanitize_key', explode(',', sanitize_text_field(wp_unslash($_GET['types'])))))) : [];
        $results = $selected !== '' ? $engine->recommend($selected, ['depth'=>$depth,'limit'=>$limit,'types'=>$types,'require_url'=>false]) : [];
        $weights = $engine->relationship_weights();
        ?>
        <div class="wrap tng-rec-studio">
            <style>
                .tng-rec-studio{max-width:1500px}.tng-rs-hero{background:#162747;color:#fff;border-radius:16px;padding:24px 28px;margin:18px 0}.tng-rs-hero h1{color:#fff;margin:0 0 6px}.tng-rs-toolbar,.tng-rs-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px}.tng-rs-toolbar form{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.tng-rs-toolbar label{display:grid;gap:5px;font-weight:600}.tng-rs-toolbar select{min-width:330px}.tng-rs-layout{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:18px;margin-top:18px}.tng-rs-results{display:grid;gap:12px}.tng-rs-result{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px}.tng-rs-result-head{display:flex;justify-content:space-between;gap:16px}.tng-rs-score{display:inline-flex;align-items:center;justify-content:center;min-width:58px;height:58px;border-radius:50%;background:#162747;color:#fff;font-size:20px;font-weight:700}.tng-rs-badges{display:flex;gap:6px;flex-wrap:wrap;margin:10px 0}.tng-rs-badge{background:#eef2ff;border-radius:999px;padding:4px 9px;font-size:12px}.tng-rs-table{width:100%;border-collapse:collapse}.tng-rs-table th,.tng-rs-table td{padding:8px 6px;border-bottom:1px solid #e4e6eb;text-align:left}.tng-rs-points{font-weight:700;text-align:right!important}.tng-rs-path{display:flex;gap:7px;align-items:center;flex-wrap:wrap;margin-top:10px}.tng-rs-node{background:#f6f7f7;border-radius:7px;padding:5px 8px}.tng-rs-edge{color:#646970;font-size:12px}.tng-rs-weights input{width:84px}.tng-rs-actions{display:flex;gap:8px;margin-top:14px}@media(max-width:1000px){.tng-rs-layout{grid-template-columns:1fr}.tng-rs-toolbar select{min-width:240px}}
            </style>
            <div class="tng-rs-hero"><p style="text-transform:uppercase;letter-spacing:.12em;margin:0 0 8px;color:#f6bd3b;font-weight:700">TN Platform · Recommendation Engine</p><h1>Recommendation Studio</h1><p>Inspect rankings, score calculations, graph paths, and tune relationship weights.</p></div>
            <?php if (isset($_GET['updated'])): ?><div class="notice notice-success inline"><p>Recommendation weights saved.</p></div><?php endif; ?>
            <?php if (isset($_GET['reset'])): ?><div class="notice notice-success inline"><p>Recommendation weights reset to defaults.</p></div><?php endif; ?>

            <div class="tng-rs-toolbar"><form method="get">
                <input type="hidden" name="page" value="tng-recommendation-studio">
                <label>Analyze entity<select name="entity"><?php foreach($entities as $entity): ?><option value="<?php echo esc_attr($entity['id']); ?>" <?php selected($selected,$entity['id']); ?>><?php echo esc_html($entity['title'].' · '.$entity['type']); ?></option><?php endforeach; ?></select></label>
                <label>Depth<select name="depth"><option value="1" <?php selected($depth,1); ?>>1 hop</option><option value="2" <?php selected($depth,2); ?>>2 hops</option><option value="3" <?php selected($depth,3); ?>>3 hops</option></select></label>
                <label>Limit<input type="number" min="1" max="30" name="limit" value="<?php echo esc_attr((string)$limit); ?>"></label>
                <label>Types<input type="text" name="types" value="<?php echo esc_attr(implode(',',$types)); ?>" placeholder="restaurant,trail,hotel"></label>
                <button class="button button-primary">Analyze</button>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-graph-explorer&entity='.rawurlencode($selected))); ?>">Open Graph Explorer</a>
            </form></div>

            <div class="tng-rs-layout"><main class="tng-rs-results">
                <div class="tng-rs-card"><h2 style="margin-top:0">Ranked recommendations</h2><p><?php echo esc_html(number_format_i18n(count($results))); ?> results for <code><?php echo esc_html($selected); ?></code></p></div>
                <?php if(!$results): ?><div class="tng-rs-card"><p>No recommendations were found. Add relationships or increase traversal depth.</p></div><?php endif; ?>
                <?php foreach($results as $index=>$item): ?>
                    <article class="tng-rs-result">
                        <div class="tng-rs-result-head"><div><small>#<?php echo esc_html((string)($index+1)); ?> · <?php echo esc_html($item['type']); ?></small><h2 style="margin:4px 0"><?php echo esc_html($item['title']); ?></h2><code><?php echo esc_html($item['id']); ?></code></div><div class="tng-rs-score" title="Recommendation score"><?php echo esc_html((string)$item['score']); ?></div></div>
                        <div class="tng-rs-badges"><?php foreach((array)$item['reasons'] as $reason): ?><span class="tng-rs-badge"><?php echo esc_html($reason); ?></span><?php endforeach; ?><?php if($item['distance_miles']!==null): ?><span class="tng-rs-badge"><?php echo esc_html(number_format_i18n((float)$item['distance_miles'],1)); ?> miles</span><?php endif; ?></div>
                        <details><summary><strong>Score breakdown</strong></summary><table class="tng-rs-table"><thead><tr><th>Factor</th><th class="tng-rs-points">Points</th></tr></thead><tbody><?php foreach((array)$item['breakdown'] as $factor): ?><tr><td><?php echo esc_html((string)$factor['label']); ?></td><td class="tng-rs-points"><?php echo ((int)$factor['points']>0?'+':'').esc_html((string)$factor['points']); ?></td></tr><?php endforeach; ?></tbody></table></details>
                        <div class="tng-rs-path"><span class="tng-rs-node"><?php echo esc_html($entities[$selected]['title'] ?? $selected); ?></span><?php foreach((array)$item['path'] as $edge): ?><span class="tng-rs-edge">→ <?php echo esc_html((string)$edge['type']); ?> →</span><span class="tng-rs-node"><?php echo esc_html($entities[$edge['to_entity_id']]['title'] ?? $edge['to_entity_id']); ?></span><?php endforeach; ?></div>
                    </article>
                <?php endforeach; ?>
            </main><aside class="tng-rs-card tng-rs-weights"><h2 style="margin-top:0">Relationship weights</h2><p>Adjust the base value of each graph relationship. Changes affect frontend rankings immediately.</p><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="tng_save_recommendation_weights"><?php wp_nonce_field('tng_save_recommendation_weights'); ?><table class="tng-rs-table"><thead><tr><th>Relationship</th><th>Weight</th></tr></thead><tbody><?php foreach($weights as $type=>$weight): ?><tr><td><code><?php echo esc_html($type); ?></code></td><td><input type="number" min="0" max="500" name="weights[<?php echo esc_attr($type); ?>]" value="<?php echo esc_attr((string)$weight); ?>"></td></tr><?php endforeach; ?></tbody></table><div class="tng-rs-actions"><button class="button button-primary">Save weights</button></div></form><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px"><input type="hidden" name="action" value="tng_reset_recommendation_weights"><?php wp_nonce_field('tng_reset_recommendation_weights'); ?><button class="button">Reset defaults</button></form></aside></div>
        </div>
        <?php
    }

    private function engine(): ?Recommendation_Engine {
        $engine = $this->container->get('recommendation_engine');
        return $engine instanceof Recommendation_Engine ? $engine : null;
    }
}
