(() => {
  const cfg = window.TNGOfflineMode || {};
  if (!('serviceWorker' in navigator) || !cfg.serviceWorkerUrl) return;
  let installPrompt = null;
  let dismissed = false;

  const indicator = document.createElement('aside');
  indicator.className = 'tng-offline-indicator';
  indicator.setAttribute('role', 'status');
  indicator.setAttribute('aria-live', 'polite');
  indicator.innerHTML = '<span class="tng-offline-indicator__icon">◇</span><div><strong data-tng-offline-title>Offline mode</strong><small data-tng-offline-copy>Checking offline readiness…</small></div><button type="button" data-tng-install hidden>Install</button><button type="button" data-tng-offline-close aria-label="Dismiss">×</button>';
  document.body.appendChild(indicator);
  const title = indicator.querySelector('[data-tng-offline-title]');
  const copy = indicator.querySelector('[data-tng-offline-copy]');
  const install = indicator.querySelector('[data-tng-install]');

  const render = (force = false) => {
    const offline = !navigator.onLine;
    indicator.classList.toggle('is-offline', offline);
    indicator.classList.toggle('is-visible', !dismissed && (offline || force || Boolean(installPrompt)));
    title.textContent = offline ? 'You are offline' : 'Offline mode ready';
    copy.textContent = offline
      ? 'Cached public screens remain available. Trips, XP, photos, and profile changes wait for a connection.'
      : (cfg.privateRoute ? 'Private screens stay network-only for your safety.' : 'This public screen can reopen without a connection.');
    install.hidden = !installPrompt || offline;
  };

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    installPrompt = event;
    render(true);
  });
  window.addEventListener('appinstalled', () => {
    installPrompt = null;
    dismissed = false;
    render(true);
    window.setTimeout(() => { dismissed = true; render(); }, 2600);
  });
  window.addEventListener('offline', () => { dismissed = false; render(); });
  window.addEventListener('online', () => { dismissed = false; render(true); window.setTimeout(() => { dismissed = true; render(); }, 2200); });

  indicator.querySelector('[data-tng-offline-close]').addEventListener('click', () => { dismissed = true; render(); });
  install.addEventListener('click', async () => {
    if (!installPrompt) return;
    const prompt = installPrompt;
    installPrompt = null;
    install.hidden = true;
    await prompt.prompt();
  });

  window.addEventListener('load', async () => {
    try {
      await navigator.serviceWorker.register(cfg.serviceWorkerUrl, {scope:'/'});
      await navigator.serviceWorker.ready;
      if (!navigator.onLine) render();
    } catch (error) {
      indicator.remove();
    }
  }, {once:true});
})();
