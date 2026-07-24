document.addEventListener('DOMContentLoaded', function () {
  const selectAll = document.getElementById('tng-select-all');
  const checks = Array.from(document.querySelectorAll('.tng-item-check'));

  if (selectAll) {
    selectAll.addEventListener('change', function () {
      checks.forEach(function (check) { check.checked = selectAll.checked; });
    });
  }

  checks.forEach(function (check) {
    check.addEventListener('change', function () {
      if (!selectAll) return;
      selectAll.checked = checks.length > 0 && checks.every(function (item) { return item.checked; });
      selectAll.indeterminate = !selectAll.checked && checks.some(function (item) { return item.checked; });
    });
  });

  document.querySelectorAll('.tng-queue-card > a').forEach(function (link) {
    link.addEventListener('click', function (event) {
      if (event.target && event.target.matches('input, button, select')) event.preventDefault();
    });
  });
});
