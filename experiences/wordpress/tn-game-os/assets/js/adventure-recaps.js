(() => {
  const status = document.querySelector('[data-tng-share-status]');
  const announce = (message) => {
    if (!status) return;
    status.textContent = message;
    status.classList.add('is-visible');
    window.setTimeout(() => status.classList.remove('is-visible'), 2400);
  };

  document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-tng-share-recap]');
    if (!button) return;
    const text = button.dataset.shareText || 'I completed a Tennessee adventure in The TN Game.';
    try {
      if (navigator.share) {
        await navigator.share({title:'My TN Game Adventure', text});
        announce('Recap shared.');
        return;
      }
      await navigator.clipboard.writeText(text);
      announce('Recap copied to your clipboard.');
    } catch (error) {
      if (error && error.name === 'AbortError') return;
      announce('Sharing is unavailable on this device.');
    }
  });
})();
