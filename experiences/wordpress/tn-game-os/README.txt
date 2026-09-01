TN GAME OS 5.173.0 — GUIDED SCHEDULE REFRESH DRAFT HANDOFF

CURRENT MILESTONE
- Adds a Review remaining edit action to the schedule-refresh reminder whenever private drafts prevent refresh.
- Reuses the existing unsaved-edit reviewer to reveal and focus the next editable field without submitting or copying its contents.
- Blocked schedule actions focus the review action when a draft can be reviewed, the reminder while only a save is pending, or Refresh saved schedule when ready.
- Hides the review action as soon as drafts are clean and keeps refresh guarded until every draft or pending update is resolved.
- Uses generic labels and counts only; private names, notes, and dates never enter the reminder or status guidance.
- Adds no autosave, storage, endpoint, request, delayed focus, background work, or public data.

TN GAME OS 5.172.0 — ACCESSIBLE PAUSED ACTION GUIDANCE

CURRENT MILESTONE
- Keeps refresh-paused preparation, start, calendar, and print controls focusable instead of making them silently unresponsive.
- Marks paused controls with aria-disabled and a visible paused treatment while capture guards continue preventing stale actions and requests.
- A blocked action scrolls to the persistent schedule reminder and focuses the reminder when drafts or a save remain, or the refresh button when refresh is ready.
- Mouse, keyboard-generated clicks, nested button content, and checkbox changes receive the same private-safe guidance.
- Preserves controls that were already disabled for an in-flight operation and restores normal controls only through a fresh server-rendered page.
- Adds no autosave, storage, endpoint, request, delayed focus, or public data.

TN GAME OS 5.171.0 — REFRESH-REQUIRED PREPARATION PROTECTION

CURRENT MILESTONE
- Pauses readiness/packing checks, prep focus, next-adventure actions, starts, calendar exports, and printing after a confirmed date change while the page awaits refresh.
- Disables these controls across the current Saved Adventures page and guards captured click/change events before their existing actions run.
- A blocked checkbox change restores its prior checked state without sending a request or changing preparation counts.
- Draft review, name/notes/date editing, manual saves, and guarded refresh remain available. Fresh page loads restore normal server-rendered controls.
- The persistent refresh reminder explains the pause without exposing private content. Failed or unconfirmed schedule saves do not activate it.
- Adds no autosave, storage, server endpoint, background request, or public data.

TN GAME OS 5.170.0 — DRAFT-AWARE SCHEDULE REFRESH

CURRENT MILESTONE
- Successful date saves and clears refresh immediately only when no unsaved name, notes, date, or pending draft save remains.
- Otherwise, the page stays open and a persistent reminder offers Refresh saved schedule after remaining edits are saved or reverted.
- Saving or reverting the last draft enables the button without unexpectedly reloading. Each click rechecks current drafts and in-flight library updates.
- Failed date saves never request a refresh. Newer input, hidden drafts, and partial invalid dates remain protected without relying on an unload dialog for this internal refresh.
- Preparation details and calendar exports still require a page refresh to reflect the saved schedule; the reminder says so explicitly.
- No autosave, draft persistence, new endpoint, extra request, or background work. Existing native warnings remain best-effort for other exits.

TN GAME OS 5.169.0 — GUIDED UNSAVED EDIT REVIEW

CURRENT MILESTONE
- Review identifies the next unsaved field by type (date, notes, or name) and its position among currently reviewable edits.
- After each review, the button advances to the next field and wraps through the remaining edits; saves, reverts, and unavailable fields update the sequence.
- Opens notes and focuses then scrolls the actual field into view, so edits near the end of a long adventure card are easier to find.
- Keeps counts and button text free of private names, notes, and dates. No autosave, draft storage, extra requests, or delayed focus work.

TN GAME OS 5.168.0 — PRIVATE UNSAVED EDIT REVIEW

