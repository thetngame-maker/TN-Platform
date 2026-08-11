<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Explorer_Profile_Settings implements Module_Interface {
    private const META_TITLE = '_tng_explorer_title';
    private const META_BIO = '_tng_explorer_bio';
    private const META_HOME = '_tng_home_destination';
    private const META_BADGE = '_tng_featured_badge';
    private const META_AVATAR = '_tng_profile_avatar_id';
    private const META_PRIVATE = '_tng_profile_private';
    private const META_SHOWCASE = '_tng_showcase_memories';

    public function id(): string { return 'explorer_profile_settings'; }

    public function register(Container $container): void {
        $container->set('explorer_profile_settings', $this);
        add_shortcode('tng_explorer_settings', [$this, 'shortcode']);
        add_action('wp_ajax_tng_save_explorer_profile', [$this, 'ajax_save']);
        add_filter('pre_get_avatar_data', [$this, 'avatar_data'], 20, 2);
        add_filter('do_shortcode_tag', [$this, 'enhance_profile'], 30, 4);
        add_action('admin_menu', [$this, 'admin_menu'], 84);
    }

    public function boot(Container $container): void {}

    public function admin_menu(): void {
        add_submenu_page('tn-game-os', 'Explorer Profile Settings', 'Profile Settings', 'manage_options', 'tng-os-profile-settings', [$this, 'admin_page']);
    }

    public function admin_page(): void {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap"><h1>Explorer Profile Settings</h1><p>Create a page named <strong>Edit Explorer Profile</strong> and add <code>[tng_explorer_settings]</code>.</p><p>Explorers can customize their display name, title, bio, home destination, featured badge, avatar, privacy, and showcase memories.</p></div>';
    }

    public function shortcode(): string {
        if (!is_user_logged_in()) return '<section class="tng-profile-settings"><div class="tng-profile-settings-empty"><h2>Edit Explorer Profile</h2><p>Sign in to customize your Explorer identity.</p></div></section>';
        $user_id = get_current_user_id();
        $user = wp_get_current_user();
        $events = apply_filters('tng_os_adventure_journal_events', [], $user_id);
        $events = is_array($events) ? array_slice($events, 0, 30) : [];
        $selected = get_user_meta($user_id, self::META_SHOWCASE, true);
        $selected = is_array($selected) ? array_map('sanitize_text_field', $selected) : [];
        $avatar_id = absint(get_user_meta($user_id, self::META_AVATAR, true));
        $avatar = $avatar_id ? wp_get_attachment_image_url($avatar_id, 'thumbnail') : get_avatar_url($user_id, ['size'=>160]);
        ob_start(); ?>
        <section class="tng-profile-settings" data-tng-profile-settings data-nonce="<?php echo esc_attr(wp_create_nonce('tng_save_explorer_profile')); ?>">
            <?php echo $this->styles(); ?>
            <div class="tng-ps-hero"><div><span>TN GAME EXPLORER</span><h1>Customize your profile</h1><p>Choose how your Explorer identity and favorite memories appear to the community.</p></div></div>
            <form class="tng-ps-form" enctype="multipart/form-data">
                <div class="tng-ps-card tng-ps-identity">
                    <div class="tng-ps-avatar-wrap"><img class="tng-ps-avatar" src="<?php echo esc_url($avatar); ?>" alt=""><label class="tng-ps-upload">Change photo<input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" hidden></label><small>JPG, PNG, or WebP. Maximum 5 MB.</small></div>
                    <div class="tng-ps-fields">
                        <label>Display name<input type="text" name="display_name" maxlength="60" value="<?php echo esc_attr($user->display_name); ?>"></label>
                        <label>Explorer title<input type="text" name="explorer_title" maxlength="40" placeholder="Explorer" value="<?php echo esc_attr(get_user_meta($user_id, self::META_TITLE, true)); ?>"></label>
                        <label class="wide">Short bio<textarea name="bio" maxlength="220" rows="4" placeholder="Tell other Explorers what you love discovering."><?php echo esc_textarea(get_user_meta($user_id, self::META_BIO, true)); ?></textarea><small><span data-bio-count>0</span>/220</small></label>
                        <label>Home destination<input type="text" name="home_destination" maxlength="80" placeholder="Tracy City, Tennessee" value="<?php echo esc_attr(get_user_meta($user_id, self::META_HOME, true)); ?>"></label>
                        <label>Featured badge<input type="text" name="featured_badge" maxlength="60" placeholder="Day Explorer" value="<?php echo esc_attr(get_user_meta($user_id, self::META_BADGE, true)); ?>"></label>
                    </div>
                </div>
                <div class="tng-ps-card"><div class="tng-ps-section-head"><div><span>SHOWCASE</span><h2>Featured memories</h2><p>Select up to three Journal memories for the top of your public profile.</p></div><strong data-showcase-count><?php echo count($selected); ?>/3</strong></div>
                    <div class="tng-ps-memory-grid">
                    <?php if (!$events): ?><p class="tng-ps-muted">Complete trips, checkpoints, quests, or photos to create showcase memories.</p><?php else: foreach ($events as $event): $id=sanitize_text_field($event['id']??''); if(!$id)continue; $type=sanitize_key($event['type']??'activity'); ?>
                        <label class="tng-ps-memory"><input type="checkbox" name="showcase[]" value="<?php echo esc_attr($id); ?>" <?php checked(in_array($id,$selected,true)); ?>><span class="tng-ps-memory-icon"><?php echo esc_html($this->icon($type)); ?></span><span><strong><?php echo esc_html($event['title']??'Explorer memory'); ?></strong><small><?php echo esc_html($event['description']??''); ?></small></span></label>
                    <?php endforeach; endif; ?>
                    </div>
                </div>
                <div class="tng-ps-card tng-ps-privacy"><div><span>PRIVACY</span><h2>Profile visibility</h2><p>Public profiles can be shared and viewed by other Explorers. Private profiles remain visible only to you.</p></div><label class="tng-ps-switch"><input type="checkbox" name="private" value="1" <?php checked(get_user_meta($user_id,self::META_PRIVATE,true)==='1'); ?>><span></span><b>Private profile</b></label></div>
                <div class="tng-ps-actions"><button type="submit">Save Explorer profile</button><a href="<?php echo esc_url($this->profile_url($user)); ?>">View profile</a><span data-save-status></span></div>
            </form>
            <?php echo $this->scripts(); ?>
        </section>
        <?php return (string) ob_get_clean();
    }

    public function ajax_save(): void {
        if (!is_user_logged_in()) wp_send_json_error(['message'=>'Sign in required.'], 401);
        check_ajax_referer('tng_save_explorer_profile', 'nonce');
        $user_id = get_current_user_id();
        $display_name = sanitize_text_field(wp_unslash($_POST['display_name'] ?? ''));
        if ($display_name) wp_update_user(['ID'=>$user_id, 'display_name'=>$display_name]);
        update_user_meta($user_id, self::META_TITLE, sanitize_text_field(wp_unslash($_POST['explorer_title'] ?? '')));
        update_user_meta($user_id, self::META_BIO, sanitize_textarea_field(wp_unslash($_POST['bio'] ?? '')));
        update_user_meta($user_id, self::META_HOME, sanitize_text_field(wp_unslash($_POST['home_destination'] ?? '')));
        update_user_meta($user_id, self::META_BADGE, sanitize_text_field(wp_unslash($_POST['featured_badge'] ?? '')));
        update_user_meta($user_id, self::META_PRIVATE, !empty($_POST['private']) ? '1' : '0');
        $showcase = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array)($_POST['showcase'] ?? [])))));
        update_user_meta($user_id, self::META_SHOWCASE, array_slice($showcase, 0, 3));
        if (!empty($_FILES['avatar']['name'])) {
            if (!function_exists('media_handle_upload')) require_once ABSPATH . 'wp-admin/includes/media.php';
            if (!function_exists('wp_handle_upload')) require_once ABSPATH . 'wp-admin/includes/file.php';
            if (!function_exists('wp_generate_attachment_metadata')) require_once ABSPATH . 'wp-admin/includes/image.php';
            if (($_FILES['avatar']['size'] ?? 0) > 5 * MB_IN_BYTES) wp_send_json_error(['message'=>'Profile photo must be smaller than 5 MB.']);
            $attachment_id = media_handle_upload('avatar', 0, [], ['test_form'=>false]);
            if (is_wp_error($attachment_id)) wp_send_json_error(['message'=>$attachment_id->get_error_message()]);
            update_user_meta($user_id, self::META_AVATAR, absint($attachment_id));
        }
        wp_send_json_success(['message'=>'Explorer profile saved.', 'profile_url'=>$this->profile_url(wp_get_current_user())]);
    }

    public function avatar_data(array $args, $id_or_email): array {
        $user = false;
        if ($id_or_email instanceof \WP_User) $user = $id_or_email;
        elseif (is_numeric($id_or_email)) $user = get_user_by('id', absint($id_or_email));
        elseif ($id_or_email instanceof \WP_Comment) $user = get_user_by('id', absint($id_or_email->user_id));
        elseif (is_string($id_or_email)) $user = get_user_by('email', $id_or_email);
        if (!$user) return $args;
        $attachment_id = absint(get_user_meta($user->ID, self::META_AVATAR, true));
        if (!$attachment_id) return $args;
        $url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
        if ($url) { $args['url']=$url; $args['found_avatar']=true; }
        return $args;
    }

    public function enhance_profile(string $output, string $tag, array $attr, array $m): string {
        if ($tag !== 'tng_explorer_profile' || !$output) return $output;
        $requested = sanitize_text_field($_GET['explorer'] ?? ($attr['user'] ?? ''));
        $user = $requested ? (ctype_digit($requested) ? get_user_by('id',absint($requested)) : get_user_by('login',$requested)) : wp_get_current_user();
        if (!$user || !$user->exists()) return $output;
        $bio = sanitize_textarea_field(get_user_meta($user->ID, self::META_BIO, true));
        $title = sanitize_text_field(get_user_meta($user->ID, self::META_TITLE, true));
        $home = sanitize_text_field(get_user_meta($user->ID, self::META_HOME, true));
        $badge = sanitize_text_field(get_user_meta($user->ID, self::META_BADGE, true));
        if ($bio) $output = str_replace('Explore this Tennessee story, discoveries, and milestones.', esc_html($bio), $output);
        $chips='';
        foreach ([['⌁',$title],['⌂',$home],['★',$badge]] as [$icon,$value]) if($value)$chips.='<span class="tng-profile-custom-chip">'.esc_html($icon.' '.$value).'</span>';
        if ($chips) $output = str_replace('</div>\n                </div>\n                <button type="button" class="tng-journal-share"', '<div class="tng-profile-custom-chips">'.$chips.'</div></div>\n                </div>\n                <button type="button" class="tng-journal-share"', $output);
        if (get_current_user_id()===$user->ID) {
            $settings = $this->settings_url();
            $output = str_replace('<button type="button" class="tng-journal-share"', '<a class="tng-profile-edit-link" href="'.esc_url($settings).'">Edit profile</a><button type="button" class="tng-journal-share"', $output);
        }
        $css='<style>.tng-profile-custom-chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}.tng-profile-custom-chip{display:inline-flex;padding:7px 11px;border:1px solid rgba(255,255,255,.25);border-radius:999px;background:rgba(255,255,255,.11);font-size:12px;font-weight:800;color:#fff}.tng-profile-edit-link{display:inline-flex;align-items:center;justify-content:center;padding:13px 18px;border-radius:12px;background:rgba(255,255,255,.14);color:#fff!important;font-weight:800;text-decoration:none;margin-left:auto;margin-right:10px}@media(max-width:700px){.tng-profile-edit-link{margin:12px 0 0}.tng-profile-custom-chips{justify-content:flex-start}}</style>';
        return $css.$output;
    }

    private function profile_url(\WP_User $user): string {
        $page = get_page_by_path('explorer-profile');
        $base = $page ? get_permalink($page) : home_url('/explorer-profile/');
        return add_query_arg('explorer', rawurlencode($user->user_login), $base);
    }
    private function settings_url(): string { $page=get_page_by_path('edit-explorer-profile'); return $page?get_permalink($page):home_url('/edit-explorer-profile/'); }
    private function icon(string $type): string { if(str_contains($type,'trip'))return '✓'; if(str_contains($type,'photo'))return '▣'; if(str_contains($type,'badge')||str_contains($type,'achievement'))return '★'; if(str_contains($type,'checkpoint'))return '⌖'; return '◆'; }

    private function styles(): string { return '<style>
.tng-profile-settings{max-width:1080px;margin:36px auto 80px;color:#18213d}.tng-ps-hero{padding:36px;border-radius:28px;background:linear-gradient(120deg,#202954,#7640a4);color:#fff;margin-bottom:20px}.tng-ps-hero span,.tng-ps-card>div>span,.tng-ps-section-head span{font-size:11px;letter-spacing:.18em;font-weight:900;color:#f6ce4a}.tng-ps-hero h1{font-size:40px;margin:8px 0}.tng-ps-hero p{margin:0;opacity:.82}.tng-ps-form{display:grid;gap:18px}.tng-ps-card{background:#fff;border:1px solid #dfe3ef;border-radius:22px;padding:24px;box-shadow:0 14px 38px rgba(24,33,61,.06)}.tng-ps-identity{display:grid;grid-template-columns:180px 1fr;gap:28px}.tng-ps-avatar-wrap{text-align:center}.tng-ps-avatar{width:132px;height:132px;border-radius:30px;object-fit:cover;border:5px solid #eee8ff}.tng-ps-upload{display:block;margin:14px 0 7px;padding:10px;border-radius:11px;background:#8050df;color:#fff;font-weight:800;cursor:pointer}.tng-ps-avatar-wrap small,.tng-ps-fields small{color:#79819a}.tng-ps-fields{display:grid;grid-template-columns:1fr 1fr;gap:16px}.tng-ps-fields label{font-weight:800}.tng-ps-fields .wide{grid-column:1/-1}.tng-ps-fields input,.tng-ps-fields textarea{display:block;width:100%;box-sizing:border-box;margin-top:7px;border:1px solid #d9ddea;border-radius:12px;padding:13px;font:inherit}.tng-ps-section-head,.tng-ps-privacy{display:flex;justify-content:space-between;align-items:center;gap:20px}.tng-ps-section-head h2,.tng-ps-privacy h2{margin:5px 0}.tng-ps-section-head p,.tng-ps-privacy p{margin:0;color:#68718a}.tng-ps-memory-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-top:20px}.tng-ps-memory{display:flex;gap:12px;align-items:center;border:1px solid #e1e4ef;border-radius:16px;padding:14px;cursor:pointer}.tng-ps-memory:has(input:checked){border-color:#8353e5;background:#f4efff}.tng-ps-memory input{accent-color:#8353e5}.tng-ps-memory-icon{width:38px;height:38px;border-radius:11px;background:#eee8ff;display:grid;place-items:center;color:#7441d1;font-weight:900}.tng-ps-memory strong,.tng-ps-memory small{display:block}.tng-ps-memory small{color:#788097;margin-top:3px}.tng-ps-switch{display:flex;align-items:center;gap:10px}.tng-ps-switch input{display:none}.tng-ps-switch span{width:52px;height:29px;border-radius:999px;background:#d8dce7;position:relative}.tng-ps-switch span:after{content:"";width:23px;height:23px;border-radius:50%;background:#fff;position:absolute;left:3px;top:3px;transition:.2s}.tng-ps-switch input:checked+span{background:#8050df}.tng-ps-switch input:checked+span:after{transform:translateX(23px)}.tng-ps-actions{display:flex;align-items:center;gap:12px}.tng-ps-actions button,.tng-ps-actions a{border:0;border-radius:13px;padding:14px 20px;font-weight:900;text-decoration:none}.tng-ps-actions button{background:#8050df;color:#fff}.tng-ps-actions a{background:#192342;color:#fff}.tng-ps-muted{color:#788097}@media(max-width:760px){.tng-profile-settings{margin:18px 12px 60px}.tng-ps-hero{padding:26px}.tng-ps-hero h1{font-size:32px}.tng-ps-identity{grid-template-columns:1fr}.tng-ps-fields,.tng-ps-memory-grid{grid-template-columns:1fr}.tng-ps-section-head,.tng-ps-privacy{align-items:flex-start;flex-direction:column}.tng-ps-actions{flex-wrap:wrap}}
</style>'; }

    private function scripts(): string { return '<script>(function(){var root=document.querySelector("[data-tng-profile-settings]");if(!root||root.dataset.ready)return;root.dataset.ready="1";var form=root.querySelector("form"),bio=form.querySelector("[name=bio]"),count=root.querySelector("[data-bio-count]"),file=form.querySelector("[name=avatar]"),img=root.querySelector(".tng-ps-avatar"),status=root.querySelector("[data-save-status]");function update(){if(count)count.textContent=bio.value.length;var checked=form.querySelectorAll("[name=\"showcase[]\"]:checked"),label=root.querySelector("[data-showcase-count]");if(label)label.textContent=checked.length+"/3";form.querySelectorAll("[name=\"showcase[]\"]:not(:checked)").forEach(function(x){x.disabled=checked.length>=3;});}bio.addEventListener("input",update);form.addEventListener("change",update);if(file)file.addEventListener("change",function(){if(file.files[0])img.src=URL.createObjectURL(file.files[0]);});form.addEventListener("submit",function(e){e.preventDefault();status.textContent="Saving…";var data=new FormData(form);data.append("action","tng_save_explorer_profile");data.append("nonce",root.dataset.nonce);fetch(window.ajaxurl||"'.esc_js(admin_url('admin-ajax.php')).'",{method:"POST",credentials:"same-origin",body:data}).then(function(r){return r.json();}).then(function(r){if(!r.success)throw new Error((r.data&&r.data.message)||"Unable to save profile.");status.textContent=r.data.message;}).catch(function(e){status.textContent=e.message;});});update();})();</script>'; }
}
