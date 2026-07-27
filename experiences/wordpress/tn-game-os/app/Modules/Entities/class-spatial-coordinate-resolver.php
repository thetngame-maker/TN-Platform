<?php
namespace TNG_OS\Modules\Entities;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Spatial_Coordinate_Resolver implements Module_Interface {
    private const ENTITY_TYPE = 'tng_entity';
    private const PAGE = 'tng-spatial-integrity';

    public function id(): string { return 'spatial_coordinate_resolver'; }

    public function register(Container $container): void {
        $container->set('spatial_coordinate_resolver', $this);
        add_action('admin_post_tng_spatial_resolve_missing', [$this, 'resolve_missing']);
        add_action('admin_footer', [$this, 'inject_button']);
        add_action('admin_notices', [$this, 'notice']);
    }

    public function boot(Container $container): void {}

    public function inject_button(): void {
        if (!current_user_can('manage_options') || sanitize_key($_GET['page'] ?? '') !== self::PAGE) return;
        $action = admin_url('admin-post.php');
        $nonce = wp_create_nonce('tng_spatial_resolve_missing');
        ?>
        <script>
        (()=>{
            const add=()=>{
                const repair=[...document.querySelectorAll('a.button')].find(a=>a.textContent.trim()==='Repair obvious issues');
                if(!repair || document.querySelector('[data-tng-resolve-missing]')) return;
                const form=document.createElement('form');
                form.method='post';
                form.action=<?php echo wp_json_encode($action); ?>;
                form.dataset.tngResolveMissing='1';
                form.style.display='inline-block';
                form.style.marginLeft='8px';
                form.innerHTML='<input type="hidden" name="action" value="tng_spatial_resolve_missing"><input type="hidden" name="_wpnonce" value="<?php echo esc_js($nonce); ?>"><button type="submit" class="button button-primary">Resolve missing coordinates</button>';
                repair.insertAdjacentElement('afterend',form);
            };
            add(); new MutationObserver(add).observe(document.body,{childList:true,subtree:true});
        })();
        </script>
        <?php
    }

    public function notice(): void {
        if (sanitize_key($_GET['page'] ?? '') !== self::PAGE || !isset($_GET['resolved'])) return;
        $resolved = absint($_GET['resolved']);
        $failed = absint($_GET['failed'] ?? 0);
        echo '<div class="notice notice-success is-dismissible"><p><strong>'.esc_html((string)$resolved).'</strong> missing coordinate record(s) resolved.';
        if ($failed) echo ' '.esc_html((string)$failed).' still need review.';
        echo '</p></div>';
    }

    public function resolve_missing(): void {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('tng_spatial_resolve_missing');

        $posts = get_posts(['post_type'=>self::ENTITY_TYPE,'post_status'=>['publish','draft','private'],'posts_per_page'=>1000]);
        $entities = [];
        foreach ($posts as $post) {
            $entity_id = (string)get_post_meta($post->ID, '_tng_entity_id', true);
            if ($entity_id === '') continue;
            $entities[$entity_id] = [
                'post_id'=>(int)$post->ID,
                'title'=>$post->post_title,
                'type'=>(string)get_post_meta($post->ID, '_tng_entity_type', true),
                'payload'=>(array)get_post_meta($post->ID, '_tng_entity_payload', true),
                'relationships'=>(array)get_post_meta($post->ID, '_tng_entity_relationships', true),
            ];
        }

        $resolved = 0;
        $geocode_calls = 0;

        foreach ($entities as $id => &$entity) {
            if ($this->coordinates($entity['payload'])) continue;
            $found = $this->source_post_coordinates($entity['payload']);
            if ($found && $this->save($entity, $found[0], $found[1], 'source_post_meta', 95)) {
                $resolved++; $entity['payload'] = (array)get_post_meta($entity['post_id'], '_tng_entity_payload', true);
            }
        }
        unset($entity);

        foreach ($entities as $id => &$entity) {
            if ($this->coordinates($entity['payload'])) continue;
            if (in_array($entity['type'], ['event','concert'], true)) continue;
            $query = $this->geocode_query($entity);
            if ($query === '') continue;
            if ($geocode_calls > 0) sleep(1);
            $found = $this->nominatim($query);
            $geocode_calls++;
            if ($found && $this->save($entity, $found[0], $found[1], 'openstreetmap_geocode', 75)) {
                $resolved++; $entity['payload'] = (array)get_post_meta($entity['post_id'], '_tng_entity_payload', true);
            }
        }
        unset($entity);

        foreach ($entities as $id => &$entity) {
            if ($this->coordinates($entity['payload'])) continue;
            $found = $this->relationship_coordinates($id, $entity, $entities);
            if ($found && $this->save($entity, $found[0], $found[1], 'related_venue', 90)) {
                $resolved++; $entity['payload'] = (array)get_post_meta($entity['post_id'], '_tng_entity_payload', true);
            }
        }
        unset($entity);

        $failed = 0;
        foreach ($entities as $entity) if (!$this->coordinates((array)get_post_meta($entity['post_id'], '_tng_entity_payload', true))) $failed++;
        wp_safe_redirect(add_query_arg(['page'=>self::PAGE,'resolved'=>$resolved,'failed'=>$failed], admin_url('admin.php')));
        exit;
    }

