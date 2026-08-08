(() => {
  const cfg = window.TNG_DISCOVERY_MAP || {};
  const el = document.getElementById('tng-discovery-map');
  if (!el || !window.L || !Array.isArray(cfg.items)) return;

  const defaultCenter = Array.isArray(cfg.center) ? cfg.center : [35.2, -85.7];
  const map = L.map(el, { zoomControl: false, scrollWheelZoom: true }).setView(defaultCenter, Number(cfg.zoom || 10));
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);
  L.control.zoom({ position: 'topright' }).addTo(map);

  const markerLayer = typeof L.markerClusterGroup === 'function'
    ? L.markerClusterGroup({
        showCoverageOnHover: false,
        spiderfyOnMaxZoom: true,
        removeOutsideVisibleBounds: true,
        maxClusterRadius: 52,
        iconCreateFunction(cluster) {
          const count = cluster.getChildCount();
          const size = count > 20 ? 'lg' : count > 8 ? 'md' : 'sm';
          return L.divIcon({
            html: `<button type="button" class="tng-map-cluster tng-map-cluster--${size}" aria-label="${count} discoveries"><span>${count}</span><small>places</small></button>`,
            className: 'tng-map-cluster-wrap',
            iconSize: [56, 56]
          });
        }
      })
    : L.layerGroup();
  markerLayer.addTo(map);

  const markers = new Map();
  const resultNodes = new Map();
  const itemById = new Map(cfg.items.map(item => [String(item.id), item]));
  const resultsEl = document.querySelector('[data-tng-map-results]');
  const panelIntro = document.querySelector('[data-tng-panel-intro]');
  let activeFilter = 'all';
  let userMarker = null;
  let userLatLng = null;
  let initialFitDone = false;

  const icons = {
    trail: '🥾', game: '🎮', sight: '📍', food: '🍽️', event: '🎵', destination: '🗺️', place: '•'
  };
  const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
  const markerIcon = item => L.divIcon({
    className: 'tng-discovery-marker-wrap',
    html: `<button type="button" class="tng-discovery-marker tng-discovery-marker--${esc(item.kind)}" aria-label="${esc(item.title)}"><span>${icons[item.kind] || '•'}</span></button>`,
    iconSize: [44, 44], iconAnchor: [22, 40], popupAnchor: [0, -36]
  });

  const popup = item => {
    const image = item.image ? `<span class="tng-map-popup__media" style="background-image:url('${esc(item.image)}')"></span>` : '';
    const action = item.kind === 'game' ? 'View game' : 'Explore';
    const distance = userLatLng ? `<b>${formatDistance(distanceMiles(userLatLng.lat, userLatLng.lng, Number(item.lat), Number(item.lng)))}</b>` : '';
    return `<article class="tng-map-popup">${image}<div><small>${esc(item.label)}</small><strong>${esc(item.title)}</strong>${distance}${item.subtitle ? `<p>${esc(item.subtitle)}</p>` : ''}<a href="${esc(item.url)}">${action} →</a></div></article>`;
  };

  const showItem = item => activeFilter === 'all' || item.kind === activeFilter;
  const distanceMiles = (lat1, lng1, lat2, lng2) => {
    const toRad = deg => deg * Math.PI / 180;
    const r = 3958.8;
    const dLat = toRad(lat2 - lat1);
    const dLng = toRad(lng2 - lng1);
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;
    return 2 * r * Math.asin(Math.sqrt(a));
  };
  const formatDistance = miles => miles < 0.1 ? 'Nearby' : miles < 10 ? `${miles.toFixed(1)} mi` : `${Math.round(miles)} mi`;

  const renderMarkers = ({ fit = false } = {}) => {
    markerLayer.clearLayers();
    markers.clear();
    const bounds = [];
    cfg.items.forEach(item => {
      if (!showItem(item) || !Number.isFinite(Number(item.lat)) || !Number.isFinite(Number(item.lng))) return;
      const marker = L.marker([Number(item.lat), Number(item.lng)], { icon: markerIcon(item), keyboard: true })
        .bindPopup(() => popup(item), { className: 'tng-discovery-popup', maxWidth: 300 });
      marker.on('click', () => activateResult(item.id));
      markerLayer.addLayer(marker);
      markers.set(String(item.id), marker);
      bounds.push([Number(item.lat), Number(item.lng)]);
    });
    if ((fit || !initialFitDone) && bounds.length && !userLatLng) {
      map.fitBounds(bounds, { padding: [42, 42], maxZoom: 13 });
      initialFitDone = true;
    }
    updateLiveResults();
  };

  const activateResult = id => {
    document.querySelectorAll('.tng-map-result.is-active').forEach(n => n.classList.remove('is-active'));
    const row = resultNodes.get(String(id));
    if (row) {
      row.hidden = false;
      row.classList.add('is-active');
      row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  };

  const updateLiveResults = () => {
    const bounds = map.getBounds();
    let visible = cfg.items.filter(item => {
      if (!showItem(item)) return false;
      const lat = Number(item.lat), lng = Number(item.lng);
      return Number.isFinite(lat) && Number.isFinite(lng) && bounds.contains([lat, lng]);
    });

    if (!visible.length) visible = cfg.items.filter(showItem);
    if (userLatLng) {
      visible = visible.slice().sort((a, b) =>
        distanceMiles(userLatLng.lat, userLatLng.lng, Number(a.lat), Number(a.lng)) -
        distanceMiles(userLatLng.lat, userLatLng.lng, Number(b.lat), Number(b.lng))
      );
    }

    const visibleIds = new Set(visible.map(item => String(item.id)));
    resultNodes.forEach((node, id) => {
      node.hidden = !visibleIds.has(id);
      const item = itemById.get(id);
      const distanceEl = node.querySelector('[data-tng-distance]');
      if (distanceEl) {
        if (userLatLng && item) {
          distanceEl.textContent = formatDistance(distanceMiles(userLatLng.lat, userLatLng.lng, Number(item.lat), Number(item.lng)));
          distanceEl.hidden = false;
        } else {
          distanceEl.textContent = '';
          distanceEl.hidden = true;
        }
      }
    });

    if (resultsEl) visible.forEach(item => {
      const node = resultNodes.get(String(item.id));
      if (node) resultsEl.appendChild(node);
    });

    const count = document.querySelector('[data-tng-map-count]');
    if (count) count.textContent = `${visible.length} ${visible.length === 1 ? 'place' : 'places'} in view`;
    if (panelIntro) panelIntro.textContent = userLatLng
      ? 'Showing discoveries in this map view, sorted by distance from you.'
      : 'Showing discoveries currently visible on the map. Move or zoom to explore another area.';
  };

  document.querySelectorAll('[data-tng-map-result]').forEach(node => {
    resultNodes.set(node.getAttribute('data-tng-map-result'), node);
    node.addEventListener('click', event => {
      if (event.target.closest('a[data-tng-open-details]')) return;
      event.preventDefault();
      const id = node.getAttribute('data-tng-map-result');
      const marker = markers.get(id);
      if (!marker) return;
      const openMarker = () => {
        map.setView(marker.getLatLng(), Math.max(map.getZoom(), 14), { animate: true });
        marker.openPopup();
        activateResult(id);
      };
      if (typeof markerLayer.zoomToShowLayer === 'function') markerLayer.zoomToShowLayer(marker, openMarker);
      else openMarker();
    });
  });

  document.querySelectorAll('[data-tng-map-filter]').forEach(button => {
    button.addEventListener('click', () => {
      activeFilter = button.getAttribute('data-tng-map-filter') || 'all';
      document.querySelectorAll('[data-tng-map-filter]').forEach(b => b.classList.toggle('is-active', b === button));
      renderMarkers({ fit: true });
    });
  });

  map.on('moveend zoomend', updateLiveResults);

  const locate = document.querySelector('[data-tng-locate]');
  if (locate) locate.addEventListener('click', () => {
    if (!navigator.geolocation) return;
    locate.classList.add('is-loading');
    navigator.geolocation.getCurrentPosition(pos => {
      const latlng = L.latLng(pos.coords.latitude, pos.coords.longitude);
      userLatLng = latlng;
      if (userMarker) userMarker.remove();
      userMarker = L.circleMarker(latlng, { radius: 9, weight: 4, color: '#fff', fillColor: '#f26722', fillOpacity: 1 })
        .bindTooltip('You are here', { permanent: false, direction: 'top' })
        .addTo(map);
      map.setView(latlng, 13, { animate: true });
      locate.classList.remove('is-loading');
      locate.innerHTML = '<span>⌖</span> Near me';
      setTimeout(updateLiveResults, 250);
    }, () => {
      locate.classList.remove('is-loading');
      locate.innerHTML = '<span>⌖</span> Try location again';
    }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
  });

  renderMarkers();
  setTimeout(() => { map.invalidateSize(); updateLiveResults(); }, 180);
})();
