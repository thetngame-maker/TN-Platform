<?php

namespace TNG_OS\Modules\Entities;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Graph_Explorer implements Module_Interface {
    private const ENTITY_TYPE = 'tng_entity';
    private Container $container;

    public function id(): string { return 'graph_explorer'; }

    public function register(Container $container): void {
        $this->container = $container;
        $container->set('graph_explorer', $this);
        add_action('admin_menu', [$this, 'menu'], 27);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page(
            'tn-game-os',
            'Graph Explorer',
            'Graph Explorer',
            'edit_posts',
            'tng-graph-explorer',
            [$this, 'page']
        );
    }

    public function page(): void {
        if (!current_user_can('edit_posts')) return;

        $selected = isset($_GET['entity']) ? sanitize_text_field(wp_unslash($_GET['entity'])) : '';
        $entities = $this->entities();
        if ($selected === '' && $entities) $selected = (string)$entities[0]['id'];
        $graph = $this->graph($selected, $entities);
        ?>
        <div class="wrap tng-graph-explorer">
            <h1>Graph Explorer</h1>
            <p>Explore canonical entities and their relationships. Select a node to inspect it, then expand outward through the destination graph.</p>

            <div class="tng-graph-toolbar">
                <form method="get">
                    <input type="hidden" name="page" value="tng-graph-explorer">
                    <label for="tng-graph-entity"><strong>Start entity</strong></label>
                    <select id="tng-graph-entity" name="entity">
                        <?php foreach ($entities as $entity): ?>
                            <option value="<?php echo esc_attr($entity['id']); ?>" <?php selected($selected, $entity['id']); ?>>
                                <?php echo esc_html($entity['title'] . ' · ' . $entity['type'] . ' · v' . $entity['version']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button button-primary">Load graph</button>
                </form>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-entity-explorer')); ?>">Entity Explorer</a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-platform-health')); ?>">Platform Health</a>
            </div>