CURRENT MILESTONE
- Adds an owner-only summary of unsaved name, notes, and date fields, including hidden plans and partial invalid dates.
- Shows save-in-progress counts separately, then updates from confirmed save baselines; failed saves and newer edits remain visible.
- Review unsaved edit cycles through editable drafts, reveals filtered plans, opens notes, and focuses the field without submitting it.
- Review clears this page's search/filter only; it preserves sorting and stored organizer preferences and cancels older delayed focus requests.
- The summary exposes counts, not draft contents, and is hidden when clean and in print. Each field still requires its own Save action.
- Adds no autosave, draft storage, endpoint, public data, or background work. Existing best-effort exit warnings remain unchanged.

TN GAME OS 5.167.0 — UNSAVED ADVENTURE DATE EXIT WARNING

CURRENT MILESTONE
- Extends the shared name/notes exit warning to date edits, invalid partial date input, and date saves or clears awaiting confirmation.
- Captures each submitted date and updates its saved baseline only after success, retaining any newer date typed while saving.
- A successful clear empties the field only if it has not changed since the clear request; newer edits remain protected.
- Releases the pending-save warning before reloading confirmed schedule state, avoiding warnings caused only by a completed save.
- Failed saves preserve the existing baseline and input, keep dirty fields protected, and allow manual retry without reloading.
- Other unsaved names, notes, or dates retain their warning during schedule reloads. If a reload is cancelled, save remaining edits and refresh to update preparation details.
- Adds no autosave, draft storage, extra request, public data, or background work. Browser warnings remain best-effort, especially on mobile.

TN GAME OS 5.166.0 — UNSAVED ADVENTURE NAME EXIT WARNING

CURRENT MILESTONE
- Extends the existing browser exit warning to unsaved adventure names as well as planning notes.
- Uses one shared warning across fields and plans, including hidden cards and saves awaiting confirmation.
- Updates each name's saved baseline from the successful canonical rename response, preserving newer edits and keeping failed saves protected.
- Saving or reverting one field cannot clear the warning for another dirty field or pending notes/name save.
- Removes the handler when all covered fields are clean and no covered save is pending, and rechecks restored pages.
- Adds no autosave, browser draft storage, request, private text in dialogs, or background work.
- Browser warnings remain best-effort, especially on mobile; date edits are not covered. Save names and notes explicitly.

TN GAME OS 5.165.0 — UNSAVED PLANNING NOTES EXIT WARNING

CURRENT MILESTONE
- Requests the browser's generic exit warning only while planning notes differ from confirmed saved text or a notes save is awaiting confirmation.
- Tracks each notes field independently, including edits on plans hidden by organizer filters.
- Updates the saved baseline only after successful notes responses; newer edits and failed saves remain protected.
- Removes the exit handler after all notes are saved or reverted, and restores warning state when the page is shown again.
- Correctly labels a fully reverted notes field Saved or Optional instead of Unsaved changes.
- Adds no autosave, browser draft storage, network request, private text in dialogs, or background work.
- This is a best-effort browser warning, not recovery: mobile app termination may bypass it. Save notes explicitly; names and dates are outside this warning's scope.

TN GAME OS 5.164.0 — VERIFIED SAVED ADVENTURE ORGANIZATION

CURRENT MILESTONE
- Verifies archive, restore, and duplicate results when a metadata write returns false.
- Checks archive state and preserved plan content, allowing an already-correct archive/restore state without requiring fresh timestamps.
- Looks up the newly generated copy ID and verifies the complete expected copy before confirming duplication.
- Retains the existing privacy-safe copy rules: no private notes, scheduled date, readiness, packing, or archive state is copied.
- Uses existing failure recovery to keep the page open and restore action buttons for manual retry.
- Preserves owner/nonce checks, capacity limits, and the active-adventure archive restriction.
- Adds no deletion, automatic retry, new client request, public plan fields, browser storage, or cross-tab synchronization.

TN GAME OS 5.163.0 — VERIFIED ADVENTURE SCHEDULING

