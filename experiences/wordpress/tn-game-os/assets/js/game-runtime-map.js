(() => {
  const data = window.TNG_GAME_MAP;
  if (!data || !Array.isArray(data.checkpoints) || !data.checkpoints.length || typeof L === 'undefined') return;

  const mount = () => {
    const main = document.querySelector('.tng-game-runtime');
    const progress = document.querySelector('.tng-runtime-progress');
    const list = document.querySelector('.tng-runtime-list');
    if (!main || !progress || !list || document.querySelector('[data-tng-runtime-map]')) return;

    const section = document.createElement('section');
    section.className = 'tng-runtime-map-panel';
    section.setAttribute('data-tng-runtime-map', '');
    section.innerHTML = `
      <div class="tng-runtime-map-panel__head">
        <div><span class="tng-eyebrow">${data.labels.eyebrow}</span><h2>${data.labels.title}</h2><p>${data.labels.subtitle}</p></div>
        <div class="tng-runtime-map-panel__actions"><button type="button" data-tng-game-locate>⌖ ${data.labels.locate}</button></div>
      </div>
      <div class="tng-runtime-map" data-tng-game-map aria-label="Live game checkpoint map"></div>
      <div class="tng-runtime-map-status" data-tng-map-status></div>`;
    progress.insertAdjacentElement('afterend', section);

    const mapNode = section.querySelector('[data-tng-game-map]');
    const status = section.querySelector('[data-tng-map-status]');
    const map = L.map(mapNode, { zoomControl: true, scrollWheelZoom: false });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    const bounds = [];
    const markerByIndex = new Map();

    const markerHtml = (checkpoint) => {
      const cls = checkpoint.completed ? 'is-complete' : (checkpoint.current ? 'is-current' : 'is-locked');
      const label = checkpoint.completed ? '✓' : String(checkpoint.index + 1);
      return `<div class="tng-runtime-marker ${cls}">${label}</div>`;
    };

    data.checkpoints.forEach((checkpoint) => {
      const icon = L.divIcon({ className: '', html: markerHtml(checkpoint), iconSize: [38,38], iconAnchor: [19,19] });
      const marker = L.marker([checkpoint.lat, checkpoint.lng], { icon }).addTo(map);
      const state = checkpoint.completed ? data.labels.completed : (checkpoint.current ? data.labels.current : data.labels.locked);
      const popup = document.createElement('div');
      popup.className = 'tng-runtime-map-popup';
      popup.innerHTML = `<small>${state}</small><strong></strong><p></p><button type="button">View checkpoint ↓</button>`;
      popup.querySelector('strong').textContent = checkpoint.title;
      popup.querySelector('p').textContent = checkpoint.instructions || `${checkpoint.radius} m checkpoint radius`;
      popup.querySelector('button').addEventListener('click', () => {
        const stop = document.querySelector(`.tng-runtime-stop:nth-of-type(${checkpoint.index + 1})`);
        if (stop) stop.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
      marker.bindPopup(popup);
      markerByIndex.set(checkpoint.index, marker);
      bounds.push([checkpoint.lat, checkpoint.lng]);

      if (checkpoint.radius && checkpoint.current) {
        L.circle([checkpoint.lat, checkpoint.lng], {
          radius: checkpoint.radius,
          color: '#ef6425',
          weight: 2,
          opacity: .65,
          fillOpacity: .08
        }).addTo(map);
      }
    });

    if (bounds.length === 1) map.setView(bounds[0], 16);
    else map.fitBounds(bounds, { padding: [44,44], maxZoom: 16 });

    const current = data.checkpoints.find((cp) => cp.current);
    if (current && markerByIndex.has(current.index)) markerByIndex.get(current.index).openPopup();

    let playerMarker = null;
    let accuracyCircle = null;
    const locate = () => {
      if (!navigator.geolocation) {
        status.textContent = data.labels.locationError;
        status.classList.add('is-visible');
        return;
      }
      navigator.geolocation.getCurrentPosition((position) => {
        status.classList.remove('is-visible');
        const latlng = [position.coords.latitude, position.coords.longitude];
        const icon = L.divIcon({ className: '', html: '<div class="tng-runtime-player"></div>', iconSize: [22,22], iconAnchor: [11,11] });
        if (playerMarker) playerMarker.setLatLng(latlng); else playerMarker = L.marker(latlng, { icon, zIndexOffset: 1000 }).addTo(map).bindPopup('You are here');
        if (accuracyCircle) accuracyCircle.setLatLng(latlng).setRadius(position.coords.accuracy || 10);
        else accuracyCircle = L.circle(latlng, { radius: position.coords.accuracy || 10, color: '#ef6425', weight: 1, opacity: .35, fillOpacity: .06 }).addTo(map);
        const all = bounds.concat([latlng]);
        if (all.length > 1) map.fitBounds(all, { padding: [44,44], maxZoom: 17 }); else map.setView(latlng, 16);
      }, () => {
        status.textContent = data.labels.locationError;
        status.classList.add('is-visible');
      }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 15000 });
    };

    section.querySelector('[data-tng-game-locate]').addEventListener('click', locate);
    setTimeout(() => map.invalidateSize(), 150);
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
  else mount();
})();
