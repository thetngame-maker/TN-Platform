(() => {
  'use strict';

  const screen = document.querySelector('.tng-map-screen');
  if (!screen) return;

  const mobileQuery = window.matchMedia('(max-width: 700px)');
  let dockPlaceholder = null;
  let movedDock = null;
  let observer = null;
  let scanTimer = null;

  const exactXpText = value => /^\s*[\d,.]+\s*XP\s*$/i.test((value || '').replace(/\s+/g, ' ').trim());
  const navText = value => /\bExplore\b.*\bMap\b.*\bPlay\b.*\bTrips\b.*\bProfile\b/i.test((value || '').replace(/\s+/g, ' '));

  const candidateContainer = leaf => {
    let best = leaf;
    let node = leaf;
    for (let i = 0; i < 5 && node && node !== document.body; i += 1, node = node.parentElement) {
      if (!(node instanceof HTMLElement)) break;
      if (node.matches('.tng-app-nav,.tng-app-nav__inner,.tng-trip-dock,.tng-map-sheet')) break;
      const text = (node.textContent || '').replace(/\s+/g, ' ').trim();
      const rect = node.getBoundingClientRect();
      if (navText(text) || text.length > 120) break;
      if (rect.width >= 150 && rect.height >= 14 && rect.height <= 100) best = node;
    }
    return best;
  };

  const hideLegacyXp = () => {
    if (!mobileQuery.matches) return;

    // First catch old overlays whose class names identify them directly.
    document.querySelectorAll('[class*="xp" i],[id*="xp" i]').forEach(node => {
      if (!(node instanceof HTMLElement)) return;
      if (node.closest('.tng-map-screen,.tng-trip-dock,.tng-map-sheet')) return;
      const rect = node.getBoundingClientRect();
      const style = getComputedStyle(node);
      const nearBottom = rect.bottom >= window.innerHeight * 0.72;
      const overlayLike = ['fixed','sticky','absolute'].includes(style.position);
      if (nearBottom && overlayLike && rect.width >= 140 && rect.height <= 110) node.classList.add('tng-map-legacy-xp-hidden');
    });

    // Then catch the older anonymous progress overlay by its rendered XP pill.
    document.querySelectorAll('body *').forEach(node => {
      if (!(node instanceof HTMLElement) || !exactXpText(node.textContent)) return;
      if (node.closest('.tng-trip-dock,.tng-map-sheet')) return;
      const rect = node.getBoundingClientRect();
      if (rect.bottom < window.innerHeight * 0.68) return;
      const target = candidateContainer(node);
      if (target && !target.closest('.tng-trip-dock,.tng-map-sheet')) target.classList.add('tng-map-legacy-xp-hidden');
    });
  };

  const placeTripDock = () => {
    const dock = document.querySelector('.tng-trip-dock');
    const wrap = screen.querySelector('.tng-map-canvas-wrap');
    if (!dock || !wrap) return;

    if (mobileQuery.matches) {
      document.body.classList.add('tng-map-mobile-layout');
      if (dock.parentElement !== screen.querySelector('.tng-map-layout')) {
        if (!dockPlaceholder) {
          dockPlaceholder = document.createComment('tng-trip-dock-original-position');
          dock.parentNode?.insertBefore(dockPlaceholder, dock);
        }
        wrap.insertAdjacentElement('afterend', dock);
        movedDock = dock;
      }
    } else {
      document.body.classList.remove('tng-map-mobile-layout');
      if (movedDock && dockPlaceholder?.parentNode) {
        dockPlaceholder.parentNode.insertBefore(movedDock, dockPlaceholder);
        dockPlaceholder.remove();
        dockPlaceholder = null;
        movedDock = null;
      }
    }
  };

  const sync = () => {
    placeTripDock();
    hideLegacyXp();
  };

  // Late-injected legacy UI was the reason the old XP strip survived earlier CSS-only fixes.
  observer = new MutationObserver(() => {
    window.clearTimeout(scanTimer);
    scanTimer = window.setTimeout(sync, 40);
  });
  observer.observe(document.body, { childList: true, subtree: true });

  mobileQuery.addEventListener?.('change', sync);
  window.addEventListener('resize', sync, { passive: true });
  window.addEventListener('pageshow', sync);

  sync();
  window.setTimeout(sync, 250);
  window.setTimeout(sync, 900);
  window.setTimeout(sync, 2200);
})();
