(() => {
  const cfg = window.TNGPastTrips || {};
  const mountFinish = () => {
    const progress = document.querySelector('[data-tng-trip-progress]');
    const aside = document.querySelector('.tng-active-trip-next');
    if (!progress || !aside) return;
    const total = Number(progress.dataset.total || 0);
    const resolved = Number(progress.dataset.resolved || 0);
    let button = aside.querySelector('[data-tng-archive-trip]');
    if (total > 0 && resolved === total) {
      if (!button) {
        button = document.createElement('button');
        button.type = 'button';
        button.className = 'tng-ui-button tng-finish-trip-button';
        button.dataset.tngArchiveTrip = '';
        button.textContent = 'Finish adventure';
        aside.appendChild(button);
      }
      button.hidden = false;
    } else if (button) button.hidden = true;
  };
  document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-tng-archive-trip]');
    if (!button) return;
    button.disabled = true;
    button.textContent = 'Archiving…';
    try {
      const body = new URLSearchParams({action:'tng_archive_active_trip', nonce:cfg.nonce || ''});
      const response = await fetch(cfg.ajaxUrl, {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body});
      const json = await response.json();
      if (!json.success) throw new Error('archive_failed');
      window.location.assign(json.data.redirect || cfg.historyUrl || '/past-trips/');
    } catch (error) {
      button.disabled = false;
      button.textContent = 'Finish adventure';
      window.alert('The trip could not be archived. Please try again.');
    }
  });
  document.addEventListener('click', () => setTimeout(mountFinish, 250));
  mountFinish();
  new MutationObserver(mountFinish).observe(document.documentElement,{childList:true,subtree:true,characterData:true});
})();
