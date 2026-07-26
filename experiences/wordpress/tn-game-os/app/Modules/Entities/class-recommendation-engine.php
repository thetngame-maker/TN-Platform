<?php
namespace TNG_OS\Modules\Entities;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Recommendation_Engine implements Module_Interface {
    private const ENTITY_TYPE = 'tng_entity';
    private Container $container;

    private array $relationship_weights = [
        'held_at' => 100,
        'contains' => 90,
        'part_of' => 85,
        'located_in' => 80,
        'starts_at' => 75,
        'ends_at' => 75,
        'serves' => 70,
        'offers' => 65,
        'featured_in' => 60,
        'connects_to' => 55,
        'near' => 50,
        'operated_by' => 45,
        'related_to' => 35,
    ];

    public function id(): string { return 'recommendation_engine'; }

    public function register(Container $container): void {
        $this->container = $container;
        $container->set('recommendation_engine', $this);
        add_filter('tng_recommendation_relationship_weights', [$this, 'relationship_weights']);
    }

    public function boot(Container $container): void {}

    public function relationship_weights(array $weights = []): array {
        return array_merge($this->relationship_weights, $weights);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recommend(string $root_id, array $args = []): array {
        $args = wp_parse_args($args, [
            'depth' => 2,
            'limit' => 12,
            'types' => [],
            'require_url' => true,
        ]);
        $depth = max(1, min(3, absint($args['depth'])));
        $limit = max(1, min(50, absint($args['limit'])));
        $types = array_values(array_filter(array_map('sanitize_key', (array)$args['types'])));
        $entities = $this->entities();
        if (!isset($entities[$root_id])) return [];

        $adjacency = $this->adjacency($entities);
        $root = $entities[$root_id];
        $seen_depth = [$root_id => 0];
        $queue = [[$root_id, 0, []]];
        $candidates = [];

        while ($queue) {
            [$current, $level, $path] = array_shift($queue);
            if ($level >= $depth) continue;

            foreach ($adjacency[$current] ?? [] as $edge) {
                $next = $edge['entity_id'];
                if (!isset($entities[$next])) continue;
                $next_level = $level + 1;
                if (isset($seen_depth[$next]) && $seen_depth[$next] <= $next_level) continue;
                $seen_depth[$next] = $next_level;
                $next_path = array_merge($path, [$edge]);
                $queue[] = [$next, $next_level, $next_path];
                if ($next === $root_id) continue;

                $entity = $entities[$next];
                if ($types && !in_array($entity['type'], $types, true)) continue;
                $url = $this->entity_url($entity);
                if (!empty($args['require_url']) && $url === '') continue;

                $scored = $this->score($root, $entity, $next_path);
                $candidates[$next] = array_merge($entity, $scored, [
                    'url' => $url,
                    'image' => $this->entity_image($entity),
                    'distance_miles' => $this->distance_miles($root['payload'], $entity['payload']),
                    'hops' => $next_level,
                ]);
            }
        }

        uasort($candidates, static function (array $a, array $b): int {
            if ($a['score'] === $b['score']) return strcasecmp((string)$a['title'], (string)$b['title']);
            return $b['score'] <=> $a['score'];
        });

        return array_slice(array_values($candidates), 0, $limit);
    }

    private function score(array $root, array $entity, array $path): array {
        $score = 0;
        $reasons = [];
        $weights = (array)apply_filters('tng_recommendation_relationship_weights', $this->relationship_weights);
        $first = $path[0] ?? ['type' => 'related_to'];
        $relationship = sanitize_key((string)($first['type'] ?? 'related_to'));
        $relationship_score = (int)($weights[$relationship] ?? 30);
        $score += $relationship_score;
        $reasons[] = $this->relationship_label($relationship);

        $hops = count($path);
        if ($hops > 1) {
            $penalty = ($hops - 1) * 18;
            $score -= $penalty;
            $reasons[] = $hops . ' graph hops away';
        }

        $distance = $this->distance_miles($root['payload'], $entity['payload']);
        if ($distance !== null) {
            if ($distance <= 1) { $score += 35; $reasons[] = 'Within 1 mile'; }
            elseif ($distance <= 5) { $score += 25; $reasons[] = 'Within 5 miles'; }
            elseif ($distance <= 15) { $score += 12; $reasons[] = 'Within 15 miles'; }
        }

        $rating = $this->numeric_payload($entity['payload'], ['rating', 'average_rating', 'review_rating']);
        if ($rating >= 4.5) { $score += 20; $reasons[] = 'Highly rated'; }
        elseif ($rating >= 4.0) { $score += 12; $reasons[] = 'Well rated'; }

        $reviews = (int)$this->numeric_payload($entity['payload'], ['review_count', 'reviews']);
        if ($reviews >= 100) { $score += 12; $reasons[] = 'Popular with visitors'; }
        elseif ($reviews >= 10) { $score += 6; $reasons[] = 'Visitor reviewed'; }

        if ($this->truthy_payload($entity['payload'], ['featured', 'is_featured'])) {
            $score += 15;
            $reasons[] = 'Featured experience';
        }
        if ($this->truthy_payload($entity['payload'], ['quest_id', 'gamipress_achievement_id', 'xp'])) {
            $score += 10;
            $reasons[] = 'TN Game opportunity';
        }
        if ($this->entity_image($entity) !== TNG_OS_URL . 'assets/frontend/recommendations-placeholder.svg') {
            $score += 5;
        }

        $score = max(0, (int)apply_filters('tng_recommendation_score', $score, $root, $entity, $path));
        return [
            'score' => $score,
            'reasons' => array_values(array_unique(array_filter($reasons))),
            'primary_reason' => $reasons[0] ?? 'Connected through the destination graph',
            'relationship' => $relationship,
        ];
    }

    private function adjacency(array $entities): array {
        $adj = [];
        foreach ($entities as $entity) {
            foreach ($entity['relationships'] as $relationship) {
                if (!is_array($relationship)) continue;
                $source = sanitize_text_field((string)($relationship['source_entity_id'] ?? $entity['id']));
                $target = sanitize_text_field((string)($relationship['target_entity_id'] ?? ''));
                $type = sanitize_key((string)($relationship['type'] ?? 'related_to'));
                if ($source === '' || $target === '') continue;
                $adj[$source][] = ['entity_id' => $target, 'type' => $type, 'direction' => 'out'];
                $adj[$target][] = ['entity_id' => $source, 'type' => $type, 'direction' => 'in'];
            }
        }
        return $adj;
    }

    private function entities(): array {
        $posts = get_posts([
            'post_type' => self::ENTITY_TYPE,
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => 1000,
        ]);
        $out = [];
        foreach ($posts as $post) {
            $id = (string)get_post_meta($post->ID, '_tng_entity_id', true);
            if ($id === '') continue;
            $out[$id] = [
                'id' => $id,
                'title' => $post->post_title ?: $id,
                'type' => (string)get_post_meta($post->ID, '_tng_entity_type', true) ?: 'place',
                'payload' => (array)get_post_meta($post->ID, '_tng_entity_payload', true),
                'relationships' => (array)get_post_meta($post->ID, '_tng_entity_relationships', true),
            ];
        }
        return $out;
    }

    public function entity_for_post(int $post_id): string {
        $posts = get_posts([
            'post_type' => self::ENTITY_TYPE,
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'OR',
                ['key' => '_tng_entity_payload', 'value' => '"traveler_activity_id":' . $post_id, 'compare' => 'LIKE'],
                ['key' => '_tng_entity_payload', 'value' => '"post_id":' . $post_id, 'compare' => 'LIKE'],
                ['key' => '_tng_entity_payload', 'value' => '"wp_post_id":' . $post_id, 'compare' => 'LIKE'],
            ],
        ]);
        if ($posts) return (string)get_post_meta((int)$posts[0], '_tng_entity_id', true);
        return (string)get_post_meta($post_id, '_tng_entity_id', true);
    }

