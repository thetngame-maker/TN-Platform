<?php
/**
 * TN Game Content Calendar
 * Visual weekly planner with drag/drop scheduling and campaign balance intelligence.
 */
if (!defined('ABSPATH')) exit;

final class TNG_Content_Calendar {
    private const ITEM = 'tng_social_item';
    private const NONCE = 'tng_content_calendar_action';

    public static function boot(): void {
        static $booted = false;
        if ($booted) return;
        $booted = true;
        add_action('admin_menu', [__CLASS__, 'replace_menu'], 99);
        add_action('admin_post_tng_calendar_schedule', [__CLASS__, 'schedule']);
        add_action('admin_post_tng_calendar_unschedule', [__CLASS__, 'unschedule']);
        add_action('admin_post_tng_calendar_status', [__CLASS__, 'status']);
        add_action('wp_ajax_tng_calendar_move', [__CLASS__, 'ajax_move']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function replace_menu(): void {
        remove_submenu_page('tng-content-studio', 'tng-content-calendar');
        add_submenu_page('tng-content-studio', 'Content Calendar', 'Content Calendar', 'edit_posts', 'tng-content-calendar', [__CLASS__, 'render']);
    }

    private static function guard(): int {
        if (!current_user_can('edit_posts')) wp_die('Not allowed.');
        check_admin_referer(self::NONCE);
        $id = absint($_POST['item_id'] ?? $_GET['item_id'] ?? 0);
        if (!$id || get_post_type($id) !== self::ITEM || !current_user_can('edit_post', $id)) wp_die('Invalid content item.');
        return $id;
    }

    private static function calendar_url(array $args = []): string {
        return add_query_arg(array_merge(['page' => 'tng-content-calendar'], $args), admin_url('admin.php'));
    }

    private static function redirect(string $notice = ''): void {
        $week = sanitize_text_field(wp_unslash($_POST['week'] ?? $_GET['week'] ?? ''));
        $args = $notice ? ['tng_notice' => $notice] : [];
        if ($week) $args['week'] = $week;
        wp_safe_redirect(self::calendar_url($args));
        exit;
    }

    private static function valid_date(string $date): bool {
        if ($date === '') return true;
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        return $dt && $dt->format('Y-m-d') === $date;
    }

    private static function apply_date(int $id, string $date): void {
        $status = (string) get_post_meta($id, '_tng_plan_status', true);
        if ($date === '') {
            delete_post_meta($id, '_tng_planned_date');
            if ($status === 'scheduled') update_post_meta($id, '_tng_plan_status', 'ready');
            elseif ($status === 'planned') update_post_meta($id, '_tng_plan_status', 'idea');
            return;
        }
        update_post_meta($id, '_tng_planned_date', $date);
        if (in_array($status, ['', 'inspiration', 'idea'], true)) update_post_meta($id, '_tng_plan_status', 'planned');
    }

    public static function schedule(): void {
        $id = self::guard();
        $date = sanitize_text_field(wp_unslash($_POST['planned_date'] ?? ''));
        if (!self::valid_date($date) || $date === '') self::redirect('Choose a valid publish date.');
        self::apply_date($id, $date);
        self::redirect('Content placed on the calendar.');
    }

    public static function unschedule(): void {
        $id = self::guard();
        self::apply_date($id, '');
        self::redirect('Moved back to Unscheduled.');
    }

    public static function status(): void {
        $id = self::guard();
        $status = sanitize_key(wp_unslash($_POST['plan_status'] ?? ''));
        $allowed = ['idea','planned','creating','ready','scheduled','published','archived'];
        if (!in_array($status, $allowed, true)) self::redirect('Invalid status.');
        update_post_meta($id, '_tng_plan_status', $status);
        if ($status === 'scheduled' && !get_post_meta($id, '_tng_planned_date', true)) update_post_meta($id, '_tng_planned_date', current_time('Y-m-d'));
        self::redirect('Production status updated.');
    }

    public static function ajax_move(): void {
        if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'Not allowed.'], 403);
        check_ajax_referer(self::NONCE, 'nonce');
        $id = absint($_POST['item_id'] ?? 0);
        $date = sanitize_text_field(wp_unslash($_POST['planned_date'] ?? ''));
        if (!$id || get_post_type($id) !== self::ITEM || !current_user_can('edit_post', $id)) wp_send_json_error(['message' => 'Invalid content item.'], 400);
        if (!self::valid_date($date)) wp_send_json_error(['message' => 'Invalid date.'], 400);
        self::apply_date($id, $date);
        wp_send_json_success([
            'item_id' => $id,
            'planned_date' => $date,
            'status' => self::meta($id, '_tng_plan_status', 'idea'),
            'message' => $date ? 'Content moved to ' . $date . '.' : 'Content moved to Unscheduled.',
        ]);
    }

