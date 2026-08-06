(() => {
  const cfg = window.TNGActiveTrip || {};
  const updateSummary = (done, total) => {
    document.querySelectorAll('[data-tng-trip-progress]').forEach((el) => { el.textContent = `${done}/${total}`; });
    document.querySelectorAll('[data-tng-trip-progress-bar]').forEach((el) => { el.style.width = `${total ? Math.round((done / total) * 100) : 0}%`; });
  };
  document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-trip-complete]');
    if (!button) return;
    event.preventDefault();
    if (button.dataset.loading === '1') return;
    const postId = Number(button.dataset.postId || 0);
    const complete = button.getAttribute('aria-pressed') !== 'true';
    const stop = button.closest('[data-trip-stop]');
    button.dataset.loading = '1';
    button.disabled = true;
    try {
      const body = new URLSearchParams({action: 'tng_trip_stop_status', nonce: cfg.nonce || '', postId: String(postId), complete: complete ? '1' : ''});
      const response = await fetch(cfg.ajaxUrl, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}, body});
      const json = await response.json();
      if (!json.success) throw new Error('Unable to update stop');
      button.setAttribute('aria-pressed', json.data.complete ? 'true' : 'false');
      button.textContent = json.data.complete ? 'Undo' : 'Mark complete';
      stop?.classList.toggle('is-complete', json.data.complete);
      const number = stop?.querySelector('.tng-active-trip-stop__number');
      if (number) number.textContent = json.data.complete ? '✓' : String(Array.from(stop.parentElement.children).indexOf(stop) + 1);
      updateSummary(json.data.done, json.data.total);
    } catch (error) {
      window.alert('The stop could not be updated. Please try again.');
    } finally {
      button.disabled = false;
      delete button.dataset.loading;
    }
  });
})();