    private function entity_url(array $entity): string {
        foreach (['traveler_activity_id', 'post_id', 'wp_post_id'] as $key) {
            $id = absint($entity['payload'][$key] ?? 0);
            if ($id && get_post_status($id) === 'publish') return (string)get_permalink($id);
        }
        return esc_url_raw((string)($entity['payload']['url'] ?? $entity['payload']['permalink'] ?? ''));
    }

    private function entity_image(array $entity): string {
        foreach (['traveler_activity_id', 'post_id', 'wp_post_id'] as $key) {
            $id = absint($entity['payload'][$key] ?? 0);
            if ($id) {
                $image = get_the_post_thumbnail_url($id, 'large');
                if ($image) return $image;
            }
        }
        foreach (['image', 'image_url', 'featured_image'] as $key) {
            if (!empty($entity['payload'][$key])) return esc_url_raw((string)$entity['payload'][$key]);
        }
        return TNG_OS_URL . 'assets/frontend/recommendations-placeholder.svg';
    }

    private function relationship_label(string $type): string {
        $labels = [
            'held_at' => 'At this venue', 'located_in' => 'In the same area', 'near' => 'Nearby',
            'part_of' => 'Part of the same destination', 'contains' => 'Within this destination',
            'starts_at' => 'Connected starting point', 'ends_at' => 'Connected endpoint',
            'connects_to' => 'Connected experience', 'featured_in' => 'Featured together',
            'serves' => 'Food and drink option', 'offers' => 'Available here',
            'operated_by' => 'Related operator', 'related_to' => 'Related experience',
        ];
        return $labels[$type] ?? 'Connected through the destination graph';
    }

    private function coordinates(array $payload): ?array {
        $lat = $payload['latitude'] ?? $payload['lat'] ?? null;
        $lng = $payload['longitude'] ?? $payload['lng'] ?? $payload['lon'] ?? null;
        if ((!is_numeric($lat) || !is_numeric($lng)) && isset($payload['coordinates']) && is_array($payload['coordinates'])) {
            $lat = $payload['coordinates']['lat'] ?? $payload['coordinates']['latitude'] ?? null;
            $lng = $payload['coordinates']['lng'] ?? $payload['coordinates']['longitude'] ?? null;
        }
        return is_numeric($lat) && is_numeric($lng) ? [(float)$lat, (float)$lng] : null;
    }

    private function distance_miles(array $a, array $b): ?float {
        $one = $this->coordinates($a); $two = $this->coordinates($b);
        if (!$one || !$two) return null;
        [$lat1, $lon1] = array_map('deg2rad', $one);
        [$lat2, $lon2] = array_map('deg2rad', $two);
        $dlat = $lat2 - $lat1; $dlon = $lon2 - $lon1;
        $h = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlon / 2) ** 2;
        return round(3958.7613 * 2 * atan2(sqrt($h), sqrt(1 - $h)), 1);
    }

    private function numeric_payload(array $payload, array $keys): float {
        foreach ($keys as $key) if (isset($payload[$key]) && is_numeric($payload[$key])) return (float)$payload[$key];
        return 0.0;
    }

    private function truthy_payload(array $payload, array $keys): bool {
        foreach ($keys as $key) if (!empty($payload[$key])) return true;
        return false;
    }
}
