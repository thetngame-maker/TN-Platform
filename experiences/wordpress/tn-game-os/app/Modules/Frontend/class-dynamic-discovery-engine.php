<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Dynamic_Discovery_Engine implements Module_Interface {
    public function id(): string { return 'dynamic_discovery_engine'; }

    public function register(Container $container): void {
        $container->set('dynamic_discovery_engine', $this);
        add_action('wp_footer', [$this, 'enhance_world'], 150);
    }

    public function boot(Container $container): void {}

    public function enhance_world(): void {
        if (!isset($_GET['tng_world'])) return;
        ?>
        <style>
            .tng-dynamic-world{margin-top:16px;background:linear-gradient(135deg,#18213d,#4a2d68);color:#fff;border-radius:22px;padding:18px;border:1px solid rgba(255,255,255,.1);box-shadow:0 14px 35px rgba(24,33,61,.16)}
            .tng-dynamic-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:14px}.tng-dynamic-kicker{text-transform:uppercase;letter-spacing:.12em;color:#f6bd3b;font-size:11px;font-weight:900}.tng-dynamic-head h2{margin:4px 0 0;color:#fff}.tng-dynamic-status{font-size:12px;color:rgba(255,255,255,.72);white-space:nowrap}
            .tng-dynamic-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.tng-dynamic-card{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.16);border-radius:16px;padding:14px;min-height:132px}.tng-dynamic-icon{font-size:25px}.tng-dynamic-card h3{color:#fff;margin:8px 0 5px;font-size:16px}.tng-dynamic-card p{margin:0;color:rgba(255,255,255,.76);font-size:13px;line-height:1.45}.tng-dynamic-reward{display:inline-flex;margin-top:10px;border-radius:999px;background:#ecfdf3;color:#067647;padding:5px 9px;font-size:11px;font-weight:900}.tng-dynamic-live{display:inline-flex;margin-left:7px;border-radius:999px;background:#fff4cc;color:#7a4e00;padding:4px 8px;font-size:10px;font-weight:900}
            @media(max-width:850px){.tng-dynamic-world{margin:12px}.tng-dynamic-grid{grid-template-columns:1fr}.tng-dynamic-status{white-space:normal;text-align:right}}
        </style>
        <script>
        (()=>{
            const world=document.querySelector('.tng-world');
            if(!world||world.dataset.dynamicEnhanced)return;
            world.dataset.dynamicEnhanced='1';
            const dataNode=world.querySelector('.tng-world-data');
            let data={entities:[],quests:[]};
            try{data=JSON.parse(dataNode?.textContent||'{}')}catch(e){}
            const living=document.querySelector('.tng-living-grid');
            const anchor=living||world.querySelector('.tng-world-layout');
            if(!anchor)return;
            const section=document.createElement('section');
            section.className='tng-dynamic-world';
            section.innerHTML='<div class="tng-dynamic-head"><div><div class="tng-dynamic-kicker">Dynamic discovery engine</div><h2>Today in your world</h2></div><div class="tng-dynamic-status" data-dynamic-status>Personalizing nearby opportunities…</div></div><div class="tng-dynamic-grid" data-dynamic-grid></div>';
            anchor.insertAdjacentElement('afterend',section);
            const grid=section.querySelector('[data-dynamic-grid]');
            const status=section.querySelector('[data-dynamic-status]');
            const entities=(data.entities||[]).map(x=>({...x,kind:['event','concert'].includes(x.type)?'event':'place'}));
            const quests=(data.quests||[]).map(x=>({...x,kind:'quest'}));
            const all=[...entities,...quests];
            const now=new Date();
            const hour=now.getHours();
            const weekend=now.getDay()===0||now.getDay()===6;
            const timed=[];
            if(hour<9) timed.push({icon:'🌅',title:'Morning Explorer',text:'Discover a nearby place before 9:00 AM.',xp:35,live:'Morning only'});
            else if(hour>=18) timed.push({icon:'🌙',title:'Night Explorer',text:'Complete a discovery after 6:00 PM.',xp:40,live:'Tonight'});
            else timed.push({icon:'☀️',title:'Daylight Discovery',text:'Visit any new nearby destination today.',xp:25,live:'Today'});
            if(weekend) timed.push({icon:'🔥',title:'Weekend Adventure',text:'Complete any quest or event discovery this weekend.',xp:50,live:'Weekend bonus'});
            const event=entities.find(x=>x.kind==='event');
            if(event) timed.push({icon:'🎵',title:'Event Spotlight',text:'Explore '+event.title+' while it is featured nearby.',xp:45,live:'Featured'});
            const quest=quests[0];
            if(quest) timed.push({icon:'🎯',title:'Quest of the Day',text:'Start '+quest.title+' from the World Map.',xp:75,live:'Daily pick'});
            if(timed.length<3) timed.push({icon:'🧭',title:'First Visit Bonus',text:'Discover somewhere you have not visited before.',xp:25,live:'Always available'});
            const cards=timed.slice(0,3);
            grid.innerHTML=cards.map(c=>`<article class="tng-dynamic-card"><div class="tng-dynamic-icon">${c.icon}</div><h3>${c.title}<span class="tng-dynamic-live">${c.live}</span></h3><p>${c.text}</p><span class="tng-dynamic-reward">+${c.xp} XP preview</span></article>`).join('');
            const label=weekend?'Weekend world active':hour<9?'Morning world active':hour>=18?'Night world active':'Daytime world active';
            status.textContent=label+' · '+all.length+' discoveries available';
        })();
        </script>
        <?php
    }
}
