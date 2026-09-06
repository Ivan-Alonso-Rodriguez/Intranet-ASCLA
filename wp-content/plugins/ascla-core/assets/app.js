/* ASCLA frontend. WordPress owns authentication and authorization; this UI never grants permissions. */
(() => {
  "use strict";
  const root = document.getElementById("ascla-root");
  if (!root || !window.ASCLA) return;
  const C = window.ASCLA;
  const S = {
    boot: null,
    page: C.page,
    filter: {},
    list: null,
    conversation: 0,
    conversations: [],
    calendar: new Date(),
    adminTab: "moderacion",
    poll: null,
  };
  const icons = {
    home: "M3 10 12 3l9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z",
    users:
      "M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75",
    calendar:
      "M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z",
    hub: "M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8Z",
    gallery:
      "M4 3h16a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1ZM3 16l5-5 4 4 4-5 5 6M8 7h.01",
    book: "M12 7v14M3 3h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5v17h-5a4 4 0 0 0-4 1 4 4 0 0 0-4-1H3Z",
    spark:
      "m12 3 2.4 6.6L21 12l-6.6 2.4L12 21l-2.4-6.6L3 12l6.6-2.4ZM20 2v4M18 4h4",
    ally: "m12 3 3 5 6 1-4 5 1 7-6-3-6 3 1-7-4-5 6-1Z",
    mail: "M3 5h18v14H3ZM3 5l9 7 9-7",
    contact: "M22 2 9 15M22 2l-7 20-6-7-7-6Z",
    search: "M21 21l-5-5M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14",
    bell: "M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4",
    arrow: "M5 12h14m-6-6 6 6-6 6",
    chevron: "m9 5 7 7-7 7",
    plus: "M12 5v14M5 12h14",
    close: "m6 6 12 12M6 18 18 6",
    pin: "M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0ZM12 7a3 3 0 1 0 0 6 3 3 0 0 0 0-6",
    clock: "M12 8v4l3 3M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20",
    heart:
      "M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z",
    logout: "M9 21H4V3h5M15 17l5-5-5-5M20 12H9",
    shield: "m12 2 9 4v6c0 6-9 10-9 10S3 18 3 12V6ZM8 12l3 3 5-6",
    menu: "M3 6h18M3 12h18M3 18h18",
    download: "M12 3v12m-5-5 5 5 5-5M5 17v4h14v-4",
    play: "m8 5 12 7-12 7Z",
    settings:
      "M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M5 19l2-2M17 7l2-2",
    check: "m5 12 4 4L19 6",
    edit: "m15 4 5 5M4 20l4-1L21 6l-4-4L4 15Z",
  };
  const I = (name) =>
    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${icons[name] || icons.hub}"/></svg>`;
  const E = (v) =>
    String(v ?? "").replace(
      /[&<>"']/g,
      (c) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#39;",
        })[c],
    );
  const safeURL = (value) => {
    try {
      const u = new URL(value, location.href);
      return ["http:", "https:"].includes(u.protocol) ? u.href : "#";
    } catch {
      return "#";
    }
  };
  const date = (value, opts = { day: "numeric", month: "short" }) =>
    value
      ? new Date(
          /(?:Z|[+-]\d\d:\d\d)$/.test(value)
            ? value
            : value.replace(" ", "T") + "Z",
        ).toLocaleDateString("es-PE", opts)
      : "Por confirmar";
  const time = (value) =>
    value
      ? new Date(value).toLocaleTimeString("es-PE", {
          hour: "2-digit",
          minute: "2-digit",
        })
      : "";
  const status = (value) =>
    `<span class="status ${E(value)}">${E({ publish: "Publicado", draft: "Borrador", pending: "Pendiente de revisión", private: "Solicitud privada", ascla_rejected: "Rechazado", ascla_hidden: "Oculto", completed: "Completado", processing: "Procesando", error: "Error", accepted: "Inscrito", invited: "Invitado", cancelled: "Cancelado", declined: "Rechazado" }[value] || value)}</span>`;
  const initials = (name) =>
    String(name || "AS")
      .split(" ")
      .slice(0, 2)
      .map((x) => x[0])
      .join("");
  const avatar = (p, size = "") =>
    `<span class="avatar ${size}">${p.photo_url ? `<img src="${E(safeURL(p.photo_url))}" alt="${E(p.name)}">` : E(initials(p.name))}</span>`;
  const btn = (label, action, extra = "", kind = "") =>
    `<button type="button" class="btn ${kind}" data-action="${action}" ${extra}>${label}</button>`;
  const link = (page, label, kind = "") =>
    `<a class="btn ${kind}" href="${E(C.pages[page]?.url || "#")}">${label}</a>`;
  const empty = (title, text = "") =>
    `<div class="empty">${I("users")}<strong>${E(title)}</strong><p>${E(text)}</p></div>`;
  const UI = window.ASCLAContent({ escape: E, icon: I, config: C });
  function apiURL(path) {
    const url = new URL(C.api, location.href);
    const [route, query = ""] = path.split("?", 2);
    if (url.searchParams.has("rest_route")) {
      url.searchParams.set("rest_route", url.searchParams.get("rest_route") + route);
    } else {
      url.pathname += route;
    }
    new URLSearchParams(query).forEach((value, key) => url.searchParams.set(key, value));
    return url.href;
  }
  async function api(path, body, method) {
    const options = {
      method: method || (body ? "POST" : "GET"),
      credentials: "same-origin",
      headers: { "X-WP-Nonce": C.nonce },
    };
    if (body instanceof FormData) options.body = body;
    else if (body) {
      options.headers["Content-Type"] = "application/json";
      options.body = JSON.stringify(body);
    }
    const response = await fetch(apiURL(path), options);
    const data = await response.json();
    if (!response.ok)
      throw new Error(data.message || "No se pudo completar la solicitud.");
    return data;
  }
  function toast(message) {
    document.querySelector(".toast")?.remove();
    const t = document.createElement("div");
    t.className = "toast";
    t.role = "status";
    t.textContent = message;
    root.append(t);
    setTimeout(() => t.remove(), 5500);
  }
  function modal(title, body, wide = false) {
    closeModal();
    const div = document.createElement("div");
    div.className = "modal-backdrop";
    div.innerHTML = `<section role="dialog" aria-modal="true" aria-label="${E(title)}" class="modal ${wide ? "wide" : ""}"><div class="modal-top"><h2>${E(title)}</h2>${btn(I("close"), "close", 'aria-label="Cerrar"', "ghost")}</div><div class="modal-content">${body}</div></section>`;
    root.append(div);
    S.focus = document.activeElement;
    div.querySelector("button,input")?.focus();
  }
  function closeModal() {
    document.querySelector(".modal-backdrop")?.remove();
    S.focus?.focus();
  }
  function field(name, label, value = "", type = "text", extra = "") {
    return `<div class="field"><label for="f-${E(name)}">${E(label)}</label>${type === "textarea" ? `<textarea id="f-${E(name)}" name="${E(name)}" ${extra}>${E(value)}</textarea>` : `<input id="f-${E(name)}" name="${E(name)}" type="${type}" value="${E(value)}" ${extra}>`}</div>`;
  }
  const check = (name, label, value) =>
    `<label class="check"><input type="checkbox" name="${E(name)}" ${value ? "checked" : ""}> <span>${E(label)}</span></label>`;
  const select = (name, label, values, value = "") =>
    `<div class="field"><label for="f-${E(name)}">${E(label)}</label><select name="${E(name)}" id="f-${E(name)}">${values
      .map((v) => {
        const pair = Array.isArray(v) ? v : [v, v];
        return `<option value="${E(pair[0])}" ${String(value) === String(pair[0]) ? "selected" : ""}>${E(pair[1])}</option>`;
      })
      .join("")}</select></div>`;
  function formData(form) {
    return Object.fromEntries(new FormData(form));
  }
  const pageIcon = {
    intranet: "home",
    perfil: "users",
    eventos: "calendar",
    hub: "hub",
    galeria: "gallery",
    foros: "hub",
    directorio: "users",
    "centro-conocimiento": "book",
    asistente: "spark",
    aliados: "ally",
    mensajeria: "mail",
    contacto: "contact",
  };
  const navOrder = [
    "intranet",
    "eventos",
    "hub",
    "galeria",
    "foros",
    "directorio",
    "centro-conocimiento",
    "asistente",
    "aliados",
    "mensajeria",
    "contacto",
  ];
  function shell() {
    const p = S.boot.me;
    root.innerHTML = `<aside class="ascla-sidebar"><a class="brand" href="${E(C.pages.intranet.url)}" aria-label="ASCLA inicio"><img src="${E(C.logo)}" alt="ASCLA"></a><div class="brand-sub">COMUNIDAD DE ASOCIADOS</div><nav aria-label="Navegación principal">${navOrder.map((k) => `<a class="nav-link ${S.page === k ? "active" : ""}" href="${E(C.pages[k].url)}">${I(pageIcon[k])}<span>${E(C.pages[k].label)}</span></a>`).join("")}</nav><div class="nav-bottom">${S.boot.moderator ? `<a class="nav-link" href="${E(C.adminUrl)}">${I("settings")}Administración</a>` : ""}<a class="nav-link" href="${E(C.logout)}">${I("logout")}Cerrar sesión</a></div></aside><div class="ascla-main"><header class="ascla-header">${btn(I("menu"), "menu", 'aria-label="Abrir navegación"', "icon-button mobile-menu")}<form class="header-search" data-form="global-search">${I("search")}<input name="q" aria-label="Buscar en ASCLA" placeholder="Buscar en tu comunidad…" autocomplete="off"></form><div class="header-right">${S.boot.demo ? '<span class="demo-badge">DEMO MODE</span>' : ""}${btn(`${I("bell")}<i class="unread-dot" hidden></i>`, "notifications", 'aria-label="Notificaciones"', "icon-button")}<a class="header-profile" href="${E(C.pages.perfil.url)}">${avatar(p)}<span><strong>${E(p.name)}</strong><small class="muted">${E(p.member_type || "Comunidad ASCLA")}</small></span>${I("chevron")}</a></div></header><main id="main" class="page-wrap"><div class="breadcrumb">ASCLA ${I("chevron")} ${E(C.pages[S.page]?.label || "Administración")}</div><div id="page-content"></div><div class="demo-footer">© ${new Date().getFullYear()} ASCLA · Conectamos conocimiento, fortalecemos la gobernanza.${S.boot.demo ? " · Datos ficticios de demostración." : ""}</div></main></div>`;
    refreshNotifications();
  }
  function heading(title, subtitle, action = "") {
    return `<div class="page-heading"><div><h1>${E(title)}</h1><p>${E(subtitle)}</p></div>${action}</div>`;
  }
  const content = () => document.getElementById("page-content");
  function memberCard(p) {
    return `<article class="card member-card">${avatar(p, "lg")}<h3>${E(p.name)}</h3><div class="role">${E(p.position || "Miembro ASCLA")}</div><div class="company">${E(p.company || "Comunidad profesional")}</div><span class="country">${I("pin")}${E(p.country || "América Latina")}</span>${
      p.affinity
        ? `<div class="match-pill">${I("spark")}${p.affinity.score}% de afinidad</div>`
        : `<div class="tag-row">${(p.terms?.interests || [])
            .slice(0, 2)
            .map((t) => `<span class="tag">${E(t)}</span>`)
            .join("")}</div>`
    }${btn("Ver perfil " + I("arrow"), "member", `data-id="${p.id}"`, "small")}</article>`;
  }
  function resourceCard(p, i = 0) {
    const cover = p.meta.thumbnail_url
      ? `<a href="${E(p.url)}" class="resource-video-cover"><img class="resource-thumbnail" src="${E(p.meta.thumbnail_url)}" alt="Miniatura de ${E(p.title)}" loading="lazy"><span>${I("play")} ${E(UI.duration(p.meta.duration_seconds))}</span></a>`
      : `<a href="${E(p.url)}" class="resource-cover v${i % 3}"><div class="cover-label">ASCLA · CONOCIMIENTO</div><strong>${E(p.title.split(":")[0])}</strong><span class="cover-icon">${I(p.meta.resource_type === "Video" ? "play" : "book")}</span></a>`;
    return `<article class="card resource-card">${cover}<div class="resource-content"><span class="tag" style="align-self:flex-start;margin-bottom:10px">${E(p.meta.resource_type || "Artículo")}</span><h3><a href="${E(p.url)}">${E(p.title)}</a></h3><p>${E((p.meta.summary || p.body).slice(0, 115))}${p.body.length > 115 ? "…" : ""}</p><div class="resource-footer"><span>${date(p.date)}</span>${btn("Explorar " + I("arrow"), "item", `data-id="${p.id}"`, "ghost")}</div></div></article>`;
  }
  function eventMini(p) {
    const d = new Date(p.meta.start);
    return `<div class="event-mini"><div class="date-box"><small>${d.toLocaleDateString("es", { month: "short" })}</small><strong>${d.getDate()}</strong></div><div><h3>${E(p.title)}</h3><p>${E(p.meta.modality || "Virtual")} · ${time(p.meta.start)}</p><a href="${E(p.url)}">Ver evento ${I("arrow")}</a></div></div>`;
  }
  function calendar(events) {
    const d = S.calendar;
    const first = (new Date(d.getFullYear(), d.getMonth(), 1).getDay() + 6) % 7;
    const days = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
    const today = new Date();
    const eventDays = Array.from({ length: days }, (_, index) => index + 1).filter(day => {
      const from = new Date(d.getFullYear(), d.getMonth(), day), to = new Date(d.getFullYear(), d.getMonth(), day + 1);
      return events.some(p => new Date(p.meta.start) < to && new Date(p.meta.end) > from);
    });
    return `<div class="calendar-head"><strong>${E(d.toLocaleDateString("es-PE", { month: "long", year: "numeric" }))}</strong><span>${btn("‹", "calendar-prev", 'aria-label="Mes anterior"', "ghost")}${btn("›", "calendar-next", 'aria-label="Mes siguiente"', "ghost")}</span></div><div class="calendar">${["L", "M", "M", "J", "V", "S", "D"].map((x) => `<span class="weekday">${x}</span>`).join("")}${"<span></span>".repeat(first)}${Array.from({ length: days }, (_, i) => `<span class="${today.getFullYear() === d.getFullYear() && today.getMonth() === d.getMonth() && today.getDate() === i + 1 ? "today" : eventDays.includes(i + 1) ? "event-day" : ""}">${eventDays.includes(i + 1) ? `<button class="calendar-day" data-action="calendar-day" data-day="${i + 1}" aria-label="Ver eventos del día ${i + 1}">${i + 1}</button>` : i + 1}</span>`).join("")}</div><div class="calendar-legend"><i></i> Eventos de la comunidad</div>`;
  }
  async function dashboard() {
    const [people, events, resources, hub, directory, notes, monthEvents, recentResources] =
      await Promise.all([
        api("matching"),
        api("content/event?past=0&status=publish"),
        api("content/resource?recommended=1"),
        api("content/hub"),
        api("profiles"),
        api("notifications"),
        calendarEvents(),
        api("content/resource?status=publish"),
      ]);
    S.events = monthEvents;
    const upcoming = events.items
      .filter((p) => new Date(p.meta.end) > new Date())
      .sort((a, b) => new Date(a.meta.start) - new Date(b.meta.start));
    const p = S.boot.me;
    content().innerHTML = `<section class="hero"><div class="hero-copy"><div class="eyebrow">TU COMUNIDAD, MÁS CERCA</div><h1>Hola ${E(p.first_name || p.name.split(" ")[0])},<br>sigamos conectando ideas.</h1><p>Un espacio para compartir experiencias, fortalecer tu red y construir el futuro del gobierno corporativo.</p>${link("directorio", "Explorar la comunidad " + I("arrow"), "white small")}</div><svg class="hero-art" viewBox="0 0 300 260" fill="none" aria-hidden="true"><g stroke="#7ac0e5" stroke-width=".7"><circle cx="160" cy="130" r="105"/><circle cx="160" cy="130" r="77"/><ellipse cx="160" cy="130" rx="45" ry="105"/><path d="M55 130h210M80 64l170 134M80 196 247 65M68 80l191 89M70 173l180-89"/></g><g fill="#8dc4e3" stroke="#c7edff" stroke-width="4"><circle cx="90" cy="76" r="9"/><circle cx="236" cy="70" r="7"/><circle cx="160" cy="130" r="11"/><circle cx="87" cy="195" r="7"/><circle cx="244" cy="193" r="10"/><circle cx="181" cy="27" r="5"/></g></svg><div class="hero-tag"><i></i> Una red que crece contigo</div></section><div class="stat-grid">${[
      [directory.total, "Asociados en la comunidad", "users"],
      [events.total, "Próximos encuentros", "calendar"],
      [recentResources.total, "Recursos para aprender", "book"],
      [notes.filter((n) => !n.read_at).length, "Nuevas notificaciones", "bell"],
    ]
      .map(
        ([num, label, icon]) =>
          `<div class="stat"><span class="stat-icon">${I(icon)}</span><div><strong>${num}</strong><small>${label}</small></div></div>`,
      )
      .join(
        "",
      )}</div><div class="dashboard-columns"><div><div class="section-top"><h2>Conexiones que suman</h2>${link("directorio", "Ver directorio " + I("arrow"), "ghost")}</div><div class="cards">${people.slice(0, 3).map(memberCard).join("") || empty("Activa tu networking", "Completa tus intereses en Perfil para descubrir conexiones.")}</div><div class="section-gap"><div class="section-top"><h2>Conocimiento para tu día a día</h2>${link("centro-conocimiento", "Ver todo " + I("arrow"), "ghost")}</div><div class="cards two">${resources.items.slice(0, 2).map(resourceCard).join("") || empty("Completa tus intereses para ver recomendaciones")}</div><h3 class="section-gap">Publicados recientemente</h3><div class="cards two">${recentResources.items.slice(0, 2).map(resourceCard).join("") || empty("Tu biblioteca está por comenzar")}</div></div><div class="section-gap"><div class="section-top"><h2>La conversación en nuestra comunidad</h2>${link("hub", "Ir al Hub " + I("arrow"), "ghost")}</div>${hub.items.slice(0, 1).map(feedCard).join("") || empty("Comparte la primera idea")}</div></div><aside class="dashboard-aside"><div><div class="section-top"><h2>Tu agenda ASCLA</h2>${I("calendar")}</div><div class="card calendar-card"><div id="calendar-body">${calendar(monthEvents)}</div><div style="border-top:1px solid var(--line);margin-top:16px;padding-top:8px">${upcoming.slice(0, 2).map(eventMini).join("") || '<p class="muted">Sin próximos eventos.</p>'}</div></div></div><div class="notice-card">${I("spark")}<h3>El conocimiento, a una pregunta</h3><p>Encuentra respuestas en los recursos de nuestra comunidad con el Asistente ASCLA.</p>${link("asistente", "Hacer una pregunta " + I("arrow"), "ghost small")}</div><div class="notice-card" style="background:white;border-color:var(--line)">${I("shield")}<h3>Un espacio de confianza</h3><p>Compartimos conocimiento con respeto, confidencialidad y bajo la Regla de Chatham House.</p>${btn("Normas de la comunidad " + I("arrow"), "rules", "", "ghost small")}</div></aside></div>`;
  }
  function feedCard(p) {
    return `<article class="card feed-card"><div class="feed-top">${avatar({ name: p.author.name })}<div><strong>${E(p.author.name)}</strong><small>${date(p.date)} · Comunidad ASCLA</small></div><span style="margin-left:auto">${p.status !== "publish" ? status(p.status) : ""}</span></div><h3><a href="${E(p.url)}">${E(p.title)}</a></h3><p>${E(p.body.slice(0, 450))}${p.body.length > 450 ? "…" : ""}</p>${UI.attachments(p, true)}${p.tags.length ? `<div class="tag-row">${p.tags.map((t) => `<span class="tag">${E(t.name)}</span>`).join("")}</div>` : ""}<div class="feed-actions">${btn(I("heart") + " " + p.reactions, "like", `data-id="${p.id}" data-active="${!p.liked}" aria-label="Me gusta"`)}${btn(I("hub") + " " + p.comments + " comentarios", "item", `data-id="${p.id}"`)}${btn(I("arrow") + " Ver conversación", "item", `data-id="${p.id}"`)}</div></article>`;
  }
  function pager(list) {
    return list.pages > 1
      ? `<div class="pagination">${btn("Anterior", "page", `data-page="${list.page - 1}" ${list.page <= 1 ? "disabled" : ""}`, "small")}<span>Página ${list.page} de ${list.pages}</span>${btn("Siguiente", "page", `data-page="${list.page + 1}" ${list.page >= list.pages ? "disabled" : ""}`, "small")}</div>`
      : "";
  }
  async function directory() {
    const list = await api("profiles?" + new URLSearchParams(S.filter));
    S.list = list;
    content().innerHTML =
      heading(
        "Tu red profesional",
        "Conecta con quienes comparten tus retos, intereses y conocimientos.",
      ) +
      `<form class="filters" data-form="filters"><input aria-label="Buscar perfiles" name="q" placeholder="Nombre, cargo, empresa o experiencia…" value="${E(S.filter.q || "")}"><input aria-label="País" name="country" placeholder="País" value="${E(S.filter.country || "")}" style="max-width:180px;min-width:120px"><select name="industries" aria-label="Industria" style="max-width:200px"><option value="">Todas las industrias</option>${S.boot.catalogs.industry.map((t) => `<option value="${t.id}" ${String(S.filter.industries) === String(t.id) ? "selected" : ""}>${E(t.name)}</option>`).join("")}</select>${UI.termFilter("interests", "Interés", S.boot.catalogs.interest, S.filter)}${UI.termFilter("areas", "Área de conocimiento", S.boot.catalogs.area, S.filter)}<button class="btn primary">${I("search")} Buscar</button></form><div class="section-top"><span class="muted" style="font-size:12px">${list.total} perfiles en la comunidad</span>${link("perfil", "Editar mis intereses", "ghost")}</div><div class="cards directory">${list.items.map(memberCard).join("")}</div>${!list.items.length ? empty("No encontramos perfiles", "Prueba con otro nombre, país o interés.") : ""}${pager(list)}`;
  }
  async function member(id) {
    const p = await api("profiles/" + id);
    let match = null;
    try {
      match = await api("matching/" + id);
    } catch {}
    modal(
      p.name,
      `<div class="profile-summary">${avatar(p, "xl")}<div><h2>${E(p.position || "Miembro ASCLA")}</h2><p class="muted">${E(p.company || "")}</p><p class="muted">${E(p.country || "")} ${E(p.city || "")}</p>${match ? `<span class="match-pill">${I("spark")}${match.score}% de afinidad</span>` : ""}</div></div>${match ? `<div class="alert">${E(match.explanation)}</div>` : ""}<p class="detail-body">${E(p.bio || "Este miembro aún no ha añadido su biografía.")}</p>${p.experience ? `<h3>Experiencia profesional</h3><p class="detail-body">${E(p.experience)}</p>` : ""}${Object.entries(
        p.terms || {},
      )
        .filter(([, v]) => v.length)
        .map(
          ([k, v]) =>
            `<h3>${E(profileLabels[k] || k)}</h3><div class="tag-row">${v.map((t) => `<span class="tag">${E(t)}</span>`).join("")}</div>`,
        )
        .join("")}<div class="sources">${["linkedin", "twitter", "website"]
        .filter((k) => p[k])
        .map(
          (k) =>
            `<a href="${E(safeURL(p[k]))}" target="_blank" rel="noopener noreferrer">${E({ linkedin: "LinkedIn", twitter: "X / Twitter", website: "Sitio personal" }[k])} ↗</a>`,
        )
        .join(
          "",
        )}</div><div class="form-actions">${Number(id) !== S.boot.me.id ? `${btn("Mensaje sugerido", "intro", `data-id="${id}"`)}${match ? btn("Conectar", "connect", `data-id="${id}"`) : ""}${btn(I("mail") + " Enviar mensaje", "message-start", `data-id="${id}"`, "primary")}` : link("perfil", "Editar perfil", "primary")}</div>`,
    );
  }
  const profileLabels = {
    first_name: "Nombre",
    last_name: "Apellidos",
    position: "Cargo",
    company: "Empresa",
    country: "País",
    city: "Ciudad",
    member_type: "Tipo de asociado",
    bio: "Biografía",
    experience: "Experiencia profesional",
    linkedin: "LinkedIn",
    twitter: "X / Twitter",
    website: "Página personal",
    interests: "Temas de interés",
    areas: "Áreas de conocimiento",
    industries: "Industrias",
    goals: "Objetivos de networking",
    languages: "Idiomas",
    learn: "Temas que deseas aprender",
    help: "Temas en los que puedes ayudar",
    connect_topics: "Temas sobre los que quieres conectar",
  };
  const profileTax = {
    interests: "interest",
    areas: "area",
    industries: "industry",
    goals: "goal",
    languages: "language",
    learn: "area",
    help: "area",
    connect_topics: "interest",
  };
  async function profile() {
    const p = await api("profiles/" + S.boot.me.id);
    content().innerHTML =
      heading(
        "Mi perfil",
        "Tu experiencia es el punto de partida de nuevas conexiones.",
      ) +
      `<form class="card" data-form="profile"><div class="profile-summary">${avatar(p, "xl")}<div><h2>${E(p.name)}</h2><p class="muted">${E(p.email || "")}</p><label class="btn small" style="margin-top:10px">${I("edit")} Cambiar fotografía<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" hidden data-upload="photo"></label><input type="hidden" name="photo_id" value="${p.photo_id || 0}"><div id="photo-status" class="private-note">JPG, PNG o WebP. Máximo 3 MB.</div></div></div><div class="form-section">Información profesional</div><div class="form-grid">${["first_name", "last_name", "position", "company", "country", "city", "member_type", "linkedin", "twitter", "website"].map((k) => field(k, profileLabels[k], p[k] || "", k === "linkedin" || k === "twitter" || k === "website" ? "url" : "text", 'maxlength="200"')).join("")}<div class="full">${field("bio", "Biografía", p.bio || "", "textarea", 'maxlength="3000"')}${field("experience", "Experiencia profesional", p.experience || "", "textarea", 'maxlength="3000"')}</div></div><div class="form-section">Conocimiento e intereses</div>${Object.entries(
        profileTax,
      )
        .map(
          ([key, tax]) =>
            `<label style="font-size:12px;font-weight:600">${profileLabels[key]}</label><div class="multi-select">${S.boot.catalogs[tax].map((t) => `<label class="chip-check"><input type="checkbox" name="${key}" value="${t.id}" ${(p[key] || []).includes(t.id) ? "checked" : ""}>${E(t.name)}</label>`).join("")}</div>`,
        )
        .join(
          "",
        )}<div class="form-section">Privacidad y participación</div>${check("directory", "Mostrar mi perfil en el directorio de asociados", p.directory)}${check("networking", "Quiero recibir sugerencias de networking", p.networking)}${check("microevents", "Acepto participar en propuestas de microeventos", p.microevents)}<p class="private-note">Selecciona la información que deseas ocultar a otros asociados. Tus objetivos y preferencias de aprendizaje se usan de forma interna para networking.</p><div class="multi-select">${["company", "position", "city", "country", "bio", "experience", "linkedin", "twitter", "website", "interests", "areas", "industries", "photo_id"].map((k) => `<label class="chip-check"><input type="checkbox" name="hidden" value="${k}" ${(p.hidden || []).includes(k) ? "checked" : ""}>Ocultar ${E(profileLabels[k] || "fotografía")}</label>`).join("")}</div><div class="form-actions"><button class="btn primary">${I("check")} Guardar perfil</button></div></form><div class="card section-gap"><h3>Mi calendario</h3><p class="private-note">${S.boot.google_connected ? "Tu calendario Google está conectado." : "Integración Google Calendar no configurada para tu cuenta. Los enlaces e ICS siempre están disponibles."}</p><div class="admin-actions">${btn("Conectar Google Calendar", "google-connect", 'data-service="calendar"')}${S.boot.google_connected ? btn("Desconectar", "google-disconnect", 'data-service="calendar"') : ""}</div></div>`;
  }
  const typeByPage = {
    hub: "hub",
    foros: "topic",
    eventos: "event",
    "centro-conocimiento": "resource",
    galeria: "gallery",
    aliados: "ally",
    contacto: "contact",
  };
  const typeLabel = {
    hub: "publicación",
    topic: "tema",
    forum: "foro",
    event: "evento",
    resource: "recurso",
    gallery: "galería",
    ally: "aliado",
    contact: "solicitud",
  };
  async function allContent(type, filter = {}) {
    const first = await api("content/" + type + "?" + new URLSearchParams({ ...filter, per_page: 100 }));
    const items = [...first.items];
    for (let page = 2; page <= first.pages; page++) {
      const next = await api("content/" + type + "?" + new URLSearchParams({ ...filter, per_page: 100, page }));
      items.push(...next.items);
    }
    return items;
  }
  async function calendarEvents() {
    const d = new Date(S.calendar.getFullYear(), S.calendar.getMonth(), 1);
    const month = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
    return allContent("event", { month, tz_offset: d.getTimezoneOffset(), status: "publish" });
  }
  async function listing() {
    const type = typeByPage[S.page];
    const list = await api(
      "content/" +
        type +
        "?" +
        new URLSearchParams(
          type === "event" ? { past: 0, ...S.filter } : S.filter,
        ),
    );
    S.list = list;
    const canWrite =
      S.boot.moderator || ["hub", "topic", "gallery"].includes(type);
    const labels = {
      hub: [
        "Hub ASCLA",
        "Ideas, experiencias y conversaciones que nos acercan.",
      ],
      topic: [
        "Foros de la comunidad",
        "Comparte una pregunta y construyamos respuestas juntos.",
      ],
      event: [
        "Eventos y Capacitaciones",
        "Encuentros para aprender, conversar y ampliar tu perspectiva.",
      ],
      resource: [
        "Centro de Conocimiento",
        "La experiencia de nuestra comunidad, siempre a tu alcance.",
      ],
      gallery: ["Galería", "Los momentos que construyen nuestra comunidad."],
      ally: [
        "Nuestros aliados",
        "Colaboraciones que impulsan el buen gobierno corporativo.",
      ],
    };
    const authors = type === "resource" ? await api("resource-authors") : [];
    const forums = type === "topic" ? (await allContent("forum")).map(p => ({ id: p.id, name: p.title })) : [];
    const items = list.items;
    content().innerHTML =
      heading(
        ...labels[type],
        canWrite
          ? btn(
              I("plus") +
                " Nuev" +
                (type === "hub" || type === "gallery" ? "a " : "o ") +
                typeLabel[type],
              "editor",
              `data-type="${type}"`,
              "primary",
            )
          : "",
      ) +
      `<form class="filters" data-form="filters"><input name="q" aria-label="Buscar contenido" value="${E(S.filter.q || "")}" placeholder="${type === "resource" ? "Buscar por tema, autor o contenido…" : "Buscar en esta sección…"}">${type === "resource" ? `<select name="resource_type" aria-label="Tipo de recurso" style="max-width:180px"><option value="">Todos los tipos</option>${["Artículo", "Video", "Podcast", "Nota técnica", "Infografía", "Documento"].map((t) => `<option ${S.filter.resource_type === t ? "selected" : ""}>${t}</option>`).join("")}</select><input type="date" name="after" value="${E(S.filter.after || "")}" aria-label="Desde fecha" style="max-width:160px;min-width:100px">` : ""}${UI.filters(type, S.filter, S.boot.catalogs, authors, forums)}<button class="btn">${I("search")} Buscar</button></form><div class="tabs">${btn(type === "event" ? "Próximos eventos" : "Comunidad", "filter-all", "", "tab " + (!S.filter.mine && !S.filter.past ? "active" : ""))}${type === "event" ? btn("Eventos anteriores", "filter-past", "", "tab " + (S.filter.past ? "active" : "")) : ""}${canWrite ? btn("Mis publicaciones", "filter-mine", "", "tab " + (S.filter.mine ? "active" : "")) : ""}${type === "topic" && S.boot.moderator ? btn(I("plus") + " Crear foro", "editor", 'data-type="forum"', "tab") : ""}</div>${type === "resource" ? `<div class="cards">${items.map(resourceCard).join("")}</div>` : type === "event" ? `<div class="cards two">${items.map(eventCard).join("")}</div>` : type === "gallery" ? `<div class="cards">${items.map(galleryCard).join("")}</div>` : type === "ally" ? `<div class="cards">${items.map(allyCard).join("")}</div>` : items.map(feedCard).join("")}${!items.length ? empty("Aún no hay contenido aquí", S.filter.q ? "Prueba una búsqueda diferente." : "Comparte un aporte o vuelve pronto para ver novedades.") : ""}${pager(list)}`;
  }
  function eventCard(p) {
    return `<article class="card"><div class="section-top"><span class="tag">${I("calendar")} ${E(p.meta.modality || "Virtual")}</span>${p.status !== "publish" ? status(p.status) : ""}</div><h3>${E(p.title)}</h3><p class="detail-body" style="font-size:12px">${E(p.body.slice(0, 160))}</p><div class="detail-meta"><span>${I("calendar")} ${date(p.meta.start)}</span><span>${I("clock")} ${time(p.meta.start)}</span></div><div class="form-actions" style="justify-content:space-between">${p.meta.chatham ? '<span class="tag">Chatham House</span>' : "<span></span>"}${btn("Ver encuentro " + I("arrow"), "item", `data-id="${p.id}"`, "small primary")}</div></article>`;
  }
  function galleryCard(p) {
    return `<article class="card resource-card">${p.meta.media_ids?.length ? `<img src="${E(C.mediaUrl + p.meta.media_ids[0])}" alt="${E(p.title)}" style="height:190px;object-fit:cover;width:100%">` : `<div class="resource-cover v1"><div class="cover-label">ASCLA · ENCUENTROS</div><strong>${E(p.title)}</strong><span class="cover-icon">${I("gallery")}</span></div>`}<div class="resource-content"><h3>${E(p.title)}</h3><p>${E(p.body.slice(0, 120))}</p><div class="resource-footer"><span>${date(p.date)}</span>${btn("Ver galería", "item", `data-id="${p.id}"`, "ghost")}</div></div></article>`;
  }
  function allyCard(p) {
    return `<article class="card">${UI.images(p).length ? `<img class="ally-logo" src="${E(UI.images(p)[0].url)}" alt="Logo de ${E(p.title)}" loading="lazy">` : `<div class="stat-icon" style="margin-bottom:17px">${I("ally")}</div>`}<span class="tag">${E(p.meta.alliance_type || "Alianza")}</span><h3 style="margin-top:12px">${E(p.title)}</h3><p class="detail-body" style="font-size:12px">${E(p.body)}</p>${btn("Conoce más " + I("arrow"), "item", `data-id="${p.id}"`, "ghost")}</article>`;
  }
  async function item(id) {
    const p = await api("items/" + id);
    S.item = p;
    let extra = "";
    if (p.type === "event") {
      const d = await api("events/" + id);
      S.event = d;
      extra = `<div class="card" style="background:var(--bg);margin:20px 0"><div class="detail-meta"><span>${I("calendar")} ${date(p.meta.start, { weekday: "long", day: "numeric", month: "long", year: "numeric" })}</span><span>${I("clock")} ${time(p.meta.start)} – ${time(p.meta.end)}</span><span>${I("pin")} ${E(p.meta.location || "Por confirmar")}</span></div><p class="private-note">${d.attending} inscritos${p.meta.capacity ? " · " + p.meta.capacity + " cupos" : " · Sin límite de cupos"} · ${status(d.registered)}</p>${p.meta.agenda ? `<p class="detail-body">${E(p.meta.agenda).replace(/\\n/g, "<br>")}</p>` : ""}<div class="form-actions">${d.registered === "accepted" ? btn("Cancelar inscripción", "register", `data-id="${id}" data-status="cancelled"`) : btn("Registrarme", "register", `data-id="${id}" data-status="accepted"`, "primary")}${d.registered === "invited" ? btn("Rechazar invitación", "register", `data-id="${id}" data-status="declined"`) : ""}<a class="btn small" href="${E(d.google_url)}" target="_blank" rel="noopener noreferrer">Añadir a Google Calendar ↗</a>${btn(I("download") + " ICS", "ics", `data-id="${id}"`, "small")}${S.boot.google_connected ? btn("Guardar en Google conectado", "google-event", `data-id="${id}" data-operation="save"`, "small") + btn("Quitar de Google", "google-event", `data-id="${id}" data-operation="cancel"`, "small") : ""}</div>${d.participants ? `<details><summary class="private-note">Participantes (moderación)</summary>${d.participants.map((x) => `<p>${E(x.name)} · ${status(x.status)}</p>`).join("")}</details>` : ""}</div>`;
    }
    const canEdit = S.boot.moderator || p.author.id === S.boot.me.id;
    modal(
      p.title,
      `<div class="detail-meta"><span>${E(p.author.name)}</span><span>${date(p.date)}</span>${status(p.status)}${p.meta.demo ? '<span class="demo-badge">DATOS DEMO</span>' : ""}</div>${p.meta.chatham ? '<div class="alert chatham" style="margin-top:18px">' + I("shield") + " Regla de Chatham House: utiliza el conocimiento sin revelar identidades ni afiliaciones.</div>" : ""}${p.meta.generated ? `<div class="alert">Contenido generado · ${E(p.meta.ai_mode || p.meta.social_mode || "IA")} · ${p.meta.reviewed ? "Revisado" : "Requiere revisión de fuentes, anonimización y derechos."}</div>` : ""}<p class="detail-body">${E(p.body)}</p>${p.meta.video_id ? `<div class="video-wrap"><iframe loading="lazy" referrerpolicy="strict-origin-when-cross-origin" src="https://www.youtube-nocookie.com/embed/${E(p.meta.video_id)}${p.meta.clip ? "?start=" + Number(p.meta.clip.start) + "&end=" + Number(p.meta.clip.end) : ""}" title="${E(p.title)}" allow="accelerometer; encrypted-media; picture-in-picture" allowfullscreen></iframe></div>` : ""}${p.meta.video_id ? `<div class="video-metadata"><span>${p.meta.duration_seconds ? E(UI.duration(p.meta.duration_seconds)) : "Duración por confirmar"}</span>${p.meta.video_metadata_mode ? `<span class="tag">${E(p.meta.video_metadata_mode)}</span>` : ""}</div>` : ""}${extra}${UI.attachments(p)}${UI.generated(p)}${p.meta.url ? `<a class="btn" href="${E(safeURL(p.meta.url))}" target="_blank" rel="noopener noreferrer">Abrir enlace ↗</a>` : ""}${p.meta.benefits ? `<h3>Beneficios</h3><p class="detail-body">${E(p.meta.benefits)}</p>` : ""}${p.meta.initiatives ? `<h3>Iniciativas</h3><p class="detail-body">${E(p.meta.initiatives)}</p>` : ""}${p.meta.clip ? `<div class="alert">Cápsula: ${p.meta.clip.start}s – ${p.meta.clip.end}s · Referencia externa. No se almacena el video completo.</div>` : ""}${p.meta.infographic ? btn(I("download") + " Descargar infografía", "infographic", `data-id="${id}"`) : ""}${p.meta.copyright ? `<p class="private-note">${E(p.meta.copyright)}</p>` : ""}<div class="form-actions">${canEdit ? btn(I("edit") + " Editar", "editor", `data-type="${p.type}" data-id="${id}"`) : ""}${S.boot.moderator && p.type === "event" && p.status === "publish" && !p.meta.micro ? btn("Invitar asociados", "event-invite", `data-id="${id}"`) : ""}${S.boot.moderator && p.type === "resource" && p.meta.video_id ? btn("Actualizar datos de YouTube", "video-metadata", `data-id="${id}"`) : ""}${S.boot.moderator && p.type === "resource" ? btn(I("spark") + " Generar resumen y nota", "generate", `data-id="${id}"`) : ""}${S.boot.moderator && p.type !== "contact" ? btn(I("shield") + " Moderar", "moderate", `data-id="${id}"`) : ""}${p.status === "publish" ? `${btn(I("heart") + " " + p.reactions, "like", `data-id="${id}" data-active="${!p.liked}"`)}${btn(p.following ? "Dejar de seguir" : "Seguir conversación", "follow", `data-id="${id}" data-active="${!p.following}"`)}${btn("Reportar", "report", `data-id="${id}"`, "ghost")}` : ""}</div>${p.status === "publish" ? `<section class="comments"><h3>Conversación</h3><div id="comments-list">Cargando comentarios…</div><form data-form="comment" data-id="${id}" style="margin-top:18px">${field("body", "Comparte tu opinión", "", "textarea", 'required maxlength="5000"')}<button class="btn primary small">Publicar comentario</button></form></section>` : ""}`,
      true,
    );
    if (p.status === "publish") {
      const comments = await api("items/" + id + "/comments");
      const target = document.getElementById("comments-list");
      if (target)
        target.innerHTML =
          comments
            .map(
              (c) =>
                `<article class="comment"><strong>${E(c.author)}</strong> <small class="muted">${date(c.date)}</small><p>${E(c.body)}</p></article>`,
            )
            .join("") ||
          '<p class="private-note">Sé la primera persona en compartir una idea.</p>';
    }
  }
  async function editor(type, id = 0) {
    const p = id
      ? await api("items/" + id)
      : { title: "", body: "", meta: {}, tags: [], status: "pending" };
    S.edit = p;
    const m = p.meta;
    const localDate = (d) => {
      if (!d) return "";
      const t = new Date(d);
      return new Date(t - t.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 16);
    };
    let extra = "";
    if (type === "event")
      extra = `<div class="form-grid">${field("start", "Inicio (tu zona horaria)", localDate(m.start), "datetime-local", "required")}${field("end", "Fin (tu zona horaria)", localDate(m.end), "datetime-local", "required")}${field("capacity", "Cupos (0 = ilimitado)", m.capacity || 0, "number", 'min="0" max="100000"')}${select("modality", "Modalidad", ["Virtual", "Presencial", "Híbrido"], m.modality || "Virtual")}${field("location", "Ubicación", m.location || "")}${field("url", "Enlace del encuentro", m.url || "", "url")}</div>${field("agenda", "Agenda", m.agenda || "", "textarea")}`;
    if (type === "resource")
      extra = `<div class="form-grid">${select("resource_type", "Tipo de recurso", ["Artículo", "Video", "Podcast", "Nota técnica", "Infografía", "Documento"], m.resource_type || "Artículo")}${field("source", "Fuente / autoría", m.source || "")}${field("youtube_url", "URL de YouTube", m.youtube_url || "", "url")}${field("url", "URL del recurso externo", m.url || "", "url")}${field("duration_seconds", "Duración en segundos (0 = por confirmar)", m.duration_seconds || 0, "number", 'min="0" max="604800"')}</div>${field("copyright", "Propiedad intelectual", m.copyright || "© ASCLA – Asociación de Secretarios Corporativos de América Latina")}${field("summary", "Resumen", m.summary || "", "textarea")}${S.boot.moderator ? `${field("transcript", "Transcripción autorizada (opcional)", m.transcript || "", "textarea", 'maxlength="100000"')}${field("identities", "Identidades y afiliaciones que deben anonimizarse (una por línea)", m.identities || "", "textarea")}` : ""}<p class="private-note">Las conferencias completas permanecen en YouTube. La IA genera borradores revisables.</p>`;
    if (type === "ally")
      extra =
        select(
          "alliance_type",
          "Tipo de alianza",
          ["Socio estratégico", "Convenio", "Otro"],
          m.alliance_type,
        ) +
        field("url", "Sitio del aliado", m.url || "", "url") +
        field("benefits", "Beneficios", m.benefits || "", "textarea") +
        field(
          "initiatives",
          "Iniciativas y recursos",
          m.initiatives || "",
          "textarea",
        );
    if (type === "topic") {
      const forums = { items: await allContent("forum") };
      extra = select(
        "parent",
        "Foro",
        [
          [0, "Conversación general"],
          ...forums.items.map((f) => [f.id, f.title]),
        ],
        p.parent || 0,
      );
    }
    if (type === "gallery") {
      const events = { items: await allContent("event") };
      extra = select(
        "event_id",
        "Evento relacionado",
        [[0, "Sin evento"], ...events.items.map((f) => [f.id, f.title])],
        m.event_id || 0,
      );
    }
    modal(
      (id ? "Editar " : "Crear ") + typeLabel[type],
      `<form data-form="editor" data-type="${type}" data-id="${id}">${field("title", "Título", p.title, "text", 'required maxlength="200"')}${field("body", "Contenido", p.body, "textarea", 'required maxlength="30000"')}${extra}${select("category", "Categoría", [["", "Sin categoría"], ...S.boot.catalogs.category.map(t => [t.id, t.name])], p.tags.find(t => t.taxonomy === "ascla_category")?.id || "")}${field("tag_names", "Etiquetas (separadas por comas)", p.tags.filter(t => t.taxonomy === "ascla_tag").map(t => t.name).join(", ") || (m.tags || []).join(", "))}<label>Temas</label><div class="multi-select">${S.boot.catalogs.interest.map((t) => `<label class="chip-check"><input type="checkbox" name="interest" value="${t.id}" ${p.tags.some((x) => x.id === t.id) ? "checked" : ""}>${E(t.name)}</label>`).join("")}</div>${["hub", "gallery", "resource", "ally"].includes(type) ? `<label class="btn small">${I("plus")} Adjuntar imagen o PDF<input type="file" data-upload="content" accept="image/jpeg,image/png,image/webp,application/pdf" hidden></label><div id="attachments">${(m.media_ids || []).map((mid) => `<span class="attached-file" data-media="${mid}">Archivo #${mid}</span>`).join("")}</div><p class="private-note">Imágenes hasta 3 MB; PDF hasta 5 MB. Sólo acceso autenticado.</p>` : ""}${S.boot.moderator ? check("chatham", "Aplicar Regla de Chatham House", m.chatham !== false) : ""}${select(
        "status",
        "Guardar como",
        S.boot.moderator && !m.generated
          ? [
              ["draft", "Borrador"],
              ["pending", "Pendiente de revisión"],
              ["publish", "Publicado"],
            ]
          : [
              ["draft", "Borrador"],
              ["pending", "Enviar a revisión"],
            ],
        p.status === "publish" ? "pending" : p.status,
      )}<div class="alert">Respeta la confidencialidad, la propiedad intelectual y la diversidad. No se admite spam ni promoción comercial directa.</div><div class="form-actions">${btn("Cancelar", "close")}<button class="btn primary">Guardar ${typeLabel[type]}</button></div></form>`,
      true,
    );
  }
  async function inviteMembers(id, page = 1, query = "") {
    if (!S.invite || S.invite.id !== id) S.invite = { id, selected: new Set() };
    S.invite.query = query;
    const list = await api("profiles?" + new URLSearchParams({ q: query, page }));
    modal("Invitar asociados", `<p class="private-note">Selecciona hasta 50 asociados. La invitación es interna y no reserva un cupo.</p><form data-form="invite-search" data-id="${id}" class="filters"><input name="q" aria-label="Buscar invitados" value="${E(query)}" placeholder="Nombre, empresa o interés"><button class="btn">Buscar</button></form><form data-form="event-invite" data-id="${id}"><div class="invite-options">${list.items.map(p => `<label class="check"><input type="checkbox" data-invite-member="${p.id}" ${S.invite.selected.has(p.id) ? "checked" : ""}><span>${E(p.name)}<small class="muted"> · ${E(p.company || "ASCLA")}</small></span></label>`).join("") || empty("No encontramos asociados")}</div><div class="pagination">${btn("Anterior", "invite-page", `data-page="${page - 1}" ${page <= 1 ? "disabled" : ""}`, "small")}<span>${page} / ${list.pages}</span>${btn("Siguiente", "invite-page", `data-page="${page + 1}" ${page >= list.pages ? "disabled" : ""}`, "small")}</div><p class="private-note"><span id="invite-selected">${S.invite.selected.size}</span> seleccionados</p><button class="btn primary">Enviar invitaciones</button></form>`);
  }
  root.addEventListener("change", event => {
    const input = event.target.closest("[data-invite-member]");
    if (!input || !S.invite) return;
    const id = Number(input.dataset.inviteMember);
    if (input.checked && S.invite.selected.size >= 50) { input.checked = false; toast("Puedes invitar hasta 50 asociados por envío."); return; }
    if (input.checked) S.invite.selected.add(id); else S.invite.selected.delete(id);
    document.getElementById("invite-selected").textContent = S.invite.selected.size;
  });
  async function messages() {
    S.conversations = await api(
      "conversations?" + new URLSearchParams({ q: S.filter.q || "" }),
    );
    const selected =
      S.conversation ||
      Number(new URLSearchParams(location.search).get("conversation")) ||
      Number(S.conversations[0]?.id) ||
      0;
    S.conversation = selected;
    const current = S.conversations.find((c) => Number(c.id) === selected);
    content().innerHTML =
      heading(
        "Mensajería",
        "Una conversación puede ser el inicio de una gran colaboración.",
        link("directorio", I("plus") + " Nueva conversación", "primary"),
      ) +
      `<form class="filters" data-form="filters"><input name="q" aria-label="Buscar conversaciones" placeholder="Buscar conversaciones…" value="${E(S.filter.q || "")}"><button class="btn">Buscar</button></form><div class="chat-layout"><aside class="chat-sidebar">${S.conversations.map((c) => `<button class="chat-person ${Number(c.id) === selected ? "active" : ""}" data-action="conversation" data-id="${c.id}">${avatar(c.other)}<span><strong>${E(c.other.name)} ${c.unread ? '<span class="tag">' + c.unread + "</span>" : ""}</strong><small>${E(c.preview.slice(0, 42))}</small></span></button>`).join("") || '<p class="private-note">Busca un asociado en el directorio para iniciar una conversación.</p>'}</aside><section class="chat-conversation">${current ? `<div class="chat-title"><strong>${E(current.other.name)}</strong>${btn(current.blocked ? "Desbloquear" : "Bloquear", "block", `data-id="${current.other.id}" data-active="${!current.blocked}"`, "ghost small")}</div><div class="chat-messages" id="chat-messages"></div><form class="chat-compose" data-form="message" data-id="${selected}"><textarea name="body" aria-label="Escribir mensaje" placeholder="Escribe un mensaje…" required maxlength="5000" ${current.blocked ? "disabled" : ""}></textarea><button class="btn primary" ${current.blocked ? "disabled" : ""}>${I("contact")} Enviar</button></form>` : empty("Inicia una conversación", "Tus mensajes privados aparecerán aquí.")}</section></div>`;
    if (current) {
      await loadMessages();
      clearInterval(S.poll);
      S.poll = setInterval(() => {
        if (!document.hidden && S.page === "mensajeria")
          loadMessages().catch(() => {});
      }, 12000);
    }
  }
  async function loadMessages() {
    const data = await api(`conversations/${S.conversation}/messages`);
    const area = document.getElementById("chat-messages");
    if (!area) return;
    const bottom = area.scrollHeight - area.scrollTop - area.clientHeight < 100;
    area.innerHTML =
      (data.items.length >= 60
        ? btn(
            "Cargar anteriores",
            "older-messages",
            `data-before="${data.before}"`,
            "small",
          )
        : "") +
      data.items
        .map(
          (m) =>
            `<div class="bubble ${Number(m.sender_id) === S.boot.me.id ? "me" : ""}">${E(m.body)}<small>${date(m.created_at, { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })}</small></div>`,
        )
        .join("");
    if (bottom || !S.messagesLoaded) {
      area.scrollTop = area.scrollHeight;
      S.messagesLoaded = true;
    }
  }
  async function intro(id) {
    const r = await api("matching/" + id + "/intro");
    modal(
      "Una idea para romper el hielo",
      `<p class="alert">${E(r.mode)} · Puedes editarla antes de enviar. Aún no se ha enviado ningún mensaje.</p><form data-form="intro" data-id="${id}">${field("body", "Mensaje introductorio", r.text, "textarea", 'required maxlength="5000"')}<div class="form-actions">${btn("Cancelar", "close")}<button class="btn primary">Enviar mensaje</button></div></form>`,
    );
  }
  async function assistant() {
    content().innerHTML = `<section class="assistant-intro"><div class="assistant-mark">${I("spark")}</div><div class="eyebrow">ASISTENTE ASCLA</div><h1>El conocimiento de tu comunidad,<br>a una pregunta de distancia.</h1><p>Explora ideas y encuentra respuestas basadas en el Centro de Conocimiento, siempre con sus fuentes.</p><span class="demo-badge" style="display:inline-block;margin-top:15px">${E(S.boot.ai_mode)}</span></section><div class="ask-suggestions">${["¿Cómo puede la junta supervisar los riesgos de inteligencia artificial?", "¿Cuál es el rol de la secretaría corporativa?", "¿Cómo mejorar el seguimiento de acuerdos?", "¿Qué recursos tenemos sobre gobierno corporativo?"].map((q) => btn(E(q) + " " + I("arrow"), "ask-suggestion", `data-question="${E(q)}"`)).join("")}</div><form class="card" data-form="ask" style="max-width:820px;margin:auto">${field("question", "Tu pregunta", "", "textarea", 'placeholder="¿Qué te gustaría conocer?" required maxlength="2000"')}<div class="form-actions"><button class="btn primary">${I("spark")} Consultar al asistente</button></div><p class="private-note">El asistente usa recursos publicados de ASCLA. Si no encuentra evidencia suficiente, te lo indicará.</p></form><div id="answers"></div>`;
  }
  async function watchJob(id, target, onDone) {
    let attempts = 0;
    target.innerHTML =
      '<div class="alert">Pendiente · Estamos preparando la respuesta…</div>';
    const poll = async () => {
      try {
        const j = await api("jobs/" + id);
        if (!target.isConnected) return;
        if (j.status === "completed") {
          onDone(j.result);
          return;
        }
        if (j.status === "error") {
          target.innerHTML = `<div class="error">${E(j.error)} ${btn("Reintentar", "retry-job", `data-id="${id}"`, "small")}</div>`;
          return;
        }
        target.innerHTML = `<div class="alert">${I("clock")} ${j.status === "processing" ? "Procesando" : "Pendiente"} · Trabajo #${id}</div>`;
        if (++attempts < 90) setTimeout(poll, 2500);
        else
          target.innerHTML =
            '<div class="alert">El trabajo continúa en segundo plano. Puedes consultar su estado en administración.</div>';
      } catch (e) {
        target.innerHTML = `<div class="error">${E(e.message)}</div>`;
      }
    };
    setTimeout(poll, 1800);
  }
  async function contact() {
    const list = await api("content/contact?mine=1");
    content().innerHTML =
      heading(
        "Estamos para ayudarte",
        "Consultas, sugerencias y soporte para tu experiencia en ASCLA.",
      ) +
      `<div class="cards two"><form class="card" data-form="contact">${field("title", "Asunto", "", "text", 'required maxlength="200"')}${select("category", "Categoría", ["Consulta general", "Soporte técnico", "Eventos", "Membresía", "Sugerencia"])}${field("body", "Mensaje", "", "textarea", 'required maxlength="5000"')}<input name="website_confirm" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true"><button class="btn primary">Enviar solicitud</button><p class="private-note">Sólo tú y el equipo de moderación pueden ver tu solicitud.</p></form><div><div class="card"><h2>Preguntas frecuentes</h2>${[
        [
          "¿Cómo actualizo mis intereses?",
          "En Perfil encontrarás Conocimiento e intereses. Guarda los cambios para actualizar tus recomendaciones.",
        ],
        [
          "¿Cómo me inscribo en un evento?",
          "Abre Eventos, elige un encuentro y pulsa Registrarme.",
        ],
        [
          "¿Qué significa Chatham House?",
          "Comparte el conocimiento sin revelar identidades o afiliaciones de los participantes.",
        ],
      ]
        .map(
          ([q, a]) =>
            `<details style="padding:16px 0;border-bottom:1px solid var(--line)"><summary>${E(q)}</summary><p class="private-note">${E(a)}</p></details>`,
        )
        .join(
          "",
        )}</div><div class="card"><h3>Mis solicitudes</h3>${list.items.map((p) => `<div class="notification-row"><div><strong>${E(p.title)}</strong><p class="private-note">${date(p.date)}</p></div><span class="tag">${E({ closed: "Resuelta", progress: "En atención", open: "Recibida" }[p.meta.request_status] || "Recibida")}</span></div>`).join("") || '<p class="private-note">Aún no has enviado solicitudes.</p>'}</div></div></div>`;
  }
  async function refreshNotifications() {
    try {
      const list = await api("notifications");
      const dot = document.querySelector(".unread-dot");
      if (dot) dot.hidden = !list.some((n) => !n.read_at);
    } catch {}
  }
  async function notifications() {
    const list = await api("notifications");
    modal(
      "Tus notificaciones",
      list
        .map(
          (n) =>
            `<div class="notification-row ${n.read_at ? "" : "unread"}"><div>${n.url ? `<a href="${E(safeURL(n.url))}">${E(n.label)}</a>` : E(n.label)}<p class="private-note">${date(n.created_at)}</p></div>${!n.read_at ? btn(I("check"), "notification-read", `data-id="${n.id}" aria-label="Marcar como leída"`, "ghost") : ""}</div>`,
        )
        .join("") || empty("Estás al día"),
    );
  }
  async function admin() {
    const d = await api("admin");
    S.admin = d;
    const tab = S.adminTab;
    const tabs = [
      ["moderacion", "Moderación"],
      ["trabajos", "IA y trabajos"],
      ["microeventos", "Microeventos"],
      ["solicitudes", "Solicitudes"],
      ["logs", "Auditoría"],
      ...(S.boot.admin ? [["configuracion", "Configuración"]] : []),
    ];
    content().innerHTML =
      heading(
        "Administración ASCLA",
        "Herramientas para cuidar y hacer crecer la comunidad.",
        link("intranet", "Ver intranet " + I("arrow")),
      ) +
      `<div class="stat-grid">${[
        [d.counts.members, "Miembros", "users"],
        [d.pending.length, "Contenidos por revisar", "shield"],
        [
          d.jobs.filter((j) => ["pending", "processing"].includes(j.status))
            .length,
          "Trabajos activos",
          "spark",
        ],
        [d.reports.length, "Reportes", "bell"],
      ]
        .map(
          ([n, l, i]) =>
            `<div class="stat"><span class="stat-icon">${I(i)}</span><div><strong>${n}</strong><small>${l}</small></div></div>`,
        )
        .join(
          "",
        )}</div><div class="tabs">${tabs.map(([k, l]) => btn(l, "admin-tab", `data-tab="${k}"`, "tab " + (tab === k ? "active" : ""))).join("")}</div><div id="admin-panel"></div>`;
    const panel = document.getElementById("admin-panel");
    if (tab === "moderacion")
      panel.innerHTML = `<div class="card"><h2>Contenido pendiente y borradores</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>CONTENIDO</th><th>AUTOR</th><th>ESTADO</th><th>ACCIÓN</th></tr></thead><tbody>${d.pending.map((p) => `<tr><td><strong>${E(p.title)}</strong><br><small>${E(typeLabel[p.type])}${p.meta.generated ? " · IA" : ""}${p.meta.chatham ? " · Chatham House" : ""}</small></td><td>${E(p.author.name)}</td><td>${status(p.status)}</td><td>${btn("Revisar", "item", `data-id="${p.id}"`, "small")}</td></tr>`).join("") || '<tr><td colspan="4">No hay contenido pendiente.</td></tr>'}</tbody></table></div></div><div class="card"><h3>Reportes de la comunidad</h3>${d.reports.map((r) => `<p style="padding:10px 0">Publicación #${r.target_id} ${btn("Revisar", "item", `data-id="${r.target_id}"`, "small")}</p>`).join("") || '<p class="private-note">Sin reportes.</p>'}</div><div class="card"><h3>Comentarios pendientes</h3>${d.comments.map((c) => `<div class="comment"><strong>${E(c.author)}</strong><p>${E(c.body)}</p>${btn("Aprobar", "comment-moderate", `data-id="${c.id}" data-decision="approve"`, "small")}${btn("Mantener oculto", "comment-moderate", `data-id="${c.id}" data-decision="reject"`, "small")}</div>`).join("") || '<p class="private-note">Sin comentarios pendientes.</p>'}</div>`;
    if (tab === "trabajos")
      panel.innerHTML = `<div class="alert">La IA y las transcripciones se procesan en segundo plano. Ningún borrador IA se publica sin revisión.</div><div class="admin-actions">${link("centro-conocimiento", "Gestionar recursos", "primary")}${btn("Curaduría social demo", "social-job")}${btn("Conectar YouTube OAuth", "google-connect", 'data-service="youtube"')}${btn("Actualizar estados", "admin-refresh")}</div><div class="card table-wrap"><table class="data-table"><thead><tr><th>ID</th><th>TIPO</th><th>ESTADO</th><th>DETALLE</th><th></th></tr></thead><tbody>${d.jobs.map((j) => `<tr><td>#${j.id}</td><td>${E(j.kind)}</td><td>${status(j.status)}</td><td>${E(j.error || date(j.created_at))}</td><td>${btn("Ver", "job-detail", `data-id="${j.id}"`, "small")}${j.status === "error" ? btn("Reintentar", "retry-job", `data-id="${j.id}"`, "small") : ""}</td></tr>`).join("")}</tbody></table></div>`;
    if (tab === "microeventos")
      panel.innerHTML = `<div class="card"><h2>Círculos de conversación ASCLA</h2><p class="detail-body">Prepara grupos de 4 a 6 asociados que aceptaron participar. El sistema considera intereses comunes y el historial para reducir la repetición. La propuesta mensual incluye tema, agenda y fecha ajustable.</p><div class="alert">La propuesta se genera una vez por mes. En modo de aprobación, revisa y publica cada microevento para enviar sus invitaciones internas.</div>${S.boot.admin ? btn(I("spark") + " Preparar propuesta del mes", "micro-job", "", "primary") : ""}<div id="micro-job-result" style="margin-top:18px"></div></div>`;
    if (tab === "logs")
      panel.innerHTML = `<div class="card table-wrap"><table class="data-table"><thead><tr><th>FECHA UTC</th><th>ACCIÓN</th><th>ACTOR</th><th>OBJETO</th><th>CLASIFICACIÓN</th></tr></thead><tbody>${d.audit.map((a) => `<tr><td>${E(a.created_at)}</td><td>${E(a.action)}</td><td>${a.actor_id}</td><td>${a.object_id}</td><td>${E(a.detail)}</td></tr>`).join("")}</tbody></table></div>`;
    if (tab === "solicitudes") {
      const list = await api("content/contact");
      panel.innerHTML =
        list.items
          .map(
            (p) =>
              `<div class="card"><h3>${E(p.title)}</h3><p class="detail-body">${E(p.body)}</p><p class="private-note">${E(p.author.name)} · ${date(p.date)}</p><div class="admin-actions">${[
                ["open", "Recibida"],
                ["progress", "En atención"],
                ["closed", "Resuelta"],
              ]
                .map(([k, l]) =>
                  btn(
                    l,
                    "contact-status",
                    `data-id="${p.id}" data-status="${k}"`,
                    "small",
                  ),
                )
                .join("")}</div></div>`,
          )
          .join("") || empty("No hay solicitudes pendientes");
    }
    if (tab === "configuracion") await settings(panel);
  }
  async function settings(panel) {
    const s = await api("settings");
    panel.innerHTML = `<form class="card" data-form="settings"><h2>Comunidad e integraciones</h2><div class="form-section">Participación y revisión</div>${check("demo", "Modo demo (datos e integraciones identificados)", s.demo)}${check("moderation_required", "Revisar publicaciones del Hub antes de publicarlas", s.moderation_required)}${check("moderate_comments", "Revisar comentarios antes de publicarlos", s.moderate_comments)}${check("chatham_default", "Aplicar Chatham House por defecto", s.chatham_default)}${check("micro_enabled", "Preparar microeventos mensualmente con WP-Cron", s.micro_enabled)}${check("micro_approval", "Exigir aprobación administrativa de microeventos", s.micro_approval)}<div class="form-section">Motor de afinidad</div><div class="form-grid">${Object.entries(
      s.matching_weights,
    )
      .map(([k, v]) =>
        field(
          "weight_" + k,
          profileLabels[k],
          v,
          "number",
          'min="0" max="100"',
        ),
      )
      .join(
        "",
      )}</div><div class="form-section">Inteligencia artificial</div><div class="form-grid">${select(
      "ai_mode",
      "Proveedor",
      [
        ["mock", "DEMO MODE · sin API"],
        ["real", "API real · OpenAI Responses"],
      ],
      s.ai_mode,
    )}${field("ai_model", "Modelo habilitado en tu cuenta", s.ai_model)}${field("ai_key", s.has_ai_key ? "API key (configurada; vacío para conservar)" : "API key", "", "password", 'autocomplete="new-password"')}${select(
      "youtube_mode",
      "Transcripciones YouTube",
      [
        ["mock", "DEMO MODE / transcripción manual"],
        ["real", "YouTube OAuth real"],
      ],
      s.youtube_mode,
    )}</div>${check("clear_ai_key", "Eliminar API key guardada", false)}<div class="form-section">Google OAuth</div><div class="form-grid">${field("google_client_id", "Client ID", s.google_client_id)}${field("google_client_secret", s.has_google_secret ? "Client Secret (configurado)" : "Client Secret", "", "password", 'autocomplete="new-password"')}</div><div class="alert">URI de redirección: <code>${E(s.google_redirect)}</code></div><p class="private-note">Cada asociado conecta su calendario desde Perfil. YouTube se conecta desde IA y trabajos.</p><div class="form-section">Social Listening</div><div class="alert">LinkedIn y X: DEMO MODE. Los adaptadores requieren aprobación, permisos y planes oficiales; no se realiza scraping ni se envían respuestas externas.</div>${field("copyright", "Propiedad intelectual", s.copyright)}<div class="form-actions"><button class="btn primary">Guardar configuración</button></div></form><form class="card section-gap" data-form="demo"><h2>Preparar datos de demostración</h2><p class="private-note">Crea 18 perfiles y 9 empresas ficticias. Las siguientes ejecuciones conservan datos y contraseñas existentes.</p>${field("password", "Contraseña para nuevas cuentas demo", "", "password", 'required minlength="12" autocomplete="new-password"')}<button class="btn">Crear / completar demo</button></form>`;
  }
  function rules() {
    modal(
      "Normas de nuestra comunidad",
      `<div class="alert chatham">${I("shield")} Compartimos el conocimiento; protegemos las identidades.</div>${[
        [
          "Respeto y profesionalismo",
          "Interactúa con cortesía, promueve discusión constructiva y valora la diversidad.",
        ],
        [
          "Participación y colaboración",
          "Comparte aportes pertinentes a la gobernanza corporativa y al desarrollo profesional.",
        ],
        [
          "Confidencialidad y datos",
          "No compartas información sensible ni datos personales sin consentimiento.",
        ],
        [
          "Regla de Chatham House",
          "Puedes usar el conocimiento recibido sin revelar la identidad ni afiliación de participantes y oradores.",
        ],
        [
          "Uso responsable",
          "Evita spam, automatización abusiva y promoción comercial directa.",
        ],
        [
          "Propiedad intelectual",
          "Reconoce los derechos de ASCLA y terceros al utilizar o reproducir materiales.",
        ],
        [
          "Moderación",
          "Reporta contenido inapropiado. ASCLA revisa publicaciones y registra sus decisiones.",
        ],
      ]
        .map(
          ([t, b]) =>
            `<h3 style="margin-top:18px">${E(t)}</h3><p class="private-note">${E(b)}</p>`,
        )
        .join("")}`,
    );
  }
  function download(name, body, mime) {
    const url = URL.createObjectURL(new Blob([body], { type: mime }));
    const a = document.createElement("a");
    a.href = url;
    a.download = name;
    a.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }
  function infographic() {
    const p = S.item,
      info = p.meta.infographic || {};
    const points = (info.key_points || info.sections || [])
      .slice(0, 6)
      .map((v) => (typeof v === "string" ? v : JSON.stringify(v)));
    const lines = [];
    points.forEach((p, i) => {
      let line = "",
        row = 0;
      p.split(" ").forEach((w) => {
        if ((line + w).length > 65) {
          lines.push(
            `<text x="70" y="${190 + i * 120 + row * 22}" fill="#527088" font-size="16">${E(line)}</text>`,
          );
          row++;
          line = "";
        }
        if (row < 4) line += w + " ";
      });
      if (row < 4)
        lines.push(
          `<text x="70" y="${190 + i * 120 + row * 22}" fill="#527088" font-size="16">${E(line)}</text>`,
        );
    });
    const height = 250 + points.length * 120;
    download(
      "ascla-infografia-" + p.id + ".svg",
      `<svg xmlns="http://www.w3.org/2000/svg" width="760" height="${height}"><rect width="760" height="${height}" fill="#f4f8fb"/><rect width="760" height="110" fill="#103554"/><text x="50" y="45" font-family="Arial" font-size="16" fill="#9ecce8">ASCLA · CENTRO DE CONOCIMIENTO</text><text x="50" y="80" font-family="Arial" font-size="24" fill="white">${E(String(info.title || "Claves de la sesión").slice(0, 48))}</text><g font-family="Arial">${lines.join("")}</g><text x="50" y="${height - 60}" font-family="Arial" font-size="12" fill="#65859b">Fuente interna ASCLA #${p.meta.source_id || p.id} · ${E(p.meta.ai_mode || "")}</text><text x="50" y="${height - 35}" font-family="Arial" font-size="10" fill="#65859b">© ASCLA – Asociación de Secretarios Corporativos de América Latina</text></svg>`,
      "image/svg+xml",
    );
  }
  async function render() {
    clearInterval(S.poll);
    content().innerHTML = '<div class="loading">Cargando…</div>';
    try {
      if (S.page === "intranet") await dashboard();
      else if (S.page === "directorio") await directory();
      else if (S.page === "perfil") await profile();
      else if (S.page === "mensajeria") await messages();
      else if (S.page === "asistente") await assistant();
      else if (S.page === "contacto") await contact();
      else if (S.page === "admin") await admin();
      else await listing();
    } catch (e) {
      content().innerHTML = `<div class="error">${E(e.message)} ${btn("Reintentar", "refresh", "", "small")}</div>`;
    }
  }
  root.addEventListener("click", async (event) => {
    const b = event.target.closest("[data-action]");
    if (!b) return;
    event.preventDefault();
    const a = b.dataset.action,
      id = Number(b.dataset.id || 0);
    b.disabled = true;
    try {
      if (a === "close") closeModal();
      else if (a === "menu")
        document.querySelector(".ascla-sidebar").classList.toggle("open");
      else if (a === "refresh") await render();
      else if (a === "rules") rules();
      else if (a === "member") await member(id);
      else if (a === "item") await item(id);
      else if (a === "editor") await editor(b.dataset.type, id);
      else if (a === "notifications") await notifications();
      else if (["like", "follow", "report"].includes(a)) {
        await api(`items/${id}/reaction`, {
          kind: a,
          active: a === "report" || b.dataset.active === "true",
        });
        toast(
          a === "report" ? "Reporte enviado a moderación." : "Actualizado.",
        );
        if (S.item?.id === id && document.querySelector(".modal"))
          await item(id);
        else await render();
      } else if (a === "connect") {
        await api("relations", { target: id, kind: "connect", active: true });
        toast("Solicitud de conexión enviada.");
      } else if (a === "intro") await intro(id);
      else if (a === "message-start") {
        const c = await api("conversations", { target: id });
        location.href = C.pages.mensajeria.url + "?conversation=" + c.id;
      } else if (a === "conversation") {
        S.conversation = id;
        S.messagesLoaded = false;
        await messages();
      } else if (a === "block") {
        await api("relations", {
          target: id,
          kind: "block",
          active: b.dataset.active === "true",
        });
        await messages();
      } else if (a === "older-messages") {
        const d = await api(
          `conversations/${S.conversation}/messages?before=${b.dataset.before}`,
        );
        b.outerHTML = d.items
          .map(
            (m) =>
              `<div class="bubble ${Number(m.sender_id) === S.boot.me.id ? "me" : ""}">${E(m.body)}<small>${date(m.created_at)}</small></div>`,
          )
          .join("");
      } else if (a === "register") {
        await api(`events/${id}/register`, { status: b.dataset.status });
        toast("Inscripción actualizada.");
        await item(id);
      } else if (a === "ics")
        download(
          "ascla-evento-" + id + ".ics",
          S.event.ics,
          "text/calendar;charset=utf-8",
        );
      else if (a === "page") {
        S.filter.page = b.dataset.page;
        await render();
      } else if (a.startsWith("filter-")) {
        S.filter = {
          ...S.filter, page: 1,
          mine: a === "filter-mine" ? 1 : "",
          past: a === "filter-past" ? 1 : "",
        };
        await render();
      } else if (a === "calendar-prev" || a === "calendar-next") {
        S.calendar = new Date(
          S.calendar.getFullYear(),
          S.calendar.getMonth() + (a === "calendar-next" ? 1 : -1),
          1,
        );
        S.events = await calendarEvents();
        document.getElementById("calendar-body").innerHTML = calendar(S.events);
      } else if (a === "calendar-day") {
        const day = Number(b.dataset.day), from = new Date(S.calendar.getFullYear(), S.calendar.getMonth(), day), to = new Date(S.calendar.getFullYear(), S.calendar.getMonth(), day + 1);
        const events = S.events.filter(p => new Date(p.meta.start) < to && new Date(p.meta.end) > from);
        modal("Agenda del " + from.toLocaleDateString("es-PE"), events.map(eventMini).join(""));
      } else if (a === "notification-read") {
        await api("notifications/" + id + "/read", {});
        await notifications();
        await refreshNotifications();
      } else if (a === "ask-suggestion") {
        document.querySelector("[name=question]").value = b.dataset.question;
        document.querySelector("[data-form=ask]").requestSubmit();
      } else if (a === "admin-tab") {
        S.adminTab = b.dataset.tab;
        await admin();
      } else if (a === "admin-refresh") await admin();
      else if (a === "moderate") {
        const p = await api("items/" + id);
        modal(
          "Revisar publicación",
          `<h3>${E(p.title)}</h3><p class="detail-body">${E(p.body)}</p><form data-form="moderate" data-id="${id}">${select(
            "decision",
            "Decisión",
            [
              ["approve", "Aprobar y publicar"],
              ["reject", "Rechazar"],
              ["hide", "Ocultar"],
              ["suspend", "Suspender publicación"],
            ],
          )}${field("reason", "Motivo de la decisión", "", "textarea", 'required maxlength="1000"')}${p.meta.generated ? check("reviewed", "Revisé fuentes, identidades, afiliaciones y derechos de propiedad intelectual.", false) : ""}<div class="form-actions">${btn("Cancelar", "close")}<button class="btn primary">Guardar decisión</button></div></form>`,
        );
      } else if (a === "comment-moderate") {
        await api("admin/comments/" + id, { decision: b.dataset.decision });
        toast("Comentario revisado.");
        await admin();
      } else if (a === "contact-status") {
        await api("admin/contact/" + id, { status: b.dataset.status });
        toast("Estado actualizado.");
        await admin();
      } else if (a === "generate") {
        const j = await api("jobs", { kind: "multimedia", resource_id: id });
        modal("Procesar conferencia", '<div id="job-result"></div>');
        watchJob(j.id, document.getElementById("job-result"), (r) => {
          document.getElementById("job-result").innerHTML =
            `<div class="alert">${E(r.message)} · ${E(r.mode)}</div>${btn("Abrir nota técnica", "item", `data-id="${r.resource_id}"`, "primary")}`;
        });
      } else if (a === "event-invite") {
        S.invite = null;
        await inviteMembers(id);
      } else if (a === "invite-page") {
        await inviteMembers(S.invite.id, Number(b.dataset.page), S.invite.query);
      } else if (a === "video-metadata") {
        const j = await api("jobs", { kind: "video_metadata", resource_id: id });
        modal("Datos del video", '<div id="video-job-result" role="status">Consultando metadatos…</div>');
        watchJob(j.id, document.getElementById("video-job-result"), () => item(id));
      } else if (a === "micro-job") {
        const j = await api("jobs", { kind: "microevents" });
        watchJob(j.id, document.getElementById("micro-job-result"), (r) => {
          document.getElementById("micro-job-result").innerHTML =
            `<div class="alert">${r.events.length} microeventos propuestos. ${r.waiting.length} asociados en espera.</div>${r.events.map((e) => btn("Revisar #" + e, "item", `data-id="${e}"`, "small")).join("")}`;
        });
      } else if (a === "social-job") {
        const j = await api("jobs", { kind: "social" });
        toast("Curaduría DEMO en cola: #" + j.id);
        await admin();
      } else if (a === "job-detail") {
        const j = await api("jobs/" + id);
        modal(
          "Trabajo #" + id,
          `${status(j.status)}<p class="detail-body">${E(j.error || j.result?.message || j.result?.answer || "")}</p>${j.result?.resource_id ? btn("Abrir recurso", "item", `data-id="${j.result.resource_id}"`) : ""}${j.result?.events ? j.result.events.map((e) => btn("Microevento #" + e, "item", `data-id="${e}"`)).join("") : ""}${j.result?.items ? j.result.items.map((i) => `<p>${E(i.suggested_reply || "")}</p>${btn("Abrir borrador", "item", `data-id="${i.draft_id}"`)}`).join("") : ""}`,
        );
      } else if (a === "retry-job") {
        await api("jobs/" + id + "/retry", {});
        toast("Trabajo nuevamente en cola.");
        if (S.page === "admin") {
          closeModal();
          await admin();
        }
      } else if (a === "google-connect") {
        const r = await api("google/connect", { service: b.dataset.service });
        location.href = safeURL(r.url);
      } else if (a === "google-disconnect") {
        await api("google/disconnect", { service: b.dataset.service });
        toast("Conexión eliminada.");
        S.boot = await api("bootstrap");
        await profile();
      } else if (a === "google-event") {
        await api(`events/${id}/google`, { operation: b.dataset.operation });
        toast("Google Calendar actualizado.");
      } else if (a === "infographic") infographic();
    } catch (e) {
      toast(e.message);
    } finally {
      b.disabled = false;
    }
  });
  root.addEventListener("submit", async (event) => {
    const form = event.target.closest("[data-form]");
    if (!form) return;
    event.preventDefault();
    const action = form.dataset.form,
      data = formData(form),
      submit = form.querySelector("button[type=submit],button:not([type])");
    if (submit) submit.disabled = true;
    try {
      if (action === "global-search") {
        const url = new URL(C.pages["centro-conocimiento"].url, location.href);
        url.searchParams.set("q", data.q);
        location.href = url.href;
      }
      else if (action === "filters") {
        S.filter = { ...S.filter, ...data, page: 1 };
        await render();
      } else if (action === "invite-search") {
        await inviteMembers(Number(form.dataset.id), 1, data.q);
      } else if (action === "event-invite") {
        const r = await api("events/" + form.dataset.id + "/invite", { users: [...S.invite.selected] });
        closeModal(); S.invite = null;
        toast(`${r.sent} invitaciones enviadas; ${r.skipped} asociados ya tenían una inscripción o invitación.`);
        await item(Number(form.dataset.id));
      } else if (action === "profile") {
        for (const key of Object.keys(profileTax))
          data[key] = new FormData(form).getAll(key).map(Number);
        data.hidden = new FormData(form).getAll("hidden");
        for (const key of ["directory", "networking", "microevents"])
          data[key] = form.elements[key].checked;
        delete data.photo;
        await api("profiles/me", data);
        S.boot = await api("bootstrap");
        toast("Perfil actualizado.");
        await profile();
      } else if (action === "editor") {
        const type = form.dataset.type,
          id = Number(form.dataset.id),
          meta = {};
        for (const k of [
          "start",
          "end",
          "capacity",
          "modality",
          "location",
          "url",
          "agenda",
          "resource_type",
          "source",
          "youtube_url",
          "copyright",
          "summary",
          "transcript",
          "identities",
          "alliance_type",
          "benefits",
          "initiatives",
          "event_id",
          "duration_seconds",
        ])
          if (k in data) meta[k] = data[k];
        if (type === "event") {
          meta.start = new Date(data.start).toISOString();
          meta.end = new Date(data.end).toISOString();
        }
        if (form.elements.chatham) meta.chatham = form.elements.chatham.checked;
        meta.media_ids = [...form.querySelectorAll("[data-media]")].map((x) =>
          Number(x.dataset.media),
        );
        const p = await api("content/" + type + (id ? "/" + id : ""), {
          title: data.title,
          body: data.body,
          status: data.status,
          parent: Number(data.parent || 0),
          interest: new FormData(form).getAll("interest").map(Number),
          category: data.category ? [Number(data.category)] : [],
          tag_names: data.tag_names.split(",").map(t => t.trim()).filter(Boolean),
          meta,
        });
        closeModal();
        toast(
          p.status === "publish"
            ? "Contenido publicado."
            : p.status === "draft"
              ? "Borrador guardado."
              : "Contenido enviado a revisión.",
        );
        S.boot = await api("bootstrap");
        await render();
      } else if (action === "comment") {
        const r = await api("items/" + form.dataset.id + "/comments", {
          body: data.body,
        });
        toast(
          r.status === "pending"
            ? "Comentario enviado a revisión."
            : "Comentario publicado.",
        );
        await item(Number(form.dataset.id));
      } else if (action === "message") {
        await api("conversations/" + form.dataset.id + "/messages", {
          body: data.body,
        });
        form.reset();
        await loadMessages();
      } else if (action === "intro") {
        const c = await api("conversations", {
          target: Number(form.dataset.id),
        });
        await api("conversations/" + c.id + "/messages", { body: data.body });
        closeModal();
        toast("Mensaje enviado.");
      } else if (action === "ask") {
        const j = await api("ask", { question: data.question });
        const target = document.createElement("div");
        target.className = "card answer-card";
        document.getElementById("answers").prepend(target);
        watchJob(j.id, target, (r) => {
          target.innerHTML = `<small class="demo-badge">${E(r.mode)}</small><h3 style="margin:16px 0">${E(data.question)}</h3><p>${E(r.answer)}</p>${r.sources.length ? `<div class="sources">${r.sources.map((s) => `<a href="${E(safeURL(s.url))}">${I("book")} ${E(s.title)}</a>`).join("")}</div>` : ""}`;
        });
      } else if (action === "contact") {
        if (data.website_confirm) throw new Error("Solicitud no válida.");
        await api("content/contact", {
          title: data.title,
          body: data.body,
          meta: { description: data.category },
        });
        toast("Solicitud recibida.");
        await contact();
      } else if (action === "moderate") {
        await api("items/" + form.dataset.id + "/moderate", {
          decision: data.decision,
          reason: data.reason,
          reviewed: form.elements.reviewed?.checked || false,
        });
        closeModal();
        toast("Decisión registrada.");
        await render();
      } else if (action === "settings") {
        for (const k of [
          "demo",
          "moderation_required",
          "moderate_comments",
          "chatham_default",
          "micro_enabled",
          "micro_approval",
          "clear_ai_key",
        ])
          data[k] = form.elements[k].checked;
        data.matching_weights = {};
        for (const k of [
          "interests",
          "areas",
          "industries",
          "goals",
          "languages",
        ])
          data.matching_weights[k] = Number(data["weight_" + k]);
        await api("settings", data);
        toast("Configuración guardada.");
        await admin();
      } else if (action === "demo") {
        const r = await api("demo", { password: data.password });
        form.reset();
        toast(r.message);
        await admin();
      }
    } catch (e) {
      toast(e.message);
    } finally {
      if (submit) submit.disabled = false;
    }
  });
  root.addEventListener("change", async (event) => {
    const input = event.target;
    if (!input.dataset.upload || !input.files?.length) return;
    try {
      const fd = new FormData();
      fd.append("file", input.files[0]);
      toast("Subiendo archivo privado…");
      const m = await api("media", fd);
      if (input.dataset.upload === "photo") {
        document.querySelector("[name=photo_id]").value = m.id;
        document.getElementById("photo-status").textContent =
          "Fotografía cargada. Guarda el perfil para aplicar.";
      } else
        document
          .getElementById("attachments")
          .insertAdjacentHTML(
            "beforeend",
            `<span class="attached-file" data-media="${m.id}">${E(m.name)}</span>`,
          );
      toast("Archivo cargado.");
    } catch (e) {
      toast(e.message);
    } finally {
      input.value = "";
    }
  });
  document.addEventListener("keydown", (event) => {
    const dialog = document.querySelector(".modal");
    if (event.key === "Escape") {
      closeModal();
      document.querySelector(".ascla-sidebar")?.classList.remove("open");
    }
    if (event.key === "Tab" && dialog) {
      const f = [
          ...dialog.querySelectorAll("button,input,select,textarea,a[href]"),
        ].filter((x) => !x.disabled && x.offsetParent !== null),
        first = f[0],
        last = f.at(-1);
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last?.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first?.focus();
      }
    }
  });
  async function start() {
    try {
      S.boot = await api("bootstrap");
      const q = new URLSearchParams(location.search);
      if (q.has("q")) S.filter.q = q.get("q");
      if (S.page === "admin") {
        const menu = q.get("page") || "",
          maps = {
            "ascla-miembros": "directorio",
            "ascla-hub": "hub",
            "ascla-eventos": "eventos",
            "ascla-conocimiento": "centro-conocimiento",
            "ascla-galeria": "galeria",
            "ascla-aliados": "aliados",
            "ascla-networking": "directorio",
          };
        S.page = maps[menu] || "admin";
        S.adminTab =
          {
            "ascla-ia": "trabajos",
            "ascla-microeventos": "microeventos",
            "ascla-logs": "logs",
            "ascla-integraciones": "configuracion",
            "ascla-configuracion": "configuracion",
          }[menu] || "moderacion";
      }
      shell();
      await render();
      if (q.get("item")) await item(Number(q.get("item")));
      if (q.get("member")) await member(Number(q.get("member")));
    } catch (e) {
      root.innerHTML = `<div class="error">${E(e.message)} Recarga la página para renovar tu sesión.</div>`;
    }
  }
  start();
})();