    private static function meta(int $id, string $key, string $fallback = ''): string {
        $v = trim((string) get_post_meta($id, $key, true));
        return $v !== '' ? $v : $fallback;
    }

    private static function week_start(): DateTimeImmutable {
        $raw = sanitize_text_field(wp_unslash($_GET['week'] ?? ''));
        try { $base = $raw ? new DateTimeImmutable($raw) : new DateTimeImmutable('today'); }
        catch (Exception $e) { $base = new DateTimeImmutable('today'); }
        $day = (int) $base->format('N');
        return $base->modify('-' . ($day - 1) . ' days');
    }

    private static function all_items(): array {
        return get_posts([
            'post_type' => self::ITEM,
            'post_status' => ['publish','draft','private'],
            'posts_per_page' => 300,
            'orderby' => 'modified',
            'order' => 'DESC',
            'meta_query' => [
                'relation' => 'OR',
                ['key' => '_tng_plan_status', 'compare' => 'EXISTS'],
                ['key' => '_tng_planned_date', 'compare' => 'EXISTS'],
            ],
        ]);
    }

    private static function status_label(string $status): string {
        return match ($status) {
            'idea' => 'Idea', 'planned' => 'Planned', 'creating' => 'Creating', 'ready' => 'Ready',
            'scheduled' => 'Scheduled', 'published' => 'Published', 'archived' => 'Archived', default => 'Inspiration'
        };
    }

    private static function format_icon(string $format): string {
        return match ($format) {
            'reel' => '▶', 'carousel' => '▦', 'photo' => '▧', 'story' => '◉', 'long_video' => '▸', default => '✦'
        };
    }

    private static function builder_url(int $id): string {
        return add_query_arg(['page' => 'tng-content-post-builder', 'idea' => $id], admin_url('admin.php'));
    }

    private static function item_card(WP_Post $item, string $week_key, bool $compact = false): string {
        $id = (int) $item->ID;
        $status = self::meta($id, '_tng_plan_status', 'idea');
        $format = self::meta($id, '_tng_content_format', 'post');
        $place = self::meta($id, '_tng_location_name');
        $campaign = self::meta($id, '_tng_campaign');
        $hook = self::meta($id, '_tng_hook');
        $date = self::meta($id, '_tng_planned_date');
        ob_start(); ?>
        <article class="tng-cal-card status-<?php echo esc_attr($status); ?>" draggable="true" data-item-id="<?php echo esc_attr($id); ?>" data-planned-date="<?php echo esc_attr($date); ?>">
            <div class="drag-handle" title="Drag to another day">⋮⋮</div>
            <div class="tng-cal-card-top"><span class="format"><?php echo esc_html(self::format_icon($format) . ' ' . ucwords(str_replace('_',' ',$format))); ?></span><span class="status"><?php echo esc_html(self::status_label($status)); ?></span></div>
            <h4><?php echo esc_html($item->post_title); ?></h4>
            <?php if (!$compact && $hook): ?><p class="hook"><?php echo esc_html(wp_trim_words($hook, 14)); ?></p><?php endif; ?>
            <div class="meta"><?php echo $place ? '<span>📍 ' . esc_html($place) . '</span>' : ''; ?><?php echo $campaign ? '<span>🏷 ' . esc_html($campaign) . '</span>' : ''; ?></div>
            <div class="actions">
                <a href="<?php echo esc_url(self::builder_url($id)); ?>">Build</a>
                <?php if ($date): ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="tng_calendar_unschedule"><input type="hidden" name="item_id" value="<?php echo $id; ?>"><input type="hidden" name="week" value="<?php echo esc_attr($week_key); ?>">
                        <?php wp_nonce_field(self::NONCE); ?><button type="submit" class="link">Unplan</button>
                    </form>
                <?php endif; ?>
            </div>
        </article>
        <?php return (string) ob_get_clean();
    }

