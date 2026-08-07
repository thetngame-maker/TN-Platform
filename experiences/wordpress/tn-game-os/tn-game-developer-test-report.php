<?php
/**
 * TN Game Developer Test Report
 * Admin-only guided runtime test reporting.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Game_Developer_Test_Report {
    public static function boot(): void {
        add_action('wp_footer', [self::class, 'render'], 1210);
    }

    private static function is_game_play(): bool {
        return class_exists('TNG_OS\\Platform\\App_Router')
            && TNG_OS\Platform\App_Router::current_route() === 'game-play';
    }

    private static function game_id(): int {
        $id = absint($_GET['game'] ?? 0);
        if (!$id) return 0;
        $post = get_post($id);
        return ($post && $post->post_status === 'publish') ? $id : 0;
    }

    private static function checkpoint_data(int $game_id): array {
        $raw = get_post_meta($game_id, 'tng_game_checkpoints', true);
        if (!is_array($raw) || !$raw) return [];

        $completed = get_user_meta(get_current_user_id(), '_tng_game_progress_' . $game_id, true);
        if (!is_array($completed)) $completed = [];
        $completed = array_values(array_unique(array_map('absint', $completed)));

        $out = [];
        foreach ($raw as $index => $item) {
            if (!is_array($item)) continue;
            $index = (int) $index;
            $type = sanitize_key((string) ($item['type'] ?? 'tap'));
            $xp = absint($item['xp'] ?? 0);
            if ($xp < 1) {
                $defaults = ['tap' => 10, 'gps' => 25, 'question' => 50, 'photo' => 40];
                $xp = $defaults[$type] ?? 25;
            }
            $out[] = [
                'index' => $index,
                'title' => sanitize_text_field((string) ($item['title'] ?? ('Checkpoint ' . ($index + 1)))),
                'type' => $type,
                'xp' => $xp,
                'completed' => in_array($index, $completed, true),
            ];
        }
        return $out;
    }

    public static function render(): void {
        if (is_admin() || !is_user_logged_in() || !current_user_can('manage_options') || !self::is_game_play()) return;
        $game_id = self::game_id();
        if (!$game_id) return;
        $checkpoints = self::checkpoint_data($game_id);
        if (!$checkpoints) return;

        $completed_count = 0;
        foreach ($checkpoints as $checkpoint) if (!empty($checkpoint['completed'])) $completed_count++;
        $payload = wp_json_encode([
            'gameId' => $game_id,
            'title' => get_the_title($game_id),
            'checkpoints' => $checkpoints,
            'finished' => $completed_count >= count($checkpoints),
            'runtimeXp' => absint($_GET['runtime_xp'] ?? 0),
            'runtimeError' => sanitize_key((string) ($_GET['runtime_error'] ?? '')),
        ]);
        ?>
        <style id="tng-developer-test-report-style">
            .tng-dev-report{margin-top:14px;padding-top:13px;border-top:1px solid rgba(255,255,255,.13)}
            .tng-dev-report__head{display:flex;align-items:center;justify-content:space-between;gap:10px}
            .tng-dev-report__title{font-size:10px;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#ff8a3d}
            .tng-dev-report__badge{padding:5px 8px;border-radius:999px;background:rgba(255,255,255,.1);font-size:10px;font-weight:850;color:#d9e3ef}
            .tng-dev-report__badge.is-pass{background:#3f8d63;color:#fff}.tng-dev-report__badge.is-fail{background:#b84538;color:#fff}
            .tng-dev-report__summary{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin-top:9px}
            .tng-dev-report__stat{padding:9px;border-radius:10px;background:rgba(255,255,255,.07);text-align:center}
            .tng-dev-report__stat strong{display:block;color:#fff;font-size:16px}.tng-dev-report__stat span{display:block;margin-top:2px;color:#9fb0c5;font-size:9px;text-transform:uppercase;letter-spacing:.06em}
            .tng-dev-report__list{display:grid;gap:6px;margin-top:9px;max-height:180px;overflow:auto}
            .tng-dev-report__row{display:grid;grid-template-columns:22px 1fr auto;gap:7px;align-items:center;padding:8px 9px;border-radius:9px;background:rgba(255,255,255,.06);font-size:11px}
            .tng-dev-report__icon{display:grid;place-items:center;width:22px;height:22px;border-radius:50%;background:rgba(255,255,255,.1);font-weight:900}.tng-dev-report__icon.is-pass{background:#3f8d63}.tng-dev-report__icon.is-pending{background:#8a6d2d}
            .tng-dev-report__row strong{display:block;color:#fff;font-size:11px}.tng-dev-report__row small{display:block;color:#9fb0c5;margin-top:1px}.tng-dev-report__xp{color:#d9e3ef;font-weight:800;white-space:nowrap}
            .tng-dev-report__checks{margin-top:8px;padding:9px 10px;border-radius:10px;background:rgba(255,255,255,.06);font-size:10px;line-height:1.5;color:#cbd5e1}
            .tng-dev-report__checks b{color:#fff}.tng-dev-report__actions{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:8px}.tng-dev-report__actions button{border:0;border-radius:9px;padding:8px 9px;font-weight:800;cursor:pointer}.tng-dev-report__copy{background:#fff;color:#14213d}.tng-dev-report__clear{background:rgba(255,255,255,.11);color:#fff}
        </style>
        <script id="tng-developer-test-report-script">
        (()=>{
            const cfg=<?php echo $payload; ?>;
            if(!cfg||!Array.isArray(cfg.checkpoints))return;
            const guidedKey=`tng_dev_guided_${Number(cfg.gameId)}`;
            const reportKey=`tng_dev_report_${Number(cfg.gameId)}`;
            const isGuided=()=>sessionStorage.getItem(guidedKey)==='1';
            const defaultReport=()=>({gameId:Number(cfg.gameId),title:cfg.title||'',startedAt:new Date().toISOString(),steps:{},xpObserved:0,seen:[],sequencePass:true,finished:false});
            const load=()=>{try{return JSON.parse(sessionStorage.getItem(reportKey)||'null')||defaultReport();}catch(e){return defaultReport();}};
            const save=(report)=>sessionStorage.setItem(reportKey,JSON.stringify(report));
            const contiguous=()=>{
                const done=cfg.checkpoints.filter(cp=>cp.completed).map(cp=>Number(cp.index)).sort((a,b)=>a-b);
                for(let i=0;i<done.length;i++)if(done[i]!==i)return false;
                return true;
            };
            const reconcile=()=>{
                let report=load();
                report.sequencePass=report.sequencePass!==false && contiguous();
                cfg.checkpoints.forEach(cp=>{
                    const key=String(cp.index);
                    const previous=report.steps[key]||{};
                    report.steps[key]={index:Number(cp.index),title:cp.title,type:cp.type,xp:Number(cp.xp||0),passed:!!cp.completed,completedAt:cp.completed?(previous.completedAt||new Date().toISOString()):null};
                });
                const signature=`${location.pathname}|${location.search}|${cfg.checkpoints.filter(cp=>cp.completed).length}`;
                if(Number(cfg.runtimeXp)>0 && !report.seen.includes(signature)){
                    report.xpObserved=Number(report.xpObserved||0)+Number(cfg.runtimeXp);
                    report.seen.push(signature);
                }
                report.finished=!!cfg.finished;
                if(cfg.finished)report.finishedAt=report.finishedAt||new Date().toISOString();
                save(report);
                return report;
            };
            const ensureStarted=()=>{
                if(!sessionStorage.getItem(reportKey))save(defaultReport());
                return reconcile();
            };
            const panel=()=>document.getElementById('tng-dev-gps');
            const mount=()=>{
                const root=panel(); if(!root)return false;
                if(root.querySelector('.tng-dev-report')){render();return true;}
                const guided=root.querySelector('.tng-dev-guided');
                if(!guided)return false;
                const section=document.createElement('div');
                section.className='tng-dev-report';
                section.innerHTML=`<div class="tng-dev-report__head"><span class="tng-dev-report__title">Developer test report</span><span class="tng-dev-report__badge" data-dev-report-badge>Not started</span></div><div class="tng-dev-report__summary" data-dev-report-summary></div><div class="tng-dev-report__list" data-dev-report-list></div><div class="tng-dev-report__checks" data-dev-report-checks></div><div class="tng-dev-report__actions"><button type="button" class="tng-dev-report__copy" data-dev-report-copy>Copy summary</button><button type="button" class="tng-dev-report__clear" data-dev-report-clear>Clear report</button></div>`;
                guided.insertAdjacentElement('afterend',section);
                section.querySelector('[data-dev-report-clear]')?.addEventListener('click',()=>{sessionStorage.removeItem(reportKey);render();});
                section.querySelector('[data-dev-report-copy]')?.addEventListener('click',async()=>{
                    const text=summaryText();
                    try{await navigator.clipboard.writeText(text);section.querySelector('[data-dev-report-copy]').textContent='Copied ✓';setTimeout(()=>section.querySelector('[data-dev-report-copy]').textContent='Copy summary',1200);}catch(e){console.log(text);}
                });
                render();return true;
            };
            const reportData=()=>isGuided()?ensureStarted():(sessionStorage.getItem(reportKey)?reconcile():null);
            const render=()=>{
                const root=panel(); if(!root)return;
                const section=root.querySelector('.tng-dev-report'); if(!section)return;
                const report=reportData();
                const badge=section.querySelector('[data-dev-report-badge]');
                const summary=section.querySelector('[data-dev-report-summary]');
                const list=section.querySelector('[data-dev-report-list]');
                const checks=section.querySelector('[data-dev-report-checks]');
                if(!report){
                    if(badge){badge.textContent='Not started';badge.className='tng-dev-report__badge';}
                    if(summary)summary.innerHTML='<div class="tng-dev-report__stat"><strong>0</strong><span>Passed</span></div><div class="tng-dev-report__stat"><strong>0</strong><span>XP seen</span></div><div class="tng-dev-report__stat"><strong>—</strong><span>Route</span></div>';
                    if(list)list.innerHTML='<div class="tng-dev-report__checks">Start Guided Test Run to begin recording checkpoint results.</div>';
                    if(checks)checks.innerHTML='<b>Checks:</b> progression order, checkpoint completion, XP redirects, GPS completion, and final route completion.';
                    return;
                }
                const steps=cfg.checkpoints.map(cp=>report.steps[String(cp.index)]||{...cp,passed:false});
                const passed=steps.filter(step=>step.passed).length;
                const routePass=!!report.finished && passed===steps.length && report.sequencePass!==false;
                if(badge){badge.textContent=routePass?'PASS':(report.finished?'CHECK':'RUNNING');badge.className='tng-dev-report__badge '+(routePass?'is-pass':(report.finished?'is-fail':''));}
                if(summary)summary.innerHTML=`<div class="tng-dev-report__stat"><strong>${passed}/${steps.length}</strong><span>Passed</span></div><div class="tng-dev-report__stat"><strong>${Number(report.xpObserved||0)}</strong><span>XP seen</span></div><div class="tng-dev-report__stat"><strong>${routePass?'PASS':(report.finished?'CHECK':'…')}</strong><span>Route</span></div>`;
                if(list)list.innerHTML=steps.map((step,i)=>`<div class="tng-dev-report__row"><span class="tng-dev-report__icon ${step.passed?'is-pass':'is-pending'}">${step.passed?'✓':i+1}</span><span><strong>${escapeHtml(step.title||('Checkpoint '+(i+1)))}</strong><small>${String(step.type||'tap').toUpperCase()}${step.type==='gps'?' · distance validation':''}</small></span><span class="tng-dev-report__xp">${Number(step.xp||0)} XP</span></div>`).join('');
                const sequence=report.sequencePass!==false;
                if(checks)checks.innerHTML=`<b>Progression order:</b> ${sequence?'✓ pass':'✕ fail'}<br><b>Final completion:</b> ${report.finished?'✓ recorded':'… pending'}<br><b>XP redirects observed:</b> ${Number(report.xpObserved||0)} XP${routePass?'<br><b>Overall:</b> ✓ guided runtime test passed':''}`;
            };
            const escapeHtml=(value)=>String(value??'').replace(/[&<>'"]/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[ch]));
            const summaryText=()=>{
                const report=reportData(); if(!report)return `TN Game Developer Test Report\n${cfg.title}\nNo guided run recorded.`;
                const steps=cfg.checkpoints.map(cp=>report.steps[String(cp.index)]||cp);
                const lines=[`TN Game Developer Test Report`,`Game: ${cfg.title}`,`Route complete: ${report.finished?'YES':'NO'}`,`Sequence: ${report.sequencePass!==false?'PASS':'FAIL'}`,`XP redirects observed: ${Number(report.xpObserved||0)}`,''];
                steps.forEach((step,i)=>lines.push(`${step.passed?'PASS':'PENDING'} | ${i+1}. ${step.title} | ${String(step.type||'tap').toUpperCase()} | ${Number(step.xp||0)} XP`));
                return lines.join('\n');
            };

            if(cfg.finished && cfg.runtimeError==='sequence'){
                document.querySelectorAll('.tng-runtime-notice.is-error').forEach(node=>{if((node.textContent||'').toLowerCase().includes('finish the current checkpoint'))node.remove();});
            }

            mount();
            if(isGuided())ensureStarted();
            document.addEventListener('click',(event)=>{
                const toggle=event.target.closest?.('[data-dev-guided-toggle]');
                if(!toggle)return;
                setTimeout(()=>{if(isGuided())ensureStarted();render();},60);
            },true);
            new MutationObserver(()=>mount()).observe(document.documentElement,{childList:true,subtree:true});
        })();
        </script>
        <?php
    }
}

TNG_Game_Developer_Test_Report::boot();
