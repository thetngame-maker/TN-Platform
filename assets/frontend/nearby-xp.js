(() => {
  'use strict';
  const cfg = window.TNG_NEARBY_XP || {};

  const init = () => {
    const card = document.querySelector('[data-tng-nearby-xp]');
    if (!card) return;
    const main = document.querySelector('.tng-app-shell,main');
    const check = card.querySelector('[data-tng-nearby-check]');
    const dismiss = card.querySelector('[data-tng-nearby-dismiss]');
    const title = card.querySelector('[data-tng-nearby-title]');
    const status = card.querySelector('[data-tng-nearby-status]');
    const freshAward = Boolean(cfg.openAward && cfg.openAward.awarded);
    const dismissed = window.sessionStorage?.getItem('tng-nearby-xp-dismissed') === '1';

    if (main && card.parentElement !== main) main.insertBefore(card, main.firstChild);
    card.hidden = dismissed && !freshAward;
    if (freshAward) card.classList.add('is-awarded');

    dismiss?.addEventListener('click', () => {
      card.hidden = true;
      window.sessionStorage?.setItem('tng-nearby-xp-dismissed', '1');
    });

    if (!check) return;
    check.addEventListener('click', () => {
      if (check.dataset.discoveryUrl) {
        window.location.href = check.dataset.discoveryUrl;
        return;
      }
      if (!navigator.geolocation) {
        status.textContent = 'Location is not available on this device.';
        return;
      }
      check.disabled = true;
      check.textContent = 'Finding…';
      status.textContent = 'Checking the Universal Map around you. Coordinates are not stored.';
      navigator.geolocation.getCurrentPosition(position => {
        fetch(cfg.endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || ''},
          body: JSON.stringify({lat: position.coords.latitude, lng: position.coords.longitude})
        }).then(async response => {
          const data = await response.json().catch(() => ({}));
          if (!response.ok) throw new Error(data.message || 'Nearby XP is unavailable right now.');
          return data;
        }).then(data => {
          status.textContent = data.message || 'Nearby check complete.';
          if (data.nearby) {
            title.textContent = data.awarded ? `Nearby discovery · +${Number(data.amount || 0)} XP` : 'Discovery already found';
            card.classList.toggle('is-awarded', Boolean(data.awarded));
          } else title.textContent = 'Closest TN Game discovery';
          if (data.item?.url) {
            check.dataset.discoveryUrl = data.item.url;
            check.textContent = 'View discovery';
          } else check.textContent = 'Check again';
        }).catch(error => {
          status.textContent = error.message || 'Nearby XP is unavailable right now.';
          check.textContent = 'Try again';
        }).finally(() => { check.disabled = false; });
      }, error => {
        status.textContent = error.code === 1
          ? 'Location access is off. You still earned your daily open bonus.'
          : 'Your device could not find a reliable location. Try again outside.';
        check.textContent = 'Try again';
        check.disabled = false;
      }, {enableHighAccuracy: true, timeout: 10000, maximumAge: 60000});
    });
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
  else init();
})();
