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

  const markerLayer = L.layerGroup().addTo(map);
  const markers = new Map();
  const resultNodes = new Map();
  let activeFilter = 'all';
  let userMarker = null;

  const icons = {
    trail: '🥾', game: '🎮', sight: '📍', food: '🍽️', event: '🎵', destination: '🗺️', place: '•'
  };

  const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
  const markerIcon = (item) => L.divIcon({
    className: 'tng-discovery-marker-wrap',
    html: `<button type="button" class="tng-discovery-marker tng-discovery-marker--${esc(item.kind)}" aria-label="${esc(item.title)}"><span>${icons[item.kind] || '•'}</span></button>`,
    iconSize: [44, 44], iconAnchor: [22, 40], popupAnchor: [0, -36]
  });

  const popup = (item) => {
    const image = item.image ? `<span class="tng-map-popup__media" style="background-image:url('${esc(item.image)}')"></span>` : '';
    const action = item.kind === 'game' ? 'View game' : 'Explore';
    return `<article class="tng-map-popup">${image}<div><small>${esc(item.label)}</small><strong>${esc(item.title)}</strong>${item.subtitle ? `<p>${esc(item.subtitle)}</p>` : ''}<a href="${esc(item.url)}">${action} →</a></div></article>`;
  };

  const showItem = (item) => activeFilter === 'all' || item.kind === activeFilter;

  const render = () => {
    markerLayer.clearLayers();
    markers.clear();
    const bounds = [];
    cfg.items.forEach(item => {
      const row = resultNodes.get(String(item.id));
      const visible = showItem(item);
      if (row) row.hidden = !visible;
      if (!visible || !Number.isFinite(Number(item.lat)) || !Number.isFinite(Number(item.lng))) return;
      const marker = L.marker([Number(item.lat), Number(item.lng)], { icon: markerIcon(item), keyboard: true })
        .bindPopup(popup(item), { className: 'tng-discovery-popup', maxWidth: 300 })
        .addTo(markerLayer);
      marker.on('click', () => {
        document.querySelectorAll('.tng-map-result.is-active').forEach(n => n.classList.remove('is-active'));
        if (row) {
          row.classList.add('is-active');
          row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      });
      markers.set(String(item.id), marker);
      bounds.push([Number(item.lat), Number(item.lng)]);
    });
    const count = document.querySelector('[data-tng-map-count]');
    if (count) count.textContent = `${bounds.length} places on map`;
    if (bounds.length && !userMarker) map.fitBounds(bounds, { padding: [42, 42], maxZoom: 13 });
  };

  document.querySelectorAll('[data-tng-map-result]').forEach(node => {
    resultNodes.set(node.getAttribute('data-tng-map-result'), node);
    node.addEventListener('click', event => {
      const id = node.getAttribute('data-tng-map-result');
      const marker = markers.get(id);
      if (!marker) return;
      if (event.target.closest('a[data-tng-open-details]')) return;
      event.preventDefault();
      map.setView(marker.getLatLng(), Math.max(map.getZoom(), 14), { animate: true });
      marker.openPopup();
      document.querySelectorAll('.tng-map-result.is-active').forEach(n => n.classList.remove('is-active'));
      node.classList.add('is-active');
    });
  });

  document.querySelectorAll('[data-tng-map-filter]').forEach(button => {
    button.addEventListener('click', () => {
      activeFilter = button.getAttribute('data-tng-map-filter') || 'all';
      document.querySelectorAll('[data-tng-map-filter]').forEach(b => b.classList.toggle('is-active', b === button));
      render();
    });
  });

  const locate = document.querySelector('[data-tng-locate]');
  if (locate) locate.addEventListener('click', () => {
    if (!navigator.geolocation) return;
    locate.classList.add('is-loading');
    navigator.geolocation.getCurrentPosition(pos => {
      const latlng = [pos.coords.latitude, pos.coords.longitude];
      if (userMarker) userMarker.remove();
      userMarker = L.circleMarker(latlng, { radius: 9, weight: 4, color: '#fff', fillColor: '#f26722', fillOpacity: 1 })
        .bindTooltip('You are here', { permanent: false, direction: 'top' })
        .addTo(map);
      map.setView(latlng, 14, { animate: true });
      locate.classList.remove('is-loading');
      locate.innerHTML = '<span>⌖</span> Location found';
    }, () => {
      locate.classList.remove('is-loading');
      locate.innerHTML = '<span>⌖</span> Try location again';
    }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
  });

  render();
  setTimeout(() => map.invalidateSize(), 150);
})();
