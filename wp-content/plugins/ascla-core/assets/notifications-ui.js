/* Contextual activity inbox. All strings are escaped by the host application. */
window.ASCLANotifications = ({ E, I, btn }) => {
  const instant = (value) => {
    const date = new Date(String(value || '').replace(' ', 'T') + 'Z');
    return Number.isNaN(date.getTime()) ? new Date(0) : date;
  };
  const relative = (value) => {
    const minutes = Math.max(0, Math.floor((Date.now() - instant(value)) / 60000));
    if (minutes < 1) return 'Ahora';
    let amount = Math.floor(minutes / 1440), unit = 'day';
    if (minutes < 60) { amount = minutes; unit = 'minute'; }
    else if (minutes < 1440) { amount = Math.floor(minutes / 60); unit = 'hour'; }
    return new Intl.RelativeTimeFormat('es', { numeric: 'auto' }).format(-amount, unit);
  };
  const group = (value) => {
    const day = instant(value).toDateString(), today = new Date(), yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);
    if (day === today.toDateString()) return 'Hoy';
    return day === yesterday.toDateString() ? 'Ayer' : 'Anteriores';
  };
  function card(n) {
    return `<article class="activity-card ${n.read_at ? 'is-read' : 'is-unread'}" data-notification="${n.id}">
      <a class="activity-link" href="${E(n.url)}" data-action="notification-open" data-id="${n.id}">
        <span class="activity-icon activity-${E(n.icon)}">${I(n.icon)}</span>
        <span class="activity-copy"><span class="activity-meta"><span>${E(n.category)}</span><time datetime="${E(instant(n.created_at).toISOString())}" title="${E(instant(n.created_at).toLocaleString('es-PE'))}">${E(relative(n.created_at))}</time>${n.read_at ? '' : '<span class="activity-new">Nueva</span>'}</span>
        <strong>${E(n.title)}</strong><span class="activity-description">${E(n.description)}</span>
        <span class="activity-destination">${E(n.action_label)} ${I('arrow')}</span></span>
      </a>
      ${n.read_at ? '' : btn(I('check'), 'notification-read', `data-id="${n.id}" aria-label="Marcar como leída: ${E(n.title)}" title="Marcar como leída"`, 'activity-read ghost')}
    </article>`;
  }
  function panel(feed, filter) {
    let previous = '';
    const cards = feed.items.map(n => {
      const label = group(n.created_at), heading = label === previous ? '' : `<h3 class="activity-group">${label}</h3>`;
      previous = label; return heading + card(n);
    }).join('');
    const pendingLabel = feed.unread_total === 1 ? 'novedad pendiente' : 'novedades pendientes';
    const pending = feed.unread_total ? `${feed.unread_total} ${pendingLabel}` : 'No tienes avisos pendientes';
    const filters = ['all', 'unread'].map(f => btn(f === 'all' ? 'Todas' : `Sin leer (${feed.unread_total})`, 'notification-filter', `data-filter="${f}" aria-pressed="${filter === f}"`, filter === f ? 'selected' : '')).join('');
    const empty = `<div class="activity-empty">${I('check')}<h3>${filter === 'unread' ? 'Estás al día' : 'Tu comunidad empieza aquí'}</h3><p>${filter === 'unread' ? 'Ya revisaste todos tus avisos. Las nuevas novedades aparecerán aquí.' : 'Aquí recibirás mensajes, invitaciones y respuestas del asistente.'}</p></div>`;
    const previousButton = btn('Anterior', 'notification-page', `data-page="${feed.page - 1}" ${feed.page === 1 ? 'disabled' : ''}`, 'ghost small');
    const nextButton = btn('Siguiente', 'notification-page', `data-page="${feed.page + 1}" ${feed.page === feed.pages ? 'disabled' : ''}`, 'ghost small');
    return `<div class="activity-inbox"><div class="activity-intro"><span class="activity-emblem">${I('bell')}</span><div><p class="eyebrow">TU COMUNIDAD, AL DÍA</p><p><strong>${E(pending)}</strong><br><span>Conversaciones, encuentros y conocimiento en un solo lugar.</span></p></div></div>
      <div class="activity-toolbar"><div class="activity-filters" role="group" aria-label="Filtrar notificaciones">${filters}</div>${btn(I('check') + ' Marcar todas como leídas', 'notifications-read-all', feed.unread_total ? '' : 'disabled', 'ghost small')}</div>
      <div class="activity-list">${cards || empty}</div>
      <div class="activity-footer"><span>${feed.total} ${feed.total === 1 ? 'aviso' : 'avisos'} · Página ${feed.page} de ${feed.pages}</span><div>${previousButton}${nextButton}</div></div></div>`;
  }
  return { panel };
};
