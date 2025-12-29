// Ready Set Shows — small UI helpers (nav drawer, accordions)

(function () {
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

  // Mobile menu panel
  const drawer = qs('#mobileDrawer');
  const toggleButtons = qsa('[data-drawer-toggle]');
  const closeButtons = qsa('[data-drawer-close]');

  function syncToggleUI(isOpen) {
    toggleButtons.forEach((btn) => {
      const ico = qs('.nav-ico', btn);
      const txt = qs('.nav-text', btn);
      if (ico) ico.textContent = isOpen ? '✕' : '☰';
      if (txt) txt.textContent = isOpen ? 'Close' : 'Menu';
      btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
  }

  function openDrawer() {
    if (!drawer) return;
    drawer.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    document.body.classList.add('drawer-open');
    syncToggleUI(true);
  }

  function closeDrawer() {
    if (!drawer) return;
    drawer.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('drawer-open');
    syncToggleUI(false);
  }

  function toggleDrawer() {
    if (!drawer) return;
    if (drawer.classList.contains('is-open')) closeDrawer();
    else openDrawer();
  }

  toggleButtons.forEach((b) => b.addEventListener('click', toggleDrawer));
  closeButtons.forEach((b) => b.addEventListener('click', closeDrawer));

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeDrawer();
  });

  // Close when a menu link is clicked
  if (drawer) {
    drawer.addEventListener('click', (e) => {
      const link = e.target.closest('a');
      if (link && !link.classList.contains('disabled')) closeDrawer();
    });
  }

  // Mobile accordions
  qsa('[data-acc]').forEach((acc) => {
    const btn = qs('[data-acc-btn]', acc) || qs('.acc-btn', acc);
    if (!btn) return;
    btn.addEventListener('click', () => {
      // toggle only this accordion
      acc.classList.toggle('open');
    });
  });
})();
