<?php
namespace TNG_OS\Modules\Concerts;

use TNG_OS\Core\Container;
use TNG_OS\Core\Module_Interface;
use WP_Post;
use WP_Query;

if (!defined('ABSPATH')) exit;

final class Concert_Trip_Pages implements Module_Interface {
    private Container $container;

    private array $service_types = [
        'lodging'    => ['label'=>'Stay nearby','icon'=>'🛏','post_types'=>['st_hotel','st_rental'],'terms'=>[]],
        'food'       => ['label'=>'Eat & drink','icon'=>'🍽','post_types'=>['st_activity'],'terms'=>['food-drink','restaurants','food']],
        'trails'     => ['label'=>'Explore trails','icon'=>'🥾','post_types'=>['st_activity'],'terms'=>['hiking-trails','trails']],
        'waterfalls' => ['label'=>'See waterfalls','icon'=>'💧','post_types'=>['st_activity'],'terms'=>['waterfalls']],
        'camping'    => ['label'=>'Camp nearby','icon'=>'⛺','post_types'=>['st_activity'],'terms'=>['campgrounds','camping']],
        'shops'      => ['label'=>'Shop local','icon'=>'🛍','post_types'=>['st_activity'],'terms'=>['shops','shopping']],
        'history'    => ['label'=>'Discover history','icon'=>'🏛','post_types'=>['st_activity'],'terms'=>['historic-sites','history']],
    ];

    public function id(): string { return 'concert_trip_pages'; }

    public function register(Container $container): void {
        $this->container = $container;

        add_action('add_meta_boxes_st_activity', [$this, 'meta_box']);
        add_action('save_post_st_activity', [$this, 'save'], 35, 2);
        add_action('admin_menu', [$this, 'menu'], 22);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);

