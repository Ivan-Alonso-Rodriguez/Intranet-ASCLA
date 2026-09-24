/* ASCLA near-real-time notification toasts. Rendering only; data remains in the notification center. */
globalThis.ASCLALiveToasts = ({ root, E, I, T = (text) => text, onOpen, shouldSuppress, timeout = 7000, max = 3 }) => {
  const timers = new Map();

  const container = () => {
    let node = root.querySelector('.live-toast-stack');
    if (node) return node;
    node = document.createElement('div');
    node.className = 'live-toast-stack';
    node.setAttribute('aria-live', 'polite');
    node.setAttribute('aria-relevant', 'additions');
    node.setAttribute('aria-label', T('Novedades de ASCLA'));
    root.append(node);
    return node;
  };

  const dismiss = (id) => {
    const key = String(id);
    const node = root.querySelector(`.live-toast[data-live-notification="${CSS.escape(key)}"]`);
    if (node) {
      node.classList.add('is-leaving');
      setTimeout(() => node.remove(), 160);
    }
    if (timers.has(key)) clearTimeout(timers.get(key));
    timers.delete(key);
  };

  const enforceLimit = (stack) => {
    while (stack.querySelectorAll('.live-toast').length >= max) {
      const oldest = stack.querySelector('.live-toast');
      if (!oldest) break;
      dismiss(oldest.dataset.liveNotification || '');
      oldest.remove();
    }
  };

  const show = (notice) => {
    if (!notice?.id || shouldSuppress?.(notice)) return false;
    const key = String(notice.id);
    if (root.querySelector(`.live-toast[data-live-notification="${CSS.escape(key)}"]`)) return false;
    const stack = container();
    enforceLimit(stack);
    const article = document.createElement('article');
    article.className = `live-toast live-toast-${E(notice.icon || 'bell')}`;
    article.dataset.liveNotification = key;
    article.innerHTML = `<span class="live-toast-icon">${I(notice.icon || 'bell')}</span>
      <div class="live-toast-copy"><span class="live-toast-category">${E(notice.category || 'ASCLA')}</span><strong>${E(notice.title || T('Nueva notificación'))}</strong><p>${E(notice.description || '')}</p><button type="button" class="live-toast-open">${E(notice.action_label || T('Ver'))} ${I('arrow')}</button></div>
      <button type="button" class="live-toast-close" aria-label="${E(T('Cerrar notificación'))}" title="${E(T('Cerrar'))}">${I('close')}</button>`;
    article.querySelector('.live-toast-close')?.addEventListener('click', () => dismiss(key));
    article.querySelector('.live-toast-open')?.addEventListener('click', async () => {
      dismiss(key);
      await onOpen?.(notice);
    });
    stack.append(article);
    requestAnimationFrame(() => article.classList.add('is-visible'));
    timers.set(key, setTimeout(() => dismiss(key), Math.max(5000, Math.min(8000, Number(timeout) || 7000))));
    return true;
  };

  const showMany = (items = []) => {
    for (const notice of items) show(notice);
  };

  const clear = () => {
    timers.forEach(timer => clearTimeout(timer));
    timers.clear();
    root.querySelector('.live-toast-stack')?.remove();
  };

  return { show, showMany, dismiss, clear };
};
