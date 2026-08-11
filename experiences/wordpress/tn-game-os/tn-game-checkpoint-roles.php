<?php
/**
 * TN Game Checkpoint Roles
 * Adds semantic roles to checkpoints without changing verification or XP behavior.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Checkpoint_Roles {
    private static array $roles = [
        'trail_start' => 'Trail Start',
        'top_sight' => 'Top Sight',
        'route' => 'Route Checkpoint',
        'bonus' => 'Bonus Stop',
        'trail_finish' => 'Trail Finish',
    ];

    public static function boot(): void {
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets'], 18);
        add_action('save_post_tng_game', [__CLASS__, 'save_roles'], 35, 2);
        add_action('wp_enqueue_scripts', [__CLASS__, 'frontend_assets'], 96);
    }

    private static function infer_role(array $cp): string {
        $role = sanitize_key((string)($cp['role'] ?? ''));
        if (isset(self::$roles[$role])) return $role;
        $title = strtolower(trim((string)($cp['title'] ?? '')));
        if (preg_match('/trail start|start point|trailhead/', $title)) return 'trail_start';
        if (preg_match('/trail finish|finish point|route finish/', $title)) return 'trail_finish';
        if (!empty($cp['sight_id']) || !empty($cp['top_sight_id'])) return 'top_sight';
        return 'route';
    }

    public static function admin_assets(string $hook): void {
        if (!in_array($hook, ['post.php','post-new.php'], true)) return;
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'tng_game') return;

        wp_register_style('tng-checkpoint-roles-admin', false, [], TNG_OS_VERSION);
        wp_enqueue_style('tng-checkpoint-roles-admin');
        wp_add_inline_style('tng-checkpoint-roles-admin', '
            .tng-cp-role-wrap{display:block;margin-top:7px}.tng-cp-role-wrap label{display:block;margin-bottom:3px;color:#68756d;font-size:9px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.tng-cp-role-wrap select{width:100%;max-width:210px;font-size:11px}.tng-role-chip{display:inline-flex;margin-top:5px;padding:3px 7px;border-radius:999px;background:#edf5ef;color:#17613f;font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.05em}
        ');
        wp_register_script('tng-checkpoint-roles-admin', '', [], TNG_OS_VERSION, true);
        wp_enqueue_script('tng-checkpoint-roles-admin');
        wp_localize_script('tng-checkpoint-roles-admin', 'TNG_CHECKPOINT_ROLES', ['roles'=>self::$roles]);
        wp_add_inline_script('tng-checkpoint-roles-admin', <<<'JS'
(()=>{
 const root=()=>document.getElementById('tng-checkpoint-rows');
 const rows=()=>[...(root()?.querySelectorAll('.tng-cp-row')||[])];
 const field=(row,key)=>row?.querySelector(`[name$="[${key}]"]`);
 const infer=row=>{
   const title=(field(row,'title')?.value||'').trim().toLowerCase();
   if(/trail start|start point|trailhead/.test(title))return'trail_start';
   if(/trail finish|finish point|route finish/.test(title))return'trail_finish';
   if(field(row,'sight_id')?.value)return'top_sight';
   return'route';
 };
 const renumber=()=>rows().forEach((row,i)=>{
   const sel=row.querySelector('.tng-cp-role-select');
   if(sel)sel.name=`tng_cp[${i}][role]`;
 });
 const decorate=row=>{
   if(!row||row.classList.contains('tng-cp-head')||row.querySelector('.tng-cp-role-wrap'))return;
   const title=field(row,'title');if(!title)return;
   const host=title.closest('span')||title.parentElement;
   const wrap=document.createElement('span');wrap.className='tng-cp-role-wrap';
   const label=document.createElement('label');label.textContent='Checkpoint role';
   const sel=document.createElement('select');sel.className='tng-cp-role-select';
   const roles=(window.TNG_CHECKPOINT_ROLES&&TNG_CHECKPOINT_ROLES.roles)||{};
   Object.entries(roles).forEach(([value,text])=>{const o=document.createElement('option');o.value=value;o.textContent=text;sel.appendChild(o);});
   const existing=field(row,'role')?.value||row.dataset.tngRole||'';
   sel.value=roles[existing]?existing:infer(row);
   wrap.append(label,sel);host.appendChild(wrap);
   title.addEventListener('change',()=>{if(!sel.dataset.manual)sel.value=infer(row);});
   const sight=field(row,'sight_id');if(sight)sight.addEventListener('change',()=>{if(!sel.dataset.manual)sel.value=infer(row);});
   sel.addEventListener('change',()=>{sel.dataset.manual='1';document.dispatchEvent(new CustomEvent('tng:checkpoint-form-changed'));});
 };
 const scan=()=>{rows().forEach(decorate);renumber();};
 const start=()=>{const r=root();if(!r)return false;scan();new MutationObserver(()=>setTimeout(scan,0)).observe(r,{childList:true,subtree:true});r.addEventListener('input',e=>{if(e.target?.name?.endsWith('[title]')){const row=e.target.closest('.tng-cp-row'),sel=row?.querySelector('.tng-cp-role-select');if(sel&&!sel.dataset.manual)sel.value=infer(row);}});return true;};
 let tries=0,t=setInterval(()=>{if(start()||++tries>50)clearInterval(t)},120);start();
})();
JS
        ,'after');
    }

    public static function save_roles(int $post_id, WP_Post $post): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id) || !current_user_can('edit_post', $post_id)) return;
        $nonce = sanitize_text_field(wp_unslash($_POST['tng_game_checkpoints_nonce'] ?? ''));
        if (!$nonce || !wp_verify_nonce($nonce, 'tng_save_game_checkpoints')) return;
        $posted = $_POST['tng_cp'] ?? [];
        if (!is_array($posted)) return;
        $saved = get_post_meta($post_id, 'tng_game_checkpoints', true);
        if (!is_array($saved)) return;
        foreach ($saved as $i => &$cp) {
            if (!is_array($cp)) continue;
            $raw = is_array($posted[$i] ?? null) ? $posted[$i] : [];
            $role = sanitize_key(wp_unslash($raw['role'] ?? ''));
            if (!isset(self::$roles[$role])) $role = self::infer_role($cp);
            $cp['role'] = $role;
        }
        unset($cp);
        update_post_meta($post_id, 'tng_game_checkpoints', $saved);
    }

    private static function gameplay_request(): bool {
        if (is_admin()) return false;
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        return strpos($uri, '/game-play/') !== false || (function_exists('is_page') && is_page('game-play'));
    }

    public static function frontend_assets(): void {
        if (!self::gameplay_request()) return;
        $game_id = absint($_GET['game'] ?? 0);
        if (!$game_id) return;
        $cps = get_post_meta($game_id, 'tng_game_checkpoints', true);
        if (!is_array($cps) || !$cps) return;
        $data = [];
        foreach ($cps as $cp) {
            if (!is_array($cp)) continue;
            $role = self::infer_role($cp);
            $data[] = ['role'=>$role,'label'=>self::$roles[$role] ?? 'Checkpoint'];
        }
        wp_register_style('tng-checkpoint-roles-front', false, [], TNG_OS_VERSION);
        wp_enqueue_style('tng-checkpoint-roles-front');
        wp_add_inline_style('tng-checkpoint-roles-front', '
          .tng-runtime-stop__role{display:inline-flex;align-items:center;margin:0 0 5px;padding:4px 7px;border-radius:999px;background:#eef4ef;color:#365847;font-size:9px;font-weight:900;letter-spacing:.07em;text-transform:uppercase}.tng-runtime-stop[data-checkpoint-role="trail_start"] .tng-runtime-stop__role{background:#fff0e8;color:#d94e0d}.tng-runtime-stop[data-checkpoint-role="trail_finish"] .tng-runtime-stop__role{background:#173b2a;color:#fff}.tng-runtime-stop[data-checkpoint-role="top_sight"] .tng-runtime-stop__role{background:#e9f4ec;color:#17613f}.tng-runtime-stop[data-checkpoint-role="bonus"] .tng-runtime-stop__role{background:#fff6d9;color:#7a5b00}.tng-runtime-stop.is-next[data-checkpoint-role="trail_start"] .tng-runtime-action::before{content:"🥾 Trail start check-in"}.tng-runtime-stop.is-next[data-checkpoint-role="trail_finish"] .tng-runtime-action::before{content:"🏁 Trail finish check-in"}.tng-runtime-stop.is-next[data-checkpoint-role="top_sight"] .tng-runtime-action::before{content:"📍 Top Sight check-in"}.tng-runtime-stop.is-next[data-checkpoint-role="bonus"] .tng-runtime-action::before{content:"⭐ Bonus stop"}
        ');
        wp_register_script('tng-checkpoint-roles-front', '', [], TNG_OS_VERSION, true);
        wp_enqueue_script('tng-checkpoint-roles-front');
        wp_localize_script('tng-checkpoint-roles-front', 'TNG_GAME_CHECKPOINT_ROLE_DATA', $data);
        wp_add_inline_script('tng-checkpoint-roles-front', <<<'JS'
(()=>{
 const apply=()=>{
   const stops=[...document.querySelectorAll('.tng-runtime-stop')];if(!stops.length)return false;
   const data=window.TNG_GAME_CHECKPOINT_ROLE_DATA||[];
   stops.forEach((stop,i)=>{const d=data[i];if(!d)return;stop.dataset.checkpointRole=d.role;if(!stop.querySelector('.tng-runtime-stop__role')){const copy=stop.querySelector('.tng-runtime-stop__copy');if(copy){const badge=document.createElement('span');badge.className='tng-runtime-stop__role';badge.textContent=d.label;copy.prepend(badge);}}});
   return true;
 };
 let tries=0,t=setInterval(()=>{if(apply()||++tries>60)clearInterval(t)},150);apply();
})();
JS
        ,'after');
    }
}
TNG_Checkpoint_Roles::boot();