        add_shortcode('tng_concert_trip_page', [$this, 'shortcode']);
        add_filter('the_content', [$this, 'append_to_content'], 35);
    }

    public function boot(Container $container): void {}

    public function menu(): void {
        add_submenu_page(
            'tn-game-os',
            'Concert Trip Pages',
            'Concert Trip Pages',
            'edit_posts',
            'tng-concert-trip-pages',
            [$this, 'admin_page']
        );
    }

    public function admin_assets(string $hook): void {
        if (strpos($hook, 'tng-concert-trip-pages') === false && get_post_type() !== 'st_activity') return;
        wp_enqueue_style('tng-concert-trip-pages-admin', TNG_OS_URL . 'assets/admin/concert-trip-pages.css', [], TNG_OS_VERSION);
    }

    public function frontend_assets(): void {
        if (!is_singular('st_activity') && !is_singular()) return;
        wp_enqueue_style('tng-concert-trip-pages', TNG_OS_URL . 'assets/frontend/concert-trip-pages.css', [], TNG_OS_VERSION);
    }

    public function meta_box(): void {
        add_meta_box(
            'tng-concert-trip-page',
            'TN Game Concert Trip Page',
            [$this, 'render_meta_box'],
            'st_activity',
            'normal',
            'high'
        );
    }

    public function render_meta_box(WP_Post $post): void {
        wp_nonce_field('tng_concert_trip_save', 'tng_concert_trip_nonce');
        $v = static function(string $key, string $default='') use ($post): string {
            $value = get_post_meta($post->ID, $key, true);
            return $value === '' ? $default : (string)$value;
        };
        $enabled = $v('_tng_trip_enabled', '0') === '1';
        $destination = (int)get_post_meta($post->ID, '_tng_destination_id', true);
        $selected_sections = get_post_meta($post->ID, '_tng_trip_sections', true);
        if (!is_array($selected_sections) || !$selected_sections) $selected_sections = array_keys($this->service_types);
        ?>
        <div class="tng-trip-admin">
            <header>
                <div>
                    <span>CONCERT INTELLIGENCE</span>
                    <h2>Build a complete trip around this event</h2>
                    <p>TN Game OS will combine the concert with nearby places from its assigned Destination.</p>
                </div>
                <label class="tng-trip-toggle">
                    <input type="checkbox" name="_tng_trip_enabled" value="1" <?php checked($enabled); ?>>
                    <span>Enable trip page</span>
                </label>
            </header>

            <?php if (!$destination): ?>
                <div class="notice notice-warning inline"><p>Assign a TN Game Destination to this Activity so nearby recommendations can be generated.</p></div>
            <?php endif; ?>

            <div class="tng-trip-fields">
                <label><span>Venue name</span><input type="text" name="_tng_trip_venue" value="<?php echo esc_attr($v('_tng_trip_venue', 'The Caverns')); ?>" placeholder="The Caverns"></label>
                <label><span>Event date</span><input type="date" name="_tng_trip_date" value="<?php echo esc_attr($v('_tng_trip_date')); ?>"></label>
                <label><span>Show time</span><input type="time" name="_tng_trip_time" value="<?php echo esc_attr($v('_tng_trip_time')); ?>"></label>
                <label><span>Doors open</span><input type="time" name="_tng_trip_doors" value="<?php echo esc_attr($v('_tng_trip_doors')); ?>"></label>
                <label class="wide"><span>Ticket URL</span><input type="url" name="_tng_trip_ticket_url" value="<?php echo esc_attr($v('_tng_trip_ticket_url')); ?>" placeholder="https://..."></label>
                <label><span>Trip length</span>
                    <select name="_tng_trip_length">
                        <?php foreach (['same-day'=>'Same-day trip','overnight'=>'Overnight trip','weekend'=>'Weekend trip'] as $key=>$label): ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($v('_tng_trip_length','overnight'),$key); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><span>Arrival recommendation</span><input type="text" name="_tng_trip_arrival" value="<?php echo esc_attr($v('_tng_trip_arrival','Arrive 2–3 hours before doors')); ?>"></label>
                <label class="wide"><span>Local planning note</span><textarea name="_tng_trip_note" rows="3" placeholder="Parking, cave temperature, camping, or local travel guidance."><?php echo esc_textarea($v('_tng_trip_note')); ?></textarea></label>
            </div>

            <div class="tng-trip-sections">
                <strong>Include these recommendation sections</strong>
                <div>
                    <?php foreach ($this->service_types as $key=>$service): ?>
                        <label><input type="checkbox" name="_tng_trip_sections[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key,$selected_sections,true)); ?>> <?php echo esc_html($service['icon'].' '.$service['label']); ?></label>
                    <?php endforeach; ?>
                </div>
            </div>

            <footer>
                <code>[tng_concert_trip_page event_id="<?php echo (int)$post->ID; ?>"]</code>
                <?php if ($enabled && get_post_status($post) === 'publish'): ?>
                    <a class="button" href="<?php echo esc_url(get_permalink($post)); ?>" target="_blank" rel="noopener">Preview trip page</a>
                <?php endif; ?>
            </footer>
        </div>
        <?php
    }

    public function save(int $post_id, WP_Post $post): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!isset($_POST['tng_concert_trip_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tng_concert_trip_nonce'])), 'tng_concert_trip_save')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        update_post_meta($post_id, '_tng_trip_enabled', isset($_POST['_tng_trip_enabled']) ? '1' : '0');

        $text_fields = [
            '_tng_trip_venue','_tng_trip_date','_tng_trip_time','_tng_trip_doors',
            '_tng_trip_length','_tng_trip_arrival'
        ];
        foreach ($text_fields as $key) {
            $value = isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
            update_post_meta($post_id, $key, $value);
        }

        $url = isset($_POST['_tng_trip_ticket_url']) ? esc_url_raw(wp_unslash($_POST['_tng_trip_ticket_url'])) : '';
        update_post_meta($post_id, '_tng_trip_ticket_url', $url);

        $note = isset($_POST['_tng_trip_note']) ? sanitize_textarea_field(wp_unslash($_POST['_tng_trip_note'])) : '';
        update_post_meta($post_id, '_tng_trip_note', $note);

        $allowed = array_keys($this->service_types);
        $sections = isset($_POST['_tng_trip_sections']) ? array_map('sanitize_key', (array)wp_unslash($_POST['_tng_trip_sections'])) : [];
        update_post_meta($post_id, '_tng_trip_sections', array_values(array_intersect($allowed, $sections)));
    }

    public function append_to_content(string $content): string {
        if (is_admin() || !is_singular('st_activity') || !in_the_loop() || !is_main_query()) return $content;
        $post_id = get_queried_object_id();
        if (get_post_meta($post_id, '_tng_trip_enabled', true) !== '1') return $content;
        return $content . $this->render($post_id);
    }

    public function shortcode(array $atts=[]): string {
        $atts = shortcode_atts(['event_id'=>get_the_ID()], $atts, 'tng_concert_trip_page');
        return $this->render(absint($atts['event_id']));
    }

    private function render(int $event_id): string {
        $event = get_post($event_id);
        if (!$event || $event->post_type !== 'st_activity') return '';

        wp_enqueue_style('tng-concert-trip-pages');

        $destination_id = (int)get_post_meta($event_id, '_tng_destination_id', true);
        $destination_ids = [$destination_id];
        $relationships = $this->container->get('destination_relationships');
        if ($relationships && is_callable([$relationships, 'effective_destination_ids'])) {
            $destination_ids = $relationships->effective_destination_ids($event_id);
        }
        $destination_ids = array_values(array_filter(array_map('absint', $destination_ids)));
        $venue = (string)get_post_meta($event_id, '_tng_trip_venue', true);
        $date = (string)get_post_meta($event_id, '_tng_trip_date', true);
        $time = (string)get_post_meta($event_id, '_tng_trip_time', true);
        $doors = (string)get_post_meta($event_id, '_tng_trip_doors', true);
        $ticket = (string)get_post_meta($event_id, '_tng_trip_ticket_url', true);
        $length = (string)get_post_meta($event_id, '_tng_trip_length', true) ?: 'overnight';
        $arrival = (string)get_post_meta($event_id, '_tng_trip_arrival', true);
        $note = (string)get_post_meta($event_id, '_tng_trip_note', true);
        $sections = get_post_meta($event_id, '_tng_trip_sections', true);
        if (!is_array($sections) || !$sections) $sections = array_keys($this->service_types);

        $image = get_the_post_thumbnail_url($event_id, 'full');
        $destination_name = $destination_id ? get_the_title($destination_id) : '';
        $formatted_date = $date ? wp_date('l, F j, Y', strtotime($date)) : '';
        $formatted_time = $time ? wp_date('g:i A', strtotime($time)) : '';
        $formatted_doors = $doors ? wp_date('g:i A', strtotime($doors)) : '';

        ob_start(); ?>
        <section class="tng-concert-trip" id="concert-trip-<?php echo (int)$event_id; ?>">
            <div class="tng-concert-trip-shell">
                <header class="tng-concert-trip-hero <?php echo $image ? 'has-image' : ''; ?>" <?php if($image): ?>style="--trip-image:url('<?php echo esc_url($image); ?>')"<?php endif; ?>>
                    <div class="tng-concert-trip-hero-shade"></div>
                    <div class="tng-concert-trip-hero-copy">
                        <span class="tng-concert-trip-eyebrow">PLAN YOUR CONCERT TRIP</span>
                        <h2><?php echo esc_html(get_the_title($event_id)); ?></h2>
                        <p><?php echo esc_html(implode(' · ', array_filter([$formatted_date, $venue, $destination_name]))); ?></p>
                        <div class="tng-concert-trip-actions">
                            <?php if ($ticket): ?><a class="primary" href="<?php echo esc_url($ticket); ?>" target="_blank" rel="nofollow sponsored noopener">Buy tickets</a><?php endif; ?>
                            <?php if ($destination_id): ?><a href="<?php echo esc_url(get_permalink($destination_id)); ?>">Explore <?php echo esc_html($destination_name); ?></a><?php endif; ?>
                        </div>
                    </div>
                </header>

                <div class="tng-concert-trip-facts">
                    <?php if($formatted_date): ?><div><span>📅</span><small>Date</small><strong><?php echo esc_html($formatted_date); ?></strong></div><?php endif; ?>
                    <?php if($formatted_time): ?><div><span>🎵</span><small>Showtime</small><strong><?php echo esc_html($formatted_time); ?></strong></div><?php endif; ?>
                    <?php if($formatted_doors): ?><div><span>🚪</span><small>Doors</small><strong><?php echo esc_html($formatted_doors); ?></strong></div><?php endif; ?>
                    <div><span>🧳</span><small>Suggested trip</small><strong><?php echo esc_html(ucwords(str_replace('-',' ',$length))); ?></strong></div>
                </div>

                <div class="tng-concert-trip-plan">
                    <div>
                        <span class="tng-concert-trip-eyebrow">YOUR GAME PLAN</span>
                        <h3>Make the concert part of the adventure</h3>
                        <p><?php echo esc_html($arrival ?: 'Arrive early, explore the destination, enjoy the show, and stay nearby.'); ?></p>
                        <?php if($note): ?><div class="tng-concert-trip-note"><?php echo nl2br(esc_html($note)); ?></div><?php endif; ?>
                    </div>
                    <ol>
                        <li><span>1</span><div><strong>Arrive and explore</strong><p>Choose a nearby trail, waterfall, shop, or historic stop.</p></div></li>
                        <li><span>2</span><div><strong>Eat local</strong><p>Plan food before doors so the evening stays easy.</p></div></li>
                        <li><span>3</span><div><strong>Enjoy the show</strong><p>Allow extra time for parking, entry, and venue rules.</p></div></li>
                        <li><span>4</span><div><strong>Stay or camp nearby</strong><p>Turn the event into an overnight or weekend trip.</p></div></li>
                    </ol>
                </div>

                <?php if ($destination_id): ?>
                    <div class="tng-concert-trip-nearby">
                        <header><span class="tng-concert-trip-eyebrow">BUILD YOUR TRIP</span><h3>Recommended near <?php echo esc_html($venue ?: $destination_name); ?></h3></header>
                        <?php foreach ($sections as $section):
                            if (empty($this->service_types[$section])) continue;
                            $items = $this->nearby_items($destination_ids ?: [$destination_id], $section, $event_id, 4);
                            if (!$items) continue;
                            $service = $this->service_types[$section];
                        ?>
                            <section class="tng-concert-trip-section">
                                <div class="tng-concert-trip-section-heading"><span><?php echo esc_html($service['icon']); ?></span><h4><?php echo esc_html($service['label']); ?></h4></div>
                                <div class="tng-concert-trip-grid">
                                    <?php foreach ($items as $item):
                                        $thumb = get_the_post_thumbnail_url($item->ID, 'medium_large');
                                    ?>
                                        <a class="tng-concert-trip-card" href="<?php echo esc_url(get_permalink($item)); ?>">
                                            <div class="media"><?php if($thumb): ?><img src="<?php echo esc_url($thumb); ?>" alt="" loading="lazy"><?php else: ?><span><?php echo esc_html($service['icon']); ?></span><?php endif; ?></div>
                                            <div><strong><?php echo esc_html(get_the_title($item)); ?></strong><small>Explore this place</small></div>
                                            <b>→</b>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <footer class="tng-concert-trip-footer">
                    <div><span>TN GAME TRIP PAGE</span><strong>One concert. A complete South Cumberland adventure.</strong></div>
                    <?php if ($ticket): ?><a href="<?php echo esc_url($ticket); ?>" target="_blank" rel="nofollow sponsored noopener">Get tickets →</a><?php endif; ?>
                </footer>
            </div>
        </section>
        <?php
        return (string)ob_get_clean();
    }

    private function nearby_items(array $destination_ids, string $section, int $exclude, int $limit): array {
        $service = $this->service_types[$section] ?? null;
        if (!$service) return [];

        $term_ids = [];
        foreach ($destination_ids as $destination_id) {
            $term_id = (int)get_post_meta($destination_id, '_tng_destination_term_id', true);
            if ($term_id) $term_ids[] = $term_id;
        }
        $term_ids = array_values(array_unique(array_filter($term_ids)));
        if (!$term_ids) return [];

        $post_types = array_values(array_filter($service['post_types'], 'post_type_exists'));
        if (!$post_types) return [];

        $args = [
            'post_type'=>$post_types,
            'post_status'=>'publish',
            'posts_per_page'=>$limit,
            'post__not_in'=>[$exclude],
            'orderby'=>'menu_order date',
            'order'=>'DESC',
            'tax_query'=>[[
                'taxonomy'=>'tng_destination_ref',
                'field'=>'term_id',
                'terms'=>$term_ids,
            ]],
        ];

        if ($service['terms'] && in_array('st_activity',$post_types,true)) {
            $taxonomy = $this->activity_taxonomy();
            $term_ids = [];
            if ($taxonomy) {
                foreach ($service['terms'] as $slug) {
                    $term = get_term_by('slug',$slug,$taxonomy);
                    if ($term && !is_wp_error($term)) $term_ids[] = (int)$term->term_id;
                }
            }
            if ($term_ids) {
                $args['tax_query'][] = [
                    'taxonomy'=>$taxonomy,
                    'field'=>'term_id',
                    'terms'=>$term_ids,
                ];
            }
        }

        return (new WP_Query($args))->posts;
    }

    private function activity_taxonomy(): string {
        if ($this->container->has('services')) {
            $registry = $this->container->get('services');
            if (is_callable([$registry,'taxonomy'])) return (string)$registry->taxonomy();
        }
        foreach (['st_activity_type','activity_type','st_activity_types'] as $taxonomy) {
            if (taxonomy_exists($taxonomy)) return $taxonomy;
        }
        return '';
    }

    public function admin_page(): void {
        if (!current_user_can('edit_posts')) return;
        $events = new WP_Query([
            'post_type'=>'st_activity',
            'post_status'=>['publish','draft','pending'],
            'posts_per_page'=>100,
            'meta_query'=>[['key'=>'_tng_trip_enabled','value'=>'1']],
            'orderby'=>'modified',
            'order'=>'DESC',
        ]);
        ?>
        <div class="wrap tng-trip-dashboard">
            <header class="tng-trip-dashboard-header">
                <div><span>CONCERT INTELLIGENCE</span><h1>Concert Trip Pages</h1><p>Turn event listings into complete destination itineraries.</p></div>
                <a class="button button-primary" href="<?php echo esc_url(admin_url('edit.php?post_type=st_activity')); ?>">Browse Activities</a>
            </header>

            <div class="tng-trip-dashboard-stats">
                <article><strong><?php echo (int)$events->found_posts; ?></strong><span>Trip pages enabled</span></article>
                <article><strong><?php echo (int)$this->count_ready($events->posts); ?></strong><span>Ready to publish</span></article>
                <article><strong>7</strong><span>Recommendation categories</span></article>
            </div>

            <div class="tng-trip-dashboard-panel">
                <h2>Trip pages</h2>
                <?php if (!$events->have_posts()): ?>
                    <div class="tng-trip-empty"><span>🎵</span><h3>No trip pages yet</h3><p>Edit a concert Activity and enable its TN Game Concert Trip Page.</p></div>
                <?php else: ?>
                    <table class="widefat striped">
                        <thead><tr><th>Event</th><th>Date</th><th>Venue</th><th>Destination</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach($events->posts as $event):
                            $destination_id=(int)get_post_meta($event->ID,'_tng_destination_id',true);
                            $date=(string)get_post_meta($event->ID,'_tng_trip_date',true);
                        ?>
                            <tr>
                                <td><strong><?php echo esc_html(get_the_title($event)); ?></strong></td>
                                <td><?php echo esc_html($date ?: 'Needs date'); ?></td>
                                <td><?php echo esc_html((string)get_post_meta($event->ID,'_tng_trip_venue',true) ?: 'Needs venue'); ?></td>
                                <td><?php echo esc_html($destination_id ? get_the_title($destination_id) : 'Needs destination'); ?></td>
                                <td><span class="tng-trip-status <?php echo ($destination_id && $date) ? 'ready':'setup'; ?>"><?php echo ($destination_id && $date) ? 'Ready':'Needs setup'; ?></span></td>
                                <td><a href="<?php echo esc_url(get_edit_post_link($event->ID)); ?>">Edit</a><?php if(get_post_status($event)==='publish'): ?> · <a href="<?php echo esc_url(get_permalink($event)); ?>" target="_blank">View</a><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function count_ready(array $events): int {
        $ready=0;
        foreach($events as $event) {
            if (get_post_meta($event->ID,'_tng_destination_id',true) && get_post_meta($event->ID,'_tng_trip_date',true)) $ready++;
        }
        return $ready;
    }
}
