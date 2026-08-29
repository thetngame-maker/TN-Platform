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
  let registration = null;

  const messageWorker = async (payload) => {
    const ready = registration || await navigator.serviceWorker.ready;
    const worker = ready.active || ready.waiting || ready.installing;
    if (!worker) throw new Error('Offline worker is not ready.');
    return await new Promise((resolve, reject) => {
      const channel = new MessageChannel();
      const timer = window.setTimeout(() => reject(new Error('Offline worker timed out.')), 30000);
      channel.port1.onmessage = (event) => { window.clearTimeout(timer); resolve(event.data || {}); };
      worker.postMessage(payload, [channel.port2]);
    });
  };

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
      registration = await navigator.serviceWorker.register(cfg.serviceWorkerUrl, {scope:'/'});
      registration = await navigator.serviceWorker.ready;
      if (!navigator.onLine) render();
      initializePackManager();
    } catch (error) {
      indicator.remove();
    }
  }, {once:true});

  const initializePackManager = async () => {
    const manager = document.querySelector('[data-tng-offline-manager]');
    if (!manager) return;
    const cards = [...manager.querySelectorAll('[data-tng-pack]')];
    const storageTitle = manager.querySelector('[data-tng-storage-title]');
    const storageCopy = manager.querySelector('[data-tng-storage-copy]');
    const deviceAdventures = manager.querySelector('[data-tng-device-adventures]');
    const deviceAdventureList = manager.querySelector('[data-tng-device-adventure-list]');

    const publicStopLabel = (value, index) => {
      try {
        const url = new URL(value, window.location.origin);
        if (url.origin !== window.location.origin) return '';
        const slug = decodeURIComponent(url.pathname.split('/').filter(Boolean).pop() || '').replace(/[-_]+/g,' ').trim();
        return slug ? slug.replace(/\b\w/g,(letter) => letter.toUpperCase()).slice(0,64) : `Public stop ${index + 1}`;
      } catch (error) { return ''; }
    };

    const renderAdventureLibrary = (packs = []) => {
      if (!deviceAdventures || !deviceAdventureList) return;
      deviceAdventureList.replaceChildren();
      packs.slice(0,12).forEach((pack, packIndex) => {
        const urls = Array.isArray(pack?.urls) ? pack.urls.slice(0,12) : [];
        const links = urls.map((value,index) => ({value,label:publicStopLabel(value,index)})).filter((item) => item.label);
        if (!links.length) return;
        const article = document.createElement('article');
        article.className = 'tng-offline-adventure';
        const heading = document.createElement('div');
        heading.className = 'tng-offline-adventure__summary';
        const headingCopy = document.createElement('div');
        const title = document.createElement('h3');
        title.textContent = `Saved Adventure ${packIndex + 1}`;
        const detail = document.createElement('small');
        const verifiedDate = pack?.verifiedAt ? new Date(pack.verifiedAt) : null;
        const verifiedLabel = verifiedDate && Number.isFinite(verifiedDate.getTime()) ? ` · verified ${Date.now()-verifiedDate.getTime()<86400000?'today':verifiedDate.toLocaleDateString(undefined,{month:'short',day:'numeric'})}` : '';
        detail.textContent = `${links.length} public stop screen${links.length === 1 ? '' : 's'} · device only${verifiedLabel}`;
        headingCopy.append(title,detail);
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.textContent = 'Remove from device';
        remove.setAttribute('data-tng-device-adventure-remove','');
        remove.addEventListener('click', async () => {
          if (!window.confirm('Remove these cached public stop screens from this device? Your Saved Adventure will remain in your Explorer account.')) return;
          remove.disabled = true; remove.textContent = 'Removing…';
          try {
            const result = await messageWorker({type:'TNG_ADVENTURE_PACK_REMOVE',id:pack.id});
            if (!result.ok) throw new Error('Remove failed');
            const response = await messageWorker({type:'TNG_ADVENTURE_PACK_LIBRARY'});
            renderAdventureLibrary(response.ok ? response.packs || [] : []);
          } catch (error) { remove.disabled = false; remove.textContent = 'Could not remove'; }
        });
        heading.append(headingCopy,remove);
        const nav = document.createElement('nav');
        nav.setAttribute('aria-label',title.textContent);
        links.forEach((item) => { const link=document.createElement('a');link.href=item.value;link.textContent=item.label;nav.append(link); });
        article.append(heading,nav);
        deviceAdventureList.append(article);
      });
      deviceAdventures.hidden = deviceAdventureList.childElementCount === 0;
    };

    const renderStatus = (installed = {}) => {
      cards.forEach((card) => {
        const id = card.getAttribute('data-tng-pack') || '';
        const count = Number(installed[id] || 0);
        const saved = count > 0;
        card.classList.toggle('is-saved', saved);
        card.querySelector('[data-tng-pack-state]').textContent = saved ? `${count} screens saved` : 'Not downloaded';
        card.querySelector('[data-tng-pack-save]').textContent = saved ? 'Update' : 'Download';
        card.querySelector('[data-tng-pack-remove]').hidden = !saved;
      });
    };

    const refresh = async () => {
      const response = await messageWorker({type:'TNG_OFFLINE_PACK_STATUS'});
      if (response.ok) renderStatus(response.installed || {});
    };

    cards.forEach((card) => {
      const id = card.getAttribute('data-tng-pack') || '';
      const state = card.querySelector('[data-tng-pack-state]');
      const save = card.querySelector('[data-tng-pack-save]');
      const remove = card.querySelector('[data-tng-pack-remove]');
      save.addEventListener('click', async () => {
        if (!navigator.onLine) { state.textContent = 'Connect to download this pack'; return; }
        save.disabled = true; remove.disabled = true; state.textContent = 'Downloading public screens…';
        try {
          const response = await messageWorker({type:'TNG_OFFLINE_PACK_SAVE',id});
          renderStatus(response.installed || {});
          if (!response.ok) state.textContent = `${response.saved || 0} saved · ${response.failed || 0} need a connection`;
        } catch (error) { state.textContent = 'Could not update this pack'; }
        save.disabled = false; remove.disabled = false;
      });
      remove.addEventListener('click', async () => {
        save.disabled = true; remove.disabled = true; state.textContent = 'Removing from this device…';
        try { const response = await messageWorker({type:'TNG_OFFLINE_PACK_REMOVE',id}); renderStatus(response.installed || {}); }
        catch (error) { state.textContent = 'Could not remove this pack'; }
        save.disabled = false; remove.disabled = false;
      });
    });

    try {
      if (navigator.storage && navigator.storage.estimate) {
        const estimate = await navigator.storage.estimate();
        const used = Number(estimate.usage || 0);
        const quota = Number(estimate.quota || 0);
        storageTitle.textContent = quota ? `${Math.round((used / quota) * 100)}% of app storage in use` : 'Device storage ready';
        storageCopy.textContent = quota ? `${(used / 1048576).toFixed(1)} MB used of ${(quota / 1048576).toFixed(0)} MB available to this browser.` : 'Offline packs use your browser\'s private app storage.';
      } else storageTitle.textContent = 'Device storage ready';
    } catch (error) { storageTitle.textContent = 'Device storage ready'; }
    try { await refresh(); } catch (error) { renderStatus({}); }
    try { const response=await messageWorker({type:'TNG_ADVENTURE_PACK_LIBRARY'});renderAdventureLibrary(response.ok ? response.packs || [] : []); }
    catch (error) { renderAdventureLibrary([]); }
  };
})();
