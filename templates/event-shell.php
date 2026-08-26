<?php
if (!defined('ABSPATH')) exit;
$id = get_queried_object_id();
$title = get_the_title($id);
$image = get_the_post_thumbnail_url($id, 'full');
$timestamp = TNG_Event_UI::timestamp($id);
$date = $timestamp ? wp_date('D, M j, Y', $timestamp) : 'Event date coming soon';
$time = TNG_Event_UI::time_label($id);
$venue = TNG_Event_UI::venue($id);
$ticket = TNG_Event_UI::ticket_url($id);
$description = TNG_Event_UI::description($id);
$related = TNG_Event_UI::related($id);
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<main class="tng-event tng-app-shell">
    <section class="tng-event-hero<?php echo $image ? '' : ' is-placeholder'; ?>"<?php echo $image ? ' style="background-image:url(' . esc_url($image) . ')"' : ''; ?>>
        <div class="tng-event-hero__overlay"></div>
        <div class="tng-event-hero__content">
            <span class="tng-eyebrow">Live event</span>
            <h1><?php echo esc_html($title); ?></h1>
            <div class="tng-event-hero__meta">
                <span>📅 <?php echo esc_html($date); ?></span>
                <?php if ($time): ?><span>🕒 <?php echo esc_html($time); ?></span><?php endif; ?>
                <span>📍 <?php echo esc_html($venue); ?></span>
            </div>
        </div>
    </section>

    <section class="tng-event-actions">
        <a class="tng-ui-button" href="<?php echo esc_url($ticket); ?>" target="_blank" rel="noopener">Get tickets</a>
        <a class="tng-ui-button tng-ui-button--secondary" href="<?php echo esc_url(home_url('/trips/')); ?>">＋ Add to trip</a>
        <button class="tng-ui-button tng-ui-button--secondary" type="button" onclick="navigator.share?navigator.share({title:document.title,url:location.href}):navigator.clipboard.writeText(location.href)">Share</button>
    </section>

    <div class="tng-event-layout">
        <div class="tng-event-main">
            <section class="tng-event-card">
                <span class="tng-eyebrow">Event overview</span>
                <h2>About this event</h2>
                <p><?php echo esc_html($description); ?></p>
            </section>

            <section class="tng-event-card">
                <div class="tng-event-heading"><div><span class="tng-eyebrow">Venue</span><h2>Plan your arrival</h2></div><a href="https://www.google.com/maps/search/?api=1&query=<?php echo rawurlencode($venue); ?>" target="_blank" rel="noopener">Directions</a></div>
                <div class="tng-event-map"><span>⌖</span><strong><?php echo esc_html($venue); ?></strong><small>The live TN Game map and nearby recommendations will connect here.</small></div>
            </section>

            <?php if ($related): ?>
            <section class="tng-event-card">
                <div class="tng-event-heading"><div><span class="tng-eyebrow">More to see</span><h2>Related events</h2></div><a href="<?php echo esc_url(home_url('/events/')); ?>">View all</a></div>
                <div class="tng-event-related">
                    <?php foreach ($related as $event): $thumb = get_the_post_thumbnail_url($event->ID, 'medium_large'); ?>
                    <a href="<?php echo esc_url(get_permalink($event)); ?>">
                        <span class="tng-event-related__media"<?php echo $thumb ? ' style="background-image:url(' . esc_url($thumb) . ')"' : ''; ?>></span>
                        <strong><?php echo esc_html(get_the_title($event)); ?></strong>
                        <small>View event →</small>
                    </a>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
        </div>

        <aside class="tng-event-sidebar">
            <section class="tng-event-card tng-event-card--summary">
                <span class="tng-eyebrow">Event details</span>
                <dl>
                    <div><dt>Date</dt><dd><?php echo esc_html($date); ?></dd></div>
                    <?php if ($time): ?><div><dt>Time</dt><dd><?php echo esc_html($time); ?></dd></div><?php endif; ?>
                    <div><dt>Venue</dt><dd><?php echo esc_html($venue); ?></dd></div>
                </dl>
                <a class="tng-ui-button" href="<?php echo esc_url($ticket); ?>" target="_blank" rel="noopener">Buy tickets</a>
            </section>
            <section class="tng-event-trip">
                <span class="tng-eyebrow">Make a day of it</span>
                <h2>Build a trip around the show.</h2>
                <p>Add food, trails, lodging, and nearby places before you arrive.</p>
                <a href="<?php echo esc_url(home_url('/trips/')); ?>">Plan a trip</a>
            </section>
        </aside>
    </div>
</main>
<?php wp_footer(); ?>
</body>
</html>
