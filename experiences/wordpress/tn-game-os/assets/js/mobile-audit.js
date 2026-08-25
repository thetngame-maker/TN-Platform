(() => {
  'use strict';

  const mobile = window.matchMedia('(max-width: 430px)');

  const dedupe = selector => {
    const nodes = [...document.querySelectorAll(selector)];
    nodes.slice(1).forEach(node => node.remove());
  };

  const syncViewport = () => {
    const height = window.visualViewport?.height || window.innerHeight;
    document.documentElement.style.setProperty('--tng-mobile-vh', `${Math.round(height)}px`);
  };

  const sync = () => {
    dedupe('.tng-topbar');
    dedupe('.tng-app-nav');
    if (!mobile.matches) return;
    document.body.classList.add('tng-mobile-audited');
    syncViewport();
  };

  mobile.addEventListener?.('change', sync);
  window.visualViewport?.addEventListener('resize', syncViewport, { passive: true });
  window.addEventListener('pageshow', sync);
  window.addEventListener('orientationchange', syncViewport, { passive: true });
  sync();
})();
