<?php
namespace TNG_OS\Modules\Concerts;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

/**
 * Provider-aware compatibility layer for Concert Intelligence.
 *
 * Concert Intelligence 5.1.0 originally posted every source to the Tixr
 * provider endpoint. This module keeps the proven queue/import workflow intact
 * while routing The Caverns through the official Platform Core provider.
 */
final class Concert_Platform_Sync implements Module_Interface {
    private const SOURCE_TYPE = 'tng_concert_source';
    private const OFFICIAL_PROVIDER = 'caverns-official';
    private const OFFICIAL_SOURCE_URL = 'https://www.thecaverns.com/shows';
    private bool $routing = false;

    public function id(): string { return 'concert_platform_sync'; }

    public function register(Container $container): void {
        $container->set('concert_platform_sync', $this);

        add_action('init', [$this, 'migrate_caverns_source'], 45);
        add_filter('pre_http_request', [$this, 'route_provider_request'], 10, 3);
    }

    public function boot(Container $container): void {}

    /**
     * Upgrade the default source created by Concert Intelligence without
     * requiring an administrator to delete and recreate it.
     */
    public function migrate_caverns_source(): void {
        $sources = get_posts([
            'post_type' => self::SOURCE_TYPE,
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        foreach ($sources as $source_id) {
            $provider = (string)get_post_meta($source_id, '_tng_ci_provider', true);
            $source_url = (string)get_post_meta($source_id, '_tng_ci_source_url', true);
            $title = (string)get_the_title($source_id);

            if (!$this->is_caverns_source($provider, $source_url, $title)) continue;

            update_post_meta($source_id, '_tng_ci_provider', self::OFFICIAL_PROVIDER);
            update_post_meta($source_id, '_tng_ci_source_url', self::OFFICIAL_SOURCE_URL);

            if ($title === 'The Caverns — Tixr') {
                wp_update_post([
                    'ID' => (int)$source_id,
                    'post_title' => 'The Caverns — Official Shows',
                ]);
            }
        }
    }

    /**
     * Replace only Caverns requests aimed at the legacy Tixr endpoint.
     * Returning a normal WP HTTP response lets the existing queue, diagnostics,
     * duplicate matching, auto-import, and admin notices continue unchanged.
     */
    public function route_provider_request($preempt, array $parsed_args, string $url) {
        if ($preempt !== false || $this->routing) return $preempt;
        if (strpos($url, '/v1/providers/tixr/sync') === false) return $preempt;

        $payload = $this->decode_body($parsed_args['body'] ?? '');
        $source_url = (string)($payload['source_url'] ?? '');
        if (!$this->is_caverns_source('', $source_url, '')) return $preempt;

        $target = preg_replace(
            '~\/v1\/providers\/tixr\/sync(?:\?.*)?$~',
            '/v1/providers/caverns/sync',
            $url
        );
        if (!$target || $target === $url) return $preempt;

        $forward_args = $parsed_args;
        $forward_args['body'] = wp_json_encode([
            'force' => !empty($payload['force']),
        ]);
        $forward_args['headers'] = is_array($forward_args['headers'] ?? null)
            ? $forward_args['headers']
            : [];
        $forward_args['headers']['Content-Type'] = 'application/json';
        $forward_args['headers']['Accept'] = 'application/json';
        $forward_args['timeout'] = max(120, (int)($forward_args['timeout'] ?? 0));

        $this->routing = true;
        try {
            $response = wp_remote_post($target, $forward_args);
        } finally {
            $this->routing = false;
        }

        return $response;
    }

    private function decode_body($body): array {
        if (is_array($body)) return $body;
        if (!is_string($body) || $body === '') return [];
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function is_caverns_source(string $provider, string $source_url, string $title): bool {
        if ($provider === self::OFFICIAL_PROVIDER) return true;
        if (stripos($title, 'The Caverns') !== false) return true;

        $host = strtolower((string)wp_parse_url($source_url, PHP_URL_HOST));
        $path = strtolower((string)wp_parse_url($source_url, PHP_URL_PATH));

        if (in_array($host, ['thecaverns.com', 'www.thecaverns.com'], true)) return true;
        return in_array($host, ['tixr.com', 'www.tixr.com'], true)
            && strpos($path, '/groups/thecaverns') === 0;
    }
}
