TN GAME OS 5.116.0 — ADVENTURE FINISH HANDOFF

CURRENT MILESTONE
- Finishes an adventure when every stop is completed or intentionally skipped.
- Preserves the Saved Adventure title and source identity in history and recaps.
- Records whether each archived stop was completed or skipped.
- Clears the finished active trip through the canonical reset path after the recap is captured.
- Keeps Saved Adventures reusable and does not delete plan-library records.

TN GAME OS 5.115.0 — ACTIVE ADVENTURE RESUME

CURRENT MILESTONE
- Marks the Saved Adventure currently loaded into Trips.
- Shows completed, skipped, remaining, and overall resolved progress.
- Resumes the active itinerary in one tap from Trips or Saved Adventures.
- Prevents skipped stops from incorrectly becoming the next recommended stop.
- Keeps progress private to the signed-in Explorer and does not add background writes.

TN GAME OS 5.114.0 — ADVENTURE LAUNCH HANDOFF

CURRENT MILESTONE
- Starts any private Saved Adventure as the active Trips itinerary.
- Requires explicit confirmation before replacing an existing trip.
- Replaces instead of merging, preventing stops from two itineraries from silently mixing.
- Resets only the replaced active route and stop progress; Saved Adventures remain untouched.
- Carries the selected adventure title into Trips and opens the route builder for review.

TN GAME OS 5.113.0 — SAVED ADVENTURE MAPS

CURRENT MILESTONE
- Opens a private Saved Adventure directly on the Universal Map.
- Draws numbered itinerary stops and a connected route in saved order.
- Fits the map to the adventure while retaining every Universal Map discovery layer.
- Exposes plan coordinates only to the signed-in Explorer who owns the plan.
- Adds a one-tap View Map action to every Saved Adventure card.

TN GAME OS 5.112.0 — SAVED ADVENTURES

CURRENT MILESTONE
- Adds a private Saved Adventures workspace for reusable Adventure AI itineraries.
- Reopens plans with their stop order, start time, travel buffer, and Universal Map preview.
- Supports explicit rename and duplicate actions without permanent deletion.
- Keeps up to 12 plans per Explorer and carries forward the existing 5.111 last-plan record.
- Connects Saved Adventures to Trips and the native five-tab app shell.

TN GAME OS 5.111.0 — ADVENTURE AI V2

CURRENT MILESTONE
- Makes generated Tennessee itineraries editable before saving.
- Adds stop reordering, removal, undo, and original-plan reset controls.
- Recalculates arrivals and total duration when start time or travel buffer changes.
- Draws a dependency-free route preview from Universal Map coordinates.
- Saves only the explicitly approved stop order and timing preferences to the Explorer account.

TN GAME OS 5.110.0 — OFFLINE PACKS

CURRENT MILESTONE
- Adds device-local Essentials, Tennessee Places, and Events packs.
- Downloads only predefined public routes marked safe by TN Game OS.
- Shows storage and saved-screen status with update and remove controls.
- Keeps every private Explorer route and gameplay write network-only.

TN GAME OS 5.109.0 — PRODUCTION SMOKE TESTS

CURRENT MILESTONE
- Adds a read-only production verification screen for routes, privacy, offline assets, and critical modules.
- Understands Coming Soon mode and validates its expected public response.
- Confirms public Explore caching and private route cache isolation.
- Exports a support-friendly JSON report without changing site or Explorer data.

TN GAME OS 5.108.0 — OFFLINE MODE

CURRENT MILESTONE
- Adds a root-scoped service worker and installable TN Game web app manifest.
- Caches same-origin app assets and anonymous public discovery screens.
- Provides a branded read-only offline fallback when the network disappears.
- Keeps Trips, XP, uploads, profiles, and Adventure Recaps network-only for privacy and consistency.
- Never queues gameplay rewards or mutations that could be duplicated after reconnecting.

TN GAME OS 5.107.0 — ADVENTURE RECAPS

CURRENT MILESTONE
- Automatically creates private recaps from completed trips and games.
- Saves stops, checkpoints, XP, route distance, time and approved Explorer photos.
- Adds editable memory titles and notes plus native share/copy controls.
- Connects Recaps to Trips, Past Trips and Completed Adventures.
- Keeps every recap private to its signed-in Explorer account.

