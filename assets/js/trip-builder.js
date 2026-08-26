(() => {
  const list = document.querySelector('[data-tng-builder-list]');
  if (!list || !window.TNGTripData) return;
  const status = document.querySelector('[data-tng-builder-status]');
  const mapEl = document.getElementById('tng-trip-builder-map');
  const optimizeButton = document.querySelector('[data-tng-optimize-route]');
  const routeDistanceEl = document.querySelector('[data-tng-route-distance]');
  const routeTimeEl = document.querySelector('[data-tng-route-time]');
  let dragged = null;
  let saveTimer = null;
  let routeTimer = null;
  let tripMap = null;
  let mapLayer = null;
  let routeLine = null;
  let routeRequest = null;
  let routeSeq = 0;

  const validCoord = value => Number.isFinite(Number(value));
  const orderedStops = () => [...list.children].map((item, index) => ({
    el: item,
    id: Number(item.dataset.postId || 0),
    title: item.dataset.title || item.querySelector('h3')?.textContent?.trim() || `Stop ${index + 1}`,
    lat: validCoord(item.dataset.lat) ? Number(item.dataset.lat) : null,
    lng: validCoord(item.dataset.lng) ? Number(item.dataset.lng) : null,
    order: index + 1
  }));

  const haversineMiles = (a, b) => {
    const toRad = value => value * Math.PI / 180;
    const earth = 3958.8;
    const dLat = toRad(b.lat - a.lat);
    const dLng = toRad(b.lng - a.lng);
    const x = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLng / 2) ** 2;
    return 2 * earth * Math.asin(Math.sqrt(x));
  };

  const estimatedRoadMiles = (a, b) => haversineMiles(a, b) * 1.18;
  const formatMiles = miles => miles < 0.1 ? '<0.1 mi' : miles < 10 ? `${miles.toFixed(1)} mi` : `${Math.round(miles)} mi`;
  const formatMinutes = minutes => {
    const rounded = Math.max(0, Math.round(minutes));
    if (rounded < 60) return `${Math.max(1, rounded)} min`;
    const hours = Math.floor(rounded / 60);
    const mins = rounded % 60;
    return mins ? `${hours} hr ${mins} min` : `${hours} hr`;
  };

  const routeIcon = number => L.divIcon({
    className: 'tng-builder-map-marker-wrap',
    html: `<span class="tng-builder-map-marker"><i>${number}</i></span>`,
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

  const setLegText = (stop, text) => {
    const leg = stop.el.querySelector('[data-tng-leg-distance]');
    if (leg) leg.textContent = text;
  };

  const applyEstimatedIntelligence = () => {
    const stops = orderedStops();
    const mapped = stops.filter(stop => stop.lat !== null && stop.lng !== null);
    let totalMiles = 0;
    let totalMinutes = 0;
    let previousMapped = null;
    stops.forEach(stop => {
      if (stop.lat === null || stop.lng === null) return;
      if (!previousMapped) {
        setLegText(stop, 'Start here');
        previousMapped = stop;
        return;
      }
      const miles = estimatedRoadMiles(previousMapped, stop);
      totalMiles += miles;
      totalMinutes += (miles / 32) * 60 + 4;
      setLegText(stop, `≈ ${formatMiles(miles)} from previous stop`);
      previousMapped = stop;
    });
    if (routeDistanceEl) routeDistanceEl.textContent = mapped.length > 1 ? `≈ ${formatMiles(totalMiles)}` : 'Add another stop';
    if (routeTimeEl) routeTimeEl.textContent = mapped.length > 1 ? `≈ ${formatMinutes(totalMinutes)}` : 'Add another stop';
  };

  const saveRoadRoute = async (mapped, route) => {
    if (!route || !Array.isArray(route.legs) || mapped.length < 2) return;
    const payload = {
      ids: orderedStops().map(stop => stop.id),
      distance_m: route.distance || 0,
      duration_s: route.duration || 0,
      provider: 'osrm',
      legs: route.legs.map((leg, index) => ({
        from: mapped[index]?.id || 0,
        to: mapped[index + 1]?.id || 0,
        distance_m: leg.distance || 0,
        duration_s: leg.duration || 0
      }))
    };
    try {
      const body = new URLSearchParams({ action: 'tng_save_trip_route', nonce: TNGTripData.nonce, route: JSON.stringify(payload) });
      await fetch(TNGTripData.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body });
    } catch (error) {}
  };

  const requestRoadRoute = (mapped) => {
    clearTimeout(routeTimer);
    if (mapped.length < 2) return;
    routeTimer = window.setTimeout(async () => {
      const seq = ++routeSeq;
      if (routeRequest) routeRequest.abort();
      routeRequest = new AbortController();
      const coords = mapped.map(stop => `${stop.lng},${stop.lat}`).join(';');
      const url = `https://router.project-osrm.org/route/v1/driving/${coords}?overview=full&geometries=geojson&steps=false`;
      if (status) status.textContent = 'Calculating road route…';
      try {
        const response = await fetch(url, { signal: routeRequest.signal, mode: 'cors' });
        if (!response.ok) throw new Error('routing_failed');
        const json = await response.json();
        const route = json && json.code === 'Ok' && json.routes && json.routes[0] ? json.routes[0] : null;
        if (!route || seq !== routeSeq) throw new Error('routing_failed');

        if (routeLine) { routeLine.remove(); routeLine = null; }
        const roadPoints = Array.isArray(route.geometry?.coordinates)
          ? route.geometry.coordinates.map(point => [Number(point[1]), Number(point[0])]).filter(point => Number.isFinite(point[0]) && Number.isFinite(point[1]))
          : [];
        if (roadPoints.length > 1) {
          routeLine = L.polyline(roadPoints, { color: '#ef6022', weight: 6, opacity: 0.9, lineCap: 'round', lineJoin: 'round' }).addTo(tripMap);
          tripMap.fitBounds(routeLine.getBounds(), { padding: [48, 48], maxZoom: 13 });
        }

        if (routeDistanceEl) routeDistanceEl.textContent = formatMiles((route.distance || 0) / 1609.344);
        if (routeTimeEl) routeTimeEl.textContent = formatMinutes((route.duration || 0) / 60);
        mapped.forEach((stop, index) => {
          if (index === 0) return setLegText(stop, 'Start here');
          const leg = route.legs?.[index - 1];
          if (!leg) return;
          const miles = (leg.distance || 0) / 1609.344;
          const mins = (leg.duration || 0) / 60;
          setLegText(stop, `${formatMiles(miles)} · ${formatMinutes(mins)} from previous stop`);
        });
        if (status) status.textContent = 'Road route ready';
        window.setTimeout(() => { if (status && status.textContent === 'Road route ready') status.textContent = 'Saved'; }, 1500);
        saveRoadRoute(mapped, route);
      } catch (error) {
        if (error?.name === 'AbortError') return;
        if (status) status.textContent = 'Using planning estimate';
        applyEstimatedIntelligence();
      }
    }, 220);
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
      const safeTitle = String(stop.title).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
      const marker = L.marker([stop.lat, stop.lng], { icon: routeIcon(stop.order), keyboard: true })
        .bindPopup(`<strong>${stop.order}. ${safeTitle}</strong>`)
        .on('click', () => stop.el.scrollIntoView({ behavior: 'smooth', block: 'center' }));
      mapLayer.addLayer(marker);
      points.push([stop.lat, stop.lng]);
    });

    if (points.length > 1) {
      routeLine = L.polyline(points, { color: '#ef6022', weight: 4, opacity: 0.55, dashArray: '10 8', lineCap: 'round', lineJoin: 'round' }).addTo(tripMap);
    }
    if (points.length) tripMap.fitBounds(L.latLngBounds(points), { padding: [48, 48], maxZoom: 13 });

    const mapCount = document.querySelector('[data-tng-builder-map-count]');
    if (mapCount) mapCount.textContent = String(mapped.length);
    applyEstimatedIntelligence();
    requestRoadRoute(mapped);
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
        drawMap();
      } catch (error) {
        if (status) status.textContent = 'Could not save';
      }
    }, 250);
  };

  const optimizeRoute = () => {
    const all = orderedStops();
    const mapped = all.filter(stop => stop.lat !== null && stop.lng !== null);
    if (mapped.length < 3) return;

    const first = mapped[0];
    const remaining = mapped.slice(1);
    const optimized = [first];
    let current = first;
    while (remaining.length) {
      let nearestIndex = 0;
      let nearestDistance = Infinity;
      remaining.forEach((candidate, index) => {
        const distance = haversineMiles(current, candidate);
        if (distance < nearestDistance) {
          nearestDistance = distance;
          nearestIndex = index;
        }
      });
      current = remaining.splice(nearestIndex, 1)[0];
      optimized.push(current);
    }

    const unmapped = all.filter(stop => stop.lat === null || stop.lng === null);
    [...optimized, ...unmapped].forEach(stop => list.appendChild(stop.el));
    renumber();
    save();
    if (status) status.textContent = unmapped.length ? 'Optimized · unmapped stops kept last' : 'Route optimized';
  };

  if (optimizeButton) optimizeButton.addEventListener('click', optimizeRoute);

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