# TN Game Local Discovery — Production Release Checklist

This document is the production contract for the recovered TN Game Local Discovery / Town Scanner stack.

## What is included

- Google Maps / Apify Local Discovery
- review-first Discovery Queue
- Town Scanner with history and change detection
- Changes Inbox with bulk actions and review states
- 10-photo capture, featured image + gallery import
- scheduled Town Monitoring
- Content Studio Operations Overview
- cron heartbeat / reliability screen
- Apify usage estimates
- monthly usage safeguards and automatic scheduled-monitor pause
- Content Studio operational alerts
- Broad vs Strict Town Scan Scope controls

## Safety rules

1. **Never use `rsync --delete` against `wp-content/plugins/tn-game-os`.** Production contains recovered standalone TN Game UI entry files that are not all owned by the Local Discovery overlay.
2. `tn-game-os/tn-game-os.php` must be first among TN Game OS entries in WordPress `active_plugins`. Older UI plugins reference `TNG_OS_PATH` and `TNG_OS_URL` during bootstrap.
3. Deploy Local Discovery as overlays/companions. Do not replace the recovered homepage, map, router, or trip UI files as part of this release.
4. Keep discovery review-first. Bulk actions send items to Discovery; they do not auto-publish public listings.
5. Strict Town Scan Scope should be tested town-by-town before being enabled globally.

## Required companion plugins

- `tn-game-os/tn-game-local-discovery.php`
- `tn-game-os/tn-game-discovery-media-importer.php`
- `tn-game-os/tn-game-apify-budget-safeguards.php`
- `tn-game-os/tn-game-local-discovery-hardening.php`

The main `tn-game-os/tn-game-os.php` plugin must remain active and load first.

## Cloudways server cron

WP-Cron traffic triggering is not sufficient for reliable production monitoring. Configure a Cloudways cron job every 5 minutes using the application's real `public_html` path:

```bash
cd /REAL/CLOUDWAYS/APPLICATION/PATH/public_html && wp cron event run --due-now --quiet
```

The TN Game heartbeat itself runs every 15 minutes. After configuring the server cron, wait 15–20 minutes and verify **Content Studio → Cron Reliability** shows a new automatic heartbeat without clicking the manual-test button.

Recommended cron expression:

```text
*/5 * * * *
```

## Post-deploy smoke test

Verify these pages without changing any recovered routing code:

- `/`
- `/map/`
- `/trips/`
- `/wp-admin/admin.php?page=tng-content-studio-overview`
- `/wp-admin/admin.php?page=tng-local-discovery`
- `/wp-admin/admin.php?page=tng-town-scanner`
- `/wp-admin/admin.php?page=tng-town-changes`
- `/wp-admin/admin.php?page=tng-town-monitor`
- `/wp-admin/admin.php?page=tng-cron-reliability`
- `/wp-admin/admin.php?page=tng-apify-usage`
- `/wp-admin/admin.php?page=tng-apify-budget`
- `/wp-admin/admin.php?page=tng-content-studio-alerts`
- `/wp-admin/admin.php?page=tng-town-scan-scope`

## Functional smoke test

1. Run one 5–10 result Local Discovery search.
2. Run one Town Scanner search in **Broad area** mode.
3. Confirm Changes Inbox receives the scan state.
4. Add one result to Discovery.
5. Approve one test candidate into a draft Activity and verify image #1 is featured and remaining captured images populate the gallery.
6. Run one monitored town manually.
7. Verify Apify Usage records the run.
8. Verify Usage Safeguards reflects the current month's results.
9. Switch Scan Scope to Strict for a single test town, run a small scan, and confirm the Scope page reports Returned / Kept / Filtered counts. Switch back to Broad if the returned address data is incomplete.
10. Verify Content Studio Alerts is either All Clear or links to the relevant warning source.

## Budget defaults

Recommended initial safeguards:

- monthly result budget: `10000`
- per-town cap: `75`
- warnings: `50%`, `75%`, `90%`
- automatic scheduled-monitor pause: enabled

Manual scans remain intentionally available even when automatic monitoring is paused by budget.

## Scan Scope

**Broad area** preserves the current Google Maps behavior.

**Strict town match** filters Apify's returned dataset before Town Scanner or Town Monitoring consumes it. A result is retained only if the returned location/address data contains one of the configured allowed names for the requested town.

Rule syntax:

```text
Monteagle, TN => Monteagle
Gruetli-Laager, TN => Gruetli-Laager; Gruetli Laager
```

To intentionally include a neighboring community:

```text
Monteagle, TN => Monteagle; Sewanee
```

## Rollback

The production deploy script creates a full backup of the existing `wp-content/plugins/tn-game-os` directory before copying any release files.

If the Local Discovery hardening layer causes a problem, deactivate only the companion plugin first:

```bash
wp plugin deactivate tn-game-os/tn-game-local-discovery-hardening.php --skip-plugins --skip-themes
wp cache flush --skip-plugins --skip-themes
```

If the budget layer causes a problem:

```bash
wp plugin deactivate tn-game-os/tn-game-apify-budget-safeguards.php --skip-plugins --skip-themes
wp cache flush --skip-plugins --skip-themes
```

Do not delete the recovered TN Game OS/UI files during rollback.

## Merge gate

PR #74 should move from draft to ready-for-merge only after:

- homepage and `/map/` remain healthy
- Local Discovery and Town Scanner work
- one scheduled-monitor run succeeds
- an automatic heartbeat is observed from Cloudways server cron
- strict scope is tested once and either accepted or left in Broad mode
- no new PHP fatal errors are present