CURRENT MILESTONE
- Verifies the stored date and related readiness/packing state when a schedule metadata write returns false.
- Distinguishes unchanged schedules from failed saves when setting or clearing an adventure date.
- Checks the existing checklist reset, including readiness and packing timestamps, before accepting a verified no-op.
- Uses the existing failure path to keep the page open, retain the date input, and make manual retry available.
- Preserves owner/nonce checks, archived-plan restrictions, calendar-date validation, and today-or-future scheduling.
- Adds no automatic retry, new client request, public response fields, browser storage, or cross-tab synchronization.

TN GAME OS 5.162.0 — VERIFIED PREP CHECKLIST SAVES

CURRENT MILESTONE
- Verifies the stored readiness or packing checklist when a metadata write returns false, distinguishing an unchanged checklist from a failed save.
- Rejects unpersisted checklist changes so the existing client restores the checkbox and leaves confirmed counts, print views, and prep status unchanged.
- Keeps launch-ready confirmations behind a successful save response; failed saves remain available for manual retry.
- Checks the whole affected checklist, including removals, without returning private plan fields.
- Retains owner, nonce, scheduled-plan, archived-plan, and allowed-check protections.
- Adds no automatic retry, new client request, browser storage, or cross-tab synchronization.

TN GAME OS 5.161.0 — CONSISTENT SAVED ADVENTURE NAMES

CURRENT MILESTONE
- Returns the sanitized, length-limited saved name only in an authenticated owner's rename response.
- Updates the adventure card, private print view, Next adventure banner, and prep overview from that confirmed name.
- Preserves any newer name typed while the save is pending and clearly reports that it still needs saving.
- Reflects server normalization in the rename field when no newer edit needs preserving; older responses use the submitted name.
- Distinguishes unchanged names from failed writes before returning success, while retaining notes-save protection.
- Adds no automatic retry, new request, browser storage, or broader sharing of private plan data.

TN GAME OS 5.160.0 — SNAPSHOT-SAFE NOTES SAVING

CURRENT MILESTONE
- Captures the submitted notes before saving so continued typing cannot be mistaken for saved content.
- Returns sanitized notes only in the authenticated owner's notes-update response and uses that confirmed text in the private print view.
- Preserves newer textarea edits and labels them Unsaved changes instead of overwriting them or marking them saved.
- Reflects server normalization in the editor only when no newer edits need preserving; older responses use the submitted snapshot.
- Marks typing as unsaved and retains existing save-in-progress protection and manual retry behavior.
- Verifies unchanged notes after a no-op write and rejects failed writes rather than confirming text that was not persisted.
- Adds no automatic save, new request, storage, public sharing, or calendar disclosure.

TN GAME OS 5.159.0 — SAVE-IN-PROGRESS PROTECTION

CURRENT MILESTONE
- Allows only one Saved Adventure update request at a time within the current library page.
- Rejects an overlapping change with a clear wait-and-retry message, without sending or queueing it.
- Keeps the protection active through response parsing and releases it after success, server rejection, or network failure.
- Uses the existing checkbox rollback and button recovery paths; unsaved form text remains available for manual retry.
- Preserves the existing endpoint, nonce, same-origin credentials, and user confirmations.
- Adds no automatic retry, storage, background task, or public output; separate tabs and devices are not coordinated.

TN GAME OS 5.158.0 — RELIABLE PLAN NAVIGATION

CURRENT MILESTONE
- Clears an active search before revealing the private Next adventure card so the requested plan cannot remain hidden.
- Captures the exact prep card and checkbox selected by Review next check before its smooth-scroll focus handoff.
- Cancels stale focus handoffs when another plan navigation starts or the prep priority changes.
- Avoids focusing removed, hidden, disabled, completed, or superseded controls and will not steal focus after the user moves elsewhere.
- Selects the first currently enabled button or link when a revealed plan receives focus.
- Adds no request, new storage, automatic checklist change, background task, or public output.

