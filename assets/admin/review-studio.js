document.addEventListener('DOMContentLoaded', function () {
  const selectAll = document.getElementById('tng-select-all');
  const checks = Array.from(document.querySelectorAll('.tng-item-check'));
  const cards = Array.from(document.querySelectorAll('.tng-queue-card'));
  const bulkForm = document.querySelector('.tng-review-queue form');
  const bulkSelect = bulkForm ? bulkForm.querySelector('select[name="bulk_action"]') : null;

  function updateSelectionState() {
    const selectedCount = checks.filter(function (item) { return item.checked; }).length;

    if (selectAll) {
      selectAll.checked = checks.length > 0 && selectedCount === checks.length;
      selectAll.indeterminate = selectedCount > 0 && selectedCount < checks.length;
    }

    if (bulkSelect) {
      bulkSelect.setAttribute('aria-label', selectedCount
        ? 'Bulk action for ' + selectedCount + ' selected items'
        : 'Bulk action');
    }
  }

  if (selectAll) {
    selectAll.addEventListener('change', function () {
      checks.forEach(function (check) { check.checked = selectAll.checked; });
      updateSelectionState();
    });
  }

  checks.forEach(function (check) {
    check.addEventListener('change', updateSelectionState);
  });

  document.querySelectorAll('.tng-queue-card > a').forEach(function (link) {
    link.addEventListener('click', function (event) {
      if (event.target && event.target.matches('input, button, select')) event.preventDefault();
    });
  });

  if (bulkForm) {
    bulkForm.addEventListener('submit', function (event) {
      const selectedCount = checks.filter(function (item) { return item.checked; }).length;
      const action = bulkSelect ? bulkSelect.value : '';

      if (!selectedCount || !action) {
        event.preventDefault();
        window.alert('Select at least one item and choose a bulk action.');
        return;
      }

      if (action === 'ignore' && !window.confirm('Ignore ' + selectedCount + ' selected item(s)?')) {
        event.preventDefault();
      }
    });
  }

  const selectedIndex = cards.findIndex(function (card) { return card.classList.contains('is-selected'); });
  const shortcutHelp = document.createElement('p');
  shortcutHelp.className = 'tng-review-shortcuts';
  shortcutHelp.innerHTML = '<span><kbd>J</kbd>/<kbd>K</kbd> move</span><span><kbd>P</kbd> publish</span><span><kbd>I</kbd> ignore</span>';
  const queueHead = document.querySelector('.tng-queue-head');
  if (queueHead) queueHead.insertAdjacentElement('afterend', shortcutHelp);

  function isTypingTarget(target) {
    return target instanceof HTMLElement && (
      target.matches('input, textarea, select, button') ||
      target.isContentEditable
    );
  }

  function openCard(index) {
    if (!cards.length) return;
    const normalized = Math.max(0, Math.min(cards.length - 1, index));
    const link = cards[normalized].querySelector(':scope > a');
    if (link) window.location.assign(link.href);
  }

  document.addEventListener('keydown', function (event) {
    if (event.metaKey || event.ctrlKey || event.altKey || isTypingTarget(event.target)) return;

    const key = event.key.toLowerCase();
    if (key === 'j') {
      event.preventDefault();
      openCard((selectedIndex < 0 ? -1 : selectedIndex) + 1);
    } else if (key === 'k') {
      event.preventDefault();
      openCard((selectedIndex < 0 ? 1 : selectedIndex) - 1);
    } else if (key === 'p') {
      const publish = document.querySelector('.tng-workspace-actions a[href*="tng_concert_import_item"]');
      if (publish && window.confirm('Publish or update this Activity?')) {
        event.preventDefault();
        window.location.assign(publish.href);
      }
    } else if (key === 'i') {
      const ignore = document.querySelector('.tng-workspace-actions a[href*="tng_concert_ignore_item"]');
      if (ignore && window.confirm('Ignore this review item?')) {
        event.preventDefault();
        window.location.assign(ignore.href);
      }
    }
  });

  document.querySelectorAll('.tng-workspace-actions a[href*="tng_concert_ignore_item"]').forEach(function (link) {
    link.addEventListener('click', function (event) {
      if (!window.confirm('Ignore this review item?')) event.preventDefault();
    });
  });

  updateSelectionState();
});
