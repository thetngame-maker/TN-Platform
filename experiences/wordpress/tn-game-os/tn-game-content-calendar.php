<?php
/**
 * TN Game Content Calendar
 * Visual weekly planner for the Content Studio production pipeline.
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

    public static function schedule(): void {
        $id = self::guard();
        $date = sanitize_text_field(wp_unslash($_POST['planned_date'] ?? ''));
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) self::redirect('Choose a valid publish date.');
        update_post_meta($id, '_tng_planned_date', $date);
        $status = (string) get_post_meta($id, '_tng_plan_status', true);
        if (in_array($status, ['', 'inspiration', 'idea'], true)) update_post_meta($id, '_tng_plan_status', 'planned');
        self::redirect('Content placed on the calendar.');
    }

    public static function unschedule(): void {
        $id = self::guard();
        delete_post_meta($id, '_tng_planned_date');
        $status = (string) get_post_meta($id, '_tng_plan_status', true);
        if ($status === 'scheduled') update_post_meta($id, '_tng_plan_status', 'ready');
        elseif ($status === 'planned') update_post_meta($id, '_tng_plan_status', 'idea');
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
        <article class="tng-cal-card status-<?php echo esc_attr($status); ?>">
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

    public static function render(): void {
        if (!current_user_can('edit_posts')) return;
        $start = self::week_start();
        $week_key = $start->format('Y-m-d');
        $end = $start->modify('+6 days');
        $prev = $start->modify('-7 days')->format('Y-m-d');
        $next = $start->modify('+7 days')->format('Y-m-d');
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $items = self::all_items();
        $by_date = []; $unscheduled = [];
        $counts = ['idea'=>0,'creating'=>0,'ready'=>0,'scheduled'=>0,'published'=>0];
        foreach ($items as $item) {
            $id = (int) $item->ID;
            $status = self::meta($id, '_tng_plan_status', 'idea');
            if (isset($counts[$status])) $counts[$status]++;
            $date = self::meta($id, '_tng_planned_date');
            if ($date) $by_date[$date][] = $item; else if (!in_array($status, ['published','archived','inspiration'], true)) $unscheduled[] = $item;
        }
        ?>
        <div class="wrap tng-cal">
            <section class="tng-cal-hero">
                <div><p class="eyebrow">CONTENT STUDIO</p><h1>Plan the week. Know what needs to get made.</h1><p>Move ideas through production and give every Reel, carousel, and post a place on the calendar.</p></div>
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
            <div class="tng-cal-layout">
                <aside class="tng-cal-queue">
                    <div class="section-head"><div><p class="eyebrow">PRODUCTION QUEUE</p><h2>Unscheduled</h2></div><span><?php echo count($unscheduled); ?></span></div>
                    <p class="description">Give a post a date when you know where it belongs. Its production status stays intact.</p>
                    <?php if (!$unscheduled): ?><div class="empty">Nothing waiting. Your queue is clear.</div><?php endif; ?>
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
                </aside>
                <main class="tng-cal-week">
                    <?php for ($i=0;$i<7;$i++): $day=$start->modify('+' . $i . ' days'); $key=$day->format('Y-m-d'); $day_items=$by_date[$key]??[]; ?>
                        <section class="tng-cal-day <?php echo $key===$today?'today':''; ?>">
                            <header><div><span><?php echo esc_html($day->format('D')); ?></span><strong><?php echo esc_html($day->format('j')); ?></strong></div><small><?php echo count($day_items); ?> post<?php echo count($day_items)===1?'':'s'; ?></small></header>
                            <div class="day-body">
                                <?php if (!$day_items): ?><div class="day-empty">Open</div><?php endif; ?>
                                <?php foreach ($day_items as $item) echo self::item_card($item, $week_key); ?>
                            </div>
                        </section>
                    <?php endfor; ?>
                </main>
            </div>
        </div>
        <?php
    }

    public static function assets(): void {
        if (!isset($_GET['page']) || $_GET['page'] !== 'tng-content-calendar') return;
        wp_register_style('tng-content-calendar', false, [], defined('TNG_OS_VERSION') ? TNG_OS_VERSION : null); wp_enqueue_style('tng-content-calendar');
        wp_add_inline_style('tng-content-calendar', '.tng-cal{max-width:1500px}.tng-cal-hero{margin:20px 0;background:linear-gradient(135deg,#073c2b,#176b45);color:#fff;border-radius:24px;padding:32px;display:flex;justify-content:space-between;gap:24px}.tng-cal-hero h1{color:#fff;font-size:34px;margin:6px 0 10px}.tng-cal-hero p{max-width:760px;font-size:15px}.hero-actions{display:flex;gap:8px;align-items:flex-start}.eyebrow{font-size:11px;font-weight:800;letter-spacing:.13em;color:#f05b25;margin:0}.tng-cal-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin:18px 0}.tng-cal-stats>div{background:#fff;border:1px solid #dfe5df;border-radius:16px;padding:18px}.tng-cal-stats strong{font-size:28px;color:#123c2b;display:block}.tng-cal-stats span{color:#69766e}.tng-cal-toolbar{background:#fff;border:1px solid #dfe5df;border-radius:18px;padding:18px 20px;margin:16px 0;display:flex;align-items:center;justify-content:space-between}.tng-cal-toolbar h2{margin:2px 0 0;color:#123c2b}.tng-cal-layout{display:grid;grid-template-columns:330px minmax(0,1fr);gap:18px;align-items:start}.tng-cal-queue{background:#fff;border:1px solid #dfe5df;border-radius:20px;padding:18px;position:sticky;top:42px}.section-head{display:flex;justify-content:space-between;align-items:center}.section-head h2{margin:3px 0}.section-head>span{background:#edf5ef;color:#175c3c;border-radius:999px;padding:6px 10px;font-weight:700}.queue-item{padding:14px 0;border-top:1px solid #eef1ee}.queue-item:first-of-type{border-top:0}.schedule-form{display:flex;gap:6px;margin-top:8px}.schedule-form input{min-width:0;width:100%}.tng-cal-week{display:grid;grid-template-columns:repeat(7,minmax(150px,1fr));gap:8px;overflow-x:auto;padding-bottom:10px}.tng-cal-day{background:#f8faf8;border:1px solid #dfe5df;border-radius:18px;min-height:520px;overflow:hidden}.tng-cal-day.today{border:2px solid #f05b25}.tng-cal-day>header{background:#fff;border-bottom:1px solid #e3e8e3;padding:13px;display:flex;justify-content:space-between;align-items:center}.tng-cal-day header span{display:block;text-transform:uppercase;font-size:10px;font-weight:800;color:#728077}.tng-cal-day header strong{font-size:26px;color:#123c2b}.tng-cal-day header small{color:#7a867e}.day-body{padding:8px}.day-empty{height:90px;border:1px dashed #cfd8d1;border-radius:12px;color:#9aa49d;display:flex;align-items:center;justify-content:center}.tng-cal-card{background:#fff;border:1px solid #dde4de;border-radius:13px;padding:11px;margin-bottom:8px;box-shadow:0 2px 7px rgba(18,60,43,.035);border-left:4px solid #9da9a1}.tng-cal-card.status-creating{border-left-color:#f0a52b}.tng-cal-card.status-ready{border-left-color:#3f8f63}.tng-cal-card.status-scheduled{border-left-color:#3157d5}.tng-cal-card.status-published{border-left-color:#176b45}.tng-cal-card.status-idea{border-left-color:#f05b25}.tng-cal-card-top{display:flex;justify-content:space-between;gap:6px;font-size:10px;text-transform:uppercase;font-weight:800}.tng-cal-card-top .format{color:#f05b25}.tng-cal-card-top .status{color:#617068}.tng-cal-card h4{font-size:14px;margin:7px 0;color:#19392c;line-height:1.25}.tng-cal-card .hook{font-size:11px;color:#647168;margin:5px 0}.tng-cal-card .meta{font-size:10px;color:#738078;display:flex;flex-direction:column;gap:2px}.tng-cal-card .actions{display:flex;gap:8px;margin-top:8px;align-items:center}.tng-cal-card .actions a,.tng-cal-card .actions .link{font-size:10px;font-weight:700;color:#d94a18;text-decoration:none;border:0;background:none;padding:0;cursor:pointer}.tng-cal-card .actions form{margin:0}.empty{padding:18px;border:1px dashed #ccd6ce;border-radius:12px;color:#7a867e;background:#fafcfa}@media(max-width:1180px){.tng-cal-layout{grid-template-columns:1fr}.tng-cal-queue{position:static}.tng-cal-week{grid-template-columns:repeat(7,220px)}}@media(max-width:782px){.tng-cal-hero{flex-direction:column}.tng-cal-stats{grid-template-columns:repeat(2,1fr)}.tng-cal-toolbar{gap:8px;flex-wrap:wrap}.tng-cal-layout{display:block}.tng-cal-week{margin-top:16px}}');
    }
}
TNG_Content_Calendar::boot();