TN GAME OS 5.157.0 — START-TIME-AWARE PREP PRIORITY

CURRENT MILESTONE
- Orders same-day adventures by their saved start time in the private prep overview and Next adventure banner.
- Uses the same date-and-time comparison for prep-priority and adventure-date sorting.
- Keeps incomplete plans ahead of launch-ready plans in prep sort; checklist counts break ties only at the same date and time.
- Retains upcoming-before-past-before-unscheduled date groups, with past adventures ordered most recent first.
- Uses only owner-rendered plan values, with the existing 10:00 AM default for missing or invalid start times.
- Adds no request, storage, background task, auto-start, or public output; upcoming scope remains day-based.

TN GAME OS 5.156.0 — SCHEDULE-SAFE PREP SCOPE

CURRENT MILESTONE
- Centralizes the private rule that decides which saved adventures count as upcoming preparation.
- Reuses that rule across the prep overview, preparation sort, upcoming filter, next-adventure selection, and launch-ready confirmation.
- Prevents archived or past scheduled plans from receiving a launch-ready confirmation.
- Keeps the scope owner-only and derived from already-rendered plan state and date values.
- Adds no request, storage, background task, notification permission, automatic completion, or public output.

TN GAME OS 5.155.0 — PRIVATE LAUNCH-READY CONFIRMATION

CURRENT MILESTONE
- Confirms in the existing private live-status region when an explicit final checklist save reaches 10 of 10.
- Names the owner-rendered adventure and states that all preparation checks are complete.
- Announces only the transition from incomplete to launch-ready, avoiding repeated or background messages.
- Falls back to the existing server response for every other readiness or packing update.
- Adds no notification permission, automatic completion, request, storage, launch gate, or public output.

TN GAME OS 5.154.0 — ACTIVE PREP FILTER STATE

CURRENT MILESTONE
- Keeps the private prep overview metrics synchronized with the organizer's active filter.
- Exposes the selected Upcoming, Need Prep, or Launch Ready metric through aria-pressed state.
- Adds a clear active style without introducing another filter or duplicate query path.
- Centralizes filter-state updates across saved preferences, reset, prep focus, and next-plan reveal actions.
- Uses only owner-rendered controls with no new storage, request, notification, automation, or public output.

TN GAME OS 5.153.0 — LIVE PREP PRIORITY REFRESH

CURRENT MILESTONE
- Refreshes the private Prep priority order immediately after an explicit readiness or packing save succeeds.
- Moves a newly launch-ready plan into the launch-ready group without requiring a manual page refresh.
- Keeps Needs Prep and Launch Ready filters synchronized through one shared client-only refresh path.
- Preserves the focused checklist control while reordering its existing owner-rendered card.
- Adds no checklist automation, database record, request, notification, background task, or public output.

TN GAME OS 5.152.0 — ADVENTURE PREP PRIORITY SORTING

CURRENT MILESTONE
- Adds an explicit Prep priority option to the private Saved Adventures organizer.
- Places upcoming plans that still need launch checks before launch-ready, past, unscheduled, and archived plans.
- Orders incomplete upcoming plans by the nearest date, then by the lowest completed-check count on the same date.
- Preserves the choice only in the existing device-local organizer preference and never changes checklist completion.
- Uses only owner-rendered card data with no new database record, request, notification, automation, or public output.

TN GAME OS 5.151.0 — PRIVATE ADVENTURE PREP FOCUS

CURRENT MILESTONE
- Identifies the first incomplete launch check on the closest upcoming plan that still needs preparation.
- Adds one compact review action to the existing private prep overview.
- Clears only the temporary search view, selects the existing Needs Prep filter, and focuses the exact owner checklist control.
- Leaves every checklist update explicit; opening a check never completes it or launches an adventure.
- Uses only owner-rendered plan cards with no new storage, request, notification, automation, or public output.

TN GAME OS 5.150.0 — PRIVATE ADVENTURE PREP OVERVIEW