TN GAME OS 5.106.0 — AI ADMIN / CONTENT MANAGER

CURRENT MILESTONE
- Natural-language requests become reviewable content plans.
- Every write requires explicit approval and is reversible.
- No automatic publishing, permanent deletion or batch execution.
- Optional structured OpenAI planning with an on-site fallback.

TN GAME OS 2.1.0 — MODULAR REBUILD

INSTALLATION
1. Deactivate the previous TN Game Core or TN Game OS build.
2. Upload and activate this package as TN Game OS.
3. Purge Breeze and Cloudways Varnish.
4. Open TN Game OS → OS Settings.

ARCHITECTURE
- Small bootstrap file
- Module interface and dependency container
- Isolated Admin, Settings, Services, Assets and Destination modules
- Existing gameplay retained behind a compatibility module

STABILITY
- One stable top-level slug: tn-game-os
- OS Settings is a real wp-admin page: admin.php?page=tng-os-settings
- The legacy top-level menu is removed
- Existing data and options are reused

PRESERVED
Trail maps, GPX, elevation, checkpoints, GamiPress, progression, odometer, HUD, Food & Drink, Google import, galleries, photos, audits, Content Wizard, Quick Duplicate and developer tools.


UNIFIED CONTENT SOURCES — 2.1.0
- Adds a provider registry and a universal Content Sources module.
- Google Places (New) is the first working provider.
- Replaces the legacy Food importer AJAX handler while preserving its button and metadata.
- Adds a Content Sources meta box to every Traveler Activity.
- Stores normalized source data, source ID, last sync, status, errors, response hash, attributes and photo references separately from editorial content.
- Maps compatible Google data into existing Food & Drink fields.
- Adds TN Game OS → Content Sources dashboard.
- Future providers can register through tng_os_register_content_source_providers without modifying the OS bootstrap.
- Google photo resource references and attribution are stored, but photos are not automatically downloaded in this release.


TN GAME OS 2.1.1 — CONTENT MANAGER UI FIX
- Fixes the modular Content Manager CSS and JavaScript URLs.
- Ensures assets load on both tn-game and tng-os administration slugs.
- Moves legacy Content Manager subpages beneath the TN Game OS parent menu.
- Restores service cards, listing counts, action cards and responsive layout.
- Improves spacing, focus states, card sizing and mobile presentation.
- No Activity data, source data, Google imports, game progress or settings are changed.


TN GAME OS 2.2.0 — FOOD & DRINK FRONT-END SERVICE
- Adds Food & Drink as a Traveler-style virtual front-end service.
- Adds a Food & Drink tab beside Traveler's Recommended for You service tabs.
- Removes Food & Drink listings from the ordinary Activity recommendations.
- Adds a dedicated /food-drink/ archive using the active Traveler header and footer.
- Adds Food & Drink to compatible primary Traveler navigation menus.
- Adds separate Food & Drink counts to Top Destination cards where Activity location terms can be resolved.
- Keeps restaurants stored as st_activity for Traveler galleries, locations, favorites and compatibility.
- Includes responsive restaurant cards matching the clean Traveler visual language.
- Does not convert or duplicate existing restaurant posts.


TN GAME OS 2.3.0 — UNIFIED RECOMMENDATIONS
- Replaces the complete Traveler "Recommended for you" section.
- Does not depend on Traveler's tab AJAX or carousel initialization.
- Renders all category data on the server before the page loads.
- Combines Traveler Hotels, Tours, Rentals and Cars with TN Game services.
- Supports Trails, Food & Drink, Activities, Concerts, Shops, Historic Sites, Campgrounds, Waterfalls and Scenic Views.
- Hides tabs that have no published listings.
- Keeps Food & Drink out of the ordinary Activity tab.
- Adds responsive cards, horizontally scrolling tabs, keyboard controls and View All links.
- Adds [tng_recommendations] for manual placement in Elementor, WPBakery or a page editor.
- Disables the previous Food & Drink tab-injection script while retaining the Food & Drink archive.


