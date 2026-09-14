/* Small router: retain the authenticated WordPress shell; load only REST-backed views. */
window.ASCLANavigation = ({ root, pages, prepare, load, error, beforeNavigate }) => {
  const routes = Object.entries(pages).map(([key, page]) => ({ key, page, url: new URL(page.url, location.href) }));
  let sequence = 0;
  let activeUrl = location.href;
  const path = url => { let value = url.pathname; while (value.length > 1 && value.endsWith('/')) { value = value.slice(0, -1); } return value; };
  function matches(value) {
    const url = new URL(value, location.href);
    if (url.origin !== location.origin) return null;
    return routes.find(route => path(route.url) === path(url) && [...route.url.searchParams].every(([k, v]) => url.searchParams.get(k) === v)) || null;
  }
  function activate(route) {
    for (const anchor of root.querySelectorAll('.nav-link, .header-profile')) {
      const current = matches(anchor.href)?.key === route.key;
      anchor.classList.toggle('active', current);
      if (current) anchor.setAttribute('aria-current', 'page'); else anchor.removeAttribute('aria-current');
    }
    const crumb = root.querySelector('.breadcrumb');
    if (crumb) crumb.textContent = 'ASCLA › ' + route.page.label;
    document.title = route.page.label + ' · ASCLA';
  }
  async function navigate(value, { pop = false, state = null } = {}) {
    const route = matches(value); if (!route) return false;
    const url = new URL(value, location.href);
    if (beforeNavigate && !beforeNavigate({ url: url.href, pop })) {
      if (pop && location.href !== activeUrl) {
        history.pushState({ ascla: true, scroll: scrollY }, '', activeUrl);
        const activeRoute = matches(activeUrl); if (activeRoute) activate(activeRoute);
      }
      return false;
    }
    const current = ++sequence;
    if (!pop) {
      history.replaceState({ ...history.state, ascla: true, scroll: scrollY }, '', location.href);
      if (url.href !== location.href) history.pushState({ ascla: true, scroll: 0 }, '', url.href);
    }
    activeUrl = url.href;
    activate(route); prepare(route.key); window.scrollTo(0, 0);
    try {
      await load();
      if (current !== sequence) return true;
      if (pop) window.scrollTo(0, state?.scroll || 0);
      const title = root.querySelector('#page-content h1');
      if (title && !root.querySelector('.modal')) { title.tabIndex = -1; title.focus({ preventScroll: true }); }
    } catch (error_) { if (current === sequence) error(error_); }
    return true;
  }
  root.addEventListener('click', event => {
    const a = event.target.closest('a[href]');
    if (!a || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    if (a.hasAttribute('download') || a.dataset.native !== undefined || (a.target && a.target !== '_self') || a.getAttribute('href').startsWith('#')) return;
    if (!matches(a.href)) return;
    event.preventDefault(); void navigate(a.href);
  });
  window.addEventListener('popstate', event => {
    if (matches(location.href)) void navigate(location.href, { pop: true, state: event.state });
    else location.reload();
  });
  history.scrollRestoration = 'manual';
  history.replaceState({ ...history.state, ascla: true, scroll: scrollY }, '', location.href);
  const initial = matches(location.href); if (initial) activate(initial);
  return { matches, navigate };
};