CURRENT MILESTONE
- Summarizes upcoming, needs-prep, and launch-ready Saved Adventures in one private overview.
- Identifies the closest upcoming plan with incomplete launch checks and its remaining count.
- Turns each overview metric into a shortcut to the existing safe organizer filter.
- Updates totals and priority immediately after explicit readiness or packing changes.
- Derives everything from owner-only rendered cards with no new storage, request, notification, launch block, or public data.

TN GAME OS 5.149.0 — ADVENTURE PREP ORGANIZER

CURRENT MILESTONE
- Adds a 0–10 launch-preparation score to every scheduled, non-archived Saved Adventure.
- Adds Needs Prep and Launch Ready filters for upcoming plans in the private organizer.
- Recalculates the score and active filter immediately after explicit readiness or packing changes.
- Keeps unscheduled, archived, past, and unrelated plans out of both preparation filters.
- Uses existing owner-only rendered counts and device view preferences with no new server write, block, notification, or public activity.

TN GAME OS 5.148.0 — NEXT ADVENTURE LAUNCH STATUS

CURRENT MILESTONE
- Combines readiness and packing progress for the next scheduled Saved Adventure.
- Shows both checklist counts, a ten-check progress bar, and a clear remaining or complete status.
- Updates immediately after an explicit checklist change without reloading the private workspace.
- Keeps Start Adventure available even when checks remain; the status is advisory rather than a blocker.
- Derives everything from already-rendered owner-only data with no new storage, request, notification, or public activity.

TN GAME OS 5.147.0 — PRIVATE ADVENTURE PACKING CHECKLIST

CURRENT MILESTONE
- Adds a six-item packing checklist to every scheduled, non-archived Saved Adventure.
- Stores only predefined completion keys inside the owner Explorer's existing private plan record.
- Resets packing completion when the adventure date changes and excludes it from duplicated plans.
- Synchronizes the explicit checklist updates into the owner-only Day-of Adventure Brief.
- Adds no custom sensitive fields, notifications, background tasks, public activity, or external requests.

TN GAME OS 5.146.0 — DAY-OF ADVENTURE BRIEF

CURRENT MILESTONE
- Upgrades each Saved Adventure's print action into a compact owner-only day-of brief.
- Calculates a numbered stop schedule with local arrival times from the saved start, visit, and buffer settings.
- Includes the current readiness checklist, private planning notes, timing summary, and private map route.
- Keeps readiness and notes synchronized in the brief after explicit Explorer updates without a page reload.
- Creates no new stored record, external forecast request, background task, share link, or public plan page.

TN GAME OS 5.145.0 — OFFLINE ADVENTURE INTEGRITY CHECK

CURRENT MILESTONE
- Verifies every device-launcher entry is a readable, same-origin public-safe HTML response.
- Hides questionable cached entries from launch and reports how many need repair.
- Keeps healthy public stops available when another entry in the same pack is damaged or missing.
- Directs the Explorer to reconnect and refresh the affected pack from Saved Adventures.
- Performs no automatic deletion, redownload, private-route request, or server activity write.

TN GAME OS 5.144.0 — OFFLINE ADVENTURE STORAGE SUMMARY

CURRENT MILESTONE
- Measures the cached public-screen size of each device-local Adventure Pack.
- Shows the combined Adventure Pack footprint in the public Offline manager.
- Adds a separately confirmed Remove All action for Adventure Pack caches and metadata.
- Leaves general Offline Packs, app assets, Saved Adventures, accounts, XP, and gameplay untouched.
- Calculates and clears storage locally without a server request or activity record.

TN GAME OS 5.143.0 — DEVICE OFFLINE ADVENTURE MANAGEMENT

CURRENT MILESTONE
- Shows each device pack's last successful public-screen verification date in the Offline manager.
- Adds a confirmed Remove from Device action beside each downloaded Adventure pack.
- Deletes only that pack's cached public screens, staging cache, and device-only metadata.
- Keeps the Explorer's Saved Adventure, plan details, progress, XP, and account data untouched.
- Works without a network request and sends no removal activity to WordPress.

