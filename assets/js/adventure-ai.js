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
  let plan = null;

  const setStatus = (message, error = false) => {
    status.textContent = message;
    status.classList.toggle('is-error', error);
  };

  const duration = (minutes) => {
    const value = Number(minutes || 0);
    if (value < 60) return `${value} min`;
    const hours = Math.round((value / 60) * 10) / 10;
    return `${hours} hr`;
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

  const render = (data) => {
    plan = data;
    title.textContent = data.title || 'Your Tennessee adventure';
    summary.textContent = `${data.summary || ''} ${data.stops.length} stops · ${duration(data.total_minutes)}. ${data.planning_note || ''}`.trim();
    tags.replaceChildren();
    (data.tags || []).forEach((label) => {
      const tag = document.createElement('span');
      tag.textContent = label;
      tags.appendChild(tag);
    });
    stops.replaceChildren();
    data.stops.forEach((stop, index) => {
      const row = document.createElement('article');
      row.className = 'tng-ai-stop';
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
      const visit = document.createElement('span');
      visit.className = 'tng-ai-stop__duration';
      visit.textContent = duration(stop.minutes);
      row.append(time, number, image, copy, visit);
      stops.appendChild(row);
    });
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
      setStatus('Adventure ready. You can save it to Trips or generate another.');
    } catch (error) {
      setStatus(error.message || 'TN Game could not build that adventure.', true);
    } finally {
      submit.disabled = false;
      submit.innerHTML = '<span aria-hidden="true">✦</span> Build my adventure';
    }
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
      });
      save.textContent = `✓ Saved ${data.added} new stop${data.added === 1 ? '' : 's'}`;
      setStatus(`${data.count} total stops are now in Trips.`);
    } catch (error) {
      save.disabled = false;
      save.textContent = '＋ Save stops to Trips';
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
})();