    private static function weekly_health(array $week_items, array $by_date, DateTimeImmutable $start): array {
        $formats = []; $places = []; $campaigns = []; $local_business = 0;
        foreach ($week_items as $item) {
            $id = (int) $item->ID;
            $format = strtolower(self::meta($id, '_tng_content_format', 'post'));
            $place = trim(self::meta($id, '_tng_location_name'));
            $campaign = trim(self::meta($id, '_tng_campaign'));
            $formats[$format] = ($formats[$format] ?? 0) + 1;
            if ($place) $places[strtolower($place)] = ['label'=>$place,'count'=>(($places[strtolower($place)]['count'] ?? 0) + 1)];
            if ($campaign) $campaigns[strtolower($campaign)] = ['label'=>$campaign,'count'=>(($campaigns[strtolower($campaign)]['count'] ?? 0) + 1)];
            $hay = strtolower($item->post_title . ' ' . $place . ' ' . get_post_field('post_content', $id) . ' ' . self::meta($id, '_tng_original_angle'));
            if (preg_match('/\b(food|restaurant|cafe|coffee|bakery|shop|store|local business|eat|dining)\b/', $hay)) $local_business++;
        }
        $total = count($week_items);
        $open_days = 0;
        for ($i=0;$i<7;$i++) { $key=$start->modify('+' . $i . ' days')->format('Y-m-d'); if (empty($by_date[$key])) $open_days++; }
        arsort($formats);
        $top_format = $formats ? array_key_first($formats) : '';
        $top_format_count = $top_format ? (int)$formats[$top_format] : 0;
        $repeat_place = null;
        foreach ($places as $p) if (!$repeat_place || $p['count'] > $repeat_place['count']) $repeat_place = $p;
        $coming_soon = 0;
        foreach ($campaigns as $key=>$c) if (str_contains($key, 'coming soon')) $coming_soon += $c['count'];

        $signals = [];
        if ($total === 0) {
            $signals[] = ['tone'=>'neutral','title'=>'Week is open','text'=>'No content is planned yet. Start with 3–5 posts so the week has a clear rhythm.'];
            return $signals;
        }
        if ($total >= 3 && $top_format_count / $total >= .67) $signals[] = ['tone'=>'warn','title'=>'Format is concentrated','text'=>ucwords(str_replace('_',' ',$top_format)) . ' makes up ' . $top_format_count . ' of ' . $total . ' posts. Consider mixing in a carousel, photo, or Story.'];
        else $signals[] = ['tone'=>'good','title'=>'Format mix looks healthy','text'=>count($formats) . ' content format' . (count($formats)===1?'':'s') . ' represented this week.'];
        if ($repeat_place && $repeat_place['count'] >= 3) $signals[] = ['tone'=>'warn','title'=>'Location is repeating','text'=>$repeat_place['label'] . ' appears in ' . $repeat_place['count'] . ' posts. A different town, trail, sight, or business could broaden the week.'];
        if ($open_days >= 5 && $total < 3) $signals[] = ['tone'=>'warn','title'=>'Large publishing gaps','text'=>$open_days . ' days are open. Add another post or two if you want a steadier launch cadence.'];
        elseif ($open_days <= 3) $signals[] = ['tone'=>'good','title'=>'Publishing rhythm looks active','text'=>(7-$open_days) . ' days currently have planned content.'];
        if ($local_business === 0 && $total >= 3) $signals[] = ['tone'=>'idea','title'=>'Add a local-business story','text'=>'This week is adventure-heavy. Consider a restaurant, shop, coffee stop, or other local favorite to show the broader TN Game experience.'];
        if ($coming_soon > 0 && $coming_soon < 3) $signals[] = ['tone'=>'idea','title'=>'Coming Soon campaign could use another beat','text'=>'Only ' . $coming_soon . ' Coming Soon post is planned this week. A teaser, feature reveal, or behind-the-scenes post could strengthen the launch story.'];
        if (!$signals) $signals[] = ['tone'=>'good','title'=>'Week looks balanced','text'=>'No major content-balance issues detected.'];
        return array_slice($signals, 0, 4);
    }