TN GAME OS 5.142.0 — DEVICE-LOCAL OFFLINE ADVENTURE LAUNCHER

CURRENT MILESTONE
- Lists downloaded Saved Adventure packs in the public Offline manager on the same device.
- Opens each verified public stop screen without requiring the private Saved Adventures page.
- Uses numbered pack labels and public URL slugs instead of private plan names or schedules.
- Validates every launcher link as same-origin and caps output at 12 packs with 12 stops each.
- Sends no plan, account, XP, notes, schedule, or device-library data to WordPress.

TN GAME OS 5.141.0 — OFFLINE ADVENTURE REFRESH GUIDANCE

CURRENT MILESTONE
- Marks downloaded public stop packs for a recommended refresh after seven days.
- Prompts one manual refresh for migrated packs that do not yet have a verification date.
- Distinguishes a changed stop list from an age-based refresh recommendation.
- Keeps every refresh explicit and preserves the working pack if verification fails.
- Adds no timers, background fetch, automatic cache mutation, private data, or server tracking.

TN GAME OS 5.140.0 — OFFLINE ADVENTURE VERIFICATION DATES

CURRENT MILESTONE
- Records when a Saved Adventure's complete public stop pack last passed verification.
- Shows the device-local verification date beside each downloaded pack.
- Writes a date only after the full staged replacement succeeds.
- Removes the date when the Explorer explicitly removes the pack.
- Sends no timestamp, plan activity, or browsing data to WordPress or another service.

TN GAME OS 5.139.0 — RELEASE-STABLE OFFLINE ADVENTURES

CURRENT MILESTONE
- Keeps downloaded Saved Adventure stop screens across normal TN Game plugin upgrades.
- Moves Adventure Packs to a release-stable, device-local cache separate from versioned app assets.
- Migrates previously verified 5.135–5.138 Adventure Pack caches during activation without a network request.
- Cleans abandoned staging caches while leaving complete working packs intact.
- Adds no background refresh, account data, private cache, queued write, or automatic content update.

TN GAME OS 5.138.0 — OFFLINE ADVENTURE STORAGE GUARD

CURRENT MILESTONE
- Checks browser storage headroom before creating a staged Adventure Pack replacement.
- Keeps at least 8 MB or 2% of the browser quota free for a safe staging operation.
- Refuses low-space downloads before modifying the working pack.
- Tells the Explorer when existing public stop screens were preserved and how to make room.
- Adds no tracking, background download, private cache, queued write, or automatic deletion.

TN GAME OS 5.137.0 — FAILURE-SAFE OFFLINE PACK UPDATES

CURRENT MILESTONE
- Downloads every replacement public stop screen into a temporary staging cache.
- Keeps the currently working pack untouched when any new screen cannot be verified.
- Replaces the live pack only after the complete staged set passes public-safety checks.
- Restores the previous cache if the final replacement step fails.
- Requires an explicit Update; adds no automatic download, sync, private cache, or queued gameplay.

TN GAME OS 5.136.0 — OFFLINE ADVENTURE PACK FRESHNESS

CURRENT MILESTONE
- Compares each downloaded Adventure Pack with the plan's current public stop list.
- Shows Update Available when saved screens are missing, added, or changed.
- Keeps the existing downloaded screens usable until the Explorer explicitly updates or removes them.
- Performs the comparison inside device Cache Storage without a network request.
- Adds no automatic download, background refresh, queued write, or private data cache.

TN GAME OS 5.135.0 — PRIVACY-SAFE OFFLINE ADVENTURE PACKS

CURRENT MILESTONE
- Downloads the public stop pages for one Saved Adventure after an explicit Explorer action.
- Verifies every logged-out response is marked public-safe before caching it.
- Supports update, device-local status, and removal from any private Saved Adventure card, including archived plans.
- Never caches the private plan, notes, readiness, XP, profile, account cookies, or write requests.
- Limits each pack to 12 same-origin published stop screens and queues no offline gameplay.