TN GAME OS 2.4.0 — MANUAL RECOMMENDATIONS WIDGET
- Removes all automatic Traveler homepage DOM replacement.
- Stops searching for or replacing Traveler sections with JavaScript.
- Prevents the homepage header, hero, search form and destination layout from being altered.
- Keeps the unified recommendations widget available through [tng_recommendations].
- Adds a WPBakery element named TN Game Recommendations.
- Adds an Elementor widget named TN Game Recommendations when Elementor is active.
- Adds TN Game OS → Recommendations Widget with placement instructions.
- Retains all server-rendered categories and does not use AJAX.
- Adds safer CSS containment for page-builder placement.
- The site administrator should remove Traveler's original Recommended for you element manually.


TN GAME OS 2.4.1 — CRITICAL ERROR FIX
- Removes the unsafe Elementor PHP widget registration that could run before Elementor loaded.
- Fixes the critical error immediately after plugin activation.
- Retains the TN Game Recommendations shortcode.
- Retains the native WPBakery element.
- Elementor users can place the widget safely with a Shortcode element using [tng_recommendations].
- Does not modify listings, source data, Google imports, progression, maps, photos or settings.


TN GAME OS 2.4.2 — RECOMMENDATIONS SHORTCODE FATAL FIX
- Fixes: Call to private method Service_Registry::taxonomy().
- Makes the service taxonomy resolver publicly readable by other OS modules.
- Adds an is_callable safeguard before the Recommendations module invokes it.
- Restores [tng_recommendations] in Elementor, WPBakery, Gutenberg and the Classic Editor.
- Does not modify listings, settings, Google source records, maps, photos or player progress.


TN GAME OS 2.5.0
- Accurate total recommendation counts.
- Adds [tng_destinations] and WPBakery destination widget.
- Combines Traveler and TN Game service counts.
- Manual placement only; no homepage DOM replacement.


TN GAME OS 3.0.0 — FIRST-CLASS DESTINATION PLATFORM
- Expands tng_destination into the master geographic content object.
- Adds a shared tng_destination_ref relationship to Activities, Hotels, Tours, Rentals, Cars, Top Sights, Posts and Pages.
- Adds destination relationship metaboxes to supported listings.
- Adds destination coordinates, radius, weather location, season, crowd baseline and tagline.
- Adds full destination pages with alerts, maps, service counts, recommendations, passport progress, itinerary builder, weather integration hooks, seasonal recommendations, trip planner and leaderboards.
- Adds Local Alerts as a managed content type.
- Adds [tng_destinations], [tng_near_me], [tng_trip_planner], [tng_destination_map], [tng_destination_leaderboard] and [tng_local_alerts].
- Adds REST endpoints for nearby destinations, itinerary generation, saved trip plans and destination analytics.
- Adds a rule-based itinerary engine with a provider filter for future AI integrations.
- Adds lightweight crowd estimates and daily destination view analytics.
- Replaces dependency on Traveler's unknown location taxonomy.
- Existing Traveler listing post types remain the booking/content engines but are connected through TN Game Destinations.


TN GAME OS 3.1.0 — FUNCTIONAL NEARBY, TRIP PLANNER AND LEADERBOARD
- Near Me now returns both closest TN Game Destinations and nearby geocoded listings.
- Supports common Traveler, TN Game and custom latitude/longitude metadata formats.
- Shows an administrator setup notice when no destinations have coordinates.
- Adds Add to Trip controls to destination recommendation cards.
- Adds persistent per-user trip plans with add, remove, refresh and clear actions.
- Trip plans are normalized against currently published posts and display images, listing types and links.
- Explorer Leaderboard now reads the configured GamiPress Explorer XP points type.
- Includes fallback user meta detection when GamiPress is unavailable.
- Sorts actual player XP totals and highlights the current player.
- Displays mileage and checkpoint statistics when available.
- Improves empty states, status counts, accessibility feedback and mobile presentation.


