<?php
namespace TNG_OS\Modules\Sources;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;

if (!defined('ABSPATH')) exit;

final class Town_Scan_Scope implements Module_Interface {
    private const OPTION = 'tng_town_scan_scope_v1';
    private const STATS_OPTION = 'tng_town_scan_scope_last_v1';
    private const NONCE = 'tng_town_scan_scope_save';

    public function id(): string { return 'town_scan_scope'; }

    public function register(Container $container): void {
        add_action('admin_menu', [$this, 'admin_menu'], 34);
        add_action('admin_post_tng_town_scope_save', [$this, 'save_action']);
        add_filter('http_response', [$this, 'filter_response'], 20, 3);
        $container->set('town_scan_scope', $this);
    }

    public function boot(Container $container): void {}

    private function defaults(): array {
        return [
            'mode' => 'broad',
            'rules' => "Monteagle, TN => Monteagle\nTracy City, TN => Tracy City\nSewanee, TN => Sewanee\nPelham, TN => Pelham\nCoalmont, TN => Coalmont\nAltamont, TN => Altamont\nGruetli-Laager, TN => Gruetli-Laager; Gruetli Laager\nPalmer, TN => Palmer\nBeersheba Springs, TN => Beersheba Springs",
        ];
    }

    private function settings(): array {
        $saved = get_option(self::OPTION, []);
        return wp_parse_args(is_array($saved) ? $saved : [], $this->defaults());
    }

    public function admin_menu(): void {
        add_submenu_page('tng-content-studio', 'Scan Scope', 'Scan Scope', 'edit_posts', 'tng-town-scan-scope', [$this, 'render_page']);
    }

    public function save_action(): void {
        if (!current_user_can('edit_posts')) wp_die('You do not have permission to manage scan scope.');
        check_admin_referer(self::NONCE);
        $mode = sanitize_key((string)wp_unslash($_POST['mode'] ?? 'broad'));
        if (!in_array($mode, ['broad','strict'], true)) $mode = 'broad';
        $rules = sanitize_textarea_field((string)wp_unslash($_POST['rules'] ?? ''));
        update_option(self::OPTION, ['mode'=>$mode,'rules'=>$rules], false);
        wp_safe_redirect(add_query_arg(['page'=>'tng-town-scan-scope','tng_notice'=>'Scan scope settings saved.'], admin_url('admin.php')));
        exit;
    }

