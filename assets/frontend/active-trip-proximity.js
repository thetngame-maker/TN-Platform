(() => {
  const cfg = window.TNGTripProximity || {};
  const radius = Number(cfg.arrivalRadius || 300);
  let watchId = null;
  let simulated = null;
  let lastPosition = null;
  let nearHits = 0;
  let nearStopId = 0;
  let autoArrivalTimer = null;

  const stops = () => [...document.querySelectorAll('[data-trip-stop]')];
  const eligible = stop => stop && !stop.classList.contains('is-complete') && !stop.classList.contains('is-skipped');
  const currentStop = () => stops().find(stop => stop.classList.contains('is-current') && eligible(stop)) || stops().find(eligible) || null;

  const distanceMeters = (lat1, lng1, lat2, lng2) => {
    const toRad = value => value * Math.PI / 180;
    const earth = 6371000;
    const dLat = toRad(lat2 - lat1), dLng = toRad(lng2 - lng1);
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;
    return 2 * earth * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  };

  const formatDistance = meters => {
    if (!Number.isFinite(meters)) return '';
    if (meters < 1000) return `${Math.max(1, Math.round(meters))} m away`;
    const miles = meters / 1609.344;
    return miles < 10 ? `${miles.toFixed(1)} mi away` : `${Math.round(miles)} mi away`;
  };

  const ensureUi = () => {
    const aside = document.querySelector('.tng-active-trip-next');
    if (aside && !aside.querySelector('[data-trip-proximity-card]')) {
      const card = document.createElement('div');
      card.className = 'tng-trip-proximity-card';
      card.setAttribute('data-trip-proximity-card', '');
      card.innerHTML = `
        <div class="tng-trip-proximity-card__top"><span>Live proximity</span><b data-trip-proximity-dot></b></div>
        <strong data-trip-proximity-distance>Location not active</strong>
        <small data-trip-proximity-status>Turn on live proximity to see how close you are to the next stop.</small>
        <button type="button" data-trip-proximity-start>Use live location</button>`;
      const firstButton = aside.querySelector('.tng-ui-button');
      if (firstButton) aside.insertBefore(card, firstButton);
      else aside.appendChild(card);
    }

    stops().forEach(stop => {
      if (stop.querySelector('[data-trip-proximity-inline]')) return;
      const actions = stop.querySelector('.tng-active-trip-stop__actions');
      if (!actions) return;
      const status = document.createElement('span');
      status.className = 'tng-trip-proximity-inline';
      status.setAttribute('data-trip-proximity-inline', '');
      status.hidden = true;
      actions.prepend(status);
    });
  };

  const clearAutoArrival = () => {
    nearHits = 0;
    nearStopId = 0;
    if (autoArrivalTimer) window.clearTimeout(autoArrivalTimer);
    autoArrivalTimer = null;
  };

  const resetButtons = () => {
    clearAutoArrival();
    stops().forEach(stop => {
      const arrive = stop.querySelector('[data-trip-arrive]');
      const inline = stop.querySelector('[data-trip-proximity-inline]');
      stop.classList.remove('is-nearby', 'is-approaching');
      if (inline) inline.hidden = true;
      if (arrive && eligible(stop) && !stop.classList.contains('is-arrived')) {
        if (stop === currentStop()) {
          arrive.disabled = false;
          arrive.textContent = 'I’m here';
        }
      }
    });
  };

  const scheduleAutoArrival = (stop, arrive, source) => {
    if (!eligible(stop) || !arrive || stop.classList.contains('is-arrived')) return;
    const stopId = Number(stop.dataset.tripStop || 0);
    if (nearStopId !== stopId) {
      nearStopId = stopId;
      nearHits = 0;
    }
    nearHits += source === 'simulated' ? 2 : 1;
    if (nearHits < 2 || autoArrivalTimer) return;

    arrive.textContent = 'Arrival detected…';
    autoArrivalTimer = window.setTimeout(() => {
      autoArrivalTimer = null;
      const active = currentStop();
      if (active !== stop || !eligible(stop) || stop.classList.contains('is-arrived')) return;
      if (arrive.disabled || arrive.dataset.loading === '1') return;
      arrive.click();
      window.setTimeout(() => {
        if (simulated) update(simulated.lat, simulated.lng, simulated.accuracy || 0, 'simulated');
        else if (lastPosition) update(lastPosition.lat, lastPosition.lng, lastPosition.accuracy || 0, 'gps');
      }, 350);
    }, source === 'simulated' ? 80 : 650);
  };

  const update = (lat, lng, accuracy = 0, source = 'gps') => {
    ensureUi();
    const stop = currentStop();
    const card = document.querySelector('[data-trip-proximity-card]');
    const distanceEl = card?.querySelector('[data-trip-proximity-distance]');
    const statusEl = card?.querySelector('[data-trip-proximity-status]');
    const dot = card?.querySelector('[data-trip-proximity-dot]');
    const start = card?.querySelector('[data-trip-proximity-start]');

    if (!stop || !stop.dataset.lat || !stop.dataset.lng) {
      clearAutoArrival();
      const skipped = stops().filter(node => node.classList.contains('is-skipped')).length;
      if (distanceEl) distanceEl.textContent = skipped ? 'Trip finished' : 'Trip complete';
      if (statusEl) statusEl.textContent = skipped ? `No active stops remain. ${skipped} stop${skipped === 1 ? ' was' : 's were'} skipped.` : 'Every stop on this itinerary is complete.';
      if (dot) dot.dataset.state = 'arrived';
      if (start) start.textContent = source === 'simulated' ? 'Developer GPS active' : 'Live location active';
      return;
    }

    const targetLat = Number(stop.dataset.lat);
    const targetLng = Number(stop.dataset.lng);
    const distance = distanceMeters(Number(lat), Number(lng), targetLat, targetLng);
    const allowed = radius + Math.min(Math.max(Number(accuracy || 0), 0), 150);
    const arrive = stop.querySelector('[data-trip-arrive]');
    const inline = stop.querySelector('[data-trip-proximity-inline]');

    stops().forEach(node => {
      if (node !== stop) {
        node.classList.remove('is-nearby', 'is-approaching');
        const other = node.querySelector('[data-trip-proximity-inline]');
        if (other) other.hidden = true;
      }
    });

    let state = 'far';
    let status = `Keep heading toward ${stop.querySelector('h3')?.textContent?.trim() || 'the next stop'}.`;

    if (stop.classList.contains('is-arrived')) {
      clearAutoArrival();
      state = 'arrived';
      status = 'You’ve arrived. Complete this stop when you’re ready to continue.';
      stop.classList.add('is-nearby');
      stop.classList.remove('is-approaching');
    } else if (distance <= allowed) {
      state = 'near';
      status = 'Arrival detected. Trip Mode is confirming this stop automatically.';
      stop.classList.add('is-nearby');
      stop.classList.remove('is-approaching');
      if (arrive) {
        arrive.disabled = false;
        arrive.textContent = 'Confirm arrival';
        scheduleAutoArrival(stop, arrive, source);
      }
    } else if (distance <= 1609.344) {
      clearAutoArrival();
      state = 'approaching';
      status = 'You’re getting close. Arrival will unlock automatically inside the check-in radius.';
      stop.classList.add('is-approaching');
      stop.classList.remove('is-nearby');
      if (arrive) {
        arrive.disabled = true;
        arrive.textContent = 'Get closer to arrive';
      }
    } else {
      clearAutoArrival();
      stop.classList.remove('is-nearby', 'is-approaching');
      if (arrive) {
        arrive.disabled = true;
        arrive.textContent = 'Get closer to arrive';
      }
    }

    if (distanceEl) distanceEl.textContent = state === 'arrived' ? 'Arrived' : formatDistance(distance);
    if (statusEl) statusEl.textContent = status + (source === 'simulated' ? ' Developer GPS is active.' : '');
    if (dot) dot.dataset.state = state;
    if (start) start.textContent = source === 'simulated' ? 'Developer GPS active' : 'Live location active';
    if (inline) {
      inline.hidden = false;
      inline.textContent = state === 'arrived'
        ? 'Arrived · ready to complete'
        : `${formatDistance(distance)} · ${state === 'near' ? 'arrival detected' : state === 'approaching' ? 'approaching' : 'on the way'}`;
    }

    document.dispatchEvent(new CustomEvent('tng:trip-proximity-update', {detail:{distance, allowed, state, source, stopId:Number(stop.dataset.tripStop || 0)}}));
  };

  const startWatch = () => {
    simulated = null;
    clearAutoArrival();
    if (!navigator.geolocation) {
      window.alert('Live location is not available in this browser.');
      return;
    }
    if (watchId !== null) navigator.geolocation.clearWatch(watchId);
    watchId = navigator.geolocation.watchPosition(position => {
      lastPosition = {lat:position.coords.latitude, lng:position.coords.longitude, accuracy:Number(position.coords.accuracy || 0)};
      update(lastPosition.lat, lastPosition.lng, lastPosition.accuracy, 'gps');
    }, error => {
      const card = document.querySelector('[data-trip-proximity-card]');
      const status = card?.querySelector('[data-trip-proximity-status]');
      if (status) status.textContent = error?.code === 1 ? 'Location permission was denied. You can still use Directions.' : 'Live location could not be updated.';
      const start = card?.querySelector('[data-trip-proximity-start]');
      if (start) start.textContent = 'Try live location again';
    }, Object.assign({enableHighAccuracy:true, timeout:15000, maximumAge:10000}, cfg.watchOptions || {}));
  };

  const useSimulatedStop = (stop, outside = false) => {
    if (!eligible(stop) || !stop?.dataset.lat || !stop?.dataset.lng) return;
    clearAutoArrival();
    const offset = outside ? (radius + 125) / 111320 : 0;
    simulated = {lat:Number(stop.dataset.lat) + offset, lng:Number(stop.dataset.lng), accuracy:0};
    update(simulated.lat, simulated.lng, 0, 'simulated');
  };

  document.addEventListener('click', event => {
    if (event.target.closest('[data-trip-proximity-start]')) {
      event.preventDefault();
      startWatch();
      return;
    }

    if (event.target.closest('[data-trip-dev-current]')) {
      window.setTimeout(() => useSimulatedStop(currentStop(), false), 40);
      return;
    }
    if (event.target.closest('[data-trip-dev-at]')) {
      window.setTimeout(() => {
        const panel = document.querySelector('.tng-trip-dev-shell');
        const selected = Number(panel?.querySelector('[data-trip-dev-select]')?.value || 0);
        useSimulatedStop(stops()[selected], false);
      }, 40);
      return;
    }
    if (event.target.closest('[data-trip-dev-outside]')) {
      window.setTimeout(() => {
        const panel = document.querySelector('.tng-trip-dev-shell');
        const selected = Number(panel?.querySelector('[data-trip-dev-select]')?.value || 0);
        useSimulatedStop(stops()[selected], true);
      }, 40);
      return;
    }
    if (event.target.closest('[data-trip-dev-clear]')) {
      simulated = null;
      clearAutoArrival();
      resetButtons();
      if (lastPosition) update(lastPosition.lat, lastPosition.lng, lastPosition.accuracy, 'gps');
    }
  }, true);

  document.addEventListener('click', event => {
    if (!event.target.closest('[data-trip-complete],[data-trip-skip]')) return;
    const oldSimulated = simulated ? {...simulated} : null;
    window.setTimeout(() => {
      resetButtons();
      const next = currentStop();
      if (!next) {
        update(0, 0, 0, oldSimulated ? 'simulated' : 'gps');
        return;
      }
      if (oldSimulated) {
        simulated = oldSimulated;
        update(simulated.lat, simulated.lng, simulated.accuracy || 0, 'simulated');
      } else if (lastPosition) {
        update(lastPosition.lat, lastPosition.lng, lastPosition.accuracy, 'gps');
      }
    }, 700);
  });

  const permissionBootstrap = async () => {
    ensureUi();
    if (!navigator.permissions || !navigator.permissions.query) return;
    try {
      const permission = await navigator.permissions.query({name:'geolocation'});
      if (permission.state === 'granted') startWatch();
    } catch (e) {}
  };

  new MutationObserver(() => ensureUi()).observe(document.documentElement, {childList:true, subtree:true});
  ensureUi();
  permissionBootstrap();
  window.addEventListener('beforeunload', () => {
    clearAutoArrival();
    if (watchId !== null && navigator.geolocation) navigator.geolocation.clearWatch(watchId);
  });
})();