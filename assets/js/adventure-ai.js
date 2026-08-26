(() => {
  'use strict';

  const root = document.querySelector('[data-tng-adventure-ai]');
  if (!root) return;

  const form = root.querySelector('[data-tng-ai-form]');
  const prompt = form.querySelector('textarea[name="prompt"]');
  const submit = form.querySelector('button[type="submit"]');
  const status = root.querySelector('[data-tng-ai-status]');
  const results = root.querySelector('[data-tng-ai-results]');
  const title = root.querySelector('[data-tng-ai-title]');
  const summary = root.querySelector('[data-tng-ai-summary]');
  const tags = root.querySelector('[data-tng-ai-tags]');
  const stops = root.querySelector('[data-tng-ai-stops]');
  const save = root.querySelector('[data-tng-ai-save]');
  const share = root.querySelector('[data-tng-ai-share]');
  const start = root.querySelector('[data-tng-ai-start]');
  const buffer = root.querySelector('[data-tng-ai-buffer]');
  const reset = root.querySelector('[data-tng-ai-reset]');
  const undo = root.querySelector('[data-tng-ai-undo]');
  const stopCount = root.querySelector('[data-tng-ai-stop-count]');
  const totalTime = root.querySelector('[data-tng-ai-total-time]');
  const routeCount = root.querySelector('[data-tng-ai-route-count]');
  const routeSvg = root.querySelector('[data-tng-ai-route-svg]');
  const routeEmpty = root.querySelector('[data-tng-ai-route-empty]');
  let plan = null;
  let originalStops = [];
  let originalTiming = {start: 600, buffer: 20};
  let removedStop = null;

  const setStatus = (message, error = false) => {
    status.textContent = message;
    status.classList.toggle('is-error', error);
  };

  const duration = (minutes) => {
    const value = Number(minutes || 0);
    if (value < 60) return `${value} min`;
    return `${Math.round((value / 60) * 10) / 10} hr`;
  };

  const formatClock = (minutes) => {
    const normalized = ((Number(minutes) % 1440) + 1440) % 1440;
    const hour = Math.floor(normalized / 60);
    const minute = normalized % 60;
    return `${hour % 12 || 12}:${String(minute).padStart(2, '0')} ${hour >= 12 ? 'PM' : 'AM'}`;
  };

  const timeInput = (minutes) => {
    const normalized = ((Number(minutes) % 1440) + 1440) % 1440;
    return `${String(Math.floor(normalized / 60)).padStart(2, '0')}:${String(normalized % 60).padStart(2, '0')}`;
  };

  const startMinutes = () => {
    const [hours, minutes] = String(start.value || '10:00').split(':').map(Number);
    return Math.min(1439, Math.max(0, (hours || 0) * 60 + (minutes || 0)));
  };

  const cloneStops = (items) => items.map((item) => ({...item}));

  const markDirty = () => {
    save.disabled = false;
    save.textContent = '＋ Save adventure';
  };

  const post = async (action, fields = {}) => {
    const body = new URLSearchParams({action, nonce: root.dataset.nonce || ''});
    Object.entries(fields).forEach(([key, value]) => {
      if (Array.isArray(value)) value.forEach((item) => body.append(`${key}[]`, String(item)));
      else body.append(key, String(value));
    });
    const response = await fetch(root.dataset.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body,
    });
    const json = await response.json();
    if (!json.success) throw new Error(json.data?.message || 'TN Game could not build that adventure.');
    return json.data;
  };

  const drawRoute = () => {
    const mapped = plan.stops.filter((stop) => Number.isFinite(Number(stop.lat)) && Number.isFinite(Number(stop.lng)));
    routeSvg.replaceChildren();
    routeCount.textContent = `${mapped.length} mapped stop${mapped.length === 1 ? '' : 's'}`;
    routeEmpty.hidden = mapped.length > 0;
    routeSvg.hidden = mapped.length === 0;
    if (!mapped.length) return;

    const namespace = 'http://www.w3.org/2000/svg';
    const lats = mapped.map((stop) => Number(stop.lat));
    const lngs = mapped.map((stop) => Number(stop.lng));
    const minLat = Math.min(...lats);
    const maxLat = Math.max(...lats);
    const minLng = Math.min(...lngs);
    const maxLng = Math.max(...lngs);
    const latSpan = maxLat - minLat || 0.04;
    const lngSpan = maxLng - minLng || 0.04;
    const points = mapped.map((stop) => ({
      stop,
      x: 34 + ((Number(stop.lng) - minLng) / lngSpan) * 452,
      y: 28 + ((maxLat - Number(stop.lat)) / latSpan) * 164,
      number: plan.stops.indexOf(stop) + 1,
    }));
    if (points.length === 1) Object.assign(points[0], {x: 260, y: 110});

    if (points.length > 1) {
      const path = document.createElementNS(namespace, 'path');
      path.setAttribute('class', 'tng-ai-route-line');
      path.setAttribute('d', points.map((point, index) => `${index ? 'L' : 'M'} ${point.x} ${point.y}`).join(' '));
      routeSvg.appendChild(path);
    }
    points.forEach((point) => {
      const group = document.createElementNS(namespace, 'g');
      group.setAttribute('class', 'tng-ai-route-point');
      const circle = document.createElementNS(namespace, 'circle');
      circle.setAttribute('cx', point.x);
      circle.setAttribute('cy', point.y);
      circle.setAttribute('r', '15');
      const number = document.createElementNS(namespace, 'text');
      number.setAttribute('x', point.x);
      number.setAttribute('y', point.y + 4);
      number.setAttribute('text-anchor', 'middle');
      number.textContent = String(point.number);
      const label = document.createElementNS(namespace, 'title');
      label.textContent = `${point.number}. ${point.stop.title}`;
      group.append(circle, number, label);
      routeSvg.appendChild(group);
    });
  };

  const recalculate = () => {
    if (!plan) return;
    const travel = Number(buffer.value || 20);
    let clock = startMinutes();
    plan.stops.forEach((stop) => {
      stop.time = formatClock(clock);
      clock += Number(stop.minutes || 0) + travel;
    });
    plan.start_minutes = startMinutes();
    plan.buffer_minutes = travel;
    plan.total_minutes = plan.stops.reduce((total, stop) => total + Number(stop.minutes || 0), 0) + Math.max(0, plan.stops.length - 1) * travel;
    stopCount.textContent = String(plan.stops.length);
    totalTime.textContent = duration(plan.total_minutes);
    summary.textContent = `${plan.summary || ''} ${plan.stops.length} stops · ${duration(plan.total_minutes)}. Times include a ${travel}-minute planning buffer between stops. Confirm hours, tickets, trail conditions, and driving time before leaving.`.trim();
  };

  const renderStops = () => {
    stops.replaceChildren();
    plan.stops.forEach((stop, index) => {
      const row = document.createElement('article');
      row.className = 'tng-ai-stop';
      row.dataset.stopIndex = String(index);
      const time = document.createElement('div');
      time.className = 'tng-ai-stop__time';
      time.textContent = stop.time;
      const number = document.createElement('div');
      number.className = 'tng-ai-stop__number';
      number.textContent = String(index + 1);
      const image = document.createElement('a');
      image.className = 'tng-ai-stop__image';
      image.href = stop.url;
      image.setAttribute('aria-label', `View ${stop.title}`);
      if (stop.image) image.style.backgroundImage = `url("${String(stop.image).replaceAll('"', '%22')}")`;
      const copy = document.createElement('div');
      copy.className = 'tng-ai-stop__copy';
      const label = document.createElement('small');
      label.textContent = `${stop.label} · ${stop.type}`;
      const heading = document.createElement('h3');
      const link = document.createElement('a');
      link.href = stop.url;
      link.textContent = stop.title;
      heading.appendChild(link);
      const reason = document.createElement('p');
      reason.textContent = stop.reason;
      copy.append(label, heading, reason);
      const meta = document.createElement('div');
      meta.className = 'tng-ai-stop__meta';
      const visit = document.createElement('span');
      visit.className = 'tng-ai-stop__duration';
      visit.textContent = duration(stop.minutes);
      const actions = document.createElement('div');
      actions.className = 'tng-ai-stop__actions';
      [['move-up', '↑', 'Move stop earlier'], ['move-down', '↓', 'Move stop later'], ['remove', '×', 'Remove stop']].forEach(([action, symbol, labelText]) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.tngAiAction = action;
        button.textContent = symbol;
        button.setAttribute('aria-label', `${labelText}: ${stop.title}`);
        if ((action === 'move-up' && index === 0) || (action === 'move-down' && index === plan.stops.length - 1) || (action === 'remove' && plan.stops.length === 1)) button.disabled = true;
        actions.appendChild(button);
      });
      meta.append(visit, actions);
      row.append(time, number, image, copy, meta);
      stops.appendChild(row);
    });
  };

  const refreshPlan = () => {
    recalculate();
    renderStops();
    drawRoute();
  };

  const render = (data) => {
    plan = {...data, stops: cloneStops(data.stops || [])};
    originalStops = cloneStops(plan.stops);
    originalTiming = {start: Number(data.start_minutes ?? 600), buffer: Number(data.buffer_minutes ?? 20)};
    removedStop = null;
    undo.hidden = true;
    title.textContent = data.title || 'Your Tennessee adventure';
    start.value = timeInput(data.start_minutes ?? 600);
    buffer.value = String(data.buffer_minutes ?? 20);
    tags.replaceChildren();
    (data.tags || []).forEach((label) => {
      const tag = document.createElement('span');
      tag.textContent = label;
      tags.appendChild(tag);
    });
    refreshPlan();
    results.hidden = false;
    results.scrollIntoView({behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start'});
  };

  root.querySelectorAll('[data-tng-ai-example]').forEach((button) => {
    button.addEventListener('click', () => {
      prompt.value = button.dataset.tngAiExample || button.textContent || '';
      prompt.focus();
      setStatus('Prompt ready—edit it or build your adventure.');
    });
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const value = prompt.value.trim();
    if (value.length < 8) {
      setStatus('Tell us a little more about the adventure you want.', true);
      prompt.focus();
      return;
    }
    submit.disabled = true;
    submit.innerHTML = '<span aria-hidden="true">✦</span> Building your adventure…';
    results.hidden = true;
    setStatus('Reading your request and matching Tennessee places…');
    try {
      render(await post('tng_generate_adventure_ai', {prompt: value}));
      setStatus('Adventure ready. Reorder it, adjust timing, or save it to Trips.');
    } catch (error) {
      setStatus(error.message || 'TN Game could not build that adventure.', true);
    } finally {
      submit.disabled = false;
      submit.innerHTML = '<span aria-hidden="true">✦</span> Build my adventure';
    }
  });

  stops.addEventListener('click', (event) => {
    const button = event.target.closest('[data-tng-ai-action]');
    const row = button?.closest('[data-stop-index]');
    if (!button || !row || !plan) return;
    const index = Number(row.dataset.stopIndex);
    const action = button.dataset.tngAiAction;
    if (action === 'move-up' && index > 0) [plan.stops[index - 1], plan.stops[index]] = [plan.stops[index], plan.stops[index - 1]];
    if (action === 'move-down' && index < plan.stops.length - 1) [plan.stops[index + 1], plan.stops[index]] = [plan.stops[index], plan.stops[index + 1]];
    if (action === 'remove' && plan.stops.length > 1) {
      removedStop = {stop: plan.stops[index], index};
      plan.stops.splice(index, 1);
      undo.hidden = false;
    }
    refreshPlan();
    markDirty();
    setStatus('Itinerary updated. Save when it feels right.');
  });

  [start, buffer].forEach((control) => control.addEventListener('change', () => {
    refreshPlan();
    markDirty();
    setStatus('Arrival times recalculated.');
  }));

  reset.addEventListener('click', () => {
    if (!plan) return;
    plan.stops = cloneStops(originalStops);
    start.value = timeInput(originalTiming.start);
    buffer.value = String(originalTiming.buffer);
    removedStop = null;
    undo.hidden = true;
    refreshPlan();
    markDirty();
    setStatus('Original itinerary restored.');
  });

  undo.addEventListener('click', () => {
    if (!plan || !removedStop) return;
    plan.stops.splice(Math.min(removedStop.index, plan.stops.length), 0, removedStop.stop);
    removedStop = null;
    undo.hidden = true;
    refreshPlan();
    markDirty();
    setStatus('Stop restored.');
  });

  save.addEventListener('click', async () => {
    if (!plan?.stops?.length) return;
    if (root.dataset.loggedIn !== '1') {
      window.location.assign(root.dataset.loginUrl || '/wp-login.php');
      return;
    }
    save.disabled = true;
    save.textContent = 'Saving to Trips…';
    try {
      const data = await post('tng_save_adventure_ai', {
        ids: plan.stops.map((stop) => stop.id),
        prompt: prompt.value.trim(),
        title: plan.title,
        plan_id: plan.id || '',
        start_minutes: startMinutes(),
        buffer_minutes: Number(buffer.value || 20),
      });
      plan.id = data.plan_id || plan.id;
      save.textContent = '✓ Adventure saved';
      setStatus(`${data.count} total stops are now in Trips. This plan is in Saved Adventures.`);
    } catch (error) {
      save.disabled = false;
      save.textContent = '＋ Save adventure';
      setStatus(error.message || 'The itinerary could not be saved.', true);
    }
  });

  share.addEventListener('click', async () => {
    if (!plan?.stops?.length) return;
    const text = `${plan.title}\n${plan.stops.map((stop, index) => `${index + 1}. ${stop.time} — ${stop.title}`).join('\n')}`;
    try {
      if (navigator.share) await navigator.share({title: plan.title, text});
      else {
        await navigator.clipboard.writeText(text);
        setStatus('Itinerary copied to your clipboard.');
      }
    } catch (error) {
      if (error?.name !== 'AbortError') setStatus('The itinerary could not be shared.', true);
    }
  });

  const initial = root.querySelector('[data-tng-ai-initial]');
  if (initial) {
    try {
      const restored = JSON.parse(initial.textContent || '{}');
      if (restored?.stops?.length) {
        prompt.value = restored.prompt || '';
        render(restored);
        setStatus('Saved adventure reopened. Edit it or continue to Trips.');
      }
    } catch (error) {
      setStatus('The saved adventure could not be reopened.', true);
    }
  }
})();
