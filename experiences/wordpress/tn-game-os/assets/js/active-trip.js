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
  let developerLocation = null;
  let finishEventSent = false;

  const isComplete = stop => stop?.classList.contains('is-complete');
  const isSkipped = stop => stop?.classList.contains('is-skipped');
  const isResolved = stop => isComplete(stop) || isSkipped(stop);

  const counts = () => {
    const all = stops();
    const done = all.filter(isComplete).length;
    const skipped = all.filter(isSkipped).length;
    return {done, skipped, resolved: done + skipped, total: all.length};
  };

  const updateSummary = (done, total, skipped = counts().skipped) => {
    document.querySelectorAll('[data-tng-trip-progress]').forEach(el => { el.textContent = `${done}/${total}`; });
    const resolved = Math.min(total, done + skipped);
    document.querySelectorAll('[data-tng-trip-progress-bar]').forEach(el => { el.style.width = `${total ? Math.round((resolved / total) * 100) : 0}%`; });
    document.querySelectorAll('.tng-active-trip-score small').forEach(el => {
      el.textContent = `Stops complete${skipped ? ` · ${skipped} skipped` : ''}`;
    });
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
    complete: isComplete(stop),
    skipped: isSkipped(stop)
  })).filter(stop => Number.isFinite(stop.lat) && Number.isFinite(stop.lng));

  const firstIncomplete = () => stops().find(stop => !isResolved(stop)) || null;

  const routeIcon = stop => L.divIcon({
    className: 'tng-active-map-marker-wrap',
    html: `<span class="tng-active-map-marker${stop.complete ? ' is-complete' : ''}${stop.skipped ? ' is-skipped' : ''}"><i>${stop.complete ? '✓' : (stop.skipped ? '↷' : stop.order)}</i></span>`,
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
      const state = stop.complete ? 'Completed' : (stop.skipped ? 'Skipped' : 'Planned stop');
      const marker = L.marker([stop.lat, stop.lng], {icon:routeIcon(stop), keyboard:true})
        .bindPopup(`<strong>${stop.order}. ${escapeHtml(stop.title)}</strong><br><small>${state}</small>`)
        .on('click', () => stop.el.scrollIntoView({behavior:'smooth', block:'center'}));
      markerLayer.addLayer(marker);
    });

    if (!mapped.length) return;
    const bounds = L.latLngBounds(mapped.map(stop => [stop.lat, stop.lng]));
    tripMap.fitBounds(bounds, {padding:[42,42], maxZoom:13});

    const routePoints = mapped.filter(stop => !stop.skipped);
    if (mapRequest) mapRequest.abort?.();
    const controller = new AbortController();
    mapRequest = controller;
    if (routePoints.length > 1) {
      try {
        const geometry = await fetchRoadGeometry(routePoints);
        if (controller.signal.aborted || !geometry) return;
        fullRouteLine = L.polyline(geometry, {color:'#355f4a', weight:5, opacity:.42, lineCap:'round', lineJoin:'round'}).addTo(tripMap);
      } catch (error) {
        if (controller.signal.aborted) return;
        fullRouteLine = L.polyline(routePoints.map(stop => [stop.lat, stop.lng]), {color:'#355f4a', weight:4, opacity:.35, dashArray:'8 8'}).addTo(tripMap);
      }
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
      const c = counts();
      if (status) status.textContent = c.skipped ? `Trip finished · ${c.done} completed · ${c.skipped} skipped` : 'Every stop is complete.';
      return;
    }
    const currentIndex = stops().indexOf(currentEl);
    const current = all.find(stop => stop.el === currentEl);
    if (!current) return;
    if (status) status.textContent = `Current leg highlighted to ${current.title}`;

    let previous = null;
    for (let index = currentIndex - 1; index >= 0; index--) {
      const candidate = stops()[index];
      if (isSkipped(candidate)) continue;
      previous = all.find(stop => stop.el === candidate) || null;
      if (previous) break;
    }
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

  const syncFinishCard = (allowEvent = false) => {
    const c = counts();
    const card = document.querySelector('[data-trip-finish-card]');
    const finished = c.total > 0 && c.resolved >= c.total;
    card?.classList.toggle('is-visible', finished);
    if (card && finished) {
      const title = card.querySelector('[data-trip-finish-title]');
      const copy = card.querySelector('[data-trip-finish-copy]');
      if (title) title.textContent = c.skipped ? 'Your day is wrapped up.' : 'You completed every stop!';
      if (copy) copy.textContent = c.skipped ? `${c.done} completed · ${c.skipped} skipped. Your progress is saved, and skipped stops can be revisited later.` : 'Your entire itinerary is complete and ready to be saved to your Explorer story.';
    }
    if (!finished) {
      finishEventSent = false;
      return;
    }
    if (!allowEvent || finishEventSent) return;
    finishEventSent = true;
    window.dispatchEvent(new CustomEvent(c.skipped ? 'tng:trip-finished' : 'tng:trip-completed', {detail:c}));
  };

  const syncCurrentStop = (allowFinishEvent = false) => {
    const current = firstIncomplete();
    stops().forEach(stop => {
      const isCurrent = stop === current;
      stop.classList.toggle('is-current', isCurrent);
      const arrive = stop.querySelector('[data-trip-arrive]');
      const complete = stop.querySelector('[data-trip-complete]');
      if (isSkipped(stop)) {
        if (arrive) arrive.disabled = true;
        if (complete) complete.disabled = true;
        return;
      }
      if (!isComplete(stop)) {
        if (arrive && !stop.classList.contains('is-arrived')) arrive.disabled = !isCurrent || !stop.dataset.lat || !stop.dataset.lng;
        if (complete && !stop.classList.contains('is-arrived')) complete.disabled = true;
      }
    });

    const c = counts();
    updateSummary(c.done, c.total, c.skipped);
    syncFinishCard(allowFinishEvent);

    const heading = document.querySelector('[data-tng-trip-next-heading]');
    const nextTitle = document.querySelector('[data-tng-next-title]');
    const nextLeg = document.querySelector('[data-tng-next-leg]');
    const nextDirections = document.querySelector('[data-tng-next-directions]');
    const nextView = document.querySelector('[data-tng-next-view]');
    if (!current) {
      if (heading) heading.textContent = c.skipped ? 'Trip finished — review your day below.' : 'You finished every stop.';
      if (nextTitle) nextTitle.textContent = c.skipped ? 'Trip finished.' : 'Adventure complete.';
      if (nextLeg) nextLeg.textContent = c.skipped ? `${c.done} completed · ${c.skipped} skipped` : 'You visited every stop in this trip.';
      if (nextDirections) nextDirections.style.display = 'none';
      if (nextView) nextView.style.display = 'none';
      drawTripMap();
      syncDeveloperPanel();
      return;
    }
    const title = current.querySelector('h3')?.textContent?.trim() || 'Next stop';
    if (heading) heading.textContent = `Next: ${title}`;
    if (nextTitle) nextTitle.textContent = title;
    if (nextDirections) {
      nextDirections.style.display = '';
      if (current.dataset.directions) nextDirections.href = current.dataset.directions;
    }
    const viewLink = current.querySelector('.tng-active-trip-stop__copy a');
    if (nextView) {
      nextView.style.display = '';
      if (viewLink?.href) nextView.href = viewLink.href;
    }
    const leg = current.querySelector('.tng-active-trip-leg');
    if (nextLeg && leg) nextLeg.textContent = leg.textContent.trim();
    drawTripMap();
    syncDeveloperPanel();
  };

  const markArrived = (stop, arrive) => {
    stop.classList.add('is-arrived');
    arrive.textContent = 'Arrived ✓';
    arrive.disabled = true;
    const complete = stop.querySelector('[data-trip-complete]');
    if (complete) {
      complete.disabled = false;
      complete.focus({preventScroll:true});
    }
    syncDeveloperPanel();
  };

  const validateArrivalPosition = (stop, arrive, latitude, longitude, accuracy = 0, simulated = false) => {
    const lat = Number(stop.dataset.lat), lng = Number(stop.dataset.lng);
    const distance = distanceMeters(Number(latitude), Number(longitude), lat, lng);
    const radius = Number(cfg.arrivalRadius || 300);
    const allowed = radius + Math.min(Math.max(Number(accuracy || 0), 0), 150);
    if (distance <= allowed) {
      markArrived(stop, arrive);
      if (simulated) arrive.title = 'Developer simulated arrival';
      return true;
    }
    arrive.textContent = 'I’m here';
    arrive.disabled = false;
    window.alert(`You are about ${Math.round(distance)} m from this stop. Get within ${radius} m to confirm arrival.${simulated ? ' (Developer simulation)' : ''}`);
    return false;
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
    if (!stop || isSkipped(stop)) return;
    const lat = Number(stop.dataset.lat), lng = Number(stop.dataset.lng);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
      window.alert('This stop does not have a usable GPS location yet.');
      return;
    }

    arrive.dataset.loading = '1';
    arrive.disabled = true;
    arrive.textContent = developerLocation ? 'Checking simulated location…' : 'Checking location…';

    if (developerLocation) {
      validateArrivalPosition(stop, arrive, developerLocation.lat, developerLocation.lng, 0, true);
      delete arrive.dataset.loading;
      return;
    }

    if (!navigator.geolocation) {
      arrive.textContent = 'I’m here';
      arrive.disabled = false;
      delete arrive.dataset.loading;
      window.alert('Location is not available in this browser.');
      return;
    }
    navigator.geolocation.getCurrentPosition(position => {
      validateArrivalPosition(stop, arrive, position.coords.latitude, position.coords.longitude, Number(position.coords.accuracy || 0), false);
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
      if (json.data.complete) {
        stop?.classList.remove('is-arrived', 'is-skipped');
        delete stop?.dataset.skipReason;
      }
      const number = stop?.querySelector('.tng-active-trip-stop__number');
      if (number) number.textContent = json.data.complete ? '✓' : String(stops().indexOf(stop) + 1);
      developerLocation = null;
      syncCurrentStop(true);
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

  const reasonLabels = {closed:'Closed', inaccessible:'Inaccessible', weather:'Weather', changed_plans:'Changed plans', other:'Other'};

  const chooseSkipReason = title => new Promise(resolve => {
    const dialog = document.createElement('div');
    dialog.className = 'tng-trip-skip-dialog';
    dialog.innerHTML = `<div class="tng-trip-skip-dialog__card" role="dialog" aria-modal="true" aria-label="Skip ${escapeHtml(title)}"><h3>Skip this stop?</h3><p>Tell TN Game why ${escapeHtml(title)} can’t be visited today. You can restore it later.</p><div class="tng-trip-skip-reasons">${Object.entries(reasonLabels).map(([key,label]) => `<button type="button" data-skip-reason="${key}">${label}</button>`).join('')}</div><button type="button" class="tng-trip-skip-dialog__cancel">Keep stop</button></div>`;
    document.body.appendChild(dialog);
    const close = value => { dialog.remove(); resolve(value); };
    dialog.addEventListener('click', event => {
      const reason = event.target.closest('[data-skip-reason]');
      if (reason) return close(reason.dataset.skipReason || 'other');
      if (event.target === dialog || event.target.closest('.tng-trip-skip-dialog__cancel')) close('');
    });
    const onKey = event => { if (event.key === 'Escape') { document.removeEventListener('keydown', onKey); close(''); } };
    document.addEventListener('keydown', onKey, {once:true});
  });

  const applySkippedState = (stop, skipped, reason = '') => {
    if (!stop) return;
    stop.classList.toggle('is-skipped', skipped);
    stop.classList.remove('is-arrived');
    if (skipped) stop.dataset.skipReason = reason || 'other';
    else delete stop.dataset.skipReason;
    const number = stop.querySelector('.tng-active-trip-stop__number');
    if (number) number.textContent = skipped ? '↷' : String(stops().indexOf(stop) + 1);
    let label = stop.querySelector('.tng-active-trip-skip-label');
    if (skipped) {
      if (!label) {
        label = document.createElement('span');
        label.className = 'tng-active-trip-skip-label';
        const copy = stop.querySelector('.tng-active-trip-stop__copy');
        const details = copy?.querySelector('a');
        if (copy) copy.insertBefore(label, details || null);
      }
      label.textContent = `Skipped · ${reasonLabels[reason] || 'Other'}`;
    } else label?.remove();
    const button = stop.querySelector('[data-trip-skip]');
    if (button) {
      button.setAttribute('aria-pressed', skipped ? 'true' : 'false');
      button.textContent = skipped ? 'Restore stop' : 'Can’t visit?';
    }
  };

  document.addEventListener('click', async event => {
    const button = event.target.closest('[data-trip-skip]');
    if (!button) return;
    event.preventDefault();
    if (button.dataset.loading === '1') return;
    const stop = button.closest('[data-trip-stop]');
    if (!stop || isComplete(stop)) return;
    const restoring = isSkipped(stop);
    const title = stop.querySelector('h3')?.textContent?.trim() || 'this stop';
    const reason = restoring ? (stop.dataset.skipReason || 'other') : await chooseSkipReason(title);
    if (!restoring && !reason) return;
    button.dataset.loading = '1';
    button.disabled = true;
    try {
      const body = new URLSearchParams({action:'tng_trip_skip_status', nonce:cfg.nonce || '', postId:String(Number(stop.dataset.tripStop || 0)), skip:restoring ? '' : '1', reason});
      const response = await fetch(cfg.ajaxUrl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body});
      const json = await response.json();
      if (!json.success) throw new Error('Unable to update skipped stop');
      applySkippedState(stop, !!json.data.skip, json.data.reason || reason);
      developerLocation = null;
      syncCurrentStop(true);
      const next = firstIncomplete();
      if (!restoring && next) window.setTimeout(() => next.scrollIntoView({behavior:'smooth', block:'center'}), 250);
    } catch (error) {
      window.alert('The stop could not be skipped. Please try again.');
    } finally {
      button.disabled = false;
      delete button.dataset.loading;
    }
  });

  let devPanel = null;
  const developerAdminVisible = () => {
    if (!document.body.classList.contains('admin-bar')) return false;
    const bar = document.getElementById('wpadminbar');
    return !!bar && /TN Developer/i.test(bar.textContent || '');
  };

  const selectedDeveloperStop = () => {
    const select = devPanel?.querySelector('[data-trip-dev-select]');
    return select ? stops()[Number(select.value || 0)] || null : null;
  };

  const syncDeveloperPanel = () => {
    if (!devPanel) return;
    const select = devPanel.querySelector('[data-trip-dev-select]');
    const current = firstIncomplete();
    const currentIndex = current ? stops().indexOf(current) : -1;
    if (select) {
      const previousValue = select.value;
      select.innerHTML = '';
      stops().forEach((stop, index) => {
        const option = document.createElement('option');
        option.value = String(index);
        const title = stop.querySelector('h3')?.textContent?.trim() || `Stop ${index + 1}`;
        const state = isComplete(stop) ? 'Completed' : (isSkipped(stop) ? 'Skipped' : (stop === current ? 'Active stop' : 'Future stop'));
        option.textContent = `${index + 1}. ${title} — ${state}`;
        select.appendChild(option);
      });
      select.value = previousValue && Number(previousValue) < stops().length ? previousValue : String(Math.max(0, currentIndex));
    }
    const status = devPanel.querySelector('[data-trip-dev-status]');
    if (status) {
      if (!current) {
        const c = counts();
        status.innerHTML = c.skipped ? `<strong>Trip finished.</strong> ${c.done} completed · ${c.skipped} skipped.` : '<strong>Trip complete.</strong> Undo a completed stop to test the route again.';
      } else {
        const title = current.querySelector('h3')?.textContent?.trim() || 'Current stop';
        const sim = developerLocation ? `<br>Simulated GPS: ${developerLocation.mode === 'outside' ? 'outside radius' : 'at stop'}` : '';
        status.innerHTML = `<strong>Active:</strong> ${escapeHtml(title)}${sim}`;
      }
    }
    const quick = devPanel.querySelector('[data-trip-dev-current]');
    if (quick) quick.disabled = !current || !current.dataset.lat || !current.dataset.lng;
    const complete = devPanel.querySelector('[data-trip-dev-complete]');
    if (complete) {
      const action = current?.querySelector('[data-trip-complete]');
      complete.disabled = !action || action.disabled;
      complete.textContent = current?.classList.contains('is-arrived') ? 'Complete current stop' : 'Complete current stop (arrive first)';
    }
  };

  const renderDeveloperPanel = () => {
    if (!developerAdminVisible() || devPanel) return;
    const shell = document.createElement('div');
    shell.className = 'tng-trip-dev-shell is-collapsed';
    shell.innerHTML = `
      <button class="tng-trip-dev-pill" type="button" data-trip-dev-toggle><span>🧪</span><span><strong>Trip Developer</strong><small>GPS + progression testing</small></span><b>⌃</b></button>
      <aside class="tng-trip-dev-panel" aria-label="Trip Mode developer tools">
        <div class="tng-trip-dev-head"><div><small>Developer mode</small><h3>Trip simulator</h3></div><button type="button" data-trip-dev-close>×</button></div>
        <label class="tng-trip-dev-label">Test stop<select data-trip-dev-select></select></label>
        <div class="tng-trip-dev-grid"><button type="button" data-trip-dev-at>📍 Simulate at stop</button><button type="button" data-trip-dev-outside>🧪 Outside radius</button></div>
        <div class="tng-trip-dev-grid"><button type="button" data-trip-dev-focus>View selected stop</button><button type="button" data-trip-dev-clear>Use real GPS</button></div>
        <button class="tng-trip-dev-primary" type="button" data-trip-dev-current>Simulate current + run “I’m here”</button>
        <button class="tng-trip-dev-complete" type="button" data-trip-dev-complete>Complete current stop</button>
        <div class="tng-trip-dev-status" data-trip-dev-status></div>
        <small class="tng-trip-dev-note">Admin testing only. Arrival still runs through the normal radius validation, and completion uses the normal Trip Mode save endpoint.</small>
      </aside>`;
    document.body.appendChild(shell);
    devPanel = shell;
    const setCollapsed = collapsed => {
      shell.classList.toggle('is-collapsed', collapsed);
      try { localStorage.setItem('tng_trip_dev_collapsed', collapsed ? '1' : '0'); } catch (e) {}
    };
    let collapsed = true;
    try { collapsed = localStorage.getItem('tng_trip_dev_collapsed') !== '0'; } catch (e) {}
    setCollapsed(collapsed);
    shell.querySelector('[data-trip-dev-toggle]')?.addEventListener('click', () => setCollapsed(!shell.classList.contains('is-collapsed')));
    shell.querySelector('[data-trip-dev-close]')?.addEventListener('click', () => setCollapsed(true));
    shell.querySelector('[data-trip-dev-select]')?.addEventListener('change', syncDeveloperPanel);
    shell.querySelector('[data-trip-dev-focus]')?.addEventListener('click', () => {
      const stop = selectedDeveloperStop();
      if (!stop) return;
      stop.scrollIntoView({behavior:'smooth', block:'center'});
      if (tripMap && stop.dataset.lat && stop.dataset.lng) tripMap.setView([Number(stop.dataset.lat), Number(stop.dataset.lng)], 14, {animate:true});
    });
    shell.querySelector('[data-trip-dev-at]')?.addEventListener('click', () => {
      const stop = selectedDeveloperStop();
      if (!stop || !stop.dataset.lat || !stop.dataset.lng) return window.alert('This stop does not have usable coordinates.');
      developerLocation = {lat:Number(stop.dataset.lat), lng:Number(stop.dataset.lng), mode:'inside', id:Number(stop.dataset.tripStop || 0)};
      if (tripMap) tripMap.setView([developerLocation.lat, developerLocation.lng], 15, {animate:true});
      syncDeveloperPanel();
    });
    shell.querySelector('[data-trip-dev-outside]')?.addEventListener('click', () => {
      const stop = selectedDeveloperStop();
      if (!stop || !stop.dataset.lat || !stop.dataset.lng) return window.alert('This stop does not have usable coordinates.');
      const offsetMeters = Number(cfg.arrivalRadius || 300) + 125;
      developerLocation = {lat:Number(stop.dataset.lat) + offsetMeters / 111320, lng:Number(stop.dataset.lng), mode:'outside', id:Number(stop.dataset.tripStop || 0)};
      if (tripMap) tripMap.setView([developerLocation.lat, developerLocation.lng], 15, {animate:true});
      syncDeveloperPanel();
    });
    shell.querySelector('[data-trip-dev-clear]')?.addEventListener('click', () => { developerLocation = null; syncDeveloperPanel(); });
    shell.querySelector('[data-trip-dev-current]')?.addEventListener('click', () => {
      const current = firstIncomplete();
      if (!current || !current.dataset.lat || !current.dataset.lng) return;
      developerLocation = {lat:Number(current.dataset.lat), lng:Number(current.dataset.lng), mode:'inside', id:Number(current.dataset.tripStop || 0)};
      const arrive = current.querySelector('[data-trip-arrive]');
      if (arrive && !arrive.disabled) arrive.click();
      else if (current.classList.contains('is-arrived')) syncDeveloperPanel();
    });
    shell.querySelector('[data-trip-dev-complete]')?.addEventListener('click', () => {
      const current = firstIncomplete();
      const button = current?.querySelector('[data-trip-complete]');
      if (button && !button.disabled) button.click();
    });
    syncDeveloperPanel();
  };

  initMap();
  syncCurrentStop(false);
  renderDeveloperPanel();
  window.addEventListener('resize', () => tripMap && window.setTimeout(() => tripMap.invalidateSize(), 80));
})();