    private function rules(string $text): array {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim((string)$line);
            if ($line === '' || strpos($line, '=>') === false) continue;
            [$town, $aliases] = array_map('trim', explode('=>', $line, 2));
            if ($town === '') continue;
            $vals = array_values(array_filter(array_map('trim', explode(';', $aliases))));
            if ($vals) $out[strtolower($town)] = $vals;
        }
        return $out;
    }

    private function aliases_for(string $town, array $settings): array {
        $rules = $this->rules((string)($settings['rules'] ?? ''));
        $key = strtolower(trim($town));
        if (!empty($rules[$key])) return $rules[$key];
        $parts = array_map('trim', explode(',', $town));
        return !empty($parts[0]) ? [$parts[0]] : [$town];
    }

    private function item_text(array $item): string {
        $parts = [];
        foreach (['address','fullAddress','formattedAddress','city','municipality','neighborhood','district','state','postalCode'] as $key) {
            if (!empty($item[$key]) && !is_array($item[$key])) $parts[] = (string)$item[$key];
        }
        foreach (['location','addressComponents'] as $key) {
            if (empty($item[$key]) || !is_array($item[$key])) continue;
            $parts[] = wp_json_encode($item[$key]);
        }
        return strtolower(html_entity_decode(implode(' ', $parts), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function matches(array $item, array $aliases): bool {
        $text = $this->item_text($item);
        if ($text === '') return false;
        foreach ($aliases as $alias) {
            $alias = strtolower(trim((string)$alias));
            if ($alias !== '' && strpos($text, $alias) !== false) return true;
        }
        return false;
    }

    public function filter_response($response, array $parsed_args, string $url) {
        if (is_wp_error($response)) return $response;
        if (strpos($url, 'api.apify.com/v2/acts/') === false || strpos($url, 'run-sync-get-dataset-items') === false) return $response;

        $settings = $this->settings();
        if (($settings['mode'] ?? 'broad') !== 'strict') return $response;

        $request = json_decode((string)($parsed_args['body'] ?? ''), true);
        if (!is_array($request)) return $response;
        $town = sanitize_text_field((string)($request['locationQuery'] ?? ''));
        if ($town === '') return $response;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || !$body) return $response;

        $aliases = $this->aliases_for($town, $settings);
        $before = count($body);
        $filtered = [];
        foreach ($body as $item) {
            if (is_array($item) && $this->matches($item, $aliases)) $filtered[] = $item;
        }

        $response['body'] = wp_json_encode($filtered);
        update_option(self::STATS_OPTION, [
            'time' => current_time('mysql'),
            'town' => $town,
            'aliases' => $aliases,
            'before' => $before,
            'after' => count($filtered),
            'removed' => max(0, $before - count($filtered)),
        ], false);
        return $response;
    }

    public function render_page(): void {
        if (!current_user_can('edit_posts')) return;
        $settings = $this->settings();
        $stats = get_option(self::STATS_OPTION, []);
        $stats = is_array($stats) ? $stats : [];
        $notice = sanitize_text_field(wp_unslash($_GET['tng_notice'] ?? ''));
        ?>
        <div class="wrap">
            <h1>📍 Scan Scope</h1>
            <p>Control how broadly Google Maps results are accepted after Apify returns them. This applies to manual Town Scanner runs and scheduled Town Monitoring.</p>
            <?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:900px;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px">
                <input type="hidden" name="action" value="tng_town_scope_save">
                <?php wp_nonce_field(self::NONCE); ?>
                <h2 style="margin-top:0">Result boundary</h2>
                <label style="display:block;margin:8px 0"><input type="radio" name="mode" value="broad" <?php checked(($settings['mode'] ?? 'broad'), 'broad'); ?>> <strong>Broad area</strong> — keep Google Maps results returned for the town search.</label>
                <label style="display:block;margin:8px 0"><input type="radio" name="mode" value="strict" <?php checked(($settings['mode'] ?? 'broad'), 'strict'); ?>> <strong>Strict town match</strong> — keep only results whose returned address/location contains an allowed place name.</label>
                <h3>Allowed place names by scan</h3>
                <p class="description">One rule per line. Use <code>Town, ST =&gt; Allowed name; Another allowed name</code>. This lets you intentionally include nearby communities when desired.</p>
                <textarea name="rules" rows="11" style="width:100%;font-family:monospace"><?php echo esc_textarea((string)($settings['rules'] ?? '')); ?></textarea>
                <?php submit_button('Save Scan Scope', 'primary', 'submit', false); ?>
            </form>
            <h2 style="margin-top:24px">Last strict-scope filter</h2>
            <?php if (!$stats): ?><p>No strict-scope scan has run yet.</p><?php else: ?>
                <table class="widefat striped" style="max-width:900px"><tbody>
                    <tr><td>Time</td><td><?php echo esc_html((string)($stats['time'] ?? '')); ?></td></tr>
                    <tr><td>Town</td><td><?php echo esc_html((string)($stats['town'] ?? '')); ?></td></tr>
                    <tr><td>Allowed names</td><td><?php echo esc_html(implode(', ', (array)($stats['aliases'] ?? []))); ?></td></tr>
                    <tr><td>Returned by Apify</td><td><?php echo absint($stats['before'] ?? 0); ?></td></tr>
                    <tr><td>Kept</td><td><?php echo absint($stats['after'] ?? 0); ?></td></tr>
                    <tr><td>Filtered out</td><td><?php echo absint($stats['removed'] ?? 0); ?></td></tr>
                </tbody></table>
            <?php endif; ?>
            <div class="notice notice-info inline" style="max-width:860px;margin-top:20px"><p><strong>Tip:</strong> Leave this on Broad area until you test Strict town match with one town. Strict mode intentionally rejects results that do not contain an allowed place name in the returned location data.</p></div>
        </div>
        <?php
    }
}
