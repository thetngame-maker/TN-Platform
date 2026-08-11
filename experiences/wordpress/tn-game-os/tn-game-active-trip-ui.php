<?php
/**
 * Plugin Name: TN Game Active Trip UI
 * Description: Live itinerary and stop progress for saved TN Game trips.
 * Version: 0.4.0
 * Author: The TN Game
 */
if (!defined('ABSPATH')) exit;

final class TNG_Active_Trip_UI {
    private const META_KEY = 'tng_active_trip_completed';
    private const SKIP_META_KEY = 'tng_active_trip_skipped';

    public static function boot(): void {
        add_action('wp_ajax_tng_trip_stop_status', [self::class, 'ajax_status']);
        add_action('wp_ajax_tng_trip_skip_status', [self::class, 'ajax_skip_status']);
        add_action('wp_enqueue_scripts', [self::class, 'assets'], 90);
    }

    public static function assets(): void {
        if (!class_exists('TNG_OS\\Platform\\App_Router')) return;
        $route = TNG_OS\Platform\App_Router::current_route();
        if (!in_array($route, ['active-trip','trip-mode'], true)) return;
        wp_enqueue_style('tng-active-trip-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
        wp_enqueue_script('tng-active-trip-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
        wp_enqueue_style('tng-active-trip-recovery', plugin_dir_url(__FILE__) . 'assets/frontend/active-trip-recovery.css', [], '0.1.0');
    }

    private static function completed_ids(int $user_id = 0): array {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) return [];
        $ids = get_user_meta($user_id, self::META_KEY, true);
        return is_array($ids) ? array_values(array_unique(array_map('absint', $ids))) : [];
    }

    private static function skipped_stops(int $user_id = 0): array {
        $user_id = $user_id ?: get_current_user_id();
        if (!$user_id) return [];
        $raw = get_user_meta($user_id, self::SKIP_META_KEY, true);
        if (!is_array($raw)) return [];
        $clean = [];
        foreach ($raw as $id => $entry) {
            $id = absint($id);
            if (!$id) continue;
            if (is_array($entry)) {
                $clean[$id] = [
                    'reason' => sanitize_key($entry['reason'] ?? 'changed_plans'),
                    'time' => sanitize_text_field($entry['time'] ?? ''),
                ];
            } else {
                $clean[$id] = ['reason' => sanitize_key((string)$entry), 'time' => ''];
            }
        }
        return $clean;
    }

    private static function saved_ids(): array {
        return class_exists('TNG_Trip_Data') ? array_values(array_map('absint', TNG_Trip_Data::ids())) : [];
    }

    public static function ajax_status(): void {
        check_ajax_referer('tng_active_trip', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error(['code' => 'login_required'], 401);
        $post_id = absint($_POST['postId'] ?? 0);
        $complete = !empty($_POST['complete']);
        if (!$post_id || get_post_status($post_id) !== 'publish') wp_send_json_error(['code' => 'invalid_post'], 400);

        $user_id = get_current_user_id();
        $ids = self::completed_ids($user_id);
        $skipped = self::skipped_stops($user_id);
        if ($complete && !in_array($post_id, $ids, true)) $ids[] = $post_id;
        if (!$complete) $ids = array_values(array_diff($ids, [$post_id]));
        if ($complete && isset($skipped[$post_id])) {
            unset($skipped[$post_id]);
            update_user_meta($user_id, self::SKIP_META_KEY, $skipped);
        }
        update_user_meta($user_id, self::META_KEY, $ids);

        $saved = self::saved_ids();
        $done = count(array_intersect($saved, $ids));
        $skip_count = count(array_intersect($saved, array_keys($skipped)));
        wp_send_json_success([
            'complete' => $complete,
            'done' => $done,
            'skipped' => $skip_count,
            'resolved' => $done + $skip_count,
            'total' => count($saved),
        ]);
    }

    public static function ajax_skip_status(): void {
        check_ajax_referer('tng_active_trip', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error(['code' => 'login_required'], 401);
        $post_id = absint($_POST['postId'] ?? 0);
        $skip = !empty($_POST['skip']);
        $allowed_reasons = ['closed','inaccessible','weather','changed_plans','other'];
        $reason = sanitize_key($_POST['reason'] ?? 'changed_plans');
        if (!in_array($reason, $allowed_reasons, true)) $reason = 'other';
        if (!$post_id || get_post_status($post_id) !== 'publish') wp_send_json_error(['code' => 'invalid_post'], 400);

        $user_id = get_current_user_id();
        $skipped = self::skipped_stops($user_id);
        $completed = self::completed_ids($user_id);
        if ($skip) {
            $skipped[$post_id] = ['reason' => $reason, 'time' => current_time('mysql')];
            if (in_array($post_id, $completed, true)) {
                $completed = array_values(array_diff($completed, [$post_id]));
                update_user_meta($user_id, self::META_KEY, $completed);
            }
        } else {
            unset($skipped[$post_id]);
        }
        update_user_meta($user_id, self::SKIP_META_KEY, $skipped);

        $saved = self::saved_ids();
        $done = count(array_intersect($saved, $completed));
        $skip_count = count(array_intersect($saved, array_keys($skipped)));
        wp_send_json_success([
            'skip' => $skip,
            'reason' => $reason,
            'done' => $done,
            'skipped' => $skip_count,
            'resolved' => $done + $skip_count,
            'total' => count($saved),
        ]);
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

    private static function reason_label(string $reason): string {
        $labels = ['closed'=>'Closed','inaccessible'=>'Inaccessible','weather'=>'Weather','changed_plans'=>'Changed plans','other'=>'Other'];
        return $labels[$reason] ?? 'Skipped';
    }

    public static function render(): string {
        $logged_in=is_user_logged_in();
        $posts=($logged_in&&class_exists('TNG_Trip_Data'))?TNG_Trip_Data::posts():[];
        $completed=$logged_in?self::completed_ids():[];
        $skipped=$logged_in?self::skipped_stops():[];
        $post_ids=array_map(static fn($p)=>$p->ID,$posts);
        $done=count(array_intersect($post_ids,$completed));
        $skip_count=count(array_intersect($post_ids,array_keys($skipped)));
        $resolved=$done+$skip_count;
        $total=count($posts);$percent=$total?(int)round(($resolved/$total)*100):0;
        $next=null;foreach($posts as $post){if(!in_array($post->ID,$completed,true)&&!isset($skipped[$post->ID])){$next=$post;break;}}
        $route=($logged_in&&class_exists('TNG_Trip_Data'))?TNG_Trip_Data::route_data():[];
        $legs=[];foreach(($route['legs']??[]) as $leg){if(is_array($leg)&&!empty($leg['to']))$legs[(int)$leg['to']]=$leg;}
        wp_localize_script('tng-active-trip','TNGActiveTrip',[
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce('tng_active_trip'),
            'arrivalRadius'=>300,
            'completed'=>$completed,
            'skipped'=>$skipped,
        ]);
        ob_start(); ?>
        <main class="tng-active-trip-screen tng-app-shell">
            <section class="tng-active-trip-hero"><div><span class="tng-eyebrow">Trip mode</span><h1><?php echo $total&&$resolved===$total?($skip_count?'Trip finished.':'Trip complete!'):'Your Tennessee day.'; ?></h1><p>Follow your saved route, confirm arrival, complete each stop, and keep the day moving.</p></div><div class="tng-active-trip-score"><strong data-tng-trip-progress><?php echo esc_html($done.'/'.$total); ?></strong><small>Stops complete<?php echo $skip_count?' · '.esc_html((string)$skip_count).' skipped':''; ?></small></div></section>
            <nav class="tng-trip-tabs" aria-label="Trip planning"><a href="<?php echo esc_url(home_url('/trips/')); ?>">🗺 Trips</a><a href="<?php echo esc_url(home_url('/saved/')); ?>">♡ Saved places</a><a href="<?php echo esc_url(home_url('/trip-builder/')); ?>">☰ Trip builder</a><a class="is-active" href="<?php echo esc_url(home_url('/active-trip/')); ?>">▶ Trip mode</a></nav>
            <?php if(!$logged_in): ?>
                <section class="tng-active-trip-empty"><h2>Sign in to start trip mode.</h2><p>Your itinerary and progress will stay synced to your Explorer account.</p><a class="tng-ui-button" href="<?php echo esc_url(wp_login_url(home_url('/active-trip/'))); ?>">Sign in</a></section>
            <?php elseif(!$posts): ?>
                <section class="tng-active-trip-empty"><h2>Your route needs a few stops.</h2><p>Save places, arrange them, then return here to begin the day.</p><a class="tng-ui-button" href="<?php echo esc_url(home_url('/explore/')); ?>">Find places</a></section>
            <?php else: ?>
                <section class="tng-active-trip-progress-card"><div><span class="tng-eyebrow">Today’s progress</span><h2 data-tng-trip-next-heading><?php echo esc_html($resolved===$total?($skip_count?'Trip finished — review your day below.':'You finished every stop.'):($next?'Next: '.get_the_title($next):'Keep exploring')); ?></h2><?php if(!empty($route['distance_m'])&&!empty($route['duration_s'])): ?><p class="tng-active-trip-road-summary">Road itinerary: <?php echo esc_html(self::format_miles((int)$route['distance_m']).' · '.self::format_time((int)$route['duration_s'])); ?></p><?php endif; ?></div><div class="tng-ui-progress"><span data-tng-trip-progress-bar style="width:<?php echo esc_attr((string)$percent); ?>%"></span></div></section>

                <section class="tng-trip-finish-card<?php echo $resolved===$total?' is-visible':''; ?>" data-trip-finish-card>
                    <div class="tng-trip-finish-card__icon"><?php echo $skip_count?'✓':'🏁'; ?></div>
                    <div class="tng-trip-finish-card__copy"><span class="tng-eyebrow"><?php echo $skip_count?'Trip finished':'Adventure complete'; ?></span><h2 data-trip-finish-title><?php echo $skip_count?'Your day is wrapped up.':'You completed every stop!'; ?></h2><p data-trip-finish-copy><?php echo $skip_count?esc_html($done.' completed · '.$skip_count.' skipped. Your progress is saved, and skipped stops can be revisited later.'):'Your entire itinerary is complete and ready to be saved to your Explorer story.'; ?></p></div>
                    <div class="tng-trip-finish-card__actions"><a href="<?php echo esc_url(home_url('/trips/')); ?>">View trips</a><a class="is-primary" href="<?php echo esc_url(home_url('/explore/')); ?>">Find another adventure</a></div>
                </section>

                <section class="tng-active-trip-map-card" aria-labelledby="tng-active-trip-map-title">
                    <div class="tng-active-trip-map-heading"><div><span class="tng-eyebrow">Live route</span><h2 id="tng-active-trip-map-title">Your trip on the map</h2><p data-tng-active-map-status><?php echo $next?'Current leg highlighted to '.esc_html(get_the_title($next)):($skip_count?'Trip finished. Completed and skipped stops are shown below.':'Every stop is complete.'); ?></p></div><a href="<?php echo esc_url(home_url('/trip-builder/')); ?>">Edit route</a></div>
                    <div id="tng-active-trip-map" class="tng-active-trip-map" aria-label="Map of the active trip route"></div>
                    <div class="tng-active-trip-map-footer"><span class="tng-active-trip-map-key"><i></i> Current leg</span><button type="button" data-tng-fit-active-route>Fit route</button></div>
                </section>

                <div class="tng-active-trip-layout">
                    <section class="tng-active-trip-route"><div class="tng-section__heading"><div><span class="tng-eyebrow">Your itinerary</span><h2>Stops for today</h2></div><a href="<?php echo esc_url(home_url('/trip-builder/')); ?>">Edit route</a></div><ol class="tng-active-trip-list">
                        <?php foreach($posts as $index=>$post): $is_done=in_array($post->ID,$completed,true);$skip_entry=$skipped[$post->ID]??null;$is_skipped=(bool)$skip_entry;$image=get_the_post_thumbnail_url($post->ID,'medium');$coords=self::coordinates((int)$post->ID);$leg=$legs[(int)$post->ID]??null;$directions=self::directions((int)$post->ID); ?>
                        <li class="tng-active-trip-stop<?php echo $is_done?' is-complete':''; ?><?php echo $is_skipped?' is-skipped':''; ?>" data-trip-stop="<?php echo esc_attr((string)$post->ID); ?>" data-trip-order="<?php echo esc_attr((string)($index+1)); ?>" data-directions="<?php echo esc_url($directions); ?>"<?php if($is_skipped): ?> data-skip-reason="<?php echo esc_attr($skip_entry['reason']); ?>"<?php endif; ?><?php if($coords): ?> data-lat="<?php echo esc_attr((string)$coords[0]); ?>" data-lng="<?php echo esc_attr((string)$coords[1]); ?>"<?php endif; ?>>
                            <span class="tng-active-trip-stop__number"><?php echo $is_done?'✓':($is_skipped?'↷':esc_html((string)($index+1))); ?></span><span class="tng-active-trip-stop__media"<?php echo $image?' style="background-image:url('.esc_url($image).')"':''; ?>></span>
                            <div class="tng-active-trip-stop__copy"><small><?php echo esc_html(get_post_type_object(get_post_type($post->ID))->labels->singular_name??'Stop'); ?></small><h3><?php echo esc_html(get_the_title($post)); ?></h3><?php if($is_skipped): ?><span class="tng-active-trip-skip-label">Skipped · <?php echo esc_html(self::reason_label($skip_entry['reason'])); ?></span><?php elseif($leg): ?><span class="tng-active-trip-leg">🚗 <?php echo esc_html(self::format_miles((int)$leg['distance_m']).' · '.self::format_time((int)$leg['duration_s'])); ?></span><?php elseif($index===0): ?><span class="tng-active-trip-leg">Start here</span><?php endif; ?><a href="<?php echo esc_url(get_permalink($post)); ?>">View details</a></div>
                            <div class="tng-active-trip-stop__actions"><a href="<?php echo esc_url($directions); ?>" target="_blank" rel="noopener">Directions</a><?php if(!$is_done&&!$is_skipped): ?><button type="button" class="tng-trip-arrive" data-trip-arrive<?php echo !$coords?' disabled':''; ?>>I’m here</button><?php endif; ?><button type="button" data-trip-complete data-post-id="<?php echo esc_attr((string)$post->ID); ?>" aria-pressed="<?php echo $is_done?'true':'false'; ?>"<?php echo (!$is_done&&$coords)?' disabled':''; ?>><?php echo $is_done?'Undo':'Complete stop'; ?></button><?php if(!$is_done): ?><button type="button" class="tng-trip-skip" data-trip-skip data-post-id="<?php echo esc_attr((string)$post->ID); ?>" aria-pressed="<?php echo $is_skipped?'true':'false'; ?>"><?php echo $is_skipped?'Restore stop':'Can’t visit?'; ?></button><?php endif; ?></div>
                        </li>
                        <?php endforeach; ?>
                    </ol></section>
                    <aside class="tng-active-trip-next"><span class="tng-eyebrow">Next stop</span><?php if($next): $next_leg=$legs[(int)$next->ID]??null; ?><h2 data-tng-next-title><?php echo esc_html(get_the_title($next)); ?></h2><?php if($next_leg): ?><p data-tng-next-leg>🚗 <?php echo esc_html(self::format_miles((int)$next_leg['distance_m']).' · '.self::format_time((int)$next_leg['duration_s'])); ?></p><?php else: ?><p data-tng-next-leg>Open directions when you are ready to continue your trip.</p><?php endif; ?><a class="tng-ui-button" data-tng-next-directions href="<?php echo esc_url(self::directions($next->ID)); ?>" target="_blank" rel="noopener">Get directions</a><a class="tng-ui-button tng-ui-button--secondary" data-tng-next-view href="<?php echo esc_url(get_permalink($next)); ?>">View stop</a><?php else: ?><h2 data-tng-next-title><?php echo $skip_count?'Trip finished.':'Adventure complete.'; ?></h2><p data-tng-next-leg><?php echo $skip_count?esc_html($done.' completed · '.$skip_count.' skipped'):'You visited every stop in this trip.'; ?></p><a class="tng-ui-button" href="<?php echo esc_url(home_url('/completed/')); ?>">View history</a><?php endif; ?></aside>
                </div>
            <?php endif; ?>
        </main>
        <?php return (string)ob_get_clean();
    }
}
TNG_Active_Trip_UI::boot();