TN GAME OS 5.134.0 — UPCOMING ADVENTURES CALENDAR EXPORT

CURRENT MILESTONE
- Exports every upcoming Saved Adventure into one standard .ics calendar file.
- Preserves each plan's local start, estimated finish, title, and full stop list.
- Includes overnight adventures and works alongside individual calendar downloads.
- Excludes archived, unscheduled, and past plans automatically.
- Requires one explicit download and creates no external connection or server write.

TN GAME OS 5.133.0 — PRIVATE SCHEDULE CONFLICT ALERTS

CURRENT MILESTONE
- Detects overlapping upcoming Saved Adventures using their dates and timing summaries.
- Recognizes conflicts that cross midnight into the next day.
- Identifies the overlapping plan on each affected private card.
- Adds one library-level warning while leaving every plan unchanged and startable.
- Runs entirely on already-rendered private data with no request, write, or notification.

TN GAME OS 5.132.0 — ADVENTURE TIMING SUMMARY

CURRENT MILESTONE
- Shows the planned start, estimated finish, and total duration on every Saved Adventure.
- Adds the same timing line to the Next Adventure dashboard.
- Uses the itinerary's existing stop durations and travel buffer.
- Identifies estimated finishes that cross into the next day.
- Runs entirely from already-rendered private plan data with no new endpoint or write.

TN GAME OS 5.131.0 — PRIVATE ADVENTURE PLANNING NOTES

CURRENT MILESTONE
- Adds a collapsed Planning Notes panel to every active Saved Adventure.
- Stores parking details, meeting places, and packing reminders privately per Explorer.
- Limits notes to 600 characters and supports explicit update or clearing.
- Excludes notes from public sharing, calendar files, maps, and printed itineraries.
- Clears notes from duplicated plans and performs no automatic or background write.

TN GAME OS 5.130.0 — ADVENTURE READINESS CHECKLIST

CURRENT MILESTONE
- Adds four explicit preparation checks to each scheduled Saved Adventure.
- Tracks conditions, reservations, route readiness, and gear privately per Explorer.
- Shows readiness progress on both the plan card and Next Adventure dashboard.
- Resets readiness whenever the scheduled date changes so stale preparation is not carried forward.
- Uses only owner actions with no automatic completion, reminder, public data, or background write.

TN GAME OS 5.129.0 — NEXT ADVENTURE QUICK ACTIONS

CURRENT MILESTONE
- Adds View Route directly to the Next Adventure dashboard.
- Offers Start Today's Adventure when the scheduled date is today.
- Offers Resume Adventure when that plan is already active.
- Reuses the existing trip-replacement confirmation and server-side safeguards.
- Adds no automatic start, background action, or new write endpoint.

TN GAME OS 5.128.0 — NEXT ADVENTURE DASHBOARD

CURRENT MILESTONE
- Highlights the nearest upcoming Saved Adventure above the private library.
- Shows Today, Tomorrow, or an exact days-away countdown.
- Displays the scheduled weekday and date using the Explorer's device locale.
- Jumps directly to the plan and restores a visible filter if needed.
- Runs entirely on already-rendered private data with no server or background write.

TN GAME OS 5.127.0 — UPCOMING ADVENTURE ORGANIZER

CURRENT MILESTONE
- Adds an Upcoming filter for scheduled adventures dated today or later.
- Sorts by Adventure Date with upcoming plans first.
- Places recently passed dates after upcoming plans and unscheduled plans last.
- Combines date organization with existing search and private plan states.
- Runs entirely in the browser with no server request or database write.

TN GAME OS 5.126.0 — PRIVATE CALENDAR EXPORT

