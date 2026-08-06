(() => {
  const list = document.querySelector('[data-tng-builder-list]');
  if (!list || !window.TNGTripData) return;
  const status = document.querySelector('[data-tng-builder-status]');
  let dragged = null;
  let saveTimer = null;

  const renumber = () => {
    [...list.children].forEach((item, index) => {
      const number = item.querySelector('.tng-builder-stop__number');
      if (number) number.textContent = String(index + 1);
    });
    const count = document.querySelector('[data-tng-builder-count]');
    if (count) count.textContent = String(list.children.length);
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
})();