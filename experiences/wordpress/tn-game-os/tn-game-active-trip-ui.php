<?php
/**
 * Plugin Name: TN Game Active Trip UI
 * Description: Live itinerary and stop progress for saved TN Game trips.
 * Version: 0.2.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Active_Trip_UI {
    private const META_KEY = 'tng_active_trip_completed';

    public static function boot(): void {
        add_action('wp_ajax_tng_trip_stop_status', [self::class, 'ajax_status']);
    }

    private static function completed_ids(int $user_id = 0): array {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) return [];
        $ids = get_user_meta($user_id, self::META_KEY, true);
        return is_array($ids) ? array_values(array_unique(array_map('absint', $ids))) : [];
    }

    public static function ajax_status(): void {
        check_ajax_referer('tng_active_trip', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error(['code' => 'login_required'], 401);
        $post_id = absint($_POST['postId'] ?? 0);
        $complete = !empty($_POST['complete']);
        if (!$post_id || get_post_status($post_id) !== 'publish') wp_send_json_error(['code' => 'invalid_post'], 400);
        $ids = self::completed_ids();
        if ($complete && !in_array($post_id, $ids, true)) $ids[] = $post_id;
        if (!$complete) $ids = array_values(array_diff($ids, [$post_id]));
        update_user_meta(get_current_user_id(), self::META_KEY, $ids);
        $saved = class_exists('TNG_Trip_Data') ? TNG_Trip_Data::ids() : [];
        $done = count(array_intersect($saved, $ids));
        wp_send_json_success(['complete' => $complete, 'done' => $done, 'total' => count($saved)]);
    }

    private static function valid_coords($lat, $lng): bool {
        if (!is_numeric($lat) || !is_numeric($lng)) return false;
        $lat=(float)$lat; $lng=(float)$lng;
        return $lat>=-90 && $lat<=90 && $lng>=-180 && $lng<=180 && !($lat===0.0 && $lng===0.0);
    }

    private static function coords_from_value($value): array {
        if (is_array($value)) {
            $lat=$value['lat']??$value['latitude']??null; $lng=$value['lng']??$value['lon']??$value['longitude']??null;
            if (self::valid_coords($lat,$lng)) return [(float)$lat,(float)$lng];
        }
        if (is_string($value) && preg_match('/(-?\d{1,2}\.\d+)\s*[,| ]\s*(-?\d{1,3}\.\d+)/',$value,$m) && self::valid_coords($m[1],$m[2])) return [(float)$m[1],(float)$m[2]];
        return [];
    }

    private static function coordinates(int $id): array {
        foreach ([['_sight_latitude','_sight_longitude'],['sight_latitude','sight_longitude'],['_tng_destination_lat','_tng_destination_lng'],['tng_destination_lat','tng_destination_lng'],['latitude','longitude'],['lat','lng'],['_latitude','_longitude'],['_lat','_lng']] as [$lat_key,$lng_key]) {
            $lat=get_post_meta($id,$lat_key,true); $lng=get_post_meta($id,$lng_key,true);
            if (self::valid_coords($lat,$lng)) return [(float)$lat,(float)$lng];
        }
        foreach (['st_google_map','location','map','google_map','coordinates'] as $key) {
            $coords=self::coords_from_value(get_post_meta($id,$key,true)); if($coords)return $coords;
            if(function_exists('get_field')){$coords=self::coords_from_value(get_field($key,$id));if($coords)return $coords;}
        }
        return [];
    }

    private static function directions(int $id): string {
        $coords=self::coordinates($id);
        $address=get_post_meta($id,'address',true)?:get_post_meta($id,'st_address',true);
        if($coords)return 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($coords[0].','.$coords[1]);
        if($address)return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode((string)$address);
        return home_url('/map/');
    }

    private static function format_miles(int $meters): string {
        $miles=$meters/1609.344;
        return $miles<10?number_format($miles,1).' mi':number_format($miles,0).' mi';
    }

    private static function format_time(int $seconds): string {
        $mins=max(1,(int)round($seconds/60));
        if($mins<60)return $mins.' min';
        $h=(int)floor($mins/60);$m=$mins%60;
        return $m?$h.' hr '.$m.' min':$h.' hr';
    }

    public static function render(): string {
        $logged_in=is_user_logged_in();
        $posts=($logged_in&&class_exists('TNG_Trip_Data'))?TNG_Trip_Data::posts():[];
        $completed=$logged_in?self::completed_ids():[];
        $done=count(array_intersect(array_map(static fn($p)=>$p->ID,$posts),$completed));
        $total=count($posts);$percent=$total?(int)round(($done/$total)*100):0;
        $next=null;foreach($posts as $post){if(!in_array($post->ID,$completed,true)){$next=$post;break;}}
        $route=($logged_in&&class_exists('TNG_Trip_Data'))?TNG_Trip_Data::route_data():[];
        $legs=[];foreach(($route['legs']??[]) as $leg){if(is_array($leg)&&!empty($leg['to']))$legs[(int)$leg['to']]=$leg;}
        wp_localize_script('tng-active-trip','TNGActiveTrip',['ajaxUrl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('tng_active_trip'),'arrivalRadius'=>300]);
        ob_start(); ?>
        <main class="tng-active-trip-screen tng-app-shell">
            <section class="tng-active-trip-hero"><div><span class="tng-eyebrow">Trip mode</span><h1><?php echo $total&&$done===$total?'Trip complete!':'Your Tennessee day.'; ?></h1><p>Follow your saved route, confirm arrival, complete each stop, and keep the day moving.</p></div><div class="tng-active-trip-score"><strong data-tng-trip-progress><?php echo esc_html($done.'/'.$total); ?></strong><small>Stops complete</small></div></section>
            <nav class="tng-trip-tabs" aria-label="Trip planning"><a href="<?php echo esc_url(home_url('/trips/')); ?>">🗺 Trips</a><a href="<?php echo esc_url(home_url('/saved/')); ?>">♡ Saved places</a><a href="<?php echo esc_url(home_url('/trip-builder/')); ?>">☰ Trip builder</a><a class="is-active" href="<?php echo esc_url(home_url('/active-trip/')); ?>">▶ Trip mode</a></nav>
            <?php if(!$logged_in): ?>
                <section class="tng-active-trip-empty"><h2>Sign in to start trip mode.</h2><p>Your itinerary and progress will stay synced to your Explorer account.</p><a class="tng-ui-button" href="<?php echo esc_url(wp_login_url(home_url('/active-trip/'))); ?>">Sign in</a></section>
            <?php elseif(!$posts): ?>
                <section class="tng-active-trip-empty"><h2>Your route needs a few stops.</h2><p>Save places, arrange them, then return here to begin the day.</p><a class="tng-ui-button" href="<?php echo esc_url(home_url('/explore/')); ?>">Find places</a></section>
            <?php else: ?>
                <section class="tng-active-trip-progress-card"><div><span class="tng-eyebrow">Today’s progress</span><h2 data-tng-trip-next-heading><?php echo esc_html($done===$total?'You finished every stop.':($next?'Next: '.get_the_title($next):'Keep exploring')); ?></h2><?php if(!empty($route['distance_m'])&&!empty($route['duration_s'])): ?><p class="tng-active-trip-road-summary">Road itinerary: <?php echo esc_html(self::format_miles((int)$route['distance_m']).' · '.self::format_time((int)$route['duration_s'])); ?></p><?php endif; ?></div><div class="tng-ui-progress"><span data-tng-trip-progress-bar style="width:<?php echo esc_attr((string)$percent); ?>%"></span></div></section>
                <div class="tng-active-trip-layout">
                    <section class="tng-active-trip-route"><div class="tng-section__heading"><div><span class="tng-eyebrow">Your itinerary</span><h2>Stops for today</h2></div><a href="<?php echo esc_url(home_url('/trip-builder/')); ?>">Edit route</a></div><ol class="tng-active-trip-list">
                        <?php foreach($posts as $index=>$post): $is_done=in_array($post->ID,$completed,true);$image=get_the_post_thumbnail_url($post->ID,'medium');$coords=self::coordinates((int)$post->ID);$leg=$legs[(int)$post->ID]??null;$directions=self::directions((int)$post->ID); ?>
                        <li class="tng-active-trip-stop<?php echo $is_done?' is-complete':''; ?>" data-trip-stop="<?php echo esc_attr((string)$post->ID); ?>" data-directions="<?php echo esc_url($directions); ?>"<?php if($coords): ?> data-lat="<?php echo esc_attr((string)$coords[0]); ?>" data-lng="<?php echo esc_attr((string)$coords[1]); ?>"<?php endif; ?>>
                            <span class="tng-active-trip-stop__number"><?php echo $is_done?'✓':esc_html((string)($index+1)); ?></span><span class="tng-active-trip-stop__media"<?php echo $image?' style="background-image:url('.esc_url($image).')"':''; ?>></span>
                            <div class="tng-active-trip-stop__copy"><small><?php echo esc_html(get_post_type_object(get_post_type($post->ID))->labels->singular_name??'Stop'); ?></small><h3><?php echo esc_html(get_the_title($post)); ?></h3><?php if($leg): ?><span class="tng-active-trip-leg">🚗 <?php echo esc_html(self::format_miles((int)$leg['distance_m']).' · '.self::format_time((int)$leg['duration_s'])); ?></span><?php elseif($index===0): ?><span class="tng-active-trip-leg">Start here</span><?php endif; ?><a href="<?php echo esc_url(get_permalink($post)); ?>">View details</a></div>
                            <div class="tng-active-trip-stop__actions"><a href="<?php echo esc_url($directions); ?>" target="_blank" rel="noopener">Directions</a><?php if(!$is_done): ?><button type="button" class="tng-trip-arrive" data-trip-arrive<?php echo !$coords?' disabled':''; ?>>I’m here</button><?php endif; ?><button type="button" data-trip-complete data-post-id="<?php echo esc_attr((string)$post->ID); ?>" aria-pressed="<?php echo $is_done?'true':'false'; ?>"<?php echo (!$is_done&&$coords)?' disabled':''; ?>><?php echo $is_done?'Undo':'Complete stop'; ?></button></div>
                        </li>
                        <?php endforeach; ?>
                    </ol></section>
                    <aside class="tng-active-trip-next"><span class="tng-eyebrow">Next stop</span><?php if($next): $next_leg=$legs[(int)$next->ID]??null; ?><h2 data-tng-next-title><?php echo esc_html(get_the_title($next)); ?></h2><?php if($next_leg): ?><p data-tng-next-leg>🚗 <?php echo esc_html(self::format_miles((int)$next_leg['distance_m']).' · '.self::format_time((int)$next_leg['duration_s'])); ?></p><?php else: ?><p data-tng-next-leg>Open directions when you are ready to continue your trip.</p><?php endif; ?><a class="tng-ui-button" data-tng-next-directions href="<?php echo esc_url(self::directions($next->ID)); ?>" target="_blank" rel="noopener">Get directions</a><a class="tng-ui-button tng-ui-button--secondary" data-tng-next-view href="<?php echo esc_url(get_permalink($next)); ?>">View stop</a><?php else: ?><h2>Adventure complete.</h2><p>You visited every stop in this trip.</p><a class="tng-ui-button" href="<?php echo esc_url(home_url('/completed/')); ?>">View history</a><?php endif; ?></aside>
                </div>
            <?php endif; ?>
        </main>
        <?php return (string)ob_get_clean();
    }
}
TNG_Active_Trip_UI::boot();