    public static function render(): void {
        if (!current_user_can('edit_posts')) return;
        $start = self::week_start();
        $week_key = $start->format('Y-m-d');
        $end = $start->modify('+6 days');
        $prev = $start->modify('-7 days')->format('Y-m-d');
        $next = $start->modify('+7 days')->format('Y-m-d');
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $items = self::all_items();
        $by_date = []; $unscheduled = []; $week_items = [];
        $counts = ['idea'=>0,'creating'=>0,'ready'=>0,'scheduled'=>0,'published'=>0];
        foreach ($items as $item) {
            $id = (int) $item->ID;
            $status = self::meta($id, '_tng_plan_status', 'idea');
            if (isset($counts[$status])) $counts[$status]++;
            $date = self::meta($id, '_tng_planned_date');
            if ($date) {
                $by_date[$date][] = $item;
                if ($date >= $week_key && $date <= $end->format('Y-m-d') && !in_array($status,['archived'],true)) $week_items[] = $item;
            } else if (!in_array($status, ['published','archived','inspiration'], true)) $unscheduled[] = $item;
        }
        $health = self::weekly_health($week_items, $by_date, $start);
        ?>
        <div class="wrap tng-cal">
            <section class="tng-cal-hero">
                <div><p class="eyebrow">CONTENT STUDIO</p><h1>Plan the week. Know what needs to get made.</h1><p>Drag posts between days, balance the campaign, and keep your production pipeline moving.</p></div>
                <div class="hero-actions"><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-content-idea-generator')); ?>">+ Generate idea</a><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tng-content-post-builder')); ?>">Post Builder</a></div>
            </section>
            <?php if (isset($_GET['tng_notice'])): ?><div class="notice notice-success inline"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['tng_notice']))); ?></p></div><?php endif; ?>
            <section class="tng-cal-stats">
                <div><strong><?php echo count($unscheduled); ?></strong><span>Unscheduled</span></div><div><strong><?php echo $counts['creating']; ?></strong><span>Creating</span></div><div><strong><?php echo $counts['ready']; ?></strong><span>Ready</span></div><div><strong><?php echo $counts['scheduled']; ?></strong><span>Scheduled</span></div><div><strong><?php echo $counts['published']; ?></strong><span>Published</span></div>
            </section>
            <section class="tng-cal-toolbar">
                <a class="button" href="<?php echo esc_url(self::calendar_url(['week'=>$prev])); ?>">← Previous</a>
                <div><p class="eyebrow">WEEK OF</p><h2><?php echo esc_html($start->format('M j') . ' – ' . $end->format('M j, Y')); ?></h2></div>
                <div><a class="button" href="<?php echo esc_url(self::calendar_url()); ?>">Today</a> <a class="button" href="<?php echo esc_url(self::calendar_url(['week'=>$next])); ?>">Next →</a></div>
            </section>
            <section class="tng-cal-health">
                <div class="health-head"><div><p class="eyebrow">WEEKLY INTELLIGENCE</p><h2>Campaign balance</h2></div><span><?php echo count($week_items); ?> planned</span></div>
                <div class="health-grid">
                    <?php foreach ($health as $signal): ?><article class="health-card <?php echo esc_attr($signal['tone']); ?>"><strong><?php echo esc_html($signal['title']); ?></strong><p><?php echo esc_html($signal['text']); ?></p></article><?php endforeach; ?>
                </div>
            </section>
            <div class="tng-cal-layout">
                <aside class="tng-cal-queue tng-drop-zone" data-drop-date="">
                    <div class="section-head"><div><p class="eyebrow">PRODUCTION QUEUE</p><h2>Unscheduled</h2></div><span class="zone-count"><?php echo count($unscheduled); ?></span></div>
                    <p class="description">Drag a card here to remove its publish date. Its production work stays intact.</p>
                    <div class="queue-drop-body">
                        <?php if (!$unscheduled): ?><div class="empty drop-placeholder">Nothing waiting. Your queue is clear.</div><?php endif; ?>
                        <?php foreach ($unscheduled as $item): $id=(int)$item->ID; ?>
                            <div class="queue-item">
                                <?php echo self::item_card($item, $week_key, true); ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="schedule-form">
                                    <input type="hidden" name="action" value="tng_calendar_schedule"><input type="hidden" name="item_id" value="<?php echo $id; ?>"><input type="hidden" name="week" value="<?php echo esc_attr($week_key); ?>">
                                    <?php wp_nonce_field(self::NONCE); ?>
                                    <input type="date" name="planned_date" min="<?php echo esc_attr($start->format('Y-m-d')); ?>" value="<?php echo esc_attr($start->format('Y-m-d')); ?>"><button class="button button-primary">Place</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </aside>
                <main class="tng-cal-week">
                    <?php for ($i=0;$i<7;$i++): $day=$start->modify('+' . $i . ' days'); $key=$day->format('Y-m-d'); $day_items=$by_date[$key]??[]; ?>
                        <section class="tng-cal-day tng-drop-zone <?php echo $key===$today?'today':''; ?>" data-drop-date="<?php echo esc_attr($key); ?>">
                            <header><div><span><?php echo esc_html($day->format('D')); ?></span><strong><?php echo esc_html($day->format('j')); ?></strong></div><small><b class="zone-count"><?php echo count($day_items); ?></b> post<?php echo count($day_items)===1?'':'s'; ?></small></header>
                            <div class="day-body">
                                <?php if (!$day_items): ?><div class="day-empty drop-placeholder">Drop here</div><?php endif; ?>
                                <?php foreach ($day_items as $item) echo self::item_card($item, $week_key); ?>
                            </div>
                        </section>
                    <?php endfor; ?>
                </main>
            </div>
            <div class="tng-cal-toast" role="status" aria-live="polite"></div>
        </div>
        <?php
    }

    public static function assets(): void {
        if (!isset($_GET['page']) || $_GET['page'] !== 'tng-content-calendar') return;
        wp_register_style('tng-content-calendar', false, [], defined('TNG_OS_VERSION') ? TNG_OS_VERSION : null); wp_enqueue_style('tng-content-calendar');
        wp_add_inline_style('tng-content-calendar', '.tng-cal{max-width:1500px}.tng-cal-hero{margin:20px 0;background:linear-gradient(135deg,#073c2b,#176b45);color:#fff;border-radius:24px;padding:32px;display:flex;justify-content:space-between;gap:24px}.tng-cal-hero h1{color:#fff;font-size:34px;margin:6px 0 10px}.tng-cal-hero p{max-width:760px;font-size:15px}.hero-actions{display:flex;gap:8px;align-items:flex-start}.eyebrow{font-size:11px;font-weight:800;letter-spacing:.13em;color:#f05b25;margin:0}.tng-cal-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin:18px 0}.tng-cal-stats>div{background:#fff;border:1px solid #dfe5df;border-radius:16px;padding:18px}.tng-cal-stats strong{font-size:28px;color:#123c2b;display:block}.tng-cal-stats span{color:#69766e}.tng-cal-toolbar{background:#fff;border:1px solid #dfe5df;border-radius:18px;padding:18px 20px;margin:16px 0;display:flex;align-items:center;justify-content:space-between}.tng-cal-toolbar h2{margin:2px 0 0;color:#123c2b}.tng-cal-health{background:#fff;border:1px solid #dfe5df;border-radius:20px;padding:18px;margin:16px 0}.health-head{display:flex;align-items:center;justify-content:space-between}.health-head h2{margin:3px 0;color:#153c2d}.health-head>span{background:#edf5ef;color:#175c3c;border-radius:999px;padding:6px 10px;font-weight:700}.health-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:14px}.health-card{border:1px solid #dfe5df;border-radius:14px;padding:13px;background:#fafcfb}.health-card strong{display:block;color:#183c2e}.health-card p{font-size:12px;color:#647168;margin:5px 0 0;line-height:1.45}.health-card.good{background:#eef8f1;border-color:#cfe6d6}.health-card.warn{background:#fff8ea;border-color:#f1deb2}.health-card.idea{background:#fff3ed;border-color:#f2d1c2}.health-card.neutral{background:#f4f6f5}.tng-cal-layout{display:grid;grid-template-columns:330px minmax(0,1fr);gap:18px;align-items:start}.tng-cal-queue{background:#fff;border:1px solid #dfe5df;border-radius:20px;padding:18px;position:sticky;top:42px;transition:.18s}.section-head{display:flex;justify-content:space-between;align-items:center}.section-head h2{margin:3px 0}.section-head>span{background:#edf5ef;color:#175c3c;border-radius:999px;padding:6px 10px;font-weight:700}.queue-item{padding:14px 0;border-top:1px solid #eef1ee}.queue-item:first-of-type{border-top:0}.schedule-form{display:flex;gap:6px;margin-top:8px}.schedule-form input{min-width:0;width:100%}.tng-cal-week{display:grid;grid-template-columns:repeat(7,minmax(150px,1fr));gap:8px;overflow-x:auto;padding-bottom:10px}.tng-cal-day{background:#f8faf8;border:1px solid #dfe5df;border-radius:18px;min-height:520px;overflow:hidden;transition:.18s}.tng-cal-day.today{border:2px solid #f05b25}.tng-cal-day>header{background:#fff;border-bottom:1px solid #e3e8e3;padding:13px;display:flex;justify-content:space-between;align-items:center}.tng-cal-day header span{display:block;text-transform:uppercase;font-size:10px;font-weight:800;color:#728077}.tng-cal-day header strong{font-size:26px;color:#123c2b}.tng-cal-day header small{color:#7a867e}.day-body{padding:8px;min-height:450px}.day-empty{height:90px;border:1px dashed #cfd8d1;border-radius:12px;color:#9aa49d;display:flex;align-items:center;justify-content:center}.tng-cal-card{position:relative;background:#fff;border:1px solid #dde4de;border-radius:13px;padding:11px;margin-bottom:8px;box-shadow:0 2px 7px rgba(18,60,43,.035);border-left:4px solid #9da9a1;cursor:grab}.tng-cal-card:active{cursor:grabbing}.tng-cal-card.dragging{opacity:.42;transform:scale(.98)}.drag-handle{position:absolute;right:8px;top:7px;color:#a7b0aa;font-size:12px;letter-spacing:-2px}.tng-cal-card.status-creating{border-left-color:#f0a52b}.tng-cal-card.status-ready{border-left-color:#3f8f63}.tng-cal-card.status-scheduled{border-left-color:#3157d5}.tng-cal-card.status-published{border-left-color:#176b45}.tng-cal-card.status-idea{border-left-color:#f05b25}.tng-cal-card-top{display:flex;justify-content:space-between;gap:6px;font-size:10px;text-transform:uppercase;font-weight:800;padding-right:18px}.tng-cal-card-top .format{color:#f05b25}.tng-cal-card-top .status{color:#617068}.tng-cal-card h4{font-size:14px;margin:7px 0;color:#19392c;line-height:1.25}.tng-cal-card .hook{font-size:11px;color:#647168;margin:5px 0}.tng-cal-card .meta{font-size:10px;color:#738078;display:flex;flex-direction:column;gap:2px}.tng-cal-card .actions{display:flex;gap:8px;margin-top:8px;align-items:center}.tng-cal-card .actions a,.tng-cal-card .actions .link{font-size:10px;font-weight:700;color:#d94a18;text-decoration:none;border:0;background:none;padding:0;cursor:pointer}.tng-cal-card .actions form{margin:0}.empty{padding:18px;border:1px dashed #ccd6ce;border-radius:12px;color:#7a867e;background:#fafcfa}.tng-drop-zone.drag-over{outline:3px solid rgba(240,91,37,.35);outline-offset:2px;background:#fff8f4}.tng-cal-toast{position:fixed;right:24px;bottom:24px;background:#153c2d;color:#fff;border-radius:12px;padding:11px 15px;box-shadow:0 12px 30px rgba(0,0,0,.18);font-weight:700;opacity:0;transform:translateY(8px);pointer-events:none;transition:.2s;z-index:99999}.tng-cal-toast.show{opacity:1;transform:none}@media(max-width:1180px){.tng-cal-layout{grid-template-columns:1fr}.tng-cal-queue{position:static}.tng-cal-week{grid-template-columns:repeat(7,220px)}.health-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:782px){.tng-cal-hero{flex-direction:column}.tng-cal-stats{grid-template-columns:repeat(2,1fr)}.tng-cal-toolbar{gap:8px;flex-wrap:wrap}.tng-cal-layout{display:block}.tng-cal-week{margin-top:16px}.health-grid{grid-template-columns:1fr}}');

        wp_register_script('tng-content-calendar-dnd', '', [], defined('TNG_OS_VERSION') ? TNG_OS_VERSION : null, true); wp_enqueue_script('tng-content-calendar-dnd');
        $config = wp_json_encode(['ajaxUrl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce(self::NONCE)]);
        wp_add_inline_script('tng-content-calendar-dnd', 'window.TNGCalendar=' . $config . ';(() => {let dragged=null;const toast=document.querySelector(".tng-cal-toast");const show=m=>{if(!toast)return;toast.textContent=m;toast.classList.add("show");setTimeout(()=>toast.classList.remove("show"),1800)};const zoneBody=z=>z.classList.contains("tng-cal-day")?z.querySelector(".day-body"):z.querySelector(".queue-drop-body");const updateCounts=()=>{document.querySelectorAll(".tng-drop-zone").forEach(z=>{const n=z.querySelectorAll(":scope .tng-cal-card").length;const count=z.querySelector(".zone-count");if(count)count.textContent=n;const p=z.querySelector(".drop-placeholder");if(n>0&&p)p.remove();if(n===0&&!p){const e=document.createElement("div");e.className=z.classList.contains("tng-cal-day")?"day-empty drop-placeholder":"empty drop-placeholder";e.textContent=z.classList.contains("tng-cal-day")?"Drop here":"Nothing waiting. Your queue is clear.";zoneBody(z).appendChild(e)}})};document.addEventListener("dragstart",e=>{const c=e.target.closest(".tng-cal-card");if(!c)return;dragged=c;c.classList.add("dragging");e.dataTransfer.effectAllowed="move";e.dataTransfer.setData("text/plain",c.dataset.itemId)});document.addEventListener("dragend",()=>{document.querySelectorAll(".drag-over,.dragging").forEach(x=>x.classList.remove("drag-over","dragging"));dragged=null});document.querySelectorAll(".tng-drop-zone").forEach(z=>{z.addEventListener("dragover",e=>{e.preventDefault();z.classList.add("drag-over");e.dataTransfer.dropEffect="move"});z.addEventListener("dragleave",e=>{if(!z.contains(e.relatedTarget))z.classList.remove("drag-over")});z.addEventListener("drop",async e=>{e.preventDefault();z.classList.remove("drag-over");if(!dragged)return;const date=z.dataset.dropDate||"";if((dragged.dataset.plannedDate||"")===date)return;const oldParent=dragged.parentElement;const oldDate=dragged.dataset.plannedDate||"";const target=zoneBody(z);target.querySelectorAll(".drop-placeholder").forEach(x=>x.remove());if(z.classList.contains("tng-cal-queue")){let wrap=document.createElement("div");wrap.className="queue-item";target.appendChild(wrap);wrap.appendChild(dragged)}else target.appendChild(dragged);dragged.dataset.plannedDate=date;updateCounts();const body=new URLSearchParams({action:"tng_calendar_move",nonce:window.TNGCalendar.nonce,item_id:dragged.dataset.itemId,planned_date:date});try{const r=await fetch(window.TNGCalendar.ajaxUrl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},credentials:"same-origin",body:body.toString()});const j=await r.json();if(!j.success)throw new Error(j.data&&j.data.message?j.data.message:"Could not move content.");show(date?"Scheduled for "+date:"Moved to Unscheduled");setTimeout(()=>location.reload(),450)}catch(err){show(err.message||"Could not save move.");dragged.dataset.plannedDate=oldDate;if(oldParent)oldParent.appendChild(dragged);updateCounts()}})});})();');
    }
}
TNG_Content_Calendar::boot();
