<?php
namespace TNG_OS\Modules\Entities;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Destination_Intelligence implements Module_Interface {
    private Container $container;

    public function id(): string { return 'destination_intelligence'; }

    public function register(Container $container): void {
        $this->container = $container;
        $container->set('destination_intelligence', $this);
        add_action('admin_menu', [$this, 'menu'], 29);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page('tn-game-os', 'Destination Intelligence', 'Destination Intelligence', 'manage_options', 'tng-destination-intelligence', [$this, 'page']);
    }

    public function page(): void {
        if (!current_user_can('manage_options')) return;
        $engine = $this->container->get('recommendation_engine');
        if (!$engine || !is_callable([$engine, 'entities'])) {
            echo '<div class="wrap"><h1>Destination Intelligence</h1><div class="notice notice-error"><p>Recommendation Engine is unavailable.</p></div></div>';
            return;
        }

        $entities = $engine->entities();
        $analysis = $this->analyze($entities);
        ?>
        <div class="wrap tng-di">
            <style>
                .tng-di{max-width:1500px}.tng-di-hero{background:linear-gradient(135deg,#102542,#1f4f78);color:#fff;border-radius:18px;padding:28px 30px;margin:18px 0;box-shadow:0 12px 35px rgba(16,37,66,.18)}.tng-di-hero h1{color:#fff;margin:0 0 6px;font-size:30px}.tng-di-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.tng-di-card{background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:18px}.tng-di-stat strong{display:block;font-size:30px;color:#102542;margin-top:5px}.tng-di-layout{display:grid;grid-template-columns:minmax(0,1fr) 420px;gap:18px;margin-top:18px}.tng-di-table{width:100%;border-collapse:collapse}.tng-di-table th,.tng-di-table td{padding:10px 8px;border-bottom:1px solid #e5e7eb;text-align:left}.tng-di-score{font-weight:800}.tng-di-badge{display:inline-flex;border-radius:999px;padding:4px 9px;font-size:12px;background:#eef2ff}.tng-di-badge.good{background:#ecfdf3;color:#067647}.tng-di-badge.warn{background:#fff7ed;color:#b54708}.tng-di-badge.bad{background:#fef3f2;color:#b42318}.tng-di-bar{height:8px;background:#edf1f5;border-radius:999px;overflow:hidden}.tng-di-bar span{display:block;height:100%;background:#4f46e5}.tng-di-list{display:grid;gap:10px}.tng-di-item{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid #eef0f3}.tng-di-muted{color:#646970}.tng-di-section-title{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px}@media(max-width:1100px){.tng-di-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.tng-di-layout{grid-template-columns:1fr}}@media(max-width:700px){.tng-di-grid{grid-template-columns:1fr}}
            </style>
            <div class="tng-di-hero"><p style="text-transform:uppercase;letter-spacing:.12em;margin:0 0 8px;color:#f6bd3b;font-weight:700">TN Platform · Destination Intelligence</p><h1>Graph Health</h1><p>Measure connectivity, metadata coverage, relationship quality, and the readiness of every destination entity.</p></div>

            <div class="tng-di-grid">
                <div class="tng-di-card tng-di-stat"><span>Total entities</span><strong><?php echo esc_html(number_format_i18n($analysis['total'])); ?></strong></div>
                <div class="tng-di-card tng-di-stat"><span>Connected entities</span><strong><?php echo esc_html($analysis['connected_percent']); ?>%</strong></div>
                <div class="tng-di-card tng-di-stat"><span>Orphaned entities</span><strong><?php echo esc_html(number_format_i18n(count($analysis['orphans']))); ?></strong></div>
                <div class="tng-di-card tng-di-stat"><span>Average graph score</span><strong><?php echo esc_html($analysis['average_score']); ?>/100</strong></div>
            </div>

            <div class="tng-di-layout">
                <main class="tng-di-card">
                    <div class="tng-di-section-title"><div><h2 style="margin:0">Entity health</h2><p class="tng-di-muted">Lowest-scoring entities appear first so content gaps are easy to prioritize.</p></div></div>
                    <table class="tng-di-table"><thead><tr><th>Entity</th><th>Type</th><th>Graph score</th><th>Relationships</th><th>Needs attention</th></tr></thead><tbody>
                    <?php foreach ($analysis['entity_rows'] as $row): ?>
                        <tr><td><strong><?php echo esc_html($row['title']); ?></strong><br><code><?php echo esc_html($row['id']); ?></code></td><td><?php echo esc_html($row['type']); ?></td><td><span class="tng-di-score"><?php echo esc_html((string)$row['score']); ?></span><div class="tng-di-bar"><span style="width:<?php echo esc_attr((string)$row['score']); ?>%"></span></div></td><td><?php echo esc_html((string)$row['relationship_count']); ?></td><td><?php if (!$row['issues']): ?><span class="tng-di-badge good">Healthy</span><?php else: foreach (array_slice($row['issues'],0,3) as $issue): ?><span class="tng-di-badge <?php echo esc_attr($issue === 'No relationships' ? 'bad' : 'warn'); ?>"><?php echo esc_html($issue); ?></span> <?php endforeach; endif; ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                </main>

                <aside class="tng-di-list">
                    <section class="tng-di-card"><h2 style="margin-top:0">Coverage</h2>
                        <?php foreach ($analysis['coverage'] as $label => $value): ?><div class="tng-di-item"><span><?php echo esc_html($label); ?></span><strong><?php echo esc_html((string)$value); ?>%</strong></div><?php endforeach; ?>
                    </section>
                    <section class="tng-di-card"><h2 style="margin-top:0">Relationship inventory</h2>
                        <?php if (!$analysis['relationship_types']): ?><p>No relationships found.</p><?php endif; ?>
                        <?php foreach (array_slice($analysis['relationship_types'],0,12,true) as $type => $count): ?><div class="tng-di-item"><code><?php echo esc_html($type); ?></code><strong><?php echo esc_html(number_format_i18n($count)); ?></strong></div><?php endforeach; ?>
                    </section>
                    <section class="tng-di-card"><h2 style="margin-top:0">Disconnected entities</h2>
                        <?php if (!$analysis['orphans']): ?><p><span class="tng-di-badge good">No orphaned entities</span></p><?php endif; ?>
                        <?php foreach (array_slice($analysis['orphans'],0,12) as $orphan): ?><div class="tng-di-item"><span><strong><?php echo esc_html($orphan['title']); ?></strong><br><small><?php echo esc_html($orphan['type']); ?></small></span><span class="tng-di-badge bad">Orphan</span></div><?php endforeach; ?>
                    </section>
                </aside>
            </div>
        </div>
        <?php
    }

    private function analyze(array $entities): array {
        $relationship_types = [];
        $degree = array_fill_keys(array_keys($entities), 0);
        foreach ($entities as $entity) {
            foreach ((array)$entity['relationships'] as $rel) {
                if (!is_array($rel)) continue;
                $source = (string)($rel['source_entity_id'] ?? $entity['id']);
                $target = (string)($rel['target_entity_id'] ?? '');
                $type = sanitize_key((string)($rel['type'] ?? 'related_to'));
                if ($source === '' || $target === '') continue;
                $relationship_types[$type] = ($relationship_types[$type] ?? 0) + 1;
                if (isset($degree[$source])) $degree[$source]++;
                if (isset($degree[$target])) $degree[$target]++;
            }
        }
        arsort($relationship_types);

        $rows = []; $orphans = []; $with_coords = 0; $with_image = 0; $with_description = 0; $with_url = 0; $score_total = 0;
        foreach ($entities as $id => $entity) {
            $payload = (array)$entity['payload'];
            $issues = []; $score = 20;
            $relationships = (int)($degree[$id] ?? 0);
            if ($relationships > 0) $score += min(35, $relationships * 7); else $issues[] = 'No relationships';

            $coords = $this->has_coordinates($payload); if ($coords) { $score += 15; $with_coords++; } else $issues[] = 'Missing coordinates';
            $image = $this->has_value($payload, ['image','image_url','featured_image']) || $this->linked_post_has_image($payload); if ($image) { $score += 10; $with_image++; } else $issues[] = 'Missing image';
            $description = $this->has_value($payload, ['description','summary','excerpt']); if ($description) { $score += 10; $with_description++; } else $issues[] = 'Missing description';
            $url = $this->has_value($payload, ['url','permalink']) || $this->linked_post_exists($payload); if ($url) { $score += 10; $with_url++; } else $issues[] = 'Missing URL';
            $score = min(100, $score);
            $score_total += $score;
            $row = ['id'=>$id,'title'=>$entity['title'],'type'=>$entity['type'],'score'=>$score,'relationship_count'=>$relationships,'issues'=>$issues];
            $rows[] = $row;
            if ($relationships === 0) $orphans[] = $row;
        }
        usort($rows, static fn(array $a,array $b): int => $a['score'] <=> $b['score']);
        $total = count($entities);
        $connected = $total - count($orphans);
        $pct = static fn(int $n): int => $total ? (int)round($n / $total * 100) : 0;
        return [
            'total'=>$total,
            'connected_percent'=>$pct($connected),
            'average_score'=>$total ? (int)round($score_total / $total) : 0,
            'orphans'=>$orphans,
            'relationship_types'=>$relationship_types,
            'entity_rows'=>$rows,
            'coverage'=>[
                'Coordinates'=>$pct($with_coords),
                'Images'=>$pct($with_image),
                'Descriptions'=>$pct($with_description),
                'Public URLs'=>$pct($with_url),
            ],
        ];
    }

    private function has_coordinates(array $payload): bool {
        $lat = $payload['latitude'] ?? $payload['lat'] ?? null;
        $lng = $payload['longitude'] ?? $payload['lng'] ?? $payload['lon'] ?? null;
        if (is_numeric($lat) && is_numeric($lng)) return true;
        if (!empty($payload['coordinates']) && is_array($payload['coordinates'])) {
            return is_numeric($payload['coordinates']['lat'] ?? $payload['coordinates']['latitude'] ?? null) && is_numeric($payload['coordinates']['lng'] ?? $payload['coordinates']['longitude'] ?? null);
        }
        return false;
    }

    private function has_value(array $payload, array $keys): bool {
        foreach ($keys as $key) if (!empty($payload[$key])) return true;
        return false;
    }

    private function linked_post_exists(array $payload): bool {
        foreach (['traveler_activity_id','post_id','wp_post_id'] as $key) if (absint($payload[$key] ?? 0) && get_post_status(absint($payload[$key]))) return true;
        return false;
    }

    private function linked_post_has_image(array $payload): bool {
        foreach (['traveler_activity_id','post_id','wp_post_id'] as $key) { $id = absint($payload[$key] ?? 0); if ($id && has_post_thumbnail($id)) return true; }
        return false;
    }
}
