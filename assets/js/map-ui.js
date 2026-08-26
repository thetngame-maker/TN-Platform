(() => {
  const cfg = window.TNG_DISCOVERY_MAP || {};
  const el = document.getElementById('tng-discovery-map');
  if (!el || !window.L || !Array.isArray(cfg.items)) return;

  const mobileQuery = window.matchMedia('(max-width: 700px)');
  const isMobile = () => mobileQuery.matches;
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
  const categories = cfg.categories || {};
  const resultsEl = document.querySelector('[data-tng-map-results]');
  const panelIntro = document.querySelector('[data-tng-panel-intro]');
  const nearestEl = document.querySelector('[data-tng-nearest]');
  const emptyEl = document.querySelector('[data-tng-map-empty]');
  const searchInput = document.querySelector('[data-tng-map-search]');
  const searchClear = document.querySelector('[data-tng-map-search-clear]');
  const sheet = document.querySelector('[data-tng-map-sheet]');
  const sheetContent = document.querySelector('[data-tng-map-sheet-content]');
  const sheetBackdrop = document.querySelector('.tng-map-sheet-backdrop');
  const sheetHandle = document.querySelector('[data-tng-map-sheet-handle]');
  let activeFilter = 'all';
  let searchQuery = '';
  let userMarker = null;
  let userLatLng = null;
  let initialFitDone = false;
  let activeSheetId = '';
  let touchStartY = 0;
  let touchDeltaY = 0;

  const icons = {
    trail: '🥾', game: '🎮', sight: '📍', food: '🍽️', event: '🎵', lodging: '🛏️',
    tour: '🚌', rental: '🏡', transport: '🚗', destination: '🗺️', venue: '🎤', place: '•'
  };
  const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
  const markerIcon = item => L.divIcon({
    className: 'tng-discovery-marker-wrap',
    html: `<button type="button" class="tng-discovery-marker tng-discovery-marker--${esc(item.kind)}" aria-label="${esc(item.title)}"><span>${icons[item.kind] || '•'}</span></button>`,
    iconSize: [44, 44], iconAnchor: [22, 40], popupAnchor: [0, -36]
  });

  const hideLegacyXpOverlay = () => {
    const protectedNode = node => node.closest('.tng-app-nav,.tng-trip-dock,.tng-map-sheet,.tng-map-screen');
    document.querySelectorAll('body *').forEach(node => {
      if (!(node instanceof HTMLElement) || protectedNode(node)) return;
      const text = (node.textContent || '').replace(/\s+/g, ' ').trim();
      if (!/\b[\d,]+\s*XP\b/i.test(text) || text.length > 80) return;
      const style = window.getComputedStyle(node);
      if (!['fixed', 'sticky'].includes(style.position)) return;
      const rect = node.getBoundingClientRect();
      if (rect.width < 180 || rect.height < 20 || rect.height > 130 || rect.bottom < window.innerHeight * 0.65) return;
      node.classList.add('tng-map-legacy-xp-hidden');
    });
  };

  const distanceMiles = (lat1, lng1, lat2, lng2) => {
    const toRad = deg => deg * Math.PI / 180;
    const r = 3958.8;
    const dLat = toRad(lat2 - lat1);
    const dLng = toRad(lng2 - lng1);
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;
    return 2 * r * Math.asin(Math.sqrt(a));
  };
  const formatDistance = miles => miles < 0.1 ? 'Nearby' : miles < 10 ? `${miles.toFixed(1)} mi` : `${Math.round(miles)} mi`;
  const directionsUrl = item => {
    const destination = `${Number(item.lat)},${Number(item.lng)}`;
    const apple = /iPad|iPhone|iPod|Macintosh/.test(navigator.userAgent || '');
    return apple
      ? `https://maps.apple.com/?daddr=${encodeURIComponent(destination)}&dirflg=d`
      : `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(destination)}`;
  };

  const itemDistance = item => userLatLng
    ? formatDistance(distanceMiles(userLatLng.lat, userLatLng.lng, Number(item.lat), Number(item.lng)))
    : '';

  const popup = item => {
    const image = item.image ? `<span class="tng-map-popup__media" style="background-image:url('${esc(item.image)}')"></span>` : '';
    const distance = itemDistance(item) ? `<b>${esc(itemDistance(item))}</b>` : '';
    const actionLabel = item.actionLabel || (item.kind === 'game' ? 'Play game' : 'View');
    const actionUrl = item.actionUrl || item.url;
    const xp = Number(item.xp) > 0 ? `<span class="tng-map-popup__xp">+${esc(item.xp)} XP</span>` : '';
    return `<article class="tng-map-popup">${image}<div><small>${esc(item.label)}</small><strong>${esc(item.title)}</strong>${distance}${xp}${item.subtitle ? `<p>${esc(item.subtitle)}</p>` : ''}<span class="tng-map-popup__actions"><a class="is-primary" href="${esc(actionUrl)}">${esc(actionLabel)}</a><button type="button" data-tng-directions data-lat="${esc(item.lat)}" data-lng="${esc(item.lng)}">Directions</button><button type="button" data-tng-trip-toggle data-post-id="${esc(item.id)}">＋ Add to trip</button></span></div></article>`;
  };

  const sheetMarkup = item => {
    const image = item.image ? `<div class="tng-map-sheet__media" style="background-image:url('${esc(item.image)}')"></div>` : `<div class="tng-map-sheet__media is-placeholder"><span>${icons[item.kind] || '•'}</span></div>`;
    const distance = itemDistance(item);
    const actionLabel = item.actionLabel || (item.kind === 'game' ? 'Play game' : item.kind === 'trail' ? 'View trail' : 'View');
    const actionUrl = item.actionUrl || item.url;
    const kindChip = `<span class="tng-map-sheet__chip">${icons[item.kind] || '•'} ${esc(item.label)}</span>`;
    const xpChip = Number(item.xp) > 0 ? `<span class="tng-map-sheet__chip is-xp">+${esc(item.xp)} XP</span>` : '';
    return `<div class="tng-map-sheet__overview">${image}<div class="tng-map-sheet__body"><div class="tng-map-sheet__eyebrow"><span>${esc(item.label)}</span>${distance ? `<b>${esc(distance)}</b>` : ''}</div><h2>${esc(item.title)}</h2>${item.subtitle ? `<p>${esc(item.subtitle)}</p>` : ''}<div class="tng-map-sheet__chips">${kindChip}${xpChip}${distance ? `<span class="tng-map-sheet__chip is-distance">⌖ ${esc(distance)} away</span>` : ''}</div></div></div><div class="tng-map-sheet__actions"><a class="is-primary" href="${esc(actionUrl)}">${esc(actionLabel)}</a><button type="button" data-tng-directions data-lat="${esc(item.lat)}" data-lng="${esc(item.lng)}">↗ Directions</button><button type="button" data-tng-trip-toggle data-post-id="${esc(item.id)}">＋ Add to trip</button></div>`;
  };

  const openMobileSheet = item => {
    if (!isMobile() || !sheet || !sheetContent) return;
    activeSheetId = String(item.id);
    sheetContent.innerHTML = sheetMarkup(item);
    sheet.classList.add('is-open');
    sheet.setAttribute('aria-hidden', 'false');
    sheet.style.transform = '';
    if (sheetBackdrop) {
      sheetBackdrop.hidden = false;
      requestAnimationFrame(() => sheetBackdrop.classList.add('is-open'));
    }
    document.body.classList.add('tng-map-sheet-open');
    hideLegacyXpOverlay();
  };

  const closeMobileSheet = () => {
    if (!sheet) return;
    activeSheetId = '';
    sheet.classList.remove('is-open');
    sheet.setAttribute('aria-hidden', 'true');
    sheet.style.transform = '';
    if (sheetBackdrop) {
      sheetBackdrop.classList.remove('is-open');
      window.setTimeout(() => { if (!sheetBackdrop.classList.contains('is-open')) sheetBackdrop.hidden = true; }, 220);
    }
    document.body.classList.remove('tng-map-sheet-open');
  };

  const showItem = item => {
    if (activeFilter !== 'all' && item.kind !== activeFilter) return false;
    if (!searchQuery) return true;
    const haystack = String(item.search || `${item.title || ''} ${item.label || ''}`).toLowerCase();
    return searchQuery.split(/\s+/).filter(Boolean).every(term => haystack.includes(term));
  };

  const activateResult = id => {
    document.querySelectorAll('.tng-map-result.is-active').forEach(n => n.classList.remove('is-active'));
    const row = resultNodes.get(String(id));
    if (row) {
      row.hidden = false;
      row.classList.add('is-active');
      if (!isMobile()) row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  };

  const focusItem = id => {
    const item = itemById.get(String(id));
    if (!item) return;
    let rerender = false;
    if (searchQuery && !String(item.search || '').toLowerCase().includes(searchQuery)) {
      searchQuery = '';
      if (searchInput) searchInput.value = '';
      if (searchClear) searchClear.hidden = true;
      rerender = true;
    }
    if (activeFilter !== 'all' && activeFilter !== item.kind) {
      activeFilter = item.kind;
      document.querySelectorAll('[data-tng-map-filter]').forEach(b => b.classList.toggle('is-active', b.getAttribute('data-tng-map-filter') === activeFilter));
      rerender = true;
    }
    if (rerender) renderMarkers();
    const marker = markers.get(String(id));
    if (!marker) return;
    const openMarker = () => {
      map.setView(marker.getLatLng(), Math.max(map.getZoom(), isMobile() ? 15 : 14), { animate: true });
      activateResult(id);
      if (isMobile()) {
        map.closePopup();
        openMobileSheet(item);
      } else {
        marker.openPopup();
      }
    };
    if (typeof markerLayer.zoomToShowLayer === 'function') markerLayer.zoomToShowLayer(marker, openMarker);
    else openMarker();
  };

  const renderNearest = () => {
    if (!nearestEl || !userLatLng) return;
    const priority = ['trail', 'game', 'sight', 'food', 'event', 'lodging', 'tour', 'destination'];
    const kinds = priority.filter(kind => cfg.items.some(item => item.kind === kind)).slice(0, 3).map(kind => [
      kind,
      categories[kind]?.icon || icons[kind] || '•',
      `Nearest ${String(categories[kind]?.singular || categories[kind]?.label || kind).toLowerCase()}`
    ]);
    const cards = kinds.map(([kind, icon, label]) => {
      const choices = cfg.items.filter(item => item.kind === kind && Number.isFinite(Number(item.lat)) && Number.isFinite(Number(item.lng)));
      if (!choices.length) return '';
      choices.sort((a, b) => distanceMiles(userLatLng.lat, userLatLng.lng, Number(a.lat), Number(a.lng)) - distanceMiles(userLatLng.lat, userLatLng.lng, Number(b.lat), Number(b.lng)));
      const item = choices[0];
      const distance = formatDistance(distanceMiles(userLatLng.lat, userLatLng.lng, Number(item.lat), Number(item.lng)));
      return `<button type="button" class="tng-map-nearest__item" data-tng-nearest-id="${esc(item.id)}"><span>${icon}</span><small>${label}</small><strong>${esc(item.title)}</strong><b>${distance}</b></button>`;
    }).filter(Boolean).join('');
    nearestEl.innerHTML = cards;
    nearestEl.hidden = !cards;
  };

  const renderMarkers = ({ fit = false } = {}) => {
    markerLayer.clearLayers();
    markers.clear();
    const bounds = [];
    cfg.items.forEach(item => {
      if (!showItem(item) || !Number.isFinite(Number(item.lat)) || !Number.isFinite(Number(item.lng))) return;
      const marker = L.marker([Number(item.lat), Number(item.lng)], { icon: markerIcon(item), keyboard: true });
      if (!isMobile()) marker.bindPopup(() => popup(item), { className: 'tng-discovery-popup', maxWidth: 320 });
      marker.on('click', () => {
        activateResult(item.id);
        if (isMobile()) openMobileSheet(item);
      });
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

    if (emptyEl) emptyEl.hidden = visible.length > 0 || (!searchQuery && activeFilter === 'all');

    const count = document.querySelector('[data-tng-map-count]');
    if (count) count.textContent = `${visible.length} ${visible.length === 1 ? 'discovery' : 'discoveries'} in view`;
    if (panelIntro) panelIntro.textContent = userLatLng
      ? 'Showing discoveries in this map view, sorted by distance from you.'
      : searchQuery
        ? `Showing matches for “${searchQuery}”. Move or zoom to explore another area.`
        : 'Showing discoveries currently visible on the map. Move or zoom to explore another area.';

    if (activeSheetId && userLatLng) {
      const activeItem = itemById.get(activeSheetId);
      if (activeItem && sheetContent && sheet?.classList.contains('is-open')) sheetContent.innerHTML = sheetMarkup(activeItem);
    }
  };

  document.querySelectorAll('[data-tng-map-result]').forEach(node => {
    resultNodes.set(node.getAttribute('data-tng-map-result'), node);
    node.addEventListener('click', event => {
      if (event.target.closest('a,button')) return;
      event.preventDefault();
      focusItem(node.getAttribute('data-tng-map-result'));
    });
  });

  document.querySelectorAll('[data-tng-map-filter]').forEach(button => {
    button.addEventListener('click', () => {
      closeMobileSheet();
      activeFilter = button.getAttribute('data-tng-map-filter') || 'all';
      document.querySelectorAll('[data-tng-map-filter]').forEach(b => b.classList.toggle('is-active', b === button));
      renderMarkers({ fit: true });
    });
  });

  const applySearch = value => {
    searchQuery = String(value || '').trim().toLowerCase();
    if (searchClear) searchClear.hidden = !searchQuery;
    closeMobileSheet();
    renderMarkers({ fit: true });
  };

  if (searchInput) {
    let searchTimer = 0;
    searchInput.addEventListener('input', () => {
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(() => applySearch(searchInput.value), 120);
    });
    searchInput.addEventListener('search', () => applySearch(searchInput.value));
  }
  if (searchClear) searchClear.addEventListener('click', () => {
    if (searchInput) {
      searchInput.value = '';
      searchInput.focus();
    }
    applySearch('');
  });

  document.addEventListener('click', event => {
    const closeButton = event.target.closest('[data-tng-map-sheet-close]');
    if (closeButton) {
      event.preventDefault();
      closeMobileSheet();
      return;
    }
    const directionButton = event.target.closest('[data-tng-directions]');
    if (directionButton) {
      event.preventDefault();
      event.stopPropagation();
      const lat = Number(directionButton.getAttribute('data-lat'));
      const lng = Number(directionButton.getAttribute('data-lng'));
      if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
      window.open(directionsUrl({ lat, lng }), '_blank', 'noopener');
      return;
    }
    const nearestButton = event.target.closest('[data-tng-nearest-id]');
    if (nearestButton) {
      event.preventDefault();
      focusItem(nearestButton.getAttribute('data-tng-nearest-id'));
    }
  }, true);

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && sheet?.classList.contains('is-open')) closeMobileSheet();
  });

  if (sheetHandle && sheet) {
    sheetHandle.addEventListener('touchstart', event => {
      if (!sheet.classList.contains('is-open')) return;
      touchStartY = event.touches[0]?.clientY || 0;
      touchDeltaY = 0;
      sheet.classList.add('is-dragging');
    }, { passive: true });
    sheetHandle.addEventListener('touchmove', event => {
      if (!sheet.classList.contains('is-dragging')) return;
      const currentY = event.touches[0]?.clientY || touchStartY;
      touchDeltaY = Math.max(0, currentY - touchStartY);
      sheet.style.transform = `translateY(${touchDeltaY}px)`;
    }, { passive: true });
    sheetHandle.addEventListener('touchend', () => {
      sheet.classList.remove('is-dragging');
      if (touchDeltaY > 80) closeMobileSheet();
      else sheet.style.transform = '';
      touchStartY = 0;
      touchDeltaY = 0;
    }, { passive: true });
  }

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
      renderNearest();
      setTimeout(updateLiveResults, 250);
    }, () => {
      locate.classList.remove('is-loading');
      locate.innerHTML = '<span>⌖</span> Try location again';
    }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
  });

  mobileQuery.addEventListener?.('change', () => {
    closeMobileSheet();
    renderMarkers();
    setTimeout(() => map.invalidateSize(), 80);
  });

  hideLegacyXpOverlay();
  window.setTimeout(hideLegacyXpOverlay, 350);
  window.setTimeout(hideLegacyXpOverlay, 1200);
  renderMarkers();
  setTimeout(() => { map.invalidateSize(); updateLiveResults(); }, 180);
})();
