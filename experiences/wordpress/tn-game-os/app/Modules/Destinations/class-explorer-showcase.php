<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Explorer_Showcase implements Module_Interface {
    private const META_SHOWCASE = '_tng_showcase_memories';

    public function id(): string { return 'explorer_showcase'; }

    public function register(Container $container): void {
        $container->set('explorer_showcase', $this);
        add_filter('do_shortcode_tag', [$this, 'enhance_profile'], 45, 4);
        add_action('admin_menu', [$this, 'admin_menu'], 86);
    }

    public function boot(Container $container): void {}

    public function admin_menu(): void {
        add_submenu_page('tn-game-os', 'Explorer Showcase', 'Explorer Showcase', 'manage_options', 'tng-os-explorer-showcase', [$this, 'admin_page']);
    }

    public function admin_page(): void {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap"><h1>Explorer Showcase</h1><p>Selected memories from Explorer Profile Settings are displayed as large cards on public profiles. The profile also receives favorite-place insights and a shareable Explorer identity card.</p></div>';
    }

    public function enhance_profile(string $output, string $tag, array $attr, array $m): string {
        if ($tag !== 'tng_explorer_profile' || !$output || str_contains($output, 'data-tng-explorer-showcase')) return $output;
        $user = $this->resolve_user($attr);
        if (!$user) return $output;

        $events = apply_filters('tng_os_adventure_journal_events', [], $user->ID);
        $events = is_array($events) ? $events : [];
        $selected = get_user_meta($user->ID, self::META_SHOWCASE, true);
        $selected = is_array($selected) ? array_values(array_filter(array_map('sanitize_text_field', $selected))) : [];
        $showcase = $this->selected_events($events, $selected);
        $stats = apply_filters('tng_os_explorer_profile_stats', [], $user->ID);
        $stats = is_array($stats) ? $stats : [];
        $favorite = $this->favorite_place($events, $user->ID);
        $photos = count(array_filter($events, static fn($e) => str_contains(sanitize_key($e['type'] ?? ''), 'photo')));
        $trips = absint($stats['completed_trips'] ?? $stats['trips'] ?? 0);
        $hours = round(absint($stats['trip_minutes'] ?? $stats['total_trip_minutes'] ?? 0) / 60, 1);

        $block = $this->render_showcase($user, $showcase, $favorite, $photos, $trips, $hours);
        $markers = ['<div class="tng-public-explorer-section"><h2>Recent memories</h2>', '<div class="tng-journal-tabs" role="tablist">'];
        foreach ($markers as $marker) {
            if (str_contains($output, $marker)) return str_replace($marker, $block . $marker, $output);
        }
        return $output . $block;
    }

    private function resolve_user(array $attr): ?\WP_User {
        $requested = sanitize_text_field($_GET['explorer'] ?? ($attr['user'] ?? $attr['username'] ?? ''));
        if (!$requested) return is_user_logged_in() ? wp_get_current_user() : null;
        if (ctype_digit($requested)) $user = get_user_by('id', absint($requested));
        else { $user = get_user_by('login', $requested); if (!$user) $user = get_user_by('slug', $requested); }
        return $user instanceof \WP_User ? $user : null;
    }

    private function selected_events(array $events, array $selected): array {
        $by_id = [];
        foreach ($events as $event) if (is_array($event) && !empty($event['id'])) $by_id[sanitize_text_field($event['id'])] = $event;
        $out = [];
        foreach ($selected as $id) if (isset($by_id[$id])) $out[] = $by_id[$id];
        if (!$out) {
            usort($events, static fn($a,$b)=>(strtotime($b['date']??'')?:0)<=>(strtotime($a['date']??'')?:0));
            $out = array_slice(array_values(array_filter($events, 'is_array')), 0, 3);
        }
        return array_slice($out, 0, 3);
    }

    private function favorite_place(array $events, int $user_id): string {
        $counts = [];
        foreach ($events as $event) {
            if (!is_array($event)) continue;
            $meta = is_array($event['meta'] ?? null) ? $event['meta'] : [];
            $place = sanitize_text_field($meta['destination'] ?? $meta['place'] ?? $meta['location'] ?? '');
            $post_id = absint($meta['post_id'] ?? $meta['object_id'] ?? 0);
            if (!$place && $post_id) $place = get_the_title($post_id);
            if ($place) $counts[$place] = ($counts[$place] ?? 0) + 1;
        }
        if ($counts) { arsort($counts); return (string) array_key_first($counts); }
        return sanitize_text_field(get_user_meta($user_id, '_tng_home_destination', true)) ?: 'Tennessee';
    }

    private function render_showcase(\WP_User $user, array $events, string $favorite, int $photos, int $trips, float $hours): string {
        $display = $user->display_name ?: $user->user_login;
        $title = sanitize_text_field(get_user_meta($user->ID, '_tng_explorer_title', true)) ?: 'Explorer';
        $badge = sanitize_text_field(get_user_meta($user->ID, '_tng_featured_badge', true)) ?: 'TN Game Explorer';
        $avatar = get_avatar_url($user->ID, ['size'=>240]);
        ob_start(); ?>
        <section class="tng-explorer-showcase" data-tng-explorer-showcase>
            <?php echo $this->styles(); ?>
            <div class="tng-es-head"><div><span>EXPLORER SHOWCASE</span><h2>Featured adventures</h2><p>The places and memories that define this Explorer story.</p></div><button type="button" data-open-identity-card>Share Explorer card</button></div>
            <div class="tng-es-insights">
                <div><small>Favorite place</small><strong><?php echo esc_html($favorite); ?></strong></div>
                <div><small>Trips completed</small><strong><?php echo esc_html(number_format_i18n($trips)); ?></strong></div>
                <div><small>Adventure hours</small><strong><?php echo esc_html(number_format_i18n($hours, 1)); ?></strong></div>
                <div><small>Photos shared</small><strong><?php echo esc_html(number_format_i18n($photos)); ?></strong></div>
            </div>
            <div class="tng-es-grid">
                <?php foreach ($events as $i => $event): $meta=is_array($event['meta']??null)?$event['meta']:[]; $image=$this->event_image($meta); $type=sanitize_key($event['type']??'activity'); ?>
                    <article class="tng-es-card <?php echo $i===0?'is-featured':''; ?>"<?php if($image): ?> style="--tng-es-image:url('<?php echo esc_url($image); ?>')"<?php endif; ?>>
                        <div class="tng-es-overlay"></div><div class="tng-es-card-content"><span><?php echo esc_html($this->label($type)); ?></span><h3><?php echo esc_html($event['title']??'Explorer memory'); ?></h3><p><?php echo esc_html($event['description']??'A Tennessee discovery.'); ?></p><?php if(!empty($meta['xp'])):?><b>+<?php echo absint($meta['xp']); ?> XP</b><?php endif; ?></div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="tng-es-modal" data-identity-modal hidden><div class="tng-es-modal-card"><button type="button" class="tng-es-close" data-close-identity>×</button><div class="tng-es-identity" data-identity-card><div class="tng-es-brand">THE TN GAME</div><div class="tng-es-person"><img src="<?php echo esc_url($avatar); ?>" alt=""><div><small>TN GAME EXPLORER</small><h2><?php echo esc_html($display); ?></h2><p><?php echo esc_html($title); ?> · <?php echo esc_html($badge); ?></p></div></div><div class="tng-es-card-stats"><div><strong><?php echo esc_html($trips); ?></strong><span>Trips</span></div><div><strong><?php echo esc_html(number_format_i18n($hours,1)); ?></strong><span>Hours</span></div><div><strong><?php echo esc_html($photos); ?></strong><span>Photos</span></div></div><div class="tng-es-favorite">Favorite place · <?php echo esc_html($favorite); ?></div><footer>Explore Tennessee. Build your story.</footer></div><div class="tng-es-modal-actions"><button type="button" data-share-identity>Share card</button><button type="button" data-save-identity>Save image</button></div></div></div>
            <?php echo $this->scripts($display, $title, $badge, $favorite, $avatar, $trips, $hours, $photos); ?>
        </section>
        <?php return (string) ob_get_clean();
    }

    private function event_image(array $meta): string {
        if (!empty($meta['image'])) return esc_url_raw($meta['image']);
        $attachment = absint($meta['attachment_id'] ?? 0); if ($attachment) return (string) wp_get_attachment_image_url($attachment, 'large');
        $post = absint($meta['post_id'] ?? $meta['object_id'] ?? 0); if ($post && has_post_thumbnail($post)) return (string) get_the_post_thumbnail_url($post, 'large');
        return '';
    }
    private function label(string $type): string { if(str_contains($type,'trip'))return 'Travel day'; if(str_contains($type,'photo'))return 'Photo memory'; if(str_contains($type,'badge')||str_contains($type,'achievement'))return 'Achievement'; if(str_contains($type,'checkpoint'))return 'Discovery'; return 'Explorer memory'; }

    private function styles(): string { return '<style>
.tng-explorer-showcase{margin:18px 0;color:#18213d}.tng-es-head{display:flex;align-items:end;justify-content:space-between;gap:18px;padding:24px 4px 16px}.tng-es-head span{font-size:11px;letter-spacing:.18em;font-weight:900;color:#7440ca}.tng-es-head h2{margin:5px 0;font-size:28px}.tng-es-head p{margin:0;color:#707991}.tng-es-head button,.tng-es-modal-actions button{border:0;border-radius:13px;padding:13px 17px;background:#7f4ce0;color:#fff;font-weight:850;cursor:pointer}.tng-es-insights{display:grid;grid-template-columns:2fr repeat(3,1fr);gap:12px;margin-bottom:14px}.tng-es-insights div{padding:16px 18px;border:1px solid #e0e4ef;border-radius:17px;background:#fff}.tng-es-insights small{display:block;color:#7b8398;text-transform:uppercase;letter-spacing:.1em;font-weight:800;font-size:9px}.tng-es-insights strong{display:block;margin-top:5px;font-size:17px}.tng-es-grid{display:grid;grid-template-columns:1.45fr 1fr 1fr;gap:14px}.tng-es-card{position:relative;min-height:250px;border-radius:22px;overflow:hidden;background:linear-gradient(135deg,#26305c,#7542a0);background-image:linear-gradient(180deg,rgba(15,20,45,.08),rgba(15,20,45,.88)),var(--tng-es-image);background-size:cover;background-position:center;color:#fff}.tng-es-card.is-featured{min-height:330px}.tng-es-overlay{position:absolute;inset:0;background:linear-gradient(180deg,transparent 30%,rgba(17,22,49,.86))}.tng-es-card-content{position:absolute;left:0;right:0;bottom:0;padding:22px}.tng-es-card-content span{font-size:10px;letter-spacing:.15em;font-weight:900;color:#f5d14e}.tng-es-card-content h3{color:#fff;margin:7px 0;font-size:23px;line-height:1.12}.tng-es-card-content p{margin:0;color:rgba(255,255,255,.8)}.tng-es-card-content b{display:inline-block;margin-top:10px;padding:6px 9px;border-radius:999px;background:rgba(255,255,255,.15)}.tng-es-modal[hidden]{display:none}.tng-es-modal{position:fixed;z-index:100050;inset:0;background:rgba(18,23,49,.72);display:grid;place-items:center;padding:18px}.tng-es-modal-card{position:relative;width:min(520px,100%);padding:22px;border-radius:25px;background:#f7f8fc}.tng-es-close{position:absolute;right:10px;top:10px;width:40px;height:40px;border:0;border-radius:50%;background:#fff;font-size:27px;cursor:pointer}.tng-es-identity{padding:28px;border-radius:22px;background:linear-gradient(140deg,#202953,#7541a0);color:#fff;box-shadow:0 18px 50px rgba(25,31,67,.22)}.tng-es-brand{color:#f4cf4c;font-size:11px;letter-spacing:.2em;font-weight:900}.tng-es-person{display:flex;gap:16px;align-items:center;margin:46px 0 28px}.tng-es-person img{width:82px;height:82px;border-radius:23px;object-fit:cover;border:4px solid rgba(255,255,255,.2)}.tng-es-person small{letter-spacing:.15em;font-weight:900}.tng-es-person h2{color:#fff;margin:5px 0;font-size:30px}.tng-es-person p{margin:0;opacity:.8}.tng-es-card-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.tng-es-card-stats div{padding:13px;border:1px solid rgba(255,255,255,.18);border-radius:13px;background:rgba(255,255,255,.1)}.tng-es-card-stats strong,.tng-es-card-stats span{display:block}.tng-es-card-stats strong{font-size:24px}.tng-es-card-stats span{text-transform:uppercase;font-size:9px;letter-spacing:.12em}.tng-es-favorite{margin-top:14px;padding:13px;border-radius:13px;background:rgba(255,255,255,.1)}.tng-es-identity footer{margin-top:28px;opacity:.72}.tng-es-modal-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px}.tng-es-modal-actions button:last-child{background:#18213d}@media(max-width:760px){.tng-es-head{align-items:flex-start;flex-direction:column}.tng-es-insights{grid-template-columns:1fr 1fr}.tng-es-grid{grid-template-columns:1fr}.tng-es-card,.tng-es-card.is-featured{min-height:260px}.tng-es-modal-actions{grid-template-columns:1fr}}
</style>'; }

    private function scripts(string $display,string $title,string $badge,string $favorite,string $avatar,int $trips,float $hours,int $photos): string {
        return '<script>(function(){var r=document.currentScript.closest("[data-tng-explorer-showcase]");if(!r)return;var m=r.querySelector("[data-identity-modal]");r.querySelector("[data-open-identity-card]").onclick=function(){m.hidden=false};r.querySelector("[data-close-identity]").onclick=function(){m.hidden=true};m.addEventListener("click",function(e){if(e.target===m)m.hidden=true});var d='.wp_json_encode(['display'=>$display,'title'=>$title,'badge'=>$badge,'favorite'=>$favorite,'avatar'=>$avatar,'trips'=>$trips,'hours'=>$hours,'photos'=>$photos]).';async function canvas(){var c=document.createElement("canvas"),x=c.getContext("2d");c.width=1080;c.height=1080;var g=x.createLinearGradient(0,0,1080,1080);g.addColorStop(0,"#202953");g.addColorStop(1,"#7a43a5");x.fillStyle=g;x.fillRect(0,0,1080,1080);x.fillStyle="#f4cf4c";x.font="bold 28px sans-serif";x.fillText("THE TN GAME",75,90);x.fillStyle="#fff";x.font="bold 62px sans-serif";x.fillText(d.display,75,390);x.font="30px sans-serif";x.fillStyle="rgba(255,255,255,.8)";x.fillText(d.title+" · "+d.badge,75,440);x.font="bold 52px sans-serif";x.fillStyle="#fff";x.fillText(d.trips+" Trips",75,590);x.fillText(d.hours+" Adventure Hours",75,675);x.fillText(d.photos+" Photos",75,760);x.font="30px sans-serif";x.fillStyle="#f4cf4c";x.fillText("Favorite place · "+d.favorite,75,860);x.fillStyle="rgba(255,255,255,.72)";x.fillText("Explore Tennessee. Build your story.",75,990);return c}r.querySelector("[data-save-identity]").onclick=async function(){var c=await canvas(),a=document.createElement("a");a.download="tn-game-explorer-card.png";a.href=c.toDataURL("image/png");a.click()};r.querySelector("[data-share-identity]").onclick=async function(){var c=await canvas();c.toBlob(async function(b){var f=new File([b],"tn-game-explorer-card.png",{type:"image/png"});try{if(navigator.canShare&&navigator.canShare({files:[f]}))await navigator.share({title:d.display+" on The TN Game",text:"See my Tennessee Explorer story.",files:[f]});else if(navigator.share)await navigator.share({title:d.display+" on The TN Game",text:"See my Tennessee Explorer story.",url:location.href});else await navigator.clipboard.writeText(location.href)}catch(e){}},"image/png")}})();</script>';
    }
}
