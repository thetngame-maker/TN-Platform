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
  let selectedFilter = 'all';

  const applyFilters = () => {
    const stateOrder = {active:0,ready:1,completed:2};
    const sorted = [...cards].sort((a, b) => {
      if (sort?.value === 'title') return (a.querySelector('[data-plan-title]')?.textContent || '').localeCompare(b.querySelector('[data-plan-title]')?.textContent || '', undefined, {sensitivity:'base'});
      if (sort?.value === 'status') return (stateOrder[a.dataset.planState] ?? 9) - (stateOrder[b.dataset.planState] ?? 9) || Number(b.dataset.planUpdated || 0) - Number(a.dataset.planUpdated || 0);
      return Number(b.dataset.planUpdated || 0) - Number(a.dataset.planUpdated || 0);
    });
    if (grid) sorted.forEach((card) => grid.append(card));
    const query = search?.value.trim().toLocaleLowerCase() || '';
    let visible = 0;
    cards.forEach((card) => {
      const matchesState = selectedFilter === 'all' || card.dataset.planState === selectedFilter;
      const matchesQuery = !query || card.textContent.toLocaleLowerCase().includes(query);
      card.hidden = !(matchesState && matchesQuery);
      if (!card.hidden) visible += 1;
    });
    if (filterStatus) filterStatus.textContent = `${visible} of ${cards.length} adventure${cards.length === 1 ? '' : 's'} shown`;
    if (filterEmpty) filterEmpty.hidden = visible !== 0;
  };

  search?.addEventListener('input', applyFilters);
  sort?.addEventListener('change', applyFilters);
  filters.forEach((button) => button.addEventListener('click', () => {
    selectedFilter = button.dataset.tngAdventureFilter || 'all';
    filters.forEach((item) => item.setAttribute('aria-pressed', String(item === button)));
    applyFilters();
  }));
  applyFilters();

  const post = async (fields) => {
    const body = new URLSearchParams({action:'tng_adventure_library_action',nonce:root.dataset.nonce || '',...fields});
    const response = await fetch(root.dataset.ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body});
    const json = await response.json();
    if (!json.success) throw new Error(json.data?.message || 'Saved Adventures could not update that plan.');
    return json.data;
  };

  root.addEventListener('click', async (event) => {
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
      applyFilters();
      if (status) status.textContent = data.message;
    } catch (error) {
      if (status) status.textContent = error.message;
    } finally { button.disabled = false; }
  });
})();