    private function source_post_coordinates(array $payload): ?array {
        foreach (['traveler_activity_id','post_id','wp_post_id','source_post_id'] as $key) {
            $post_id = absint($payload[$key] ?? 0);
            if (!$post_id) continue;
            $pairs = [
                ['map_lat','map_lng'], ['map_lat','map_long'], ['st_map_lat','st_map_lng'],
                ['_st_map_lat','_st_map_lng'], ['latitude','longitude'], ['lat','lng'],
                ['_latitude','_longitude'], ['location_lat','location_lng'], ['google_map_lat','google_map_lng'],
            ];
            foreach ($pairs as [$lat_key,$lng_key]) {
                $lat = get_post_meta($post_id, $lat_key, true);
                $lng = get_post_meta($post_id, $lng_key, true);
                $normalized = $this->normalize($lat, $lng);
                if ($normalized) return $normalized;
            }
            $all = get_post_meta($post_id);
            foreach ($all as $meta_key => $values) {
                if (!preg_match('/lat(itude)?/i', (string)$meta_key)) continue;
                $lat = maybe_unserialize($values[0] ?? null);
                foreach ($all as $lng_key => $lng_values) {
                    if (!preg_match('/(lng|lon|longitude)/i', (string)$lng_key)) continue;
                    $lng = maybe_unserialize($lng_values[0] ?? null);
                    $normalized = $this->normalize($lat, $lng);
                    if ($normalized) return $normalized;
                }
            }
        }
        return null;
    }

    private function relationship_coordinates(string $id, array $entity, array $entities): ?array {
        $preferred = ['held_at','located_in','part_of','contains','near'];
        foreach ($preferred as $wanted) {
            foreach ($entity['relationships'] as $relationship) {
                if (!is_array($relationship) || sanitize_key((string)($relationship['type'] ?? '')) !== $wanted) continue;
                $source = (string)($relationship['source_entity_id'] ?? $id);
                $target = (string)($relationship['target_entity_id'] ?? '');
                $other = $source === $id ? $target : $source;
                if ($other !== '' && isset($entities[$other])) {
                    $coords = $this->coordinates($entities[$other]['payload']);
                    if ($coords) return $coords;
                }
            }
            foreach ($entities as $other_id => $other_entity) {
                foreach ($other_entity['relationships'] as $relationship) {
                    if (!is_array($relationship) || sanitize_key((string)($relationship['type'] ?? '')) !== $wanted) continue;
                    $source = (string)($relationship['source_entity_id'] ?? $other_id);
                    $target = (string)($relationship['target_entity_id'] ?? '');
                    if ($source !== $id && $target !== $id) continue;
                    $coords = $this->coordinates($other_entity['payload']);
                    if ($coords) return $coords;
                }
            }
        }
        return null;
    }

    private function geocode_query(array $entity): string {
        $payload = $entity['payload'];
        foreach (['formatted_address','address','full_address','location_address','short_address'] as $key) {
            if (!empty($payload[$key])) return sanitize_text_field((string)$payload[$key]);
        }
        $title = trim((string)$entity['title']);
        return $title === '' ? '' : $title . ', Tennessee, USA';
    }

    private function nominatim(string $query): ?array {
        $url = add_query_arg(['q'=>$query,'format'=>'jsonv2','limit'=>1,'countrycodes'=>'us'], 'https://nominatim.openstreetmap.org/search');
        $response = wp_remote_get($url, ['timeout'=>20,'headers'=>['User-Agent'=>'TNGameOS/'.TNG_OS_VERSION.' ('.home_url('/').')','Accept'=>'application/json']]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return null;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body[0])) return null;
        return $this->normalize($body[0]['lat'] ?? null, $body[0]['lon'] ?? null);
    }

    private function save(array $entity, float $lat, float $lng, string $source, int $confidence): bool {
        $normalized = $this->normalize($lat, $lng);
        if (!$normalized) return false;
        [$lat,$lng] = $normalized;
        $payload = $entity['payload'];
        $payload['latitude']=$lat; $payload['longitude']=$lng; $payload['lat']=$lat; $payload['lng']=$lng;
        $payload['coordinates']=['lat'=>$lat,'lng'=>$lng];
        $payload['coordinate_source']=$source; $payload['coordinate_confidence']=$confidence;
        update_post_meta($entity['post_id'], '_tng_entity_payload', $payload);
        clean_post_cache($entity['post_id']);
        return true;
    }

    private function coordinates(array $payload): ?array {
        $lat = $payload['latitude'] ?? $payload['lat'] ?? null;
        $lng = $payload['longitude'] ?? $payload['lng'] ?? $payload['lon'] ?? null;
        if ((!is_numeric($lat) || !is_numeric($lng)) && isset($payload['coordinates']) && is_array($payload['coordinates'])) {
            $lat = $payload['coordinates']['lat'] ?? $payload['coordinates']['latitude'] ?? null;
            $lng = $payload['coordinates']['lng'] ?? $payload['coordinates']['longitude'] ?? null;
        }
        return $this->normalize($lat, $lng);
    }

    private function normalize($lat, $lng): ?array {
        if (!is_numeric($lat) || !is_numeric($lng)) return null;
        $lat=(float)$lat; $lng=(float)$lng;
        if (abs($lat)>90 || abs($lng)>180) return null;
        if ($lat>=34.7 && $lat<=36.9 && $lng>81.4 && $lng<90.6) $lng=-$lng;
        if (!($lat>=34.0 && $lat<=37.5 && $lng>=-91.5 && $lng<=-80.0)) return null;
        return [$lat,$lng];
    }
}