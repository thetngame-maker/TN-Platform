<?php
namespace TNG_OS\Modules\Destinations;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use TNG_OS\Platform\Universal_Map_Registry;

if (!defined('ABSPATH')) exit;

final class Adventure_AI implements Module_Interface {
    private const NONCE = 'tng_adventure_ai';
    private const LAST_PLAN_META = 'tng_last_adventure_ai_plan';
    private const PLAN_LIBRARY_META = 'tng_adventure_ai_plan_library';
    private const PLAN_LIBRARY_LIMIT = 12;

    public function id(): string { return 'adventure_ai'; }

    public function register(Container $container): void {
        $container->set('adventure_ai', $this);
        add_shortcode('tng_adventure_ai', [self::class, 'render_screen']);
        add_action('wp_ajax_tng_generate_adventure_ai', [$this, 'ajax_generate']);
        add_action('wp_ajax_nopriv_tng_generate_adventure_ai', [$this, 'ajax_generate']);
        add_action('wp_ajax_tng_save_adventure_ai', [$this, 'ajax_save']);
        add_action('wp_ajax_tng_adventure_library_action', [$this, 'ajax_library_action']);
    }

    public function boot(Container $container): void {}

    public static function render_screen(): string {
        $initial_plan = self::requested_plan();
        $examples = [
            'Plan a relaxed 5-hour waterfall and lunch adventure near Tracy City.',
            'Build a family-friendly day with easy walks, scenic views, and food.',
            'I have 3 hours this afternoon and want history, coffee, and an indoor stop.',
        ];
        ob_start(); ?>
        <main class="tng-adventure-ai-screen tng-native-screen tng-app-shell" data-tng-adventure-ai data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE)); ?>" data-logged-in="<?php echo is_user_logged_in() ? '1' : '0'; ?>" data-login-url="<?php echo esc_url(wp_login_url(home_url('/adventure-ai/'))); ?>">
            <?php if ($initial_plan): ?><script type="application/json" data-tng-ai-initial><?php echo wp_json_encode($initial_plan, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?></script><?php endif; ?>
            <section class="tng-ai-hero">
                <div class="tng-ai-hero__copy">
                    <span class="tng-eyebrow">Adventure AI · v2</span>
                    <h1>Describe your Tennessee day.</h1>
                    <p>Tell TN Game where you want to go, how much time you have, and what sounds fun. Adventure AI turns it into a real itinerary using published TN Game places.</p>
                </div>
                <div class="tng-ai-orbit" aria-hidden="true"><span>TN</span><i></i><i></i><i></i></div>
            </section>
            <section class="tng-ai-composer" aria-labelledby="tng-ai-prompt-title">
                <form data-tng-ai-form>
                    <label id="tng-ai-prompt-title" for="tng-ai-prompt">What kind of adventure do you want?</label>
                    <textarea id="tng-ai-prompt" name="prompt" rows="4" maxlength="600" placeholder="Example: Plan a relaxed 5-hour waterfall and lunch adventure near Tracy City." required></textarea>
                    <div class="tng-ai-examples" aria-label="Example prompts">
                        <?php foreach ($examples as $example): ?><button type="button" data-tng-ai-example="<?php echo esc_attr($example); ?>"><?php echo esc_html($example); ?></button><?php endforeach; ?>
                    </div>
                    <div class="tng-ai-submit-row">
                        <button class="tng-ui-button tng-ai-generate" type="submit"><span aria-hidden="true">✦</span> Build my adventure</button>
                        <span class="tng-ai-status" data-tng-ai-status aria-live="polite">Uses live TN Game content—no setup required.</span>
                    </div>
                </form>
            </section>
            <section class="tng-ai-results" data-tng-ai-results hidden>
                <header class="tng-ai-results__header">
                    <div><span class="tng-eyebrow">Your generated itinerary</span><h2 data-tng-ai-title>Your Tennessee adventure</h2><p data-tng-ai-summary></p></div>
                    <div class="tng-ai-tags" data-tng-ai-tags></div>
                </header>
                <div class="tng-ai-workspace">
                    <section class="tng-ai-route-card" aria-labelledby="tng-ai-route-title">
                        <div class="tng-ai-route-card__header"><div><span class="tng-eyebrow">Route preview</span><h3 id="tng-ai-route-title">Your Tennessee path</h3></div><span data-tng-ai-route-count></span></div>
                        <div class="tng-ai-route-canvas" data-tng-ai-route-canvas>
                            <svg data-tng-ai-route-svg viewBox="0 0 520 220" role="img" aria-label="A preview of the itinerary route"></svg>
                            <p data-tng-ai-route-empty hidden>Map coordinates are not available for these stops yet. Your editable itinerary is still ready below.</p>
                        </div>
                    </section>
                    <section class="tng-ai-timing" aria-labelledby="tng-ai-timing-title">
                        <div><span class="tng-eyebrow">Timing controls</span><h3 id="tng-ai-timing-title">Shape the day</h3></div>
                        <label for="tng-ai-start-time">Start time<input id="tng-ai-start-time" type="time" value="10:00" data-tng-ai-start></label>
                        <label for="tng-ai-buffer">Travel buffer<select id="tng-ai-buffer" data-tng-ai-buffer><option value="10">10 minutes</option><option value="20" selected>20 minutes</option><option value="30">30 minutes</option><option value="45">45 minutes</option><option value="60">60 minutes</option></select></label>
                        <div class="tng-ai-timing__stats"><span><strong data-tng-ai-stop-count>0</strong> stops</span><span><strong data-tng-ai-total-time>0 hr</strong> planned</span></div>
                        <button class="tng-ui-button tng-ui-button--secondary" type="button" data-tng-ai-reset>Reset original plan</button>
                    </section>
                </div>
                <div class="tng-ai-timeline" data-tng-ai-stops></div>
                <button class="tng-ai-undo" type="button" data-tng-ai-undo hidden>Undo removed stop</button>
                <div class="tng-ai-result-actions">
                    <button class="tng-ui-button" type="button" data-tng-ai-save>＋ Save adventure</button>
                    <button class="tng-ui-button tng-ui-button--secondary" type="button" data-tng-ai-share>Share itinerary</button>
                    <a class="tng-ai-trips-link" href="<?php echo esc_url(home_url('/adventures/')); ?>">Saved Adventures</a>
                    <a class="tng-ai-trips-link" href="<?php echo esc_url(home_url('/trips/')); ?>">Open Trips →</a>
                </div>
            </section>
            <section class="tng-ai-trust">
                <article><span>⌖</span><div><strong>Built from Tennessee</strong><p>Only published TN Game places become itinerary stops.</p></div></article>
                <article><span>◇</span><div><strong>Edit before saving</strong><p>Reorder or remove stops, then save the exact plan you want to Trips.</p></div></article>
                <article><span>↻</span><div><strong>Timing that adapts</strong><p>Change your start or travel buffer and every arrival time recalculates.</p></div></article>
            </section>
        </main>
        <?php return (string)ob_get_clean();
    }

    public static function render_library(): string {
        $logged_in = is_user_logged_in();
        $plans = $logged_in ? self::library(get_current_user_id()) : [];
        $current_trip_count = $logged_in && class_exists('TNG_Trip_Data') ? count(\TNG_Trip_Data::ids(get_current_user_id())) : 0;
        ob_start(); ?>
        <main class="tng-adventure-library tng-native-screen tng-app-shell" data-tng-adventure-library data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce(self::NONCE)); ?>" data-current-trip-count="<?php echo esc_attr((string)$current_trip_count); ?>">
            <section class="tng-adventure-library__hero"><div><span class="tng-eyebrow">Saved Adventures</span><h1>Your Tennessee plans.</h1><p>Reopen an Adventure AI itinerary, adjust the timing, or make a copy for a different day.</p></div><a class="tng-ui-button" href="<?php echo esc_url(home_url('/adventure-ai/')); ?>">＋ Build another</a></section>
            <?php if (!$logged_in): ?>
                <section class="tng-adventure-library__empty"><span>◇</span><h2>Sign in to keep your plans together.</h2><p>Saved Adventures are private to your Explorer account.</p><a class="tng-ui-button" href="<?php echo esc_url(wp_login_url(home_url('/adventures/'))); ?>">Sign in</a></section>
            <?php elseif (!$plans): ?>
                <section class="tng-adventure-library__empty"><span>✦</span><h2>Your first plan starts with a sentence.</h2><p>Describe a Tennessee day in Adventure AI, edit it, and press Save Adventure.</p><a class="tng-ui-button" href="<?php echo esc_url(home_url('/adventure-ai/')); ?>">Open Adventure AI</a></section>
            <?php else: ?>
                <p class="tng-adventure-library__status" data-tng-library-status aria-live="polite"><?php echo esc_html(count($plans).' saved adventure'.(count($plans)===1?'':'s')); ?></p>
                <section class="tng-adventure-library__grid">
                    <?php foreach ($plans as $plan): $ids=array_slice((array)$plan['ids'],0,4); ?>
                        <article class="tng-adventure-card" data-plan-id="<?php echo esc_attr((string)$plan['id']); ?>">
                            <div class="tng-adventure-card__top"><span><?php echo esc_html(number_format_i18n(count($plan['ids'])).' stops'); ?></span><time datetime="<?php echo esc_attr(gmdate('c',(int)$plan['updated_at'])); ?>"><?php echo esc_html(human_time_diff((int)$plan['updated_at'],time()).' ago'); ?></time></div>
                            <h2 data-plan-title><?php echo esc_html((string)$plan['title']); ?></h2>
                            <p><?php echo esc_html(wp_trim_words((string)$plan['prompt'],18)); ?></p>
                            <div class="tng-adventure-card__stops"><?php foreach($ids as $id): ?><span><?php echo esc_html(get_the_title((int)$id) ?: '#'.(int)$id); ?></span><?php endforeach; ?></div>
                            <div class="tng-adventure-card__actions"><button class="tng-ui-button" type="button" data-tng-plan-start>Start adventure</button><a class="tng-ui-button tng-ui-button--secondary" href="<?php echo esc_url(add_query_arg('plan',(string)$plan['id'],home_url('/adventure-ai/'))); ?>">Reopen</a><a class="tng-ui-button tng-ui-button--secondary" href="<?php echo esc_url(add_query_arg('adventure',(string)$plan['id'],home_url('/map/'))); ?>">View map</a><button class="tng-ui-button tng-ui-button--secondary" type="button" data-tng-plan-duplicate>Duplicate</button></div>
                            <form class="tng-adventure-card__rename" data-tng-plan-rename><label>Rename plan<input name="title" maxlength="100" value="<?php echo esc_attr((string)$plan['title']); ?>"></label><button type="submit">Save name</button></form>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
        </main>
        <?php return (string)ob_get_clean();
    }

    public function ajax_generate(): void {
        check_ajax_referer(self::NONCE, 'nonce');
        $prompt = sanitize_textarea_field(wp_unslash((string)($_POST['prompt'] ?? '')));
        if (strlen($prompt) < 8) wp_send_json_error(['message'=>'Tell us a little more about the adventure you want.'], 400);
        if (strlen($prompt) > 600) $prompt = substr($prompt, 0, 600);

        $intent = $this->interpret($prompt);
        $source_id = (int)$intent['source_id'];
        if (!$source_id) $source_id = $this->best_source($intent['prefs']);
        if (!$source_id) wp_send_json_error(['message'=>'TN Game needs at least one published place before it can build an itinerary.'], 404);

        $stops = $this->build($source_id, (int)$intent['duration_minutes'], (int)$intent['start_minutes'], $intent['prefs']);
        if (!$stops) wp_send_json_error(['message'=>'No strong itinerary matches were found. Try a different location or interest.'], 404);

        $visit_minutes = array_sum(array_map(static fn(array $stop): int => (int)$stop['minutes'], $stops));
        $drive_buffer = max(0, count($stops) - 1) * 20;
        $source_title = get_the_title($source_id) ?: 'Tennessee';
        wp_send_json_success([
            'title' => 'Adventure around '.$source_title,
            'summary' => $intent['summary'],
            'tags' => $intent['tags'],
            'source_id' => $source_id,
            'source_title' => $source_title,
            'stops' => $stops,
            'start_minutes' => (int)$intent['start_minutes'],
            'buffer_minutes' => 20,
            'total_minutes' => $visit_minutes + $drive_buffer,
            'planning_note' => 'Times include a 20-minute planning buffer between stops. Confirm hours, tickets, trail conditions, and driving time before leaving.',
        ]);
    }

    public function ajax_save(): void {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!is_user_logged_in()) wp_send_json_error(['code'=>'login_required','message'=>'Sign in to save this adventure to Trips.'], 401);
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_slice(array_map('absint', wp_unslash($_POST['ids'])), 0, 12) : [];
        $ids = array_values(array_filter(array_unique($ids), static fn(int $id): bool => $id > 0 && get_post_status($id) === 'publish'));
        if (!$ids) wp_send_json_error(['message'=>'This itinerary does not contain any saveable stops.'], 400);
        if (!class_exists('TNG_Trip_Data')) wp_send_json_error(['message'=>'Trips is temporarily unavailable.'], 503);

        $saved = \TNG_Trip_Data::merge($ids, get_current_user_id());
        $prompt = sanitize_textarea_field(wp_unslash((string)($_POST['prompt'] ?? '')));
        $title = sanitize_text_field(wp_unslash((string)($_POST['title'] ?? 'Tennessee adventure')));
        $plan_id = sanitize_text_field(wp_unslash((string)($_POST['plan_id'] ?? '')));
        $start_minutes = min(1439, max(0, absint($_POST['start_minutes'] ?? 600)));
        $buffer_minutes = absint($_POST['buffer_minutes'] ?? 20);
        if (!in_array($buffer_minutes, [10,20,30,45,60], true)) $buffer_minutes = 20;
        $record = [
            'title' => substr($title, 0, 100),
            'prompt' => substr($prompt, 0, 600),
            'ids' => $ids,
            'start_minutes' => $start_minutes,
            'buffer_minutes' => $buffer_minutes,
            'plan_version' => 2,
            'created_at' => time(),
        ];
        $library = self::library(get_current_user_id());
        $existing_index = self::plan_index($library, $plan_id);
        if ($existing_index >= 0) {
            $record['id'] = (string)$library[$existing_index]['id'];
            $record['created_at'] = (int)$library[$existing_index]['created_at'];
            array_splice($library, $existing_index, 1);
        } else $record['id'] = wp_generate_uuid4();
        $record['updated_at'] = time();
        array_unshift($library, $record);
        $library = array_slice($library, 0, self::PLAN_LIBRARY_LIMIT);
        update_user_meta(get_current_user_id(), self::PLAN_LIBRARY_META, $library);
        update_user_meta(get_current_user_id(), self::LAST_PLAN_META, $record);
        wp_send_json_success(['count'=>$saved['count'],'added'=>$saved['added'],'plan_id'=>$record['id'],'library_url'=>home_url('/adventures/'),'url'=>home_url('/trips/')]);
    }

    public function ajax_library_action(): void {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!is_user_logged_in()) wp_send_json_error(['message'=>'Sign in to manage Saved Adventures.'], 401);
        $operation = sanitize_key((string)($_POST['operation'] ?? ''));
        $plan_id = sanitize_text_field(wp_unslash((string)($_POST['plan_id'] ?? '')));
        $library = self::library(get_current_user_id());
        $index = self::plan_index($library, $plan_id);
        if ($index < 0) wp_send_json_error(['message'=>'That saved adventure could not be found.'], 404);
        if ($operation === 'start') {
            if (!class_exists('TNG_Trip_Data')) wp_send_json_error(['message'=>'Trips is temporarily unavailable.'], 503);
            $ids = array_values(array_filter(array_unique(array_map('absint',(array)$library[$index]['ids'])), static fn(int $id): bool => $id > 0 && get_post_status($id) === 'publish'));
            if (!$ids) wp_send_json_error(['message'=>'This adventure no longer has any published stops.'], 400);
            $existing = \TNG_Trip_Data::ids(get_current_user_id());
            $confirmed = absint($_POST['confirm_replace'] ?? 0) === 1;
            if ($existing && !$confirmed) wp_send_json_error(['code'=>'confirm_required','message'=>'Confirm replacing your current trip before starting this adventure.','current_count'=>count($existing)], 409);
            $started = \TNG_Trip_Data::replace($ids, get_current_user_id(), ['kind'=>'saved_adventure','id'=>$plan_id,'title'=>(string)$library[$index]['title']]);
            wp_send_json_success(['message'=>'Adventure loaded into Trips.','url'=>home_url('/trip-builder/'),'count'=>$started['count'],'replaced'=>$started['previousCount'],'progressReset'=>$started['progressReset']]);
        } elseif ($operation === 'rename') {
            $title = sanitize_text_field(wp_unslash((string)($_POST['title'] ?? '')));
            if ($title === '') wp_send_json_error(['message'=>'Give this adventure a name.'], 400);
            $library[$index]['title'] = substr($title, 0, 100);
            $library[$index]['updated_at'] = time();
        } elseif ($operation === 'duplicate') {
            $copy = $library[$index];
            $copy['id'] = wp_generate_uuid4();
            $copy['title'] = substr('Copy of '.(string)$copy['title'], 0, 100);
            $copy['created_at'] = $copy['updated_at'] = time();
            array_unshift($library, $copy);
            $library = array_slice($library, 0, self::PLAN_LIBRARY_LIMIT);
        } else wp_send_json_error(['message'=>'That plan action is not supported.'], 400);
        update_user_meta(get_current_user_id(), self::PLAN_LIBRARY_META, array_values($library));
        wp_send_json_success(['message'=>$operation==='rename'?'Adventure renamed.':'Adventure duplicated.','url'=>home_url('/adventures/')]);
    }

    private static function library(int $user_id): array {
        $plans = get_user_meta($user_id, self::PLAN_LIBRARY_META, true);
        if (!is_array($plans) || !$plans) {
            $legacy = get_user_meta($user_id, self::LAST_PLAN_META, true);
            if (is_array($legacy) && !empty($legacy['ids'])) {
                $created = absint($legacy['created_at'] ?? time());
                $legacy['id'] = 'legacy-'.substr(hash('sha256',$user_id.'|'.$created.'|'.implode(',',array_map('absint',(array)$legacy['ids']))),0,24);
                $legacy['created_at'] = $created;
                $legacy['updated_at'] = absint($legacy['updated_at'] ?? $created);
                $plans = [$legacy];
            } else return [];
        }
        return array_values(array_filter($plans, static fn($plan): bool => is_array($plan) && !empty($plan['id']) && !empty($plan['ids'])));
    }

    private static function plan_index(array $plans, string $plan_id): int {
        if ($plan_id === '') return -1;
        foreach ($plans as $index=>$plan) if (hash_equals((string)($plan['id']??''), $plan_id)) return (int)$index;
        return -1;
    }

    private static function requested_plan(): array {
        if (!is_user_logged_in()) return [];
        $plan_id = sanitize_text_field(wp_unslash((string)($_GET['plan'] ?? '')));
        $plans = self::library(get_current_user_id());
        $index = self::plan_index($plans, $plan_id);
        if ($index < 0) return [];
        $saved = $plans[$index];
        $instance = new self();
        $rows = [];
        foreach (array_slice(array_map('absint',(array)$saved['ids']),0,12) as $id) {
            if (!$id || get_post_status($id) !== 'publish') continue;
            $rows[] = $instance->stop($id, 'Saved stop', 'Restored from your Saved Adventures library', $instance->visit_minutes($id));
        }
        if (!$rows) return [];
        $coordinates = $instance->mapped_coordinates(array_column($rows,'id'));
        $start = min(1439,max(0,absint($saved['start_minutes']??600)));
        $clock = $start;
        $buffer = absint($saved['buffer_minutes']??20);
        if (!in_array($buffer,[10,20,30,45,60],true)) $buffer=20;
        foreach($rows as &$row){$row['time']=$instance->clock($clock);$clock+=(int)$row['minutes']+$buffer;$id=(int)$row['id'];if(isset($coordinates[$id]))$row=array_merge($row,$coordinates[$id]);}unset($row);
        $total=array_sum(array_column($rows,'minutes'))+max(0,count($rows)-1)*$buffer;
        return ['id'=>(string)$saved['id'],'title'=>(string)$saved['title'],'prompt'=>(string)$saved['prompt'],'summary'=>'Reopened from your private Saved Adventures library.','tags'=>['Saved adventure',count($rows).' stops'],'stops'=>$rows,'start_minutes'=>$start,'buffer_minutes'=>$buffer,'total_minutes'=>$total];
    }

    public static function map_overlay(string $plan_id): array {
        if (!is_user_logged_in()) return [];
        $plans = self::library(get_current_user_id());
        $index = self::plan_index($plans, sanitize_text_field($plan_id));
        if ($index < 0) return [];
        $plan = $plans[$index];
        $dataset = class_exists(Universal_Map_Registry::class) ? Universal_Map_Registry::dataset() : [];
        $mapped = [];
        foreach ((array)($dataset['items'] ?? []) as $item) $mapped[(int)($item['id']??0)] = $item;
        $stops = [];
        foreach (array_slice(array_map('absint',(array)$plan['ids']),0,12) as $id) {
            if (get_post_status($id) !== 'publish' || empty($mapped[$id])) continue;
            $item = $mapped[$id];
            if (!is_numeric($item['lat']??null) || !is_numeric($item['lng']??null)) continue;
            $stops[] = ['id'=>$id,'title'=>(string)$item['title'],'lat'=>(float)$item['lat'],'lng'=>(float)$item['lng'],'url'=>(string)($item['url']??get_permalink($id))];
        }
        return $stops ? ['id'=>(string)$plan['id'],'title'=>(string)$plan['title'],'stops'=>$stops] : [];
    }

    private function interpret(string $prompt): array {
        $text = strtolower(remove_accents($prompt));
        $family = (bool)preg_match('/\b(family|families|kid|kids|child|children|toddler|teen)\b/', $text);
        $accessible = (bool)preg_match('/\b(accessible|accessibility|wheelchair|mobility|limited walking|easy walk)\b/', $text);
        $rain = (bool)preg_match('/\b(rain|rainy|storm|indoor|bad weather)\b/', $text);
        $food = !preg_match('/\b(no food|without food|skip (lunch|dinner|food))\b/', $text);
        $interest = 'smart';
        if (preg_match('/\b(history|historic|heritage|museum|civil war)\b/', $text)) $interest = 'history';
        elseif (preg_match('/\b(photo|photos|photography|scenic|view|sunset|waterfall|waterfalls)\b/', $text)) $interest = 'photography';
        elseif (preg_match('/\b(hike|hiking|trail|adventure|outdoor|cavern|climb|kayak)\b/', $text)) $interest = 'adventure';
        elseif ($rain) $interest = 'rainy_day';
        elseif ($family) $interest = 'family';
        elseif (preg_match('/\b(food|restaurant|lunch|dinner|coffee|brewery|eat)\b/', $text)) $interest = 'food_after';

        $budget = 'any';
        if (preg_match('/\b(free|no[- ]cost)\b/', $text)) $budget = 'free';
        elseif (preg_match('/\b(cheap|budget|inexpensive|under\s*\$?\s*(?:[1-7]?\d))\b/', $text)) $budget = 'low';
        elseif (preg_match('/\bunder\s*\$?\s*(?:8\d|9\d|1\d\d)\b/', $text)) $budget = 'medium';

        $pace = 'balanced';
        if (preg_match('/\b(relaxed|relaxing|easy|slow|leisure|laid[- ]back)\b/', $text)) $pace = 'relaxed';
        elseif (preg_match('/\b(packed|busy|as much as possible|full schedule|nonstop)\b/', $text)) $pace = 'packed';

        $duration = 5 * 60;
        if (preg_match('/\b(\d+(?:\.\d+)?)\s*(?:hour|hours|hr|hrs)\b/', $text, $match)) $duration = (int)round((float)$match[1] * 60);
        elseif (preg_match('/\b(full day|all day|day trip)\b/', $text)) $duration = 8 * 60;
        elseif (preg_match('/\b(half day|half-day)\b/', $text)) $duration = 4 * 60;
        elseif (preg_match('/\b(quick|couple of hours)\b/', $text)) $duration = 3 * 60;
        $duration = min(10 * 60, max(2 * 60, $duration));

        $start = 10 * 60;
        if (preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/', $text, $match)) {
            $hour = min(12, max(1, (int)$match[1]));
            $minute = isset($match[2]) ? min(59, (int)$match[2]) : 0;
            if ($match[3] === 'pm' && $hour < 12) $hour += 12;
            if ($match[3] === 'am' && $hour === 12) $hour = 0;
            $start = $hour * 60 + $minute;
        } elseif (preg_match('/\b(afternoon)\b/', $text)) $start = 13 * 60;
        elseif (preg_match('/\b(evening)\b/', $text)) $start = 16 * 60;
        elseif (preg_match('/\b(early morning)\b/', $text)) $start = 8 * 60;

        $prefs = compact('family','accessible','rain','food','interest','budget','pace');
        $source_id = $this->match_source($text);
        $tags = [$this->duration_label($duration), ucfirst(str_replace('_',' ',$interest)), ucfirst($pace).' pace'];
        if ($family) $tags[] = 'Family-friendly';
        if ($accessible) $tags[] = 'Accessibility-aware';
        if ($rain) $tags[] = 'Rain-ready';
        if ($budget !== 'any') $tags[] = $budget === 'free' ? 'Mostly free' : 'Budget-aware';
        $summary = 'Built from your request for '.strtolower(implode(' · ', array_slice($tags, 0, 4))).'.';
        if (!$source_id) $summary .= ' Using the strongest available TN Game starting point.';
        return [
            'source_id'=>$source_id,
            'duration_minutes'=>$duration,
            'start_minutes'=>$start,
            'prefs'=>$prefs,
            'tags'=>$tags,
            'summary'=>$summary,
        ];
    }

    private function build(int $source_id, int $capacity, int $start, array $prefs): array {
        $target = $prefs['pace'] === 'relaxed' ? 4 : ($prefs['pace'] === 'packed' ? 7 : 5);
        $scenarios = [];
        if ($prefs['rain']) $scenarios[] = 'rainy_day';
        if ($prefs['family']) $scenarios[] = 'family';
        if (in_array($prefs['interest'], ['adventure','photography','rainy_day','family','food_after'], true)) $scenarios[] = $prefs['interest'];
        else $scenarios[] = 'similar';
        if ($prefs['food']) $scenarios[] = 'food_after';
        $scenarios = array_values(array_unique(array_merge($scenarios, ['similar','photography','adventure'])));

        $rows = [];
        $seen = [];
        $anchor_minutes = $this->visit_minutes($source_id);
        $rows[] = $this->stop($source_id, 'Starting point', 'Best match for your request', $anchor_minutes);
        $seen[$source_id] = true;
        $used = $anchor_minutes;
        foreach ($scenarios as $scenario) {
            foreach (Smart_Recommendation_Engine::recommend($source_id, $scenario, 18) as $recommendation) {
                $id = (int)($recommendation['id'] ?? 0);
                if (!$id || isset($seen[$id]) || get_post_status($id) !== 'publish' || !$this->qualified($id, $prefs)) continue;
                $minutes = $this->visit_minutes($id);
                if ($used + 20 + $minutes > $capacity) continue;
                $rows[] = $this->stop($id, $this->scenario_label($scenario), (string)($recommendation['reason'] ?? 'Recommended by TN Game'), $minutes);
                $seen[$id] = true;
                $used += 20 + $minutes;
                if (count($rows) >= $target) break 2;
                break;
            }
        }

        if (count($rows) < min(3, $target)) {
            foreach ($this->node_ids() as $id) {
                if (isset($seen[$id]) || get_post_status($id) !== 'publish' || !$this->qualified($id, $prefs)) continue;
                $minutes = $this->visit_minutes($id);
                if ($used + 20 + $minutes > $capacity) continue;
                $rows[] = $this->stop($id, 'Tennessee pick', 'A published TN Game place that fits the available time', $minutes);
                $seen[$id] = true;
                $used += 20 + $minutes;
                if (count($rows) >= min(3, $target)) break;
            }
        }

        $clock = $start;
        foreach ($rows as &$row) {
            $row['time'] = $this->clock($clock);
            $clock += (int)$row['minutes'] + 20;
        }
        unset($row);
        $coordinates = $this->mapped_coordinates(array_column($rows, 'id'));
        foreach ($rows as &$row) {
            $id = (int)$row['id'];
            if (isset($coordinates[$id])) {
                $row['lat'] = $coordinates[$id]['lat'];
                $row['lng'] = $coordinates[$id]['lng'];
            }
        }
        unset($row);
        return $rows;
    }

    private function mapped_coordinates(array $ids): array {
        if (!class_exists(Universal_Map_Registry::class)) return [];
        $wanted = array_fill_keys(array_map('absint', $ids), true);
        $coordinates = [];
        $dataset = Universal_Map_Registry::dataset();
        foreach ((array)($dataset['items'] ?? []) as $item) {
            $id = absint($item['id'] ?? 0);
            if (!$id || !isset($wanted[$id]) || !is_numeric($item['lat'] ?? null) || !is_numeric($item['lng'] ?? null)) continue;
            $coordinates[$id] = ['lat'=>(float)$item['lat'], 'lng'=>(float)$item['lng']];
        }
        return $coordinates;
    }

    private function match_source(string $prompt): int {
        $prompt_tokens = $this->tokens($prompt);
        $best_id = 0;
        $best_score = 0;
        foreach ($this->node_ids() as $id) {
            $title = strtolower(remove_accents((string)get_the_title($id)));
            $searchable = $title;
            foreach (['address','location','st_location','_tng_destination_region','tng_destination_region'] as $key) {
                $value = get_post_meta($id, $key, true);
                if (is_scalar($value)) $searchable .= ' '.strtolower(remove_accents((string)$value));
            }
            $score = strlen($title) > 3 && str_contains($prompt, $title) ? 100 : 0;
            $haystack_tokens = $this->tokens($searchable);
            foreach ($prompt_tokens as $token) if (in_array($token, $haystack_tokens, true)) $score += str_contains($title, $token) ? 5 : 2;
            if ($score > $best_score) { $best_score = $score; $best_id = $id; }
        }
        return $best_score >= 4 ? $best_id : 0;
    }

    private function best_source(array $prefs): int {
        $best = 0;
        $best_score = -1;
        foreach ($this->node_ids() as $id) {
            if (!$this->qualified($id, $prefs)) continue;
            $profile = class_exists(Destination_AI_Profiles::class) ? Destination_AI_Profiles::profile($id) : [];
            $score = (int)($profile['confidence'] ?? 0);
            if ($prefs['family']) $score += (int)($profile['family'] ?? 0) * 4;
            if ($prefs['accessible']) $score += (int)($profile['accessibility'] ?? 0) * 4;
            if ($prefs['rain']) $score += (int)($profile['rainy_day'] ?? 0) * 4;
            if ($prefs['interest'] === 'adventure') $score += (int)($profile['adventure'] ?? 0) * 3;
            if ($prefs['interest'] === 'photography') $score += (int)($profile['photography'] ?? 0) * 3;
            if ($score > $best_score) { $best_score = $score; $best = $id; }
        }
        return $best;
    }

    private function qualified(int $id, array $prefs): bool {
        $profile = class_exists(Destination_AI_Profiles::class) ? Destination_AI_Profiles::profile($id) : [];
        foreach ([['family','family'],['accessible','accessibility'],['rain','rainy_day']] as [$preference, $field]) {
            if (!$prefs[$preference] || !array_key_exists($field, $profile)) continue;
            if ((int)$profile[$field] < 3) return false;
        }
        $cost = strtolower((string)($profile['cost'] ?? ''));
        if ($prefs['budget'] === 'free' && $cost !== '' && !preg_match('/free|\$0|no cost/', $cost)) return false;
        if ($prefs['budget'] === 'low' && preg_match('/luxury|premium|\$\$\$/', $cost)) return false;
        return true;
    }

    private function stop(int $id, string $label, string $reason, int $minutes): array {
        $type = get_post_type_object(get_post_type($id));
        if ($this->is_food($id)) $label = 'Food & drink';
        $charset = get_bloginfo('charset') ?: 'UTF-8';
        return [
            'id'=>$id,
            'title'=>html_entity_decode(get_the_title($id) ?: '#'.$id, ENT_QUOTES, $charset),
            'url'=>get_permalink($id),
            'image'=>get_the_post_thumbnail_url($id, 'medium_large') ?: '',
            'type'=>$type && !empty($type->labels->singular_name) ? $type->labels->singular_name : 'Place',
            'label'=>$label,
            'reason'=>html_entity_decode(wp_strip_all_tags($reason), ENT_QUOTES, $charset),
            'minutes'=>$minutes,
            'time'=>'',
        ];
    }

    private function is_food(int $id): bool {
        $profile = class_exists(Destination_AI_Profiles::class) ? Destination_AI_Profiles::profile($id) : [];
        $searchable = strtolower((string)get_the_title($id).' '.(string)($profile['traits'] ?? '').' '.(string)($profile['experience_type'] ?? ''));
        foreach (get_object_taxonomies(get_post_type($id)) as $taxonomy) {
            $terms = get_the_terms($id, $taxonomy);
            if (!is_array($terms)) continue;
            foreach ($terms as $term) $searchable .= ' '.strtolower((string)$term->name);
        }
        return (bool)preg_match('/\b(food|restaurant|grill|grille|cafe|coffee|pizza|kitchen|cantina|brewery|diner|bistro|eatery|bar)\b/', $searchable);
    }

    private function visit_minutes(int $id): int {
        $profile = class_exists(Destination_AI_Profiles::class) ? Destination_AI_Profiles::profile($id) : [];
        $minutes = absint($profile['visit_minutes'] ?? 0);
        if ($minutes) return min(240, max(30, $minutes));
        $raw = strtolower((string)($profile['visit_time'] ?? ''));
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(hour|hr)/', $raw, $match)) return min(240, max(30, (int)round((float)$match[1] * 60)));
        if (preg_match('/([0-9]+)\s*(minute|min)/', $raw, $match)) return min(240, max(20, (int)$match[1]));
        $type = get_post_type($id);
        return in_array($type, ['st_hotel','st_rental'], true) ? 45 : ($type === 'st_activity' ? 90 : 60);
    }

    private function tokens(string $text): array {
        $tokens = preg_split('/[^a-z0-9]+/', strtolower(remove_accents($text))) ?: [];
        $stop = ['the','and','for','with','near','around','from','this','that','want','plan','trip','day','tennessee','hours','hour','adventure'];
        return array_values(array_unique(array_filter($tokens, static fn(string $token): bool => strlen($token) >= 3 && !in_array($token, $stop, true))));
    }

    private function node_ids(): array {
        return get_posts(['post_type'=>$this->post_types(),'post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','orderby'=>'modified','order'=>'DESC','meta_query'=>[['key'=>'_tng_graph_excluded','compare'=>'NOT EXISTS']]]);
    }

    private function post_types(): array {
        return array_values(array_filter(['tng_destination','st_activity','activity','st_hotel','st_tours','st_rental','top_sight','st_location'], 'post_type_exists'));
    }

    private function scenario_label(string $scenario): string {
        return ['similar'=>'Recommended stop','family'=>'Family pick','rainy_day'=>'Rain-ready pick','food_after'=>'Food & drink','photography'=>'Scenic stop','adventure'=>'Adventure stop'][$scenario] ?? 'Recommended stop';
    }

    private function clock(int $minutes): string {
        $hours = (int)floor(($minutes % 1440) / 60);
        $minute = $minutes % 60;
        $suffix = $hours >= 12 ? 'PM' : 'AM';
        return sprintf('%d:%02d %s', $hours % 12 ?: 12, $minute, $suffix);
    }

    private function duration_label(int $minutes): string {
        if ($minutes % 60 === 0) return (string)($minutes / 60).' hours';
        return number_format($minutes / 60, 1).' hours';
    }
}
