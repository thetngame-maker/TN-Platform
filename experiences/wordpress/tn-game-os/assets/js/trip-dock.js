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

  hideLegacyTripUi();
  const observer = new MutationObserver(hideLegacyTripUi);
  observer.observe(document.documentElement, { childList: true, subtree: true });
  window.setTimeout(hideLegacyTripUi, 500);
  window.setTimeout(hideLegacyTripUi, 1800);
})();
