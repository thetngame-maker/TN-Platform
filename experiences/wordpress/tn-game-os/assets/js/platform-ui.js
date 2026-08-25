(() => {
  'use strict';

  const normalizePath = (value) => {
    const path = value || '/';
    return path.length > 1 ? path.replace(/\/+$/, '') : path;
  };

  const currentPath = normalizePath(window.location.pathname);
  const links = document.querySelectorAll('.tng-app-nav__item');

  links.forEach((link) => {
    try {
      const linkPath = normalizePath(new URL(link.href, window.location.origin).pathname);
      const isHome = linkPath === '/';
      const isActive = isHome ? currentPath === '/' : currentPath === linkPath || currentPath.startsWith(`${linkPath}/`);

      if (isActive) {
        link.classList.add('is-active');
        link.setAttribute('aria-current', 'page');
      }
    } catch (error) {
      // Ignore malformed or third-party navigation URLs.
    }
  });
})();
