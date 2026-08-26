(() => {
  'use strict';
  const root = document.querySelector('[data-tng-adventure-library]');
  if (!root) return;
  const status = root.querySelector('[data-tng-library-status]');

  const post = async (fields) => {
    const body = new URLSearchParams({action:'tng_adventure_library_action',nonce:root.dataset.nonce || '',...fields});
    const response = await fetch(root.dataset.ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body});
    const json = await response.json();
    if (!json.success) throw new Error(json.data?.message || 'Saved Adventures could not update that plan.');
    return json.data;
  };

  root.addEventListener('click', async (event) => {
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
      if (status) status.textContent = data.message;
    } catch (error) {
      if (status) status.textContent = error.message;
    } finally { button.disabled = false; }
  });
})();
