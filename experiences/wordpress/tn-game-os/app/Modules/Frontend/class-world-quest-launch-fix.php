<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class World_Quest_Launch_Fix implements Module_Interface {
    public function id(): string { return 'world_quest_launch_fix'; }

    public function register(Container $container): void {
        $container->set('world_quest_launch_fix', $this);
        add_action('wp_footer', [$this, 'fix_launch_actions'], 110);
    }

    public function boot(Container $container): void {}

    public function fix_launch_actions(): void {
        if (!isset($_GET['tng_world'])) return;
        $runtime_base = home_url('/');
        ?>
        <style>
            .tng-world-item[data-tng-quest-card="1"]{cursor:pointer;position:relative}
            .tng-world-item[data-tng-quest-card="1"]:hover{border-color:#7f56d9;box-shadow:0 5px 18px rgba(83,56,158,.12)}
        </style>
        <script>
        (()=>{
            const world=document.querySelector('.tng-world');
            if(!world) return;
            const runtimeBase=<?php echo wp_json_encode($runtime_base); ?>;
            const dataNode=world.querySelector('.tng-world-data');
            let data={quests:[]};
            try{data=JSON.parse(dataNode?.textContent||'{}')}catch(e){}
            const quests=new Map((data.quests||[]).map(q=>[String(q.title||'').trim(),q]));

            const runtimeUrl=quest=>{
                const match=String(quest?.id||'').match(/quest-(\d+)/);
                if(!match) return '';
                const url=new URL(runtimeBase,window.location.origin);
                url.searchParams.set('tng_quest_runtime_id',match[1]);
                return url.toString();
            };

            const tagQuestCards=()=>{
                world.querySelectorAll('.tng-world-item').forEach(card=>{
                    const title=card.querySelector('strong')?.textContent.trim();
                    if(title&&quests.has(title)){
                        card.dataset.tngQuestCard='1';
                        card.setAttribute('role','link');
                        card.setAttribute('tabindex','0');
                        card.setAttribute('aria-label','Start quest '+title);
                    }
                });
            };

            const repairSheet=()=>{
                const sheet=document.querySelector('.tng-world-discovery-sheet.is-open');
                if(!sheet) return;
                const title=sheet.querySelector('[data-sheet-title]')?.textContent.trim();
                const quest=title?quests.get(title):null;
                if(!quest) return;
                const primary=sheet.querySelector('[data-sheet-primary]');
                const url=runtimeUrl(quest);
                if(!primary||!url) return;
                primary.href=url;
                primary.textContent='Start quest';
                primary.onclick=null;
                primary.removeAttribute('aria-disabled');
                primary.style.pointerEvents='auto';
            };

            const launchCard=target=>{
                const card=target.closest('.tng-world-item');
                if(!card) return false;
                const title=card.querySelector('strong')?.textContent.trim();
                const quest=title?quests.get(title):null;
                const url=runtimeUrl(quest);
                if(!url) return false;
                window.location.assign(url);
                return true;
            };

            document.addEventListener('click',e=>{
                if(launchCard(e.target)){
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    return;
                }
                if(e.target.closest('.tng-world-marker')) setTimeout(repairSheet,30);
            },true);

            document.addEventListener('keydown',e=>{
                if((e.key==='Enter'||e.key===' ')&&e.target.closest('.tng-world-item[data-tng-quest-card="1"]')){
                    e.preventDefault();
                    launchCard(e.target);
                }
            },true);

            const observer=new MutationObserver(()=>{tagQuestCards();repairSheet();});
            observer.observe(world,{childList:true,subtree:true});
            tagQuestCards();
            repairSheet();
        })();
        </script>
        <?php
    }
}
