<?php
namespace TNG_OS\Modules\Frontend;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Runtime_Bootstrap_Recovery implements Module_Interface {
    public function id(): string { return 'runtime_bootstrap_recovery'; }

    public function register(Container $container): void {
        $container->set('runtime_bootstrap_recovery', $this);
        add_action('wp_footer', [$this, 'recover'], 170);
    }

    public function boot(Container $container): void {}

    public function recover(): void {
        if (!isset($_GET['tng_quest_runtime_id']) && !is_singular('tng_quest')) return;
        ?>
        <script>
        (()=>{
            const boot=()=>{
                const root=document.querySelector('.tng-runtime');
                if(!root||root.dataset.runtimeRecoveryChecked)return;
                root.dataset.runtimeRecoveryChecked='1';

                const start=root.querySelector('.tng-runtime-start');
                const list=root.querySelector('.tng-runtime-list');
                const originalReady=Boolean(list&&list.children.length);
                if(originalReady)return;

                const scripts=[...root.querySelectorAll('script')];
                const source=scripts.find(script=>script.textContent.includes("const root=document.currentScript.closest('.tng-runtime')"));
                if(!source)return;

                try{
                    const patched=source.textContent.replace(
                        "const root=document.currentScript.closest('.tng-runtime'); if(!root)return;",
                        "const root=document.querySelector('.tng-runtime'); if(!root||root.dataset.runtimeRecovered)return; root.dataset.runtimeRecovered='1';"
                    );
                    (0,eval)(patched);
                }catch(error){
                    console.error('TN Game runtime recovery failed',error);
                    if(start){
                        start.addEventListener('click',()=>{
                            root.classList.add('is-started');
                            const notice=root.querySelector('.tng-runtime-error');
                            if(notice){notice.textContent='The quest opened in recovery mode. Reload once if checkpoint details do not appear.';notice.classList.add('is-visible');}
                        },{once:true});
                    }
                }
            };
            if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>setTimeout(boot,50));
            else setTimeout(boot,50);
        })();
        </script>
        <?php
    }
}
