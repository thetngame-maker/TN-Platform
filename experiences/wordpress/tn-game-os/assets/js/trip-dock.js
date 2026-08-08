(() => {
  const isLegacyTripText = (text) => {
    const value = (text || '').replace(/\s+/g, ' ').trim().toLowerCase();
    return value.includes('live trip') || value === 'my trip' || value.startsWith('my trip ') || value === 'past trips';
  };

  const hideLegacyTripUi = () => {
    document.querySelectorAll('body *').forEach((node) => {
      if (node.closest('[data-tng-trip-dock]')) return;
      if (!isLegacyTripText(node.textContent)) return;
      let candidate = node;
      for (let depth = 0; depth < 6 && candidate?.parentElement; depth += 1) {
        const style = window.getComputedStyle(candidate);
        const rect = candidate.getBoundingClientRect();
        if ((style.position === 'fixed' || style.position === 'sticky') && rect.width > 70) {
          candidate.style.setProperty('display', 'none', 'important');
          candidate.setAttribute('data-tng-legacy-trip-hidden', '');
          break;
        }
        candidate = candidate.parentElement;
      }
    });
  };

  const syncDockFromTripMode = () => {
    const dock = document.querySelector('[data-tng-trip-dock]');
    const tripStops = [...document.querySelectorAll('[data-trip-stop]')];
    if (!dock || !tripStops.length) return;

    const completed = tripStops.filter(stop => stop.classList.contains('is-complete'));
    const skipped = tripStops.filter(stop => stop.classList.contains('is-skipped'));
    const unresolved = tripStops.filter(stop => !stop.classList.contains('is-complete') && !stop.classList.contains('is-skipped'));
    const next = unresolved[0] || null;
    const total = tripStops.length;
    const resolved = completed.length + skipped.length;
    const remaining = Math.max(0, total - resolved);
    const perfect = total > 0 && completed.length === total;
    const finishedWithSkips = total > 0 && resolved === total && skipped.length > 0;

    const eyebrow = dock.querySelector('.tng-trip-dock__copy small');
    const title = dock.querySelector('.tng-trip-dock__copy strong');
    const status = dock.querySelector('.tng-trip-dock__copy span');
    const progress = dock.querySelector('.tng-trip-dock__progress i');
    const primary = dock.querySelector('.tng-trip-dock__actions .is-primary');

    if (perfect) {
      if (eyebrow) eyebrow.textContent = 'Trip complete';
      if (title) title.textContent = 'Your Tennessee day is complete';
      if (status) status.textContent = `${completed.length} of ${total} stops completed`;
      if (primary) primary.textContent = 'View recap';
    } else if (finishedWithSkips) {
      if (eyebrow) eyebrow.textContent = 'Trip finished';
      if (title) title.textContent = 'Your Tennessee day is finished';
      if (status) status.textContent = `${completed.length} completed · ${skipped.length} skipped`;
      if (primary) primary.textContent = 'Review trip';
    } else {
      const nextTitle = next?.querySelector('h3')?.textContent?.trim() || 'Your Tennessee day';
      const bits = [`${completed.length} completed`];
      if (skipped.length) bits.push(`${skipped.length} skipped`);
      if (remaining) bits.push(`${remaining} remaining`);
      if (eyebrow) eyebrow.textContent = 'Active trip';
      if (title) title.textContent = `Next: ${nextTitle}`;
      if (status) status.textContent = bits.join(' · ');
      if (primary) primary.textContent = 'Trip mode';
    }

    if (progress) progress.style.width = `${total ? Math.round((resolved / total) * 100) : 0}%`;
    dock.dataset.tripResolved = String(resolved);
    dock.dataset.tripTotal = String(total);
  };

  hideLegacyTripUi();
  syncDockFromTripMode();

  const observer = new MutationObserver(() => {
    hideLegacyTripUi();
    syncDockFromTripMode();
  });
  observer.observe(document.documentElement, { childList: true, subtree: true });

  const activeList = document.querySelector('.tng-active-trip-list');
  if (activeList) {
    new MutationObserver(syncDockFromTripMode).observe(activeList, {
      attributes: true,
      attributeFilter: ['class'],
      subtree: true,
      childList: true,
    });
  }

  document.addEventListener('tng:trip-updated', syncDockFromTripMode);
  document.addEventListener('tng:trip-proximity-update', syncDockFromTripMode);
  window.setTimeout(() => { hideLegacyTripUi(); syncDockFromTripMode(); }, 500);
  window.setTimeout(() => { hideLegacyTripUi(); syncDockFromTripMode(); }, 1800);
})();
