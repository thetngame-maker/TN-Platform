(() => {
  'use strict';
  const root = document.querySelector('[data-tng-adventure-library]');
  if (!root) return;
  const status = root.querySelector('[data-tng-library-status]');
  const search = root.querySelector('[data-tng-adventure-search]');
  const sort = root.querySelector('[data-tng-adventure-sort]');
  const grid = root.querySelector('.tng-adventure-library__grid');
  const filters = [...root.querySelectorAll('[data-tng-adventure-filter]')];
  const cards = [...root.querySelectorAll('[data-plan-id]')];
  const filterStatus = root.querySelector('[data-tng-filter-status]');
  const filterEmpty = root.querySelector('[data-tng-filter-empty]');
  const conflictSummary = root.querySelector('[data-tng-conflict-summary]');
  const prepOverview = root.querySelector('[data-tng-prep-overview]');
  const prepFocus = prepOverview?.querySelector('[data-tng-prep-focus]');
  const prepFilters = prepOverview ? [...prepOverview.querySelectorAll('[data-tng-prep-filter]')] : [];
  const upcomingCalendar = root.querySelector('[data-tng-upcoming-calendar]');
  const resetView = root.querySelector('[data-tng-adventure-reset]');
  const preferenceKey = 'tng_saved_adventure_view_v1';
  const allowedFilters = ['all','upcoming','needs-prep','launch-ready','active','ready','completed','archived'];
  const allowedSorts = ['recent','prep','date','title','status'];
  let selectedFilter = 'all';
  let nextCard = null;
  let priorityPrep = null;
  let planNavigationRequest = 0;
  const now = new Date();
  const todayKey = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`;

  const adventureDraftFields = [...root.querySelectorAll('[data-tng-plan-notes] textarea[name="notes"], [data-tng-plan-rename] input[name="title"], [data-tng-plan-schedule] input[name="planned_date"]')];
  let updateDraftReview;
  let syncScheduleRefresh;
  const isAdventureDraftChanged = (field) => field.value !== field.defaultValue || Boolean(field.validity?.badInput);
  const isAdventureDraftPending = (field) => {
    const form = field.closest('[data-tng-plan-notes],[data-tng-plan-rename],[data-tng-plan-schedule]');
    return Boolean(form?.querySelector('button[type="submit"]')?.disabled || form?.querySelector('[data-tng-plan-clear-date]')?.disabled);
  };
  const hasUnsavedAdventureDrafts = () => adventureDraftFields.some((field) => isAdventureDraftChanged(field) || isAdventureDraftPending(field));
  const warnAboutUnsavedDrafts = (event) => {
    if (!hasUnsavedAdventureDrafts()) return;
    event.preventDefault();
    event.returnValue = true;
  };
  const syncDraftExitWarning = () => {
    if (hasUnsavedAdventureDrafts()) window.addEventListener('beforeunload', warnAboutUnsavedDrafts);
    else window.removeEventListener('beforeunload', warnAboutUnsavedDrafts);
    updateDraftReview?.();
    syncScheduleRefresh?.();
  };
  syncDraftExitWarning();
  window.addEventListener('pageshow', syncDraftExitWarning);

  const scheduleWindows = cards.map((card) => {
    if (card.dataset.planState === 'archived' || !card.dataset.planDate || card.dataset.planDate < todayKey) return null;
    const parts = card.dataset.planDate.split('-').map(Number);
    if (parts.length !== 3 || parts.some((part) => !Number.isFinite(part))) return null;
    const start = new Date(parts[0],parts[1]-1,parts[2]).getTime() + Number(card.dataset.planStart || 0) * 60000;
    return {card,start,end:start + Math.max(1,Number(card.dataset.planDuration || 1)) * 60000,title:card.querySelector('[data-plan-title]')?.textContent?.trim() || 'another adventure'};
  }).filter(Boolean);
  const conflicts = new Map();
  if (upcomingCalendar) upcomingCalendar.hidden = scheduleWindows.length === 0;
  scheduleWindows.forEach((plan, index) => scheduleWindows.slice(index + 1).forEach((other) => {
    if (plan.start >= other.end || other.start >= plan.end) return;
    if (!conflicts.has(plan.card)) conflicts.set(plan.card,new Set());
    if (!conflicts.has(other.card)) conflicts.set(other.card,new Set());
    conflicts.get(plan.card).add(other.title);
    conflicts.get(other.card).add(plan.title);
  }));
  conflicts.forEach((titles, card) => {
    const names = [...titles];
    const warning = card.querySelector('[data-tng-plan-conflict]');
    card.classList.add('has-schedule-conflict');
    warning.querySelector('[data-tng-conflict-detail]').textContent = `Overlaps ${names.slice(0,2).join(', ')}${names.length > 2 ? ` +${names.length-2} more` : ''}`;
    warning.hidden = false;
  });
  if (conflictSummary && conflicts.size) {
    conflictSummary.textContent = `${conflicts.size} scheduled adventure${conflicts.size === 1 ? '' : 's'} overlap. Review their times before starting.`;
    conflictSummary.hidden = false;
  }

  try {
    const preferences = JSON.parse(window.localStorage.getItem(preferenceKey) || '{}');
    if (allowedFilters.includes(preferences.filter)) selectedFilter = preferences.filter;
    if (sort && allowedSorts.includes(preferences.sort)) sort.value = preferences.sort;
  } catch (error) { /* Private browsing can disable local storage. */ }

  const savePreferences = () => {
    try { window.localStorage.setItem(preferenceKey, JSON.stringify({filter:selectedFilter,sort:sort?.value || 'recent'})); }
    catch (error) { /* The organizer remains usable without storage. */ }
  };
  const syncFilterControls = () => {
    filters.forEach((item) => item.setAttribute('aria-pressed', String(item.dataset.tngAdventureFilter === selectedFilter)));
    prepFilters.forEach((item) => item.setAttribute('aria-pressed', String(item.dataset.tngPrepFilter === selectedFilter)));
  };

  const updateCardLaunchStatus = (card) => {
    const panel = card.querySelector('[data-tng-plan-launch-score]');
    if (!panel) return;
    const ready = Math.min(4,Math.max(0,Number(card.dataset.planReadyCount || 0)));
    const packed = Math.min(6,Math.max(0,Number(card.dataset.planPackedCount || 0)));
    const complete = ready + packed;
    panel.querySelector('[data-tng-plan-launch-label]').textContent = complete === 10 ? 'Launch ready' : `${complete} of 10 complete`;
    panel.querySelector('[data-tng-plan-launch-progress]').style.width = `${complete * 10}%`;
    panel.classList.toggle('is-complete',complete === 10);
  };

  const launchCountFor = (card) => Math.min(10,Math.max(0,Number(card.dataset.planReadyCount || 0) + Number(card.dataset.planPackedCount || 0)));
  const isUpcomingPrepCard = (card) => card.dataset.planState !== 'archived' && card.dataset.planDate >= todayKey;
  const startMinutesFor = (card) => {
    const minutes = Number(card.dataset.planStart?.trim() || 600);
    return Number.isInteger(minutes) && minutes >= 0 && minutes <= 1439 ? minutes : 600;
  };
  const comparePlanSchedule = (a, b) => (a.dataset.planDate || '').localeCompare(b.dataset.planDate || '') || startMinutesFor(a) - startMinutesFor(b);
  const launchReadyStatusFor = (card, previousCount, fallback) => {
    if (isUpcomingPrepCard(card) && previousCount < 10 && launchCountFor(card) === 10) {
      const title = card.querySelector('[data-plan-title]')?.textContent?.trim() || 'This adventure';
      return `${title} is launch ready. All 10 preparation checks are complete.`;
    }
    return fallback || 'Checklist updated.';
  };
  const nextIncompleteCheckFor = (card) => [...card.querySelectorAll('[data-tng-readiness-key],[data-tng-packing-key]')].find((item) => !item.checked) || null;
  const updatePrepOverview = () => {
    if (!prepOverview) return;
    const upcoming = cards.filter((card) => isUpcomingPrepCard(card));
    const needsPrep = upcoming.filter((card) => launchCountFor(card) < 10);
    const launchReady = upcoming.filter((card) => launchCountFor(card) === 10);
    prepOverview.querySelector('[data-tng-prep-upcoming]').textContent = String(upcoming.length);
    prepOverview.querySelector('[data-tng-prep-needed]').textContent = String(needsPrep.length);
    prepOverview.querySelector('[data-tng-prep-ready]').textContent = String(launchReady.length);
    const priority = [...needsPrep].sort((a,b) => comparePlanSchedule(a,b) || launchCountFor(a)-launchCountFor(b))[0] || null;
    const priorityText = prepOverview.querySelector('[data-tng-prep-priority]');
    if (priority) {
      const title = priority.querySelector('[data-plan-title]')?.textContent?.trim() || 'Your next adventure';
      const remaining = 10 - launchCountFor(priority);
      priorityText.textContent = `${title} is the closest plan needing attention · ${remaining} check${remaining === 1 ? '' : 's'} remaining.`;
      const checkbox = nextIncompleteCheckFor(priority);
      priorityPrep = checkbox ? {card:priority,checkbox} : null;
      if (prepFocus) {
        if (priorityPrep) {
          const label = checkbox.closest('label')?.querySelector('span')?.textContent?.trim() || 'next launch check';
          prepFocus.textContent = `Review: ${label}`;
          prepFocus.hidden = false;
        } else prepFocus.hidden = true;
      }
    } else {
      priorityPrep = null;
      priorityText.textContent = upcoming.length ? 'Every upcoming adventure has all launch checks complete.' : '';
      if (prepFocus) prepFocus.hidden = true;
    }
    prepOverview.hidden = upcoming.length === 0;
  };

  const applyFilters = () => {
    const stateOrder = {active:0,ready:1,completed:2,archived:3};
    const sorted = [...cards].sort((a, b) => {
      if (sort?.value === 'title') return (a.querySelector('[data-plan-title]')?.textContent || '').localeCompare(b.querySelector('[data-plan-title]')?.textContent || '', undefined, {sensitivity:'base'});
      if (sort?.value === 'status') return (stateOrder[a.dataset.planState] ?? 9) - (stateOrder[b.dataset.planState] ?? 9) || Number(b.dataset.planUpdated || 0) - Number(a.dataset.planUpdated || 0);
      if (sort?.value === 'prep') {
        const aUpcoming = isUpcomingPrepCard(a);
        const bUpcoming = isUpcomingPrepCard(b);
        const aLaunch = launchCountFor(a);
        const bLaunch = launchCountFor(b);
        const aRank = aUpcoming ? (aLaunch < 10 ? 0 : 1) : (a.dataset.planState === 'archived' ? 3 : 2);
        const bRank = bUpcoming ? (bLaunch < 10 ? 0 : 1) : (b.dataset.planState === 'archived' ? 3 : 2);
        if (aRank !== bRank) return aRank - bRank;
        if (aRank < 2) {
          const dateOrder = comparePlanSchedule(a,b);
          if (dateOrder !== 0) return dateOrder;
          if (aRank === 0 && aLaunch !== bLaunch) return aLaunch - bLaunch;
        }
        return Number(b.dataset.planUpdated || 0) - Number(a.dataset.planUpdated || 0);
      }
      if (sort?.value === 'date') {
        const aDate = a.dataset.planDate || '';
        const bDate = b.dataset.planDate || '';
        const aRank = !aDate ? 2 : (aDate >= todayKey ? 0 : 1);
        const bRank = !bDate ? 2 : (bDate >= todayKey ? 0 : 1);
        if (aRank !== bRank) return aRank - bRank;
        if (aRank === 0) return comparePlanSchedule(a,b);
        if (aRank === 1) return comparePlanSchedule(b,a);
      }
      return Number(b.dataset.planUpdated || 0) - Number(a.dataset.planUpdated || 0);
    });
    if (grid) sorted.forEach((card) => grid.append(card));
    const query = search?.value.trim().toLocaleLowerCase() || '';
    let visible = 0;
    cards.forEach((card) => {
      const launchCount = launchCountFor(card);
      const upcoming = isUpcomingPrepCard(card);
      const matchesState = selectedFilter === 'all' ? card.dataset.planState !== 'archived' : (selectedFilter === 'upcoming' ? upcoming : (selectedFilter === 'needs-prep' ? upcoming && launchCount < 10 : (selectedFilter === 'launch-ready' ? upcoming && launchCount === 10 : card.dataset.planState === selectedFilter)));
      const matchesQuery = !query || card.textContent.toLocaleLowerCase().includes(query);
      card.hidden = !(matchesState && matchesQuery);
      if (!card.hidden) visible += 1;
    });
    if (filterStatus) filterStatus.textContent = `${visible} of ${cards.length} adventure${cards.length === 1 ? '' : 's'} shown`;
    if (filterEmpty) filterEmpty.hidden = visible !== 0;
  };
  const refreshPrepViews = () => {
    updatePrepOverview();
    if (selectedFilter === 'needs-prep' || selectedFilter === 'launch-ready' || sort?.value === 'prep') applyFilters();
  };

  search?.addEventListener('input', applyFilters);
  root.addEventListener('input', (event) => {
    syncDraftExitWarning();
    const notes = event.target.closest('[data-tng-plan-notes] textarea');
    if (!notes) return;
    const count = notes.closest('[data-tng-plan-notes]').querySelector('[data-tng-notes-count]');
    if (count) count.textContent = notes.value.length + ' of 600';
    const notesState = notes.closest('[data-tng-plan-notes-panel]')?.querySelector('[data-tng-notes-state]');
    if (notesState) notesState.textContent = notes.value !== notes.defaultValue ? 'Unsaved changes' : (notes.value.trim() === '' ? 'Optional' : 'Saved');
  });
  sort?.addEventListener('change', () => { savePreferences(); applyFilters(); });
  filters.forEach((button) => button.addEventListener('click', () => {
    selectedFilter = button.dataset.tngAdventureFilter || 'all';
    syncFilterControls();
    savePreferences();
    applyFilters();
  }));
  resetView?.addEventListener('click', () => {
    selectedFilter = 'all';
    if (sort) sort.value = 'recent';
    if (search) search.value = '';
    syncFilterControls();
    try { window.localStorage.removeItem(preferenceKey); } catch (error) { /* No storage to reset. */ }
    applyFilters();
  });
  syncFilterControls();
  applyFilters();
  updatePrepOverview();
  const scheduleRefresh = root.querySelector('[data-tng-schedule-refresh]');
  const scheduleRefreshMessage = scheduleRefresh?.querySelector('[data-tng-schedule-refresh-message]');
  const scheduleRefreshMessageId = scheduleRefreshMessage?.id || '';
  const scheduleReviewButton = scheduleRefresh?.querySelector('[data-tng-schedule-review-button]');
  const scheduleRefreshButton = scheduleRefresh?.querySelector('[data-tng-schedule-refresh-button]');
  let scheduleRefreshNeeded = false;
  const scheduleDependentSelector = '[data-tng-readiness-key],[data-tng-packing-key],[data-tng-prep-focus],[data-tng-next-action],[data-tng-plan-start],[data-tng-plan-calendar],[data-tng-upcoming-calendar],[data-tng-plan-print]';
  const hasReviewableScheduleDraft = () => adventureDraftFields.some((field) => isAdventureDraftChanged(field) && field.isConnected && !field.disabled && field.closest('[data-plan-id]')?.isConnected);
  syncScheduleRefresh = () => {
    if (scheduleRefreshNeeded) root.querySelectorAll(scheduleDependentSelector).forEach((control) => {
      control.setAttribute('aria-disabled','true');
      control.classList.add('is-schedule-paused');
      if (scheduleRefreshMessageId) {
        const descriptions = (control.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        if (!descriptions.includes(scheduleRefreshMessageId)) control.setAttribute('aria-describedby',[...descriptions,scheduleRefreshMessageId].join(' '));
      }
    });
    if (!scheduleRefresh) return;
    const dirtyCount = adventureDraftFields.filter(isAdventureDraftChanged).length;
    const pendingCount = adventureDraftFields.filter(isAdventureDraftPending).length;
    const hasDrafts = dirtyCount > 0 || pendingCount > 0;
    const canReview = hasReviewableScheduleDraft();
    scheduleRefresh.hidden = !scheduleRefreshNeeded;
    scheduleRefresh.setAttribute('aria-busy',String(scheduleRefreshNeeded && pendingCount > 0));
    if (scheduleReviewButton) {
      scheduleReviewButton.hidden = !scheduleRefreshNeeded || !hasDrafts;
      scheduleReviewButton.disabled = !canReview;
      scheduleReviewButton.textContent = canReview ? `Review remaining edit · ${dirtyCount}` : `Waiting for ${pendingCount} save${pendingCount === 1 ? '' : 's'}`;
    }
    if (scheduleRefreshButton) scheduleRefreshButton.disabled = hasDrafts;
    const remaining = [dirtyCount ? `${dirtyCount} unsaved field${dirtyCount === 1 ? '' : 's'}` : '',pendingCount ? `${pendingCount} save${pendingCount === 1 ? '' : 's'} in progress` : ''].filter(Boolean).join(' and ');
    const remainingVerb = dirtyCount + pendingCount === 1 ? 'remains' : 'remain';
    const message = (hasDrafts ? `${remaining} ${remainingVerb} on this page. ${dirtyCount ? 'Save or revert remaining edits, then refresh to update preparation details and calendar exports.' : 'Wait for the current save to finish, then refresh preparation details and calendar exports.'}` : 'Refresh to use the saved date in preparation details and calendar exports.') + ' Preparation actions, adventure starts, calendar exports, and printing are paused until then.';
    if (scheduleRefreshMessage && scheduleRefreshMessage.textContent !== message) scheduleRefreshMessage.textContent = message;
  };
  const requestScheduleRefresh = () => {
    scheduleRefreshNeeded = true;
    syncScheduleRefresh();
    if (!hasUnsavedAdventureDrafts()) window.location.reload();
  };
  scheduleRefreshButton?.addEventListener('click', () => {
    syncScheduleRefresh();
    if (libraryUpdatePending) {
      if (status) status.textContent = 'Another adventure update is still saving. Wait for it to finish, then refresh.';
      return;
    }
    if (scheduleRefreshNeeded && !hasUnsavedAdventureDrafts()) window.location.reload();
  });
  const guardStaleScheduleAction = (event) => {
    if (!scheduleRefreshNeeded) return;
    const control = event.target.closest(scheduleDependentSelector);
    if (!control) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (event.type === 'change' && typeof control.checked === 'boolean') control.checked = !control.checked;
    syncScheduleRefresh();
    if (status) status.textContent = 'Refresh the saved schedule before using preparation actions, starting an adventure, exporting a calendar, or printing. Save or revert remaining edits first.';
    scheduleRefresh?.scrollIntoView({block:'nearest'});
    const focusTarget = !hasUnsavedAdventureDrafts() && !libraryUpdatePending ? scheduleRefreshButton : (hasReviewableScheduleDraft() ? scheduleReviewButton : scheduleRefresh);
    focusTarget?.focus({preventScroll:true});
  };
  ['click','change'].forEach((type) => root.addEventListener(type, guardStaleScheduleAction, true));
  syncScheduleRefresh();
  const draftReview = root.querySelector('[data-tng-draft-review]');
  const draftReviewComplete = root.querySelector('[data-tng-draft-review-complete]');
  const draftReviewCompleteMessage = draftReviewComplete?.querySelector('[data-tng-draft-review-complete-message]');
  const draftReviewCompleteDismiss = draftReviewComplete?.querySelector('[data-tng-draft-review-complete-dismiss]');
  const draftReviewCount = draftReview?.querySelector('[data-tng-draft-review-count]');
  const draftReviewTypes = draftReview?.querySelector('[data-tng-draft-review-types]');
  const draftReviewPosition = draftReview?.querySelector('[data-tng-draft-review-position]');
  const draftReviewPositionId = draftReviewPosition?.id || '';
  const draftReviewTypeActions = draftReview?.querySelector('[data-tng-draft-review-type-actions]');
  const draftReviewTypeButtons = draftReview ? [...draftReview.querySelectorAll('[data-tng-draft-review-type]')] : [];
  const draftReviewButton = draftReview?.querySelector('[data-tng-draft-review-next]');
  let lastReviewedDraft = null;
  let describedDraft = null;
  let lastReviewedDraftScope = '';
  let hadDraftReviewActivity = false;
  const draftTypeFor = (field) => ({title:'name',notes:'note',planned_date:'date'}[field.name] || 'edit');
  const reviewableDrafts = () => adventureDraftFields.filter((field) => isAdventureDraftChanged(field) && field.isConnected && !field.disabled && field.closest('[data-plan-id]')?.isConnected);
  const nextDraftReviewTarget = (fieldName = '') => {
    const drafts = reviewableDrafts().filter((field) => !fieldName || field.name === fieldName);
    if (!drafts.length) return null;
    const index = (drafts.indexOf(lastReviewedDraft) + 1) % drafts.length;
    return {field:drafts[index],index,total:drafts.length};
  };
  const removeDraftReviewDescription = (field) => {
    if (!field || !draftReviewPositionId) return;
    const descriptions = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter((id) => id && id !== draftReviewPositionId);
    if (descriptions.length) field.setAttribute('aria-describedby',descriptions.join(' '));
    else field.removeAttribute('aria-describedby');
  };
  const clearDraftReviewPosition = () => {
    removeDraftReviewDescription(describedDraft);
    describedDraft = null;
    lastReviewedDraftScope = '';
    if (draftReviewPosition) {
      draftReviewPosition.textContent = '';
      draftReviewPosition.hidden = true;
    }
  };
  const syncDraftReviewPosition = () => {
    if (!describedDraft) { clearDraftReviewPosition(); return; }
    const drafts = reviewableDrafts().filter((field) => !lastReviewedDraftScope || field.name === lastReviewedDraftScope);
    const index = drafts.indexOf(describedDraft);
    if (index < 0) { clearDraftReviewPosition(); return; }
    if (draftReviewPosition) {
      const type = draftTypeFor(describedDraft);
      draftReviewPosition.textContent = `Reviewing ${type} · ${index + 1} of ${drafts.length} in this ${lastReviewedDraftScope ? 'type' : 'page'} review.`;
      draftReviewPosition.hidden = false;
    }
    if (draftReviewPositionId) {
      const descriptions = (describedDraft.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
      if (!descriptions.includes(draftReviewPositionId)) describedDraft.setAttribute('aria-describedby',[...descriptions,draftReviewPositionId].join(' '));
    }
  };
  updateDraftReview = () => {
    const changed = adventureDraftFields.filter(isAdventureDraftChanged);
    const pending = adventureDraftFields.filter(isAdventureDraftPending).length;
    const hasDraftActivity = changed.length > 0 || pending > 0;
    if (hasDraftActivity) {
      hadDraftReviewActivity = true;
      if (draftReviewComplete) {
        if (draftReviewCompleteMessage) draftReviewCompleteMessage.textContent = '';
        draftReviewComplete.hidden = true;
      }
    } else if (hadDraftReviewActivity) {
      if (draftReviewComplete) {
        if (draftReviewCompleteMessage) draftReviewCompleteMessage.textContent = 'All edits on this page are saved.';
        draftReviewComplete.hidden = false;
      }
      hadDraftReviewActivity = false;
    }
    if (!draftReview || !draftReviewCount) return;
    const planCount = new Set(changed.map((field) => field.closest('[data-plan-id]'))).size;
    const edits = changed.length ? `${changed.length} unsaved field${changed.length === 1 ? '' : 's'} across ${planCount} adventure${planCount === 1 ? '' : 's'}.` : '';
    const saving = pending ? `${pending} save${pending === 1 ? '' : 's'} in progress.` : '';
    const message = [edits,saving].filter(Boolean).join(' ');
    if (draftReviewCount.textContent !== message) draftReviewCount.textContent = message;
    if (draftReviewTypes) {
      const typeCounts = changed.reduce((counts,field) => {
        const type = draftTypeFor(field);
        counts[type] = (counts[type] || 0) + 1;
        return counts;
      },{});
      const typeSummary = ['name','note','date','edit'].filter((type) => typeCounts[type]).map((type) => `${typeCounts[type]} ${type}${typeCounts[type] === 1 ? '' : 's'}`).join(' · ');
      draftReviewTypes.textContent = typeSummary;
      draftReviewTypes.hidden = !typeSummary;
    }
    let visibleTypeActions = 0;
    draftReviewTypeButtons.forEach((button) => {
      const target = nextDraftReviewTarget(button.dataset.tngDraftReviewType || '');
      const type = target ? draftTypeFor(target.field) : '';
      button.hidden = !target;
      button.disabled = !target;
      if (target) {
        button.textContent = `Review ${type} · ${target.index + 1} of ${target.total}`;
        visibleTypeActions += 1;
      }
    });
    if (draftReviewTypeActions) draftReviewTypeActions.hidden = visibleTypeActions === 0;
    draftReview.hidden = !changed.length && !pending;
    syncDraftReviewPosition();
    if (!changed.length) lastReviewedDraft = null;
    if (draftReviewButton) {
      const target = nextDraftReviewTarget();
      const kind = target ? ({notes:'notes',title:'name',planned_date:'date'}[target.field.name] || 'edit') : '';
      draftReviewButton.disabled = !target;
      draftReviewButton.textContent = target ? `Review ${kind} · ${target.index + 1} of ${target.total}` : 'Review unsaved edit';
    }
  };
  const reviewDraft = (fieldName = '') => {
    const target = nextDraftReviewTarget(fieldName);
    if (!target) { updateDraftReview(); return; }
    const field = target.field;
    ++planNavigationRequest;
    cards.forEach((item) => item.classList.remove('is-prep-focus'));
    if (search) search.value = '';
    selectedFilter = 'all';
    syncFilterControls();
    applyFilters();
    const notesPanel = field.closest('[data-tng-plan-notes-panel]');
    if (notesPanel) notesPanel.open = true;
    if (describedDraft !== field) removeDraftReviewDescription(describedDraft);
    lastReviewedDraft = field;
    describedDraft = field;
    lastReviewedDraftScope = fieldName;
    syncDraftReviewPosition();
    field.focus({preventScroll:true});
    field.scrollIntoView({block:'center'});
    updateDraftReview();
  };
  draftReviewCompleteDismiss?.addEventListener('click', () => {
    if (draftReviewCompleteMessage) draftReviewCompleteMessage.textContent = '';
    if (draftReviewComplete) draftReviewComplete.hidden = true;
  });
  draftReviewButton?.addEventListener('click', () => reviewDraft());
  draftReviewTypeButtons.forEach((button) => button.addEventListener('click', () => reviewDraft(button.dataset.tngDraftReviewType || '')));
  scheduleReviewButton?.addEventListener('click', () => {
    if (scheduleReviewButton.disabled || !draftReviewButton || draftReviewButton.disabled) { syncScheduleRefresh(); return; }
    draftReviewButton.click();
    syncScheduleRefresh();
  });
  updateDraftReview();
  prepOverview?.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-tng-prep-filter]');
    if (!trigger) return;
    const filter = filters.find((item) => item.dataset.tngAdventureFilter === trigger.dataset.tngPrepFilter);
    if (filter) {
      filter.click();
      root.querySelector('.tng-adventure-library__organizer')?.scrollIntoView({behavior:'smooth',block:'start'});
    }
  });
  prepFocus?.addEventListener('click', () => {
    if (!priorityPrep) return;
    const target = priorityPrep;
    const request = ++planNavigationRequest;
    cards.forEach((card) => card.classList.remove('is-prep-focus'));
    if (search) search.value = '';
    selectedFilter = 'needs-prep';
    syncFilterControls();
    savePreferences();
    applyFilters();
    target.card.classList.add('is-prep-focus');
    target.card.scrollIntoView({behavior:'smooth',block:'center'});
    const focusOrigin = document.activeElement;
    window.setTimeout(() => {
      if (request !== planNavigationRequest) return;
      target.card.classList.remove('is-prep-focus');
      if (priorityPrep?.card !== target.card || priorityPrep?.checkbox !== target.checkbox) return;
      if (!target.card.isConnected || target.card.hidden || !target.checkbox.isConnected || target.checkbox.hidden || target.checkbox.disabled || target.checkbox.checked) return;
      if (document.activeElement === focusOrigin) target.checkbox.focus({preventScroll:true});
    },450);
  });

  const adventurePacks = cards.map((card) => {
    const panel = card.querySelector('[data-tng-adventure-offline]');
    if (!panel) return null;
    try {
      const urls = JSON.parse(panel.querySelector('[data-tng-offline-urls]')?.textContent || '[]');
      return {panel,id:panel.dataset.tngOfflinePack || '',urls:Array.isArray(urls) ? urls.slice(0,12) : []};
    } catch (error) { return null; }
  }).filter((pack) => pack && pack.id && pack.urls.length);

  const messageOfflineWorker = async (payload) => {
    const ready = await navigator.serviceWorker.ready;
    const worker = ready.active || ready.waiting || ready.installing;
    if (!worker) throw new Error('Offline worker is not ready.');
    return await new Promise((resolve, reject) => {
      const channel = new MessageChannel();
      const timer = window.setTimeout(() => reject(new Error('Offline worker timed out.')), 30000);
      channel.port1.onmessage = (event) => { window.clearTimeout(timer); resolve(event.data || {}); };
      worker.postMessage(payload,[channel.port2]);
    });
  };

  const initializeAdventurePacks = async () => {
    if (!adventurePacks.length || !('serviceWorker' in navigator)) return;
    try {
      const response = await messageOfflineWorker({type:'TNG_ADVENTURE_PACK_STATUS',packs:adventurePacks.map((pack) => ({id:pack.id,urls:pack.urls}))});
      adventurePacks.forEach((pack) => {
        const state = pack.panel.querySelector('[data-tng-adventure-offline-state]');
        const save = pack.panel.querySelector('[data-tng-adventure-offline-save]');
        const remove = pack.panel.querySelector('[data-tng-adventure-offline-remove]');
        const render = (info) => {
          const count = Number(typeof info === 'object' ? info?.count : info) || 0;
          const current = typeof info !== 'object' || info?.current !== false;
          const stale = count > 0 && current && typeof info === 'object' && info?.stale === true;
          const verifiedDate = typeof info === 'object' && info?.verifiedAt ? new Date(info.verifiedAt) : null;
          const verifiedLabel = verifiedDate && Number.isFinite(verifiedDate.getTime()) ? ` · verified ${Date.now() - verifiedDate.getTime() < 86400000 ? 'today' : verifiedDate.toLocaleDateString(undefined,{month:'short',day:'numeric'})}` : '';
          pack.panel.classList.toggle('needs-update', count > 0 && !current);
          pack.panel.classList.toggle('needs-refresh', stale);
          state.textContent = count > 0 ? (!current ? `Update available · ${count} screen${count === 1 ? '' : 's'} saved${verifiedLabel}` : (stale ? `Refresh recommended · ${count} screen${count === 1 ? '' : 's'} saved${verifiedLabel}` : `${count} public stop screen${count === 1 ? '' : 's'} saved${verifiedLabel}`)) : 'Not downloaded';
          save.textContent = count > 0 ? (!current ? 'Update' : 'Refresh') : 'Download';
          remove.hidden = count < 1;
        };
        pack.panel.hidden = false;
        render(response.installed?.[pack.id] || 0);
        save.addEventListener('click', async () => {
          if (!navigator.onLine) { state.textContent = 'Connect to download public stop screens'; return; }
          save.disabled = true; remove.disabled = true; state.textContent = 'Downloading public stop screens…';
          try {
            const result = await messageOfflineWorker({type:'TNG_ADVENTURE_PACK_SAVE',id:pack.id,urls:pack.urls});
            render(result.installed?.[pack.id] || result.saved || 0);
            if (!result.ok) {
              const preserved = Number(result.preserved || 0);
              state.textContent = result.error === 'low-storage' ? (preserved ? `Storage low · ${preserved} previous screen${preserved === 1 ? '' : 's'} kept` : 'Storage low · remove another offline pack first') : (preserved ? `Update incomplete · ${preserved} previous screen${preserved === 1 ? '' : 's'} kept` : `${result.failed || 0} public screen${Number(result.failed || 0) === 1 ? '' : 's'} unavailable`);
            }
          } catch (error) { state.textContent = 'Could not download this adventure'; }
          save.disabled = false; remove.disabled = false;
        });
        remove.addEventListener('click', async () => {
          save.disabled = true; remove.disabled = true; state.textContent = 'Removing public stop screens…';
          try { await messageOfflineWorker({type:'TNG_ADVENTURE_PACK_REMOVE',id:pack.id}); render(0); }
          catch (error) { state.textContent = 'Could not remove this adventure pack'; }
          save.disabled = false; remove.disabled = false;
        });
      });
    } catch (error) { /* Offline controls remain hidden when the worker is unavailable. */ }
  };
  window.addEventListener('load', initializeAdventurePacks, {once:true});

  const nextBanner = root.querySelector('[data-tng-next-adventure]');
  nextCard = cards.filter((card) => isUpcomingPrepCard(card)).sort(comparePlanSchedule)[0] || null;
  const updateNextLaunchStatus = () => {
    if (!nextBanner || !nextCard) return;
    const ready = Math.min(4,Math.max(0,Number(nextCard.dataset.planReadyCount || 0)));
    const packed = Math.min(6,Math.max(0,Number(nextCard.dataset.planPackedCount || 0)));
    const complete = ready + packed;
    const remaining = 10 - complete;
    nextBanner.querySelector('[data-tng-next-readiness]').textContent = `${ready} of 4 readiness checks complete`;
    nextBanner.querySelector('[data-tng-next-packing]').textContent = `${packed} of 6 packing checks complete`;
    nextBanner.querySelector('[data-tng-next-launch-progress]').style.width = `${complete * 10}%`;
    nextBanner.querySelector('[data-tng-next-launch-label]').textContent = remaining === 0 ? 'Launch checks complete' : `${remaining} launch check${remaining === 1 ? '' : 's'} remaining`;
    nextBanner.querySelector('[data-tng-next-launch-status]').classList.toggle('is-complete',remaining === 0);
  };
  if (nextBanner && nextCard) {
    const parts = nextCard.dataset.planDate.split('-').map(Number);
    const targetDate = new Date(parts[0],parts[1]-1,parts[2],12,0,0);
    const today = new Date(now.getFullYear(),now.getMonth(),now.getDate(),12,0,0);
    const daysAway = Math.max(0,Math.round((targetDate-today)/86400000));
    nextBanner.querySelector('[data-tng-next-title]').textContent = nextCard.querySelector('[data-plan-title]')?.textContent?.trim() || 'Tennessee adventure';
    nextBanner.querySelector('[data-tng-next-countdown]').textContent = daysAway === 0 ? 'Today' : (daysAway === 1 ? 'Tomorrow' : `In ${daysAway} days`);
    nextBanner.querySelector('[data-tng-next-date]').textContent = targetDate.toLocaleDateString(undefined,{weekday:'long',month:'long',day:'numeric'});
    const timing = nextCard.querySelector('[data-tng-plan-timing]');
    nextBanner.querySelector('[data-tng-next-timing]').textContent = timing ? `${timing.querySelector('[data-tng-timing-start]').textContent}–${timing.querySelector('[data-tng-timing-finish]').textContent} · ${timing.querySelector('[data-tng-timing-duration]').textContent}` : '';
    updateNextLaunchStatus();
    nextBanner.hidden = false;
    nextCard.classList.add('is-next-up');
    const revealNextCard = () => {
      const target = nextCard;
      const request = ++planNavigationRequest;
      cards.forEach((card) => card.classList.remove('is-prep-focus'));
      if (search) search.value = '';
      selectedFilter = 'all';
      syncFilterControls();
      savePreferences();
      applyFilters();
      target.scrollIntoView({behavior:'smooth',block:'center'});
      const focusOrigin = document.activeElement;
      window.setTimeout(() => {
        if (request !== planNavigationRequest || nextCard !== target || !target.isConnected || target.hidden || document.activeElement !== focusOrigin) return;
        target.querySelector('button:not(:disabled),a[href]')?.focus({preventScroll:true});
      },450);
    };
    const routeLink = nextCard.querySelector('a[href*="adventure="]');
    const bannerRoute = nextBanner.querySelector('[data-tng-next-map]');
    if (routeLink && bannerRoute) { bannerRoute.href = routeLink.href; bannerRoute.hidden = false; }
    const activeLink = nextCard.querySelector('a[href*="/active-trip/"]');
    const startButton = nextCard.querySelector('[data-tng-plan-start]');
    const bannerAction = nextBanner.querySelector('[data-tng-next-action]');
    if (bannerAction) {
      if (activeLink) bannerAction.textContent = 'Resume adventure';
      else if (daysAway === 0 && startButton) bannerAction.textContent = "Start today's adventure";
      bannerAction.addEventListener('click', () => {
        if (activeLink) window.location.assign(activeLink.href);
        else if (daysAway === 0 && startButton) startButton.click();
        else revealNextCard();
      });
    }
  }

  let libraryUpdatePending = false;
  const post = async (fields) => {
    if (libraryUpdatePending) throw new Error('Another adventure update is still saving. Wait for it to finish, then try this change again.');
    libraryUpdatePending = true;
    try {
      const body = new URLSearchParams({action:'tng_adventure_library_action',nonce:root.dataset.nonce || '',...fields});
      const response = await fetch(root.dataset.ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body});
      const json = await response.json();
      if (!json.success) throw new Error(json.data?.message || 'Saved Adventures could not update that plan.');
      return json.data;
    } finally {
      libraryUpdatePending = false;
    }
  };

  const cleanupPrint = () => {
    document.body.classList.remove('tng-printing-adventure');
    cards.forEach((card) => card.classList.remove('is-print-target'));
  };
  window.addEventListener('afterprint', cleanupPrint);

  root.addEventListener('change', async (event) => {
    const packingCheckbox = event.target.closest('[data-tng-packing-key]');
    if (packingCheckbox) {
      const card = packingCheckbox.closest('[data-plan-id]');
      const fieldset = packingCheckbox.closest('[data-tng-plan-packing]');
      const previous = !packingCheckbox.checked;
      const previousLaunchCount = launchCountFor(card);
      packingCheckbox.disabled = true;
      try {
        const data = await post({operation:'packing',plan_id:card.dataset.planId || '',packing_key:packingCheckbox.dataset.tngPackingKey || '',checked:packingCheckbox.checked ? '1' : '0'});
        const count = [...fieldset.querySelectorAll('[data-tng-packing-key]')].filter((item) => item.checked).length;
        card.dataset.planPackedCount = String(count);
        updateCardLaunchStatus(card);
        fieldset.querySelector('[data-tng-packing-count]').textContent = count + ' of 6 packed';
        const printItem = card.querySelector(`[data-tng-print-packing="${packingCheckbox.dataset.tngPackingKey || ''}"]`);
        if (printItem) {
          printItem.classList.toggle('is-ready', packingCheckbox.checked);
          printItem.querySelector('span').textContent = packingCheckbox.checked ? '✓' : '○';
        }
        const printCount = card.querySelector('[data-tng-print-packing-count]');
        if (printCount) printCount.textContent = `Packing · ${count} of 6 complete`;
        if (card === nextCard) updateNextLaunchStatus();
        refreshPrepViews();
        if (status) status.textContent = launchReadyStatusFor(card,previousLaunchCount,data.message);
      } catch (error) {
        packingCheckbox.checked = previous;
        if (status) status.textContent = error.message;
      } finally { packingCheckbox.disabled = false; }
      return;
    }
    const checkbox = event.target.closest('[data-tng-readiness-key]');
    if (!checkbox) return;
    const card = checkbox.closest('[data-plan-id]');
    const fieldset = checkbox.closest('[data-tng-plan-readiness]');
    const previous = !checkbox.checked;
    const previousLaunchCount = launchCountFor(card);
    checkbox.disabled = true;
    try {
      const data = await post({operation:'readiness',plan_id:card.dataset.planId || '',readiness_key:checkbox.dataset.tngReadinessKey || '',checked:checkbox.checked ? '1' : '0'});
      const count = [...fieldset.querySelectorAll('[data-tng-readiness-key]')].filter((item) => item.checked).length;
      card.dataset.planReadyCount = String(count);
      updateCardLaunchStatus(card);
      fieldset.querySelector('[data-tng-readiness-count]').textContent = count + ' of 4 ready';
      const printItem = card.querySelector(`[data-tng-print-readiness="${checkbox.dataset.tngReadinessKey || ''}"]`);
      if (printItem) {
        printItem.classList.toggle('is-ready', checkbox.checked);
        printItem.querySelector('span').textContent = checkbox.checked ? '✓' : '○';
      }
      const printCount = card.querySelector('[data-tng-print-readiness-count]');
      if (printCount) printCount.textContent = `Readiness · ${count} of 4 complete`;
      if (card === nextCard) updateNextLaunchStatus();
      refreshPrepViews();
      if (status) status.textContent = launchReadyStatusFor(card,previousLaunchCount,data.message);
    } catch (error) {
      checkbox.checked = previous;
      if (status) status.textContent = error.message;
    } finally { checkbox.disabled = false; }
  });

  const calendarStamp = (date) => `${date.getFullYear()}${String(date.getMonth() + 1).padStart(2,'0')}${String(date.getDate()).padStart(2,'0')}T${String(date.getHours()).padStart(2,'0')}${String(date.getMinutes()).padStart(2,'0')}00`;
  const calendarEscape = (value) => String(value).replace(/\\/g,'\\\\').replace(/\n/g,'\\n').replace(/,/g,'\\,').replace(/;/g,'\\;');

  root.addEventListener('click', async (event) => {
    const exportUpcoming = event.target.closest('[data-tng-upcoming-calendar]');
    if (exportUpcoming) {
      const stamp = new Date().toISOString().replace(/[-:]/g,'').replace(/\.\d{3}Z$/,'Z');
      const events = scheduleWindows.flatMap((plan, index) => {
        const title = plan.card.querySelector('[data-plan-title]')?.textContent?.trim() || 'Tennessee adventure';
        const stops = [...plan.card.querySelectorAll('[data-tng-print-stops] li')].map((node) => node.textContent.trim()).filter(Boolean);
        return ['BEGIN:VEVENT',`UID:tn-game-upcoming-${index}-${plan.start}@thetngame.com`,`DTSTAMP:${stamp}`,`DTSTART:${calendarStamp(new Date(plan.start))}`,`DTEND:${calendarStamp(new Date(plan.end))}`,`SUMMARY:${calendarEscape(title)}`,`DESCRIPTION:${calendarEscape(`Stops: ${stops.join(' → ')}\nConfirm hours, tickets, trail conditions, and driving time before leaving.`)}`,`URL:${window.location.origin}/`,'END:VEVENT'];
      });
      const content = ['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//The TN Game//Upcoming Adventures//EN','CALSCALE:GREGORIAN',...events,'END:VCALENDAR'].join('\r\n');
      const url = URL.createObjectURL(new Blob([content],{type:'text/calendar;charset=utf-8'}));
      const link = document.createElement('a');
      link.href = url;
      link.download = 'tn-game-upcoming-adventures.ics';
      link.click();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
      if (status) status.textContent = `${scheduleWindows.length} upcoming adventure${scheduleWindows.length === 1 ? '' : 's'} exported. Open the file to confirm the events.`;
      return;
    }
    const calendar = event.target.closest('[data-tng-plan-calendar]');
    if (calendar) {
      const card = calendar.closest('[data-plan-id]');
      const parts = (card.dataset.planDate || '').split('-').map(Number);
      const startMinutes = Number(card.dataset.planStart || 600);
      const durationMinutes = Math.max(30, Number(card.dataset.planDuration || 240));
      if (parts.length !== 3 || parts.some((part) => !Number.isFinite(part))) return;
      const starts = new Date(parts[0],parts[1]-1,parts[2],Math.floor(startMinutes/60),startMinutes%60,0);
      const ends = new Date(starts.getTime()+durationMinutes*60000);
      const title = card.querySelector('[data-plan-title]')?.textContent?.trim() || 'Tennessee adventure';
      const stops = [...card.querySelectorAll('[data-tng-print-stops] li')].map((node) => node.textContent.trim()).filter(Boolean);
      const stamp = new Date().toISOString().replace(/[-:]/g,'').replace(/\.\d{3}Z$/,'Z');
      const content = ['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//The TN Game//Saved Adventure//EN','CALSCALE:GREGORIAN','BEGIN:VEVENT',`UID:tn-game-${Date.now()}@thetngame.com`,`DTSTAMP:${stamp}`,`DTSTART:${calendarStamp(starts)}`,`DTEND:${calendarStamp(ends)}`,`SUMMARY:${calendarEscape(title)}`,`DESCRIPTION:${calendarEscape(`Stops: ${stops.join(' → ')}\nConfirm hours, tickets, trail conditions, and driving time before leaving.`)}`,`URL:${window.location.origin}/`,'END:VEVENT','END:VCALENDAR'].join('\r\n');
      const url = URL.createObjectURL(new Blob([content],{type:'text/calendar;charset=utf-8'}));
      const link = document.createElement('a');
      link.href = url;
      link.download = `${title.toLocaleLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'') || 'tn-game-adventure'}.ics`;
      link.click();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
      if (status) status.textContent = 'Calendar file downloaded. Open it to confirm the event.';
      return;
    }
    const clearDate = event.target.closest('[data-tng-plan-clear-date]');
    if (clearDate) {
      const card = clearDate.closest('[data-plan-id]');
      const input = clearDate.closest('[data-tng-plan-schedule]').querySelector('input[name="planned_date"]');
      const submittedDate = input.value;
      clearDate.disabled = true;
      syncDraftExitWarning();
      let refreshAfterSave = false;
      try {
        const data = await post({operation:'schedule',plan_id:card.dataset.planId || '',planned_date:''});
        if (input.value === submittedDate && !input.validity?.badInput) input.value = '';
        input.defaultValue = '';
        if (status) status.textContent = data.message + ' If you stay on this page, save other edits and refresh to update preparation details.';
        refreshAfterSave = true;
      } catch (error) {
        if (status) status.textContent = error.message;
      } finally { clearDate.disabled = false; syncDraftExitWarning(); }
      if (refreshAfterSave) requestScheduleRefresh();
      return;
    }
    const print = event.target.closest('[data-tng-plan-print]');
    if (print) {
      cleanupPrint();
      print.closest('[data-plan-id]')?.classList.add('is-print-target');
      document.body.classList.add('tng-printing-adventure');
      window.print();
      window.setTimeout(cleanupPrint, 1000);
      return;
    }
    const archive = event.target.closest('[data-tng-plan-archive]');
    if (archive) {
      const card = archive.closest('[data-plan-id]');
      const operation = archive.dataset.tngPlanArchive === 'restore' ? 'restore' : 'archive';
      if (operation === 'archive' && !window.confirm('Archive this Saved Adventure? You can restore it later from the Archived filter.')) return;
      archive.disabled = true;
      archive.textContent = operation === 'archive' ? 'Archiving…' : 'Restoring…';
      try {
        const data = await post({operation,plan_id:card.dataset.planId || ''});
        if (status) status.textContent = data.message;
        window.location.reload();
      } catch (error) {
        archive.disabled = false;
        archive.textContent = operation === 'archive' ? 'Archive' : 'Restore adventure';
        if (status) status.textContent = error.message;
      }
      return;
    }
    const share = event.target.closest('[data-tng-plan-share]');
    if (share) {
      const card = share.closest('[data-plan-id]');
      const title = card.querySelector('[data-plan-title]')?.textContent?.trim() || 'Tennessee adventure';
      const stops = [...card.querySelectorAll('.tng-adventure-card__stops span')].map((node) => node.textContent.trim()).filter(Boolean);
      const text = `${title}${stops.length ? ` — ${stops.join(', ')}` : ''}. Plan your own Tennessee adventure with The TN Game: ${window.location.origin}/`;
      try {
        if (navigator.share) await navigator.share({title, text});
        else if (navigator.clipboard) await navigator.clipboard.writeText(text);
        else throw new Error('share_unavailable');
        if (status) status.textContent = navigator.share ? 'Adventure shared.' : 'Adventure summary copied.';
      } catch (error) {
        if (error?.name !== 'AbortError' && status) status.textContent = 'Sharing is not available in this browser.';
      }
      return;
    }
    const start = event.target.closest('[data-tng-plan-start]');
    if (start) {
      const card = start.closest('[data-plan-id]');
      const currentCount = Number(root.dataset.currentTripCount || 0);
      const confirmed = currentCount < 1 || window.confirm(`Start this adventure and replace your current ${currentCount}-stop trip? Current route and stop progress will reset. Your Saved Adventures will stay untouched.`);
      if (!confirmed) return;
      start.disabled = true;
      start.textContent = 'Starting…';
      try {
        const data = await post({operation:'start',plan_id:card.dataset.planId || '',confirm_replace:currentCount > 0 ? '1' : '0'});
        if (status) status.textContent = data.message;
        window.location.assign(data.url);
      } catch (error) {
        start.disabled = false;
        start.textContent = 'Start adventure';
        if (status) status.textContent = error.message;
      }
      return;
    }
    const button = event.target.closest('[data-tng-plan-duplicate]');
    if (!button) return;
    const card = button.closest('[data-plan-id]');
    button.disabled = true;
    button.textContent = 'Duplicating…';
    try {
      const data = await post({operation:'duplicate',plan_id:card.dataset.planId || ''});
      if (status) status.textContent = data.message;
      window.location.reload();
    } catch (error) {
      button.disabled = false;
      button.textContent = 'Duplicate';
      if (status) status.textContent = error.message;
    }
  });

  root.addEventListener('submit', async (event) => {
    const notesForm = event.target.closest('[data-tng-plan-notes]');
    if (notesForm) {
      event.preventDefault();
      const card = notesForm.closest('[data-plan-id]');
      const panel = notesForm.closest('[data-tng-plan-notes-panel]');
      const notes = notesForm.querySelector('textarea[name="notes"]');
      const button = notesForm.querySelector('button[type="submit"]');
      const submittedNotes = notes.value;
      button.disabled = true;
      syncDraftExitWarning();
      try {
        const data = await post({operation:'notes',plan_id:card.dataset.planId || '',notes:submittedNotes});
        const savedNotes = typeof data.notes === 'string' ? data.notes : submittedNotes;
        const hasNewerEdits = notes.value !== submittedNotes && notes.value !== savedNotes;
        if (!hasNewerEdits) {
          notes.value = savedNotes;
          const count = notesForm.querySelector('[data-tng-notes-count]');
          if (count) count.textContent = notes.value.length + ' of 600';
        }
        notes.defaultValue = savedNotes;
        panel.classList.toggle('has-notes', savedNotes.trim() !== '');
        panel.querySelector('[data-tng-notes-state]').textContent = hasNewerEdits ? 'Unsaved changes' : (savedNotes.trim() === '' ? 'Optional' : 'Saved');
        const printNotes = card.querySelector('[data-tng-print-notes]');
        if (printNotes) {
          printNotes.hidden = savedNotes.trim() === '';
          printNotes.querySelector('p').textContent = savedNotes.trim();
        }
        if (status) status.textContent = hasNewerEdits ? 'Submitted notes saved. Your newer edits are not saved yet.' : data.message;
      } catch (error) {
        if (status) status.textContent = error.message;
      } finally { button.disabled = false; syncDraftExitWarning(); }
      return;
    }
    const schedule = event.target.closest('[data-tng-plan-schedule]');
    if (schedule) {
      event.preventDefault();
      const card = schedule.closest('[data-plan-id]');
      const input = schedule.querySelector('input[name="planned_date"]');
      const button = schedule.querySelector('button[type="submit"]');
      const submittedDate = input.value;
      if (!submittedDate) { input.focus(); return; }
      button.disabled = true;
      syncDraftExitWarning();
      let refreshAfterSave = false;
      try {
        const data = await post({operation:'schedule',plan_id:card.dataset.planId || '',planned_date:submittedDate});
        input.defaultValue = submittedDate;
        if (status) status.textContent = data.message + ' If you stay on this page, save other edits and refresh to update preparation details.';
        refreshAfterSave = true;
      } catch (error) {
        if (status) status.textContent = error.message;
      } finally { button.disabled = false; syncDraftExitWarning(); }
      if (refreshAfterSave) requestScheduleRefresh();
      return;
    }
    const form = event.target.closest('[data-tng-plan-rename]');
    if (!form) return;
    event.preventDefault();
    const card = form.closest('[data-plan-id]');
    const input = form.querySelector('input[name="title"]');
    const button = form.querySelector('button[type="submit"]');
    const submittedTitle = input.value;
    const title = submittedTitle.trim();
    if (!title) { input.focus(); return; }
    button.disabled = true;
    syncDraftExitWarning();
    try {
      const data = await post({operation:'rename',plan_id:card.dataset.planId || '',title});
      const savedTitle = typeof data.title === 'string' && data.title !== '' ? data.title : title;
      const hasNewerEdits = input.value !== submittedTitle && input.value.trim() !== savedTitle;
      if (!hasNewerEdits) input.value = savedTitle;
      input.defaultValue = savedTitle;
      card.querySelector('[data-plan-title]').textContent = savedTitle;
      const printTitle = card.querySelector('[data-plan-print-title]');
      if (printTitle) printTitle.textContent = savedTitle;
      if (card === nextCard) root.querySelector('[data-tng-next-title]').textContent = savedTitle;
      updatePrepOverview();
      applyFilters();
      if (status) status.textContent = hasNewerEdits ? 'Adventure renamed. Your newer name edit is not saved yet.' : data.message;
    } catch (error) {
      if (status) status.textContent = error.message;
    } finally { button.disabled = false; syncDraftExitWarning(); }
  });
})();