TN GAME OS 4.0.0 — ADMIN WORKSPACES
- Replaces the crowded TN Game OS submenu with six clean workspaces: Dashboard, Content, Destinations, Explorer, System and Developer.
- Keeps every legacy and specialist page registered and reachable through launcher cards and direct links.
- Auto-generates Content service tools from the registered Service Registry.
- Adds a modern launcher dashboard with metrics, workspaces, quick actions and recently edited content.
- Adds a global TN Game Search command palette using Command-K on macOS and Control-K on Windows.
- Adds a TN Game Search button to the WordPress admin bar.
- Adds operational notifications for missing API keys, destination coordinates, pending comments and plugin updates.
- Allows each user to dismiss dashboard notifications.
- Adds capability-based navigation: editors see content and destinations, players can access Explorer, administrators see System and Developer.
- Adds role-aware command results and launcher tools.
- Consolidates audits, simulation, repair utilities and map editing inside the Developer workspace.
- Consolidates integrations, diagnostics, sources and settings inside the System workspace.
- Consolidates photos, achievements, ranks, XP and player tools inside the Explorer workspace.
- No existing listing data, routes, photos, destinations, player XP, settings or source records are changed.


TN GAME OS 4.0.1 — ADMIN ACCESS FIX
- Fixes “Sorry, you are not allowed to access this page” on legacy TN Game tools.
- Stops removing WordPress menu and submenu registrations from PHP.
- Preserves WordPress capability and admin-page routing checks.
- Cleans the sidebar visually with JavaScript instead of altering registration data.
- Keeps the legacy TN Game Core parent registered but visually hidden.
- Keeps only Dashboard, Content, Destinations, Explorer, System and Developer visible under TN Game OS.
- Existing direct links, launcher cards and command-palette results remain accessible.
- No content, settings, destinations, XP, photos, routes or source records are modified.


TN GAME OS 4.0.2 — WORKSPACE ROUTING AND SIDEBAR FIX
- Fixes unauthorized-page errors from generated service cards.
- Maps plural Service Registry IDs to the registered legacy service page slugs:
  trails→trail, concerts→concert, shops→shop, waterfalls→waterfall,
  campgrounds→campground, events→event, and related aliases.
- Removes duplicated service cards from the Content workspace.
- Deduplicates all workspace tools by their final destination URL.
- Adds focused TN Game OS sidebar mode on TN Game OS admin screens.
- Hides unrelated WordPress, WooCommerce, Traveler and plugin menus visually.
- Keeps every hidden WordPress menu registered, so permissions and routing remain intact.
- Adds a Show WordPress Menu / Focus TN Game OS toggle at the bottom of the sidebar.
- Remembers each administrator's focused-sidebar preference in the browser.
- Does not modify content, listings, settings, destinations, XP, maps, photos or imports.


TN GAME OS 4.1.0 — DESTINATION STUDIO
- Replaces the default Gutenberg editor for tng_destination with a purpose-built Destination Studio.
- Automatically redirects Add Destination and Edit Destination actions into the new studio.
- Keeps an Advanced/Classic Editor link for compatibility and troubleshooting.
- Adds Overview, Discovery, Businesses, Explorer, Analytics and Settings tabs.
- Adds destination hero-image selection through the WordPress Media Library.
- Adds title, tagline, summary, overview, history, local tips and why-visit editing.
- Adds coordinates, Nearby radius, weather location, county, region, season and crowd controls.
- Adds live connected-content counts and a filterable list of linked listings.
- Adds passport stamp, destination XP bonus and seasonal challenge controls.
- Adds 30-day analytics summaries and an internal reporting note.
- Adds SEO title, SEO description and destination slug controls.
- Adds a sticky live summary sidebar with readiness checks and inventory counts.
- Preserves the existing tng_destination post type, metadata, relationships, URLs and frontend destination pages.
- No destination records or linked listings are migrated or deleted.


TN GAME OS 4.2.0 — CODE AUDIT AND DUPLICATE CLEANUP
- Audited every PHP file in the TN Game OS package.
- Fixed the confirmed Content Wizard and Content Manager double-rendering root cause.
- Removes duplicate modern Admin registrations for tn-game-content-wizard and tn-game-content-dashboard.
- Leaves those legacy pages registered exactly once by TNG_Content_Manager, preserving WordPress access checks.
- Adds one-time initialization and one-time admin-page guards to the compatibility Content Manager.
- Adds module-class and module-ID de-duplication to the core loader.
- De-duplicates command-palette entries by their final URL.
- Adds System → Runtime Audit for detecting live duplicate callbacks and menu slugs.
- Includes AUDIT-REPORT.json documenting the static audit and corrected overlap.
- No content, settings, destinations, listings, XP, routes, maps, photos, imports or user data are modified.


