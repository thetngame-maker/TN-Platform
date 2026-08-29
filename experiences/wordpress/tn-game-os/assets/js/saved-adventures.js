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
  const upcomingCalendar = root.querySelector('[data-tng-upcoming-calendar]');
  const resetView = root.querySelector('[data-tng-adventure-reset]');
  const preferenceKey = 'tng_saved_adventure_view_v1';
  const allowedFilters = ['all','upcoming','active','ready','completed','archived'];
  const allowedSorts = ['recent','date','title','status'];
  let selectedFilter = 'all';
  let nextCard = null;
  const now = new Date();
  const todayKey = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`;

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

  const applyFilters = () => {
    const stateOrder = {active:0,ready:1,completed:2,archived:3};
    const sorted = [...cards].sort((a, b) => {
      if (sort?.value === 'title') return (a.querySelector('[data-plan-title]')?.textContent || '').localeCompare(b.querySelector('[data-plan-title]')?.textContent || '', undefined, {sensitivity:'base'});
      if (sort?.value === 'status') return (stateOrder[a.dataset.planState] ?? 9) - (stateOrder[b.dataset.planState] ?? 9) || Number(b.dataset.planUpdated || 0) - Number(a.dataset.planUpdated || 0);
      if (sort?.value === 'date') {
        const aDate = a.dataset.planDate || '';
        const bDate = b.dataset.planDate || '';
        const aRank = !aDate ? 2 : (aDate >= todayKey ? 0 : 1);
        const bRank = !bDate ? 2 : (bDate >= todayKey ? 0 : 1);
        if (aRank !== bRank) return aRank - bRank;
        if (aRank === 0) return aDate.localeCompare(bDate);
        if (aRank === 1) return bDate.localeCompare(aDate);
      }
      return Number(b.dataset.planUpdated || 0) - Number(a.dataset.planUpdated || 0);
    });
    if (grid) sorted.forEach((card) => grid.append(card));
    const query = search?.value.trim().toLocaleLowerCase() || '';
    let visible = 0;
    cards.forEach((card) => {
      const matchesState = selectedFilter === 'all' ? card.dataset.planState !== 'archived' : (selectedFilter === 'upcoming' ? card.dataset.planState !== 'archived' && card.dataset.planDate >= todayKey : card.dataset.planState === selectedFilter);
      const matchesQuery = !query || card.textContent.toLocaleLowerCase().includes(query);
      card.hidden = !(matchesState && matchesQuery);
      if (!card.hidden) visible += 1;
    });
    if (filterStatus) filterStatus.textContent = `${visible} of ${cards.length} adventure${cards.length === 1 ? '' : 's'} shown`;
    if (filterEmpty) filterEmpty.hidden = visible !== 0;
  };

  search?.addEventListener('input', applyFilters);
  root.addEventListener('input', (event) => {
    const notes = event.target.closest('[data-tng-plan-notes] textarea');
    if (!notes) return;
    const count = notes.closest('[data-tng-plan-notes]').querySelector('[data-tng-notes-count]');
    if (count) count.textContent = notes.value.length + ' of 600';
  });
  sort?.addEventListener('change', () => { savePreferences(); applyFilters(); });
  filters.forEach((button) => button.addEventListener('click', () => {
    selectedFilter = button.dataset.tngAdventureFilter || 'all';
    filters.forEach((item) => item.setAttribute('aria-pressed', String(item === button)));
    savePreferences();
    applyFilters();
  }));
  resetView?.addEventListener('click', () => {
    selectedFilter = 'all';
    if (sort) sort.value = 'recent';
    if (search) search.value = '';
    filters.forEach((item) => item.setAttribute('aria-pressed', String(item.dataset.tngAdventureFilter === 'all')));
    try { window.localStorage.removeItem(preferenceKey); } catch (error) { /* No storage to reset. */ }
    applyFilters();
  });
  filters.forEach((item) => item.setAttribute('aria-pressed', String(item.dataset.tngAdventureFilter === selectedFilter)));
  applyFilters();

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
          pack.panel.classList.toggle('needs-update', count > 0 && !current);
          state.textContent = count > 0 ? (current ? `${count} public stop screen${count === 1 ? '' : 's'} saved` : `Update available · ${count} screen${count === 1 ? '' : 's'} saved`) : 'Not downloaded';
          save.textContent = count > 0 ? 'Update' : 'Download';
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
            if (!result.ok) state.textContent = `${result.saved || 0} saved · ${result.failed || 0} unavailable`;
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
  nextCard = cards.filter((card) => card.dataset.planState !== 'archived' && card.dataset.planDate >= todayKey).sort((a, b) => a.dataset.planDate.localeCompare(b.dataset.planDate))[0] || null;
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
    nextBanner.querySelector('[data-tng-next-readiness]').textContent = Number(nextCard.dataset.planReadyCount || 0) + ' of 4 readiness checks complete';
    nextBanner.hidden = false;
    nextCard.classList.add('is-next-up');
    const revealNextCard = () => {
      selectedFilter = 'all';
      filters.forEach((item) => item.setAttribute('aria-pressed', String(item.dataset.tngAdventureFilter === 'all')));
      savePreferences();
      applyFilters();
      nextCard.scrollIntoView({behavior:'smooth',block:'center'});
      window.setTimeout(() => nextCard.querySelector('button,a')?.focus({preventScroll:true}), 450);
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

  const post = async (fields) => {
    const body = new URLSearchParams({action:'tng_adventure_library_action',nonce:root.dataset.nonce || '',...fields});
    const response = await fetch(root.dataset.ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body});
    const json = await response.json();
    if (!json.success) throw new Error(json.data?.message || 'Saved Adventures could not update that plan.');
    return json.data;
  };

  const cleanupPrint = () => {
    document.body.classList.remove('tng-printing-adventure');
    cards.forEach((card) => card.classList.remove('is-print-target'));
  };
  window.addEventListener('afterprint', cleanupPrint);

  root.addEventListener('change', async (event) => {
    const checkbox = event.target.closest('[data-tng-readiness-key]');
    if (!checkbox) return;
    const card = checkbox.closest('[data-plan-id]');
    const fieldset = checkbox.closest('[data-tng-plan-readiness]');
    const previous = !checkbox.checked;
    checkbox.disabled = true;
    try {
      const data = await post({operation:'readiness',plan_id:card.dataset.planId || '',readiness_key:checkbox.dataset.tngReadinessKey || '',checked:checkbox.checked ? '1' : '0'});
      const count = [...fieldset.querySelectorAll('[data-tng-readiness-key]')].filter((item) => item.checked).length;
      card.dataset.planReadyCount = String(count);
      fieldset.querySelector('[data-tng-readiness-count]').textContent = count + ' of 4 ready';
      if (card === nextCard) root.querySelector('[data-tng-next-readiness]').textContent = count + ' of 4 readiness checks complete';
      if (status) status.textContent = data.message;
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
        const stops = [...plan.card.querySelectorAll('.tng-adventure-card__print li')].map((node) => node.textContent.trim()).filter(Boolean);
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
      const stops = [...card.querySelectorAll('.tng-adventure-card__print li')].map((node) => node.textContent.trim()).filter(Boolean);
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
      clearDate.disabled = true;
      try {
        const data = await post({operation:'schedule',plan_id:card.dataset.planId || '',planned_date:''});
        if (status) status.textContent = data.message;
        window.location.reload();
      } catch (error) {
        clearDate.disabled = false;
        if (status) status.textContent = error.message;
      }
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
      button.disabled = true;
      try {
        const data = await post({operation:'notes',plan_id:card.dataset.planId || '',notes:notes.value});
        panel.classList.toggle('has-notes', notes.value.trim() !== '');
        panel.querySelector('[data-tng-notes-state]').textContent = notes.value.trim() === '' ? 'Optional' : 'Saved';
        if (status) status.textContent = data.message;
      } catch (error) {
        if (status) status.textContent = error.message;
      } finally { button.disabled = false; }
      return;
    }
    const schedule = event.target.closest('[data-tng-plan-schedule]');
    if (schedule) {
      event.preventDefault();
      const card = schedule.closest('[data-plan-id]');
      const input = schedule.querySelector('input[name="planned_date"]');
      const button = schedule.querySelector('button[type="submit"]');
      if (!input.value) { input.focus(); return; }
      button.disabled = true;
      try {
        const data = await post({operation:'schedule',plan_id:card.dataset.planId || '',planned_date:input.value});
        if (status) status.textContent = data.message;
        window.location.reload();
      } catch (error) {
        button.disabled = false;
        if (status) status.textContent = error.message;
      }
      return;
    }
    const form = event.target.closest('[data-tng-plan-rename]');
    if (!form) return;
    event.preventDefault();
    const card = form.closest('[data-plan-id]');
    const input = form.querySelector('input[name="title"]');
    const button = form.querySelector('button[type="submit"]');
    const title = input.value.trim();
    if (!title) { input.focus(); return; }
    button.disabled = true;
    try {
      const data = await post({operation:'rename',plan_id:card.dataset.planId || '',title});
      card.querySelector('[data-plan-title]').textContent = title;
      const printTitle = card.querySelector('[data-plan-print-title]');
      if (printTitle) printTitle.textContent = title;
      if (card === nextCard) root.querySelector('[data-tng-next-title]').textContent = title;
      applyFilters();
      if (status) status.textContent = data.message;
    } catch (error) {
      if (status) status.textContent = error.message;
    } finally { button.disabled = false; }
  });
})();