            <?php if (!$entities): ?>
                <div class="notice notice-info inline"><p>No canonical entities exist yet. Publish a concert through Review Studio first.</p></div>
            <?php else: ?>
                <div class="tng-graph-layout">
                    <section class="tng-graph-canvas-card">
                        <div class="tng-graph-legend">
                            <span><i class="event"></i> Event</span>
                            <span><i class="venue"></i> Venue</span>
                            <span><i class="other"></i> Other</span>
                        </div>
                        <div id="tng-graph-canvas" class="tng-graph-canvas" role="application" aria-label="Entity relationship graph"></div>
                    </section>
                    <aside id="tng-graph-inspector" class="tng-graph-inspector">
                        <h2>Entity Inspector</h2>
                        <p>Select a graph node.</p>
                    </aside>
                </div>
            <?php endif; ?>
        </div>
        <style>
            .tng-graph-toolbar{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin:18px 0}.tng-graph-toolbar form{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.tng-graph-toolbar select{min-width:360px;max-width:60vw}.tng-graph-layout{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:18px}.tng-graph-canvas-card,.tng-graph-inspector{background:#fff;border:1px solid #dcdcde;border-radius:10px;box-shadow:0 1px 2px rgba(0,0,0,.05)}.tng-graph-canvas-card{overflow:hidden}.tng-graph-canvas{position:relative;min-height:620px;background:radial-gradient(circle at center,#f8fafc 0,#eef2f7 100%);overflow:auto}.tng-graph-inspector{padding:18px;overflow-wrap:anywhere}.tng-graph-inspector dl{display:grid;grid-template-columns:90px 1fr;gap:8px;margin:0}.tng-graph-inspector dt{font-weight:700}.tng-graph-inspector dd{margin:0}.tng-graph-inspector pre{white-space:pre-wrap;background:#f6f7f7;padding:10px;border-radius:6px;max-height:250px;overflow:auto}.tng-graph-legend{display:flex;gap:16px;padding:12px 16px;border-bottom:1px solid #dcdcde}.tng-graph-legend i{display:inline-block;width:11px;height:11px;border-radius:50%;margin-right:5px}.tng-graph-legend .event{background:#2271b1}.tng-graph-legend .venue{background:#8c5ac7}.tng-graph-legend .other{background:#646970}.tng-graph-node{position:absolute;width:180px;min-height:82px;padding:12px;border:2px solid #646970;border-radius:12px;background:#fff;box-shadow:0 4px 14px rgba(0,0,0,.12);cursor:pointer;text-align:left;z-index:2}.tng-graph-node:hover,.tng-graph-node.active{outline:3px solid rgba(34,113,177,.2);transform:translateY(-1px)}.tng-graph-node.event{border-color:#2271b1}.tng-graph-node.venue{border-color:#8c5ac7}.tng-graph-node strong,.tng-graph-node span{display:block}.tng-graph-node span{font-size:12px;color:#646970;margin-top:5px}.tng-graph-edge{position:absolute;height:2px;background:#8c8f94;transform-origin:0 0;z-index:1}.tng-graph-edge-label{position:absolute;background:#fff;border:1px solid #dcdcde;border-radius:999px;padding:3px 8px;font-size:11px;z-index:3;white-space:nowrap}@media(max-width:1000px){.tng-graph-layout{grid-template-columns:1fr}.tng-graph-inspector{order:-1}.tng-graph-toolbar select{min-width:260px;max-width:100%}}
        </style>
        <?php if ($entities): ?>
        <script>
        (() => {
            const data = <?php echo wp_json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
            const canvas = document.getElementById('tng-graph-canvas');
            const inspector = document.getElementById('tng-graph-inspector');
            if (!canvas || !inspector) return;

            const centerX = 420, centerY = 270, radius = 230;
            const nodes = data.nodes || [];
            const edges = data.edges || [];
            const positions = {};

            nodes.forEach((node, index) => {
                if (node.id === data.root) positions[node.id] = {x:centerX-90,y:centerY-41};
                else {
                    const others = Math.max(1, nodes.length - 1);
                    const angle = ((index - (nodes[0]?.id === data.root ? 1 : 0)) / others) * Math.PI * 2 - Math.PI/2;
                    positions[node.id] = {x:centerX + Math.cos(angle)*radius - 90,y:centerY + Math.sin(angle)*radius - 41};
                }
            });

            edges.forEach(edge => {
                const a = positions[edge.source], b = positions[edge.target];
                if (!a || !b) return;
                const ax=a.x+90, ay=a.y+41, bx=b.x+90, by=b.y+41;
                const dx=bx-ax, dy=by-ay, length=Math.sqrt(dx*dx+dy*dy), angle=Math.atan2(dy,dx)*180/Math.PI;
                const line=document.createElement('div');
                line.className='tng-graph-edge';
                line.style.cssText=`left:${ax}px;top:${ay}px;width:${length}px;transform:rotate(${angle}deg)`;
                canvas.appendChild(line);
                const label=document.createElement('div');
                label.className='tng-graph-edge-label';
                label.textContent=edge.type;
                label.style.cssText=`left:${(ax+bx)/2-28}px;top:${(ay+by)/2-12}px`;
                canvas.appendChild(label);
            });

            const show = node => {
                document.querySelectorAll('.tng-graph-node').forEach(el => el.classList.toggle('active', el.dataset.id === node.id));
                const relationships = edges.filter(edge => edge.source === node.id || edge.target === node.id);
                inspector.innerHTML = `<h2>${escapeHtml(node.title)}</h2><dl><dt>ID</dt><dd><code>${escapeHtml(node.id)}</code></dd><dt>Type</dt><dd>${escapeHtml(node.type)}</dd><dt>Version</dt><dd>${node.version}</dd><dt>Lifecycle</dt><dd>${escapeHtml(node.lifecycle || '—')}</dd><dt>Source</dt><dd>${escapeHtml(node.source_key || '—')}</dd><dt>Links</dt><dd>${relationships.length}</dd></dl><h3>Relationships</h3>${relationships.length ? '<ul>'+relationships.map(edge=>`<li><code>${escapeHtml(edge.type)}</code> ${edge.source===node.id?'→':'←'} ${escapeHtml(edge.source===node.id?edge.target:edge.source)}</li>`).join('')+'</ul>' : '<p>No relationships.</p>'}<h3>Canonical payload</h3><pre>${escapeHtml(JSON.stringify(node.payload || {}, null, 2))}</pre>`;
            };
            const escapeHtml = value => String(value).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));

            nodes.forEach(node => {
                const el=document.createElement('button');
                el.type='button'; el.className=`tng-graph-node ${node.type}`; el.dataset.id=node.id;
                el.style.left=positions[node.id].x+'px'; el.style.top=positions[node.id].y+'px';
                el.innerHTML=`<strong>${escapeHtml(node.title)}</strong><span>${escapeHtml(node.type)} · v${node.version}</span>`;
                el.addEventListener('click',()=>show(node));
                canvas.appendChild(el);
            });
            const rootNode=nodes.find(node=>node.id===data.root) || nodes[0];
            if (rootNode) show(rootNode);
        })();
        </script>
        <?php endif;
    }

    private function entities(): array {
        $posts = get_posts([
            'post_type' => self::ENTITY_TYPE,
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => 300,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        $entities = [];
        foreach ($posts as $post) {
            $id = (string)get_post_meta($post->ID, '_tng_entity_id', true);
            if ($id === '') continue;
            $entities[] = [
                'post_id' => (int)$post->ID,
                'id' => $id,
                'title' => $post->post_title ?: $id,
                'type' => (string)get_post_meta($post->ID, '_tng_entity_type', true) ?: 'other',
                'version' => max(1, absint(get_post_meta($post->ID, '_tng_entity_version', true))),
                'lifecycle' => (string)get_post_meta($post->ID, '_tng_entity_lifecycle', true),
                'source_key' => (string)get_post_meta($post->ID, '_tng_entity_source_key', true),
                'payload' => (array)get_post_meta($post->ID, '_tng_entity_payload', true),
                'relationships' => (array)get_post_meta($post->ID, '_tng_entity_relationships', true),
            ];
        }
        return $entities;
    }

    private function graph(string $root, array $entities): array {
        $by_id = [];
        foreach ($entities as $entity) $by_id[$entity['id']] = $entity;
        if (!isset($by_id[$root])) $root = (string)array_key_first($by_id);

        $node_ids = [$root => true];
        $edges = [];
        $queue = [[$root, 0]];

        while ($queue) {
            [$current, $depth] = array_shift($queue);
            if ($depth >= 2 || !isset($by_id[$current])) continue;
            foreach ((array)$by_id[$current]['relationships'] as $relationship) {
                if (!is_array($relationship)) continue;
                $source = sanitize_text_field((string)($relationship['source_entity_id'] ?? $current));
                $target = sanitize_text_field((string)($relationship['target_entity_id'] ?? ''));
                $type = sanitize_key((string)($relationship['type'] ?? 'related_to'));
                if ($source === '' || $target === '') continue;
                $key = $source . '|' . $type . '|' . $target;
                $edges[$key] = ['source' => $source, 'target' => $target, 'type' => $type];
                foreach ([$source, $target] as $candidate) {
                    if (isset($by_id[$candidate]) && !isset($node_ids[$candidate])) {
                        $node_ids[$candidate] = true;
                        $queue[] = [$candidate, $depth + 1];
                    }
                }
            }
        }

        $nodes = [];
        foreach (array_keys($node_ids) as $id) {
            if (!isset($by_id[$id])) continue;
            $node = $by_id[$id];
            unset($node['relationships'], $node['post_id']);
            $nodes[] = $node;
        }

        return ['root' => $root, 'nodes' => $nodes, 'edges' => array_values($edges)];
    }
}
