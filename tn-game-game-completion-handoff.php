<?php
/**
 * TN Game Game Completion Handoff
 * Connects a completed game to Explorer progression and the next playable adventure.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Game_Completion_Handoff {
    public static function boot(): void {
        add_action('wp_footer', [__CLASS__, 'footer'], 176);
    }

    private static function is_gameplay(): bool {
        if (is_admin()) return false;
        $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if (strpos($path, '/game-play/') !== false) return true;
        return function_exists('is_page') && is_page('game-play');
    }

    private static function game_id(): int {
        return self::is_gameplay() && isset($_GET['game']) ? absint($_GET['game']) : 0;
    }

    private static function checkpoints(int $game_id): array {
        $raw = get_post_meta($game_id, 'tng_game_checkpoints', true);
        return is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
    }

    private static function progress(int $game_id, int $user_id): array {
        $checkpoints = self::checkpoints($game_id);
        $total = count($checkpoints);
        if (!$total) $total = absint(get_post_meta($game_id, 'checkpoint_count', true));
        $saved = get_user_meta($user_id, '_tng_game_progress_' . $game_id, true);
        $done = is_array($saved) ? count(array_unique(array_map('absint', $saved))) : 0;
        return ['done' => $done, 'total' => $total, 'complete' => ($total > 0 && $done >= $total)];
    }

    private static function reward_xp(int $game_id): int {
        $xp = absint(get_post_meta($game_id, 'xp_available', true));
        if ($xp) return $xp;
        foreach (self::checkpoints($game_id) as $checkpoint) $xp += absint($checkpoint['xp'] ?? 0);
        return $xp;
    }

    private static function gamipress_snapshot(int $user_id): array {
        $result = ['xp' => null, 'xp_label' => 'Explorer XP', 'rank' => 'Explorer'];
        if (function_exists('gamipress_get_points_types') && function_exists('gamipress_get_user_points')) {
            $types = gamipress_get_points_types();
            if (is_array($types) && $types) {
                $selected = '';
                foreach ($types as $slug => $type) {
                    $name = is_array($type) ? (string)($type['plural_name'] ?? $type['singular_name'] ?? $slug) : (string)$slug;
                    if (stripos($slug, 'xp') !== false || stripos($name, 'xp') !== false || stripos($name, 'explorer') !== false) { $selected = (string)$slug; break; }
                }
                if (!$selected) $selected = (string)array_key_first($types);
                if ($selected) {
                    $type = $types[$selected] ?? [];
                    $result['xp'] = (int) gamipress_get_user_points($user_id, $selected);
                    if (is_array($type)) $result['xp_label'] = sanitize_text_field((string)($type['plural_name'] ?? $type['singular_name'] ?? 'Explorer XP'));
                }
            }
        }
        if (function_exists('gamipress_get_rank_types') && function_exists('gamipress_get_user_rank_id')) {
            $rank_types = gamipress_get_rank_types();
            if (is_array($rank_types) && $rank_types) {
                $rank_type = (string)array_key_first($rank_types);
                if ($rank_type) {
                    $rank_id = absint(gamipress_get_user_rank_id($user_id, $rank_type));
                    if ($rank_id && get_post($rank_id)) $result['rank'] = get_the_title($rank_id);
                }
            }
        }
        return $result;
    }

    private static function completed_games(int $user_id, int $current_game): int {
        $completed = get_user_meta($user_id, '_tng_completed_games', true);
        $ids = is_array($completed) ? array_values(array_unique(array_map('absint', $completed))) : [];
        if (!in_array($current_game, $ids, true)) $ids[] = $current_game;
        return count(array_filter($ids));
    }

    private static function next_game(int $current_game, int $user_id): array {
        if (!class_exists('TNG_Games_UI') || !method_exists('TNG_Games_UI', 'posts')) return [];
        $completed = get_user_meta($user_id, '_tng_completed_games', true);
        $completed = is_array($completed) ? array_map('absint', $completed) : [];
        foreach (TNG_Games_UI::posts() as $post) {
            $id = absint($post->ID ?? 0);
            if (!$id || $id === $current_game || in_array($id, $completed, true)) continue;
            return [
                'id' => $id,
                'title' => get_the_title($id),
                'url' => get_permalink($id),
                'play' => add_query_arg('game', $id, home_url('/game-play/')),
                'image' => get_the_post_thumbnail_url($id, 'medium_large') ?: '',
            ];
        }
        return [];
    }

    public static function footer(): void {
        if (!self::is_gameplay() || !is_user_logged_in()) return;
        $game_id = self::game_id();
        $user_id = get_current_user_id();
        if (!$game_id || !get_post($game_id)) return;
        $progress = self::progress($game_id, $user_id);
        if (!$progress['complete']) return;

        $snapshot = self::gamipress_snapshot($user_id);
        $payload = [
            'gameId' => $game_id,
            'earnedXp' => self::reward_xp($game_id),
            'explorerXp' => $snapshot['xp'],
            'xpLabel' => $snapshot['xp_label'],
            'rank' => $snapshot['rank'],
            'completedGames' => self::completed_games($user_id, $game_id),
            'next' => self::next_game($game_id, $user_id),
            'profileUrl' => home_url('/profile/'),
            'gamesUrl' => home_url('/games/'),
        ];
        ?>
        <style id="tng-completion-handoff-css">
        .tng-completion-handoff{grid-column:1/-1;margin-top:2px;padding:18px;border:1px solid #d9e8dd;border-radius:18px;background:linear-gradient(135deg,#f6fbf7,#fff);color:#173d2f}.tng-completion-handoff__top{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:14px}.tng-completion-handoff__eyebrow{display:block;color:#f16022;font-size:9px;font-weight:900;letter-spacing:.13em;text-transform:uppercase}.tng-completion-handoff h3{margin:4px 0 0;font-size:22px;line-height:1.1;color:#173d2f}.tng-completion-handoff__profile{font-size:11px;font-weight:900;color:#e85618!important;text-decoration:none;white-space:nowrap}.tng-completion-handoff__stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px}.tng-completion-handoff__stat{padding:13px;border:1px solid #e0e9e2;border-radius:13px;background:#fff}.tng-completion-handoff__stat strong{display:block;font-size:20px;line-height:1;color:#173d2f}.tng-completion-handoff__stat span{display:block;margin-top:5px;color:#718078;font-size:8px;font-weight:900;letter-spacing:.09em;text-transform:uppercase}.tng-completion-handoff__next{display:grid;grid-template-columns:64px 1fr auto;align-items:center;gap:12px;margin-top:12px;padding:11px;border:1px solid #e0e9e2;border-radius:14px;background:#fff}.tng-completion-handoff__next-media{width:64px;height:54px;border-radius:10px;background:linear-gradient(135deg,#0c412e,#1c6b4b);background-size:cover;background-position:center}.tng-completion-handoff__next small{display:block;color:#e85618;font-size:8px;font-weight:900;letter-spacing:.1em;text-transform:uppercase}.tng-completion-handoff__next strong{display:block;margin-top:2px;color:#173d2f;font-size:14px}.tng-completion-handoff__next a{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:9px 12px;border-radius:10px;background:#f16022;color:#fff!important;text-decoration:none;font-size:10px;font-weight:900;white-space:nowrap}@media(max-width:760px){.tng-completion-handoff__stats{grid-template-columns:repeat(2,1fr)}.tng-completion-handoff__next{grid-template-columns:54px 1fr}.tng-completion-handoff__next-media{width:54px;height:50px}.tng-completion-handoff__next a{grid-column:1/-1;width:100%}}@media(max-width:480px){.tng-completion-handoff__top{align-items:flex-start;flex-direction:column}.tng-completion-handoff__stats{grid-template-columns:1fr 1fr}}
        </style>
        <script id="tng-completion-handoff-js">
        (()=>{
          const data=<?php echo wp_json_encode($payload); ?>;
          const esc=v=>{const d=document.createElement('div');d.textContent=String(v??'');return d.innerHTML};
          const build=()=>{
            const recap=document.querySelector('.tng-completed-recap');if(!recap||recap.querySelector('.tng-completion-handoff'))return true;
            const stats=recap.querySelector('.tng-completed-recap__stats');if(!stats)return false;
            const totalXp=data.explorerXp===null?'Saved':Number(data.explorerXp).toLocaleString();
            const next=data.next&&data.next.id?`<div class="tng-completion-handoff__next"><div class="tng-completion-handoff__next-media"${data.next.image?` style="background-image:url('${esc(data.next.image)}')"`:''}></div><div><small>Recommended next adventure</small><strong>${esc(data.next.title)}</strong></div><a href="${esc(data.next.url||data.gamesUrl)}">View adventure →</a></div>`:`<div class="tng-completion-handoff__next"><div class="tng-completion-handoff__next-media"></div><div><small>Keep exploring</small><strong>Find another TN Game adventure</strong></div><a href="${esc(data.gamesUrl)}">Browse games →</a></div>`;
            const section=document.createElement('section');section.className='tng-completion-handoff';section.innerHTML=`<div class="tng-completion-handoff__top"><div><span class="tng-completion-handoff__eyebrow">Explorer progression</span><h3>Your adventure moved you forward.</h3></div><a class="tng-completion-handoff__profile" href="${esc(data.profileUrl)}">View full profile →</a></div><div class="tng-completion-handoff__stats"><div class="tng-completion-handoff__stat"><strong>+${Number(data.earnedXp||0).toLocaleString()}</strong><span>XP this adventure</span></div><div class="tng-completion-handoff__stat"><strong>${totalXp}</strong><span>${esc(data.xpLabel||'Explorer XP')}</span></div><div class="tng-completion-handoff__stat"><strong>${Number(data.completedGames||1)}</strong><span>Adventures completed</span></div><div class="tng-completion-handoff__stat"><strong>${esc(data.rank||'Explorer')}</strong><span>Current rank</span></div></div>${next}`;
            stats.insertAdjacentElement('afterend',section);
            return true;
          };
          if(!build()){let tries=0;const timer=setInterval(()=>{tries++;if(build()||tries>40)clearInterval(timer)},150)}
          window.addEventListener('tng:game-completed',()=>setTimeout(build,60));
        })();
        </script>
        <?php
    }
}

TNG_Game_Completion_Handoff::boot();
