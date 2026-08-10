<?php
/**
 * TN Game Social Pattern Intelligence
 * Aggregates Instagram discovery + inspiration into hashtag, creator, format, and location signals.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Social_Pattern_Intelligence {
    private const CANDIDATE = 'tng_social_candidate';
    private const ITEM = 'tng_social_item';

    public static function boot(): void {
        static $booted = false;
        if ($booted) return;
        $booted = true;
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 26);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
    }

    public static function admin_menu(): void {
        add_submenu_page(
            'tng-content-studio',
            'Instagram Intelligence',
            'Intelligence',
            'edit_posts',
            'tng-social-intelligence-patterns',
            [__CLASS__, 'render']
        );
    }

    private static function all_records(): array {
        return get_posts([
            'post_type' => [self::CANDIDATE, self::ITEM],
            'post_status' => ['publish','draft','private'],
            'numberposts' => 500,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
    }

    private static function record_platform(int $id): string {
        $platform = (string) get_post_meta($id, '_tng_candidate_platform', true);
        if ($platform) return strtolower($platform);
        $terms = get_the_terms($id, 'tng_social_platform');
        if ($terms && !is_wp_error($terms)) return strtolower((string) $terms[0]->name);
        $url = (string) get_post_meta($id, '_tng_source_url', true);
        return str_contains(strtolower($url), 'instagram.com') ? 'instagram' : '';
    }

    private static function record_url(int $id): string {
        return (string) (get_post_meta($id, '_tng_candidate_source_url', true) ?: get_post_meta($id, '_tng_source_url', true));
    }

    private static function record_creator(int $id): string {
        $creator = (string) (get_post_meta($id, '_tng_candidate_creator', true) ?: get_post_meta($id, '_tng_creator_handle', true));
        $creator = trim($creator);
        if (!$creator) return '';
        return '@' . ltrim($creator, '@');
    }

    private static function record_hashtags(int $id): array {
        $raw = (string) (get_post_meta($id, '_tng_candidate_hashtags', true) ?: get_post_meta($id, '_tng_hashtags', true));
        if (!$raw) return [];
        preg_match_all('/#([\p{L}\p{N}_]+)/u', $raw, $matches);
        $tags = $matches[1] ?? [];
        if (!$tags) {
            $tags = preg_split('/[\s,]+/', $raw) ?: [];
        }
        $out = [];
        foreach ($tags as $tag) {
            $tag = strtolower(trim((string) $tag, "# \t\n\r\0\x0B"));
            if ($tag !== '') $out[$tag] = true;
        }
        return array_keys($out);
    }

    private static function record_location(int $id): string {
        return trim((string) (get_post_meta($id, '_tng_candidate_location', true) ?: get_post_meta($id, '_tng_location_name', true)));
    }

    private static function record_format(int $id): string {
        return strtolower(trim((string) (get_post_meta($id, '_tng_candidate_format', true) ?: get_post_meta($id, '_tng_content_format', true))));
    }

    private static function opportunity(int $id): int {
        return max(0, min(100, (int) (get_post_meta($id, '_tng_candidate_trend_score', true) ?: get_post_meta($id, '_tng_source_opportunity_score', true))));
    }

    private static function engagement(int $id): int {
        return max(0, min(100, (int) (get_post_meta($id, '_tng_candidate_engagement', true) ?: get_post_meta($id, '_tng_source_engagement', true))));
    }

    private static function aggregate(): array {
        $records = self::all_records();
        $hashtags = [];
        $creators = [];
        $formats = [];
        $locations = [];
        $instagram_records = 0;
        $scored_records = 0;

        foreach ($records as $post) {
            $id = (int) $post->ID;
            if (self::record_platform($id) !== 'instagram') continue;
            $instagram_records++;
            $opp = self::opportunity($id);
            $eng = self::engagement($id);
            if ($opp > 0) $scored_records++;
            $url = self::record_url($id);

            foreach (self::record_hashtags($id) as $tag) {
                if (!isset($hashtags[$tag])) $hashtags[$tag] = ['count'=>0,'sum'=>0,'eng'=>0,'max'=>0,'url'=>'','title'=>''];
                $hashtags[$tag]['count']++;
                $hashtags[$tag]['sum'] += $opp;
                $hashtags[$tag]['eng'] += $eng;
                if ($opp >= $hashtags[$tag]['max']) {
                    $hashtags[$tag]['max'] = $opp;
                    $hashtags[$tag]['url'] = $url;
                    $hashtags[$tag]['title'] = get_the_title($id);
                }
            }

            $creator = self::record_creator($id);
            if ($creator) {
                $key = strtolower($creator);
                if (!isset($creators[$key])) $creators[$key] = ['label'=>$creator,'count'=>0,'sum'=>0,'eng'=>0,'max'=>0,'url'=>'','formats'=>[],'locations'=>[]];
                $creators[$key]['count']++;
                $creators[$key]['sum'] += $opp;
                $creators[$key]['eng'] += $eng;
                if ($opp >= $creators[$key]['max']) {
                    $creators[$key]['max'] = $opp;
                    $creators[$key]['url'] = $url;
                }
                $format = self::record_format($id);
                if ($format) $creators[$key]['formats'][$format] = ($creators[$key]['formats'][$format] ?? 0) + 1;
                $location = self::record_location($id);
                if ($location) $creators[$key]['locations'][$location] = ($creators[$key]['locations'][$location] ?? 0) + 1;
            }

            $format = self::record_format($id);
            if ($format) {
                if (!isset($formats[$format])) $formats[$format] = ['count'=>0,'sum'=>0];
                $formats[$format]['count']++;
                $formats[$format]['sum'] += $opp;
            }

            $location = self::record_location($id);
            if ($location) {
                $key = strtolower($location);
                if (!isset($locations[$key])) $locations[$key] = ['label'=>$location,'count'=>0,'sum'=>0,'max'=>0];
                $locations[$key]['count']++;
                $locations[$key]['sum'] += $opp;
                $locations[$key]['max'] = max($locations[$key]['max'], $opp);
            }
        }

        foreach ($hashtags as &$row) {
            $row['avg'] = $row['count'] ? (int) round($row['sum'] / $row['count']) : 0;
            $row['avg_eng'] = $row['count'] ? (int) round($row['eng'] / $row['count']) : 0;
            $row['signal'] = (int) round(($row['avg'] * .65) + (min(100, $row['count'] * 14) * .35));
        }
        unset($row);
        uasort($hashtags, static fn($a,$b) => $b['signal'] <=> $a['signal']);

        foreach ($creators as &$row) {
            $row['avg'] = $row['count'] ? (int) round($row['sum'] / $row['count']) : 0;
            $row['avg_eng'] = $row['count'] ? (int) round($row['eng'] / $row['count']) : 0;
            $row['signal'] = (int) round(($row['avg'] * .60) + ($row['avg_eng'] * .20) + (min(100, $row['count'] * 18) * .20));
            arsort($row['formats']);
            arsort($row['locations']);
        }
        unset($row);
        uasort($creators, static fn($a,$b) => $b['signal'] <=> $a['signal']);

        foreach ($formats as &$row) $row['avg'] = $row['count'] ? (int) round($row['sum'] / $row['count']) : 0;
        unset($row);
        uasort($formats, static fn($a,$b) => (($b['avg'] * .7) + ($b['count'] * 5)) <=> (($a['avg'] * .7) + ($a['count'] * 5)));

        foreach ($locations as &$row) $row['avg'] = $row['count'] ? (int) round($row['sum'] / $row['count']) : 0;
        unset($row);
        uasort($locations, static fn($a,$b) => (($b['avg'] * .7) + ($b['count'] * 5)) <=> (($a['avg'] * .7) + ($a['count'] * 5)));

        return compact('hashtags','creators','formats','locations','instagram_records','scored_records');
    }

    private static function meter(int $value): string {
        $value = max(0, min(100, $value));
        return '<div class="tng-pi-meter"><span style="width:' . esc_attr((string) $value) . '%"></span></div>';
    }

    private static function top_key(array $values): string {
        if (!$values) return '—';
        return (string) array_key_first($values);
    }

    public static function render(): void {
        if (!current_user_can('edit_posts')) return;
        $data = self::aggregate();
        $hashtags = array_slice($data['hashtags'], 0, 20, true);
        $creators = array_slice($data['creators'], 0, 20, true);
        $formats = array_slice($data['formats'], 0, 8, true);
        $locations = array_slice($data['locations'], 0, 8, true);
        $top_tag = self::top_key($hashtags);
        $top_creator_key = self::top_key($creators);
        $top_creator = $top_creator_key !== '—' ? ($creators[$top_creator_key]['label'] ?? $top_creator_key) : '—';
        $top_format = self::top_key($formats);
        $top_location_key = self::top_key($locations);
        $top_location = $top_location_key !== '—' ? ($locations[$top_location_key]['label'] ?? $top_location_key) : '—';

        echo '<div class="wrap tng-pi-wrap">';
        echo '<section class="tng-pi-hero"><div><p class="eyebrow">INSTAGRAM INTELLIGENCE</p><h1>What is starting to work?</h1><p>Patterns from the Instagram posts you capture and save. The dashboard gets smarter as your Discovery Inbox grows.</p></div><div class="tng-pi-hero-actions"><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=tng-social-discovery')) . '">Open Discovery Inbox</a><a class="button" href="' . esc_url(admin_url('edit.php?post_type=tng_social_item')) . '">View Inspiration</a></div></section>';

        echo '<section class="tng-pi-stats">';
        foreach ([
            ['Instagram signals', number_format_i18n($data['instagram_records']), 'captured + saved posts'],
            ['Top hashtag', $top_tag === '—' ? '—' : '#' . $top_tag, 'strongest recurring hashtag'],
            ['Top creator', $top_creator, 'strongest creator signal'],
            ['Top format', $top_format === '—' ? '—' : ucwords(str_replace('_',' ',$top_format)), 'format to study'],
            ['Top place', $top_location, 'recurring location signal'],
        ] as $stat) echo '<article><span>' . esc_html($stat[0]) . '</span><strong>' . esc_html((string) $stat[1]) . '</strong><small>' . esc_html($stat[2]) . '</small></article>';
        echo '</section>';

        echo '<div class="tng-pi-grid"><section class="tng-pi-panel"><div class="tng-pi-head"><div><p class="eyebrow">HASHTAG INTELLIGENCE</p><h2>Hashtags gaining signal</h2></div><span>Frequency + opportunity</span></div>';
        if (!$hashtags) echo '<div class="tng-pi-empty">Capture Instagram posts with hashtags to begin seeing patterns.</div>';
        foreach ($hashtags as $tag => $row) {
            echo '<article class="tng-pi-row"><div class="tng-pi-rank"><b>#' . esc_html($tag) . '</b><small>' . number_format_i18n($row['count']) . ' post' . ($row['count'] === 1 ? '' : 's') . ' · avg opportunity ' . number_format_i18n($row['avg']) . '</small></div><div class="tng-pi-score"><strong>' . number_format_i18n($row['signal']) . '</strong><span>SIGNAL</span></div><div class="tng-pi-bar">' . self::meter($row['signal']) . '<small>Engagement ' . number_format_i18n($row['avg_eng']) . ' · best ' . number_format_i18n($row['max']) . '</small></div><div class="tng-pi-links"><a href="https://www.instagram.com/explore/tags/' . esc_attr(rawurlencode($tag)) . '/" target="_blank" rel="noopener">Instagram ↗</a>' . ($row['url'] ? '<a href="' . esc_url($row['url']) . '" target="_blank" rel="noopener">Best source ↗</a>' : '') . '</div></article>';
        }
        echo '</section>';

        echo '<section class="tng-pi-panel"><div class="tng-pi-head"><div><p class="eyebrow">CREATOR INTELLIGENCE</p><h2>Creators worth watching</h2></div><span>Quality + repetition</span></div>';
        if (!$creators) echo '<div class="tng-pi-empty">Capture creator handles to begin building your creator intelligence list.</div>';
        foreach ($creators as $row) {
            $format = self::top_key($row['formats']);
            $location = self::top_key($row['locations']);
            $handle = ltrim($row['label'], '@');
            echo '<article class="tng-pi-creator"><div><div class="tng-pi-avatar">@</div></div><div><h3>' . esc_html($row['label']) . '</h3><p>' . number_format_i18n($row['count']) . ' captured post' . ($row['count'] === 1 ? '' : 's') . ' · avg opportunity ' . number_format_i18n($row['avg']) . '</p><div class="tng-pi-chips">' . ($format !== '—' ? '<span>' . esc_html(ucwords(str_replace('_',' ',$format))) . '</span>' : '') . ($location !== '—' ? '<span>' . esc_html($location) . '</span>' : '') . '<span>Engagement ' . number_format_i18n($row['avg_eng']) . '</span></div></div><div class="tng-pi-score"><strong>' . number_format_i18n($row['signal']) . '</strong><span>SIGNAL</span></div><div class="tng-pi-links"><a href="https://www.instagram.com/' . esc_attr(rawurlencode($handle)) . '/" target="_blank" rel="noopener">Profile ↗</a>' . ($row['url'] ? '<a href="' . esc_url($row['url']) . '" target="_blank" rel="noopener">Best source ↗</a>' : '') . '</div></article>';
        }
        echo '</section></div>';

        echo '<div class="tng-pi-grid tng-pi-bottom"><section class="tng-pi-panel"><div class="tng-pi-head"><div><p class="eyebrow">FORMAT SIGNALS</p><h2>What kind of content?</h2></div></div>';
        if (!$formats) echo '<div class="tng-pi-empty">Add a format while capturing posts—especially Reel, carousel, and photo.</div>';
        foreach ($formats as $format => $row) echo '<div class="tng-pi-mini"><strong>' . esc_html(ucwords(str_replace('_',' ',$format))) . '</strong><span>' . number_format_i18n($row['count']) . ' posts</span><b>' . number_format_i18n($row['avg']) . ' avg</b></div>';
        echo '</section><section class="tng-pi-panel"><div class="tng-pi-head"><div><p class="eyebrow">PLACE SIGNALS</p><h2>Where is attention clustering?</h2></div></div>';
        if (!$locations) echo '<div class="tng-pi-empty">Add locations during Quick Capture to see destination patterns.</div>';
        foreach ($locations as $row) echo '<div class="tng-pi-mini"><strong>' . esc_html($row['label']) . '</strong><span>' . number_format_i18n($row['count']) . ' posts</span><b>' . number_format_i18n($row['avg']) . ' avg</b></div>';
        echo '</section></div>';

        echo '<section class="tng-pi-next"><div><p class="eyebrow">NEXT LAYER</p><h2>Turn patterns into original TN Game ideas.</h2><p>Once a few more posts are captured, these hashtag, creator, place, and format signals can feed the Content Generator automatically.</p></div><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=tng-social-discovery')) . '">Capture more Instagram signals</a></section>';
        echo '</div>';
    }

    public static function admin_assets(string $hook): void {
        if (strpos($hook, 'tng-social-intelligence-patterns') === false) return;
        wp_register_style('tng-social-pattern-intelligence', false, [], '0.1.0');
        wp_enqueue_style('tng-social-pattern-intelligence');
        wp_add_inline_style('tng-social-pattern-intelligence', '
            .tng-pi-wrap{max-width:1320px}.tng-pi-wrap .eyebrow{margin:0 0 6px;color:#f26322;font-weight:800;font-size:11px;letter-spacing:.12em}.tng-pi-hero{margin:20px 0 18px;background:linear-gradient(135deg,#0b422b,#17633d);border-radius:22px;padding:30px 34px;color:#fff;display:flex;justify-content:space-between;gap:25px;align-items:center}.tng-pi-hero h1{color:#fff;font-size:40px;line-height:1.05;margin:3px 0 10px}.tng-pi-hero p{font-size:14px;max-width:720px}.tng-pi-hero .eyebrow{color:#ff9a62}.tng-pi-hero-actions{display:flex;gap:8px;flex-wrap:wrap}.tng-pi-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:18px}.tng-pi-stats article{background:#fff;border:1px solid #dde7df;border-radius:16px;padding:16px}.tng-pi-stats span,.tng-pi-stats small{display:block;color:#718078;font-size:11px}.tng-pi-stats strong{display:block;color:#173a2a;font-size:20px;margin:7px 0 3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tng-pi-grid{display:grid;grid-template-columns:1.08fr .92fr;gap:18px;margin-bottom:18px}.tng-pi-panel{background:#fff;border:1px solid #dfe8e1;border-radius:18px;padding:22px}.tng-pi-head{display:flex;justify-content:space-between;align-items:flex-start;gap:15px;margin-bottom:12px}.tng-pi-head h2{font-size:24px;color:#17372a;margin:0}.tng-pi-head>span{font-size:11px;color:#7a887f}.tng-pi-row{display:grid;grid-template-columns:1.5fr 68px 1fr 90px;gap:13px;align-items:center;border-top:1px solid #edf1ee;padding:14px 0}.tng-pi-rank b,.tng-pi-rank small{display:block}.tng-pi-rank b{font-size:15px;color:#233c31}.tng-pi-rank small,.tng-pi-bar small{font-size:10px;color:#748078;margin-top:4px}.tng-pi-score{text-align:center;background:#f3f7f4;border-radius:11px;padding:8px}.tng-pi-score strong,.tng-pi-score span{display:block}.tng-pi-score strong{font-size:20px;color:#1b5135}.tng-pi-score span{font-size:8px;font-weight:800;letter-spacing:.08em;color:#718078}.tng-pi-meter{height:6px;border-radius:99px;background:#e5ede7;overflow:hidden}.tng-pi-meter span{display:block;height:100%;border-radius:99px;background:#f26322}.tng-pi-links{display:flex;flex-direction:column;gap:5px;font-size:11px}.tng-pi-links a{color:#d94e0f;text-decoration:none;font-weight:700}.tng-pi-creator{display:grid;grid-template-columns:42px 1fr 68px 85px;gap:12px;align-items:center;border-top:1px solid #edf1ee;padding:14px 0}.tng-pi-avatar{width:38px;height:38px;border-radius:12px;background:#eaf5ed;color:#20653c;display:flex;align-items:center;justify-content:center;font-weight:800}.tng-pi-creator h3{margin:0;font-size:16px}.tng-pi-creator p{margin:3px 0 7px;color:#738078;font-size:11px}.tng-pi-chips{display:flex;gap:5px;flex-wrap:wrap}.tng-pi-chips span{background:#f3f7f4;border-radius:999px;padding:4px 7px;font-size:9px;color:#5e7066}.tng-pi-bottom{grid-template-columns:1fr 1fr}.tng-pi-mini{display:grid;grid-template-columns:1fr 90px 70px;gap:10px;padding:11px 0;border-top:1px solid #edf1ee;align-items:center}.tng-pi-mini span{font-size:11px;color:#728078}.tng-pi-mini b{text-align:right;color:#1d5437}.tng-pi-empty{padding:22px;border:1px dashed #c9d8cf;border-radius:13px;background:#f8faf8;color:#66766d}.tng-pi-next{display:flex;justify-content:space-between;align-items:center;gap:20px;background:#fff8f3;border:1px solid #f5d4c3;border-radius:18px;padding:22px 24px;margin-bottom:30px}.tng-pi-next h2{margin:0 0 5px;color:#263d33}.tng-pi-next p{margin:0;color:#6d786f;max-width:750px}@media(max-width:1050px){.tng-pi-stats{grid-template-columns:repeat(2,1fr)}.tng-pi-grid{grid-template-columns:1fr}.tng-pi-row{grid-template-columns:1fr 68px}.tng-pi-bar,.tng-pi-links{grid-column:1/-1}.tng-pi-creator{grid-template-columns:42px 1fr 68px}.tng-pi-creator .tng-pi-links{grid-column:2/-1;flex-direction:row}}@media(max-width:700px){.tng-pi-hero,.tng-pi-next{flex-direction:column;align-items:flex-start}.tng-pi-stats{grid-template-columns:1fr}.tng-pi-creator{grid-template-columns:42px 1fr}.tng-pi-creator .tng-pi-score{grid-column:1/-1}.tng-pi-mini{grid-template-columns:1fr auto}.tng-pi-mini b{grid-column:2}.tng-pi-mini span{grid-row:2}}');
    }
}

TNG_Social_Pattern_Intelligence::boot();
