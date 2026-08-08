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
        <div class="tng-runtime-map-panel__actions"><span class="tng-runtime-map-badge" data-tng-route-badge>${data.routeUrl ? 'Loading route…' : data.labels.routeUnavailable}</span><button type="button" data-tng-game-locate>⌖ ${data.labels.locate}</button></div>
      </div>
      <div class="tng-runtime-map" data-tng-game-map aria-label="Live game checkpoint map"></div>
      <div class="tng-runtime-map-status" data-tng-map-status></div>`;
    progress.insertAdjacentElement('afterend', section);

    const mapNode = section.querySelector('[data-tng-game-map]');
    const status = section.querySelector('[data-tng-map-status]');
    const routeBadge = section.querySelector('[data-tng-route-badge]');
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
      popup.innerHTML = `<small>${state}</small><strong></strong><p></p><button type="button" data-tng-view-checkpoint>View checkpoint ↓</button><button type="button" data-tng-dev-teleport hidden>🧪 Teleport here</button>`;
      popup.querySelector('strong').textContent = checkpoint.title;
      popup.querySelector('p').textContent = checkpoint.instructions || `${checkpoint.radius} m checkpoint radius`;
      popup.querySelector('[data-tng-view-checkpoint]').addEventListener('click', () => {
        const stops = document.querySelectorAll('.tng-runtime-stop');
        const stop = stops[checkpoint.index];
        if (stop) stop.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
      const teleportButton = popup.querySelector('[data-tng-dev-teleport]');
      teleportButton.dataset.checkpointIndex = String(checkpoint.index);
      teleportButton.dataset.checkpointLat = String(checkpoint.lat);
      teleportButton.dataset.checkpointLng = String(checkpoint.lng);
      teleportButton.dataset.checkpointCurrent = checkpoint.current ? '1' : '0';
      teleportButton.dataset.checkpointType = checkpoint.type || '';
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

    const fitAll = () => {
      if (bounds.length === 1) map.setView(bounds[0], 16);
      else if (bounds.length > 1) map.fitBounds(bounds, { padding: [44,44], maxZoom: 16 });
    };
    fitAll();

    const loadRoute = async () => {
      if (!data.routeUrl) return;
      try {
        const response = await fetch(data.routeUrl, { credentials: 'same-origin' });
        if (!response.ok) throw new Error('Route request failed');
        const xmlText = await response.text();
        const xml = new DOMParser().parseFromString(xmlText, 'application/xml');
        if (xml.querySelector('parsererror')) throw new Error('Invalid GPX');
        const points = Array.from(xml.querySelectorAll('trkpt, rtept')).map((node) => {
          const lat = Number(node.getAttribute('lat'));
          const lng = Number(node.getAttribute('lon'));
          return Number.isFinite(lat) && Number.isFinite(lng) ? [lat, lng] : null;
        }).filter(Boolean);
        if (points.length < 2) throw new Error('No route points');

        L.polyline(points, {
          color: '#ef6425',
          weight: 5,
          opacity: .9,
          lineJoin: 'round',
          lineCap: 'round'
        }).addTo(map).bringToBack();
        points.forEach((point) => bounds.push(point));
        map.fitBounds(bounds, { padding: [44,44], maxZoom: 16 });
        routeBadge.textContent = data.labels.routeReady;
        routeBadge.classList.add('is-ready');
      } catch (error) {
        routeBadge.textContent = data.labels.routeUnavailable;
      }
    };
    loadRoute();

    const current = data.checkpoints.find((cp) => cp.current);
    if (current && markerByIndex.has(current.index)) markerByIndex.get(current.index).openPopup();

    let playerMarker = null;
    let accuracyCircle = null;
    const placePlayer = (lat, lng, accuracy = 10, label = 'You are here', simulated = false) => {
      const latlng = [Number(lat), Number(lng)];
      if (!Number.isFinite(latlng[0]) || !Number.isFinite(latlng[1])) return;
      const icon = L.divIcon({ className: '', html: `<div class="tng-runtime-player${simulated ? ' is-simulated' : ''}"></div>`, iconSize: [22,22], iconAnchor: [11,11] });
      if (playerMarker) {
        playerMarker.setLatLng(latlng).setIcon(icon);
        playerMarker.setPopupContent(label);
      } else {
        playerMarker = L.marker(latlng, { icon, zIndexOffset: 1000 }).addTo(map).bindPopup(label);
      }
      if (accuracyCircle) accuracyCircle.setLatLng(latlng).setRadius(accuracy || 10);
      else accuracyCircle = L.circle(latlng, { radius: accuracy || 10, color: '#ef6425', weight: 1, opacity: .35, fillOpacity: .06 }).addTo(map);
      map.setView(latlng, Math.max(map.getZoom(), 17));
      playerMarker.openPopup();
    };

    const locate = () => {
      if (!navigator.geolocation) {
        status.textContent = data.labels.locationError;
        status.classList.add('is-visible');
        return;
      }
      navigator.geolocation.getCurrentPosition((position) => {
        status.classList.remove('is-visible');
        const latlng = [position.coords.latitude, position.coords.longitude];
        placePlayer(latlng[0], latlng[1], position.coords.accuracy || 10, 'You are here', false);
        const all = bounds.concat([latlng]);
        if (all.length > 1) map.fitBounds(all, { padding: [44,44], maxZoom: 17 }); else map.setView(latlng, 16);
      }, () => {
        status.textContent = data.labels.locationError;
        status.classList.add('is-visible');
      }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 15000 });
    };

    window.addEventListener('tng:developer-location', (event) => {
      const detail = event && event.detail ? event.detail : {};
      const lat = Number(detail.lat);
      const lng = Number(detail.lng);
      if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
      status.textContent = detail.title ? `Developer location: ${detail.title}` : 'Developer simulated location loaded.';
      status.classList.add('is-visible');
      placePlayer(lat, lng, 5, detail.title ? `Developer: ${detail.title}` : 'Developer simulated location', true);
    });

    section.querySelector('[data-tng-game-locate]').addEventListener('click', locate);
    setTimeout(() => map.invalidateSize(), 150);
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
  else mount();
})();
