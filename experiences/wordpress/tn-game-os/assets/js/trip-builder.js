(() => {
  const list = document.querySelector('[data-tng-builder-list]');
  if (!list || !window.TNGTripData) return;
  const status = document.querySelector('[data-tng-builder-status]');
  const mapEl = document.getElementById('tng-trip-builder-map');
  let dragged = null;
  let saveTimer = null;
  let tripMap = null;
  let mapLayer = null;
  let routeLine = null;

  const validCoord = value => Number.isFinite(Number(value));
  const orderedStops = () => [...list.children].map((item, index) => ({
    el: item,
    id: Number(item.dataset.postId || 0),
    title: item.dataset.title || item.querySelector('h3')?.textContent?.trim() || `Stop ${index + 1}`,
    lat: validCoord(item.dataset.lat) ? Number(item.dataset.lat) : null,
    lng: validCoord(item.dataset.lng) ? Number(item.dataset.lng) : null,
    order: index + 1
  }));

  const routeIcon = number => L.divIcon({
    className: 'tng-builder-map-marker-wrap',
    html: `<span class="tng-builder-map-marker">${number}</span>`,
    iconSize: [40, 40],
    iconAnchor: [20, 34],
    popupAnchor: [0, -30]
  });

  const initMap = () => {
    if (!mapEl || !window.L || tripMap) return;
    tripMap = L.map(mapEl, { zoomControl: false, scrollWheelZoom: true, attributionControl: true }).setView([35.2, -85.7], 10);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(tripMap);
    L.control.zoom({ position: 'topright' }).addTo(tripMap);
    mapLayer = L.layerGroup().addTo(tripMap);
    window.setTimeout(() => tripMap.invalidateSize(), 100);
  };

  const drawMap = () => {
    if (!mapEl || !window.L) return;
    initMap();
    if (!tripMap || !mapLayer) return;
    mapLayer.clearLayers();
    if (routeLine) { routeLine.remove(); routeLine = null; }

    const mapped = orderedStops().filter(stop => stop.lat !== null && stop.lng !== null);
    const points = [];
    mapped.forEach(stop => {
      const marker = L.marker([stop.lat, stop.lng], { icon: routeIcon(stop.order), keyboard: true })
        .bindPopup(`<strong>${stop.order}. ${String(stop.title).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))}</strong>`)
        .on('click', () => stop.el.scrollIntoView({ behavior: 'smooth', block: 'center' }));
      mapLayer.addLayer(marker);
      points.push([stop.lat, stop.lng]);
    });

    if (points.length > 1) {
      routeLine = L.polyline(points, { color: '#ef6022', weight: 5, opacity: 0.82, dashArray: '10 8', lineCap: 'round', lineJoin: 'round' }).addTo(tripMap);
    }
    if (points.length) tripMap.fitBounds(L.latLngBounds(points), { padding: [48, 48], maxZoom: 13 });

    const mapCount = document.querySelector('[data-tng-builder-map-count]');
    if (mapCount) mapCount.textContent = String(mapped.length);
  };

  const renumber = () => {
    [...list.children].forEach((item, index) => {
      const number = item.querySelector('.tng-builder-stop__number');
      if (number) number.textContent = String(index + 1);
    });
    const count = document.querySelector('[data-tng-builder-count]');
    if (count) count.textContent = String(list.children.length);
    drawMap();
  };

  const save = () => {
    clearTimeout(saveTimer);
    if (status) status.textContent = 'Saving…';
    saveTimer = setTimeout(async () => {
      const ids = [...list.children].map((item) => item.dataset.postId).filter(Boolean);
      const body = new URLSearchParams({ action: 'tng_reorder_saved', nonce: TNGTripData.nonce });
      ids.forEach((id) => body.append('ids[]', id));
      try {
        const response = await fetch(TNGTripData.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body });
        const json = await response.json();
        if (!json.success) throw new Error('save_failed');
        if (status) status.textContent = 'Saved';
        document.dispatchEvent(new CustomEvent('tng:trip-order-updated', { detail: { ids: json.data.ids || ids.map(Number) } }));
      } catch (error) {
        if (status) status.textContent = 'Could not save';
      }
    }, 250);
  };

  list.addEventListener('click', (event) => {
    const button = event.target.closest('[data-move]');
    if (!button) return;
    const item = button.closest('.tng-builder-stop');
    if (!item) return;
    if (button.dataset.move === 'up' && item.previousElementSibling) list.insertBefore(item, item.previousElementSibling);
    if (button.dataset.move === 'down' && item.nextElementSibling) list.insertBefore(item.nextElementSibling, item);
    renumber(); save();
  });

  list.addEventListener('dragstart', (event) => {
    dragged = event.target.closest('.tng-builder-stop');
    if (!dragged) return;
    dragged.classList.add('is-dragging');
    event.dataTransfer.effectAllowed = 'move';
  });
  list.addEventListener('dragend', () => {
    if (dragged) dragged.classList.remove('is-dragging');
    dragged = null; renumber(); save();
  });
  list.addEventListener('dragover', (event) => {
    event.preventDefault();
    if (!dragged) return;
    const target = event.target.closest('.tng-builder-stop');
    if (!target || target === dragged) return;
    const box = target.getBoundingClientRect();
    list.insertBefore(dragged, event.clientY < box.top + box.height / 2 ? target : target.nextSibling);
  });

  document.addEventListener('tng:trip-updated', (event) => {
    const detail = event.detail || {};
    if (detail.saved !== false) return;
    const item = list.querySelector(`[data-post-id="${detail.postId}"]`);
    if (item) item.remove();
    renumber(); save();
  });

  initMap();
  renumber();
  window.addEventListener('resize', () => tripMap && window.setTimeout(() => tripMap.invalidateSize(), 80));
})();