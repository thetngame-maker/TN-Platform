<?php
/**
 * Dedicated Trip Mode surface.
 *
 * This template intentionally does not render WordPress page content. The
 * Trip Mode JavaScript controller owns the single root below, while the normal
 * TN Game/theme header and footer remain available around it.
 */

if (!defined('ABSPATH')) exit;

get_header();
?>
<main id="tng-trip-mode-page" class="tng-trip-mode-page" role="main">
    <section
        id="tng-trip-mode-v1"
        class="tng-trip-mode tng-trip-mode--standalone"
        aria-live="polite"
        aria-busy="true"
    >
        <div class="tng-trip-mode__empty tng-trip-mode__boot">
            <small>ACTIVE TRIP</small>
            <h1>Trip mode</h1>
            <p>Loading your active trip…</p>
        </div>
    </section>
</main>
<?php
get_footer();
