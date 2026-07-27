<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Runtime_Bootstrap_Recovery implements Module_Interface {
    public function id(): string { return 'runtime_bootstrap_recovery'; }

    public function register(Container $container): void {
        $container->set('runtime_bootstrap_recovery', $this);
        add_action('wp_footer', [$this, 'recover'], 999);
    }

    public function boot(Container $container): void {}

    public function recover(): void {
        if (!isset($_GET['tng_quest_runtime_id']) && !is_singular('tng_quest')) return;
        ?>
        <script data-tng-runtime-recovery>
        (()=>{
            let attempts=0;
            const maxAttempts=50;

            const initialized=root=>{
                if(!root)return false;
                const list=root.querySelector('.tng-runtime-list');
                return root.classList.contains('is-started') || Boolean(list&&list.children.length) || root.dataset.runtimeInitialized==='1';
            };

            const fallback=root=>{
                const start=root?.querySelector('.tng-runtime-start');
                if(!start||start.dataset.recoveryFallback)return;
                start.dataset.recoveryFallback='1';
                start.addEventListener('click',()=>{
                    root.classList.add('is-started');
                    const notice=root.querySelector('.tng-runtime-error');
                    if(notice){
                        notice.textContent='Quest runtime recovery could not load the full gameplay engine. Clear the page cache and reload.';
                        notice.classList.add('is-visible');
                    }
                });
            };

            const boot=()=>{
                attempts++;
                const root=document.querySelector('.tng-runtime');
                if(!root){
                    if(attempts<maxAttempts)setTimeout(boot,100);
                    return;
                }
                if(initialized(root))return;

                const scripts=[...document.scripts];
                const source=scripts.find(script=>{
                    if(script.hasAttribute('data-tng-runtime-recovery'))return false;
                    const text=script.textContent||'';
                    return text.includes('tngQuestProgress:') && text.includes('.tng-runtime-data') && text.includes('.tng-runtime-start');
                });

                if(!source){
                    if(attempts<maxAttempts){setTimeout(boot,100);return;}
                    fallback(root);
                    return;
                }

                if(root.dataset.runtimeRecovered==='1')return;
                root.dataset.runtimeRecovered='1';

                try{
                    let patched=source.textContent;
                    patched=patched.replace(
                        /const root=document\.currentScript\.closest\(['\"]\.tng-runtime['\"]\);\s*if\(!root\)return;/,
                        "const root=document.querySelector('.tng-runtime'); if(!root||root.dataset.runtimeInitialized==='1')return; root.dataset.runtimeInitialized='1';"
                    );
                    patched=patched.replace(
                        /const root=document\.querySelector\(['\"]\.tng-runtime['\"]\);\s*if\(!root\|\|root\.dataset\.runtimeRecovered\)return;\s*root\.dataset\.runtimeRecovered=['\"]1['\"];/,
                        "const root=document.querySelector('.tng-runtime'); if(!root||root.dataset.runtimeInitialized==='1')return; root.dataset.runtimeInitialized='1';"
                    );
                    (0,eval)(patched);
                    setTimeout(()=>{
                        if(!initialized(root))fallback(root);
                    },500);
                }catch(error){
                    console.error('TN Game runtime recovery failed',error);
                    root.dataset.runtimeRecovered='0';
                    fallback(root);
                }
            };

            if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});
            else boot();
        })();
        </script>
        <?php
    }
}
