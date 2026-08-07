(() => {
  const data = window.TNG_VISUAL_BUILDER;
  if (!data || typeof L === 'undefined') return;

  const esc = (value='') => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const round = n => Math.round(Number(n) * 1e6) / 1e6;

  const mount = () => {
    const form = document.querySelector('.tng-game-builder-form');
    const textarea = form?.querySelector('textarea[name="checkpoint_plan"]');
    const trailSelect = form?.querySelector('select[name="trail_route_source"]');
    if (!form || !textarea || !trailSelect || form.querySelector('[data-tng-visual-builder]')) return;

    const label = textarea.closest('label');
    if (!label) return;

    const wrap = document.createElement('div');
    wrap.className = 'tng-visual-builder';
    wrap.setAttribute('data-tng-visual-builder','');
    wrap.innerHTML = `
      <div class="tng-visual-builder__head">
        <div>
          <span class="tng-eyebrow">Easy route builder</span>
          <h3>${esc(data.labels.title)}</h3>
          <p>${esc(data.labels.subtitle)}</p>
        </div>
        <div class="tng-visual-builder__sight-tools">
          <select data-tng-sight-select><option value="">${esc(data.labels.chooseSight)}</option></select>
          <button type="button" data-tng-add-sight>${esc(data.labels.addSight)}</button>
        </div>
      </div>
      <div class="tng-visual-builder__map" data-tng-builder-map></div>
      <div class="tng-visual-builder__route-status" data-tng-route-status>Choose a trail above to preview its route, or click anywhere on the map to add a GPS checkpoint.</div>
      <div class="tng-visual-builder__trail-sights" data-tng-trail-sights hidden></div>
      <div class="tng-visual-builder__list" data-tng-checkpoint-list></div>
      <details class="tng-visual-builder__advanced">
        <summary>Advanced / raw checkpoint format</summary>
        <div data-tng-advanced-slot></div>
      </details>`;

    label.parentNode.insertBefore(wrap, label);
    wrap.querySelector('[data-tng-advanced-slot]').appendChild(label);

    const allSights = Array.isArray(data.sights) ? data.sights : [];
    const sightById = new Map(allSights.map(s => [String(s.id), s]));
    const sightSelect = wrap.querySelector('[data-tng-sight-select]');
    if (allSights.length) {
      allSights.forEach(s => {
        const option = document.createElement('option');
        option.value = String(s.id);
        option.textContent = s.title;
        sightSelect.appendChild(option);
      });
    } else {
      const option = document.createElement('option');
      option.value = '';
      option.disabled = true;
      option.textContent = 'No Top Sights with coordinates were detected';
      sightSelect.appendChild(option);
    }

    const map = L.map(wrap.querySelector('[data-tng-builder-map]'), { scrollWheelZoom:false });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom:19, attribution:'&copy; OpenStreetMap contributors' }).addTo(map);
    map.setView([35.25,-85.75], 11);

    let routeLayer = null;
    const checkpointLayer = L.layerGroup().addTo(map);
    const trailSightLayer = L.layerGroup().addTo(map);
    let checkpoints = [];
    let activeTrailId = '';
    const list = wrap.querySelector('[data-tng-checkpoint-list]');
    const routeStatus = wrap.querySelector('[data-tng-route-status]');
    const trailSightsBox = wrap.querySelector('[data-tng-trail-sights]');
    const trailById = new Map((data.trails || []).map(t => [String(t.id), t]));

    const lineFor = cp => {
      const title = (cp.title || '').trim();
      const instructions = (cp.instructions || '').trim();
      const type = cp.type || 'gps';
      if (cp.sightId) return `${title} | ${instructions} | sight | ${cp.sightId} | ${type} | ${cp.radius || 30}`;
      if (type === 'question') return `${title} | ${instructions} | question | ${cp.answer || ''}`;
      if (type === 'gps') return `${title} | ${instructions} | gps | ${round(cp.lat)} | ${round(cp.lng)} | ${cp.radius || 30}`;
      if (type === 'photo') return `${title} | ${instructions} | photo`;
      return `${title} | ${instructions} | tap`;
    };

    const sync = () => { textarea.value = checkpoints.map(lineFor).join('\n'); };

    const hasSight = id => checkpoints.some(cp => String(cp.sightId || '') === String(id));
    const addSightCheckpoint = (sight, source='manual') => {
      if (!sight || hasSight(sight.id)) return false;
      checkpoints.push({
        title:sight.title,
        instructions:`Visit ${sight.title}.`,
        type:'gps',
        sightId:sight.id,
        lat:Number(sight.lat),
        lng:Number(sight.lng),
        radius:30,
        autoTrailId: source === 'trail' ? activeTrailId : ''
      });
      return true;
    };

    const renderTrailSights = trail => {
      trailSightLayer.clearLayers();
      const ids = Array.isArray(trail?.sightIds) ? trail.sightIds : [];
      const sights = ids.map(id => sightById.get(String(id))).filter(Boolean);
      if (!sights.length) {
        trailSightsBox.hidden = true;
        trailSightsBox.innerHTML = '';
        return;
      }
      trailSightsBox.hidden = false;
      trailSightsBox.innerHTML = `<div><strong>Top Sights already on this trail</strong><span>${sights.length} found</span></div><div data-tng-trail-sight-chips></div>`;
      const chips = trailSightsBox.querySelector('[data-tng-trail-sight-chips]');
      sights.forEach(sight => {
        const marker = L.circleMarker([Number(sight.lat),Number(sight.lng)], {
          radius:8, color:'#0f5132', weight:3, fillColor:'#fff', fillOpacity:1
        }).addTo(trailSightLayer);
        marker.bindTooltip(sight.title, { direction:'top' });
        marker.on('click', () => {
          if (addSightCheckpoint(sight,'manual')) { sync(); render(); }
        });
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.textContent = hasSight(sight.id) ? `✓ ${sight.title}` : `+ ${sight.title}`;
        chip.disabled = hasSight(sight.id);
        chip.addEventListener('click', () => {
          if (addSightCheckpoint(sight,'manual')) { sync(); render(); map.setView([Number(sight.lat),Number(sight.lng)],16); renderTrailSights(trail); }
        });
        chips.appendChild(chip);
      });
    };

    const render = () => {
      checkpointLayer.clearLayers();
      list.innerHTML = '';
      checkpoints.forEach((cp, index) => {
        if (Number.isFinite(cp.lat) && Number.isFinite(cp.lng) && (cp.lat || cp.lng)) {
          const marker = L.marker([cp.lat, cp.lng], { draggable: !cp.sightId }).addTo(checkpointLayer);
          marker.bindTooltip(String(index + 1), { permanent:true, direction:'center', className:'tng-builder-marker-label' });
          if (!cp.sightId) marker.on('dragend', e => {
            const pos = e.target.getLatLng(); cp.lat = round(pos.lat); cp.lng = round(pos.lng); sync(); render();
          });
        }
        const row = document.createElement('div');
        row.className = 'tng-visual-checkpoint';
        row.innerHTML = `
          <div class="tng-visual-checkpoint__num">${index + 1}</div>
          <div class="tng-visual-checkpoint__fields">
            <input data-field="title" value="${esc(cp.title)}" placeholder="Checkpoint name">
            <input data-field="instructions" value="${esc(cp.instructions)}" placeholder="What should the player do here?">
            <div class="tng-visual-checkpoint__options">
              <select data-field="type">
                <option value="gps" ${cp.type==='gps'?'selected':''}>GPS check-in</option>
                <option value="photo" ${cp.type==='photo'?'selected':''}>Photo</option>
                <option value="question" ${cp.type==='question'?'selected':''}>Question / puzzle</option>
                <option value="tap" ${cp.type==='tap'?'selected':''}>Simple tap</option>
              </select>
              <input data-field="radius" type="number" min="5" max="500" value="${cp.radius || 30}" ${cp.type==='gps'?'':'hidden'} aria-label="GPS radius in meters">
              <input data-field="answer" value="${esc(cp.answer || '')}" placeholder="Correct answer" ${cp.type==='question'?'':'hidden'}>
            </div>
            ${cp.sightId ? `<small>📍 Linked Top Sight · coordinates filled automatically${cp.autoTrailId ? ' · preloaded from trail' : ''}</small>` : (cp.type==='gps' ? `<small>📍 ${round(cp.lat)}, ${round(cp.lng)} · drag marker to adjust</small>` : '')}
          </div>
          <div class="tng-visual-checkpoint__actions">
            <button type="button" data-move="up" aria-label="Move up">↑</button>
            <button type="button" data-move="down" aria-label="Move down">↓</button>
            <button type="button" data-remove aria-label="Remove checkpoint">×</button>
          </div>`;
        row.querySelectorAll('[data-field]').forEach(el => el.addEventListener('input', () => {
          const field = el.dataset.field;
          cp[field] = field === 'radius' ? Number(el.value || 30) : el.value;
          if (field === 'type') render(); else sync();
        }));
        row.querySelector('[data-remove]').addEventListener('click', () => { checkpoints.splice(index,1); sync(); render(); renderTrailSights(trailById.get(activeTrailId)); });
        row.querySelector('[data-move="up"]').addEventListener('click', () => {
          if (index < 1) return; [checkpoints[index-1],checkpoints[index]]=[checkpoints[index],checkpoints[index-1]]; sync(); render();
        });
        row.querySelector('[data-move="down"]').addEventListener('click', () => {
          if (index >= checkpoints.length-1) return; [checkpoints[index+1],checkpoints[index]]=[checkpoints[index],checkpoints[index+1]]; sync(); render();
        });
        list.appendChild(row);
      });
      sync();
    };

    map.on('click', e => {
      checkpoints.push({ title:`${data.labels.checkpoint} ${checkpoints.length+1}`, instructions:'', type:'gps', lat:round(e.latlng.lat), lng:round(e.latlng.lng), radius:30 });
      render();
    });

    const parseGpx = text => {
      const xml = new DOMParser().parseFromString(text, 'application/xml');
      return [...xml.querySelectorAll('trkpt, rtept')]
        .map(n => [Number(n.getAttribute('lat')), Number(n.getAttribute('lon'))])
        .filter(p => Number.isFinite(p[0]) && Number.isFinite(p[1]));
    };

    const preloadTrailSights = trail => {
      const ids = Array.isArray(trail?.sightIds) ? trail.sightIds : [];
      let added = 0;
      ids.forEach(id => { if (addSightCheckpoint(sightById.get(String(id)),'trail')) added++; });
      if (added) { sync(); render(); }
      renderTrailSights(trail);
      return added;
    };

    const loadTrail = async () => {
      if (routeLayer) { map.removeLayer(routeLayer); routeLayer = null; }
      trailSightLayer.clearLayers();
      const previousTrail = activeTrailId;
      activeTrailId = String(trailSelect.value || '');
      if (previousTrail && previousTrail !== activeTrailId) {
        checkpoints = checkpoints.filter(cp => !cp.autoTrailId || String(cp.autoTrailId) !== String(previousTrail));
        sync(); render();
      }
      const trail = trailById.get(activeTrailId);
      if (!trail) {
        trailSightsBox.hidden = true;
        routeStatus.textContent = 'No linked trail. Click the map to place checkpoints manually.';
        return;
      }
      const added = preloadTrailSights(trail);
      const sightNote = (trail.sightIds || []).length ? ` ${trail.sightIds.length} linked Top Sight${trail.sightIds.length===1?'':'s'} loaded automatically.` : '';
      if (!trail.gpxUrl) {
        routeStatus.textContent = `${trail.title} is selected, but no GPX file was detected.${sightNote} You can still click the map to place checkpoints.`;
        return;
      }
      routeStatus.textContent = `Loading ${trail.title}…`;
      try {
        const response = await fetch(trail.gpxUrl, { credentials:'same-origin' });
        if (!response.ok) throw new Error('GPX request failed');
        const pts = parseGpx(await response.text());
        if (!pts.length) throw new Error('No route points');
        routeLayer = L.polyline(pts, { color:'#ef6425', weight:5, opacity:.92 }).addTo(map);
        const bounds = routeLayer.getBounds();
        (trail.sightIds || []).map(id => sightById.get(String(id))).filter(Boolean).forEach(s => bounds.extend([Number(s.lat),Number(s.lng)]));
        map.fitBounds(bounds, { padding:[35,35] });
        routeStatus.textContent = `${trail.title} route loaded.${sightNote} Click the trail to add more checkpoints.`;
      } catch (e) {
        routeStatus.textContent = `The trail is linked, but its GPX preview could not load.${sightNote} You can still place checkpoints manually.`;
      }
      if (added) renderTrailSights(trail);
    };
    trailSelect.addEventListener('change', loadTrail);

    wrap.querySelector('[data-tng-add-sight]').addEventListener('click', () => {
      const sight = sightById.get(String(sightSelect.value));
      if (!sight) return;
      if (addSightCheckpoint(sight,'manual')) { sync(); render(); }
      map.setView([Number(sight.lat),Number(sight.lng)], 16);
      sightSelect.value = '';
      renderTrailSights(trailById.get(activeTrailId));
    });

    form.addEventListener('submit', sync);
    setTimeout(() => map.invalidateSize(), 120);
    loadTrail();
    render();
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
  else mount();
})();
