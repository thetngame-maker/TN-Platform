(() => {
  const cfg = window.TNGActiveTrip || {};
  const stops = () => [...document.querySelectorAll('[data-trip-stop]')];
  const mapEl = document.getElementById('tng-active-trip-map');
  let tripMap = null;
  let markerLayer = null;
  let fullRouteLine = null;
  let currentLegLine = null;
  let mapRequest = null;
  let legRequest = null;

  const updateSummary = (done, total) => {
    document.querySelectorAll('[data-tng-trip-progress]').forEach(el => { el.textContent = `${done}/${total}`; });
    document.querySelectorAll('[data-tng-trip-progress-bar]').forEach(el => { el.style.width = `${total ? Math.round((done / total) * 100) : 0}%`; });
  };

  const distanceMeters = (lat1, lng1, lat2, lng2) => {
    const toRad = value => value * Math.PI / 180;
    const earth = 6371000;
    const dLat = toRad(lat2 - lat1), dLng = toRad(lng2 - lng1);
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;
    return 2 * earth * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  };

  const mappedStops = () => stops().map((stop, index) => ({
    el: stop,
    id: Number(stop.dataset.tripStop || 0),
    order: Number(stop.dataset.tripOrder || index + 1),
    title: stop.querySelector('h3')?.textContent?.trim() || `Stop ${index + 1}`,
    lat: Number(stop.dataset.lat),
    lng: Number(stop.dataset.lng),
    complete: stop.classList.contains('is-complete')
  })).filter(stop => Number.isFinite(stop.lat) && Number.isFinite(stop.lng));

  const firstIncomplete = () => stops().find(stop => !stop.classList.contains('is-complete')) || null;

  const routeIcon = stop => L.divIcon({
    className: 'tng-active-map-marker-wrap',
    html: `<span class="tng-active-map-marker${stop.complete ? ' is-complete' : ''}"><i>${stop.complete ? '✓' : stop.order}</i></span>`,
    iconSize: [40, 40],
    iconAnchor: [20, 34],
    popupAnchor: [0, -30]
  });

  const initMap = () => {
    if (!mapEl || !window.L || tripMap) return;
    tripMap = L.map(mapEl, {zoomControl:false, scrollWheelZoom:true, attributionControl:true}).setView([35.2, -85.7], 10);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom:19, attribution:'&copy; OpenStreetMap contributors'}).addTo(tripMap);
    L.control.zoom({position:'topright'}).addTo(tripMap);
    markerLayer = L.layerGroup().addTo(tripMap);
    window.setTimeout(() => tripMap.invalidateSize(), 100);
  };

  const escapeHtml = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));

  const fetchRoadGeometry = async points => {
    if (points.length < 2) return null;
    const coords = points.map(point => `${point.lng},${point.lat}`).join(';');
    const response = await fetch(`https://router.project-osrm.org/route/v1/driving/${coords}?overview=full&geometries=geojson&steps=false`, {mode:'cors'});
    if (!response.ok) throw new Error('route_failed');
    const json = await response.json();
    if (json.code !== 'Ok' || !json.routes?.[0]?.geometry?.coordinates) throw new Error('route_failed');
    return json.routes[0].geometry.coordinates.map(([lng, lat]) => [lat, lng]);
  };

  const drawTripMap = async () => {
    if (!mapEl || !window.L) return;
    initMap();
    if (!tripMap || !markerLayer) return;
    markerLayer.clearLayers();
    if (fullRouteLine) { fullRouteLine.remove(); fullRouteLine = null; }
    if (currentLegLine) { currentLegLine.remove(); currentLegLine = null; }

    const mapped = mappedStops();
    mapped.forEach(stop => {
      const marker = L.marker([stop.lat, stop.lng], {icon:routeIcon(stop), keyboard:true})
        .bindPopup(`<strong>${stop.order}. ${escapeHtml(stop.title)}</strong>${stop.complete ? '<br><small>Completed</small>' : ''}`)
        .on('click', () => stop.el.scrollIntoView({behavior:'smooth', block:'center'}));
      markerLayer.addLayer(marker);
    });

    if (!mapped.length) return;
    const bounds = L.latLngBounds(mapped.map(stop => [stop.lat, stop.lng]));
    tripMap.fitBounds(bounds, {padding:[42,42], maxZoom:13});

    if (mapRequest) mapRequest.abort?.();
    const controller = new AbortController();
    mapRequest = controller;
    try {
      const geometry = await fetchRoadGeometry(mapped);
      if (controller.signal.aborted || !geometry) return;
      fullRouteLine = L.polyline(geometry, {color:'#355f4a', weight:5, opacity:.42, lineCap:'round', lineJoin:'round'}).addTo(tripMap);
    } catch (error) {
      if (controller.signal.aborted) return;
      fullRouteLine = L.polyline(mapped.map(stop => [stop.lat, stop.lng]), {color:'#355f4a', weight:4, opacity:.35, dashArray:'8 8'}).addTo(tripMap);
    }

    await drawCurrentLeg();
  };

  const drawCurrentLeg = async () => {
    if (!tripMap) return;
    if (currentLegLine) { currentLegLine.remove(); currentLegLine = null; }
    const all = mappedStops();
    const currentEl = firstIncomplete();
    const status = document.querySelector('[data-tng-active-map-status]');
    if (!currentEl) {
      if (status) status.textContent = 'Every stop is complete.';
      return;
    }
    const currentIndex = stops().indexOf(currentEl);
    const current = all.find(stop => stop.el === currentEl);
    if (!current) return;
    if (status) status.textContent = `Current leg highlighted to ${current.title}`;

    const previousEl = currentIndex > 0 ? stops()[currentIndex - 1] : null;
    const previous = previousEl ? all.find(stop => stop.el === previousEl) : null;
    if (!previous) {
      tripMap.panTo([current.lat, current.lng], {animate:true});
      return;
    }

    if (legRequest) legRequest.abort?.();
    const controller = new AbortController();
    legRequest = controller;
    try {
      const geometry = await fetchRoadGeometry([previous, current]);
      if (controller.signal.aborted || !geometry) return;
      currentLegLine = L.polyline(geometry, {color:'#ef6022', weight:7, opacity:.96, lineCap:'round', lineJoin:'round'}).addTo(tripMap);
      tripMap.fitBounds(currentLegLine.getBounds(), {padding:[55,55], maxZoom:13});
    } catch (error) {
      if (controller.signal.aborted) return;
      currentLegLine = L.polyline([[previous.lat,previous.lng],[current.lat,current.lng]], {color:'#ef6022', weight:6, opacity:.9, dashArray:'10 7'}).addTo(tripMap);
    }
  };

  const syncCurrentStop = () => {
    const current = firstIncomplete();
    stops().forEach(stop => {
      const isCurrent = stop === current;
      stop.classList.toggle('is-current', isCurrent);
      if (!stop.classList.contains('is-complete')) {
        const arrive = stop.querySelector('[data-trip-arrive]');
        const complete = stop.querySelector('[data-trip-complete]');
        if (arrive && !stop.classList.contains('is-arrived')) arrive.disabled = !isCurrent || !stop.dataset.lat || !stop.dataset.lng;
        if (complete && !stop.classList.contains('is-arrived')) complete.disabled = true;
      }
    });

    const heading = document.querySelector('[data-tng-trip-next-heading]');
    if (!current) {
      if (heading) heading.textContent = 'You finished every stop.';
      drawTripMap();
      return;
    }
    const title = current.querySelector('h3')?.textContent?.trim() || 'Next stop';
    if (heading) heading.textContent = `Next: ${title}`;
    const nextTitle = document.querySelector('[data-tng-next-title]');
    if (nextTitle) nextTitle.textContent = title;
    const nextDirections = document.querySelector('[data-tng-next-directions]');
    if (nextDirections && current.dataset.directions) nextDirections.href = current.dataset.directions;
    const nextView = document.querySelector('[data-tng-next-view]');
    const viewLink = current.querySelector('.tng-active-trip-stop__copy a');
    if (nextView && viewLink?.href) nextView.href = viewLink.href;
    const nextLeg = document.querySelector('[data-tng-next-leg]');
    const leg = current.querySelector('.tng-active-trip-leg');
    if (nextLeg && leg) nextLeg.textContent = leg.textContent.trim();
    drawTripMap();
  };

  document.addEventListener('click', event => {
    const fit = event.target.closest('[data-tng-fit-active-route]');
    if (!fit || !tripMap) return;
    const mapped = mappedStops();
    if (mapped.length) tripMap.fitBounds(L.latLngBounds(mapped.map(stop => [stop.lat, stop.lng])), {padding:[42,42], maxZoom:13});
  });

  document.addEventListener('click', event => {
    const arrive = event.target.closest('[data-trip-arrive]');
    if (!arrive) return;
    event.preventDefault();
    if (arrive.disabled || arrive.dataset.loading === '1') return;
    const stop = arrive.closest('[data-trip-stop]');
    if (!stop) return;
    const lat = Number(stop.dataset.lat), lng = Number(stop.dataset.lng);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
      window.alert('This stop does not have a usable GPS location yet.');
      return;
    }
    if (!navigator.geolocation) {
      window.alert('Location is not available in this browser.');
      return;
    }
    arrive.dataset.loading = '1';
    arrive.disabled = true;
    arrive.textContent = 'Checking location…';
    navigator.geolocation.getCurrentPosition(position => {
      const accuracy = Number(position.coords.accuracy || 0);
      const distance = distanceMeters(position.coords.latitude, position.coords.longitude, lat, lng);
      const radius = Number(cfg.arrivalRadius || 300);
      const allowed = radius + Math.min(Math.max(accuracy, 0), 150);
      if (distance <= allowed) {
        stop.classList.add('is-arrived');
        arrive.textContent = 'Arrived ✓';
        arrive.disabled = true;
        const complete = stop.querySelector('[data-trip-complete]');
        if (complete) {
          complete.disabled = false;
          complete.focus({preventScroll:true});
        }
      } else {
        arrive.textContent = 'I’m here';
        arrive.disabled = false;
        window.alert(`You are about ${Math.round(distance)} m from this stop. Get within ${radius} m to confirm arrival.`);
      }
      delete arrive.dataset.loading;
    }, error => {
      arrive.textContent = 'I’m here';
      arrive.disabled = false;
      delete arrive.dataset.loading;
      const message = error?.code === 1 ? 'Location permission was denied.' : 'Your location could not be determined.';
      window.alert(`${message} You can still open Directions and try again.`);
    }, {enableHighAccuracy:true, timeout:12000, maximumAge:15000});
  });

  document.addEventListener('click', async event => {
    const button = event.target.closest('[data-trip-complete]');
    if (!button) return;
    event.preventDefault();
    if (button.disabled || button.dataset.loading === '1') return;
    const postId = Number(button.dataset.postId || 0);
    const complete = button.getAttribute('aria-pressed') !== 'true';
    const stop = button.closest('[data-trip-stop]');
    button.dataset.loading = '1';
    button.disabled = true;
    try {
      const body = new URLSearchParams({action:'tng_trip_stop_status', nonce:cfg.nonce || '', postId:String(postId), complete:complete ? '1' : ''});
      const response = await fetch(cfg.ajaxUrl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body});
      const json = await response.json();
      if (!json.success) throw new Error('Unable to update stop');
      button.setAttribute('aria-pressed', json.data.complete ? 'true' : 'false');
      button.textContent = json.data.complete ? 'Undo' : 'Complete stop';
      stop?.classList.toggle('is-complete', json.data.complete);
      if (json.data.complete) stop?.classList.remove('is-arrived');
      const number = stop?.querySelector('.tng-active-trip-stop__number');
      if (number) number.textContent = json.data.complete ? '✓' : String(Array.from(stop.parentElement.children).indexOf(stop) + 1);
      updateSummary(json.data.done, json.data.total);
      syncCurrentStop();
      if (json.data.complete) {
        const next = firstIncomplete();
        if (next) window.setTimeout(() => next.scrollIntoView({behavior:'smooth', block:'center'}), 250);
      }
    } catch (error) {
      window.alert('The stop could not be updated. Please try again.');
    } finally {
      if (!button.getAttribute('aria-pressed') || button.getAttribute('aria-pressed') !== 'true') button.disabled = true;
      else button.disabled = false;
      delete button.dataset.loading;
    }
  });

  initMap();
  syncCurrentStop();
  window.addEventListener('resize', () => tripMap && window.setTimeout(() => tripMap.invalidateSize(), 80));
})();