TN GAME OS 4.3.0 — DISCOVERY SEARCH
- Adds a tourism-first Discovery Search designed for TN Game OS.
- Removes hotel-booking concepts such as checkout, guests and room count.
- Searches Destinations, Traveler Activities, hotels, rentals, tours and Top Sights.
- Adds What, Where and optional When fields.
- Adds Trails, Waterfalls, Food & Drink, Events, Shops, History, Camping, Scenic and Lodging filters.
- Adds live autocomplete and visual result cards.
- Adds Near Me, Surprise Me and My Trip shortcuts.
- Integrates destination relationships and the TN Game OS Trip Planner.
- Adds the [tng_discovery_search] and [tn_game_search] shortcodes.
- Automatically replaces the large Traveler homepage booking form when a supported Traveler search wrapper is detected.
- Keeps the original Traveler form in the page source and hides it visually, allowing safe fallback.
- No theme files, Elementor templates or Traveler core files are modified.


TN GAME OS 4.3.1 — DISCOVERY SEARCH POLISH
- Fixes visible HTML entities such as &amp;, &#8217; and &#038; in search results.
- Decodes titles, taxonomy labels, destination names, post-type labels and descriptions before REST output.
- Adds a defensive browser-side entity decoder for cached and third-party result data.
- Improves fallback descriptions when a listing has no manual excerpt.
- Uses medium-large featured images for sharper result cards.
- Separates the results area visually from the search controls.
- Adds consistent image ratios, equal-height cards and cleaner typography.
- Limits long titles and descriptions without breaking the card grid.
- Improves hover states, category chips, close control and focus behavior.
- Refines tablet and mobile result layouts.


TN GAME OS 4.3.2 — SERVICE TAG MANAGER
- Adds Content → Service Tag Manager for bulk classification of existing Traveler Activities.
- Supports Trails, Waterfalls, Food & Drink, Concerts, Shops, Historic Sites, Campgrounds, Events and Scenic Views.
- Allows an Activity to have multiple discovery tags, including Trails + Waterfalls.
- Uses additive tagging by default, preserving existing Activity Types.
- Includes an explicit Replace mode for administrators who need it.
- Adds native Activity-list bulk actions for Add Trails, Add Waterfalls, and Add Trails + Waterfalls.
- Adds a TN Game Tags column to the standard Traveler Activity list.
- Automatically creates missing TN Game service taxonomy terms using the exact slugs expected by Discovery Search.
- Adds the manager to the TN Game OS Content workspace and command palette.


TN GAME OS 4.3.3 — DESTINATION CARD DESIGN FIX
- Rebuilds the Top Destinations card layout with strongly scoped CSS.
- Gives placeholder images and real destination photos the same aspect ratio and card structure.
- Removes the large empty white area under destinations with no connected content.
- Adds a useful “coming soon” state when a destination has no listing counts.
- Moves the Explore action into a consistent footer beneath every image.
- Improves image overlays, destination titles, count readability and visual hierarchy.
- Limits displayed count badges to four for a cleaner layout.
- Adds consistent card heights and responsive tablet/mobile behavior.
- Uses stronger selectors to prevent Traveler and theme styles from overriding the widget.


TN GAME OS 4.3.4 — ACTIVE DESTINATION CARD FIX
- Corrects the actual active [tng_destinations] shortcode in Destination Platform.
- Replaces the old image-plus-empty-footer structure with one unified image tile.
- Removes the embedded “Explore the destination” text from the placeholder SVG.
- Makes real photos and placeholders use the same dimensions and overlay.
- Displays destination title, total inventory and up to three service totals inside the image.
- Shows a polished coming-soon pill when a destination has no linked content.
- Adds a hover arrow and consistent responsive behavior.
- Updates destination-platform.css, which is the stylesheet actually loaded by the active shortcode.


TN GAME OS 4.4.0 — CONCERT TRIP PAGES
- Adds Concert Trip Pages as the first Concert Intelligence module.
- Adds a dedicated trip-page dashboard under TN Game OS.
- Adds a Concert Trip Page editor panel to Traveler Activity records.
- Generates a full event hero, ticket actions, quick facts, trip timeline and local planning notes.
- Automatically recommends destination-connected lodging, food, trails, waterfalls, camping, shops and historic places.
- Supports same-day, overnight and weekend trip styles.
- Lets editors choose which recommendation categories appear for each concert.
- Adds [tng_concert_trip_page event_id="123"] for manual placement.
- Automatically appends the trip experience to enabled concert Activities.
- Preserves Traveler as the underlying event record and does not modify Traveler core.


