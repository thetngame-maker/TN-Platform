(() => {
  const cfg = window.TNGTripData || {};
  const saved = new Set((cfg.savedIds || []).map(Number));

  const postIdFromBody = () => {
    const match = Array.from(document.body.classList).map((name) => name.match(/^postid-(\d+)$/)).find(Boolean);
    return match ? Number(match[1]) : 0;
  };

  const setButton = (button, isSaved) => {
    button.classList.toggle('is-saved', isSaved);
    button.setAttribute('aria-pressed', isSaved ? 'true' : 'false');
    button.textContent = isSaved ? '✓ Added to trip' : '＋ Add to trip';
  };

  const addNewTripAction = () => {
    const actions = document.querySelector('.tng-trip-finish-card__actions');
    if (!actions || actions.querySelector('[data-tng-start-new-trip]')) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'tng-ui-button tng-start-new-trip';
    button.setAttribute('data-tng-start-new-trip', '');
    button.textContent = 'Start a new trip';
    actions.prepend(button);
  };

  const enhance = () => {
    const currentId = postIdFromBody();
    document.querySelectorAll('[data-tng-trip-toggle], a, button').forEach((button) => {
      if (button.matches('[data-tng-trip-ready]')) return;
      const label = (button.textContent || '').trim().toLowerCase();
      if (!button.matches('[data-tng-trip-toggle]') && !label.includes('add to trip')) return;
      const id = Number(button.getAttribute('data-post-id') || currentId || 0);
      if (!id) return;
      button.setAttribute('data-post-id', String(id));
      button.setAttribute('data-tng-trip-toggle', '');
      button.setAttribute('data-tng-trip-ready', '');
      if (button.tagName === 'A') button.setAttribute('href', cfg.savedUrl || '/saved/');
      setButton(button, saved.has(id));
    });
    addNewTripAction();
  };

  const toggle = async (button) => {
    const postId = Number(button.getAttribute('data-post-id') || 0);
    if (!postId) return;
    if (!cfg.loggedIn) {
      window.location.assign(cfg.loginUrl || '/wp-login.php');
      return;
    }
    if (button.dataset.loading === '1') return;
    button.dataset.loading = '1';
    button.disabled = true;
    const previous = saved.has(postId);
    setButton(button, !previous);
    try {
      const body = new URLSearchParams({action: 'tng_toggle_saved', nonce: cfg.nonce || '', postId: String(postId)});
      const response = await fetch(cfg.ajaxUrl, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}, body});
      const json = await response.json();
      if (!json.success) throw new Error('Unable to save');
      if (json.data.saved) saved.add(postId); else saved.delete(postId);
      document.querySelectorAll(`[data-tng-trip-toggle][data-post-id="${postId}"]`).forEach((item) => setButton(item, json.data.saved));
      if (!json.data.saved) document.querySelectorAll(`[data-tng-saved-card="${postId}"]`).forEach((card) => card.remove());
      document.dispatchEvent(new CustomEvent('tng:trip-updated', {detail: json.data}));
    } catch (error) {
      setButton(button, previous);
      window.alert('The place could not be updated. Please try again.');
    } finally {
      button.disabled = false;
      delete button.dataset.loading;
    }
  };

  const resetTrip = async (button) => {
    if (!cfg.loggedIn) {
      window.location.assign(cfg.loginUrl || '/wp-login.php');
      return;
    }
    if (button?.dataset.loading === '1') return;
    const confirmed = window.confirm('Start a new trip? Your completed trip recap stays saved, but the current itinerary and its progress will be cleared.');
    if (!confirmed) return;
    if (button) {
      button.dataset.loading = '1';
      button.disabled = true;
      button.textContent = 'Starting new trip…';
    }
    try {
      const body = new URLSearchParams({action: 'tng_reset_trip', nonce: cfg.nonce || ''});
      const response = await fetch(cfg.ajaxUrl, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}, body});
      const json = await response.json();
      if (!json.success) throw new Error('Unable to reset trip');
      saved.clear();
      document.dispatchEvent(new CustomEvent('tng:trip-updated', {detail: json.data}));
      window.location.assign(cfg.exploreUrl || '/explore/');
    } catch (error) {
      if (button) {
        button.disabled = false;
        button.textContent = 'Start a new trip';
        delete button.dataset.loading;
      }
      window.alert('The trip could not be reset. Please try again.');
    }
  };

  document.addEventListener('click', (event) => {
    const reset = event.target.closest('[data-tng-start-new-trip]');
    if (reset) {
      event.preventDefault();
      resetTrip(reset);
      return;
    }
    const button = event.target.closest('[data-tng-trip-toggle]');
    if (!button) return;
    event.preventDefault();
    toggle(button);
  }, true);

  let enhanceQueued = false;
  const scheduleEnhance = () => {
    if (enhanceQueued) return;
    enhanceQueued = true;
    window.requestAnimationFrame(() => {
      enhanceQueued = false;
      enhance();
    });
  };

  enhance();
  new MutationObserver(scheduleEnhance).observe(document.documentElement, {childList: true, subtree: true});
})();
