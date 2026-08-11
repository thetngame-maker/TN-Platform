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

    const setText = (node, value) => {
      if (node && node.textContent !== value) node.textContent = value;
    };

    if (perfect) {
      setText(eyebrow, 'Trip complete');
      setText(title, 'Your Tennessee day is complete');
      setText(status, `${completed.length} of ${total} stops completed`);
      setText(primary, 'View recap');
    } else if (finishedWithSkips) {
      setText(eyebrow, 'Trip finished');
      setText(title, 'Your Tennessee day is finished');
      setText(status, `${completed.length} completed · ${skipped.length} skipped`);
      setText(primary, 'Review trip');
    } else {
      const nextTitle = next?.querySelector('h3')?.textContent?.trim() || 'Your Tennessee day';
      const bits = [`${completed.length} completed`];
      if (skipped.length) bits.push(`${skipped.length} skipped`);
      if (remaining) bits.push(`${remaining} remaining`);
      setText(eyebrow, 'Active trip');
      setText(title, `Next: ${nextTitle}`);
      setText(status, bits.join(' · '));
      setText(primary, 'Trip mode');
    }

    const width = `${total ? Math.round((resolved / total) * 100) : 0}%`;
    if (progress && progress.style.width !== width) progress.style.width = width;
    if (dock.dataset.tripResolved !== String(resolved)) dock.dataset.tripResolved = String(resolved);
    if (dock.dataset.tripTotal !== String(total)) dock.dataset.tripTotal = String(total);
  };

  let syncQueued = false;
  const queueDockSync = () => {
    if (syncQueued) return;
    syncQueued = true;
    window.requestAnimationFrame(() => {
      syncQueued = false;
      syncDockFromTripMode();
    });
  };

  // Legacy UI cleanup is deliberately bounded. A document-wide MutationObserver here
  // can become expensive on map/trip screens and can recurse when the dock updates.
  hideLegacyTripUi();
  syncDockFromTripMode();
  window.setTimeout(hideLegacyTripUi, 500);
  window.setTimeout(hideLegacyTripUi, 1800);

  // Only watch the itinerary itself. Dock mutations happen outside this element, so
  // updating the dock cannot trigger its own observer again.
  const activeList = document.querySelector('.tng-active-trip-list');
  if (activeList) {
    new MutationObserver(queueDockSync).observe(activeList, {
      attributes: true,
      attributeFilter: ['class'],
      subtree: true,
      childList: true,
    });
  }

  document.addEventListener('tng:trip-updated', queueDockSync);
})();