CURRENT MILESTONE
- Downloads a scheduled Saved Adventure as a standard .ics calendar file.
- Includes its local start time, estimated end time, title, and full stop list.
- Works with Apple Calendar, Google Calendar imports, Outlook, and compatible apps.
- Requires an explicit Explorer download and calendar confirmation.
- Creates no external connection, account permission, server write, or background event.

TN GAME OS 5.125.0 — PRIVATE ADVENTURE SCHEDULING

CURRENT MILESTONE
- Adds an optional date to any active Saved Adventure.
- Supports explicit save, change, and clear actions.
- Validates dates server-side and accepts only today or a future date.
- Includes the chosen date in the printable itinerary.
- Keeps scheduling private with no automatic calendar event, reminder, or background write.

TN GAME OS 5.124.0 — PRINTABLE SAVED ADVENTURES

CURRENT MILESTONE
- Prints or saves one Saved Adventure as a clean PDF from the browser.
- Includes the full saved stop list, start time, and travel buffer.
- Excludes account controls, other plans, progress, and private identifiers.
- Opens only after an explicit Explorer action and performs no server write.
- Restores the normal library view automatically after printing.

TN GAME OS 5.123.0 — DEVICE VIEW PREFERENCES

CURRENT MILESTONE
- Remembers the selected Saved Adventure status filter and sort order on the device.
- Restores the preferred organizer view on the next visit.
- Adds one-tap Reset View to return to All and Recently Updated.
- Stores only generic view names, never plan, account, stop, or progress data.
- Falls back cleanly when private browsing blocks local storage.

TN GAME OS 5.122.0 — SAVED ADVENTURE CAPACITY SAFETY

CURRENT MILESTONE
- Shows active and archived Saved Adventure capacity before the library fills.
- Blocks new saves and duplicates at the active-plan limit instead of silently dropping an older plan.
- Lets archiving free an active slot while keeping up to 24 reversible archived plans.
- Blocks restores when all active slots are occupied and explains how to make room.
- Performs every capacity check on the server before changing Trips or plan data.

TN GAME OS 5.121.0 — REVERSIBLE ADVENTURE ARCHIVE

CURRENT MILESTONE
- Archives Saved Adventures without permanently deleting them.
- Restores archived plans from a dedicated Archived filter.
- Prevents the currently active adventure from being archived.
- Keeps archived plans private and removes them from the default library view.
- Requires explicit Explorer actions and performs no bulk or background changes.

TN GAME OS 5.120.0 — SAVED ADVENTURE SORTING

CURRENT MILESTONE
- Sorts Saved Adventures by recently updated, plan title, or adventure status.
- Keeps Active plans first when sorting by status, followed by Ready and Completed.
- Combines sorting with the existing search and status filters.
- Reorders only already-rendered private cards on the Explorer's device.
- Performs no server requests, database writes, or background activity.

TN GAME OS 5.119.0 — SAVED ADVENTURE ORGANIZER

CURRENT MILESTONE
- Searches private Saved Adventures by visible plan and stop names.
- Filters plans by Active, Ready, and Completed status.
- Updates results instantly on the Explorer's device without a server request.
- Adds a clear no-results state and mobile-friendly filter controls.
- Creates no database writes, new public data, or background activity.

TN GAME OS 5.118.0 — PRIVATE-SAFE ADVENTURE SHARING

CURRENT MILESTONE
- Shares a Saved Adventure only after an explicit Explorer action.
- Uses the native device share sheet when available and clipboard fallback otherwise.
- Includes only the plan title, visible stop names, and public TN Game homepage.
- Excludes account IDs, plan IDs, private recap links, progress, and profile data.
- Performs no server write and creates no public copy of the private plan.

TN GAME OS 5.117.0 — COMPLETED SAVED ADVENTURES

CURRENT MILESTONE
- Marks Saved Adventures that have been completed and archived.
- Links each completed plan to its latest private Adventure Recap.
- Offers Start Again without changing or deleting the previous recap.
- Keeps Active, Completed, and reusable plan states visually distinct.
- Derives status from existing private trip history without background writes.

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
