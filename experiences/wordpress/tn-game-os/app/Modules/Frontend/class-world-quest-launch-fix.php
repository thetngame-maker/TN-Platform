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
        <script>
        (()=>{
            const world=document.querySelector('.tng-world');
            if(!world) return;
            const runtimeBase=<?php echo wp_json_encode($runtime_base); ?>;
            const dataNode=world.querySelector('.tng-world-data');
            let data={quests:[]};
            try{data=JSON.parse(dataNode?.textContent||'{}')}catch(e){}
            const quests=new Map((data.quests||[]).map(q=>[String(q.title||'').trim(),q]));

            const repair=()=>{
                const sheet=document.querySelector('.tng-world-discovery-sheet.is-open');
                if(!sheet) return;
                const title=sheet.querySelector('[data-sheet-title]')?.textContent.trim();
                const quest=title?quests.get(title):null;
                if(!quest) return;
                const primary=sheet.querySelector('[data-sheet-primary]');
                if(!primary) return;
                const raw=String(quest.id||'');
                const match=raw.match(/quest-(\d+)/);
                if(!match) return;
                const url=new URL(runtimeBase,window.location.origin);
                url.searchParams.set('tng_quest_runtime_id',match[1]);
                primary.href=url.toString();
                primary.textContent='Start quest';
                primary.onclick=null;
                primary.removeAttribute('aria-disabled');
                primary.style.pointerEvents='auto';
            };

            document.addEventListener('click',e=>{
                if(e.target.closest('.tng-world-item,.tng-world-marker')) setTimeout(repair,20);
            },true);
            new MutationObserver(repair).observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['class']});
            repair();
        })();
        </script>
        <?php
    }
}