TN GAME OS 4.5.0 — DESTINATION RELATIONSHIPS + CONCERT INTELLIGENCE
- Adds primary and related destination assignments to supported listings.
- Synchronizes all selected and inherited destinations into tng_destination_ref terms.
- Adds destination hierarchy and destination type fields.
- Supports destination inheritance such as The Caverns → Pelham → South Cumberland.
- Updates Concert Trip Pages to recommend content across all effective destinations.
- Adds Concert Intelligence dashboard, sources, venues, artists and import queue.
- Adds the first Tixr provider with group-page event discovery.
- Parses JSON-LD and Open Graph event data when available.
- Adds manual source sync and six-hour scheduled sync.
- Adds duplicate matching by external ID, source URL, and normalized title/date.
- Creates or updates Traveler st_activity concert records.
- Automatically populates Concert Trip Page dates, times, venue, ticket URL, trip style and visitor notes.
- Automatically applies venue primary and related destinations.
- Automatically assigns the Concerts service tag.
- Creates reusable artist records and links them to imported activities.
- Downloads the source poster as the featured image when possible.
- Includes The Caverns venue and Tixr source as safe default records.
- Does not modify Traveler core.


TN GAME OS 4.5.1 — ROBUST TIXR ADAPTER
- Replaces the single Tixr request with three browser/search-crawler request profiles.
- Adds Tixr sitemap discovery using both sitemap URLs advertised in robots.txt.
- Recursively checks bounded sitemap indexes and filters events to the configured Tixr group.
- Keeps direct group-page discovery as the fastest strategy when it works.
- Adds manually supplied event URLs as a reliable administrative fallback.
- Uses the same robust request layer for individual event pages.
- Records per-strategy HTTP results, content types, byte counts, sitemap totals and event-fetch failures.
- Displays adapter diagnostics on each Concert Source editor.
- Gives actionable import errors instead of only reporting HTTP 403.


TN GAME OS 4.6.0 — CONCERT INTELLIGENCE API CLIENT
- Moves Tixr collection out of WordPress and into the private Playwright API.
- Adds TN Game OS → API Settings.
- Stores a protected API base URL and API key.
- Adds an API health-test action.
- Sends Tixr source syncs to /v1/providers/tixr/sync.
- Receives normalized event JSON and feeds the existing import queue.
- Preserves duplicate detection, venues, artists, multiple destinations and trip-page generation.
- Keeps detailed source diagnostics for API response codes, event counts and failures.
- Shows a dashboard warning until the API connection is configured.


TN GAME OS 4.7.0 — CONCERT INTELLIGENCE HEALTH
- Adds API, browser, and Tixr provider health cards.
- Reads API v2 browser and provider diagnostics.
- Preserves the existing importer, queue, destinations, venues, artists, and trip pages.

= 4.8.0 =
* Adds the TN Studio application shell.
* Adds Discovery Studio with source selection and safe browser-only discovery runs.
* Adds live running state, summary metrics, timeline, discovery-source visualization, event URL inspector, network inspector, JSON/GraphQL endpoint inspector, and raw diagnostics.
* Connects WordPress securely to Concert Intelligence API v2.1 through an authenticated AJAX proxy.

= 4.9.0 =
* Adds Deep Diagnostics to TN Studio Discovery.
* Captures browser metadata, final URL, page title, HTTP status, headers, redirects, screenshots, HTML/body previews, console messages, JavaScript errors, failed requests, and challenge-page analysis.
* Adds Overview, Screenshots, Console, and HTML inspection tabs.

= 5.0.0 =
* Adds TN Studio Knowledge Platform dashboard.
* Adds Entity Registry and Entity Inspector.
* Adds typed Relationship Registry.
* Adds entity revision history and source/confidence display.
* Adds Graph Explorer MVP.
* Preserves Discovery Studio and existing TN Game OS modules.
* Requires TN Platform Core API v3.0.0 for Knowledge features.
