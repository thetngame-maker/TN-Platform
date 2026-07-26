<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Quest_Runtime implements Module_Interface {
    private const QUEST_TYPE = 'tng_quest';
    private Container $container;

    public function id(): string { return 'quest_runtime'; }

    public function register(Container $container): void {
        $this->container = $container;
        $container->set('quest_runtime', $this);
        add_shortcode('tng_quest_runtime', [$this, 'shortcode']);
        add_filter('the_content', [$this, 'append_runtime']);
    }

    public function boot(Container $container): void {}

    public function append_runtime(string $content): string {
        if (!is_singular(self::QUEST_TYPE) || !in_the_loop() || !is_main_query()) return $content;
        return $content . $this->render((int)get_the_ID());
    }

    public function shortcode(array $atts = []): string {
        $atts = shortcode_atts(['id' => 0], $atts, 'tng_quest_runtime');
        $id = absint($atts['id']);
        if (!$id && is_singular(self::QUEST_TYPE)) $id = (int)get_the_ID();
        return $this->render($id);
    }

    private function render(int $quest_id): string {
        $quest = $quest_id ? get_post($quest_id) : null;
        if (!$quest || $quest->post_type !== self::QUEST_TYPE) return '';
        if ($quest->post_status !== 'publish' && !current_user_can('edit_post', $quest_id)) return '';

        $entities = $this->entities();
        $ids = (array)get_post_meta($quest_id, '_tng_quest_entity_ids', true);
        $notes = (array)get_post_meta($quest_id, '_tng_quest_checkpoint_instructions', true);
        $mechanics = (array)get_post_meta($quest_id, '_tng_game_checkpoint_mechanics', true);
        $xp = absint(get_post_meta($quest_id, '_tng_quest_xp', true) ?: get_post_meta($quest_id, '_tng_quest_estimated_xp', true));
        $minutes = absint(get_post_meta($quest_id, '_tng_quest_estimated_minutes', true));
        $summary = (string)get_post_meta($quest_id, '_tng_quest_summary', true);
        $mode = sanitize_key((string)get_post_meta($quest_id, '_tng_game_completion_mode', true)) ?: 'all';
        $configured = absint(get_post_meta($quest_id, '_tng_game_completion_count', true));
        $stops = [];

        foreach ($ids as $entity_id) {
            $key = (string)$entity_id;
            if (!isset($entities[$key])) continue;
            $entity = $entities[$key];
            $m = is_array($mechanics[$key] ?? null) ? $mechanics[$key] : [];
            $stops[] = [
                'id' => $key,
                'title' => (string)($entity['title'] ?? 'Checkpoint'),
                'type' => sanitize_key((string)($m['type'] ?? 'manual')),
                'instruction' => (string)($m['challenge'] ?? $notes[$key] ?? ''),
                'arrival' => (string)($m['arrival_message'] ?? ''),
                'xp' => absint($m['xp'] ?? 25),
            ];
        }

        $required = $mode === 'count' ? min(count($stops), max(1, $configured)) : count($stops);
        $payload = [
            'questId' => $quest_id,
            'title' => get_the_title($quest_id),
            'required' => $required,
            'rewardXp' => $xp,
            'stops' => $stops,
        ];

        ob_start(); ?>
        <section class="tng-runtime" data-quest-id="<?php echo esc_attr((string)$quest_id); ?>">
            <style>
                .tng-runtime{--ink:#18213d;--accent:#7f56d9;--soft:#f4f0ff;max-width:860px;margin:28px auto;font-family:inherit;color:var(--ink)}
                .tng-runtime *{box-sizing:border-box}.tng-runtime-hero{background:linear-gradient(135deg,#18213d,#633b78);color:#fff;border-radius:24px;padding:30px;box-shadow:0 18px 45px rgba(24,33,61,.18)}
                .tng-runtime-kicker{text-transform:uppercase;letter-spacing:.12em;color:#f6bd3b;font-weight:800;font-size:12px}.tng-runtime h2{color:#fff;font-size:30px;margin:8px 0}.tng-runtime-meta{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0}
                .tng-runtime-pill{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.18);border-radius:999px;padding:7px 11px;font-weight:700;font-size:13px}.tng-runtime-start{border:0;border-radius:12px;background:#fff;color:var(--ink);font-weight:800;padding:13px 18px;cursor:pointer}
                .tng-runtime-panel{background:#fff;border:1px solid #e4e7ec;border-radius:18px;padding:20px;margin-top:16px}.tng-runtime-progress{height:11px;background:#eceff3;border-radius:999px;overflow:hidden}.tng-runtime-progress span{display:block;height:100%;width:0;background:#12b76a;transition:.25s}
                .tng-runtime-status{display:flex;justify-content:space-between;gap:12px;margin:10px 0 18px;font-weight:700}.tng-runtime-list{display:grid;gap:12px}.tng-runtime-stop{border:1px solid #e5e7eb;border-radius:15px;padding:15px;display:grid;grid-template-columns:40px minmax(0,1fr) auto;gap:12px;align-items:start;background:#fff}
                .tng-runtime-stop.is-locked{opacity:.58}.tng-runtime-stop.is-done{background:#ecfdf3;border-color:#abefc6}.tng-runtime-num{width:36px;height:36px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900}.tng-runtime-stop h3{margin:0 0 4px;font-size:17px}.tng-runtime-stop p{margin:4px 0;color:#475467}.tng-runtime-claim{border:0;border-radius:10px;background:var(--accent);color:#fff;padding:10px 12px;font-weight:800;cursor:pointer}.tng-runtime-claim:disabled{background:#98a2b3;cursor:not-allowed}.tng-runtime-complete{display:none;text-align:center;background:var(--soft);border:1px solid #d9d0ff;border-radius:18px;padding:24px;margin-top:16px}.tng-runtime-complete.is-visible{display:block}@media(max-width:650px){.tng-runtime{margin:16px 0}.tng-runtime-hero{border-radius:18px;padding:23px}.tng-runtime-stop{grid-template-columns:36px 1fr}.tng-runtime-claim{grid-column:1/-1;width:100%}}
            </style>
            <div class="tng-runtime-hero">
                <div class="tng-runtime-kicker">TN Game Quest</div>
                <h2><?php echo esc_html(get_the_title($quest_id)); ?></h2>
                <p><?php echo esc_html($summary ?: wp_strip_all_tags($quest->post_content)); ?></p>
                <div class="tng-runtime-meta"><span class="tng-runtime-pill"><?php echo esc_html((string)count($stops)); ?> checkpoints</span><span class="tng-runtime-pill"><?php echo esc_html(number_format_i18n($xp)); ?> XP</span><span class="tng-runtime-pill"><?php echo esc_html($this->duration_label($minutes)); ?></span></div>
                <button type="button" class="tng-runtime-start">Start Quest</button>
            </div>
            <div class="tng-runtime-panel" hidden>
                <div class="tng-runtime-progress"><span></span></div>
                <div class="tng-runtime-status"><span><b data-complete>0</b> of <b><?php echo esc_html((string)$required); ?></b> required</span><span><b data-earned>0</b> XP earned</span></div>
                <div class="tng-runtime-list"></div>
                <div class="tng-runtime-complete"><h3>Quest complete!</h3><p>You completed the required checkpoints and earned <?php echo esc_html(number_format_i18n($xp)); ?> XP.</p></div>
            </div>
            <script type="application/json" class="tng-runtime-data"><?php echo wp_json_encode($payload); ?></script>
            <script>
            (()=>{const root=document.currentScript.closest('.tng-runtime');if(!root)return;const data=JSON.parse(root.querySelector('.tng-runtime-data').textContent),panel=root.querySelector('.tng-runtime-panel'),list=root.querySelector('.tng-runtime-list'),bar=root.querySelector('.tng-runtime-progress span'),storage='tngQuestProgress:'+data.questId;let state={started:false,done:[]};try{state=Object.assign(state,JSON.parse(localStorage.getItem(storage)||'{}'));}catch(e){}const save=()=>localStorage.setItem(storage,JSON.stringify(state));const render=()=>{panel.hidden=!state.started;const done=new Set(state.done||[]);let earned=0;data.stops.forEach((s,i)=>{if(done.has(s.id))earned+=Number(s.xp||0)});root.querySelector('[data-complete]').textContent=done.size;root.querySelector('[data-earned]').textContent=earned;bar.style.width=(data.required?Math.min(100,done.size/data.required*100):0)+'%';list.innerHTML=data.stops.map((s,i)=>{const complete=done.has(s.id),unlocked=state.started&&(i===0||done.has(data.stops[i-1].id)||complete);return `<article class="tng-runtime-stop ${complete?'is-done':''} ${unlocked?'':'is-locked'}"><span class="tng-runtime-num">${complete?'✓':i+1}</span><div><h3>${escapeHtml(s.title)}</h3><p>${escapeHtml(s.instruction||s.arrival||'Reach this checkpoint to continue.')}</p><small>${escapeHtml(s.type)} · ${Number(s.xp||0)} XP</small></div><button type="button" class="tng-runtime-claim" data-id="${escapeHtml(s.id)}" ${unlocked?'':'disabled'}>${complete?'Claimed':'Claim checkpoint'}</button></article>`}).join('');root.querySelector('.tng-runtime-complete').classList.toggle('is-visible',done.size>=data.required);};const escapeHtml=v=>String(v).replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));root.querySelector('.tng-runtime-start').addEventListener('click',()=>{state.started=true;save();render();panel.scrollIntoView({behavior:'smooth',block:'start'});});list.addEventListener('click',e=>{const button=e.target.closest('[data-id]');if(!button||button.disabled)return;state.done=Array.from(new Set([...(state.done||[]),button.dataset.id]));save();render();});render();})();
            </script>
        </section>
        <?php return (string)ob_get_clean();
    }

    private function entities(): array {
        $engine = $this->container->get('recommendation_engine');
        return $engine && is_callable([$engine, 'entities']) ? $engine->entities() : [];
    }

    private function duration_label(int $minutes): string {
        if ($minutes < 60) return $minutes . ' min';
        $hours = round($minutes / 60, 1);
        return rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.') . ' hr';
    }
}
