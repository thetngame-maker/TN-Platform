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
                'hint' => (string)($m['hint'] ?? ''),
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
            'loggedIn' => is_user_logged_in(),
            'progressUrl' => rest_url('tng-game/v1/quest-progress/' . $quest_id),
            'restNonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
        ];

        ob_start(); ?>
        <section class="tng-runtime" data-quest-id="<?php echo esc_attr((string)$quest_id); ?>">
            <style>
                .tng-runtime{--ink:#18213d;--muted:#667085;--accent:#7f56d9;--accent-dark:#52306f;--success:#12b76a;--soft:#f4f0ff;max-width:920px;margin:28px auto;font-family:inherit;color:var(--ink)}
                .tng-runtime *{box-sizing:border-box}.tng-runtime button{font:inherit}.tng-runtime-hero{background:linear-gradient(135deg,#18213d,#633b78);color:#fff;border-radius:26px;padding:32px;box-shadow:0 18px 45px rgba(24,33,61,.18)}
                .tng-runtime-kicker{text-transform:uppercase;letter-spacing:.13em;color:#f6bd3b;font-weight:800;font-size:12px}.tng-runtime h2{color:#fff;font-size:32px;margin:9px 0}.tng-runtime-meta{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0}
                .tng-runtime-pill{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.2);border-radius:999px;padding:7px 11px;font-weight:700;font-size:13px}.tng-runtime-start{border:0;border-radius:13px;background:#fff;color:var(--ink);font-weight:800;padding:14px 20px;cursor:pointer;box-shadow:0 8px 24px rgba(0,0,0,.14)}
                .tng-runtime-sync{font-size:12px;margin-top:11px;color:rgba(255,255,255,.78)}.tng-runtime-error{display:none;background:#fff1f0;color:#b42318;border:1px solid #fecdca;border-radius:12px;padding:11px;margin-top:12px}.tng-runtime-error.is-visible{display:block}
                .tng-adventure{display:none;margin-top:16px;background:#eef1f5;border-radius:24px;overflow:hidden;border:1px solid #dfe3e8}.tng-runtime.is-started .tng-runtime-hero{display:none}.tng-runtime.is-started .tng-adventure{display:block}
                .tng-adventure-head{background:linear-gradient(135deg,#18213d,#4a2d68);color:#fff;padding:20px}.tng-adventure-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.tng-adventure-title{font-weight:800;font-size:20px;line-height:1.2}.tng-adventure-exit{border:1px solid rgba(255,255,255,.26);background:rgba(255,255,255,.1);color:#fff;border-radius:10px;padding:8px 10px;cursor:pointer}.tng-adventure-stats{display:flex;justify-content:space-between;gap:12px;margin-top:16px;font-weight:700;font-size:13px}
                .tng-runtime-progress{height:10px;background:rgba(255,255,255,.18);border-radius:999px;overflow:hidden;margin-top:10px}.tng-runtime-progress span{display:block;height:100%;width:0;background:#4ade80;transition:.25s}.tng-runtime-dots{display:flex;gap:7px;align-items:center;margin-top:13px}.tng-runtime-dot{width:10px;height:10px;border-radius:50%;background:rgba(255,255,255,.28)}.tng-runtime-dot.is-done{background:#4ade80}.tng-runtime-dot.is-current{background:#fff;box-shadow:0 0 0 4px rgba(255,255,255,.16)}
                .tng-adventure-body{padding:18px}.tng-next-card{background:#fff;border:1px solid #e4e7ec;border-radius:20px;padding:20px;box-shadow:0 10px 28px rgba(24,33,61,.08)}.tng-next-label{text-transform:uppercase;letter-spacing:.1em;color:var(--accent);font-size:11px;font-weight:900}.tng-next-card h3{font-size:24px;margin:6px 0 8px}.tng-next-card p{color:#475467;margin:0 0 14px}.tng-next-meta{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.tng-next-chip{background:var(--soft);color:#53389e;border-radius:999px;padding:6px 9px;font-size:12px;font-weight:800}.tng-next-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:16px}.tng-next-claim,.tng-next-secondary{border-radius:12px;padding:12px 15px;font-weight:800;cursor:pointer}.tng-next-claim{border:0;background:var(--accent);color:#fff}.tng-next-secondary{border:1px solid #d0d5dd;background:#fff;color:var(--ink)}
                .tng-journey{margin-top:16px}.tng-journey h3{margin:0 0 10px}.tng-runtime-list{display:grid;gap:10px}.tng-runtime-stop{border:1px solid #e5e7eb;border-radius:15px;padding:13px;display:grid;grid-template-columns:38px minmax(0,1fr) auto;gap:11px;align-items:center;background:#fff}.tng-runtime-stop.is-locked{opacity:.55}.tng-runtime-stop.is-done{background:#ecfdf3;border-color:#abefc6}.tng-runtime-stop.is-current{border-color:#b9a7ef;box-shadow:0 0 0 3px #f0edff}.tng-runtime-num{width:34px;height:34px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900}.tng-runtime-stop h4{margin:0 0 3px;font-size:15px}.tng-runtime-stop small{color:var(--muted)}.tng-runtime-state{font-size:12px;font-weight:800;color:var(--muted)}
                .tng-runtime-complete{display:none;text-align:center;background:#fff;border:1px solid #d9d0ff;border-radius:20px;padding:28px;margin-top:16px}.tng-runtime-complete.is-visible{display:block}.tng-runtime-complete-icon{font-size:42px}.tng-runtime-complete h3{font-size:27px;margin:8px 0}.tng-runtime-complete p{color:#475467}.tng-runtime-reset{border:1px solid #d0d5dd;background:#fff;border-radius:10px;padding:10px 12px;cursor:pointer}
                @media(max-width:650px){.tng-runtime{margin:14px 0}.tng-runtime-hero{border-radius:20px;padding:24px}.tng-runtime h2{font-size:27px}.tng-adventure{border-radius:0;margin-left:-12px;margin-right:-12px}.tng-adventure-body{padding:14px}.tng-next-card h3{font-size:21px}.tng-runtime-stop{grid-template-columns:34px 1fr}.tng-runtime-state{grid-column:2}.tng-next-actions>*{width:100%}}
            </style>
            <div class="tng-runtime-hero">
                <div class="tng-runtime-kicker">TN Game Quest</div>
                <h2><?php echo esc_html(get_the_title($quest_id)); ?></h2>
                <p><?php echo esc_html($summary ?: wp_strip_all_tags($quest->post_content)); ?></p>
                <div class="tng-runtime-meta"><span class="tng-runtime-pill"><?php echo esc_html((string)count($stops)); ?> checkpoints</span><span class="tng-runtime-pill"><?php echo esc_html(number_format_i18n($xp)); ?> XP</span><span class="tng-runtime-pill"><?php echo esc_html($this->duration_label($minutes)); ?></span></div>
                <button type="button" class="tng-runtime-start">Start Quest</button>
                <div class="tng-runtime-sync"><?php echo is_user_logged_in() ? 'Progress syncs to your TN Game account.' : 'Guest progress is saved on this device.'; ?></div>
            </div>
            <div class="tng-runtime-error" role="alert"></div>
            <div class="tng-adventure">
                <header class="tng-adventure-head">
                    <div class="tng-adventure-top"><div><div class="tng-runtime-kicker">Adventure in progress</div><div class="tng-adventure-title"><?php echo esc_html(get_the_title($quest_id)); ?></div></div><button type="button" class="tng-adventure-exit">Exit</button></div>
                    <div class="tng-adventure-stats"><span><b data-complete>0</b> / <b><?php echo esc_html((string)$required); ?></b> complete</span><span><b data-earned>0</b> XP</span></div>
                    <div class="tng-runtime-progress"><span></span></div>
                    <div class="tng-runtime-dots" aria-label="Quest progress"></div>
                </header>
                <div class="tng-adventure-body">
                    <section class="tng-next-card"></section>
                    <section class="tng-journey"><h3>Checkpoint journey</h3><div class="tng-runtime-list"></div></section>
                    <div class="tng-runtime-complete"><div class="tng-runtime-complete-icon">🎉</div><h3>Quest complete!</h3><p>You completed the required checkpoints and earned <strong><span data-final-xp>0</span> XP</strong>.</p><button type="button" class="tng-runtime-reset">Replay quest</button></div>
                </div>
            </div>
            <script type="application/json" class="tng-runtime-data"><?php echo wp_json_encode($payload); ?></script>
            <script>
            (()=>{const root=document.currentScript.closest('.tng-runtime');if(!root)return;const data=JSON.parse(root.querySelector('.tng-runtime-data').textContent),list=root.querySelector('.tng-runtime-list'),next=root.querySelector('.tng-next-card'),bar=root.querySelector('.tng-runtime-progress span'),dots=root.querySelector('.tng-runtime-dots'),error=root.querySelector('.tng-runtime-error'),storage='tngQuestProgress:'+data.questId;let state={started:false,done:[],status:'not_started'},saving=false;const escapeHtml=v=>String(v).replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));const localLoad=()=>{try{return JSON.parse(localStorage.getItem(storage)||'{}')}catch(e){return{}}};const localSave=()=>{try{localStorage.setItem(storage,JSON.stringify(state))}catch(e){}};const showError=message=>{error.textContent=message||'';error.classList.toggle('is-visible',Boolean(message));};const api=async(method,body)=>{const response=await fetch(data.progressUrl,{method,credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':data.restNonce},body:body?JSON.stringify(body):undefined});if(!response.ok)throw new Error('Progress could not be synced.');return response.json();};const apply=remote=>{state.started=Boolean(remote.started);state.done=Array.isArray(remote.completedStops)?remote.completedStops.map(String):[];state.status=remote.status||'not_started';};const persist=async()=>{localSave();if(!data.loggedIn||saving)return;saving=true;showError('');try{const remote=await api('POST',{started:state.started,completedStops:state.done});apply(remote);localSave();}catch(e){showError('Your progress is saved on this device, but account sync is temporarily unavailable.');}finally{saving=false;render();}};const typeLabel=type=>({gps:'GPS arrival',trivia:'Trivia',photo:'Photo challenge',qr:'QR code',manual:'Manual claim'}[type]||'Checkpoint');const render=()=>{root.classList.toggle('is-started',state.started);root.querySelector('.tng-runtime-start').textContent=state.started?'Resume Quest':'Start Quest';const done=new Set((state.done||[]).map(String));let earned=0;data.stops.forEach(s=>{if(done.has(String(s.id)))earned+=Number(s.xp||0)});root.querySelector('[data-complete]').textContent=done.size;root.querySelector('[data-earned]').textContent=earned;root.querySelector('[data-final-xp]').textContent=earned;bar.style.width=(data.required?Math.min(100,done.size/data.required*100):0)+'%';const currentIndex=data.stops.findIndex(s=>!done.has(String(s.id)));dots.innerHTML=data.stops.map((s,i)=>`<span class="tng-runtime-dot ${done.has(String(s.id))?'is-done':''} ${i===currentIndex?'is-current':''}"></span>`).join('');const current=currentIndex>=0?data.stops[currentIndex]:null;if(current){next.innerHTML=`<div class="tng-next-label">Next checkpoint · ${currentIndex+1} of ${data.stops.length}</div><h3>${escapeHtml(current.title)}</h3><p>${escapeHtml(current.instruction||current.arrival||'Reach this checkpoint and complete the activity to continue.')}</p><div class="tng-next-meta"><span class="tng-next-chip">${escapeHtml(typeLabel(current.type))}</span><span class="tng-next-chip">${Number(current.xp||0)} XP</span></div>${current.hint?`<p><strong>Hint:</strong> ${escapeHtml(current.hint)}</p>`:''}<div class="tng-next-actions"><button type="button" class="tng-next-claim" data-claim-current="${escapeHtml(String(current.id))}">Claim checkpoint</button><button type="button" class="tng-next-secondary" data-scroll-journey>View full journey</button></div>`;}else{next.innerHTML='<div class="tng-next-label">Journey complete</div><h3>Every required checkpoint is complete.</h3><p>Your adventure has been saved.</p>';}list.innerHTML=data.stops.map((s,i)=>{const id=String(s.id),complete=done.has(id),isCurrent=i===currentIndex,locked=!complete&&!isCurrent;return `<article class="tng-runtime-stop ${complete?'is-done':''} ${isCurrent?'is-current':''} ${locked?'is-locked':''}"><span class="tng-runtime-num">${complete?'✓':i+1}</span><div><h4>${escapeHtml(s.title)}</h4><small>${escapeHtml(typeLabel(s.type))} · ${Number(s.xp||0)} XP</small></div><span class="tng-runtime-state">${complete?'Completed':isCurrent?'Next':'Locked'}</span></article>`}).join('');root.querySelector('.tng-runtime-complete').classList.toggle('is-visible',done.size>=data.required&&data.required>0);};root.querySelector('.tng-runtime-start').addEventListener('click',()=>{state.started=true;persist();render();root.scrollIntoView({behavior:'smooth',block:'start'});});root.querySelector('.tng-adventure-exit').addEventListener('click',()=>{root.classList.remove('is-started');root.querySelector('.tng-runtime-hero').scrollIntoView({behavior:'smooth',block:'start'});});next.addEventListener('click',e=>{const claim=e.target.closest('[data-claim-current]');if(claim){const id=String(claim.dataset.claimCurrent);state.done=Array.from(new Set([...(state.done||[]).map(String),id]));persist();render();if(navigator.vibrate)navigator.vibrate(120);}if(e.target.closest('[data-scroll-journey]'))root.querySelector('.tng-journey').scrollIntoView({behavior:'smooth',block:'start'});});root.querySelector('.tng-runtime-reset').addEventListener('click',()=>{state={started:true,done:[],status:'in_progress'};persist();render();});const init=async()=>{const local=localLoad();apply({started:Boolean(local.started),completedStops:local.done||local.completedStops||[],status:local.status||'not_started'});if(data.loggedIn){try{const remote=await api('GET');apply(remote);localSave();}catch(e){showError('Account progress could not be loaded. Using progress saved on this device.');}}render();};init();})();
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
