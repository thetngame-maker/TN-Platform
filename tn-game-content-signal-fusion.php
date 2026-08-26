<?php
/**
 * TN Game Content Signal Fusion
 * Uses Instagram intelligence to shape pillar-aware campaign ideas.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Content_Signal_Fusion {
    private const ITEM = 'tng_social_item';
    private const CANDIDATE = 'tng_social_candidate';

    public static function boot(): void {
        static $booted = false;
        if ($booted) return;
        $booted = true;
        add_action('admin_footer', [__CLASS__, 'footer']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    private static function page(): string {
        return sanitize_key(wp_unslash($_GET['page'] ?? ''));
    }

    private static function meta(int $id, string ...$keys): string {
        foreach ($keys as $key) {
            $v = trim((string)get_post_meta($id, $key, true));
            if ($v !== '') return $v;
        }
        return '';
    }

    private static function instagram(int $id): bool {
        $platform = strtolower(self::meta($id, '_tng_candidate_platform'));
        if ($platform === 'instagram') return true;
        $terms = get_the_terms($id, 'tng_social_platform');
        if ($terms && !is_wp_error($terms) && str_contains(strtolower((string)$terms[0]->name), 'instagram')) return true;
        return str_contains(strtolower(self::meta($id, '_tng_candidate_source_url', '_tng_source_url')), 'instagram.com');
    }

    private static function hashtags(int $id): array {
        $raw = self::meta($id, '_tng_candidate_hashtags', '_tng_hashtags');
        if (!$raw) return [];
        preg_match_all('/#([\p{L}\p{N}_]+)/u', $raw, $m);
        $tags = $m[1] ?? [];
        if (!$tags) $tags = preg_split('/[\s,]+/', $raw) ?: [];
        $out = [];
        foreach ($tags as $tag) {
            $tag = strtolower(trim((string)$tag, "# \t\n\r\0\x0B"));
            if ($tag !== '') $out[$tag] = true;
        }
        return array_keys($out);
    }

    private static function records(): array {
        return get_posts([
            'post_type' => [self::CANDIDATE, self::ITEM],
            'post_status' => ['publish','draft','private'],
            'posts_per_page' => 500,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
    }

    private static function pillar_words(string $pillar): array {
        return [
            'brand_intro' => ['brand','tennessee','explore','adventure','travel','launch'],
            'feature_reveal' => ['game','trip','map','trail','challenge','xp','checkpoint','route'],
            'destination_inspiration' => ['falls','trail','waterfall','hike','overlook','park','cave','mountain','scenic','tennessee'],
            'local_business' => ['food','restaurant','cafe','coffee','bakery','shop','store','hotel','stay','event','local','market'],
            'gameplay' => ['game','challenge','hunt','checkpoint','play','xp','quest','competition'],
            'behind_scenes' => ['build','testing','test','research','route','photo','camera','behind','process','making'],
        ][$pillar] ?? ['tennessee','adventure','explore'];
    }

    private static function fallback_format(string $pillar): string {
        return match ($pillar) {
            'brand_intro' => 'reel',
            'feature_reveal' => 'reel',
            'destination_inspiration' => 'reel',
            'local_business' => 'carousel',
            'gameplay' => 'reel',
            'behind_scenes' => 'story',
            default => 'reel',
        };
    }

    private static function best_signal(string $pillar): array {
        $words = self::pillar_words($pillar);
        $best = null;
        foreach (self::records() as $post) {
            $id = (int)$post->ID;
            if (!self::instagram($id)) continue;
            $opp = max(0, min(100, (int)self::meta($id, '_tng_candidate_trend_score', '_tng_source_opportunity_score')));
            $eng = max(0, min(100, (int)self::meta($id, '_tng_candidate_engagement', '_tng_source_engagement')));
            $place = self::meta($id, '_tng_candidate_location', '_tng_location_name');
            $format = strtolower(self::meta($id, '_tng_candidate_format', '_tng_content_format')) ?: self::fallback_format($pillar);
            $creator = self::meta($id, '_tng_candidate_creator', '_tng_creator_handle');
            $url = self::meta($id, '_tng_candidate_source_url', '_tng_source_url');
            $tags = self::hashtags($id);
            $haystack = strtolower(implode(' ', [get_the_title($id), $place, $creator, implode(' ', $tags), self::meta($id, '_tng_candidate_notes', '_tng_content_notes')]));
            $matches = 0;
            foreach ($words as $word) if (str_contains($haystack, strtolower($word))) $matches++;
            $match_score = min(100, $matches * 24);
            $score = (int)round(($opp * .52) + ($eng * .18) + ($match_score * .30));
            if (!$best || $score > $best['score']) {
                $best = [
                    'score'=>$score,'opportunity'=>$opp,'engagement'=>$eng,'place'=>$place ?: 'Tennessee',
                    'format'=>$format,'creator'=>$creator,'url'=>$url,'tags'=>$tags,'title'=>get_the_title($id),
                ];
            }
        }
        if ($best) return $best;
        return ['score'=>0,'opportunity'=>0,'engagement'=>0,'place'=>'Tennessee','format'=>self::fallback_format($pillar),'creator'=>'','url'=>'','tags'=>[],'title'=>''];
    }

    private static function pillar_copy(string $pillar, array $signal): array {
        $place = $signal['place'] ?: 'Tennessee';
        $creator_note = $signal['creator'] ? ' A creator signal is also emerging around this subject, but use it only as inspiration.' : '';
        return match ($pillar) {
            'brand_intro' => [
                'title'=>'Why The TN Game exists — told through '.$place,
                'hook'=>'Tennessee already has the adventure. The TN Game gives you a new way to play it.',
                'angle'=>'Use the current social interest around '.$place.' as the proof point, then introduce The TN Game: discover places, build trips, play challenges, earn XP, and turn a normal outing into something memorable.'.$creator_note,
            ],
            'feature_reveal' => [
                'title'=>'Feature reveal: turn '.$place.' into a TN Game adventure',
                'hook'=>'What if finding this place was only the beginning?',
                'angle'=>'Lead with the strongest '.$place.' visual, then reveal one TN Game feature such as Trip Mode, checkpoints, the live map, XP, or saved trips. Show the product solving a real exploration need rather than presenting a generic feature tour.',
            ],
            'destination_inspiration' => [
                'title'=>'Save this Tennessee adventure: '.$place,
                'hook'=>'This is the kind of Tennessee stop you build a day around.',
                'angle'=>'Use the strongest current Instagram signal around '.$place.' to create an original destination story. Give people one reason to go, one lesser-known detail, and one nearby bonus stop or TN Game action. Do not reproduce the source post.',
            ],
            'local_business' => [
                'title'=>'Add a local stop to the adventure near '.$place,
                'hook'=>'The best Tennessee day does not end when the trail does.',
                'angle'=>'Turn the location signal around '.$place.' into a local-business discovery post. Pair an outdoor attraction or destination with a restaurant, coffee shop, store, event, or stay that makes the trip feel complete.',
            ],
            'gameplay' => [
                'title'=>'How The TN Game would play at '.$place,
                'hook'=>'You found the place. Now there is something to do when you get there.',
                'angle'=>'Use '.$place.' as the setting for a gameplay demonstration: reach a checkpoint, complete a challenge, earn XP, unlock progress, or compete with friends. Keep the destination visually central while showing the game mechanic clearly.',
            ],
            'behind_scenes' => [
                'title'=>'Behind the build: adding '.$place.' to The TN Game',
                'hook'=>'Before this becomes a game, someone has to build the adventure.',
                'angle'=>'Show the real process behind bringing '.$place.' into The TN Game: research, route planning, field testing, photography, checkpoint placement, map cleanup, or content creation. Make the building process part of the launch story.',
            ],
            default => [
                'title'=>'A TN Game idea inspired by '.$place,
                'hook'=>'Here is a Tennessee adventure worth saving.',
                'angle'=>'Use the current Instagram signal around '.$place.' as inspiration for an original TN Game post.',
            ],
        };
    }

    public static function footer(): void {
        if (!current_user_can('edit_posts') || self::page() !== 'tng-content-idea-generator') return;
        $pillar = sanitize_key(wp_unslash($_GET['pillar'] ?? ''));
        if (!$pillar) return;
        $labels = [
            'brand_intro'=>'Brand introduction','feature_reveal'=>'Feature reveal','destination_inspiration'=>'Destination inspiration',
            'local_business'=>'Local business','gameplay'=>'Gameplay','behind_scenes'=>'Behind the scenes',
        ];
        if (!isset($labels[$pillar])) return;
        $signal = self::best_signal($pillar);
        $copy = self::pillar_copy($pillar, $signal);
        $payload = [
            'pillar'=>$pillar,'pillarLabel'=>$labels[$pillar],'signal'=>$signal,'copy'=>$copy,
            'hashtags'=>implode(' ', array_map(static fn($t)=>'#'.ltrim($t,'#'), array_slice($signal['tags'],0,5))),
        ];
        ?>
        <script id="tng-content-signal-fusion">
        (()=>{
            const data=<?php echo wp_json_encode($payload); ?>;
            const grid=document.querySelector('.tng-cig-grid');
            if(!grid)return;
            const hero=document.querySelector('.tng-cig-signals');
            const panel=document.createElement('section');panel.className='tng-signal-fusion';
            const src=data.signal.url?`<a href="${esc(data.signal.url)}" target="_blank" rel="noopener">View source ↗</a>`:'';
            panel.innerHTML=`<div><p class="eyebrow">RECOMMENDED SOCIAL SIGNAL</p><h2>${esc(data.pillarLabel)} × ${esc(data.signal.place)}</h2><p>TN Game matched this missing campaign beat to the strongest relevant Instagram signal currently in your library.</p></div><div class="fusion-stats"><span><strong>${data.signal.score}</strong> match</span><span><strong>${data.signal.opportunity}</strong> opportunity</span><span><strong>${data.signal.engagement}</strong> engagement</span>${src}</div>`;
            if(hero)hero.insertAdjacentElement('afterend',panel);else grid.insertAdjacentElement('beforebegin',panel);
            const first=grid.querySelector('.tng-cig-card');if(!first)return;
            const set=(sel,val)=>{const el=first.querySelector(sel);if(el&&val!==undefined&&val!==null)el.value=val};
            set('[name="idea_title"]',data.copy.title);set('[name="idea_hook"]',data.copy.hook);set('[name="idea_angle"]',data.copy.angle);set('[name="idea_place"]',data.signal.place);set('[name="idea_format"]',data.signal.format||'reel');
            if(data.hashtags)set('[name="idea_hashtags"]',data.hashtags+' #Tennessee #TheTNGame');
            const badge=document.createElement('div');badge.className='tng-fusion-badge';badge.textContent='Recommended for '+data.pillarLabel;first.prepend(badge);
            function esc(v){const d=document.createElement('div');d.textContent=v||'';return d.innerHTML}
        })();
        </script>
        <?php
    }

    public static function assets(): void {
        if (self::page() !== 'tng-content-idea-generator' || empty($_GET['pillar'])) return;
        wp_register_style('tng-content-signal-fusion', false, [], defined('TNG_OS_VERSION') ? TNG_OS_VERSION : null);
        wp_enqueue_style('tng-content-signal-fusion');
        wp_add_inline_style('tng-content-signal-fusion', '
            .tng-signal-fusion{background:#fff;border:1px solid #dce5df;border-left:5px solid #f05b25;border-radius:18px;padding:18px 20px;margin:16px 0;display:flex;justify-content:space-between;gap:24px;align-items:center}.tng-signal-fusion h2{margin:3px 0 6px;color:#173a2c}.tng-signal-fusion p{margin:0;color:#69786f;max-width:720px}.fusion-stats{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end}.fusion-stats span{background:#f3f7f4;border-radius:12px;padding:8px 11px;color:#66766d;font-size:10px;text-transform:uppercase}.fusion-stats strong{display:block;color:#173a2c;font-size:18px}.fusion-stats a{color:#dd501d;font-weight:700;text-decoration:none;white-space:nowrap}.tng-fusion-badge{display:inline-block;background:#fff0e8;color:#c34a1d;border-radius:999px;padding:5px 9px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px}@media(max-width:900px){.tng-signal-fusion{display:block}.fusion-stats{justify-content:flex-start;margin-top:14px}}
        ');
    }
}
TNG_Content_Signal_Fusion::boot();
