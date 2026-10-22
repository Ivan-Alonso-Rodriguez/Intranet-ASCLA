/* ASCLA frontend. WordPress owns authentication and authorization; this UI never grants permissions. */
(() => {
  "use strict";
  const scriptNonce = document.currentScript?.nonce || "";
  const root = document.getElementById("ascla-root");
  if (!root || !globalThis.ASCLA) return;
  const C = globalThis.ASCLA;
  const T = text => C.translations?.[text] || text;
  const THEME_KEY = "ascla-theme";
  const themeMedia = globalThis.matchMedia?.("(prefers-color-scheme: dark)");
  const themeMode = () => {
    try {
      const mode = localStorage.getItem(THEME_KEY);
      return ["light", "dark", "system"].includes(mode) ? mode : "system";
    } catch { return "system"; }
  };
  function effectiveTheme(mode = themeMode()) {
    if (mode !== "system") return mode;
    return themeMedia?.matches ? "dark" : "light";
  }
  function applyTheme(mode = themeMode(), persist = false) {
    if (!["light", "dark", "system"].includes(mode)) mode = "system";
    if (persist) { try { localStorage.setItem(THEME_KEY, mode); } catch {} }
    const active = effectiveTheme(mode);
    document.documentElement.dataset.asclaTheme = active;
    document.documentElement.dataset.asclaThemeMode = mode;
    document.documentElement.style.colorScheme = active;
    const button = root.querySelector('[data-action="theme-menu"]');
    if (button) {
      const labels = { system: T("Automático (sistema)"), light: T("Claro"), dark: T("Oscuro") };
      button.setAttribute("aria-label", `${T("Apariencia")}: ${labels[mode]}`);
      button.setAttribute("title", `${T("Apariencia")}: ${labels[mode]}`);
      button.dataset.theme = active;
    }
  }
  function themeDialog() {
    const current = themeMode();
    const option = (mode, title, description) => `<button type="button" class="theme-choice ${current === mode ? "selected" : ""}" data-action="theme-set" data-theme="${mode}" aria-pressed="${current === mode}"><span class="theme-choice-title">${E(T(title))}</span><span>${E(T(description))}</span></button>`;
    modal(T("Apariencia"), `<p class="private-note">${E(T("Elige cómo quieres ver la intranet. En Automático, ASCLA sigue la preferencia de tu dispositivo."))}</p><div class="theme-options">${option("system", "Automático (sistema)", "Usa el modo claro u oscuro configurado en tu dispositivo.")}${option("light", "Claro", "Mantiene la interfaz clara en este navegador.")}${option("dark", "Oscuro", "Mantiene la interfaz oscura en este navegador.")}</div>`);
  }
  // Translate interface labels only; source content and user input keep their original language.
  const labelHTML = label => String(label).replaceAll(/(^|>)([^<>]+)(?=<|$)/g, (all, prefix, text) => {
    const trimmed = text.trim();return prefix + text.replace(trimmed, T(trimmed));
  });
  let navigation;
  const S = {
    boot: null,
    viewVersion: 0,
    controller: new AbortController(),
    page: C.page,
    filter: {},
    list: null,
    conversation: 0,
    conversations: [],
    connectionTab: "",
    connectionData: null,
    connectionVersion: 0,
    messageTab: "chats",
    profileTab: "info",
    groupChat: null,
    groupEdit: null,
    calendar: new Date(),
    adminTab: "resumen",
    adminFilters: {users: {}, contacts: {}},
    pendingUnsavedResolver: null,
    pendingUnsavedSaveForm: null,
    modalUnsavedForm: null,
    poll: null,
    noticeFilter: "all",
    noticePage: 1,
    assistantThread: "",
    assistantVersion: 0,
    locations: null,
    locationTimer: null,
    liveToastCursor: 0,
    liveToastTimer: null,
    liveToastPolling: false,
  };
  let liveToasts = null;
  const ASSISTANT_THREAD_PREFIX = "ascla-assistant-thread-";
  function secureRandomId() {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
    if (globalThis.crypto?.getRandomValues) {
      const bytes = new Uint8Array(16);
      globalThis.crypto.getRandomValues(bytes);
      return Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
    }
    throw new Error("No hay un generador criptográfico seguro disponible.");
  }
  function assistantThread(reset = false, forced = "") {
    const key = ASSISTANT_THREAD_PREFIX + String(S.boot?.me?.id || "member");
    // The active tab owns its context, even if browser storage refuses a write.
    let value = forced || (!reset ? S.assistantThread : "");
    if (!value && !reset) { try { value = sessionStorage.getItem(key) || ""; } catch {} }
    if (!value && !reset) { try { value = localStorage.getItem(key) || ""; } catch {} }
    if (!/^[a-zA-Z0-9_-]{6,80}$/.test(value)) {
      const random = secureRandomId();
      value = "chat_" + random.replaceAll(/[^a-zA-Z0-9_-]/g, "");
    }
    S.assistantThread = value;
    try { sessionStorage.setItem(key, value); } catch {}
    try { localStorage.setItem(key, value); } catch {}
    return value;
  }
  const icons = C.icons;
  const I = (name) =>
    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${icons[name] || icons.hub}"/></svg>`;
  const themeIcons = () => `<span class="theme-icon theme-icon-light">${I("sun")}</span><span class="theme-icon theme-icon-dark">${I("moon")}</span>`;
  function adminLanguageControl() {
    const options = Object.entries(C.languageOptions || { es_ES: "Español", en_US: "English" });
    const selected = C.userLocale || (String(C.language).startsWith("en") ? "en_US" : "es_ES");
    return `<form class="header-language admin-language-control" method="post" action="" data-native aria-label="${E(T("Cambiar idioma"))}"><span class="admin-language-icon">${I("globe")}</span><label class="screen-reader-text" for="ascla-admin-language">${E(T("Idioma"))}</label><select id="ascla-admin-language" name="_ascla_locale" data-admin-language-select aria-label="${E(T("Idioma"))}">${options.map(([value,label]) => ("<option value=\"" + (E(value)) + "\" " + (value===selected?"selected":"") + ">" + (E(label)) + "</option>")).join("")}</select><input type="hidden" name="_ascla_change_language" value="1"><input type="hidden" name="_ascla_language_nonce" value="${E(C.languageNonce || "")}"></form>`;
  }
  const E = (v) =>
    String(v ?? "").replaceAll(
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
  const DEFAULT_DATE_OPTIONS = { day: "numeric", month: "short" };
  function date(value, opts = DEFAULT_DATE_OPTIONS) {
    if (!value) return T("Por confirmar");
    let normalized = value;
    if (typeof value === "string" && !/(?:Z|[+-]\d\d:\d\d)$/.test(value)) normalized = value.replace(" ", "T") + "Z";
    return new Date(normalized).toLocaleDateString(C.locale || "es-PE", opts);
  }
  const time = (value) =>
    value
      ? new Date(value).toLocaleTimeString(C.locale || "es-PE", {
          hour: "2-digit",
          minute: "2-digit",
        })
      : "";
  const status = (value) =>
    `<span class="status ${E(value)}">${E(T({ publish: "Publicado", draft: "Borrador", pending: "Pendiente de revisión", private: "Solicitud privada", ascla_rejected: "Rechazado", ascla_hidden: "Oculto", scheduled: "Programado", completed: "Completado", processing: "Procesando", error: "Error", accepted: "Inscrito", invited: "Invitado", waitlisted: "En lista de espera", offered: "Cupo disponible", reconfirm: "Pendiente de reconfirmación", expired: "Oferta vencida", cancelled: "Cancelado", declined: "Rechazado" }[value] || value))}</span>`;
  const initials = (name) =>
    String(name || "AS")
      .split(" ")
      .slice(0, 2)
      .map((x) => x[0])
      .join("");
  const avatar = (p, size = "") => {
    let url = '';
    try { const candidate = new URL(p.photo_url); if (['http:', 'https:'].includes(candidate.protocol)) url = candidate.href; } catch { /* Initials are the shared fallback. */ }
    return `<span class="avatar ${size}">${url ? ("<img src=\"" + (E(url)) + "\" alt=\"" + (E(p.name)) + "\">") : ''}<span class="avatar-initials">${E(initials(p.name))}</span></span>`;
  };
  root.addEventListener('error', event => {
    if (event.target.matches?.('.avatar img')) event.target.remove();
  }, true);
  const btn = (label, action, extra = "", kind = "") =>
    `<button type="button" class="btn ${kind}" data-action="${action}" ${extra}>${labelHTML(label)}</button>`;
  const link = (page, label, kind = "") =>
    `<a class="btn ${kind}" href="${E(C.pages[page]?.url || "#")}">${labelHTML(label)}</a>`;
  const empty = (title, text = "") =>
    `<div class="empty">${I("users")}<strong>${E(T(title))}</strong><p>${E(T(text))}</p></div>`;
  const UI = globalThis.ASCLAContent({ escape: E, icon: I, config: C, T });
  function apiURL(path) {
    const url = new URL(C.api, location.href);
    const [route, query = ""] = path.split("?", 2);
    if (url.searchParams.has("rest_route")) {
      url.searchParams.set("rest_route", url.searchParams.get("rest_route") + route);
    } else {
      url.pathname += route;
    }
    const params = new URLSearchParams(query);
    for (const key of new Set(params.keys())) url.searchParams.delete(key);
    params.forEach((value, key) => url.searchParams.append(key, value));
    return url.href;
  }
  let youtubeIframeApiPromise = null;
  function youtubeIframeApi() {
    if (globalThis.YT?.Player) return Promise.resolve(globalThis.YT);
    if (!youtubeIframeApiPromise) {
      youtubeIframeApiPromise = requestYouTubeIframeApi().catch(error => {
        youtubeIframeApiPromise = null;
        throw error;
      });
    }
    return youtubeIframeApiPromise;
  }
  function requestYouTubeIframeApi() {
    return new Promise((resolve, reject) => {
      const previous = globalThis.onYouTubeIframeAPIReady;
      let settled = false, timer;
      let script = document.querySelector('script[data-ascla-youtube-api]');
      const settle = (error) => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        if (globalThis.onYouTubeIframeAPIReady === finish) globalThis.onYouTubeIframeAPIReady = previous;
        if (script) script.onerror = null;
        if (error) {
          script?.remove();
          reject(error);
        } else resolve(globalThis.YT);
      };
      const finish = () => {
        if (settled) return;
        try { if (typeof previous === "function") previous(); } catch { /* Preserve other integrations without blocking this loader. */ }
        settle(globalThis.YT?.Player ? null : new Error(T("No se pudo cargar el reproductor de YouTube.")));
      };
      globalThis.onYouTubeIframeAPIReady = finish;
      timer = setTimeout(() => {
        if (globalThis.YT?.Player) finish();
        else settle(new Error(T("YouTube tardó demasiado en responder.")));
      }, 12000);
      if (!script) {
        script = document.createElement("script");
        // Unversioned official loader: a fixed SRI hash would block upstream updates.
        // Keep this HTTPS URL literal; CSP and integration notes: MANUAL_QA_UAT.md.
        script.src = "https://www.youtube.com/iframe_api";
        if (scriptNonce) script.nonce = scriptNonce;
        script.referrerPolicy = "strict-origin-when-cross-origin";
        script.async = true;
        script.dataset.asclaYoutubeApi = "1";
        script.onerror = () => settle(new Error(T("No se pudo cargar el reproductor de YouTube.")));
        document.head.appendChild(script);
      }
    });
  }
  async function detectYouTubeDuration(videoId) {
    if (!/^[A-Za-z0-9_-]{11}$/.test(String(videoId || ""))) throw new Error(T("Video de YouTube no válido."));
    const YT = await youtubeIframeApi();
    return new Promise((resolve, reject) => {
      const host = document.createElement("iframe");
      host.className = "youtube-duration-probe";
      host.setAttribute("credentialless", "");
      host.title = "YouTube";
      host.tabIndex = -1;
      host.setAttribute("aria-hidden", "true");
      host.src = `https://www.youtube-nocookie.com/embed/${encodeURIComponent(videoId)}?enablejsapi=1&controls=0&playsinline=1&origin=${encodeURIComponent(location.origin)}`;
      document.body.appendChild(host);
      let player = null, timer = null, checks = 0, finished = false;
      const cleanup = () => {
        if (timer) clearInterval(timer);
        try { player?.destroy?.(); } catch {}
        host.remove();
      };
      const done = (fn, value) => {
        if (finished) return;
        finished = true;
        cleanup();
        fn(value);
      };
      const poll = () => {
        let duration = 0;
        try { duration = Math.round(Number(player?.getDuration?.() || 0)); } catch {}
        if (duration > 0 && duration <= 604800) return done(resolve, duration);
        if (++checks >= 24) done(reject, new Error(T("YouTube no entregó la duración al reproductor.")));
      };
      try {
        player = new YT.Player(host, {
          width: "2", height: "2", videoId,
          playerVars: { controls: 0, playsinline: 1, rel: 0, origin: location.origin },
          events: {
            onReady: event => {
              try { event.target.mute(); event.target.cueVideoById(videoId); } catch {}
              timer = setInterval(poll, 350);
              poll();
            },
            onError: () => done(reject, new Error(T("YouTube no pudo abrir este video para leer su duración."))),
          },
        });
      } catch (error) { done(reject, error); }
    });
  }
  async function ensureYouTubeDuration(id) {
    const item = await api("items/" + Number(id));
    const videoId = item?.meta?.video_id || "";
    if (!videoId) return null;
    if (Number(item?.meta?.duration_seconds || 0) > 0) return Number(item.meta.duration_seconds);
    const seconds = await detectYouTubeDuration(videoId);
    const saved = await api("items/" + Number(id) + "/video-duration", { duration_seconds: seconds });
    return Number(saved.duration_seconds || seconds);
  }
  const pendingReads = new Map();
  function api(path, body, method) {
    const verb = method || (body ? "POST" : "GET");
    if (verb !== "GET") { pendingReads.clear(); return requestApi(path, body, verb); }
    const key = S.viewVersion + ":" + path;
    if (pendingReads.has(key)) return pendingReads.get(key);
    const request = requestApi(path, body, verb);
    pendingReads.set(key, request);
    const clear = () => { if (pendingReads.get(key) === request) pendingReads.delete(key); };
    request.then(clear, clear);
    return request;
  }
  async function requestApi(path, body, method) {
    const version = S.viewVersion;
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
    if (options.method === "GET") options.signal = S.controller.signal;
    const response = await fetch(apiURL(path), options);
    const data = await response.json();
    if (version !== S.viewVersion) throw new DOMException("La vista cambió", "AbortError");
    if (!response.ok) {
      const error = new Error(T(data.message || "No se pudo completar la solicitud."));
      error.status = response.status; error.code = data.code;
      throw error;
    }
    return data;
  }
  function toast(message) {
    document.querySelector(".toast")?.remove();
    const t = document.createElement("div");
    t.className = "toast feedback-toast";
    t.role = "status";
    t.textContent = T(message);
    (root.querySelector(".ascla-image-editor") || root.querySelector(".modal-decision") || root.querySelector(".modal") || root).append(t);
    setTimeout(() => t.remove(), 5500);
  }
  async function discardTemporaryMedia(id, keepalive = false) {
    id = Number(id) || 0;
    if (!id) return false;
    try {
      const response = await fetch(apiURL(`media/${id}/discard`), {
        method: "POST", credentials: "same-origin", keepalive,
        headers: { "X-WP-Nonce": C.nonce },
      });
      return response.ok;
    } catch { return false; }
  }
  function pendingMediaIds(scope = root) {
    if (!scope?.querySelectorAll) return [];
    return [...new Set([...scope.querySelectorAll("[data-pending-media]")].map(node => Number(node.dataset.pendingMedia || 0)).filter(Boolean))];
  }
  function discardPendingWithin(scope, keepalive = false) {
    const ids = pendingMediaIds(scope);
    scope?.querySelectorAll?.("[data-pending-media]").forEach(node => { delete node.dataset.pendingMedia; });
    ids.forEach(id => { void discardTemporaryMedia(id, keepalive); });
    return ids.length;
  }
  function replacePendingMedia(node, id) {
    if (!node) return;
    const previous = Number(node.dataset.pendingMedia || 0);
    if (previous && previous !== Number(id)) void discardTemporaryMedia(previous);
    if (id) node.dataset.pendingMedia = String(Number(id));
    else delete node.dataset.pendingMedia;
  }
  const modalUnsavedBaselines = new WeakMap();
  let accessibility;
  function modalDecision(title, body) {
    document.querySelector(".modal-decision-backdrop")?.remove();
    const div = document.createElement("div");
    div.className = "modal-backdrop modal-decision-backdrop";
    div.innerHTML = `<section role="alertdialog" aria-modal="true" aria-label="${E(title)}" class="modal modal-decision"><div class="modal-top"><h2>${E(title)}</h2>${btn(I("close"), "modal-unsaved-stay", ("aria-label=\"" + (E(T("Seguir editando"))) + "\""), "ghost")}</div><div class="modal-content">${body}</div></section>`;
    root.append(div);
    accessibility?.refresh();
    div.addEventListener("click", event => { if (event.target === div) closeModalDecision(); });
    div.querySelector("button")?.focus();
  }
  function closeModalDecision() {
    document.querySelector(".modal-decision-backdrop")?.remove();
    accessibility?.refresh();
    S.modalUnsavedForm?.querySelector("button,input,textarea,select")?.focus();
  }
  function modalHasUnsavedChanges(form) {
    if (!form?.isConnected || !modalUnsavedBaselines.has(form)) return false;
    return modalUnsavedBaselines.get(form) !== formSnapshot(form);
  }
  function canSaveContentAsDraft(form) {
    return form?.dataset.form === "editor" && form.dataset.type !== "contact"
      && (!Number(form.dataset.id) || S.edit?.status === "draft");
  }
  function requestModalClose() {
    const backdrop = [...document.querySelectorAll(".modal-backdrop")].find(node => !node.classList.contains("modal-decision-backdrop"));
    const form = backdrop?.querySelector("form[data-guard-modal-unsaved]");
    if (!modalHasUnsavedChanges(form)) { closeModal(); return; }
    S.modalUnsavedForm = form;
    const saveDraft = canSaveContentAsDraft(form);
    const question = saveDraft ? "Puedes guardar el progreso como borrador antes de salir." : "¿Quieres guardar los cambios antes de cerrar esta ventana?";
    const actions = saveDraft
      ? `${btn(T("Seguir editando"), "modal-unsaved-stay")}${btn(I("check")+" "+T("Guardar como borrador"), "modal-unsaved-save", "", "primary")}${btn(T("Salir sin guardar"), "modal-unsaved-discard", "", "danger")}`
      : `${btn(T("Seguir editando"), "modal-unsaved-stay")}${btn(T("Descartar cambios"), "modal-unsaved-discard", "", "danger")}${btn(I("check")+" "+T("Guardar cambios"), "modal-unsaved-save", "", "primary")}`;
    modalDecision(T("Cambios sin guardar"), `<p class="detail-body">${E(T("Has realizado cambios que todavía no se han guardado."))}</p><p class="private-note">${E(T(question))}</p><div class="form-actions">${actions}</div>`);
  }
  function modal(title, body, wide = false) {
    closeModal();
    S.focus = S.actionFocus?.isConnected ? S.actionFocus : document.activeElement;
    const div = document.createElement("div");
    div.className = "modal-backdrop";
    div.innerHTML = `<section role="dialog" aria-modal="true" aria-label="${E(title)}" class="modal ${wide ? "wide" : ""}"><div class="modal-top"><h2>${E(title)}</h2>${btn(I("close"), "close", ("aria-label=\"" + (E(T("Cerrar"))) + "\""), "ghost")}</div><div class="modal-content">${body}</div></section>`;
    root.append(div);
    accessibility?.refresh();
    const guarded = div.querySelector("form[data-guard-modal-unsaved]");
    if (guarded) modalUnsavedBaselines.set(guarded, formSnapshot(guarded));
    div.addEventListener("click", (event) => {
      if (event.target === div) requestModalClose();
    });
    div.querySelector("button,input")?.focus();
    return div;
  }
  function closeModal() {
    const focus = S.focus;
    S.focus = null;
    const pending = S.pendingUnsavedResolver;
    S.pendingUnsavedResolver = null;
    S.pendingUnsavedSaveForm = null;
    S.modalUnsavedForm = null;
    document.querySelectorAll(".modal-backdrop").forEach(node => { discardPendingWithin(node); node.remove(); });
    accessibility?.refresh();
    if (focus?.isConnected) focus.focus();
    if (pending) pending(false);
  }
  let fieldSequence = 0;
  const requiredTextErrors = new WeakSet();
  const requiredMark = () => '<span class="required-mark" aria-hidden="true"> *</span>';
  const requiredLabel = (label, extra) => E(T(label)) + (/\brequired\b/.test(extra) ? requiredMark() : '');
  function field(name, label, value = "", type = "text", extra = "") {
    if (['linkedin','twitter'].includes(name)) extra += ' data-social-link placeholder="https://'+(name==='linkedin'?'www.linkedin.com/in/tu-perfil':'x.com/tu-usuario')+'"';
    const id = `f-${E(name)}-${++fieldSequence}`;
    const bounds = globalThis.ASCLA_FORM_LIMITS?.integers[name];
    const max = globalThis.ASCLA_FORM_LIMITS?.text[name];
    if (type === "number" && bounds) extra = extra.replace(/\b(?:min|max|step)="[^"]*"/g, "") + ` min="${bounds[0]}" max="${bounds[1]}" step="1"`;
    if (["text", "textarea", "url", "email", "password"].includes(type) && max && !/\bmaxlength=/.test(extra)) extra += ` maxlength="${max}"`;
    return `<div class="field"><label for="${id}">${requiredLabel(label, extra)}</label>${type === "textarea" ? ("<textarea id=\"" + (id) + "\" name=\"" + (E(name)) + "\" " + (extra) + ">" + (E(value)) + "</textarea>") : ("<input id=\"" + (id) + "\" name=\"" + (E(name)) + "\" type=\"" + (E(type)) + "\" value=\"" + (E(value)) + "\" " + (extra) + ">")}</div>`;
  }
  const check = (name, label, value) =>
    `<label class="check"><input type="checkbox" name="${E(name)}" ${value ? "checked" : ""}> <span>${E(T(label))}</span></label>`;
  const select = (name, label, values, value = "", extra = "") => {
    const id = `f-${E(name)}-${++fieldSequence}`;
    return `<div class="field"><label for="${id}">${requiredLabel(label, extra)}</label><select name="${E(name)}" id="${id}" ${extra}>${values
      .map((v) => {
        const pair = Array.isArray(v) ? v : [v, v];
        return ("<option value=\"" + (E(pair[0])) + "\" " + (String(value) === String(pair[0]) ? "selected" : "") + ">" + (E(T(pair[1]))) + "</option>");
      })
      .join("")}</select></div>`;
  };
  function formData(form) {
    return Object.fromEntries(new FormData(form));
  }
  const locationNormalize = value => String(value || "").normalize("NFD").replaceAll(/[\u0300-\u036f]/g, "").toLowerCase().replaceAll(/[^\p{L}\p{N}]+/gu, " ").trim();
  function profileLocationFields(p, optional = false) {
    const countryPlaceholder = E(T("Busca un país o región"));
    const cityPlaceholder = E(T("Busca una ciudad"));
    return `<div class="field location-combobox" data-location-box="country"><label for="f-country">${E(T("País/región"))}${optional ? "" : requiredMark()}</label><input type="hidden" name="country" value="${E(p.country || "")}" data-location-country-value><div class="location-input-wrap"><input id="f-country" type="text" value="${E(p.country || "")}" maxlength="120" autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" data-form-type="other" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="ascla-country-options" placeholder="${countryPlaceholder}" data-location-country>${I("chevron")}</div><div id="ascla-country-options" class="location-suggestions" role="listbox" hidden></div></div><div class="field location-combobox" data-location-box="city"><label for="f-city">${E(T("Ciudad"))}</label><input type="hidden" name="city" value="${E(p.city || "")}" data-location-city-value><div class="location-input-wrap"><input id="f-city" type="text" value="${E(p.city || "")}" maxlength="120" autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" data-form-type="other" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="ascla-city-options" placeholder="${cityPlaceholder}" data-location-city>${I("chevron")}</div><div id="ascla-city-options" class="location-suggestions" role="listbox" hidden></div></div>`;
  }
  function locationCountryMatch(value) {
    const needle = locationNormalize(value);
    return S.locations?.countries?.find(country => [country.code, country.es, country.en].some(name => locationNormalize(name) === needle)) || null;
  }
  function locationCloseMenus(except = null) {
    document.querySelectorAll('.location-combobox').forEach(box => {
      if (box === except) return;
      const menu = box.querySelector('.location-suggestions');
      const input = box.querySelector('[role="combobox"]');
      if (menu) menu.hidden = true;
      if (input) { input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); }
      box.classList.remove('is-open');
    });
  }
  function locationSetActive(box, index) {
    const input = box?.querySelector('[role="combobox"]');
    const options = [...(box?.querySelectorAll('.location-option') || [])];
    if (!input || !options.length) return;
    const bounded = Math.max(0, Math.min(options.length - 1, index));
    options.forEach((option, i) => option.classList.toggle('is-active', i === bounded));
    options[bounded].scrollIntoView({block:'nearest'});
    input.dataset.locationIndex = String(bounded);
    input.setAttribute('aria-activedescendant', options[bounded].id);
  }
  function locationRender(box, items, renderer, emptyText = "") {
    const input = box?.querySelector('[role="combobox"]');
    const menu = box?.querySelector('.location-suggestions');
    if (!box || !input || !menu) return;
    input.dataset.locationIndex = '-1';
    if (!items.length) {
      menu.innerHTML = emptyText ? `<div class="location-empty">${E(T(emptyText))}</div>` : '';
      menu.hidden = !emptyText;
      input.setAttribute('aria-expanded', emptyText ? 'true' : 'false');
      box.classList.toggle('is-open', !!emptyText);
      return;
    }
    menu.innerHTML = items.map((item, i) => renderer(item, i)).join('');
    menu.hidden = false;
    input.setAttribute('aria-expanded', 'true');
    box.classList.add('is-open');
  }
  function countrySuggestions(value = "") {
    const items = S.locations?.countries || [];
    const needle = locationNormalize(value);
    if (!needle) return items.slice(0, 10);
    const begins = [], contains = [];
    for (const country of items) {
      const names = [country.es, country.en, country.code].map(locationNormalize);
      if (names.some(name => name.startsWith(needle))) begins.push(country);
      else if (names.some(name => name.includes(needle))) contains.push(country);
    }
    return [...begins, ...contains].slice(0, 10);
  }
  function renderCountrySuggestions(form) {
    const input = form?.querySelector('[data-location-country]');
    const box = input?.closest('.location-combobox');
    if (!input || !box) return;
    const items = countrySuggestions(input.value);
    locationRender(box, items, (country, i) => {
      const primary = C.language === 'en' ? country.en : country.es;
      const secondary = C.language === 'en' ? country.es : country.en;
      return `<button type="button" class="location-option" role="option" id="${E(box.querySelector('.location-suggestions').id)}-option-${i}" data-country-code="${E(country.code)}" data-country-value="${E(primary)}"><span>${E(primary)}</span>${secondary && secondary !== primary ? ("<small>" + (E(secondary)) + "</small>") : ''}</button>`;
    }, "No encontramos países con ese nombre.");
  }
  function citySuggestionInputs(form) {
    const countryInput = form?.querySelector('[data-location-country]');
    const cityInput = form?.querySelector('[data-location-city]');
    const box = cityInput?.closest('.location-combobox');
    return countryInput && cityInput && box ? { countryInput, cityInput, box } : null;
  }
  function invalidCitySuggestionSearch(country, query, exact) {
    if (!country) return true;
    return exact ? !query : query.length < 2;
  }
  function renderCitySuggestionResult(box, result, country) {
    box.classList.remove('is-loading');
    const items = result.items || [];
    const emptyText = result.available ? "No encontramos ciudades con ese nombre." : "No se pudieron cargar las ciudades en este momento.";
    locationRender(box, items, (city, i) => `<button type="button" class="location-option" role="option" id="${E(box.querySelector('.location-suggestions').id)}-option-${i}" data-city-value="${E(city)}"><span>${E(city)}</span><small>${E(C.language === 'en' ? country.en : country.es)}</small></button>`, emptyText);
  }
  function citySuggestionFailure(box, exact, error) {
    box.classList.remove('is-loading');
    if (error.name === "AbortError") throw error;
    if (!exact) locationRender(box, [], () => '', "No se pudieron cargar las ciudades en este momento.");
    return {available:false, items:[]};
  }
  async function loadCitySuggestions(form, exact = false) {
    const inputs = citySuggestionInputs(form);
    if (!inputs) return null;
    const { countryInput, cityInput, box } = inputs;
    const country = locationCountryMatch(countryInput.value);
    const query = cityInput.value.trim();
    if (invalidCitySuggestionSearch(country, query, exact)) {
      if (!exact) locationRender(box, [], () => '', query && !country ? "Selecciona primero un país." : "");
      return null;
    }
    if (!exact) {
      locationRender(box, [], () => '', "Cargando ciudades…");
      box.classList.add('is-loading');
    }
    try {
      const suffix = exact ? "&exact=1" : "";
      const result = await api(`locations/cities?country=${encodeURIComponent(country.code)}&q=${encodeURIComponent(query)}${suffix}`);
      if (!exact) renderCitySuggestionResult(box, result, country);
      return result;
    } catch (error) {
      return citySuggestionFailure(box, exact, error);
    }
  }
  function locationKeyboard(input, event) {
    const box = input.closest('.location-combobox');
    const options = [...box.querySelectorAll('.location-option')];
    if (event.key === 'Escape') { locationCloseMenus(); return true; }
    if (!options.length || !['ArrowDown','ArrowUp','Enter'].includes(event.key)) return false;
    const current = Number(input.dataset.locationIndex || -1);
    if (event.key === 'ArrowDown') { event.preventDefault(); locationSetActive(box, current < options.length - 1 ? current + 1 : 0); return true; }
    if (event.key === 'ArrowUp') { event.preventDefault(); locationSetActive(box, current > 0 ? current - 1 : options.length - 1); return true; }
    if (event.key === 'Enter' && current >= 0) { event.preventDefault(); options[current].click(); return true; }
    return false;
  }
  function loadCitySuggestionsSilently(form) {
    void loadCitySuggestions(form).catch(() => {});
  }
  function scheduleCitySuggestions(form) {
    clearTimeout(S.locationTimer);
    S.locationTimer = setTimeout(loadCitySuggestionsSilently, 220, form);
  }
  async function setupProfileLocations(form) {
    if (!form) return;
    const countryInput = form.querySelector('[data-location-country]');
    const cityInput = form.querySelector('[data-location-city]');
    const countryValue = form.querySelector('[data-location-country-value]');
    const cityValue = form.querySelector('[data-location-city-value]');
    const countryBox = countryInput?.closest('.location-combobox');
    const cityBox = cityInput?.closest('.location-combobox');
    if (!countryInput || !cityInput || !countryValue || !cityValue || !countryBox || !cityBox) return;
    countryInput.dataset.initial = countryInput.value;
    cityInput.dataset.initial = cityInput.value;
    try {
      const countries = await api("locations/countries");
      S.locations = {countries};
    } catch (error) {
      if (error.name !== "AbortError") toast(T("No se pudieron cargar las sugerencias geográficas. Puedes conservar tu ubicación actual."));
      return;
    }
    const syncCountryState = (clearCity = false) => {
      const country = locationCountryMatch(countryInput.value);
      const previous = countryInput.dataset.selectedCode || '';
      const next = country?.code || '';
      if (clearCity && previous !== next && (previous || next)) {
        cityInput.value = '';
        cityValue.value = '';
        cityInput.dataset.selectedCity = '';
        refreshUnsavedGuard(cityInput);
      }
      countryInput.dataset.selectedCode = next;
      countryValue.value = country ? country.es : '';
      if (!next) cityValue.value = '';
      cityInput.disabled = !next;
      cityInput.placeholder = next ? T("Busca una ciudad") : T("Selecciona primero un país");
      if (!next) locationCloseMenus();
      return country;
    };
    const initialCountry = locationCountryMatch(countryInput.value);
    countryInput.dataset.selectedCode = initialCountry?.code || '';
    cityInput.disabled = !initialCountry;
    cityInput.placeholder = initialCountry ? T("Busca una ciudad") : T("Selecciona primero un país");

    countryInput.addEventListener('focus', () => { locationCloseMenus(countryBox); renderCountrySuggestions(form); });
    countryInput.addEventListener('input', () => { syncCountryState(true); locationCloseMenus(countryBox); renderCountrySuggestions(form); });
    countryInput.addEventListener('keydown', event => locationKeyboard(countryInput, event));
    countryBox.querySelector('.location-suggestions')?.addEventListener('mousedown', event => event.preventDefault());
    countryBox.addEventListener('click', event => {
      const option = event.target.closest('[data-country-value]');
      if (!option) return;
      const previous = countryInput.dataset.selectedCode || '';
      countryInput.value = option.dataset.countryValue || '';
      countryInput.dataset.selectedCode = option.dataset.countryCode || '';
      const selectedCountry = locationCountryMatch(countryInput.value);
      countryValue.value = selectedCountry?.es || countryInput.value;
      if (previous && previous !== countryInput.dataset.selectedCode) { cityInput.value = ''; cityValue.value = ''; }
      cityInput.disabled = false;
      cityInput.placeholder = T("Busca una ciudad");
      refreshUnsavedGuard(countryInput); refreshUnsavedGuard(cityInput);
      locationCloseMenus();
    });

    cityInput.addEventListener('focus', () => {
      locationCloseMenus(cityBox);
      if (cityInput.value.trim().length >= 2) loadCitySuggestions(form).catch(() => {});
    });
    cityInput.addEventListener('input', () => {
      cityInput.dataset.selectedCity = '';
      cityValue.value = '';
      scheduleCitySuggestions(form);
    });
    cityInput.addEventListener('keydown', event => locationKeyboard(cityInput, event));
    cityBox.querySelector('.location-suggestions')?.addEventListener('mousedown', event => event.preventDefault());
    cityBox.addEventListener('click', event => {
      const option = event.target.closest('[data-city-value]');
      if (!option) return;
      cityInput.value = option.dataset.cityValue || '';
      cityValue.value = cityInput.value;
      cityInput.dataset.selectedCity = cityInput.value;
      refreshUnsavedGuard(cityInput);
      locationCloseMenus();
    });
    if (!S.locationOutsideBound) {
      document.addEventListener('mousedown', event => { if (!event.target.closest('.location-combobox')) locationCloseMenus(); });
      S.locationOutsideBound = true;
    }
  }
  async function validateProfileLocations(form) {
    if (!S.locations?.countries?.length) return;
    const countryInput = form.querySelector('[data-location-country]');
    const cityInput = form.querySelector('[data-location-city]');
    const countryValue = form.querySelector('[data-location-country-value]');
    const cityValue = form.querySelector('[data-location-city-value]');
    if (!countryInput || !cityInput || !countryValue || !cityValue) return;
    const countryChanged = countryInput.value.trim() !== String(countryInput.dataset.initial || "").trim();
    const cityChanged = cityInput.value.trim() !== String(cityInput.dataset.initial || "").trim();
    const country = locationCountryMatch(countryInput.value);
    if (countryInput.value.trim() && !country && countryChanged) {
      countryInput.focus();
      throw new Error(T("Selecciona un país de la lista de sugerencias."));
    }
    countryValue.value = country ? country.es : (countryChanged ? '' : countryInput.value.trim());
    if (!countryInput.value.trim()) cityValue.value = '';
    if (cityInput.value.trim() && country && (cityChanged || countryChanged)) {
      const result = await loadCitySuggestions(form, true);
      if (result?.available && !result.exact) {
        cityInput.focus();
        throw new Error(T("Selecciona una ciudad real de las sugerencias para el país elegido."));
      }
      cityValue.value = '';
      if (result?.available === false || result?.exact) cityValue.value = cityInput.value.trim();
    } else if (!cityInput.value.trim()) {
      cityValue.value = '';
    }
  }
  function maybeBirthdayGreeting() {
    const birthday = S.boot?.birthday;
    if (!birthday?.today) return false;
    const key = `ascla-birthday-${S.boot.me.id}-${birthday.date || new Date().toISOString().slice(0,10)}`;
    try {
      if (localStorage.getItem(key)) return false;
      localStorage.setItem(key, "1");
    } catch {}
    modal(T("¡Feliz cumpleaños! 🎉"), `<div class="birthday-celebration"><div class="birthday-confetti" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div><div class="birthday-emblem">🎂</div><h3>${E(T("Hoy celebramos contigo"))}, ${E(birthday.first_name || S.boot.me.first_name || S.boot.me.name)}.</h3><p>${E(T("Todo ASCLA te desea un gran día y un nuevo año lleno de buenas conexiones, aprendizajes y proyectos."))}</p><div class="birthday-signature">— ASCLA</div></div><div class="form-actions"><button type="button" class="btn primary" data-action="close">${E(T("¡Gracias!"))}</button></div>`);
    return true;
  }
  function maybeProfileCompletionNudge() {
    const review = S.boot?.profile_review;
    if (!review?.due || S.page !== "intranet") return;
    const completion = S.boot.profile_completion;
    const title = review.incomplete ? T("Completa tu perfil") : T("Revisa tu perfil");
    const copy = review.incomplete ? T("Completa los criterios de tu perfil profesional. Puedes posponer este aviso y continuar navegando.") : T("Confirma que tu información profesional sigue vigente o actualízala.");
    modal(title, '<p>'+E(copy)+'</p>'+ (review.incomplete ? '<p>'+E(completion.missing.join(", "))+'</p>' : '') + '<div class="form-actions">'+btn(T("Ahora no"),"profile-nudge-later")+btn(T("Actualizar mi perfil"),"profile-nudge-go")+(review.incomplete ? '' : btn(T("Mi información sigue vigente"),"profile-review-confirm","","primary"))+'</div>');
  }
  const unsavedGuard = { form: null, baseline: "", dirty: false };
  function formSnapshot(form) {
    if (!form) return "";
    const values = [...form.elements].map((element) => {
      if (!element.name || element.disabled || ["button", "submit", "reset", "file"].includes(element.type)) return null;
      if (element.type === "checkbox" || element.type === "radio") return [element.name, element.value, element.checked];
      if (element.tagName === "SELECT" && element.multiple) return [element.name, [...element.selectedOptions].map((option) => option.value)];
      return [element.name, element.value];
    }).filter(Boolean);
    return JSON.stringify([values, [...form.querySelectorAll("[data-media]")].map(node => node.dataset.media)]);
  }
  function registerUnsavedForm(form) {
    unsavedGuard.form = form || null;
    unsavedGuard.baseline = formSnapshot(form);
    unsavedGuard.dirty = false;
  }
  function refreshUnsavedGuard(target = null) {
    const form = target?.closest?.('form[data-guard-unsaved]') || unsavedGuard.form;
    if (!form || form !== unsavedGuard.form || !form.isConnected) return;
    unsavedGuard.dirty = formSnapshot(form) !== unsavedGuard.baseline;
  }
  function clearUnsavedGuard(form = unsavedGuard.form) {
    if (form && form === unsavedGuard.form) {
      unsavedGuard.baseline = formSnapshot(form);
      unsavedGuard.dirty = false;
    } else if (!form) {
      unsavedGuard.form = null; unsavedGuard.baseline = ""; unsavedGuard.dirty = false;
    }
  }
  function hasUnsavedChanges() {
    if (!unsavedGuard.form?.isConnected) { clearUnsavedGuard(null); return false; }
    refreshUnsavedGuard();
    return unsavedGuard.dirty;
  }
  function confirmUnsavedExit() {
    if (!hasUnsavedChanges()) return Promise.resolve(true);
    if (S.pendingUnsavedResolver) return Promise.resolve(false);
    return new Promise(resolve => {
      modal(T("Cambios sin guardar"), `<p class="detail-body">${E(T("Has realizado cambios que todavía no se han guardado."))}</p><p class="private-note">${E(T("¿Quieres guardar los cambios antes de salir?"))}</p><div class="form-actions">${btn(T("Seguir editando"), "unsaved-stay")}${btn(T("Descartar cambios"), "unsaved-discard", "", "danger")}${btn(I("check")+" "+T("Guardar cambios y salir"), "unsaved-save", "", "primary")}</div>`);
      S.pendingUnsavedResolver = resolve;
    });
  }
  function resolveUnsavedExit(leave) {
    const resolve = S.pendingUnsavedResolver;
    S.pendingUnsavedResolver = null;
    S.pendingUnsavedSaveForm = null;
    document.querySelector(".modal-backdrop")?.remove();
    S.focus?.focus();
    if (leave) { discardPendingWithin(content()); clearUnsavedGuard(null); }
    if (resolve) resolve(!!leave);
  }
  const reportReasons = [
    ["harassment", "Acoso"],
    ["fraud", "Fraude o estafa"],
    ["spam", "Mensaje no deseado (spam)"],
    ["false_information", "Información falsa"],
    ["hate", "Incitación al odio"],
    ["violence", "Amenazas o violencia"],
    ["self_harm", "Autolesiones"],
    ["explicit", "Contenido explícito"],
    ["extremism", "Organizaciones extremistas o peligrosas"],
    ["sexual", "Contenido sexual"],
    ["fake_account", "Cuenta falsa"],
    ["child_exploitation", "Explotación infantil"],
    ["restricted_goods", "Bienes y servicios restringidos"],
    ["intimate_images", "Difusión de imágenes íntimas sin consentimiento"],
    ["other", "Otro motivo"],
  ];
  function updateReportDetailRequirement(form) {
    if (form?.dataset.form !== "report") return;
    const detail = form.elements.detail;
    if (!detail) return;
    const required = form.querySelector('input[name="reason"]:checked')?.value === "other";
    detail.required = required;
    detail.setAttribute("aria-required", required ? "true" : "false");
    detail.setCustomValidity(required && detail.value.trim() === "" ? T("Describe brevemente el motivo del reporte.") : "");
    const label = form.querySelector("[data-report-detail-label]");
    if (label) label.textContent = T(required ? "Información adicional (obligatoria)" : "Información adicional (opcional)");
  }
  function reportDialog(id, kind = "content") {
    const isComment = kind === "comment";
    const placeholder = E(T("Describe brevemente el motivo del reporte…"));
    modal(
      T(isComment ? "Denuncia este comentario" : "Denuncia esta publicación"),
      `<form data-form="report" data-kind="${isComment ? 'comment' : 'content'}" data-id="${Number(id)}" class="report-form"><h3>${E(T("Selecciona el motivo de la denuncia"))}</h3><p class="private-note">${E(T("Tu reporte será revisado por el equipo de moderación de ASCLA."))}</p><div class="report-reasons">${reportReasons.map(([value, label]) => ("<label class=\"report-reason\"><input type=\"radio\" name=\"reason\" value=\"" + (E(value)) + "\" required><span>" + (E(T(label))) + "</span></label>")).join("")}</div><div class="field report-detail-field"><label for="f-detail" data-report-detail-label>${E(T("Información adicional (opcional)"))}</label><textarea id="f-detail" name="detail" maxlength="1000" placeholder="${placeholder}"></textarea></div><div class="form-actions"><button type="button" class="btn" data-action="close">${E(T("Cancelar"))}</button><button class="btn primary">${E(T("Enviar reporte"))}</button></div></form>`,
    );
    updateReportDetailRequirement(root.querySelector('.report-form[data-id="' + Number(id) + '"]'));
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
    "perfil",
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
    if (root.querySelector(".ascla-sidebar")) { refreshNotifications(); return; }
    const p = S.boot.me;
    const homeUrl = S.boot.admin ? C.adminUrl : C.pages.intranet.url;
    const links = navOrder.filter(k=>!S.boot.admin||!['directorio','mensajeria','contacto'].includes(k)).map((k) => {const adminHome=S.boot.admin&&k==='intranet';return `<a class="nav-link ${(adminHome?S.page==='admin':S.page===k) ? "active" : ""}" href="${E(adminHome?homeUrl:C.pages[k].url)}">${I(adminHome?'settings':pageIcon[k])}<span>${E(adminHome?T('Administración'):C.pages[k].label)}</span></a>`;}).join("");
    const adminLink = S.boot.admin_area && !S.boot.admin ? `<a class="nav-link" href="${E(C.adminUrl)}">${I("settings")}${E(T("Administración"))}</a>` : "";
    root.innerHTML = `<aside class="ascla-sidebar"><a class="brand" href="${E(homeUrl)}" aria-label="${E(T("ASCLA inicio"))}"><img src="${E(C.logoWhite || C.logo)}" alt="ASCLA"></a><div class="brand-sub">${E(T("COMUNIDAD DE ASOCIADOS"))}</div><nav aria-label="${E(T("Navegación principal"))}">${links}</nav><div class="nav-bottom">${adminLink}<a class="nav-link" href="${E(C.logout)}">${I("logout")}${E(T("Cerrar sesión"))}</a></div></aside><div class="ascla-main"><header class="ascla-header"><a class="header-brand" href="${E(homeUrl)}" aria-label="${E(T("ASCLA inicio"))}"><img class="brand-logo-normal" src="${E(C.logo)}" alt="ASCLA"><img class="brand-logo-inverse" src="${E(C.logoWhite || C.logo)}" alt="" aria-hidden="true"></a>${btn(I("menu"), "menu", ("aria-label=\"" + (E(T("Abrir navegación"))) + "\""), "icon-button mobile-menu")}<form class="header-search" data-form="global-search">${I("search")}<input name="q" aria-label="${E(T("Buscar en ASCLA"))}" placeholder="${E(T("Buscar en tu comunidad…"))}" autocomplete="off"></form><div class="header-right">${S.boot.demo ? '<span class="demo-badge">DEMO MODE</span>' : ""}${C.page === "admin" ? adminLanguageControl() + btn(themeIcons(), "theme-menu", ("aria-label=\"" + (E(T("Apariencia"))) + "\""), "icon-button theme-button") : ""}${btn(I("bell") + '<span class="notification-count" hidden></span>', "notifications", ("aria-label=\"" + (E(T("Notificaciones"))) + "\""), "icon-button")}<a class="header-profile" href="${E(C.pages.perfil.url)}">${avatar(p)}<span><strong>${E(p.name)}</strong><small class="muted">${E(p.member_type || T("Comunidad ASCLA"))}</small></span>${I("chevron")}</a></div></header><main id="main" class="page-wrap" tabindex="-1"><div class="breadcrumb">ASCLA ${I("chevron")} ${E(C.pages[S.page]?.label || T("Administración"))}</div><div id="page-content"></div><div class="demo-footer">© ${new Date().getFullYear()} ASCLA · ${E(T("Conectamos conocimiento, fortalecemos la gobernanza."))}${S.boot.demo ? " · " + E(T("Datos ficticios de demostración.")) : ""}</div></main></div>`;
    refreshNotifications();
  }
  function heading(title, subtitle, action = "") {
    return `<div class="page-heading"><div><h1>${E(T(title))}</h1><p>${E(T(subtitle))}</p></div>${action}</div>`;
  }
  const content = () => document.getElementById("page-content");
  function conversationRequestActions(p) {
    const c = p.connection || {}, r = p.conversation || {}, id = Number(p.id);
    if (c.state === 'connected') return '';
    if (r.state === 'incoming_pending') {
      return `<div class="conversation-request-actions"><span class="connection-state pending">${E(T('Solicitud de conversación recibida'))}</span>${r.conversation_id ? btn('Ver mensaje','conversation-open',("data-conversation=\"" + (Number(r.conversation_id)) + "\""),'small') : ''}${btn('Aceptar conversación','conversation-request-respond',("data-id=\"" + (id) + "\" data-request=\"" + (Number(r.request_id)) + "\" data-decision=\"accept\" " + (r.blocked ? 'disabled' : '') + ""),'primary small')}${btn('Rechazar','conversation-request-respond',("data-id=\"" + (id) + "\" data-request=\"" + (Number(r.request_id)) + "\" data-decision=\"reject\""),'small')}</div>`;
    }
    if (r.state === 'outgoing_pending') {
      return `<div class="conversation-request-actions"><span class="connection-state pending">${E(T('Mensaje enviado · Esperando aceptación'))}</span>${r.conversation_id ? btn('Ver mensaje','conversation-open',("data-conversation=\"" + (Number(r.conversation_id)) + "\""),'small') : ''}${btn(T('Cancelar solicitud'),'conversation-request-cancel',("data-id=\"" + (id) + "\""),'ghost danger small')}</div>`;
    }
    if (r.state === 'allowed') {
      return r.can_message ? `<div class="conversation-request-actions conversation-request-actions-authorized">${btn(I('mail')+' '+T('Enviar mensaje'),'message-start',("data-id=\"" + (id) + "\""),'primary small')}</div>` : '';
    }
    return `<div class="conversation-request-actions">${btn(I('mail')+' '+T('Enviar mensaje'),'conversation-request',("data-id=\"" + (id) + "\" " + (r.can_request ? '' : 'disabled') + ""),'small')}</div>`;
  }
  function connectionPrimaryActions(c, id, suggested, compactConnected) {
    if (c.state === 'incoming_pending') {
      return btn('Aceptar conexión', 'connection-respond', `data-id="${id}" data-request="${c.request_id}" data-decision="accept" ${c.blocked ? 'disabled' : ''}`, 'primary small') + btn('Rechazar solicitud', 'connection-respond', `data-id="${id}" data-request="${c.request_id}" data-decision="reject"`, 'small');
    }
    if (c.state === 'outgoing_pending') {
      return '<div class="connection-pending"><span class="connection-sent">'+I('check')+E(T('Solicitud enviada'))+'</span>'+btn(T('Cancelar'),'connection-remove-request','data-id="'+id+'" data-mode="cancel" aria-label="'+E(T('Cancelar solicitud'))+'"','connection-cancel ghost small')+'</div>';
    }
    if (c.state === 'connected') {
      let actions = compactConnected ? '' : '<span class="connection-state connected">' + I('check') + ' ' + E(T('Conectados')) + '</span>';
      if (c.can_message) actions += btn(I('mail') + ' ' + T('Enviar mensaje'), 'message-start', `data-id="${id}"`, 'primary small') + (suggested ? btn(T('Mensaje sugerido'), 'intro', `data-id="${id}"`, 'small') : '');
      return actions + btn(T('Eliminar conexión'), 'connection-remove-request', `data-id="${id}" data-mode="disconnect"`, 'ghost danger small');
    }
    if (c.retry_at) return '<span class="connection-availability" title="'+E(connectionNote(c))+'">'+I('clock')+E(T('Disponible a partir del'))+' '+E(date(c.retry_at))+'</span>';
    return btn(compactConnected ? 'Conectar' : 'Enviar solicitud de conexión', 'connect', `data-id="${id}" ${c.networking_available ? '' : 'disabled'}`, 'small');
  }
  function connectionNote(c) {
    if (c.blocked) return T('La mensajería está bloqueada entre estas cuentas.');
    if (c.retry_at) return T('Podrás volver a solicitar la conexión a partir de') + ' ' + date(c.retry_at);
    if (c.state === 'none' && !c.networking_available) return T('Ambos asociados deben tener activado networking para conectar.');
    return '';
  }
  function connectionActions(p, suggested = false, compactConnected = false, preventiveBlock = false, separateNote = false) {
    if (p.community_participant===false || S.boot.me.community_participant===false) return '';
    if (Number(p.id) === S.boot.me.id || !p.connection) return '';
    const c = p.connection, id = Number(p.id);
    let actions = connectionPrimaryActions(c, id, suggested, compactConnected);
    if (preventiveBlock || c.blocked_by_me) {
      const active = !c.blocked_by_me;
      actions += btn(active ? 'Bloquear' : 'Desbloquear', 'block', `data-id="${id}" data-active="${active}"`, 'ghost danger small');
    }
    const conversationActions = conversationRequestActions(p);
    const note = connectionNote(c);
    return `<div class="connection-controls" data-member-connection="${id}" data-suggested="${suggested}" data-compact-connected="${compactConnected}" data-preventive-block="${preventiveBlock}" data-separate-note="${separateNote}" data-state="${E(c.state)}"><div class="connection-actions">${actions}</div>${conversationActions}${note && !separateNote ? '<p class="private-note">' + E(note) + '</p>' : ''}</div>`;
  }
  function memberRelationshipState(connection) {
    return connection?.state === 'connected' ? `<span class="connection-state connected compact">${I('check')} ${E(T('Conectados'))}</span>` : '';
  }
  function updateRelationshipState(p) {
    const id = Number(p.id);
    for (const element of root.querySelectorAll(`[data-member-connection="${id}"]`)) {
      const card = element.closest('.member-card');
      const suggested = element.dataset.suggested === 'true';
      const compactConnected = element.dataset.compactConnected === 'true';
      const preventiveBlock = element.dataset.preventiveBlock === 'true';
      element.outerHTML = connectionActions(p, suggested, compactConnected, preventiveBlock, element.dataset.separateNote === 'true');
      if (card) {
        const holder=card.querySelector('.member-card-relationship-state'); if (holder) holder.innerHTML=memberRelationshipState(p.connection);
        const note=card.querySelector('.member-card-note'); if (note) note.textContent=p.connection.retry_at && !p.connection.blocked ? '' : connectionNote(p.connection);
      }
    }
  }
  async function refreshRelationshipState(id) {
    const p = await api('profiles/' + Number(id));
    updateRelationshipState(p);
    return p;
  }
  async function connectionRequestDialog(id) {
    const p = await refreshRelationshipState(id);
    if (!p.connection.can_request) { toast(T('No es posible enviar la solicitud en este momento.')); return; }
    modal(T('Enviar solicitud de conexión'), '<p>'+E(p.name)+'</p><form data-form="connection-request" data-id="'+Number(id)+'">'+field('message','Mensaje de presentación (opcional)','','textarea','maxlength="1000" rows="4"')+'<p class="private-note">'+E(T('Puedes presentarte antes de conectar. Máximo 1000 caracteres.'))+'</p><div class="form-actions">'+btn('Cancelar','close')+'<button class="btn primary" type="submit">'+E(T('Enviar solicitud'))+'</button></div></form>');
  }
  async function submitConnectionRequest(_action, form, data) {
    const id = Number(form.dataset.id);
    await api('relations', {target:id, kind:'connect', active:true, message:data.message || ''});
    closeModal(); await refreshRelationshipState(id); await refreshConnectionsPanel();
    toast(T('Solicitud de conexión enviada.'));
  }
  function connectionMenuItem(label, action, extra, danger = false) {
    return `<button type="button" class="overflow-item${danger ? ' danger-text' : ''}" data-action="${action}" ${extra}>${E(T(label))}</button>`;
  }
  function connectionRequestRowButtons(p) {
    const id = Number(p.id), c = p.connection || {}, r = p.conversation || {}, target = `data-id="${id}"`;
    const incoming = c.state === 'incoming_pending';
    const disabled = c.blocked ? 'disabled' : '';
    const primary = incoming
      ? btn('Aceptar','connection-respond',`${target} data-request="${Number(c.request_id)}" data-decision="accept" aria-label="${E(T('Aceptar conexión')+' · '+p.name)}" ${disabled}`,'primary small') + btn('Rechazar','connection-respond',`${target} data-request="${Number(c.request_id)}" data-decision="reject" aria-label="${E(T('Rechazar solicitud')+' · '+p.name)}"`,'small')
      : `<span class="connection-waiting">${I('clock')}${E(T('Pendiente'))}</span>`;
    let more = incoming ? '' : connectionMenuItem('Cancelar solicitud','connection-remove-request',`${target} data-mode="cancel"`,true);
    if (r.can_message) more += connectionMenuItem('Enviar mensaje','message-start',target);
    else if (r.can_request) more += connectionMenuItem('Enviar mensaje','conversation-request',target);
    else if (r.conversation_id) more += connectionMenuItem('Ver mensaje','conversation-open',`data-conversation="${Number(r.conversation_id)}"`);
    return {primary,more};
  }
  function conversationRequestRowButtons(p) {
    const id = Number(p.id), r = p.conversation || {}, target = `data-id="${id}"`;
    let primary = '', more = '';
    if (r.state === 'incoming_pending') {
      primary = btn('Aceptar','conversation-request-respond',`${target} data-request="${Number(r.request_id)}" data-decision="accept" aria-label="${E(T('Aceptar conversación')+' · '+p.name)}" ${r.blocked ? 'disabled' : ''}`,'primary small') + btn('Rechazar','conversation-request-respond',`${target} data-request="${Number(r.request_id)}" data-decision="reject" aria-label="${E(T('Rechazar solicitud')+' · '+p.name)}"`,'small');
    } else {
      more = connectionMenuItem('Cancelar solicitud','conversation-request-cancel',target,true);
    }
    if (r.conversation_id) primary += btn('Ver mensaje','conversation-open',`data-conversation="${Number(r.conversation_id)}"`,'ghost small');
    return {primary,more};
  }
  function connectionRowActions(p, kind) {
    const id = Number(p.id), c = p.connection || {}, target = `data-id="${id}"`;
    let buttons;
    if (kind === 'blocked') buttons = {primary:btn('Desbloquear','block',`${target} data-active="false"`,'small'),more:''};
    else if (kind === 'conversation') buttons = conversationRequestRowButtons(p);
    else if (c.state === 'connected') buttons = {
      primary:c.can_message ? btn(I('mail')+' '+T('Enviar mensaje'),'message-start',target,'small') : '',
      more:connectionMenuItem('Eliminar conexión','connection-remove-request',`${target} data-mode="disconnect"`,true),
    };
    else buttons = connectionRequestRowButtons(p);
    const menu = buttons.more ? `<details class="overflow-menu connection-row-menu"><summary aria-label="${E(T('Más opciones')+' · '+p.name)}" title="${E(T('Más opciones'))}">${I('more')}</summary><div class="overflow-popover">${buttons.more}</div></details>` : '';
    return `<div class="connection-row-actions">${buttons.primary}${menu}</div>`;
  }
  function connectionRow(p, kind = 'connection') {
    const introduction = kind === 'conversation' ? p.conversation?.initial_message : p.connection?.initial_message;
    const preview = introduction ? `<details class="connection-introduction"><summary>${I('hub')}${E(T('Mensaje de presentación'))}${I('chevron')}</summary><p>${E(introduction)}</p></details>` : '';
    const confirmed = p.connection?.state === 'connected' && p.connection.connected_at
      ? `<span class="connection-confirmed-date">${I('calendar')}<span>${E(T('Conexión confirmada el'))} <time datetime="${E(p.connection.connected_at)}">${E(date(p.connection.connected_at,{day:'numeric',month:'long',year:'numeric'}))}</time></span></span>` : '';
    const professional = [p.position,p.company].filter(Boolean).map(E).join(' · ') || E(T('Comunidad ASCLA'));
    const name = p.profile_url ? `<button type="button" class="connection-name" data-action="member" data-id="${Number(p.id)}" aria-label="${E(T('Ver perfil')+' · '+p.name)}">${E(p.name)}</button>` : `<strong>${E(p.name)}</strong>`;
    return `<article class="connection-row" data-connection-row="${Number(p.id)}" data-connection-name="${E(p.name)}"><div class="connection-person">${avatar(p)}<div class="connection-person-copy">${name}<p class="connection-description">${professional}</p>${confirmed}${preview}</div></div>${connectionRowActions(p,kind)}</article>`;
  }
  function connectionsEmpty(text) {
    return `<div class="connections-empty">${I('users')}<p>${E(T(text))}</p></div>`;
  }
  function confirmedConnections(people) {
    if (!people.length) return connectionsEmpty('No tienes conexiones confirmadas.');
    return `<div class="confirmed-connections"><div class="field connections-search"><label for="connection-name-filter">${E(T('Buscar en mis conexiones'))}</label><div>${I('search')}<input type="search" id="connection-name-filter" value="${E(S.connectionFilter || '')}" autocomplete="off" placeholder="${E(T('Nombre del contacto'))}"></div></div><div class="connections-list" data-confirmed-connections>${people.map(p=>connectionRow(p,'connected')).join('')}</div><p class="private-note" data-connections-empty role="status" hidden>${E(T('No hay conexiones que coincidan con ese nombre.'))}</p></div>`;
  }
  function filterConfirmedConnections() {
    const panel = document.querySelector('.confirmed-connections');
    if (!panel) return;
    const query = locationNormalize(S.connectionFilter);
    let visible = 0;
    panel.querySelectorAll('[data-connection-name]').forEach(row => {
      row.hidden = !locationNormalize(row.dataset.connectionName).includes(query);
      if (!row.hidden) visible++;
    });
    panel.querySelector('[data-connections-empty]').hidden = visible > 0;
  }
  const CONNECTION_TABS = [['contacts','Contactos'],['received','Recibidas'],['sent','Enviadas'],['blocked','Bloqueados']];
  function connectionSection(label, people, kind) {
    if (!people.length) return '';
    return `<section class="connections-group"><h3>${E(T(label))}<span>${people.length}</span></h3><div class="connections-list">${people.map(p=>connectionRow(p,kind)).join('')}</div></section>`;
  }
  function connectionTabContent(tab, data, requests) {
    if (tab === 'contacts') return confirmedConnections(data.connected);
    if (tab === 'blocked') return (data.blocked || []).map(p=>connectionRow(p,'blocked')).join('') || connectionsEmpty('No hay asociados bloqueados.');
    const direction = tab === 'received' ? 'incoming' : 'outgoing';
    return connectionSection('Conexiones',data[direction],'connection') + connectionSection('Conversaciones',requests[direction],'conversation') || connectionsEmpty(tab === 'received' ? 'No tienes solicitudes pendientes.' : 'No hay solicitudes enviadas pendientes.');
  }
  function selectConnectionTab(tab, focus = false) {
    if (!CONNECTION_TABS.some(([key])=>key===tab) || !S.connectionData) return;
    const panel = document.getElementById('connections-panel'), target = panel?.querySelector('[data-connections-content]');
    if (!target) return;
    S.connectionTab = tab;
    for (const button of panel.querySelectorAll('[data-action="connection-tab"]')) {
      const selected = button.dataset.tab === tab;
      button.setAttribute('aria-selected',String(selected));button.tabIndex = selected ? 0 : -1;
    }
    target.setAttribute('aria-labelledby','connections-tab-'+tab);
    target.innerHTML = connectionTabContent(tab,S.connectionData.data,S.connectionData.requests);
    filterConfirmedConnections();
    if (focus) panel.querySelector('#connections-tab-'+tab)?.focus();
  }
  async function refreshConnectionsPanel() {
    const panel = document.getElementById('connections-panel'); if (!panel) return;
    const version = ++S.connectionVersion;
    const previousOverview = panel.querySelector('.connections-overview'), restoreFocus = panel.contains(document.activeElement);
    const [data, requests] = await Promise.all([api('connections'), api('conversation-requests')]);
    if (!panel.isConnected || version !== S.connectionVersion) return;
    S.connectionData = {data,requests};
    const pendingCount = data.incoming.length + requests.incoming.length;
    const counts = {contacts:data.connected.length,received:pendingCount,sent:data.outgoing.length+requests.outgoing.length,blocked:(data.blocked || []).length};
    const tab = S.connectionTab || (pendingCount ? 'received' : 'contacts');
    const openOverview = previousOverview ? previousOverview.open : pendingCount > 0 || (innerWidth > 640 && data.connected.length > 0);
    const tabs = CONNECTION_TABS.map(([key,label])=>btn(`${E(T(label))}<span>${counts[key]}</span>`,'connection-tab',`id="connections-tab-${key}" data-tab="${key}" role="tab" aria-selected="${tab===key}" aria-controls="connections-tab-panel" tabindex="${tab===key?0:-1}"`,'connection-tab')).join('');
    const summary = pendingCount ? pendingCount+' '+T('solicitudes recibidas') : data.connected.length+' '+T('confirmadas');
    panel.innerHTML = `<details class="connections-overview" ${openOverview?'open':''}><summary><span>${I('users')}${E(T('Mis conexiones'))}</span><span class="muted">${E(summary)}</span></summary><div class="connections-overview-body"><div class="connections-toolbar"><div class="connections-tabs" role="tablist" aria-label="${E(T('Gestionar mis conexiones'))}">${tabs}</div>${btn(I('clock'),'connections-refresh',("aria-label=\"" + (E(T('Actualizar conexiones'))) + "\" title=\"" + (E(T('Actualizar conexiones'))) + "\""),'ghost small connections-reload')}</div><div class="connections-content" id="connections-tab-panel" data-connections-content role="tabpanel" aria-labelledby="connections-tab-${tab}" tabindex="0"></div></div></details>`;
    selectConnectionTab(tab,restoreFocus && openOverview);
  }
  let relationshipRefreshing = false;
  async function refreshOpenConnection() {
    const element = root.querySelector('.modal [data-member-connection]');
    if (!element || relationshipRefreshing || document.hidden) return;
    relationshipRefreshing = true;
    try {
      const p = await api('profiles/' + element.dataset.memberConnection);
      if (element.isConnected) updateRelationshipState(p);
    } catch { /* The action endpoints still validate the latest state and permissions. */ }
    finally { relationshipRefreshing = false; }
  }
  function confirmConnectionRemoval(id, mode) {
    const cancelling = mode === 'cancel';
    const title = cancelling ? T('Cancelar solicitud de conexión') : T('Eliminar conexión');
    const description = cancelling
      ? T('La solicitud se cancelará y la otra persona dejará de ver la notificación asociada.')
      : T('Dejarán de aparecer como conectados y la mensajería quedará deshabilitada. Podrás consultar el historial. Para volver a conectar deberán transcurrir 30 días.');
    const keep = cancelling ? T('Mantener solicitud') : T('Mantener conexión');
    const remove = cancelling ? T('Cancelar solicitud') : T('Eliminar conexión');
    modal(title, `<p class="detail-body">${E(description)}</p><div class="form-actions">${btn(keep,'close')}${btn(remove,'connection-remove-confirm',("data-id=\"" + (Number(id)) + "\" data-mode=\"" + (E(mode)) + "\""),'danger primary')}</div>`);
  }
  function affinityContext(affinity) {
    if (!affinity) return "";
    const details = [];
    if ((affinity.shared || []).length) details.push(`${E(T("Comparten"))} ${affinity.shared.slice(0, 2).map(E).join(" · ")}`);
    if ((affinity.signals || []).includes("experience")) details.push(E(T("Experiencia relacionada")));
    if ((affinity.signals || []).includes("participation")) details.push(E(T("Actividad en temas similares")));
    return details.length ? `<small class="match-context">${details.slice(0, 2).join(" · ")}</small>` : "";
  }
  function directoryAdminActions(p) {
    if (S.page !== 'directorio' || !S.boot.admin || !p.administration) return '';
    const data = 'data-id="'+Number(p.id)+'"';
    let actions = btn(I('users')+' '+T('Actividad e intereses'),'user-insights',data,'small');
    if (p.administration.can_edit || p.administration.can_suspend || p.administration.can_delete || p.administration.can_record_role_approval) actions += btn(I('settings')+' '+T('Gestionar usuario'),'directory-manage',data,'ghost small');
    return '<div class="directory-admin-actions">'+actions+'</div>';
  }
  async function directoryManage(id) {
    const u = await api('admin/users/'+id);
    const roles = Object.fromEntries(u.roles.map(role=>[role.id,role.name]));
    const card = {...u,roles:u.assigned_roles,name:[u.first_name,u.last_name].filter(Boolean).join(' ')||u.login};
    modal(T('Gestionar usuario'),adminUserCard(card,roles),true);
  }
  async function refreshManagedUsers() {
    if (S.page === 'directorio') return directory();
    return adminUsers(document.getElementById('admin-panel'));
  }
  function memberCard(p) {
    const isMe = Number(p.id) === Number(S.boot.me.id);
    const signal = p.affinity
      ? `<div class="match-pill">${I("spark")}${p.affinity.score}% ${E(T("de afinidad"))}</div>${affinityContext(p.affinity)}`
      : `<div class="tag-row">${(p.terms?.interests || [])
          .slice(0, 2)
          .map((t) => ("<span class=\"tag\">" + (E(t)) + "</span>"))
          .join("")}</div>`;
    const relationship = isMe
      ? `<div class="connection-controls member-own-actions"><div class="connection-actions">${link("perfil", "Editar mi perfil", "small")}</div></div>`
      : connectionActions(p, false, true, false, true);
    const relationshipState = isMe || S.boot.me.community_participant===false || p.community_participant===false ? '' : memberRelationshipState(p.connection);
    const note = !isMe && relationship && (!p.connection.retry_at || p.connection.blocked) ? connectionNote(p.connection) : '';
    return `<article class="card member-card"><div class="member-card-profile">${avatar(p, "lg")}<h3>${E(p.name)}</h3><div class="role">${E(p.position || T("Miembro ASCLA"))}</div><div class="company">${E(p.company || T("Comunidad profesional"))}</div><span class="country">${I("pin")}${E(p.country || T("América Latina"))}</span><div class="member-card-signal">${signal}<span class="member-card-relationship-state">${relationshipState}</span></div></div><p class="member-card-note">${E(note)}</p><div class="member-card-actions">${btn("Ver perfil " + I("arrow"), "member", ("data-id=\"" + (p.id) + "\""), "small")}${directoryAdminActions(p)}${relationship || (S.boot.admin ? "" : '<div class="connection-controls member-action-placeholder" aria-hidden="true"></div>')}</div></article>`;
  }
  function recommendationMemberCard(p) {
    const shared=(p.affinity?.shared || []).slice(0, 4);
    const relationship=connectionActions(p, true, true);
    const company=p.company ? " · " + E(p.company) : "";
    const sharedItems=shared.map(value => "<span>" + E(value) + "</span>").join("");
    const sharedBlock=shared.length ? '<div class="recommendation-shared">' + sharedItems + '</div>' : "";
    const profileData='data-id="' + Number(p.id) + '"';
    return `<article class="card recommendation-person-card"><div class="recommendation-person-top"><div class="recommendation-avatar">${avatar(p, "lg")}<span class="match-score">${Number(p.affinity?.score || 0)}%</span></div><div><span class="recommendation-kicker">${I("spark")} ${E(T("Conexión recomendada"))}</span><h3>${E(p.name)}</h3><p>${E(p.position || T("Miembro ASCLA"))}${company}</p></div></div><div class="recommendation-reason"><strong>${E(T("Por qué podrían conectar"))}</strong><p>${E(p.affinity?.explanation || T("Encontramos señales de afinidad entre sus perfiles profesionales."))}</p>${sharedBlock}</div><div class="recommendation-person-actions">${btn(T("Ver perfil") + " " + I("arrow"), "recommendation-open", profileData, "small")}${relationship}${btn(T("No me interesa"), "recommendation-dismiss", profileData, "ghost small")}</div></article>`;
  }
  function resourceRecommendation(recommendation) {
    if (!recommendation) return "";
    const reasons = recommendation.reasons || [];
    const labels = [];
    if (reasons.some((reason) => ["interest_match", "keyword_interest"].includes(reason))) labels.push(T("Coincide con tus intereses"));
    if (reasons.some((reason) => ["keyword_area", "keyword_industry", "keyword_goal", "professional_match"].includes(reason))) labels.push(T("Relacionado con tu perfil profesional"));
    if (reasons.includes("activity_match")) labels.push(T("Relacionado con tu actividad reciente"));
    if (!labels.length) labels.push(T("Seleccionado para ti"));
    return `<div class="recommendation-context" title="${E(T("Por qué te lo recomendamos"))}">${I("spark")}<span>${E(labels.slice(0, 2).join(" · "))}</span></div>`;
  }
  function resourceCard(p, i = 0) {
    const durationLabel = p.meta.duration_seconds ? E(UI.duration(p.meta.duration_seconds)) : E(T("Duración por confirmar"));
    const resourceIcon = p.meta.resource_type === "Video" ? "play" : "book";
    const cover = p.meta.thumbnail_url
      ? `<a href="${E(p.url)}" class="resource-video-cover"><img class="resource-thumbnail" src="${E(p.meta.thumbnail_url)}" alt="${E(T("Miniatura de"))} ${E(p.title)}" loading="lazy"><span>${I("play")} ${durationLabel}</span></a>`
      : `<a href="${E(p.url)}" class="resource-cover v${i % 3}"><div class="cover-label">${E(T("ASCLA · CONOCIMIENTO"))}</div><strong>${E(p.title.split(":")[0])}</strong><span class="cover-icon">${I(resourceIcon)}</span></a>`;
    const editorialState = p.status === "publish" ? "" : status(p.status);
    return `<article class="card resource-card">${cover}<div class="resource-content"><div class="resource-card-badges"><span class="tag">${E(T(p.meta.resource_type || "Artículo"))}</span>${editorialState}</div>${resourceRecommendation(p.recommendation)}<h3><a href="${E(p.url)}">${E(p.title)}</a></h3><p>${E((p.meta.summary || p.body).slice(0, 115))}${(p.meta.summary || p.body).length > 115 ? "…" : ""}</p><div class="resource-footer"><span>${date(p.date)}</span>${btn("Explorar " + I("arrow"), "item", ("data-id=\"" + (p.id) + "\""), "ghost")}</div></div></article>`;
  }
  function recommendationsHome(result, resources) {
    if (!result.eligible) {
      const disabled=result.reason === "disabled";
      const title=disabled ? "Activa tus recomendaciones" : "Completa tu perfil para descubrir afinidades";
      const description=disabled
        ? "La participación está desactivada. Puedes habilitarla desde Privacidad y participación."
        : "Para cuidar la calidad y privacidad de las coincidencias, necesitamos un perfil profesional mínimo y al menos 70% de completitud.";
      const missing=(result.missing || []).map(value => `<li>${E(T(value))}</li>`).join("");
      const missingList=missing ? "<ul>" + missing + "</ul>" : "";
      return `<section class="home-recommendations" id="recomendaciones" aria-labelledby="home-recommendations-title"><div class="home-recommendations-heading"><div><span class="eyebrow">${E(T("RECOMENDACIONES PARA TI"))}</span><h2 id="home-recommendations-title">${E(T("Conexiones que suman"))}</h2><p>${E(T("Personas y conocimiento según los datos que autorizaste."))}</p></div></div><div class="recommendations-gate card"><span class="recommendations-gate-icon">${I(disabled ? "shield" : "spark")}</span><div><span class="eyebrow">${E(T("TU PRIVACIDAD PRIMERO"))}</span><h3>${E(T(title))}</h3><p>${E(T(description))}</p>${missingList}<div class="recommendations-gate-progress"><span><progress max="100" value="${Math.max(0,Math.min(100,Number(result.completion || 0)))}"></progress></span><strong>${Number(result.completion || 0)}%</strong></div>${link("perfil", T("Revisar mis preferencias") + " " + I("arrow"), "primary")}</div></div></section>`;
    }
    const updated=result.generated_at ? date(result.generated_at,{day:"numeric",month:"long",year:"numeric"}) : T("esta semana");
    const lowMatchCopy=T("Encontramos {count} de hasta {limit} perfiles que cumplen los criterios. Te mostraremos nuevas opciones en la siguiente actualización automática.").replace("{count}",String(result.people.length)).replace("{limit}",String(result.limit));
    const lowMatch=result.low_match ? `<div class="recommendation-low-match" role="status">${I("spark")}<div><strong>${E(T("Pocas coincidencias esta semana"))}</strong><p>${E(lowMatchCopy)}</p></div></div>` : "";
    const people=result.people.length ? result.people.map(recommendationMemberCard).join("") : empty("Aún no hay coincidencias disponibles", "Tus descartes no volverán a aparecer. La próxima actualización buscará nuevas afinidades.");
    const knowledge=resources.items.length ? resources.items.map((item,index)=>resourceCard(item,index)).join("") : empty("Aún no encontramos contenidos afines", "Cuando se publiquen recursos vinculados con tus intereses aparecerán aquí.");
    return `<section class="home-recommendations" id="recomendaciones" aria-labelledby="home-recommendations-title"><div class="home-recommendations-heading"><div><span class="eyebrow">${E(T("RECOMENDACIONES PARA TI"))}</span><h2 id="home-recommendations-title">${E(T("Conexiones que suman"))}</h2><p>${E(T("Hasta cinco perfiles vigentes, ordenados por afinidad y complementariedad. Desliza para ver todas las sugerencias."))}</p></div><div class="recommendations-heading-actions">${link("directorio", T("Ver directorio") + " " + I("arrow"), "ghost")}<div class="recommendation-cycle">${I("clock")}<span><strong>${E(T("Actualización automática semanal"))}</strong>${E(T("Lista generada"))} ${E(updated)} · ${E(T("sin actualización manual"))}</span></div></div></div>${lowMatch}<div class="recommendation-strip" tabindex="0" role="region" aria-label="${E(T("Perfiles recomendados"))}">${people}</div><div class="home-recommendation-group recommendation-content-section"><div class="section-top"><div><span class="eyebrow">${E(T("CONOCIMIENTO"))}</span><h3>${E(T("Materiales seleccionados para ti"))}</h3><p>${E(T("Recursos publicados y autorizados, ordenados por su relación con tus intereses."))}</p></div>${link("centro-conocimiento", T("Ver Centro de Conocimiento") + " " + I("arrow"), "ghost")}</div><div class="recommendation-strip recommendation-content-strip" tabindex="0" role="region" aria-label="${E(T("Materiales recomendados"))}">${knowledge}</div></div></section>`;
  }
  function eventMini(p) {
    const d = new Date(p.meta.start);
    const cancelled = p.meta.cancelled ? ` · ${E(T("Cancelado"))}` : "";
    return `<div class="event-mini"><div class="date-box"><small>${d.toLocaleDateString(C.locale || "es-PE", { month: "short" })}</small><strong>${d.getDate()}</strong></div><div><h3>${E(p.title)}</h3><p>${E(T(p.meta.modality || "Virtual"))} · ${time(p.meta.start)}${cancelled}</p><a href="${E(p.url)}">${E(T("Ver evento"))} ${I("arrow")}</a></div></div>`;
  }
  function calendar(events) {
    const d = S.calendar;
    const first = (new Date(d.getFullYear(), d.getMonth(), 1).getDay() + 6) % 7;
    const days = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
    const today = new Date();
    const eventDays = new Set(Array.from({ length: days }, (_, index) => index + 1).filter(day => {
      const from = new Date(d.getFullYear(), d.getMonth(), day), to = new Date(d.getFullYear(), d.getMonth(), day + 1);
      return events.some(p => new Date(p.meta.start) < to && new Date(p.meta.end) > from);
    }));
    const weekdays = Array.from({length:7},(_,i)=>new Intl.DateTimeFormat(C.locale || "es-PE",{weekday:"narrow"}).format(new Date(2024,0,1+i)));
    const previousAttrs = 'aria-label="' + E(T("Mes anterior")) + '"';
    const nextAttrs = 'aria-label="' + E(T("Mes siguiente")) + '"';
    const weekdayHtml = weekdays.map(x => `<span class="weekday">${E(x)}</span>`).join("");
    const dayHtml = Array.from({ length: days }, (_, i) => {
      const day = i + 1;
      const isToday = today.getFullYear() === d.getFullYear() && today.getMonth() === d.getMonth() && today.getDate() === day;
      let cls = "";
      if (isToday) cls = "today";
      else if (eventDays.has(day)) cls = "event-day";
      const content = eventDays.has(day)
        ? `<button class="calendar-day" data-action="calendar-day" data-day="${day}" aria-label="${E(T("Ver eventos del día"))} ${day}">${day}</button>`
        : day;
      return `<span class="${cls}">${content}</span>`;
    }).join("");
    return `<div class="calendar-head"><strong>${E(d.toLocaleDateString(C.locale || "es-PE", { month: "long", year: "numeric" }))}</strong><span>${btn("‹", "calendar-prev", previousAttrs, "ghost")}${btn("›", "calendar-next", nextAttrs, "ghost")}</span></div><div class="calendar">${weekdayHtml}${"<span></span>".repeat(first)}${dayHtml}</div><div class="calendar-legend"><i></i> ${E(T("Eventos de la comunidad"))}</div>`;
  }
  async function dashboardRecommendations(request, resources, version) {
    try {
      const outcome = await request;
      if (version !== S.viewVersion || S.page !== "intranet") return;
      const target = document.getElementById("dashboard-recommendations");
      if (!target) return;
      if (outcome.error) throw outcome.error;
      target.innerHTML = recommendationsHome(outcome.data, resources);
    } catch (error) {
      if (error.name === "AbortError" || version !== S.viewVersion || S.page !== "intranet") return;
      const target = document.getElementById("dashboard-recommendations");
      if (target) target.innerHTML = `<section class="card"><p role="status">${E(T("No se pudieron cargar las recomendaciones. Puedes seguir usando la comunidad."))}</p>${btn("Reintentar", "refresh", "", "small")}</section>`;
    }
  }
  async function dashboard() {
    const version = S.viewVersion;
    // Optional AI explanations must not hold the rest of the home page open.
    // Handle rejection immediately, including when a different home request fails.
    const recommendations = api("recommendations").then(data => ({ data }), error => ({ error }));
    const [events, resources, hub, directory, notes, monthEvents, recentResources] =
      await Promise.all([
        api("content/event?past=0&status=publish"),
        api("content/resource?recommended=1&per_page=6"),
        api("content/hub"),
        api("profiles"),
        api("notifications/summary"),
        calendarEvents(),
        api("content/resource?status=publish"),
      ]);
    if (version !== S.viewVersion) return;
    S.events = monthEvents;
    const upcoming = events.items
      .filter((p) => new Date(p.meta.end) > new Date())
      .sort((a, b) => new Date(a.meta.start) - new Date(b.meta.start));
    const p = S.boot.me;
    const birthdayToday = !!S.boot?.birthday?.today;
    const heroTitle = birthdayToday ? `${E(T("¡Feliz cumpleaños"))}, ${E(S.boot.birthday.first_name || p.first_name || p.name.split(" ")[0])}! 🎉` : `${E(T("Hola"))} ${E(p.first_name || p.name.split(" ")[0])},<br>${E(T("sigamos conectando ideas."))}`;
    const heroText = birthdayToday ? E(T("Todo ASCLA te desea un gran día y un nuevo año lleno de buenas conexiones, aprendizajes y proyectos.")) : E(T("Un espacio para compartir experiencias, fortalecer tu red y construir el futuro del gobierno corporativo."));
    const hero=`<section class="hero ${birthdayToday ? "hero-birthday" : ""}"><div class="hero-copy"><div class="eyebrow">${E(T(birthdayToday ? "HOY CELEBRAMOS CONTIGO" : "TU COMUNIDAD, MÁS CERCA"))}</div><h1>${heroTitle}</h1><p>${heroText}</p>${link("directorio", "Explorar la comunidad " + I("arrow"), "white small")}</div><svg class="hero-art" viewBox="0 0 300 260" fill="none" aria-hidden="true"><g stroke="#7ac0e5" stroke-width=".7"><circle cx="160" cy="130" r="105"/><circle cx="160" cy="130" r="77"/><ellipse cx="160" cy="130" rx="45" ry="105"/><path d="M55 130h210M80 64l170 134M80 196 247 65M68 80l191 89M70 173l180-89"/></g><g fill="#8dc4e3" stroke="#c7edff" stroke-width="4"><circle cx="90" cy="76" r="9"/><circle cx="236" cy="70" r="7"/><circle cx="160" cy="130" r="11"/><circle cx="87" cy="195" r="7"/><circle cx="244" cy="193" r="10"/><circle cx="181" cy="27" r="5"/></g></svg><div class="hero-tag"><i></i> ${E(T("Una red que crece contigo"))}</div></section>`;
    const stats=`<div class="stat-grid">${[
      [directory.total, "Asociados en la comunidad", "users"],
      [events.total, "Próximos encuentros", "calendar"],
      [recentResources.total, "Recursos para aprender", "book"],
      [notes.unread_total, "Nuevas notificaciones", "bell"],
    ]
      .map(
        ([num, label, icon]) =>
          ("<div class=\"stat\"><span class=\"stat-icon\">" + (I(icon)) + "</span><div><strong>" + (num) + "</strong><small>" + (E(T(label))) + "</small></div></div>"),
      )
      .join(
        "",
      )}</div>`;
    const aside=`<aside class="dashboard-aside"><div><div class="section-top"><h2>${E(T("Tu agenda ASCLA"))}</h2>${I("calendar")}</div><div class="card calendar-card"><div id="calendar-body">${calendar(monthEvents)}</div><div class="dashboard-agenda-upcoming">${upcoming.slice(0, 2).map(eventMini).join("") || ("<p class=\"muted\">" + E(T("Sin próximos eventos.")) + "</p>")}</div></div></div><div class="notice-card">${I("spark")}<h3>${E(T("El conocimiento, a una pregunta"))}</h3><p>${E(T("Encuentra respuestas en los recursos de nuestra comunidad con el Asistente ASCLA."))}</p>${link("asistente", "Hacer una pregunta " + I("arrow"), "ghost small")}</div><div class="notice-card trust-card">${I("shield")}<h3>${E(T("Un espacio de confianza"))}</h3><p>${E(T("Compartimos conocimiento con respeto, confidencialidad y bajo la Regla de Chatham House."))}</p>${btn("Normas de la comunidad " + I("arrow"), "rules", "", "ghost small")}</div></aside>`;
    const recommendationsLayout=`<div class="dashboard-recommendations-layout"><div id="dashboard-recommendations"><section class="card" role="status"><span class="loading-dot"></span> ${E(T("Cargando recomendaciones…"))}</section></div>${aside}</div>`;
    const activity=`<div class="dashboard-activity"><div class="section-top"><h2>${E(T("Conocimiento reciente"))}</h2>${link("centro-conocimiento", "Ver todo " + I("arrow"), "ghost")}</div><div class="cards two">${recentResources.items.slice(0, 2).map(resourceCard).join("") || empty("Tu biblioteca está por comenzar")}</div><div class="section-gap"><div class="section-top"><h2>${E(T("La conversación en nuestra comunidad"))}</h2>${link("hub", "Ir al Hub " + I("arrow"), "ghost")}</div>${hub.items.slice(0, 1).map(feedCard).join("") || empty("Comparte la primera idea")}</div></div>`;
    content().innerHTML=hero+stats+recommendationsLayout+activity;
    void dashboardRecommendations(recommendations, resources, version);
  }
  function commentCountText(count) {
    return `${count} ${T(count === 1 ? "comentario" : "comentarios")}`;
  }
  function syncCommentCount(item) {
    const id = Number(item.id), count = Number(item.comments);
    if (!Number.isSafeInteger(id) || id <= 0 || !Number.isSafeInteger(count) || count < 0) return;
    // Use the authorized server total; the comment list can contain pending entries or be limited.
    root.querySelectorAll(`[data-comment-count="${id}"]`).forEach(node => { node.textContent = commentCountText(count); });
    const cached = S.list?.items?.find(entry => Number(entry.id) === id);
    if (cached) cached.comments = count;
  }
  function feedCard(p) {
    const editorialState = p.status === "publish" ? "" : status(p.status);
    return `<article class="card feed-card" data-content-id="${Number(p.id)}" data-content-type="${E(p.type)}"><div class="feed-top">${avatar({ name: p.author.name })}<div><strong>${E(p.author.name)}</strong><small>${date(p.date)} · Comunidad ASCLA</small></div><span class="feed-editorial-state">${editorialState}</span></div><h3><a href="${E(p.url)}">${E(p.title)}</a></h3><p>${E(p.body.slice(0, 450))}${p.body.length > 450 ? "…" : ""}</p>${UI.attachments(p, true)}${p.tags.length ? ("<div class=\"tag-row\">" + (p.tags.map((t) => ("<span class=\"tag\">" + (E(t.name)) + "</span>")).join("")) + "</div>") : ""}<div class="feed-actions">${btn(I("heart") + " " + p.reactions, "like", ("data-id=\"" + (p.id) + "\" data-active=\"" + (!p.liked) + "\" aria-label=\"" + (E(T("Me gusta") + " " + p.reactions)) + "\""))}${btn(I("hub") + (" <span data-comment-count=\"" + (Number(p.id)) + "\">" + (E(commentCountText(p.comments))) + "</span>"), "item", ("data-id=\"" + (p.id) + "\""))}${btn(I("arrow") + " " + T("Ver conversación"), "item", ("data-id=\"" + (p.id) + "\""))}</div></article>`;
  }
  function pager(list) {
    const perPage = Number(list.per_page || 0);
    const total = Math.max(0, Number(list.total || 0));
    const first = total ? (list.page - 1) * perPage + 1 : 0;
    const last = total ? Math.min(total, first + list.items.length - 1) : 0;
    const summary = perPage ? `<p class="content-pagination-summary">${E(T("Mostrando"))} ${first}–${last} ${E(T("de"))} ${total} · ${perPage} ${E(T("por página"))}</p>` : "";
    if (list.pages <= 1) return summary;
    const previousDisabled = list.page <= 1 ? "disabled" : "";
    const nextDisabled = list.page >= list.pages ? "disabled" : "";
    return `${summary}<nav class="pagination" aria-label="${E(T("Paginación de contenidos"))}">${btn("Anterior", "page", ("data-page=\"" + (list.page - 1) + "\" " + previousDisabled), "small")}<span aria-current="page">${E(T("Página"))} ${list.page} ${E(T("de"))} ${list.pages}</span>${btn("Siguiente", "page", ("data-page=\"" + (list.page + 1) + "\" " + nextDisabled), "small")}</nav>`;
  }
  function directoryPager(list) {
    const page = Math.max(1, Number(list.page || 1));
    const pages = Math.max(1, Number(list.pages || 1));
    const perPage = Number(list.per_page || 12);
    const total = Math.max(0, Number(list.total || 0));
    const startItem = total ? ((page - 1) * perPage) + 1 : 0;
    const endItem = total ? Math.min(total, startItem + Number(list.items?.length || 0) - 1) : 0;
    const pageSet = new Set([1, pages, page - 2, page - 1, page, page + 1, page + 2]);
    const visible = [...pageSet].filter(n => n >= 1 && n <= pages).sort((a,b) => a - b);
    const numeric = [];
    let previous = 0;
    for (const number of visible) {
      if (previous && number - previous > 1) numeric.push('<span class="pagination-ellipsis" aria-hidden="true">…</span>');
      numeric.push(btn(String(number), 'page', `data-page="${number}" ${number === page ? 'aria-current="page" disabled' : ''}`, `small pagination-number${number === page ? ' active' : ''}`));
      previous = number;
    }
    const previousAttrs = `data-page="${page - 1}"` + (page <= 1 ? " disabled" : "");
    const nextAttrs = `data-page="${page + 1}"` + (page >= pages ? " disabled" : "");
    const controls = '<nav class="pagination directory-pagination-numeric" aria-label="' + E(T("Paginación del directorio")) + '">'
      + btn("Anterior", "page", previousAttrs, "small")
      + numeric.join("")
      + btn("Siguiente", "page", nextAttrs, "small")
      + "</nav>";
    return `<div class="directory-pagination"><div class="directory-pagination-summary"><span><strong>${perPage}</strong> ${E(T('por página'))} · ${E(T('Mostrando'))} ${startItem}–${endItem} ${E(T('de'))} ${total}</span></div>${controls}</div>`;
  }
  function directoryQuery(filter = S.filter) {
    const query = new URLSearchParams();
    for (const [key, value] of Object.entries(filter)) {
      if (Array.isArray(value)) value.forEach(item => query.append(key+"[]",item));
      else query.set(key,value);
    }
    return query;
  }
  function directoryTermFilter(name, label, terms, filter = S.filter) {
    const rawSelected=filter[name];
    const selected=new Set((Array.isArray(rawSelected) ? rawSelected : [rawSelected]).filter(value => value != null).map(String));
    const options = terms.map(term => '<label class="directory-option"><input type="checkbox" name="'+name+'" value="'+Number(term.id)+'" '+(selected.has(String(term.id))?'checked':'')+'><span>'+E(term.name)+'</span></label>').join('');
    return '<details class="directory-facet"><summary>'+E(T(label))+' <span class="directory-facet-count" data-facet-count>'+selected.size+'</span>'+I('chevron')+'</summary><div class="directory-facet-options" role="group" aria-label="'+E(T(label))+'">'+options+'</div></details>';
  }
  function directoryFilters() {
    return `<form class="card directory-search" data-form="filters" role="search" aria-label="${E(T('Buscar en el directorio'))}"><div class="directory-search-row"><div class="field"><label for="directory-search">${E(T('Buscar personas'))}</label><input type="search" id="directory-search" name="q" placeholder="${E(T('Nombre, cargo, empresa o experiencia…'))}" value="${E(S.filter.q || '')}"></div><div class="field"><label for="directory-country">${E(T('País'))}</label><input type="search" id="directory-country" name="country" placeholder="${E(T('Todos los países'))}" value="${E(S.filter.country || '')}"></div><button class="btn primary">${I('search')} ${E(T('Buscar'))}</button>${btn('Limpiar filtros','directory-reset','','ghost')}</div><div class="directory-facets">${directoryTermFilter('industries','Industria',S.boot.catalogs.industry)}${directoryTermFilter('interests','Interés',S.boot.catalogs.interest)}${directoryTermFilter('areas','Área de conocimiento',S.boot.catalogs.area)}<span class="private-note">${E(T('Puedes elegir varias opciones en cada filtro.'))}</span></div></form>`;
  }
  async function directory() {
    const list = await api("profiles?" + directoryQuery());
    S.list = list;
    content().innerHTML =
      heading(
        "Tu red profesional",
        "Conecta con quienes comparten tus retos, intereses y conocimientos.",
      ) +
      `<div class="directory-view">${directoryFilters()}${S.boot.me.community_participant===false?'':`<section class="card connections-panel" id="connections-panel" aria-label="${E(T('Mis conexiones'))}"></section>`}<div class="section-top directory-results-heading"><h2>${list.total} ${E(T('perfiles en la comunidad'))}</h2>${link('perfil','Editar mi perfil','ghost small')}</div><p class="directory-visibility-note private-note">${E(T('Se muestran miembros activos que han elegido aparecer en el directorio.'))}</p><div class="cards directory">${list.items.map(memberCard).join('')}</div>${list.items.length ? '' : empty('No encontramos perfiles','Prueba con otro nombre, país o interés.')}${directoryPager(list)}</div>`;
    await refreshConnectionsPanel();
  }
  async function member(id) {
    const dialog = modal(T("Perfil"), `<div class="view-loading" role="status"><span class="loading-dot"></span>${E(T("Cargando perfil…"))}</div>`);
    const other = Number(id) !== S.boot.me.id;
    // Data and deterministic affinity are independent; neither waits for the AI provider.
    const matchRequest = other && S.boot.me.community_participant!==false ? api("matching/" + id + "?explain=0").catch(() => null) : Promise.resolve(null);
    let p;
    try { p = await api("profiles/" + id); }
    catch (error) {
      if (!dialog.isConnected || error.name === "AbortError") return;
      dialog.querySelector(".modal-content").innerHTML = `<p role="alert">${E(error.message)}</p>`;
      return;
    }
    if (!dialog.isConnected) return;
    const suggested = other && p.networking && p.directory && S.boot.me.networking && !p.connection?.blocked;
    const actions = (other ? connectionActions(p, suggested, false, true) : link("perfil", "Editar perfil", "primary")) + directoryAdminActions(p);
    const terms = Object.entries(p.terms || {}).filter(([, v]) => v.length).map(([k, v]) => `<h3>${E(T(profileLabels[k] || k))}</h3><div class="tag-row">${v.map((t) => ("<span class=\"tag\">" + (E(t)) + "</span>")).join("")}</div>`).join("");
    const sources = ["linkedin", "twitter", "website"].filter((k) => p[k]).map((k) => `<a href="${E(safeURL(p[k]))}" target="_blank" rel="noopener noreferrer">${E({ linkedin: "LinkedIn", twitter: "X / Twitter", website: T("Sitio personal") }[k])} ↗</a>`).join("");
    dialog.querySelector(".modal-top h2").textContent = p.name;
    dialog.querySelector('[role="dialog"]').setAttribute("aria-label", p.name);
    const showAffinity = other && p.community_participant!==false && S.boot.me.community_participant!==false;
    const affinitySection = showAffinity
      ? `<div data-profile-affinity aria-live="polite" aria-busy="true"><p class="private-note" role="status">${E(T("Cargando afinidad…"))}</p></div>`
      : "";
    const experienceSection = p.experience
      ? `<h3>${E(T("Experiencia profesional"))}</h3><p class="detail-body">${E(p.experience)}</p>`
      : "";
    const phoneSection = p.phone
      ? `<p><strong>${E(T("Teléfono"))}:</strong> <a href="tel:${E(p.phone.replaceAll(/[^+0-9]/g, ""))}">${E(p.phone)}</a></p>`
      : "";
    dialog.querySelector(".modal-content").innerHTML = `<div class="profile-summary">${avatar(p, "xl")}<div><h2>${E(p.position || T("Miembro ASCLA"))}</h2><p class="muted">${E(p.company || "")}</p><p class="muted">${E(p.country || "")} ${E(p.city || "")}</p><span data-profile-affinity-score></span></div></div>${affinitySection}<p class="detail-body">${E(p.bio || T("Este miembro aún no ha añadido su biografía."))}</p>${experienceSection}${phoneSection}${terms}<div class="sources">${sources}</div><div class="form-actions">${actions}</div>`;
    if (showAffinity) void memberAffinity(dialog, id, matchRequest);
  }
  function affinityDetails(match, pending = false, unavailable = false) {
    return `<div class="alert">${E(match.explanation)}<p class="private-note">${E(match.mode || T("Afinidad determinística"))}</p>${match.conversation_proposal ? ("<p>" + (E(match.conversation_proposal)) + "</p>") : ""}${pending ? ("<p class=\"private-note\" role=\"status\">" + (E(T("Preparando explicación de IA…"))) + "</p>") : ""}${unavailable || match.fallback ? ("<p class=\"private-note\">" + (E(T("La explicación de IA no está disponible ahora. Puedes seguir usando el perfil."))) + "</p>") : ""}</div>`;
  }
  async function memberAffinity(dialog, id, matchRequest) {
    const match = await matchRequest;
    if (!dialog.isConnected) return;
    const block = dialog.querySelector("[data-profile-affinity]");
    if (!match) { block.remove(); return; }
    const score = dialog.querySelector("[data-profile-affinity-score]");
    score.innerHTML = `<span class="match-pill">${I("spark")}${E(match.score)}% ${E(T("de afinidad"))}</span>`;
    block.innerHTML = affinityDetails(match, true);
    try {
      const prose = await api("matching/" + id);
      if (!dialog.isConnected) return;
      score.innerHTML = `<span class="match-pill">${I("spark")}${E(prose.score)}% ${E(T("de afinidad"))}</span>`;
      block.innerHTML = affinityDetails(prose);
    } catch (error) {
      if (!dialog.isConnected || error.name === "AbortError") return;
      // Do not retain affinity if access/networking was revoked while the request was pending.
      if ([400, 401, 403, 404].includes(error.status)) { score.replaceChildren(); block.remove(); return; }
      block.innerHTML = affinityDetails(match, false, true);
    } finally {
      if (block.isConnected) block.setAttribute("aria-busy", "false");
    }
  }
  const profileLabels = {
    first_name: "Nombre",
    last_name: "Apellidos",
    birth_date: "Fecha de nacimiento",
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
  const profileTopics = {
    interests: ["spark", "Los temas que te interesan"],
    areas: ["book", "Tu experiencia y especialidad"],
    industries: ["ally", "Los sectores que conoces"],
    goals: ["users", "Lo que buscas en la comunidad"],
    languages: ["hub", "Idiomas para conversar"],
    learn: ["book", "Lo que te gustaría aprender"],
    help: ["heart", "El conocimiento que puedes compartir"],
    connect_topics: ["users", "Conversaciones que quieres iniciar"],
  };
  function profileTopic(key, tax, p, optional = false) {
    const terms = S.boot.catalogs[tax], selected = new Set(p[key] || []);
    const names = terms.filter(t => selected.has(t.id)).map(t => t.name);
    const [icon, hint] = profileTopics[key];
    const required = !optional && ["industries", "interests", "goals"].includes(key);
    const choices = terms.map(t => `<label class="topic-choice"><input type="checkbox" name="${key}" value="${t.id}" data-choice-label="${E(t.name)}" ${selected.has(t.id) ? "checked" : ""}><span>${I("check")}${E(t.name)}</span></label>`).join("");
    return `<details class="profile-topic" ${["interests", "areas"].includes(key) ? "open" : ""}>
      <summary><span class="topic-icon">${I(icon)}</span><span class="topic-caption"><span class="topic-title">${E(T(profileLabels[key]))}${required ? requiredMark() : ""}</span><span class="topic-selection">${E(names.join(" · ") || T("Aún no has elegido opciones"))}</span></span><span class="topic-count" aria-label="${names.length} ${E(T("seleccionados"))}">${names.length}</span><span class="topic-chevron">${I("chevron")}</span></summary>
      <div class="topic-options"><p>${E(T(hint))}. ${E(T(required ? "Selecciona al menos una opción." : "Puedes elegir varias opciones."))}</p><div class="topic-choices">${choices || ("<span class=\"muted\">" + (E(T("No hay opciones disponibles."))) + "</span>")}</div></div>
    </details>`;
  }
  function profileKnowledge(p) {
    return `<section class="profile-preferences" aria-labelledby="profile-knowledge-title"><div class="preference-heading"><span class="preference-emblem">${I("spark")}</span><div><h2 id="profile-knowledge-title">${E(T("Conocimiento e intereses"))}</h2><p>${E(T("Haz que tu perfil conecte con las personas y las ideas que te interesan."))}</p></div></div><div class="profile-topics">${Object.entries(profileTax).map(([key, tax]) => profileTopic(key, tax, p)).join("")}</div></section>`;
  }
  function profileParticipation(p) {
    if (p.community_participant===false) return `<p class="private-note">${E(T('La cuenta de administración gestiona la comunidad y no participa en el directorio, las conexiones ni los microeventos.'))}</p>`;
    const options = [
      ["directory", "users", "Aparecer en el directorio", "Permite que otros asociados encuentren tu perfil."],
      ["networking", "spark", "Descubrir nuevas conexiones", "Recibe recomendaciones de personas afines a tus intereses."],
      ["microevents", "calendar", "Participar en microeventos", "Recibe invitaciones prioritarias para conversar en grupos con intereses comunes."],
    ];
    return options.map(([key, icon, title, description]) => `<label class="participation-option"><span class="participation-icon">${I(icon)}</span><span class="participation-copy"><strong>${E(T(title))}</strong><span>${E(T(description))}</span></span><span class="preference-switch"><input type="checkbox" role="switch" name="${key}" aria-label="${E(T(title))}" ${p[key] ? "checked" : ""}><span class="switch-track" aria-hidden="true"></span></span></label>`).join("");
  }
  function profilePrivacy(p) {
    const fields = ["company", "position", "city", "country", "bio", "experience", "linkedin", "twitter", "website", "interests", "areas", "industries", "photo_id"];
    const controls = fields.map(key => {
      const label = key === "photo_id" ? "Fotografía" : profileLabels[key];
      return `<label class="visibility-option"><input type="checkbox" name="hidden" value="${key}" aria-label="${E(T("Ocultar"))} ${E(T(label))}" ${(p.hidden || []).includes(key) ? "checked" : ""}><span class="visibility-label">${E(T(label))}</span><span class="visibility-state"><span class="is-visible">${E(T("Visible"))}</span><span class="is-hidden">${I("shield")}${E(T("Oculto"))}</span></span></label>`;
    }).join("");
    // Preserve privacy choices outside this view instead of silently clearing them on save.
    const preserved = (p.hidden || []).filter(key => !fields.includes(key)).map(key => `<input type="hidden" name="hidden" value="${E(key)}">`).join("");
    return `<section class="profile-preferences" aria-labelledby="profile-privacy-title"><div class="preference-heading"><span class="preference-emblem privacy-emblem">${I("shield")}</span><div><h2 id="profile-privacy-title">${E(T("Privacidad y participación"))}</h2><p>${E(T("Tú eliges cómo participar y qué información compartir con la comunidad."))}</p></div></div><div class="profile-privacy-layout"><div class="participation-panel"><h3>${E(T("Tu lugar en la comunidad"))}</h3>${profileParticipation(p)}<div class="profile-privacy-note">${I("shield")}<p>${E(T("Tus objetivos y preferencias de aprendizaje se utilizan internamente para ayudarte a conectar."))}</p></div></div><div class="visibility-panel"><h3>${E(T("Qué ven otros asociados"))}</h3><p>${E(T("Pulsa un dato para cambiar entre visible y oculto. La moderación puede consultarlo."))}</p><div class="visibility-options">${controls}</div>${preserved}<span class="profile-save-hint">${E(T("Los cambios se aplican al guardar tu perfil."))}</span></div></div></section>`;
  }
  function profileEmailNotifications(p) {
    const prefs = { connections: true, messages: true, events: true, support: true, ...p.email_notifications };
    const rows = [
      ["connections", "users", "Conexiones", "Solicitudes de conexión y avisos cuando una conexión sea aceptada."],
      ["messages", "mail", "Mensajes", "Mensajes privados, solicitudes de conversación e invitaciones a grupos."],
      ["events", "calendar", "Eventos", "Invitaciones a eventos y círculos de conversación."],
    ["support", "mail", "Solicitudes y soporte", "Recibir avisos sobre solicitudes y sus respuestas."]];
    const options = rows.map(([key, icon, title, description]) => `<label class="participation-option"><span class="participation-icon">${I(icon)}</span><span class="participation-copy"><strong>${E(T(title))}</strong><span>${E(T(description))}</span></span><span class="preference-switch"><input type="checkbox" role="switch" name="email_${key}" aria-label="${E(T(title))}" ${prefs[key] === false ? "" : "checked"}><span class="switch-track" aria-hidden="true"></span></span></label>`).join("");
    return `<section class="profile-preferences email-notification-preferences" aria-labelledby="profile-email-title"><div class="preference-heading"><span class="preference-emblem">${I("mail")}</span><div><h2 id="profile-email-title">${E(T("Notificaciones por correo"))}</h2><p>${E(T("Elige qué avisos opcionales quieres recibir también en tu correo. Por defecto están activados."))}</p></div></div><div class="email-notification-panel">${options}<p class="profile-save-hint">${E(T("Las notificaciones internas de ASCLA seguirán disponibles aunque desactives estos correos."))}</p></div></section>`;
  }
  function dismissedRecommendations(list) {
    const items=(list.items || []).map(person => {
      const restoreData='data-id="' + Number(person.id) + '"';
      return `<article class="dismissed-recommendation-row">${avatar(person)}<div><strong>${E(person.name)}</strong><span>${E(person.profile_url ? T("Puede volver a aparecer en próximas recomendaciones.") : T("Perfil no disponible actualmente."))}</span></div>${btn(T("Restituir"),"recommendation-restore",restoreData,"small")}</article>`;
    }).join("");
    const emptyState='<p class="profile-save-hint">' + E(T("No has descartado recomendaciones.")) + '</p>';
    return `<section class="profile-preferences dismissed-recommendations" aria-labelledby="dismissed-recommendations-title"><div class="preference-heading"><span class="preference-emblem">${I("users")}</span><div><h2 id="dismissed-recommendations-title">${E(T("Perfiles descartados"))}</h2><p>${E(T("“No me interesa” es privado. Puedes restituir aquí la elegibilidad de cualquier perfil."))}</p></div></div><div class="dismissed-recommendations-list">${items || emptyState}</div></section>`;
  }

  function updateProfilePreference(input) {
    const topic = input.closest(".profile-topic");
    if (!topic) return;
    const selected = [...topic.querySelectorAll("input:checked")];
    const count = topic.querySelector(".topic-count");
    count.textContent = selected.length;
    count.setAttribute("aria-label", selected.length + " " + T("seleccionados"));
    topic.querySelector(".topic-selection").textContent = selected.map(choice => choice.dataset.choiceLabel).join(" · ") || T("Aún no has elegido opciones");
  }
  function memberTypeField(value = 'independent') {
    return select('member_type','Tipo de asociado',[['','Selecciona el tipo'],...Object.entries(S.boot.member_types)],value,'required');
  }
  function profileMemberType(p) {
    if (p.community_participant === false) return '';
    const value = p.member_type || T('Pendiente de asignación');
    return '<div class="field profile-member-type"><span>'+E(T('Tipo de asociado'))+'</span><div class="profile-type-value">'+E(value)+'</div><small>'+E(T('Lo establece administración.'))+'</small></div>';
  }
  function profilePhoneFields(p = {}) {
    const phoneTitle=E(T('Incluye +, código de país y número; por ejemplo +51 987 654 321'));
    const phoneAttrs = String.raw`maxlength="40" autocomplete="tel" inputmode="tel" pattern="\+[1-9][0-9 ()-]{7,30}" title="${phoneTitle}" placeholder="+51 987 654 321"`;
    return '<div class="field profile-phone-field"><label for="f-phone">'+E(T('Teléfono'))+'</label><div class="profile-phone-control" data-phone-input data-phone-location="'+E(p.country || '')+'"><input id="f-phone" name="phone" type="tel" value="'+E(p.phone || '')+'" '+phoneAttrs+'></div><div class="birthday-inline-note phone-private-note">'+I('shield')+'<span>'+E(T('Dato privado. Solo yo y administradores.'))+'</span></div></div>';
  }
  let phoneModule;
  async function setupProfilePhone(form) {
    if (!C.phoneModule || !form?.querySelector('[data-phone-input]')) return;
    try {
      phoneModule ||= import(C.phoneModule);
      const module = await phoneModule;
      if (form.isConnected) module.setup(form, {T, I, locale:C.locale});
    } catch { phoneModule = null; /* The original international input remains usable if enhancement fails. */ }
  }
  function profileBirthdayField(p) {
    const maxDate = new Date().toISOString().slice(0, 10);
    return `<div class="birthday-profile-field">${field("birth_date", "Fecha de nacimiento", p.birth_date || "", "date", ("min=\"1900-01-01\" max=\"" + (maxDate) + "\""))}<div class="birthday-inline-note">${I("shield")}<span>${E(T("Dato privado. Solo se usa para felicitarte y avisar a la administración el día de tu cumpleaños."))}</span></div></div>`;
  }
  function profileTabButton(tab, index) {
    const [key, icon, title, description] = tab;
    const selected = index === 0;
    return '<button type="button" class="profile-tab" role="tab" id="profile-tab-' + key
      + '" data-profile-tab="' + key + '" aria-controls="profile-panel-' + key
      + '" aria-selected="' + (selected ? "true" : "false")
      + '" tabindex="' + (selected ? "0" : "-1") + '">'
      + '<span class="profile-tab-icon">' + I(icon) + '</span>'
      + '<span class="profile-tab-copy"><strong>' + E(T(title)) + '</strong><small>' + E(T(description)) + '</small></span>'
      + '</button>';
  }
  let learningModule;
  function interestLearning() {
    learningModule ||= globalThis.ASCLAInterestLearning({root,api});
    return learningModule;
  }
  function profileTabs() {
    const tabs = [
      ["info", "users", "Información", "Datos personales y profesionales"],
      ["interests", "spark", "Intereses", "Conocimiento y networking"],
      ["privacy", "shield", "Privacidad y avisos", "Visibilidad y notificaciones"],
      ["account", "shield", "Cuenta y seguridad", "Contraseña e integraciones"],
    ];
    return '<div class="profile-tabs" role="tablist" aria-label="' + E(T("Secciones del perfil")) + '">'
      + tabs.map((tab, index) => profileTabButton(tab, index)).join("")
      + '</div>';
  }
  function setupProfileTabs() {
    const scope = content();
    const tabs = [...scope.querySelectorAll("[data-profile-tab]")];
    const panels = [...scope.querySelectorAll("[data-profile-panel]")];
    const form = scope.querySelector('[data-form="profile"]');
    const accountPanel = scope.querySelector('[data-profile-panel="account"]');
    const keys = new Set(tabs.map(tab => tab.dataset.profileTab));
    const activate = (key, focus = false) => {
      if (!keys.has(key)) key = "info";
      S.profileTab = key;
      tabs.forEach(tab => {
        const selected = tab.dataset.profileTab === key;
        tab.setAttribute("aria-selected", selected ? "true" : "false");
        tab.tabIndex = selected ? 0 : -1;
        if (selected && focus) tab.focus();
      });
      panels.forEach(panel => { panel.hidden = panel.dataset.profilePanel !== key; });
      if (form) form.hidden = key === "account";
      if (accountPanel) accountPanel.hidden = key !== "account";
    };
    tabs.forEach((tab, index) => {
      tab.addEventListener("click", () => activate(tab.dataset.profileTab));
      tab.addEventListener("keydown", event => {
        let next;
        if (event.key === "ArrowRight") next = (index + 1) % tabs.length;
        else if (event.key === "ArrowLeft") next = (index - 1 + tabs.length) % tabs.length;
        else if (event.key === "Home") next = 0;
        else if (event.key === "End") next = tabs.length - 1;
        else return;
        event.preventDefault();
        activate(tabs[next].dataset.profileTab, true);
      });
    });
    form?.addEventListener("invalid", event => {
      const panel = event.target.closest?.("[data-profile-panel]");
      if (panel?.dataset.profilePanel) activate(panel.dataset.profilePanel);
    }, true);
    activate(S.profileTab || "info");
  }
  async function profile() {
    const initial = S.initialProfile;
    S.initialProfile = null;
    const [p, dismissed] = await Promise.all([initial || api("profiles/" + S.boot.me.id),api("recommendations/dismissed")]);
    content().innerHTML =
      heading(
        "Mi perfil",
        "Tu experiencia es el punto de partida de nuevas conexiones.",
      ) +
      (p.publication_missing?.length ? `<div class="alert" role="status">${E(T("Para completar tu perfil y recibir recomendaciones, completa:"))} ${E(p.publication_missing.map(T).join(", "))}.</div>` : "") +
      profileTabs() +
      `<form class="card profile-tab-form" data-form="profile" data-guard-unsaved><p class="profile-required-note">${requiredMark()} ${E(T("Los campos marcados son obligatorios. En Intereses, elige al menos un sector, un interés y un objetivo."))}</p>
        <section class="profile-tab-panel" id="profile-panel-info" data-profile-panel="info" role="tabpanel" aria-labelledby="profile-tab-info">
          <div class="profile-summary">${avatar(p, "xl")}<div class="profile-summary-person"><h2>${E(p.name)}</h2><p class="muted">${E(p.email || "")}</p><label class="btn small profile-photo-button">${I("edit")} ${E(T("Cambiar fotografía"))}<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" hidden data-upload="photo"></label><input type="hidden" name="photo_id" value="${p.photo_id || 0}"><div id="photo-status" class="private-note">${E(T("JPG, PNG o WebP. Podrás mover, ampliar y recortar antes de guardar; ASCLA optimiza la imagen automáticamente."))}</div></div>${profileMemberType(p)}</div>
          <div class="form-section">${E(T("Información personal y profesional"))}</div>
          <div class="form-grid">${["first_name", "last_name", "position", "company"].map((k) => field(k, profileLabels[k], p[k] || "", "text", 'required maxlength="200"')).join("")}${profileLocationFields(p)}${profileBirthdayField(p)}${profilePhoneFields(p)}${["linkedin", "twitter"].map((k) => field(k, profileLabels[k], p[k] || "", "url", 'maxlength="200"')).join("")}<div class="full profile-website-field">${field("website", profileLabels.website, p.website || "", "url", 'maxlength="200"')}</div><div class="full">${field("bio", "Biografía", p.bio || "", "textarea", 'required maxlength="3000"')}${field("experience", "Experiencia profesional", p.experience || "", "textarea", 'maxlength="3000"')}</div></div>
        </section>
        <section class="profile-tab-panel" id="profile-panel-interests" data-profile-panel="interests" role="tabpanel" aria-labelledby="profile-tab-interests" hidden>
          ${profileKnowledge(p)}
        </section>
        <section class="profile-tab-panel" id="profile-panel-privacy" data-profile-panel="privacy" role="tabpanel" aria-labelledby="profile-tab-privacy" hidden>
          ${profilePrivacy(p)}${dismissedRecommendations(dismissed)}${profileEmailNotifications(p)}
        </section>
        <div class="form-actions profile-tab-savebar"><span class="profile-save-copy">${E(T("Los cambios de Información, Intereses y Privacidad se guardan juntos."))}</span><button class="btn primary">${I("check")} ${E(T("Guardar perfil"))}</button></div>
      </form>
      <section class="card profile-tab-panel profile-account-panel" id="profile-panel-account" data-profile-panel="account" role="tabpanel" aria-labelledby="profile-tab-account" hidden>
        <div class="profile-account-heading"><span class="preference-emblem">${I("shield")}</span><div><h2>${E(T("Cuenta y seguridad"))}</h2><p>${E(T("Administra tu contraseña, tus archivos y las integraciones de tu cuenta."))}</p></div></div>
        <div class="profile-account-grid">
          <div class="profile-account-section account-security"><div class="form-section">${E(T("Seguridad de la cuenta"))}</div><h3>${E(T("Cambiar contraseña"))}</h3><p class="private-note">${E(T("Para cambiarla desde la intranet, confirma primero tu contraseña actual."))}</p><form data-form="password-change"><div class="password-current">${field("current_password", "Contraseña actual", "", "password", 'required autocomplete="current-password"')}</div><div class="form-grid password-new-grid">${field("new_password", "Nueva contraseña", "", "password", 'required minlength="'+S.boot.password_min_length+'" autocomplete="new-password"')}${field("confirm_password", "Confirmar nueva contraseña", "", "password", 'required minlength="'+S.boot.password_min_length+'" autocomplete="new-password"')}</div><p class="private-note">${E(T("Combina mayúsculas, minúsculas, números y símbolos. Evita secuencias y datos personales."))}</p><div class="form-actions"><button class="btn primary">${E(T("Cambiar contraseña"))}</button></div></form><div class="password-recovery"><h3>${E(T("¿No recuerdas tu contraseña actual?"))}</h3><p class="private-note">${E(T("Puedes recibir un enlace seguro de recuperación en"))} <strong>${E(p.email || S.boot.me.email || T("tu correo registrado"))}</strong>.</p>${btn(I("mail") + " Enviar enlace de recuperación", "password-reset-email", "", "small")}</div></div>
          <div class="profile-account-section profile-account-tools"><div class="form-section">${E(T("Archivos e integraciones"))}</div><h3>${E(T("Mis archivos"))}</h3><p class="private-note">${E(T("Consulta tus archivos y elimina los que ya no necesitas. Los archivos eliminados también se retiran de las publicaciones y de tu fotografía de perfil."))}</p>${btn("Administrar mis archivos", "files", 'data-scope="mine"', "small")}<div class="profile-account-divider"></div><h3>${E(T("Mi calendario"))}</h3><p class="private-note">${E(T(S.boot.google_connected ? "Tu calendario Google está conectado." : "Integración Google Calendar no configurada para tu cuenta. Puedes conectarlo para guardar próximos eventos."))}</p><div class="admin-actions">${btn("Conectar Google Calendar", "google-connect", 'data-service="calendar"')}${S.boot.google_connected ? btn("Desconectar", "google-disconnect", 'data-service="calendar"') : ""}</div></div>
        </div>
      </section>`;
    const profileForm = content().querySelector('[data-form="profile"]');
    void setupProfilePhone(profileForm);
    registerUnsavedForm(profileForm);
    setupProfileTabs();
    await setupProfileLocations(profileForm);
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
  function profileCompletionSettings(settings) {
    const options = settings.profile_completion_options || { required: {}, additional: {} };
    const selected = settings.profile_completion_extra || [];
    const checkboxes = Object.entries(options.additional).map(([key, label]) => `<label class="completion-choice"><input type="checkbox" name="profile_completion_extra" value="${E(key)}" ${selected.includes(key) ? "checked" : ""}><span>${E(T(label))}</span></label>`).join("");
    return `<fieldset class="profile-completion-settings"><legend>${E(T("Criterios de completitud"))}</legend><p class="private-note">${E(T("Los ocho grupos mínimos siempre se incluyen y son obligatorios al guardar. Los criterios adicionales cuentan para llegar al 100 %, pero no impiden guardar un perfil con sus mínimos completos."))}</p><p>${E(Object.values(options.required).map(T).join(", "))}</p><div class="completion-choices">${checkboxes}</div><p class="private-note">${E(T("Los campos no seleccionados son opcionales y no afectan el porcentaje."))}</p></fieldset>`;
  }
  function listingCreateAction(type, canWrite) {
    let createType = type;
    let createLabel;
    if (type === "topic") {
      createLabel = T("Crear foro");
      createType = "forum";
    } else {
      const feminine = type === "hub" || type === "gallery";
      createLabel = "Nuev" + (feminine ? "a " : "o ") + typeLabel[type];
    }
    return canWrite ? btn(I("plus") + " " + createLabel, "editor", 'data-type="' + createType + '"', "primary") : "";
  }
  function listingResourceFilters(type) {
    if (type !== "resource") return "";
    const resourceTypes = ["Artículo", "Video", "Podcast", "Nota técnica", "Infografía", "Documento"];
    const options = resourceTypes.map(resourceType => {
      const selected = S.filter.resource_type === resourceType ? "selected" : "";
      return '<option value="' + E(resourceType) + '" ' + selected + '>' + E(T(resourceType)) + '</option>';
    }).join("");
    return `<select name="resource_type" aria-label="${E(T("Tipo de recurso"))}" class="resource-type-filter"><option value="">${E(T("Todos los tipos"))}</option>${options}</select>`;
  }
  function listingDateFilters(type, label='Fecha de publicación') {
    if (S.filter.drafts) return "";
    if (!["hub", "topic", "gallery", "resource", "event"].includes(type)) return "";
    return `<fieldset class="content-date-range"><legend>${E(T(label))}</legend><label>${E(T("Desde fecha"))}<input type="date" name="after" value="${E(S.filter.after || "")}"></label><label>${E(T("Hasta fecha"))}<input type="date" name="before" value="${E(S.filter.before || "")}"></label></fieldset>`;
  }
  function listingTabs(type, canWrite) {
    const allLabel = type === "event" ? "Próximos eventos" : type === "ally" ? "Catálogo" : "Comunidad";
    const allButton = btn(allLabel, "filter-all", "", "tab " + (!S.filter.mine && !S.filter.drafts && !S.filter.past && !S.filter.comments ? "active" : ""));
    const pastClass = S.filter.past ? "active" : "";
    const mineClass = S.filter.mine && !S.filter.drafts ? "active" : "";
    const pastButton = type === "event" ? btn("Eventos anteriores", "filter-past", "", "tab " + pastClass) : "";
    const mineLabel=type==='topic'?'Mis temas':"Mis publicaciones";
    const commentsClass=S.filter.comments?'active':'';
    const mineButton = type !== 'ally' && (canWrite || type==='topic') ? btn(mineLabel, "filter-mine", "", "tab " + mineClass) : "";
    const commentsButton=type==='topic'?btn('Mis comentarios','filter-comments','','tab '+commentsClass):'';
    const draftsButton = canWrite ? btn('Mis borradores', 'filter-drafts', '', 'tab ' + (S.filter.drafts ? 'active' : '')) : '';
    return `<div class="tabs">${allButton}${pastButton}${mineButton}${draftsButton}${commentsButton}</div>`;
  }
  function listingDrafts(items) {
    const cards = items.map(p => `<article class="card draft-card" data-draft-id="${Number(p.id)}"><div class="section-top">${status('draft')}<span class="tag">${E(T(typeLabel[p.type] || p.type))}</span></div><h3>${E(p.title)}</h3><p class="detail-body">${E(p.body.slice(0,160))}</p><div class="form-actions">${btn('Continuar borrador', 'editor', 'data-type="' + E(p.type) + '" data-id="' + Number(p.id) + '"', 'primary small')}</div></article>`).join('');
    return `<section class="draft-list"><h2>${E(T('Mis borradores'))}</h2><p class="private-note">${E(T('Aquí encontrarás tus borradores, separados del contenido publicado.'))}</p><div class="cards two">${cards}</div>${items.length ? '' : empty('No tienes borradores guardados', 'Puedes guardar contenido incompleto y retomarlo desde aquí.')}</section>`;
  }
  function listingItems(type, items) {
    const renderers = {
      resource: () => `<div class="cards">${items.map(resourceCard).join("")}</div>`,
      event: () => `<div class="cards two">${items.map(eventCard).join("")}</div>`,
      gallery: () => `<div class="cards">${items.map(galleryCard).join("")}</div>`,
    };
    return (renderers[type] || (() => items.map(feedCard).join("")))();
  }
  function listingEmpty(items) {
    if (items.length) return "";
    const emptyText = S.filter.q ? "Prueba una búsqueda diferente." : "Comparte un aporte o vuelve pronto para ver novedades.";
    return empty("Aún no hay contenido aquí", emptyText);
  }
  async function listing() {
    const type = typeByPage[S.page];
    if (type==='topic' && S.filter.comments) { await ownComments();return; }
    const params = {...S.filter};
    delete params.drafts;
    if (type === "topic") params.include_forums = 1;
    if (S.filter.drafts) {
      Object.assign(params, {mine:1, status:'draft'});
      delete params.past; delete params.after; delete params.before;
    } else if (!S.filter.mine) {
      params.status = 'publish';
      if (type === 'event') params.past = S.filter.past || 0;
    } else {
      delete params.past;
    }
    if(type==='ally' && !S.filter.drafts){params.status='publish';params.mine=false;}
    const [list, authors, forums] = await Promise.all([
      api("content/" + type + "?" + new URLSearchParams(params)),
      type === "resource" ? api("resource-authors") : [],
      type === "topic" ? allContent("forum", {status:'publish'}).then(items => items.map(p => ({...p,name:p.title}))) : [],
    ]);
    S.list = list;
    if (S.filter.page) S.filter.page = list.page;
    const canWrite = !!S.boot.can_create?.[type];
    if(type==='ally'){content().innerHTML=alliesUI().catalog(list,S.filter,S.boot.admin,{tabs:listingTabs(type,canWrite),drafts:S.filter.drafts?listingDrafts(list.items):null})+pager(list);return;}
    const labels = {
      hub: ["Hub ASCLA", "Ideas, experiencias y conversaciones que nos acercan."],
      topic: ["Foros de la comunidad", "Comparte una pregunta y construyamos respuestas juntos."],
      event: ["Eventos y Capacitaciones", "Encuentros para aprender, conversar y ampliar tu perspectiva."],
      resource: ["Centro de Conocimiento", "La experiencia de nuestra comunidad, siempre a tu alcance."],
      gallery: ["Galería", "Los momentos que construyen nuestra comunidad."],
      ally: ["Nuestros aliados", "Colaboraciones que impulsan el buen gobierno corporativo."],
    };
    const searchPlaceholder = type === "resource" ? "Buscar por tema, autor o contenido…" : "Buscar en esta sección…";
    const filtersHtml = `<form class="filters content-list-filters" data-form="filters"><input name="q" aria-label="${E(T("Buscar contenido"))}" value="${E(S.filter.q || "")}" placeholder="${E(T(searchPlaceholder))}">${listingResourceFilters(type)}${listingDateFilters(type,type==='event'?'Fechas del evento':'Fecha de publicación')}${UI.filters(type, S.filter, S.boot.catalogs, authors, forums)}<button class="btn">${I("search")} ${E(T("Buscar"))}</button>${btn("Limpiar filtros", "filter-reset", "", "ghost")}</form>`;
    const items = list.items;
    let actions=listingCreateAction(type, canWrite);
    if (canWrite && ['gallery','resource'].includes(type)) {
      const target=C.adminUrl+(C.adminUrl.includes('?')?'&':'?')+'section=contenidos&type='+type;
      actions='<div class="page-heading-actions"><a class="btn" data-native href="'+E(target)+'">'+E(T('Registro e historial'))+'</a>'+actions+'</div>';
    }
    const results = S.filter.drafts ? listingDrafts(items) : listingItems(type, items) + listingEmpty(items);
    content().innerHTML = heading(...labels[type], actions) + filtersHtml + listingTabs(type, canWrite) + results + pager(list);
  }
  async function ownComments() {
    const list=await api('comments/mine?'+new URLSearchParams(S.filter));
    const filters=`<form class="filters content-list-filters" data-form="filters"><input name="q" aria-label="${E(T('Buscar en mis comentarios'))}" placeholder="${E(T('Buscar en mis comentarios'))}" value="${E(S.filter.q||'')}">${listingDateFilters('topic','Fecha del comentario')}<button class="btn">${E(T('Buscar'))}</button>${btn('Limpiar filtros','filter-reset','','ghost')}</form>`;
    const entries=list.items.map(comment=>`<article class="card own-comment" data-own-comment="${Number(comment.id)}"><strong>${E(comment.title)}</strong><p>${E(comment.body)}</p><div class="form-actions"><small>${date(comment.date)}</small>${comment.status==='pending'?status('pending'):''}${btn('Ver conversación','item',("data-id=\"" + (Number(comment.post_id)) + "\""),'small')}</div></article>`).join('');
    content().innerHTML=heading('Mis comentarios','Consulta tus aportes y abre la conversación original.')+filters+listingTabs('topic',!!S.boot.can_create?.topic)+entries+(entries?'':empty('Aún no hay comentarios propios','Los comentarios visibles de tus foros aparecerán aquí.'))+pager(list);
  }
  function eventCard(p) {
    let state = "";
    if (p.meta.cancelled) state = `<span class="tag">${E(T("Cancelado"))}</span>`;
    else if (p.status !== "publish") state = status(p.status);
    const cover = UI.images(p)[0];
    return `<article class="card event-card">${cover ? ("<button type=\"button\" class=\"event-card-cover\" data-action=\"item\" data-id=\"" + (Number(p.id)) + "\" aria-label=\"" + (E(T("Ver evento"))) + ": " + (E(p.title)) + "\"><img src=\"" + (E(cover.url)) + "\" alt=\"" + (E(p.title)) + "\" loading=\"lazy\"></button>") : ""}<div class="section-top"><span class="tag">${I("calendar")} ${E(T(p.meta.modality || "Virtual"))}</span>${state}</div><h3>${E(p.title)}</h3><p class="detail-body event-card-description">${E(p.body.slice(0, 160))}</p><div class="detail-meta"><span>${I("calendar")} ${date(p.meta.start)}</span><span>${I("clock")} ${time(p.meta.start)}</span></div><div class="form-actions event-card-actions">${p.meta.chatham ? '<span class="tag">Chatham House</span>' : "<span></span>"}${btn("Ver encuentro " + I("arrow"), "item", ("data-id=\"" + (p.id) + "\""), "small primary")}</div></article>`;
  }
  function galleryCard(p) {
    const cover = p.meta.media_ids?.length
      ? `<img src="${E(C.mediaUrl + p.meta.media_ids[0])}" alt="${E(p.title)}" class="gallery-card-image" loading="lazy">`
      : `<div class="resource-cover v1"><div class="cover-label">${E(T("ASCLA · ENCUENTROS"))}</div><strong>${E(p.title)}</strong><span class="cover-icon">${I("gallery")}</span></div>`;
    return `<article class="card resource-card"><button type="button" class="gallery-card-cover" data-action="item" data-id="${Number(p.id)}" aria-label="${E(T('Ver galería'))}: ${E(p.title)}">${cover}</button><div class="resource-content"><h3>${E(p.title)}</h3><p>${E(p.body.slice(0, 120))}</p><div class="resource-footer"><span>${date(p.date)}</span>${btn("Ver galería", "item", 'data-id="' + Number(p.id) + '"', "ghost")}</div></div></article>`;
  }
  function commentOverflow(c, postId) {
    const items = [];
    if (c.can_report) items.push(`<button type="button" class="overflow-item" data-action="report-comment" data-id="${Number(c.id)}" data-post="${Number(postId)}">${E(T("Reportar comentario"))}</button>`);
    if (c.can_delete) items.push(`<button type="button" class="overflow-item danger-text" data-action="delete-comment" data-id="${Number(c.id)}" data-post="${Number(postId)}">${E(T("Eliminar comentario"))}</button>`);
    if (!items.length) return "";
    return `<details class="overflow-menu"><summary aria-label="${E(T("Más opciones"))}" title="${E(T("Más opciones"))}">${I("more")}</summary><div class="overflow-popover">${items.join("")}</div></details>`;
  }
  function reactionButtons(data, id, comment = false) {
    const counts = data.reaction_counts || {like: data.likes || data.reactions || 0};
    const selected = data.reaction === 'like';
    return '<div class="reaction-picker">'+btn(I('heart')+' '+E(T('Me gusta'))+' <span>'+Number(counts.like || 0)+'</span>', 'content-reaction', 'data-id="'+Number(id)+'" data-comment="'+comment+'" data-reaction="like" data-active="'+(!selected)+'" aria-pressed="'+selected+'"', 'ghost small'+(selected?' active':''))+'</div>';
  }
  async function changeContentReaction(button, id) {
    const comment = button.dataset.comment === 'true';
    const result = await api((comment?'comments/':'items/')+id+'/reaction', {kind:'like',reaction:button.dataset.reaction,active:button.dataset.active === 'true'});
    const key = button.dataset.reaction;
    const container = button.closest('.reaction-picker');
    container.outerHTML = reactionButtons(result, id, comment);
    root.querySelector('.reaction-picker [data-id="'+id+'"][data-comment="'+comment+'"][data-reaction="'+key+'"]')?.focus();
  }
  function commentsHTML(comments, postId) {
    if (!comments.length) return `<p class="private-note">${E(T('Sé la primera persona en compartir una idea.'))}</p>`;
    const children = new Map();
    for (const c of comments) {
      const parent = Number(c.parent || 0);
      if (!children.has(parent)) children.set(parent, []);
      children.get(parent).push(c);
    }
    const seen = new Set();
    const renderOne = (c, depth = 0) => {
      if (seen.has(Number(c.id))) return '';
      seen.add(Number(c.id));
      const menu = commentOverflow(c, postId);
      const canReply = c.status === 'publish';
      const actions = `<div class="comment-actions">${canReply ? reactionButtons(c, c.id, true) : ""}${canReply ? btn(I('reply') + ' ' + T('Responder'), 'comment-reply', ("data-id=\"" + (Number(c.id)) + "\" data-post=\"" + (Number(postId)) + "\" data-author=\"" + (E(c.author)) + "\""), 'ghost small') : ''}${c.status === 'pending' ? status(c.status) : ''}</div>`;
      const replies = (children.get(Number(c.id)) || []).map(child => renderOne(child, depth + 1)).join('');
      const authorName = Number(c.author_id) > 0
        ? `<button type="button" class="comment-author" data-action="member" data-id="${Number(c.author_id)}" aria-label="${E(T('Ver perfil de'))} ${E(c.author)}">${E(c.author)}</button>`
        : `<strong>${E(c.author)}</strong>`;
      return `<article class="comment ${depth ? 'comment-reply' : ''} comment-depth-${Math.min(depth, 4)}" data-comment-id="${Number(c.id)}"><div class="comment-head"><div>${authorName} <small class="muted">${date(c.date)}</small></div>${menu}</div><p>${E(c.body)}</p>${actions}<div class="comment-reply-slot"></div>${replies}</article>`;
    };
    let html = (children.get(0) || []).map(c => renderOne(c, 0)).join('');
    for (const c of comments) if (!seen.has(Number(c.id))) html += renderOne(c, 0);
    return html;
  }
  function openCommentReply(button) {
    const comment = button.closest('.comment');
    if (!comment) return;
    document.querySelectorAll('.comment-reply-form').forEach(form => form.remove());
    const slot = comment.querySelector(':scope > .comment-reply-slot');
    if (!slot) return;
    const replyId = `reply-body-${++fieldSequence}`;
    slot.innerHTML = `<form class="comment-reply-form" data-form="comment-reply" data-id="${Number(button.dataset.post)}" data-parent="${Number(button.dataset.id)}"><label for="${replyId}">${E(T("Responder a"))} ${E(button.dataset.author || T('este comentario'))}</label><textarea id="${replyId}" name="body" required maxlength="5000" placeholder="${E(T("Escribe una respuesta…"))}"></textarea><div class="form-actions">${btn('Cancelar','comment-reply-cancel','','ghost small')}<button class="btn primary small">${E(T("Responder"))}</button></div></form>`;
    slot.querySelector('textarea')?.focus();
  }
  function eventAttendees(d) {
    if (!Array.isArray(d.attendees)) return '';
    const people = d.attendees;
    const title = d.is_past ? T('Inscripciones confirmadas') : T('Personas que asistirán');
    const hidden = Number(d.attendees_hidden || 0);
    const cards = people.map(person => {
      const role = [person.position, person.company].filter(Boolean).join(' · ');
      const mine = person.is_me ? `<span class="event-attendee-you">${E(T('Tú'))}</span>` : '';
      return `<article class="event-attendee-card${person.is_me ? ' is-me' : ''}"><button type="button" class="event-attendee-profile" data-action="member" data-id="${Number(person.id)}" aria-label="${E(T('Ver perfil de'))} ${E(person.name)}">${avatar(person)}<span class="event-attendee-copy"><span class="event-attendee-name">${E(person.name)}${mine}</span><small>${E(role || T('Miembro ASCLA'))}</small></span><span class="event-attendee-arrow" aria-hidden="true">${I('arrow')}</span></button></article>`;
    }).join('');
    const hiddenLabel = hidden === 1
      ? 'persona no se muestra por sus preferencias de privacidad.'
      : 'personas no se muestran por sus preferencias de privacidad.';
    const privacy = hidden > 0
      ? `<p class="event-attendee-privacy">${I('shield')} ${hidden} ${E(T(hiddenLabel))}</p>`
      : `<p class="event-attendee-privacy">${I('shield')} ${E(T('Solo se muestra la información que cada asociado permite compartir con la comunidad.'))}</p>`;
    return `<section class="event-attendees" aria-labelledby="event-attendees-title"><div class="event-attendees-head"><div class="event-attendees-heading"><span class="event-attendees-icon">${I('users')}</span><div><h3 id="event-attendees-title">${E(title)}</h3><p>${E(T('Disponible porque confirmaste tu asistencia. Conoce a otras personas antes del encuentro.'))}</p></div></div><span class="event-attendees-count"><strong>${Number(d.attending || people.length)}</strong><small>${E(T('confirmados'))}</small></span></div><div class="event-attendee-grid">${cards}</div>${privacy}</section>`;
  }
  function eventRegistrationPresentation(d, id) {
    let registrationActions = "";
    let waitlistNotice = "";
    switch (true) {
      case d.cancelled:
        waitlistNotice = `<div class="alert spaced-notice">${I("calendar")} <strong>${E(T("Evento cancelado"))}</strong>. ${E(T("Este encuentro ya no admite inscripciones ni confirmaciones de cupo."))}<p>${E(d.meta.cancellation_reason || "")}</p></div>`;
        registrationActions = `<span class="tag">${E(T("Cancelado"))}</span>`;
        break;
      case d.is_past:
        registrationActions = `<span class="tag">${E(T("Evento finalizado"))}</span>`;
        break;
      case d.registered === "accepted":
        registrationActions = btn("Cancelar inscripción", "register", `data-id="${id}" data-status="cancelled"`);
        break;
      case d.registration_closed:
        waitlistNotice = `<div class="alert">${E(T("Inscripción cerrada o reservada a los miembros recomendados."))}</div>`;
        if (d.registered !== "none") registrationActions = btn("Cancelar inscripción","register",`data-id="${id}" data-status="cancelled"`);
        break;
      case d.registered === "waitlisted": {
        const position = Number(d.waitlist_position || 0);
        const positionLabel=position ? " · " + E(T("posición")) + " " + position : "";
        waitlistNotice = `<div class="alert spaced-notice">${I("clock")} <strong>${E(T("Estás en la lista de espera"))}</strong>${positionLabel}. ${E(T("Te avisaremos cuando se libere un cupo."))}</div>`;
        registrationActions = btn("Salir de la lista de espera", "register", `data-id="${id}" data-status="cancelled"`, "ghost");
        break;
      }
      case d.registered === "offered":
        waitlistNotice = `<div class="alert success spaced-notice">${I("check")} <strong>${E(T("Se liberó un cupo para ti"))}</strong>. ${E(T("Confirma tu asistencia para ocuparlo."))}<p>${E(T("Vence"))}: <time datetime="${E(d.offer_expires_at)}">${date(d.offer_expires_at)} ${time(d.offer_expires_at)}</time></p></div>`;
        registrationActions = btn("Confirmar asistencia", "register", `data-id="${id}" data-status="accepted"`, "primary") + btn("Rechazar cupo", "register", `data-id="${id}" data-status="declined"`, "ghost");
        break;
      case d.full:
        registrationActions = btn("Unirme a la lista de espera", "register", `data-id="${id}" data-status="waitlisted"`, "primary");
        if (d.registered === "invited") registrationActions += btn("Rechazar invitación", "register", `data-id="${id}" data-status="declined"`);
        break;
      default: {
        let label="Registrarme";
        if (d.registered === "reconfirm") label="Reconfirmar asistencia";
        else if (d.registered === "invited") label="Aceptar invitación";
        registrationActions = btn(label, "register", `data-id="${id}" data-status="accepted"`, "primary");
        if (d.registered === "invited") registrationActions += btn("Rechazar invitación", "register", `data-id="${id}" data-status="declined"`);
      }
    }
    if (d.registered === "reconfirm" && !d.cancelled && !d.is_past) waitlistNotice = `<div class="alert">${E(T("El evento cambió de fecha, horario o modalidad. Revisa los cambios y confirma nuevamente tu asistencia."))}</div>`;
    return { registrationActions, waitlistNotice };
  }
  function eventCapacitySummary(p, d) {
    if (!p.meta.capacity) return ` · ${E(T("Sin límite de cupos"))}`;
    const availabilitySummary = Number(d.remaining) === 0
      ? ` · ${E(T("aforo completo"))}`
      : ` · ${Number(d.remaining)} ${E(T("disponibles"))}`;
    return ` · ${p.meta.capacity} ${E(T("cupos"))}${availabilitySummary}`;
  }
  const microStates={pending:"Pendiente de revisión",approved:"Aprobada",published:"Publicada",insufficient:"Inscripciones insuficientes",denied:"Denegada",cancelled:"Cancelada",finished:"Finalizada"};
  function microEventHistory(meta) {
    if (!meta.micro) return "";
    const rows=(meta.micro_history || []).map(h=>'<li><strong>'+E(T(microStates[h.state] || h.state))+'</strong> · '+date(h.at)+'<p>'+E(h.reason || "")+'</p></li>').join("");
    return '<p class="private-note">'+E(T(microStates[meta.micro_state] || "Pendiente de revisión"))+'</p>'+
      (rows?'<details><summary>'+E(T("Historial de microeventos"))+'</summary><ol>'+rows+'</ol></details>':"");
  }
  function microInvitationRoster(d) {
    if (!d.micro_invitations) return "";
    const roster=d.micro_invitations, people=roster.items || [];
    const labels={selected:"Por invitar",not_invited:"Sin invitación registrada",invited:"Sin responder",accepted:"Asistencia confirmada",declined:"Invitación rechazada",cancelled:"Inscripción cancelada",waitlisted:"En lista de espera",offered:"Cupo ofrecido",expired:"Cupo vencido",reconfirm:"Debe reconfirmar"};
    let help="Son las personas elegidas para esta propuesta. Las invitaciones se generan al publicarla, después de la aprobación.";
    if (roster.created) help="Consulta el estado actual de cada persona seleccionada. Una invitación no confirma su asistencia.";
    else if (d.meta.cancelled) help="Esta convocatoria fue cancelada. No se enviarán nuevas invitaciones.";
    const rows=people.map(person=>`<li class="micro-invitee"><span class="micro-invitee-icon" aria-hidden="true">${I("users")}</span><span class="micro-invitee-name">${E(person.available?person.name:T("Cuenta no disponible"))}</span><span class="micro-invitee-status ${person.status==='accepted'?'is-confirmed':''}">${E(T(labels[person.status] || "Estado no disponible"))}</span></li>`).join("");
    return `<section class="micro-invitations" aria-label="${E(T("Personas seleccionadas para el microevento"))}"><div class="micro-invitations-heading"><h3>${E(T("Personas seleccionadas para el microevento"))}</h3><span class="tag">${people.length}</span></div><p class="private-note">${E(T(help))}</p>${rows?`<ul class="micro-invitee-list">${rows}</ul>`:`<p class="private-note">${E(T("Esta propuesta no tiene personas seleccionadas."))}</p>`}<p class="micro-invitations-privacy">${I("shield")} ${E(T("Solo visible para administración."))}</p></section>`;
  }
  async function openContentReview(id) {
    const reason = root.querySelector(`[data-form="moderate"][data-id="${Number(id)}"] [name="reason"]`)?.value || "";
    let p = await api("items/" + id), candidateNotice = "";
    if (p.meta.micro) {
      const review = await api(`events/${id}/candidates`, {});
      p = review.item; S.item = p;
      candidateNotice = `<div class="alert micro-review-notice" role="status"><strong>${E(T("Candidatos elegibles"))}: ${Number(review.candidates)} · ${E(T("Mínimo requerido"))}: ${Number(p.meta.micro_min || 4)}</strong><p>${E(T(review.removed ? "Se retiraron de la propuesta los candidatos que ya no cumplen las condiciones. Revisa la convocatoria actualizada." : "La convocatoria se comprobó con la membresía, el consentimiento, el perfil y los bloqueos actuales."))}</p></div>${microInvitationRoster(p)}`;
      if (!p.can_moderate) {
        modal(T("Revisar microevento"), `<h3>${E(p.title)}</h3>${candidateNotice}${microEventHistory(p.meta)}${p.meta.cancellation_reason ? ("<p class=\"detail-body\">" + (E(p.meta.cancellation_reason)) + "</p>") : ""}<p class="private-note">${E(T("Esta propuesta conserva su historial y ya no está pendiente de aprobación."))}</p><div class="form-actions">${btn(T("Volver a la ficha"), "item", ("data-id=\"" + (Number(id)) + "\""))}</div>`);
        return;
      }
    }
    const decisions = p.meta.micro ? [["approve", "Aprobar propuesta"], ["reject", "Denegar propuesta"]] : [["approve", "Aprobar y publicar"], ["reject", "Rechazar"], ["hide", "Ocultar"], ["suspend", "Suspender publicación"]];
    const microHelp = p.meta.micro ? `<p class="private-note">${E(T("Aprobar permite elegir después la fecha de publicación. No inscribe automáticamente a los asociados."))}</p>${btn(T("Actualizar candidatos"), "moderate", ("data-id=\"" + (Number(id)) + "\""), "ghost small")}` : "";
    modal(T(p.meta.micro ? "Revisar microevento" : "Revisar publicación"), `<h3>${E(p.title)}</h3><p class="detail-body">${E(p.body)}</p>${candidateNotice}${microHelp}<form data-form="moderate" data-id="${Number(id)}" data-micro="${!!p.meta.micro}">${select("decision", "Decisión", decisions)}${field("reason", "Motivo de la decisión", reason, "textarea", 'required maxlength="1000"')}${p.meta.generated ? check("reviewed", "Revisé fuentes, identidades, afiliaciones y derechos de propiedad intelectual.", false) : ""}<div class="form-actions">${btn("Cancelar", "close")}<button class="btn primary">${E(T("Guardar decisión"))}</button></div></form>`);
  }
  function eventStateSummary(d) {
    if (d.cancelled) return ` · ${E(T("Cancelado"))}`;
    if (d.is_past) return ` · ${E(T("Finalizado"))}`;
    return ` · ${status(d.registered)}`;
  }
  function eventGoogleActions(d, id) {
    const calendarAction = !d.is_past && !d.cancelled && d.google_url
      ? `<a class="btn small" href="${E(d.google_url)}" target="_blank" rel="noopener noreferrer">${E(T("Añadir a Google Calendar ↗"))}</a>`
      : "";
    let connectedActions = "";
    if (!d.is_past && !d.cancelled && S.boot.google_connected) {
      if (d.google_managed) connectedActions = `<span class="status accepted">${E(T("Sincronizado como organizador en Google Calendar"))}</span>`;
      else connectedActions = btn("Guardar en Google conectado", "google-event", `data-id="${id}" data-operation="save"`, "small")
        + btn("Quitar de Google", "google-event", `data-id="${id}" data-operation="cancel"`, "small");
    }
    return { calendarAction, connectedActions };
  }
  function eventParticipantsSection(d) {
    if (!d.participants) return "";
    const rows=d.participants.map((x) => `<p>${E(x.name)} · ${status(x.status)}</p>`).join("");
    return `<details class="event-moderation-participants"><summary class="private-note">${E(T("Inscripciones y lista de espera (gestión de eventos)"))}</summary>${rows}</details>`;
  }
  function eventChangeReason(p) {
    if (!p.meta.change_reason) return "";
    return '<p class="detail-body">' + E(T("Motivo de reprogramación")) + ': ' + E(p.meta.change_reason) + '</p>';
  }
  function eventMicroSchedule(d, p) {
    if (!p.meta.micro) return "";
    let publicAt=E(T("Al publicar"));
    if (d.meta.public_at) publicAt=date(Number(d.meta.public_at)*1000) + " " + time(Number(d.meta.public_at)*1000);
    return '<p>' + E(T("Cierre de inscripciones")) + ': ' + date(d.meta.registration_deadline) + ' ' + time(d.meta.registration_deadline)
      + ' · ' + E(T("Apertura a la comunidad")) + ': ' + publicAt + '</p>';
  }
  async function eventItemExtra(p, id) {
    const d = await api("events/" + id);
    S.event = d;
    const { registrationActions, waitlistNotice } = eventRegistrationPresentation(d, id);
    const waitlistCount = Number(d.waitlist_count || 0);
    const waitlistLabel = waitlistCount === 1 ? "persona en espera" : "personas en espera";
    const waitlistSummary = waitlistCount ? ` · ${waitlistCount} ${E(T(waitlistLabel))}` : "";
    const attendeeSection = eventAttendees(d);
    const agendaSection = p.meta.agenda ? `<p class="detail-body">${E(p.meta.agenda).replaceAll("\n", "<br>")}</p>` : "";
    const { calendarAction, connectedActions }=eventGoogleActions(d,id);
    const participantsSection = eventParticipantsSection(d);
    const changeReason=eventChangeReason(p);
    const microSchedule=eventMicroSchedule(d,p);
    const invitations=microInvitationRoster(d);
    return `<div class="card event-detail-summary"><div class="detail-meta"><span>${I("calendar")} ${date(p.meta.start, { weekday: "long", day: "numeric", month: "long", year: "numeric" })}</span><span>${I("clock")} ${time(p.meta.start)} – ${time(p.meta.end)}</span><span>${I("pin")} ${E(p.meta.location || T("Por confirmar"))}</span></div><p class="private-note">${d.attending} ${E(T("inscritos"))}${eventCapacitySummary(p, d)}${waitlistSummary}${eventStateSummary(d)}</p>${waitlistNotice}${changeReason}${invitations}${microEventHistory(d.meta)}${microSchedule}${agendaSection}<div class="form-actions">${registrationActions}${calendarAction}${connectedActions}</div>${attendeeSection}${participantsSection}</div>`;
  }
  function itemGeneratedNotice(p, reviewedLabel) {
    if (!p.meta.generated && !p.meta.ai_enriched && !p.meta.generated_sections) return "";
    const generatedLabel = p.meta.ai_enriched ? "Publicación enriquecida con IA" : "Contenido generado";
    const reviewedSuffix = p.meta.ai_enriched ? "" : " · " + E(reviewedLabel);
    return `<div class="alert">${E(T(generatedLabel))} · ${E(p.meta.ai_mode || p.meta.social_mode || "IA")}${reviewedSuffix}</div>`;
  }
  function itemEditingActions(p,id) {
    let actions='';
    if(p.can_delete) actions+=btn('Eliminar','delete-content','data-id="'+id+'"','danger');
    if(S.page==='admin'&&p.meta.can_archive) actions+=btn(T(p.meta.micro_archived?'Restaurar en el panel':'Archivar del panel'),'micro-archive','data-id="'+Number(id)+'" data-archived="'+!p.meta.micro_archived+'"');
    if(p.can_edit) actions+=btn(I('edit')+' '+T(p.meta.micro&&p.meta.micro_approved&&p.status!=='publish'?'Editar y publicar':'Editar'),'editor','data-type="'+p.type+'" data-id="'+id+'"');
    return actions;
  }
  function itemEventActions(p,id,eventIsPast,eventCancelled) {
    if(p.type!=='event'||eventCancelled||p.meta.cancelled)return '';
    const allowed=p.meta.micro
      ? S.boot.admin&&(p.status!=='publish'||!eventIsPast)
      : (S.boot.admin||S.boot.executive)&&p.status==='publish'&&!eventIsPast;
    if(!allowed)return '';
    const cancel=btn(T(p.meta.micro?'Cancelar microevento':'Cancelar evento'),'event-cancel-request','data-id="'+id+'" data-micro="'+!!p.meta.micro+'" data-title="'+E(p.title)+'"','danger');
    const invite=p.meta.micro?'':btn('Invitar asociados','event-invite','data-id="'+id+'"');
    return cancel+invite;
  }
  function itemResourceActions(p,id) {
    if(!(S.boot.admin||S.boot.executive)||p.type!=='resource')return '';
    return btn(I('spark')+' Generar resumen y nota','generate','data-id="'+id+'"');
  }
  function itemModerationActions(p,id) {
    if (['gallery','resource'].includes(p.type) && p.can_moderate && !p.meta.publish_at && ['publish','ascla_hidden'].includes(p.status)) {
      return btn(T(p.status==='publish'?'Ocultar del catálogo':'Volver a publicar'),'editorial-state','data-id="'+id+'"','small')
        +btn(T('Historial'),'editorial-history','data-id="'+id+'"','ghost small');
    }
    if(p.can_moderate)return btn(I('shield')+' '+T(p.meta.micro?'Revisar propuesta':'Moderar'),'moderate','data-id="'+id+'"');
    if(S.boot.admin&&p.meta.micro&&p.status==='ascla_hidden'&&p.meta.micro_approved&&!p.meta.cancelled&&!p.meta.invited)return btn(T('Actualizar candidatos'),'moderate','data-id="'+Number(id)+'"');
    return '';
  }
  async function showJobError(target,id,error,onError) {
    if(!onError){
      target.innerHTML=`<div class="error">${E(error)} ${btn('Reintentar','retry-job','data-id="'+id+'"','small')}</div>`;
      return;
    }
    try { await onError(error); }
    catch(fallbackError) { target.innerHTML=`<div class="error">${E(error)}<br>${E(fallbackError.message||'')}</div>`; }
  }
  function microHistoryTabs(archived) {
    if(!S.boot.admin)return '';
    const historyClass=archived?'':'primary',archiveClass=archived?'primary':'';
    return '<div class="tabs" role="group" aria-label="'+E(T('Mostrar microeventos'))+'">'+btn(T('Historial'),'micro-archive-tab','data-archived="false" aria-pressed="'+!archived+'"',historyClass)+btn(T('Archivados'),'micro-archive-tab','data-archived="true" aria-pressed="'+archived+'"',archiveClass)+'</div><p class="private-note">'+E(T('Archivar quita el encuentro de este listado principal. Sus datos permanecen disponibles en Archivados.'))+'</p>';
  }
  function microHistoryCard(p) {
    const archiveLabel=p.meta.micro_archived?'Restaurar en el panel':'Archivar del panel';
    const archive=p.meta.can_archive?btn(T(archiveLabel),'micro-archive','data-id="'+Number(p.id)+'" data-archived="'+!p.meta.micro_archived+'"','small'):'';
    return '<article class="card" data-micro-id="'+Number(p.id)+'"><h3>'+E(p.title)+'</h3>'+microEventHistory(p.meta)+'<div class="form-actions">'+btn('Ver','item','data-id="'+p.id+'"','small')+archive+'</div></article>';
  }
  function microHistoryMarkup(proposals) {
    const archived=!!S.microShowArchived,shown=proposals.filter(p=>!!p.meta.micro_archived===archived);
    const emptyText=archived?'No hay microeventos archivados.':'No hay microeventos en este listado.';
    return '<h3>'+E(T('Historial de microeventos'))+'</h3>'+microHistoryTabs(archived)+shown.map(microHistoryCard).join('')+(shown.length?'':empty(emptyText));
  }
  function itemActionButtons(p, id, eventIsPast, eventCancelled) {
    const actionButtons = itemEditingActions(p,id) + itemEventActions(p,id,eventIsPast,eventCancelled)
      + itemResourceActions(p,id) + itemModerationActions(p,id) + publishedItemActions(p,id);
    return publicationNotice(p) + `<div class="form-actions">${actionButtons}</div>`;
  }
  function publicationNotice(p) {
    if (!p.can_edit && !p.can_moderate) return '';
    if (p.meta.schedule_error) return '<div class="error">'+E(T('La publicación programada necesita revisión.'))+' '+E(p.meta.schedule_error)+'</div>';
    if (p.meta.publish_at) return '<div class="alert">'+E(T('Publicación programada para'))+' <time datetime="'+E(p.meta.publish_at)+'">'+E(date(p.meta.publish_at))+'</time>. '+E(T(p.status === 'pending' ? 'Necesita aprobación administrativa antes de publicarse.' : 'Permanecerá oculto hasta esa fecha.'))+'</div>';
    if (p.meta.micro && p.meta.micro_approved && p.status !== 'publish') return '<div class="alert">'+E(T('Microevento aprobado. Edita para publicar ahora o programar su publicación.'))+'</div>';
    return '';
  }
  function publishedItemActions(p, id) {
    if (p.status !== "publish") return "";
    let actions = reactionButtons(p, id);
    if (Number(p.author.id) !== Number(S.boot.me.id)) actions += btn("Reportar", "report", ("data-id=\"" + (id) + "\""), "ghost");
    return actions;
  }  function itemMediaMarkup(p, id) {
    const clipQuery = p.meta.clip ? "?start=" + Number(p.meta.clip.start) + "&end=" + Number(p.meta.clip.end) : "";
    const videoDuration = p.meta.duration_seconds ? E(UI.duration(p.meta.duration_seconds)) : E(T("Duración por confirmar"));
    const videoModeTag = p.meta.video_metadata_mode ? `<span class="tag">${E(p.meta.video_metadata_mode)}</span>` : "";
    const video = p.meta.video_id ? `<div class="video-wrap"><iframe credentialless loading="lazy" referrerpolicy="strict-origin-when-cross-origin" src="https://www.youtube-nocookie.com/embed/${E(p.meta.video_id)}${clipQuery}" title="${E(p.title)}" allow="accelerometer; encrypted-media; picture-in-picture" allowfullscreen></iframe></div><div class="video-metadata"><span>${videoDuration}</span>${videoModeTag}</div>` : "";
    const eventCover = p.type === "event" ? UI.images(p)[0] : null;
    const eventCoverMarkup = eventCover ? `<figure class="event-detail-cover"><img src="${E(eventCover.url)}" alt="${E(p.title)}"></figure>` : "";
    const attachmentsMarkup = p.type === "event" ? "" : UI.attachments(p);
    const deleteMedia = (p.media || []).filter(m => m.can_delete).map(m => btn(`${T("Eliminar archivo")}: ${E(m.name)}`, "delete-media", `data-id="${m.id}" data-post="${id}" data-name="${E(m.name)}"`, "ghost danger small")).join("");
    return { video, eventCoverMarkup, attachmentsMarkup, deleteMedia };
  }
  function itemSupplementalMarkup(p) {
    return {
      external: p.meta.url ? `<a class="btn" href="${E(safeURL(p.meta.url))}" target="_blank" rel="noopener noreferrer">${E(T("Abrir enlace ↗"))}</a>` : "",
      benefits: p.meta.benefits ? `<h3>${E(T("Beneficios"))}</h3><p class="detail-body">${E(p.meta.benefits)}</p>` : "",
      initiatives: p.meta.initiatives ? `<h3>${E(T("Iniciativas"))}</h3><p class="detail-body">${E(p.meta.initiatives)}</p>` : "",
      clip: p.meta.clip ? `<div class="alert">${E(T("Cápsula sugerida"))}: ${p.meta.clip.start}s – ${p.meta.clip.end}s · ${E(T("Referencia temporal al video de origen. No existe un archivo recortado."))}</div>` : "",
      copyright: p.meta.copyright ? `<p class="private-note">${E(p.meta.copyright)}</p>` : "",
      demoSource: p.meta.demo_source_note ? `<p class="alert">${E(p.meta.demo_source_note)}</p>` : "",
    };
  }
  function itemCommentsMarkup(p, id) {
    if (p.status !== "publish") return "";
    return `<section class="comments"><h3>${E(T("Conversación"))}</h3><div id="comments-list">${E(T("Cargando comentarios…"))}</div><form class="comment-form" data-form="comment" data-id="${id}">${field("body", "Comparte tu opinión", "", "textarea", 'required maxlength="5000"')}<button class="btn primary small">${E(T("Publicar comentario"))}</button></form></section>`;
  }
  async function loadItemComments(p, id) {
    if (p.status !== "publish") return;
    const comments = await api("items/" + id + "/comments");
    const target = document.getElementById("comments-list");
    if (target) target.innerHTML = commentsHTML(comments, id);
  }
  async function item(id) {
    const p = await api("items/" + id);
    S.item = p;
    syncCommentCount(p);
    if(p.type==='ally'){await alliesUI().detail(p);void interestLearning().watch(p,root.querySelector('.modal-backdrop'),S.page==='admin');return;}
    const extra = p.type === "event" ? await eventItemExtra(p, id) : "";
    const reviewedLabel = T(p.meta.reviewed ? "Revisado" : "Requiere revisión de fuentes, anonimización y derechos.");
    const eventIsPast = p.type === "event" && !!S.event?.is_past;
    const eventCancelled = p.type === "event" && !!S.event?.cancelled;
    const transcriptWarning = (S.boot.admin || S.boot.executive) && p.type === "resource" && p.meta.video_id && p.meta.transcript_status === "unavailable"
      ? `<div class="error spaced-notice">${E(T("No se pudo obtener una transcripción verificable para este video. ASCLA no generará resumen, nota ni temas hasta que agregues una transcripción autorizada o YouTube pueda entregar subtítulos accesibles."))}</div>`
      : "";
    const demoTag = p.meta.demo ? `<span class="demo-badge">${E(T("DATOS DEMO"))}</span>` : "";
    const chatham = p.meta.chatham ? `<div class="alert chatham spaced-notice">${I("shield")} ${E(T("Regla de Chatham House: utiliza el conocimiento sin revelar identidades ni afiliaciones."))}</div>` : "";
    const generated = itemGeneratedNotice(p, reviewedLabel);
    const media = itemMediaMarkup(p, id);
    const supplemental = itemSupplementalMarkup(p);
    const actions = itemActionButtons(p, id, eventIsPast, eventCancelled);
    const commentsSection = itemCommentsMarkup(p, id);
    const dialog = modal(
      p.title,
      `<div class="detail-meta"><span>${E(p.author.name)}</span><span>${date(p.date)}</span>${status(p.status)}${demoTag}</div>${media.eventCoverMarkup}${chatham}${generated}<p class="detail-body">${E(p.body)}</p>${media.video}${transcriptWarning}${extra}${media.attachmentsMarkup}${media.deleteMedia}${UI.generated(p)}${UI.agenda(p)}${supplemental.demoSource}${supplemental.external}${supplemental.benefits}${supplemental.initiatives}${supplemental.clip}${supplemental.copyright}${actions}${commentsSection}`,
      true,
    );
    void interestLearning().watch(p,dialog,S.page==="admin");
    await loadItemComments(p, id);
  }
  function resourceEditorExtra(m) {
    let videoNote = "";
    if (m.video_id) {
      let durationText;
      if (m.duration_seconds) durationText = ": " + E(UI.duration(m.duration_seconds));
      else durationText = ". " + E(T("Si YouTube no la expone públicamente, conecta YouTube OAuth y vuelve a intentar."));
      videoNote = `<p class="private-note">${E(T("Duración detectada automáticamente"))}${durationText}</p>`;
    }
    let moderatorFields = "";
    if (S.boot.moderator || S.boot.executive || S.boot.admin) {
      let transcriptWarning = "";
      if (m.video_id && !m.transcript) {
        transcriptWarning = `<div class="alert">${E(T("No hay una transcripción guardada. ASCLA intentará obtener una transcripción autorizada de YouTube al generar el resumen. Si no puede obtenerla, la generación se detendrá para evitar inventar información."))}</div>`;
      }
      moderatorFields = field("transcript", "Transcripción autorizada (opcional)", m.transcript || "", "textarea", 'maxlength="100000"')
        + transcriptWarning
        + field("identities", "Identidades y afiliaciones que deben anonimizarse (una por línea)", m.identities || "", "textarea");
    }
    return `<div class="form-grid">${select("resource_type", "Tipo de recurso", ["Artículo", "Video", "Podcast", "Nota técnica", "Infografía", "Documento"], m.resource_type || "Artículo")}${field("source", "Fuente / autoría", m.source || "")}${field("youtube_url", "URL de YouTube", m.youtube_url || "", "url")}${field("url", "URL del recurso externo", m.url || "", "url")}</div>${videoNote}${field("copyright", "Propiedad intelectual", m.copyright || "© ASCLA – Asociación de Secretarios Corporativos de América Latina")}${field("summary", "Resumen", m.summary || "", "textarea")}${moderatorFields}<p class="private-note">Las conferencias completas permanecen en YouTube. La duración se consulta directamente desde los metadatos del video y nunca se estima a partir de la transcripción.</p>`;
  }
  function editorStatusOptions(type, m, directResourcePublisher, directContentPublisher) {
    if (directContentPublisher && ['gallery','resource'].includes(type)) return [['draft','Borrador'],['pending','Pendiente de revisión'],['publish','Publicado'],['ascla_hidden','Oculto']];
    if(type==='ally')return [['publish','Activo · Visible en el catálogo'],['inactive','Inactivo · Fuera del catálogo'],['draft','Borrador']];
    if (type === 'hub' && !m.generated) return [['draft','Borrador'],['pending','Enviar a revisión'],['publish','Publicar ahora']];
    const directPublishType = ["topic", "forum"].includes(type) || (type === "event" && !m.micro && !m.generated);
    if (directPublishType) return [["draft", "Borrador"], ["publish", "Publicar ahora"]];
    if (directContentPublisher && (!m.generated || directResourcePublisher)) {
      const publishLabel = m.generated ? "Publicar ahora (revisado)" : "Publicado";
      return [["draft", "Borrador"], ["pending", "Pendiente de revisión"], ["publish", publishLabel]];
    }
    return [["draft", "Borrador"], ["pending", "Enviar a revisión"]];
  }
  function editorStatusValue(type, id, p, m, directResourcePublisher, directContentPublisher) {
    if(type==='ally')return allyEditorStatus(id,p,m);
    if (type === 'hub' && !m.generated) return id ? p.status : 'publish';
    const directPublishType = ["topic", "forum"].includes(type) || (type === "event" && !m.micro && !m.generated);
    if (directPublishType) return id && p.status === "draft" ? "draft" : "publish";
    if ((!id && type === "resource" && directResourcePublisher) || (p.status === "publish" && directContentPublisher)) return "publish";
    return p.status;
  }
  function allyEditorStatus(id,p,m) {
    if (!id) return 'publish';
    return p.status==='ascla_hidden'&&!m.publish_at?'inactive':p.status;
  }
  function eventCoverEditorMarkup(type, eventCoverFile) {
    if (type !== "event") return "";
    let preview;
    let actionLabel;
    if (eventCoverFile) {
      preview = `<span class="event-cover-ready" data-media="${Number(eventCoverFile.id)}"><img src="${E(eventCoverFile.url)}" alt="${E(T("Portada del evento"))}"><span class="event-cover-actions"><strong>${E(eventCoverFile.name || T("Imagen del evento"))}</strong>${btn("Quitar portada","event-cover-clear","","ghost small")}</span></span>`;
      actionLabel = "Cambiar portada";
    } else {
      preview = `<span class="event-cover-placeholder">${I("gallery")}<small>${E(T("Aún no has seleccionado una portada."))}</small></span>`;
      actionLabel = "Elegir imagen";
    }
    return `<section class="event-cover-editor"><label>${E(T("Portada del evento"))}</label><div id="event-cover-preview" class="event-cover-preview">${preview}</div><label class="btn small">${I("plus")} ${E(T(actionLabel))}<input type="file" data-upload="event-cover" accept="image/jpeg,image/png,image/webp" hidden></label><p class="private-note">${E(T("JPG, PNG o WebP. Proporción recomendada 16:9. La imagen se recorta y optimiza antes de guardarse."))}</p></section>`;
  }
  function editorDateValue(value) {
    if (!value) return "";
    const dateValue = new Date(value);
    return new Date(dateValue - dateValue.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
  }
  function eventDateTimeField(name, label, value) {
    const localValue = editorDateValue(value);
    const [dateValue = "", timeValue = ""] = localValue.split("T");
    const labelId = `f-${name}-label`;
    return `<div class="field event-datetime-field" role="group" aria-labelledby="${E(labelId)}"><span class="event-datetime-label" id="${E(labelId)}">${E(T(label))}</span><div class="event-datetime-controls"><label class="event-datetime-control" for="f-${E(name)}-date"><span>${E(T("Fecha"))}</span><input id="f-${E(name)}-date" name="${E(name)}_date" type="date" value="${E(dateValue)}" required></label><label class="event-datetime-control" for="f-${E(name)}-time"><span>${E(T("Hora"))}</span><input id="f-${E(name)}-time" name="${E(name)}_time" type="time" value="${E(timeValue)}" step="60" required></label></div></div>`;
  }
  function eventDateTimeISO(dateValue, timeValue) {
    if (!dateValue || !timeValue) return "";
    return new Date(`${dateValue}T${timeValue}`).toISOString();
  }
  function publicationFields(value, scheduled) {
    return '<div data-publication-date '+(scheduled?'':'hidden')+'>'+field('publish_at','Fecha y hora de publicación',editorDateValue(value),'datetime-local',scheduled?'required':'')+'<p class="private-note">'+E(T('Usa la hora local de tu dispositivo. El contenido permanecerá oculto hasta esta fecha.'))+'</p></div>';
  }
  function publicationFieldState(input) {
    if (input.name !== 'status') return;
    const panel = input.closest('[data-form="editor"]')?.querySelector('[data-publication-date]');
    if (!panel) return;
    panel.hidden = input.value !== 'scheduled';
    panel.querySelector('input').required = !panel.hidden;
  }
  function syncEditorRequirements(form) {
    if (form?.dataset.form !== "editor") return;
    const status = form.elements.status?.value || "draft";
    const incompleteAllowed = status === "draft";
    for (const input of form.querySelectorAll('input,textarea,select')) {
      if (input.name === 'publish_at') continue;
      if (input.required) input.dataset.editorRequired = 'true';
      if (!input.dataset.editorRequired) continue;
      input.required = !incompleteAllowed;
      const mark = input.id ? form.querySelector('label[for="' + input.id + '"] .required-mark') : null;
      if (mark) mark.hidden = incompleteAllowed;
      if (incompleteAllowed && requiredTextErrors.has(input)) {
        input.setCustomValidity("");
        requiredTextErrors.delete(input);
      }
    }
    const submit = form.querySelector("[data-editor-primary]");
    if (submit) {
      submit.dataset.editorLabel ||= submit.textContent;
      const labels = {draft: "Guardar borrador", scheduled: "Programar evento", pending: "Enviar a revisión"};
      const label = form.dataset.type === 'event' ? labels[status] || (Number(form.dataset.id) ? "Guardar evento" : "Publicar evento") : status === 'draft' ? 'Guardar borrador' : submit.dataset.editorLabel;
      submit.textContent = T(label);
    }
    const draftAction = form.querySelector('[data-editor-draft]');
    if (draftAction) draftAction.hidden = status === "draft";
  }
  function prepareEditorDraft(form) {
    if (form?.dataset.form !== "editor") return;
    form.elements.status.value = "draft";
    publicationFieldState(form.querySelector('[name="status"]:checked') || form.elements.status);
    syncEditorRequirements(form);
  }
  async function editorExtra(type, p, m) {
    if (type === "event") {
      const reminderMode=m.micro && (m.invited || p.status === "publish") ? "readonly" : "required";
      const reminderField=S.boot.admin ? editorFieldHelp(field("reminder_hours","Avisos antes del evento (horas)",(m.reminder_hours || [24,1]).join(", "),"text",reminderMode),'Por ejemplo, 24, 1 envía un aviso un día antes y otro una hora antes.') : "";
      const changeReasonField=p.id ? field("change_reason", "Motivo de reprogramación (obligatorio para microeventos)", "", "textarea", 'maxlength="1000"') : "";
      return `<div class="form-grid">${eventDateTimeField("start", "Inicio (tu zona horaria)", m.start)}${eventDateTimeField("end", "Fin (tu zona horaria)", m.end)}${select("modality", "Modalidad", ["Virtual", "Presencial", "Híbrido"], m.modality || "Virtual")}${field("location", "Lugar o sala", m.location || "")}${field("url", "Enlace para asistir", m.url || "", "url")}</div><section class="event-registration-options"><h3>${E(T('Inscripciones y avisos'))}</h3><div class="form-grid">${editorFieldHelp(field("capacity", "Máximo de asistentes", m.capacity || 0, "number", 'min="0" max="100000"'),'Escribe 0 para permitir inscripciones sin límite de cupos.')}${editorFieldHelp(field("offer_hours", "Plazo para aceptar un cupo (horas)", m.offer_hours || 24, "number", 'min="1" max="168"'),'Si se libera un lugar, este es el tiempo que tiene la siguiente persona de la lista de espera para aceptarlo.')}</div>${reminderField}</section>${microEventFields(m)}${field("agenda", "Programa del evento", m.agenda || "", "textarea")}${changeReasonField}`;
    }
    if (type === "resource") return resourceEditorExtra(m);
    if (type === "topic") {
      const forums = { items: await allContent("forum") };
      return select("parent", "Foro", [[0, "Conversación general"], ...forums.items.map((f) => [f.id, f.title])], p.parent || 0);
    }
    if (type === "gallery") {
      const events = { items: await allContent("event") };
      return select("event_id", "Evento relacionado", [[0, "Sin evento"], ...events.items.map((f) => [f.id, f.title])], m.event_id || 0);
    }
    return "";
  }
  function editorFieldHelp(control, help) {
    return '<div class="editor-field-help">'+control+'<p>'+E(T(help))+'</p></div>';
  }
  function editorStatusSelection(m, canSchedule, statusOptions, initialStatus) {
    if (m.publish_at && canSchedule) return "scheduled";
    if (statusOptions.some((option) => option[0] === initialStatus)) return initialStatus;
    return "draft";
  }
  function eventStatusControl(values, value) {
    const selected = values.some((option) => String(option[0]) === String(value)) ? String(value) : String(values[0]?.[0] || "draft");
    return `<fieldset class="event-status-field"><legend>${E(T("Publicación del evento"))}</legend><div class="event-status-options">${values.map((option, index) => {
      const pair = Array.isArray(option) ? option : [option, option];
      const id = `f-event-status-${index}`;
      return `<label class="event-status-option" for="${id}"><input id="${id}" type="radio" name="status" value="${E(pair[0])}" ${selected === String(pair[0]) ? "checked" : ""}><span>${E(T(pair[1]))}</span></label>`;
    }).join("")}</div></fieldset>`;
  }
  function editorFormMarkup({type, id, p, m, extra, eventCoverEditor, statusOptions, statusValue, canSchedule, directResourcePublisher}) {
    const statusControl = type === "event" ? eventStatusControl(statusOptions, statusValue) : select("status", "Guardar como", statusOptions, statusValue);
    const publicationControl = canSchedule ? publicationFields(m.publish_at, statusValue === "scheduled") : "";
    const draftVisibility=statusValue === "draft" ? "hidden" : "";
    const draftAction = btn(I("check")+" "+T("Guardar como borrador"), type === 'event' ? 'save-event-draft' : 'save-content-draft', draftVisibility + ' data-editor-draft', "event-draft-action");
    if(type==='ally')return alliesUI().editorMarkup({id,p,m,statusOptions,statusValue,publicationControl,draftAction});
    const eventWorkflow = type === "event" ? `<section class="event-editor-status">${statusControl}${publicationControl}<p class="private-note">${E(T("Puedes guardar un borrador aunque todavía falten datos. Para publicar, completa los campos obligatorios."))}</p></section>` : "";
    const trailingWorkflow = type === "event" ? "" : statusControl + publicationControl + `<p class="private-note">${E(T("Puedes guardar un borrador aunque todavía falten datos. Para publicar, completa los campos obligatorios."))}</p>`;
    return `<form data-form="editor" data-guard-modal-unsaved data-type="${type}" data-id="${id}">${field("title", "Título", p.title, "text", 'required maxlength="200"')}${field("body", type === "event" ? "Descripción del evento" : "Contenido", p.body, "textarea", 'required maxlength="30000"')}${eventWorkflow}${extra}${eventCoverEditor}${select("category", "Categoría", [["", "Sin categoría"], ...S.boot.catalogs.category.map(t => [t.id, t.name])], p.tags.find(t => t.taxonomy === "ascla_category")?.id || "")}${field("tag_names", "Etiquetas (separadas por comas)", p.tags.filter(t => t.taxonomy === "ascla_tag").map(t => t.name).join(", ") || (m.tags || []).join(", "))}<label>Temas</label><div class="multi-select">${S.boot.catalogs.interest.map((t) => ("<label class=\"chip-check\"><input type=\"checkbox\" name=\"interest\" value=\"" + (t.id) + "\" " + (p.tags.some((x) => x.id === t.id) ? "checked" : "") + ">" + (E(t.name)) + "</label>")).join("")}</div>${["hub", "gallery", "resource", "ally"].includes(type) ? ("<label class=\"btn small\">" + (I("plus")) + " " + (E(T("Adjuntar imagen o PDF"))) + "<input type=\"file\" data-upload=\"content\" accept=\"image/jpeg,image/png,image/webp,application/pdf\" hidden></label><div id=\"attachments\">" + ((m.media_ids || []).map((mid) => ("<span class=\"attached-file\" data-media=\"" + (mid) + "\">Archivo #" + (mid) + "" + (btn("Quitar", "detach-media", ("data-id=\"" + (mid) + "\""), "ghost small")) + "</span>")).join("")) + "</div><p class=\"private-note\">" + (E(T("Las imágenes se previsualizan, recortan y optimizan antes de guardarse; PDF hasta 5 MB. Sólo acceso autenticado."))) + "</p>") : ""}${(S.boot.moderator || S.boot.executive) ? check("chatham", "Aplicar Regla de Chatham House", m.chatham !== false) : ""}${trailingWorkflow}${m.generated && directResourcePublisher ? ("<p class=\"private-note\">" + (E(T("Al publicar o programar, confirmas que revisaste fuentes, anonimización y derechos."))) + "</p>") : ""}<div class="alert">Respeta la confidencialidad, la propiedad intelectual y la diversidad. No se admite spam ni promoción comercial directa.</div><div class="form-actions">${btn("Cancelar", "close")}${draftAction}<button class="btn primary" data-editor-primary>Guardar ${typeLabel[type]}</button></div></form>`;
  }
  async function editor(type, id = 0) {
    const p = id
      ? await api("items/" + id)
      : { title: "", body: "", meta: {}, tags: [], status: "pending" };
    if (id ? !p.can_edit : !S.boot.can_create?.[type]) { throw new Error(T("No puede editar este contenido.")); }
    S.edit = p;
    const m = p.meta;
    const extra = await editorExtra(type, p, m);
    const directResourcePublisher = type === "resource" && (S.boot.admin || S.boot.executive);
    const directContentPublisher = (["gallery", "resource"].includes(type) ? (S.boot.admin || S.boot.executive) : S.boot.moderator);
    const statusOptions = editorStatusOptions(type, m, directResourcePublisher, directContentPublisher);
    const canSchedule = (S.boot.admin || S.boot.executive) && ['event','gallery','resource','hub','ally'].includes(type);
    if (canSchedule) statusOptions.push(['scheduled','Programar publicación']);
    const initialStatus = editorStatusValue(type, id, p, m, directResourcePublisher, directContentPublisher);
    const statusValue = editorStatusSelection(m, canSchedule, statusOptions, initialStatus);
    const eventCoverFile = type === "event" ? (p.media || []).find(file => file.mime?.startsWith("image/")) : null;
    const eventCoverEditor = eventCoverEditorMarkup(type, eventCoverFile);
    const formMarkup = editorFormMarkup({type, id, p, m, extra, eventCoverEditor, statusOptions, statusValue, canSchedule, directResourcePublisher});
    const dialog = modal((id ? "Editar " : "Crear ") + typeLabel[type], formMarkup, true);
    syncEditorRequirements(dialog.querySelector('[data-form="editor"]'));
  }
  async function files(page = 1, q = "", scope = S.files?.scope || "mine", owner = S.files?.owner || 0, inline = S.page === "admin" && S.adminTab === "archivos" && scope === "all") {
    const previousScope = S.files?.scope || "";
    scope = scope === "all" && S.boot.admin ? "all" : "mine";
    if (previousScope && previousScope !== scope) owner = 0;
    owner = scope === "all" ? Math.max(0, Number(owner) || 0) : Number(S.boot.me.id);
    const panel = inline ? document.getElementById("admin-panel") : null;
    const list = await api("media?" + new URLSearchParams({page, q, scope, owner: scope === "all" ? owner : 0}));
    if (inline && !panel?.isConnected) return;
    if (page > Math.max(1, Number(list.pages))) return files(Math.max(1, Number(list.pages)), q, scope, owner, inline);
    S.files = {page, q, scope, owner: Number(list.owner || owner || 0)};
    const ownerFilter = scope === "all" ? `<div class="field"><label for="file-owner">${E(T("Usuario propietario"))}</label><select id="file-owner" name="owner"><option value="0">${E(T("Todos los usuarios"))}</option>${(list.owners || []).map(u => ("<option value=\"" + (Number(u.id)) + "\" " + (Number(S.files.owner)===Number(u.id)?"selected":"") + ">" + (E(u.name)) + " · " + (E(u.email)) + "</option>")).join("")}</select></div>` : "";
    const markup = `<form data-form="file-search" class="filters file-library-filters"><div class="field"><label for="file-query">${E(T("Buscar archivos"))}</label><input id="file-query" name="q" aria-label="${E(T("Buscar archivos"))}" placeholder="${E(T("Buscar por nombre…"))}" value="${E(q)}"></div>${ownerFilter}<button class="btn">${E(T("Buscar"))}</button></form><p class="private-note">${list.total} ${E(T("archivos"))} · ${E(T("La eliminación es permanente y retira sus referencias."))}</p><div class="file-library">${list.items.map(m => ("<article class=\"file-row\" data-file=\"" + (m.id) + "\"><div>" + (I(m.mime.startsWith('image/') ? 'gallery' : 'book')) + "<strong>" + (E(m.name)) + "</strong><small>" + (E(m.author)) + " · " + (Math.ceil((m.stored_size || m.size) / 1024)) + " KB" + (m.has_master ? " · " + E(T("incluye copia maestra optimizada")) : "") + " · " + (date(m.date)) + "</small></div><div class=\"form-actions\"><a class=\"btn small\" href=\"" + (E(m.url)) + "\" target=\"_blank\" rel=\"noopener\">" + (E(T("Abrir archivo"))) + "</a>" + (btn("Eliminar archivo", "delete-media", ("data-id=\"" + (m.id) + "\" data-name=\"" + (E(m.name)) + "\" data-library=\"true\""), "danger small")) + "</div></article>")).join("") || empty("No hay archivos")}</div><div class="pagination">${btn("Anterior", "files", ("data-page=\"" + (page-1) + "\" " + (page<=1?'disabled':'') + ""))}<span>${page} / ${list.pages}</span>${btn("Siguiente", "files", ("data-page=\"" + (page+1) + "\" " + (page>=list.pages?'disabled':'') + ""))}</div>`;
    if (inline) panel.innerHTML = `<div class="admin-section-heading"><div><span class="eyebrow">${E(T('BIBLIOTECA PRIVADA'))}</span><h2>${E(T('Archivos de la comunidad'))}</h2><p>${E(T('Consulta los archivos privados de todos los asociados y elimina los que corresponda.'))}</p></div></div><section class="card file-library-panel">${markup}</section>`;
    else modal(scope === "all" ? "Archivos de la comunidad" : "Mis archivos", markup, true);
  }
  function confirmDeletion(kind, id, {post = 0, title = "", library = false, forum = false, conversation = 0} = {}) {
    const descriptions = {
      media: 'El archivo se eliminará permanentemente, junto con su copia maestra si existe, y se retirará de las publicaciones y de la foto de perfil que lo utilicen.',
      comment: 'El comentario dejará de mostrarse en la conversación.',
      message: 'El mensaje se eliminará de esta conversación para ambos participantes.',
    };
    const description = descriptions[kind] || 'El contenido dejará de estar disponible en la comunidad.';
    modal(T('Confirmar eliminación'), `<p class="detail-body">${E(title)}</p><p>${E(T(description))}</p>${forum?'<p>Los temas de este foro se conservarán en la lista general.</p>':''}<div class="form-actions">${btn('Cancelar','delete-cancel',("data-kind=\"" + (kind) + "\" data-id=\"" + (id) + "\" data-post=\"" + (post) + "\" data-library=\"" + (library) + "\" data-conversation=\"" + (conversation) + "\""))}${btn('Eliminar','delete-confirm',("data-kind=\"" + (kind) + "\" data-id=\"" + (id) + "\" data-post=\"" + (post) + "\" data-library=\"" + (library) + "\" data-conversation=\"" + (conversation) + "\""),'danger primary')}</div>`);
  }
  async function inviteMembers(id, page = 1, query = "") {
    if (!S.invite || S.invite.id !== id) S.invite = { id, selected: new Set() };
    S.invite.query = query;
    const list = await api("profiles?" + new URLSearchParams({ q: query, page }));
    modal("Invitar asociados", `<p class="private-note">Selecciona hasta 50 asociados. La invitación no reserva un cupo. Si la cuenta organizadora tiene Google Calendar conectado, cada invitado con correo válido recibirá además la invitación oficial de Google Calendar por correo.</p><form data-form="invite-search" data-id="${id}" class="filters"><input name="q" aria-label="Buscar invitados" value="${E(query)}" placeholder="Nombre, empresa o interés"><button class="btn">Buscar</button></form><form data-form="event-invite" data-id="${id}"><div class="invite-options">${list.items.map(p => ("<label class=\"check\"><input type=\"checkbox\" data-invite-member=\"" + (p.id) + "\" " + (S.invite.selected.has(p.id) ? "checked" : "") + "><span>" + (E(p.name)) + "<small class=\"muted\"> · " + (E(p.company || "ASCLA")) + "</small></span></label>")).join("") || empty("No encontramos asociados")}</div><div class="pagination">${btn("Anterior", "invite-page", ("data-page=\"" + (page - 1) + "\" " + (page <= 1 ? "disabled" : "") + ""), "small")}<span>${page} / ${list.pages}</span>${btn("Siguiente", "invite-page", ("data-page=\"" + (page + 1) + "\" " + (page >= list.pages ? "disabled" : "") + ""), "small")}</div><p class="private-note"><span id="invite-selected">${S.invite.selected.size}</span> seleccionados</p><button class="btn primary">Enviar invitaciones</button></form>`);
  }
  root.addEventListener("change", event => {
    const input = event.target.closest("[data-invite-member]");
    if (!input || !S.invite) return;
    const id = Number(input.dataset.inviteMember);
    if (input.checked && S.invite.selected.size >= 50) { input.checked = false; toast("Puedes invitar hasta 50 asociados por envío."); return; }
    if (input.checked) S.invite.selected.add(id); else S.invite.selected.delete(id);
    document.getElementById("invite-selected").textContent = S.invite.selected.size;
  });
  const chatDrafts = new Map();
  root.addEventListener('input', event => {
    const form = event.target.closest('.chat-compose');
    if (form) chatDrafts.set(Number(form.dataset.id), form.elements.body.value);
  });
  root.addEventListener('change', event => {
    const input = event.target.closest('[data-group-member]');
    if (!input || !S.groupChat) return;
    const id = Number(input.dataset.groupMember);
    if (input.checked && S.groupChat.selected.size >= 49) { input.checked = false; toast('Un grupo puede tener como máximo 50 asociados.'); return; }
    if (input.checked) S.groupChat.selected.add(id); else S.groupChat.selected.delete(id);
    const count = document.getElementById('group-selected'); if (count) count.textContent = S.groupChat.selected.size;
  });
  async function conversationRequestDialog(id) {
    const p = await api('profiles/' + Number(id));
    modal('Enviar solicitud de conversación', `<form data-form="conversation-request-message" data-id="${Number(id)}"><div class="request-message-recipient">${avatar(p)}<div><strong>${E(p.name)}</strong><small>${E(T('Este primer mensaje llegará como una solicitud. Podrá leerlo y decidir si acepta la conversación.'))}</small></div></div>${field('body','Mensaje','','textarea','required maxlength="5000" placeholder="Escribe tu mensaje…"')}<div class="form-actions">${btn('Cancelar','close')}<button class="btn primary">${I('mail')} Enviar solicitud</button></div></form>`);
  }
  async function groupChatDialog() {
    const data = await api('connections');
    S.groupChat = {selected:new Set(), members:data.connected || [], photo:null};
    const options = S.groupChat.members.map(p => `<label class="check group-member-option"><input type="checkbox" name="users" value="${Number(p.id)}" data-group-member="${Number(p.id)}"><span>${avatar(p)}<span><strong>${E(p.name)}</strong><small>${E(p.company || T('Conexión ASCLA'))}</small></span></span></label>`).join('');
    const photo = `<div class="group-photo-field"><div id="group-photo-preview" class="group-photo-preview"><span class="group-photo-placeholder">${I('users')}</span></div><div><label class="btn small">${I('plus')} ${E(T("Elegir foto"))}<input type="file" accept="image/jpeg,image/png,image/webp" data-upload="group-photo" hidden></label><input type="hidden" name="photo_id" value="0"><p class="private-note">${E(T("JPG, PNG o WebP. Se ajustará en formato cuadrado."))}</p></div></div>`;
    modal(T('Crear chat grupal'), `<form data-form="group-chat">${photo}${field('title','Nombre del grupo','','text','required maxlength="120" placeholder="'+E(T("Ej. Comité de Innovación"))+'"')}${field('description','Descripción breve','','textarea','maxlength="240" placeholder="'+E(T("Ej. Coordinación del comité y próximos acuerdos"))+'"')}<p class="private-note">${E(T("Selecciona al menos dos conexiones confirmadas. El grupo admite hasta 50 asociados contando tu cuenta."))}</p><div class="group-member-list">${options || empty('Necesitas al menos dos conexiones confirmadas','Conecta con otros asociados antes de crear un grupo.')}</div><p class="private-note"><strong id="group-selected">0</strong> ${E(T('seleccionados'))}</p><div class="form-actions">${btn('Cancelar','close')}<button class="btn primary" ${S.groupChat.members.length < 2 ? 'disabled' : ''}>${I('plus')} ${E(T('Crear grupo'))}</button></div></form>`);
  }
  async function groupEditDialog(id) {
    const current = await api('conversations/' + Number(id));
    if (current.kind !== 'group' || !current.can_edit_group) return;
    S.groupEdit = {id:Number(current.id), photo_id:Number(current.photo_id || 0), photo_url:current.photo_url || ''};
    const preview = current.photo_url
      ? `<img src="${E(current.photo_url)}" alt="${E(T('Foto del grupo'))}">${btn('Quitar foto','group-edit-photo-clear','','ghost small')}`
      : `<span class="group-photo-placeholder">${I('users')}</span>`;
    const photo = `<div class="group-photo-field"><div id="group-edit-photo-preview" class="group-photo-preview">${preview}</div><div><label class="btn small">${I('plus')} ${E(T("Cambiar foto"))}<input type="file" accept="image/jpeg,image/png,image/webp" data-upload="group-photo-edit" hidden></label><input type="hidden" name="photo_id" value="${Number(current.photo_id || 0)}"><p class="private-note">${E(T("JPG, PNG o WebP. La nueva foto reemplazará a la actual al guardar."))}</p></div></div>`;
    modal(T('Editar grupo'), `<form data-form="group-edit" data-guard-modal-unsaved data-id="${Number(current.id)}">${photo}${field('title','Nombre del grupo',current.title || '','text','required maxlength="120"')}${field('description','Descripción breve',current.description || '','textarea','maxlength="240" placeholder="'+E(T("Añade una breve descripción del propósito del grupo"))+'"')}<div class="form-actions">${btn('Cancelar','group-menu',("data-id=\"" + (Number(current.id)) + "\""))}<button class="btn primary">${I('check')} ${E(T('Guardar cambios'))}</button></div></form>`);
  }
  async function groupInfoDialog(id) {
    const current = await api('conversations/' + Number(id));
    if (current.kind !== 'group') return;
    const identity = chatIdentity(current);
    const me = Number(S.boot.me.id);
    const members = (current.members || []).map(member => {
      const memberId = Number(member.id);
      const creator = memberId === Number(current.created_by);
      const isMe = memberId === me;
      const profileAction = btn(T('Ver perfil')+' '+I('arrow'),'member',`data-id="${memberId}"`,'ghost small group-member-action');
      const messageAction = isMe ? '' : btn(I('mail')+' '+T('Mensaje privado'),'group-member-message',`data-id="${memberId}"`,'small group-member-action');
      const ownerBadge = creator ? '<span class="group-owner-badge">' + E(T('Creador')) + '</span>' : '';
      const meBadge = isMe ? '<span class="group-me-badge">' + E(T('Tú')) + '</span>' : '';
      return `<div class="group-info-member"><div class="group-info-member-main">${avatar(member)}<div class="group-info-member-copy"><div class="group-info-member-name"><strong>${E(member.name || T('Asociado ASCLA'))}</strong>${ownerBadge}${meBadge}</div><small>${E(member.company || member.role_label || T('Integrante'))}</small></div></div><div class="group-info-member-actions">${profileAction}${messageAction}</div></div>`;
    }).join('');
    const editAction = current.can_edit_group ? btn(I('edit')+' '+T('Editar grupo'),'group-edit-open',`data-id="${Number(current.id)}"`,'ghost small') : '';
    const description = current.description ? `<p class="group-info-description">${E(current.description)}</p>` : `<p class="group-info-description muted">${E(T('Sin descripción del grupo.'))}</p>`;
    const danger = current.can_delete_group ? `<div class="group-info-danger"><div><strong>${E(T('Eliminar grupo'))}</strong><p class="private-note">${E(T('Elimina esta conversación y sus mensajes para todos los integrantes. Esta acción no se puede deshacer.'))}</p></div>${btn('Eliminar grupo','group-delete-request',("data-id=\"" + (Number(current.id)) + "\" data-name=\"" + (E(identity.name)) + "\""),'danger')}</div>` : '';
    modal(T('Información del grupo'), `<div class="group-info-head">${avatar(identity)}<div class="group-info-head-copy"><h3>${E(identity.name)}</h3><p>${Number(current.member_count || current.members?.length || 0)} ${E(T('integrantes'))}</p>${description}</div>${editAction ? ("<div class=\"group-info-head-action\">" + (editAction) + "</div>") : ''}</div><div class="group-info-section"><div class="group-info-section-title"><h4>${E(T('Integrantes'))}</h4><span>${Number(current.member_count || current.members?.length || 0)}</span></div><div class="group-info-members">${members}</div></div>${danger}`);
  }

  function stopChat() {
    clearTimeout(S.poll);
    if (S.chat) S.chat.active = false;
    S.chat = null;
  }
  function chatAlive(chat) { return chat?.active && S.chat === chat && S.page === "mensajeria"; }
  function mobileMessaging() { return !!globalThis.matchMedia?.("(max-width: 760px)")?.matches; }
  function chatStatus(text, failed = false) {
    const label = document.getElementById("chat-sync");
    if (label) {
      label.textContent = text || "";
      label.hidden = !text;
      label.classList.toggle("is-error", failed);
    }
  }
  function receiptText(read, total) {
    read = Number(read || 0); total = Number(total || 0);
    if (total <= 1) return read >= 1 ? T('Leído') : T('Enviado');
    return `${T('Leído por')} ${Math.min(read,total)} ${T('de')} ${total}`;
  }
  function messageBubble(m) {
    const mine = Number(m.sender_id) === Number(S.boot.me.id);
    const group = S.chat?.current?.kind === 'group';
    const menu = '';
    const sender = group && !mine ? `<strong class="bubble-sender">${E(m.sender?.name || T('Asociado ASCLA'))}</strong>` : '';
    const receipt = mine ? `<span class="message-receipt" data-receipt-for="${Number(m.id)}">${E(receiptText(m.read_count,m.read_total))}</span>` : '';
    return `<div data-message-id="${Number(m.id)}" data-sender-id="${Number(m.sender_id)}" class="bubble ${mine ? "me" : ""}">${sender}<div class="bubble-top"><span class="bubble-body">${E(m.body)}</span>${menu}</div><small>${date(m.created_at, { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })}${receipt ? ' · ' + receipt : ''}</small></div>`;
  }
  function applyReadState(readState = []) {
    const states = Array.isArray(readState) ? readState : [];
    document.querySelectorAll('.bubble.me[data-message-id]').forEach(bubble => {
      const id = Number(bubble.dataset.messageId);
      const count = states.filter(state => Number(state.last_read) >= id).length;
      const holder = bubble.querySelector('[data-receipt-for]');
      if (holder) holder.textContent = receiptText(count, states.length);
    });
  }
  function chatIdentity(c) {
    if (c?.kind === 'group') return {name:c.title || T('Grupo ASCLA'), photo_url:c.photo_url || '', profile_url:''};
    return c?.other || {name:T('Conversación'),photo_url:'',profile_url:''};
  }
  function isIncomingRequest(c) {
    return c?.kind !== 'group' && c?.conversation_request?.state === 'incoming_pending';
  }
  function conversationBucket(tab = S.messageTab, rows = S.conversations) {
    return (rows || []).filter(c => tab === 'requests' ? isIncomingRequest(c) : !isIncomingRequest(c));
  }
  function chatSidebar() {
    const aside = document.querySelector(".chat-sidebar");
    if (!aside) return;
    const requests = conversationBucket('requests');
    const rows = conversationBucket(S.messageTab);
    const requestUnread = requests.reduce((sum,c)=>sum + Number(c.unread || 0),0);
    const tabs = `<div class="chat-sidebar-tabs"><button type="button" class="chat-sidebar-tab ${S.messageTab==='chats'?'active':''}" data-action="chat-tab" data-tab="chats">${E(T('Chats'))}</button><button type="button" class="chat-sidebar-tab ${S.messageTab==='requests'?'active':''}" data-action="chat-tab" data-tab="requests">${E(T('Solicitudes'))}<span class="chat-request-count ${requestUnread?'unread':''}">${requests.length}</span></button></div>`;
    const heading = S.messageTab === 'requests' ? `<div class="chat-sidebar-section"><strong>${E(T('Solicitudes de conversación'))}</strong><small>${E(T('Los mensajes de personas aún no autorizadas aparecen aquí.'))}</small></div>` : '';
    const list = rows.map(c => {
      const person=chatIdentity(c);
      const requestMark = isIncomingRequest(c) ? `<span class="request-pill">${E(T('Solicitud'))}</span>` : '';
      return `<button class="chat-person ${Number(c.id) === S.conversation ? "active" : ""} ${c.unread ? 'has-unread' : ''}" data-action="conversation" data-id="${Number(c.id)}">${avatar(person)}<span><strong>${E(person.name)} ${c.kind==='group'?'<span class="group-badge">Grupo</span>':''} ${requestMark} ${c.unread ? '<span class="tag" aria-label="' + Number(c.unread) + ' sin leer">' + Number(c.unread) + '</span>' : ''}</strong><small>${E((c.preview || '').slice(0, 42))}</small></span></button>`;
    }).join("") || (S.messageTab === 'requests'
      ? `<p class="private-note chat-sidebar-empty">${E(T('No tienes solicitudes de conversación pendientes.'))}</p>`
      : `<p class="private-note chat-sidebar-empty">${E(T('No hay conversaciones para mostrar. Puedes iniciar una desde el directorio o crear un grupo.'))}</p>`);
    const markup = tabs + heading + `<div class="chat-sidebar-list">${list}</div>`;
    if (aside.innerHTML !== markup) {
      const focused = aside.contains(document.activeElement) ? document.activeElement.dataset.id : null;
      aside.innerHTML = markup;
      if (focused) aside.querySelector(`[data-id="${Number(focused)}"]`)?.focus({ preventScroll: true });
    }
  }
  function chatControls(current) {
    const form = document.querySelector('.chat-compose');
    if (!form) return;
    const disabled = current.can_message === false || !!current.blocked;
    form.querySelectorAll('textarea, button').forEach(el => { el.disabled = disabled || (el.tagName === 'BUTTON' && form.dataset.sending === 'true'); });
    if (current.kind !== 'group') {
      const block = document.querySelector('.chat-title [data-action="block"]');
      if (block) { block.textContent = current.blocked_by_me ? 'Desbloquear' : 'Bloquear'; block.dataset.active = String(!current.blocked_by_me); }
    }
  }
  function openChat(chat, current) {
    S.conversation = Number(current.id); chat.id = S.conversation; chat.current = current;
    chat.last = 0; chat.loaded = false; chat.ids = new Set();
    const group = current.kind === 'group';
    const identity = chatIdentity(current);
    let title;
    if (group) {
      title = `<button type="button" class="chat-profile group-chat-profile group-info-trigger" data-action="group-menu" data-id="${Number(current.id)}" aria-label="${E(T('Abrir información del grupo'))}">${avatar(identity)}<span><strong>${E(identity.name)}</strong><small>${Number(current.member_count || current.members?.length || 0)} participantes · ${E(T('Ver integrantes'))}</small></span></button>`;
    } else if (current.other.profile_url) {
      title = `<button type="button" class="chat-profile chat-profile-button" data-action="member" data-id="${Number(current.other.id)}" aria-label="Ver perfil de ${E(current.other.name)}">${avatar(current.other)}<span><strong>${E(current.other.name)}</strong><small>Ver perfil</small></span></button>`;
    } else {
      title = `<span class="chat-profile">${avatar(current.other)}<strong>${E(current.other.name)}</strong></span>`;
    }
    const request = current.conversation_request || {};
    let action = '';
    let requestBanner = '';
    if (group) {
      action = btn(I('more') + ' ' + T('Grupo'),'group-menu',`data-id="${Number(current.id)}"`,'ghost small');
    } else if (request.state === 'incoming_pending') {
      action = `${btn('Aceptar','conversation-request-respond',("data-id=\"" + (Number(current.other.id)) + "\" data-request=\"" + (Number(request.request_id)) + "\" data-decision=\"accept\""),'primary small')}${btn('Rechazar','conversation-request-respond',("data-id=\"" + (Number(current.other.id)) + "\" data-request=\"" + (Number(request.request_id)) + "\" data-decision=\"reject\""),'small')}`;
      requestBanner = `<div class="chat-request-banner"><strong>${E(T('Solicitud de conversación'))}</strong><span>${E(T('Puedes leer este mensaje antes de decidir. Acepta para poder responder.'))}</span></div>`;
    } else if (request.state === 'outgoing_pending') {
      action = btn('Cancelar solicitud','conversation-request-cancel',`data-id="${Number(current.other.id)}"`,'ghost danger small');
      requestBanner = `<div class="chat-request-banner outgoing"><strong>${E(T('Solicitud enviada'))}</strong><span>${E(T('La otra persona ya recibió tu mensaje. Podrás seguir escribiendo cuando acepte.'))}</span></div>`;
    } else {
      action = btn(current.blocked_by_me ? "Desbloquear" : "Bloquear", "block", `data-id="${Number(current.other.id)}" data-active="${!current.blocked_by_me}"`, "ghost small");
    }
    action += btn(T('Ocultar conversación'), 'chat-hide', `data-id="${Number(current.id)}"`, 'ghost small');
    if (!group && !current.can_message && !current.blocked && !['incoming_pending','outgoing_pending'].includes(request.state)) requestBanner = `<div class="chat-request-banner">${E(T('Conexión eliminada: puedes consultar el historial, pero no enviar mensajes.'))}</div>`;
    let messagePlaceholder='Escribe un mensaje…';
    if (!current.can_message) messagePlaceholder=['incoming_pending','outgoing_pending'].includes(request.state) ? 'Acepta la solicitud para continuar la conversación' : 'Historial de solo lectura';
    document.querySelector('.chat-conversation').innerHTML = `<div class="chat-title">${btn('← Chats','chat-back','aria-label="Volver a la lista de chats"','ghost small chat-mobile-back')}${title}<div class="chat-title-actions">${action}</div></div>${requestBanner}<div class="chat-messages" id="chat-messages" role="log" aria-label="Mensajes de la conversación" aria-live="polite" aria-relevant="additions"></div><form class="chat-compose" data-form="message" data-id="${chat.id}"><textarea name="body" aria-label="Escribir mensaje" placeholder="${E(T(messagePlaceholder))}" required maxlength="5000"></textarea><button class="btn primary">${I("contact")} Enviar</button></form>`;
    document.querySelector('.chat-compose textarea').value = chatDrafts.get(chat.id) || '';
    chatControls(current); chatSidebar();
  }
  async function messages() {
    const previous = document.querySelector('.chat-compose');
    if (previous) chatDrafts.set(Number(previous.dataset.id), previous.elements.body.value);
    stopChat();
    const chat = S.chat = { active: true, id: 0, current:null, pending: null, syncing: false, halted: false };
    const conversations = await api('conversations?' + new URLSearchParams({q: S.filter.q || ''}));
    if (!chatAlive(chat)) return;
    S.conversations = conversations;
    const urlSelected = Number(new URLSearchParams(location.search).get('conversation')) || 0;
    let selected = S.conversation || urlSelected || 0;
    let current = selected ? conversations.find(c => Number(c.id) === selected) : null;
    if (selected && !current) current = await api('conversations/' + selected);
    if (current && isIncomingRequest(current)) S.messageTab = 'requests';
    else if (current && urlSelected) S.messageTab = 'chats';
    if (!current && !mobileMessaging()) {
      const first = conversationBucket(S.messageTab, conversations)[0];
      selected = Number(first?.id) || 0;
      current = first || null;
    }
    if (!chatAlive(chat)) return;
    const headingActions = `<div class="chat-heading-actions">${link('directorio', I('plus') + ' Nueva conversación', 'primary')}${btn(I('users') + ' Crear grupo','group-chat-open','','small')}</div>`;
    content().innerHTML = heading('Mensajería', 'Conversaciones directas y grupales para colaborar dentro de ASCLA.', headingActions) + `<div class="chat-sync" id="chat-sync" role="status" hidden></div><form class="filters" data-form="filters"><input name="q" aria-label="Buscar conversaciones" placeholder="Buscar conversaciones o grupos…" value="${E(S.filter.q || '')}"><button class="btn">Buscar</button></form><div class="chat-layout ${current ? 'conversation-open' : 'conversation-list-open'}"><aside class="chat-sidebar" aria-label="Conversaciones"></aside><section class="chat-conversation">${empty('Inicia una conversación', 'Puedes conversar con una conexión, aceptar una solicitud de conversación o crear un grupo.')}</section></div>`;
    chatSidebar();
    if (current) openChat(chat, current);
    await syncChat();
  }
  async function loadMessages(chat = S.chat) {
    if (!chatAlive(chat) || !chat.id) return;
    if (chat.pending) return chat.pending;
    chat.pending = (async () => {
      const initial = !chat.loaded;
      const data = await api(`conversations/${chat.id}/messages${initial ? '' : '?after=' + chat.last}`);
      if (!chatAlive(chat)) return;
      const area = document.getElementById('chat-messages');
      if (!area) return;
      const bottom = area.scrollHeight - area.scrollTop - area.clientHeight < 100;
      if (initial && data.has_more) area.insertAdjacentHTML('afterbegin', btn('Cargar anteriores', 'older-messages', `data-before="${data.before}"`, 'small'));
      for (const m of data.items) {
        if (chat.ids.has(Number(m.id))) continue;
        chat.ids.add(Number(m.id)); area.insertAdjacentHTML('beforeend', messageBubble(m));
      }
      applyReadState(data.read_state);
      chat.last = Math.max(chat.last, Number(data.after)); chat.loaded = true;
      chat.more = !initial && data.has_more;
      if (chat.last && !document.hidden && document.hasFocus() && chatAlive(chat)) await api(`conversations/${chat.id}/read`, { last: chat.last });
      if (initial || bottom) area.scrollTop = area.scrollHeight;
    })();
    try { await chat.pending; } finally { chat.pending = null; }
  }
  async function olderMessages(button) {
    const chat = S.chat;
    const data = await api(`conversations/${chat.id}/messages?before=${button.dataset.before}`);
    if (!chatAlive(chat) || !button.isConnected) return;
    const area = document.getElementById('chat-messages'), height = area.scrollHeight, top = area.scrollTop;
    const items = data.items.filter(m => !chat.ids.has(Number(m.id)));
    items.forEach(m => chat.ids.add(Number(m.id)));
    button.outerHTML = (data.has_more ? btn('Cargar anteriores', 'older-messages', `data-before="${data.before}"`, 'small') : '') + items.map(messageBubble).join('');
    applyReadState(data.read_state);
    area.scrollTop = top + area.scrollHeight - height;
  }
  async function syncChatData(chat) {
    const conversations = await api('conversations?' + new URLSearchParams({q: S.filter.q || ''}));
    if (!chatAlive(chat)) return null;
    S.conversations = conversations;
    if (!chat.id && conversations.length && !mobileMessaging()) openChat(chat, conversations[0]);
    let current = conversations.find(c => Number(c.id) === chat.id);
    if (chat.id && !current) current = await api('conversations/' + chat.id);
    if (!chatAlive(chat)) return null;
    if (current) { chat.current=current; chatControls(current); }
    await loadMessages(chat);
    if (!chatAlive(chat)) return null;
    if (current && !chat.more && !document.hidden && document.hasFocus()) current.unread = 0;
    chatSidebar();
    chatStatus('');
    return chat.more ? 100 : 2000;
  }
  function haltChat(chat, error) {
    chat.halted = true;
    const form = document.querySelector('.chat-compose');
    if (form) chatDrafts.set(Number(form.dataset.id), form.elements.body.value);
    const pane = document.querySelector('.chat-conversation');
    if (pane) pane.innerHTML = empty('Conversación no disponible', error.message) + link('directorio', 'Revisar mis conversaciones', 'small');
    chatStatus(error.message || 'No se puede acceder a los mensajes. Recarga la página para revisar tu sesión.', true);
  }
  function syncChatFailure(chat, error) {
    if ([401, 403, 404].includes(error.status)) {
      haltChat(chat, error);
      return 2000;
    }
    chatStatus('Sin conexión con el servidor. Reintentando…', true);
    return 8000;
  }
  async function syncChat() {
    const chat = S.chat;
    if (!chatAlive(chat) || chat.syncing || chat.halted) return;
    clearTimeout(S.poll);
    if (document.hidden) return;
    chat.syncing = true;
    let delay = 2000;
    try {
      delay = await syncChatData(chat) ?? delay;
    } catch (error) {
      if (!chatAlive(chat) || error.name === 'AbortError') return;
      delay = syncChatFailure(chat, error);
    } finally {
      chat.syncing = false;
      if (chatAlive(chat) && !chat.halted && !document.hidden) S.poll = setTimeout(syncChat, delay);
    }
  }
  async function intro(id) {
    const r = await api("matching/" + id + "/intro");
    modal(
      "Mensaje sugerido",
      `<p class="alert">${E(T("Borrador sugerido según intereses compartidos. Puedes editarlo libremente antes de enviarlo."))}</p><form data-form="intro" data-id="${id}">${field("body", "Mensaje", r.text, "textarea", 'required maxlength="5000"')}<div class="form-actions">${btn("Cancelar", "close")}<button class="btn primary">Enviar mensaje</button></div></form>`,
    );
  }
  function assistantSources(r) {
    const links = (r?.sources || []).map(source => `<a href="${E(safeURL(source.url))}">${I(source.kind === "event" ? "calendar" : "book")} ${E(source.title)}</a>`).join("");
    return links ? `<div class="sources">${links}</div>` : "";
  }
  function assistantAnswerBody(r) {
    return `<div class="assistant-message-meta"><span>${I("spark")} ASCLA</span><small>${E(r?.mode || S.boot.ai_mode)}</small></div><div class="assistant-message-copy">${E(r?.answer || T("No pude preparar una respuesta."))}</div>${assistantSources(r)}`;
  }
  function assistantUserBubble(question) {
    return `<article class="assistant-message user"><div class="assistant-message-meta"><span>${E(T("Tú"))}</span></div><div class="assistant-message-copy">${E(question)}</div></article>`;
  }
  function assistantBotBubble(content = "", id = "") {
    return `<article class="assistant-message assistant" ${id ? ("id=\"" + (E(id)) + "\"") : ""}>${content}</article>`;
  }
  function assistantLoadingBody(status = "pending") {
    const label = status === "processing" ? T("Consultando la intranet…") : T("Preparando respuesta…");
    return `<div class="assistant-message-meta"><span>${I("spark")} ASCLA</span></div><div class="assistant-thinking"><span></span><span></span><span></span><em>${E(label)}</em></div>`;
  }
  function assistantScroll() {
    const area = document.getElementById("assistant-messages");
    if (area) area.scrollTop = area.scrollHeight;
  }
  async function assistant(fresh = false) {
    const thread = assistantThread(fresh), version = ++S.assistantVersion, view = S.viewVersion;
    const current = () => S.page === "asistente" && view === S.viewVersion && version === S.assistantVersion && thread === S.assistantThread;
    try {
      // A new conversation needs no history request and clears the UI immediately.
      const rows = fresh ? [] : await api("assistant/thread?" + new URLSearchParams({ thread }));
      if (current()) renderAssistant(rows);
    } catch (error) { if (current()) throw error; }
  }
  function renderAssistant(rows) {
    const suggestions = [
      "¿Hay alguna reunión o evento pronto?",
      "¿Qué se publicó recientemente?",
      "¿Qué recursos tenemos sobre gobierno corporativo?",
      "¿A quién me recomiendas conocer?",
    ];
    content().innerHTML = `<section class="assistant-intro"><div class="assistant-mark">${I("spark")}</div><div class="eyebrow">${E(T("ASISTENTE ASCLA"))}</div><h1>${E(T("Pregunta a tu comunidad ASCLA"))}</h1><p>${E(T("Conversa con el asistente sobre eventos, publicaciones, recursos y conexiones. Primero consulta la información disponible en la propia intranet."))}</p><span class="demo-badge assistant-mode-badge">${E(S.boot.ai_mode)}</span></section><div class="ask-suggestions">${suggestions.map(q => btn(E(T(q)) + " " + I("arrow"), "ask-suggestion", ("data-question=\"" + (E(T(q))) + "\""))).join("")}</div><section class="assistant-chat-shell"><header class="assistant-chat-head"><div><strong>${E(T("Conversación con ASCLA"))}</strong><span>${E(T("Los datos internos actuales tienen prioridad sobre el historial de la conversación."))}</span></div><div>${btn(I("plus") + " " + T("Nueva conversación"), "assistant-new", "", "ghost small")}${btn(I("clock") + " " + T("Historial"), "answer-history", "", "ghost small")}</div></header><div class="assistant-messages" id="assistant-messages" role="log" aria-live="polite"><article class="assistant-message assistant welcome"><div class="assistant-message-meta"><span>${I("spark")} ASCLA</span></div><div class="assistant-message-copy">${E(T("Hola. Puedo revisar lo que hay en ASCLA y ayudarte a encontrar eventos próximos, publicaciones recientes, recursos y conexiones recomendadas. ¿Qué quieres saber?"))}</div></article>${rows.map(j => {
      const user = assistantUserBubble(j.question);
      if (j.status === "completed" && j.result) return user + assistantBotBubble(assistantAnswerBody(j.result), ("assistant-job-" + (j.id) + ""));
      if (j.status === "error") return user + assistantBotBubble(("<div class=\"error\">" + (E(T(j.error || "No se pudo completar la consulta."))) + " " + (btn(T("Reintentar"), "retry-job", ("data-id=\"" + (j.id) + "\""), "small")) + "</div>"), ("assistant-job-" + (j.id) + ""));
      return user + assistantBotBubble(assistantLoadingBody(j.status), ("assistant-job-" + (j.id) + ""));
    }).join("")}</div><form class="assistant-compose" data-form="ask"><textarea name="question" aria-label="${E(T("Tu pregunta"))}" placeholder="${E(T("Escribe una pregunta sobre ASCLA…"))}" required maxlength="2000" rows="2"></textarea><button class="btn primary" aria-label="${E(T("Enviar pregunta"))}">${I("arrow")} <span>${E(T("Enviar"))}</span></button></form><p class="private-note assistant-grounding-note">${E(T("El asistente prioriza la información que tu cuenta puede consultar en ASCLA. Si un dato no puede verificarse dentro de la intranet, te lo indicará."))}</p></section>`;
    rows.filter(j => ["pending", "processing"].includes(j.status)).forEach(j => {
      const target = document.getElementById(`assistant-job-${j.id}`);
      if (target) watchJob(j.id, target, result => { target.innerHTML = assistantAnswerBody(result); assistantScroll(); });
    });
    assistantScroll();
  }
  async function watchJob(id, target, onDone, onError = null) {
    if (!target.isConnected) return;
    let attempts = 0;
    const assistantMode = target.classList?.contains('assistant-message');
    target.innerHTML = assistantMode ? assistantLoadingBody('pending') : '<div class="alert">Pendiente · Estamos preparando la respuesta…</div>';
    const poll = async () => {
      if (!target.isConnected) return;
      try {
        const j = await api('jobs/' + id);
        if (!target.isConnected) return;
        if (j.status === 'completed') { onDone(j.result);return; }
        if (j.status === 'error') { await showJobError(target,id,j.error,onError);return; }
        const jobStatusLabel = j.status === 'processing' ? 'Procesando' : 'Pendiente';
        target.innerHTML = assistantMode ? assistantLoadingBody(j.status) : `<div class="alert">${I('clock')} ${jobStatusLabel} · Trabajo #${id}</div>`;
        if (++attempts < 90) setTimeout(poll, 2500);
        else target.innerHTML = assistantMode ? `<div class="assistant-message-meta"><span>${I('spark')} ASCLA</span></div><div class="assistant-message-copy">${E(T('La consulta sigue procesándose. Puedes volver al asistente más tarde para ver la respuesta.'))}</div>` : '<div class="alert">El trabajo continúa en segundo plano. Recibirás una notificación cuando esté listo.</div>';
      } catch (error) { target.innerHTML = `<div class="error">${E(error.message)}</div>`; }
    };
    setTimeout(poll, 1800);
  }
  async function contact() {
    const list = await api("content/contact?mine=1");
    const questions = [
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
      ].map(([q, a]) => `<details class="contact-question"><summary>${E(T(q))}${I("plus")}</summary><p>${E(T(a))}</p></details>`).join("");
    const requests = list.items.map(p => {
      const state = Object.hasOwn(requestLabels, p.meta.request_status) ? p.meta.request_status : "open";
      return `<article class="contact-request"><div class="contact-request-heading"><h3>${E(p.title)}</h3><span class="contact-status ${state}">${E(T(requestLabels[state]))}</span></div><p class="contact-request-date">#${Number(p.id)} · ${date(p.date)}</p>${supportHistory(p)}</article>`;
    }).join("");
    content().innerHTML = `<div class="contact-workspace">
      <header class="contact-heading"><span class="contact-symbol">${I("mail")}</span><div><span class="eyebrow">${E(T("CONTACTO ASCLA"))}</span><h1>${E(T("Estamos para ayudarte"))}</h1><p>${E(T("Consultas, sugerencias y soporte para tu experiencia en ASCLA."))}</p></div></header>
      <div class="contact-layout"><form class="card contact-form" data-form="contact" aria-labelledby="contact-form-title">
        <div class="contact-card-heading"><span class="contact-section-icon">${I("contact")}</span><div><h2 id="contact-form-title">${E(T("Envíanos tu consulta"))}</h2><p>${E(T("Cuéntanos qué necesitas y el equipo dará seguimiento a tu solicitud."))}</p></div></div>
        ${field("title", "Asunto", "", "text", `required maxlength="200" placeholder="${E(T("Resume el motivo de tu consulta"))}"`)}
        ${select("category", "Categoría", S.boot.support_categories)}
        ${field("body", "Mensaje", "", "textarea", `required maxlength="5000" placeholder="${E(T("Describe tu consulta e incluye los detalles que nos ayuden a atenderte."))}"`)}
        <input name="website_confirm" tabindex="-1" autocomplete="off" class="ascla-honeypot" aria-hidden="true">
        <div class="contact-form-footer"><span>${E(T("Podrás consultar las respuestas en Mis solicitudes."))}</span><button class="btn primary" type="submit">${E(T("Enviar solicitud"))}${I("arrow")}</button></div>
        <div class="contact-privacy">${I("shield")}<p>${E(T("Solo tú y la persona asignada de administración o del equipo ejecutivo pueden ver tu solicitud."))}</p></div>
      </form><div class="contact-sidebar">
        <section class="card contact-faq" aria-labelledby="contact-faq-title"><div class="contact-card-heading"><span class="contact-section-icon">${I("book")}</span><h2 id="contact-faq-title">${E(T("Preguntas frecuentes"))}</h2></div>${questions}</section>
        <section class="card contact-requests" aria-labelledby="contact-requests-title"><div class="contact-card-heading"><span class="contact-section-icon">${I("clock")}</span><div><h2 id="contact-requests-title">${E(T("Mis solicitudes"))}</h2><p>${E(T("Consulta el estado y el historial de atención."))}</p></div></div>${requests || `<div class="contact-no-requests">${I("mail")}<p>${E(T("Aún no has enviado solicitudes."))}</p></div>`}</section>
      </div></div></div>`;
  }
  function notificationCount(total) {
    const badge = document.querySelector(".notification-count"), bell = document.querySelector('[data-action="notifications"]');
    if (badge) { badge.hidden = !total; badge.setAttribute("aria-hidden", "true"); badge.textContent = total > 99 ? "99+" : total; }
    const overflow=total > 99 ? " 99+" : "";
    const summary=total ? `${overflow}, ${total} ${T("sin leer")}` : `, ${T("estás al día")}`;
    bell?.setAttribute("aria-label", T("Notificaciones") + summary);
  }
  async function refreshNotifications() {
    try { notificationCount((await api("notifications/summary")).unread_total); } catch {}
  }
  function trimTrailingSlashes(path) {
    let end = path.length;
    while (end > 1 && path.codePointAt(end - 1) === 47) end--;
    return path.slice(0, end);
  }
  function notificationPageKey(url) {
    try {
      const target = new URL(url, location.href);
      const targetPath = trimTrailingSlashes(target.pathname);
      return Object.entries(C.pages || {}).find(([, page]) => {
        try {
          const pagePath = new URL(page.url, location.href).pathname;
          return trimTrailingSlashes(pagePath) === targetPath;
        } catch { return false; }
      })?.[0] || '';
    } catch { return ''; }
  }
  function liveToastRedundant(notice) {
    if (document.querySelector('.notification-modal')) return true;
    let target;
    try { target = new URL(notice.url, location.href); } catch { return false; }
    const targetPage = notificationPageKey(target.href);
    const conversation = Number(target.searchParams.get('conversation') || 0);
    if (conversation && S.page === 'mensajeria' && Number(S.conversation) === conversation) return true;
    const item = Number(target.searchParams.get('item') || 0);
    if (item && targetPage === S.page && Number(S.item?.id || new URLSearchParams(location.search).get('item') || 0) === item) return true;
    const member = Number(target.searchParams.get('member') || 0);
    if (member && targetPage === S.page && Number(new URLSearchParams(location.search).get('member') || 0) === member) return true;
    if (targetPage && targetPage === S.page && !conversation && !item && !member) return true;
    return false;
  }
  async function openLiveNotification(notice) {
    try {
      const destination = await api(`notifications/${Number(notice.id)}/open`, {});
      await refreshNotifications();
      await navigateTo(destination.url);
    } catch (error) { if (error.name !== 'AbortError') toast(error.message); }
  }
  async function pollLiveNotifications() {
    if (document.hidden || !liveToasts || S.liveToastPolling) return;
    S.liveToastPolling = true;
    try {
      const feed = await api(`notifications/toasts?after=${Math.max(0, Number(S.liveToastCursor) || 0)}`);
      S.liveToastCursor = Math.max(Number(S.liveToastCursor) || 0, Number(feed.cursor) || 0);
      notificationCount(Number(feed.unread_total) || 0);
      liveToasts.showMany(feed.items || []);
    } catch (error) {
      if (error.name !== 'AbortError' && error.status === 401) location.assign(location.href);
    } finally {
      S.liveToastPolling = false;
    }
  }
  async function notifications() {
    const feed = await api("notifications/feed?" + new URLSearchParams({ filter: S.noticeFilter, page: S.noticePage }));
    S.noticePage = feed.page;
    notificationCount(feed.unread_total);
    const html = globalThis.ASCLANotifications({ E, I, btn, T, locale: C.locale || "es-PE" }).panel(feed, S.noticeFilter);
    const existing = document.querySelector(".notification-modal .modal-content");
    if (existing) { existing.innerHTML = html; document.querySelector(`.activity-filters [data-filter="${S.noticeFilter}"]`)?.focus(); }
    else { modal(T("Tus notificaciones"), html); document.querySelector(".modal").classList.add("notification-modal"); S.focus = document.querySelector('[data-action="notifications"]'); }
  }
  async function assistantHistoryData(path) {
    const version = S.assistantVersion, view = S.viewVersion;
    const current = () => S.page === "asistente" && version === S.assistantVersion && view === S.viewVersion;
    try {
      const data = await api(path);
      return current() ? data : null;
    } catch (error) { if (current()) { throw error; } return null; }
  }
  async function restoreAnswer(id) {
    const j = await assistantHistoryData("jobs/" + id);
    if (!j) return;
    if (j.kind !== "answer") { toast("Este aviso corresponde a una tarea de administración."); return; }
    if (j.thread) {
      assistantThread(false, j.thread);
      await assistant();
      if (S.assistantThread !== j.thread) return;
      const target = document.getElementById(`assistant-job-${j.id}`);
      target?.scrollIntoView({ block: "center" });
      return;
    }
    const area = document.getElementById("assistant-messages");
    if (area) area.insertAdjacentHTML("beforeend", assistantUserBubble(j.question) + assistantBotBubble(j.status === "completed" ? assistantAnswerBody(j.result) : assistantLoadingBody(j.status), `assistant-job-${j.id}`));
    const target = document.getElementById(`assistant-job-${j.id}`);
    if (j.status !== "completed" && target) await watchJob(id, target, result => { target.innerHTML = assistantAnswerBody(result); });
    target?.scrollIntoView({ block: "center" });
  }
  async function answerHistory() {
    const rows = await assistantHistoryData("answers");
    if (!rows) return;
    modal(T("Mis consultas anteriores"), `<p class="private-note">${E(T("Tus últimas 50 consultas, guardadas para volver a ellas cuando las necesites."))}</p>${rows.map(j => {
      const url = new URL(C.pages.asistente.url); url.searchParams.set("job", j.id);
      const status = T({ completed: "Respuesta lista", pending: "Pendiente", processing: "Preparando respuesta", error: "Necesita atención" }[j.status] || j.status);
      return ("<a class=\"answer-history-entry\" href=\"" + (E(url.href)) + "\"><span>" + (I("spark")) + "</span><span><strong>" + (E(j.question)) + "</strong><small>" + (E(status)) + " · " + (date(j.created_at)) + "</small></span>" + (I("arrow")) + "</a>");
    }).join("") || empty("Aún no tienes consultas", "Haz tu primera pregunta al asistente para empezar.")}`);
  }
  const requestLabels = {open:"Pendiente", progress:"En revisión", resolved:"Resuelta", closed:"Cerrada"};
  function supportHistory(p) {
    const entries = p.meta.request_history || [];
    if (!entries.length) return "";
    return '<details class="request-history"><summary>'+E(T("Historial de atención"))+'</summary><ol>'+entries.map(h=>{
      const label = h.kind === "assignment" ? T("Responsable actualizado") : T(requestLabels[h.to] || h.to || "");
      return '<li><strong>'+E(label)+'</strong><span>'+E(h.actor || "")+' · '+date(h.at)+'</span>'+(h.response?'<p class="detail-body">'+E(h.response)+'</p>':'')+'</li>';
    }).join("")+'</ol></details>';
  }
  function supportCard(p, staff) {
    const state = p.meta.request_status || "open";
    const next = {open:"progress", progress:"resolved", resolved:"closed"}[state];
    const responsible = p.can_handle ? T('Asignada a ti') : staff.find(u=>Number(u.id)===Number(p.assignee))?.name || T('Sin responsable');
    const assignment = S.boot.admin ? '<details class="support-assignment" '+(p.redacted?'open':'')+'><summary>'+E(T('Cambiar responsable'))+'</summary><form data-form="contact-assign" data-id="'+p.id+'">'+select("assignee","Responsable",[[0,"Sin responsable"],...staff.map(u=>[u.id,u.name])],p.assignee)+'<button class="btn small">'+E(T("Asignar responsable"))+'</button></form></details>' : "";
    const edit = p.can_handle && state !== "closed"
      ? '<section class="support-reply"><h3>'+E(T('Responder y actualizar estado'))+'</h3><form data-form="contact-update" data-id="'+p.id+'">'+field("response","Respuesta al asociado","","textarea",'rows="3" maxlength="10000"')+'<div class="support-reply-actions">'+select("status","Estado",[[state,requestLabels[state]],...(next?[[next,requestLabels[next]]]:[])],next || state)+'<button class="btn primary">'+E(T("Guardar atención"))+'</button></div></form></section>' : "";
    const body = p.redacted ? '<div class="support-reserved">'+I('shield')+'<p>'+E(T("Contenido reservado al asociado y al responsable asignado."))+'</p></div>'
      : '<section class="support-message"><h3>'+E(T('Mensaje del asociado'))+'</h3><p class="request-body">'+E(p.body)+'</p></section>'+supportHistory(p)+edit;
    const archive = p.can_delete ? btn("Archivar solicitud","admin-delete",'data-kind="contact" data-id="'+p.id+'"',"small") : "";
    const category = !p.redacted && p.meta.description ? '<span>'+E(p.meta.description)+'</span>' : '';
    const author = !p.redacted ? '<span>'+E(p.author.name)+'</span>' : '';
    const archived = p.archived ? '<span class="support-archived">'+E(T('Archivada'))+'</span>' : '';
    return '<article class="request-card support-ticket '+E(state)+'" data-contact="'+p.id+'" data-state="'+E(state)+'"><details class="support-ticket-details" name="support-inbox" '+(Number(S.adminContactOpen)===Number(p.id)?'open':'')+'><summary><span class="support-ticket-icon">'+I(p.redacted?'shield':'mail')+'</span><span class="support-ticket-main"><strong>'+E(p.redacted?T('Consulta privada'):p.title)+'</strong><span class="support-ticket-meta"><span>#'+Number(p.id)+'</span>'+author+category+'<span>'+date(p.date)+'</span></span><span class="support-ticket-owner">'+I('users')+E(responsible)+'</span></span><span class="support-ticket-state"><span class="request-status '+E(state)+'">'+E(T(requestLabels[state]))+'</span>'+archived+'</span><span class="support-ticket-chevron">'+I('chevron')+'</span></summary><div class="support-ticket-content">'+body+assignment+(archive?'<div class="support-archive-action">'+archive+'</div>':'')+'</div></details></article>';
  }
  function adminPager(list, area) {
    if (list.pages < 2) return '';
    return `<div class="admin-pager">${btn("Anterior", "admin-page", ("data-area=\"" + (area) + "\" data-page=\"" + (list.page-1) + "\" " + (list.page===1?'disabled':'') + ""), "small")}<span>Página ${list.page} de ${list.pages}</span>${btn("Siguiente", "admin-page", ("data-area=\"" + (area) + "\" data-page=\"" + (list.page+1) + "\" " + (list.page===list.pages?'disabled':'') + ""), "small")}</div>`;
  }
  async function adminContacts(panel) {
    const f=S.adminFilters.contacts, list=await api('admin/contacts?'+new URLSearchParams(f));
    const total=Object.values(list.counts).reduce((sum,count)=>sum+Number(count),0);
    const filters=[['','Todas',total],...Object.entries(requestLabels).map(([state,label])=>[state,label,list.counts[state]])];
    panel.innerHTML='<section class="support-inbox"><div class="admin-section-heading"><div><h2>'+E(T('Consultas de Contacto'))+'</h2><p>'+E(T('Abre una consulta para leer el mensaje y responder.'))+'</p></div><span class="support-result-count">'+Number(list.total)+' '+E(T("resultados"))+'</span></div>'+
      '<nav class="support-status-filters" aria-label="'+E(T('Filtrar consultas por estado'))+'">'+filters.map(([state,label,count])=>btn('<span>'+E(T(label))+'</span><strong>'+Number(count)+'</strong>',"request-filter",'data-state="'+state+'" aria-pressed="'+((f.state||'')===state)+'"',"support-status-filter"+((f.state||'')===state?' active':''))).join('')+'</nav>'+
      '<form class="support-toolbar" data-form="admin-filter" data-area="contacts"><input type="hidden" name="state" value="'+E(f.state||'')+'"><div class="support-search">'+I('search')+'<input name="q" aria-label="'+E(T("Buscar solicitudes"))+'" placeholder="'+E(T('Buscar por asunto o mensaje…'))+'" maxlength="120" value="'+E(f.q||"")+'"></div><button class="btn" type="submit">'+E(T("Buscar"))+'</button>'+check("archived","Incluir archivadas",!!f.archived)+'</form>'+
      '<p class="support-privacy-note">'+I('shield')+'<span>'+E(T('Solo el asociado y su responsable pueden ver el mensaje. La búsqueda incluye tus consultas asignadas.'))+'</span></p>'+
      '<div class="request-list">'+(list.items.map(p=>supportCard(p,list.staff)).join("") || empty("No hay solicitudes en esta vista","Prueba otro estado o modifica la búsqueda."))+'</div>'+adminPager(list,"contacts")+'</section>';
    for (const detail of panel.querySelectorAll('.support-ticket-details')) detail.addEventListener('toggle',()=>{
      if (!detail.isConnected) return;
      const id=Number(detail.closest('[data-contact]').dataset.contact);
      if (detail.open) S.adminContactOpen=id;
      else if (S.adminContactOpen===id) S.adminContactOpen=0;
    });
  }
  function membershipFields(user = {}) {
    const states=[["active","Activa"],["suspended","Suspendida"],...(user.id?[["expired","Vencida"]]:[])];
    return '<div class="form-section">'+E(T("Membresía"))+'</div><div class="form-grid">'+
      select("membership_status","Estado de membresía",states,user.membership_status || "active")+
      field("membership_until","Vigente hasta",user.membership_until || "","date")+'</div><p class="private-note">'+
      E(T("Sin fecha límite, la membresía permanece vigente hasta que se suspenda. El vencimiento conserva el perfil y el historial."))+'</p>';
  }
  function adminUserCreator(roles) {
    const roleOptions=(roles||[]).map(r=>[r.id,r.name]);
    const dialog = modal(
      'Añadir usuario',
      `<form data-form="admin-user-create" data-guard-modal-unsaved class="admin-user-editor admin-user-creator"><div class="admin-user-editor-head admin-user-create-head"><span class="admin-user-create-icon">${I('users')}</span><div><span class="eyebrow">NUEVA CUENTA ASCLA</span><h3>Crear usuario de la comunidad</h3><p>La cuenta se crea directamente en ASCLA, sin abrir el panel de WordPress.</p></div></div><div class="form-section">Datos de acceso</div><div class="form-grid">${field('login','Nombre de usuario','','text','required maxlength="60" autocomplete="off" spellcheck="false" placeholder="ej. maria.perez"')}${field('email','Correo electrónico','','email','required maxlength="100" autocomplete="email" placeholder="nombre@correo.com"')}${select('role','Rol en ASCLA',roleOptions,'ascla_member')}</div>${membershipFields()}<p class="private-note full">El nombre de usuario se utilizará para iniciar sesión y no se podrá cambiar desde esta pantalla después de crear la cuenta.</p><div class="form-section">Información del asociado</div><div class="form-grid">${profilePhoneFields()}${field('first_name','Nombres','','text','required maxlength="100" autocomplete="given-name"')}${field('last_name','Apellidos','','text','maxlength="100" autocomplete="family-name"')}${field('birth_date','Fecha de nacimiento','','date','min="1900-01-01" max="'+new Date().toISOString().slice(0,10)+'"')}${field('position','Cargo','','text','maxlength="200"')}${field('company','Empresa / organización','','text','maxlength="200"')}${memberTypeField()}</div><div class="admin-user-invite-box">${check('send_invite','Enviar un correo para que el usuario configure su contraseña',true)}<p>ASCLA genera una contraseña interna segura y no la muestra al administrador. El usuario recibe un enlace de WordPress para establecer su propia contraseña. Si el correo no llega, puede usar “¿Olvidaste tu contraseña?” desde <strong>/login/</strong>.</p></div><div class="admin-user-edit-note">${I('shield')}<div><strong>Acceso seguro desde el inicio</strong><p>La cuenta tendrá acceso según el rol elegido. Asociado, Ejecutivo y Moderador se gestionan desde ASCLA; los administradores técnicos continúan fuera de este formulario.</p></div></div><div class="form-actions"><button type="button" class="btn" data-action="close">Cancelar</button><button class="btn primary">${I('plus')} Crear usuario</button></div></form>`,
      true,
    );
    void setupProfilePhone(dialog.querySelector('form'));
  }
  function professionalRequestFields(u) {
    if (!u.professional_requests?.length) return '';
    const options=[['','Sin solicitud vinculada'],...u.professional_requests.map(r=>[r.id,'#'+r.id+' · '+r.title])];
    return '<details class="admin-edit-section"><summary>'+E(T('Vincular una consulta de Contacto (opcional)'))+'</summary><div>'+select('professional_request_id','Solicitud de Contacto',options)+
      '<p class="private-note">'+E(T('Puedes guardar directamente. Si vinculas una solicitud, quedará registrada con este cambio y se utilizará una sola vez.'))+'</p>'+
      '<p class="detail-body" data-professional-request-preview></p></div></details>';
  }
  function bindProfessionalRequest(form,u) {
    const selector=form.elements.professional_request_id;
    if (!selector) return;
    selector.addEventListener('change',()=>{
      const request=(u.professional_requests||[]).find(r=>String(r.id)===selector.value);
      form.querySelector('[data-professional-request-preview]').textContent=request?.body||'';
    });
    selector.dispatchEvent(new Event('change'));
  }
  function adminAccountPermissionsFields(u) {
    const selected = u.assigned_roles || [u.role];
    const choices = (u.roles || []).map(role => `<label class="completion-choice"><input type="checkbox" name="assigned_roles" value="${E(role.id)}" ${selected.includes(role.id) ? "checked" : ""}><span>${E(T(role.name))}</span></label>`).join("");
    const overrides = u.permission_overrides || {};
    const permissions = Object.entries(u.permission_options || {}).map(([cap, label]) => select("permission_" + cap, T(label), [["", T("Heredar del rol")], ["deny", T("Denegar")]], overrides[cap] === false ? "deny" : "")).join("");
    const approval = u.role_approval || {};
    const reference = approval.reference ? `<p class="private-note">${E(T("Autorización registrada"))}: ${E(approval.reference)} · ${E(approval.decision_date)}</p>` : "";
    return `<div class="form-section">${E(T("Roles y permisos individuales"))}</div><fieldset class="profile-completion-settings"><legend>${E(T("Roles asignados"))}</legend><div class="completion-choices">${choices}</div><p class="private-note">${E(T("El rol principal siempre está seleccionado. Acumular varios roles exige una autorización previa de la Junta registrada en ASCLA."))}</p><div data-board-approval>${reference}${field("board_reference", "Referencia del acta o autorización de la Junta", "", "text", 'maxlength="200"')}${field("board_date", "Fecha de la autorización", "", "date", 'max="'+new Date().toISOString().slice(0,10)+'"')}${check("board_confirmed", "Confirmo que la Junta autorizó los roles seleccionados", false)}</div></fieldset><p class="private-note">${E(T("Puedes denegar permisos del rol asignado. Este formulario no concede permisos superiores al rol."))}</p><div class="form-grid">${permissions}</div>`;
  }
  function bindAccountPermissions(form, u) {
    const primary = form.elements.role;
    let previous = primary.value;
    const refresh = () => {
      const choices = [...form.querySelectorAll('[name="assigned_roles"]')];
      for (const choice of choices) {
        choice.disabled = choice.value === primary.value;
        if (choice.disabled) choice.checked = true;
      }
      const selected = choices.filter(choice => choice.checked).map(choice => choice.value);
      const approval = u.role_approval || {};
      const covered = approval.reference && approval.recorded_at && approval.recorded_by && selected.every(role => (approval.roles || []).includes(role));
      const needs = selected.length > 1 && !covered;
      form.querySelector('[data-board-approval]').hidden = selected.length < 2;
      for (const key of ['board_reference', 'board_date', 'board_confirmed']) {
        form.elements[key].required = needs;
        form.elements[key].disabled = selected.length < 2;
      }
    };
    primary.addEventListener('change', () => {
      const old = form.querySelector('[name="assigned_roles"][value="'+previous+'"]');
      if (old) old.checked = false;
      previous = primary.value;
      refresh();
    });
    for (const choice of form.querySelectorAll('[name="assigned_roles"]')) choice.addEventListener('change', refresh);
    refresh();
  }
  async function adminUserEditor(id) {
    const u=await api(`admin/users/${Number(id)}`);
    const roleOptions=(u.roles||[]).map(r=>[r.id,r.name]);
    const dialog=modal(
      'Editar usuario',
      `<form data-form="admin-user-edit" data-id="${Number(u.id)}" data-guard-modal-unsaved class="admin-user-editor">
        <input type="hidden" name="profile_revision" value="${Number(u.profile_revision || 0)}">
        <div class="admin-user-editor-head">${avatar({name:[u.first_name,u.last_name].filter(Boolean).join(' ')||u.login,photo_url:u.photo_url},'lg')}<div><span class="eyebrow">CUENTA ASCLA</span><h3>${E([u.first_name,u.last_name].filter(Boolean).join(' ')||u.login)}</h3><p>@${E(u.login)} · ${E(u.email)}</p></div></div>
        <p class="private-note">${E(T('Edita los datos del asociado y guarda al terminar. Los campos con * son obligatorios.'))}</p>
        <div class="form-section">${E(T('Información personal y profesional'))}</div>
        <div class="form-grid">
          ${field('first_name','Nombres',u.first_name||'','text','required maxlength="100" autocomplete="given-name"')}
          ${field('last_name','Apellidos',u.last_name||'','text','required maxlength="100" autocomplete="family-name"')}
          ${field('position','Cargo',u.position||'','text','required maxlength="200"')}
          ${field('company','Empresa / organización',u.company||'','text','required maxlength="200"')}
          ${memberTypeField(u.member_type_code)}
          ${field('birth_date','Fecha de nacimiento',u.birth_date||'','date','min="1900-01-01" max="'+new Date().toISOString().slice(0,10)+'"')}
          ${profilePhoneFields(u).replaceAll('f-phone','admin-user-phone')}
          ${field('email','Correo electrónico',u.email,'email','required maxlength="100" autocomplete="email"')}
          ${profileLocationFields(u,true).replaceAll('f-country','admin-user-country').replaceAll('f-city','admin-user-city').replaceAll('ascla-country-options','admin-user-country-options').replaceAll('ascla-city-options','admin-user-city-options')}
        </div>
        <details class="admin-edit-section"><summary>${E(T('Biografía, experiencia y enlaces'))}</summary><div class="form-grid">
          ${field('bio','Biografía',u.bio||'','textarea','maxlength="3000"')}
          ${field('experience','Experiencia profesional',u.experience||'','textarea','maxlength="3000"')}
          ${field('linkedin','LinkedIn',u.linkedin||'','url','maxlength="200"')}
          ${field('twitter','X / Twitter',u.twitter||'','url','maxlength="200"')}
          ${field('website','Sitio web',u.website||'','url','maxlength="200"')}
        </div></details>
        <details class="admin-edit-section"><summary>${E(T('Intereses y objetivos'))}</summary><div class="profile-topics">${Object.entries(profileTax).map(([key,tax])=>profileTopic(key,tax,u,true)).join('')}</div></details>
        <details class="admin-edit-section"><summary>${E(T('Acceso y membresía'))}</summary><div>
          <div class="form-grid">${field('login','Nombre de usuario',u.login,'text','readonly aria-readonly="true"')}${select('role','Rol en ASCLA',roleOptions,u.role)}</div>${membershipFields(u)}
          <details class="admin-edit-section"><summary>${E(T('Permisos avanzados'))}</summary><div>${adminAccountPermissionsFields(u)}</div></details>
        </div></details>
        ${professionalRequestFields(u)}
        <div class="admin-user-edit-note">${I('shield')}<div><strong>${E(T('Cambios registrados'))}</strong><p>${E(T('El historial identifica al administrador, al usuario y los campos modificados. Las preferencias de privacidad y participación las conserva el asociado.'))}</p></div></div>
        <div class="form-actions"><button type="button" class="btn" data-action="close">Cancelar</button><button class="btn primary">${I('check')} ${E(T('Guardar cambios'))}</button></div>
      </form>`,
      true,
    );
    bindProfessionalRequest(dialog.querySelector("form"),u);
    bindAccountPermissions(dialog.querySelector("form"),u);
    const form=dialog.querySelector("form");
    form.addEventListener('invalid', event => {
      for (let detail=event.target.closest('details'); detail; detail=detail.parentElement.closest('details')) detail.open=true;
    },true);
    void setupProfilePhone(form);
    await setupProfileLocations(form);
  }
  function boardApprovalEditor(id) {
    const roles = [['administrator', 'Administrador'], ['ascla_executive', 'Ejecutivo ASCLA'], ['ascla_moderator', 'Moderador ASCLA'], ['ascla_member', 'Asociado ASCLA']];
    const choices = roles.map(([key, label]) => `<label class="completion-choice"><input type="checkbox" name="roles" value="${key}"><span>${E(T(label))}</span></label>`).join('');
    modal('Autorización de roles de la Junta', `<form data-form="board-approval" data-id="${Number(id)}" data-guard-modal-unsaved><p>${E(T('Registra una decisión previa de la Junta. Esta acción no asigna roles ni emite la autorización. Las cuentas técnicas continúan gestionándose desde WordPress.'))}</p><div class="completion-choices">${choices}</div>${field('board_reference','Referencia del acta o autorización de la Junta','','text','required maxlength="200"')}${field('board_date','Fecha de la autorización','','date','required max="'+new Date().toISOString().slice(0,10)+'"')}${check('board_confirmed','Confirmo que la Junta autorizó los roles seleccionados',false)}<button class="btn primary">${E(T('Registrar autorización'))}</button></form>`);
  }
  function adminUserCard(u, roles) {
    const accessClass = u.membership_status === 'active' && u.access_enabled !== false ? 'open' : 'closed';
    const accessLabel = u.membership_status === 'active' && u.access_enabled === false ? 'Acceso restringido por permisos' : ({active:'Acceso activo',suspended:'Acceso suspendido',expired:'Membresía vencida'}[u.membership_status] || 'Acceso activo');
    const technicalBadge = u.technical_admin ? '<span class="tag admin-technical-tag">Administración técnica</span>' : '';
    let actions = btn(I('users')+' Actividad e intereses','user-insights',`data-id="${Number(u.id)}"`,'primary small');
    if (u.profile_url) actions += btn('Ver perfil','member',("data-id=\"" + (Number(u.id)) + "\""),'small');
    if (u.can_record_role_approval) actions += btn('Autorización de roles','board-role-approval', 'data-id="'+Number(u.id)+'"','small');
    if (u.can_edit) actions += btn(I('edit')+' Editar usuario','user-edit',("data-id=\"" + (u.id) + "\""),'small');
    let accountActions='';
    if (u.can_suspend && u.membership_status !== 'expired') {
      const suspendLabel = u.suspended ? 'Reactivar acceso' : 'Suspender acceso';
      accountActions += btn(suspendLabel,'user-access',("data-id=\"" + (u.id) + "\" data-suspended=\"" + (!u.suspended) + "\" data-name=\"" + (E(u.name)) + "\""),'ghost small');
    }
    if (u.can_delete) accountActions += btn('Eliminar usuario','user-delete',("data-id=\"" + (u.id) + "\" data-name=\"" + (E(u.name)) + "\" data-login=\"" + (E(u.login)) + "\" data-email=\"" + (E(u.email)) + "\""),'danger small');
    if(accountActions) actions+=`<details class="admin-user-more"><summary>${E(T('Más opciones de cuenta'))}</summary><div>${accountActions}</div></details>`;
    const roleBadges = u.roles.map(r => "<span class=\"tag\">" + E(roles[r] || r) + "</span>").join('');
    return `<article class="admin-user" data-admin-user="${u.id}"><div class="admin-user-person">${avatar(u)}<div><h3>${E(u.name)}</h3><span>@${E(u.login)}</span>${u.email ? ("<a href=\"mailto:" + (E(u.email)) + "\">" + (E(u.email)) + "</a>") : ("<small>" + (E(T("Correo no registrado"))) + "</small>")}</div></div><div class="admin-user-access">${roleBadges}<span class="request-status ${accessClass}">${accessLabel}</span>${technicalBadge}<small>Registro: ${date(u.registered)}</small></div><div class="admin-user-actions">${actions}</div></article>`;
  }
  async function adminUsers(panel) {
    const f=S.adminFilters.users,list=await api('admin/users?'+directoryQuery(f));
    const roles=Object.fromEntries(list.roles.map(r=>[r.id,r.name]));
    const createAction = list.can_create ? btn(I('plus')+' Añadir usuario','user-create',("data-roles=\"" + (E(JSON.stringify(list.create_roles||[]))) + "\""),'primary') : '';
    const userCards = list.items.map(u => adminUserCard(u, roles)).join('') || empty('No encontramos usuarios','Prueba otro nombre o cambia los filtros.');
    panel.innerHTML=`<div class="admin-section-heading"><div><span class="eyebrow">PERSONAS Y ACCESOS</span><h2>${E(T('Usuarios e intereses'))}</h2><p>${E(T('Busca un asociado y abre Actividad e intereses para conocer su participación en la comunidad.'))}</p></div>${createAction}</div>${adminUserFilters(list.roles,f)}<p class="private-note">${list.total} usuarios encontrados</p><div class="admin-users">${userCards}</div>${adminPager(list,'users')}`;
  }
  function adminUserFilters(roles, f) {
    return `<form class="card directory-search admin-user-filters" data-form="admin-filter" data-area="users" role="search" aria-label="${E(T('Buscar usuarios'))}"><div class="directory-search-row"><div class="field"><label for="admin-user-search">${E(T('Buscar usuarios'))}</label><input type="search" id="admin-user-search" name="q" maxlength="100" placeholder="${E(T('Nombre, usuario, correo, cargo, empresa o experiencia…'))}" value="${E(f.q||'')}"></div><div class="field"><label for="admin-user-country">${E(T('País'))}</label><input type="search" id="admin-user-country" name="country" maxlength="120" placeholder="${E(T('Todos los países'))}" value="${E(f.country||'')}"></div><button class="btn primary">${I('search')} ${E(T('Buscar'))}</button>${btn('Limpiar filtros','admin-users-reset','','ghost')}</div><div class="directory-facets">${directoryTermFilter('industries','Industria',S.boot.catalogs.industry,f)}${directoryTermFilter('interests','Interés',S.boot.catalogs.interest,f)}${directoryTermFilter('areas','Área de conocimiento',S.boot.catalogs.area,f)}<span class="private-note">${E(T('Puedes elegir varias opciones en cada filtro.'))}</span></div><div class="form-grid admin-user-access-filters">${select('role','Rol',[['','Todos los roles'],...roles.map(r=>[r.id,r.name])],f.role||'')}${select('state','Acceso',[['','Todos'],['active','Activo'],['suspended','Suspendido'],['expired','Vencido']],f.state||'')}</div></form>`;
  }
  function adminPendingRow(p) {
    let badges = "";
    if (p.meta.generated) badges += " · IA";
    if (p.meta.chatham) badges += " · Chatham House";
    let publicationNote = "";
    if (!S.boot.admin && !S.boot.executive && ["gallery", "resource"].includes(p.type)) {
      publicationNote = "<small>Publicación administrativa</small>";
    }
    const review = btn("Revisar", "item", 'data-id="' + p.id + '"', "small");
    return `<tr role="row"><td role="cell"><strong>${E(p.title)}</strong><br><small>${E(typeLabel[p.type])}${badges}</small></td><td role="cell" data-label="${E(T('Autor'))}">${E(p.author.name)}</td><td role="cell">${status(p.status)}</td><td role="cell">${review}${publicationNote}</td></tr>`;
  }
  function adminReportCard(r) {
    const cardClass = r.reviewed ? "is-reviewed" : "";
    const title = r.title || "Publicación #" + r.target_id;
    const stateClass = r.reviewed ? "success" : "";
    const stateLabel = r.reviewed ? "Revisado" : "Pendiente";
    const excerpt = r.excerpt ? `<p>${E(r.excerpt)}</p>` : "";
    const detail = r.detail ? `<br><b>Detalle:</b> ${E(r.detail)}` : "";
    const reviewedBy = r.reviewed && r.reviewed_by_name
      ? `<br><b>${E(T("Revisado por:"))}</b> ${E(r.reviewed_by_name)}`
      : "";
    const open = Number(r.target_id)
      ? btn("Abrir contenido", "item", 'data-id="' + r.target_id + '"', "small")
      : "";
    const markReviewed = !r.reviewed && S.boot.moderator
      ? btn("Marcar como revisado", "report-reviewed", 'data-id="' + r.id + '"', "primary small")
      : "";
    const remove = r.can_delete
      ? btn("Eliminar reporte", "admin-delete", 'data-kind="report" data-id="' + r.id + '"', "danger small")
      : "";
    return `<div class="admin-report ${cardClass}"><div><div class="admin-report-heading"><strong>${E(title)}</strong><span class="tag ${stateClass}">${E(T(stateLabel))}</span></div>${excerpt}<p class="private-note"><b>Motivo:</b> ${E(r.reason_label || "Sin motivo registrado")}${detail}${reviewedBy}</p></div><div class="form-actions">${open}${markReviewed}${remove}</div></div>`;
  }
  function adminCommentCard(c) {
    const remove = c.can_delete
      ? btn("Eliminar comentario", "delete-comment", 'data-id="' + c.id + '"', "danger small")
      : "";
    const approve = btn("Aprobar", "comment-moderate", 'data-id="' + c.id + '" data-decision="approve"', "small");
    const reject = btn("Mantener oculto", "comment-moderate", 'data-id="' + c.id + '" data-decision="reject"', "small");
    return `<div class="comment"><strong>${E(c.author)}</strong><p>${E(c.body)}</p>${approve}${reject}${remove}</div>`;
  }
  function adminModeration(d) {
    const views = [['publicaciones','Publicaciones',d.pending.filter(p=>p.status==='pending').length,'book']];
    if (S.boot.moderator) views.push(['comentarios','Comentarios',d.comments.length,'hub'],['reportes','Reportes recibidos',d.reports.filter(r=>!r.reviewed).length,'shield']);
    if (!views.some(([key])=>key===S.moderationView)) S.moderationView='publicaciones';
    const navigation=views.map(([key,label,count,icon])=>`<button type="button" class="moderation-choice${S.moderationView===key?' active':''}" data-moderation-view="${key}" aria-pressed="${S.moderationView===key}">${I(icon)}<span>${E(T(label))}</span><strong>${Number(count)}</strong></button>`).join('');
    return `<div class="admin-section-heading"><div><h2>${E(T('Revisar contenido'))}</h2><p>${E(T('Elige qué quieres revisar. Cada sección muestra las acciones disponibles para tu rol.'))}</p></div></div><nav class="moderation-choices" aria-label="${E(T('Qué quieres revisar'))}">${navigation}</nav><section class="card moderation-queue" aria-live="polite">${moderationQueue(d,S.moderationView)}</section>`;
  }
  function moderationTable(items) {
    return `<div class="table-wrap"><table class="data-table moderation-publications" role="table"><thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">${E(T('Contenido'))}</th><th scope="col" role="columnheader">${E(T('Autor'))}</th><th scope="col" role="columnheader">${E(T('Estado'))}</th><th scope="col" role="columnheader">${E(T('Acción'))}</th></tr></thead><tbody role="rowgroup">${items.map(adminPendingRow).join('')}</tbody></table></div>`;
  }
  function moderationQueue(d, view) {
    if (view==='comentarios') return `<h3>${E(T('Comentarios por aprobar'))}</h3><p class="moderation-help">${E(T('Aprobar hace visible el comentario. Mantener oculto lo conserva sin publicar.'))}</p>${d.comments.map(adminCommentCard).join('') || empty('No hay comentarios por aprobar.')}`;
    if (view==='reportes') {
      const pending=d.reports.filter(r=>!r.reviewed),reviewed=d.reports.filter(r=>r.reviewed);
      const history=reviewed.length?`<details class="moderation-history"><summary>${E(T('Reportes ya revisados'))} <span>${reviewed.length}</span></summary>${reviewed.map(adminReportCard).join('')}</details>`:'';
      return `<h3>${E(T('Contenido reportado por la comunidad'))}</h3><p class="moderation-help">${E(T('Abre el contenido para comprobar el motivo. Marca el reporte como revisado cuando termines; esto no elimina la publicación ni el comentario.'))}</p>${pending.map(adminReportCard).join('') || empty('No hay reportes por revisar.')}${history}`;
    }
    const pending=d.pending.filter(p=>p.status==='pending'),drafts=d.pending.filter(p=>p.status==='draft');
    const saved=drafts.length?`<details class="moderation-history" ${pending.length?'':'open'}><summary>${E(T('Borradores guardados'))} <span>${drafts.length}</span></summary><p class="moderation-help">${E(T('Están sin publicar y aún se pueden editar. No son solicitudes de aprobación.'))}</p>${moderationTable(drafts)}</details>`:'';
    return `<h3>${E(T('Publicaciones por aprobar'))}</h3><p class="moderation-help">${E(T('Abre una publicación para revisar el texto y decidir si se publica.'))}</p>${pending.length?moderationTable(pending):empty('No hay publicaciones por aprobar.')}${saved}`;
  }
  let statisticsPanel, interestImports, memberInsights, alliesView;
  function alliesUI(){
    alliesView ||= globalThis.ASCLAAllies({root,api,E,T,I,modal,closeModal,toast,btn,field,select,date,config:C,safeURL,afterChange:render});
    return alliesView;
  }
  function validateSocialLinkInput(input) {
    let valid=!input.value;
    if (input.value) {
      try {
        const url=new URL(input.value),domains=input.name==='linkedin'?['linkedin.com']:['x.com','twitter.com'];
        valid=['http:','https:'].includes(url.protocol) && !url.username && !url.password && !url.port
          && domains.some(domain=>url.hostname===domain || url.hostname.endsWith('.'+domain));
      } catch { valid=false; }
    }
    input.setCustomValidity(valid?'':T(input.name==='linkedin'?'Introduce un enlace de linkedin.com.':'Introduce un enlace de x.com o twitter.com.'));
  }
  async function adminEditorialRegistry(panel) {
    const type=S.editorialType||'gallery',filter=S.editorialFilters||{};
    const list=await api('admin/editorial/'+type+'?'+new URLSearchParams(filter));
    if (!panel.isConnected) return;
    const rows=list.items.map(p=>{
      const change=p.can_moderate && !p.meta.publish_at && ['publish','ascla_hidden'].includes(p.status)
        ?btn(T(p.status==='publish'?'Ocultar':'Volver a publicar'),'editorial-state','data-id="'+p.id+'"','small '+(p.status==='publish'?'ghost danger':'primary')):'';
      const edit=p.can_edit?btn(T('Editar'),'editor','data-id="'+p.id+'" data-type="'+p.type+'"','small'):'';
      const state=p.meta.publish_at && p.status==='ascla_hidden'?status('scheduled'):status(p.status);
      return '<article class="ally-registry-row editorial-registry-row" data-editorial-record="'+p.id+'"><span class="ally-registry-logo">'+I(type==='gallery'?'gallery':'book')+'</span><div class="ally-registry-name"><h3>'+E(p.title)+'</h3><p>'+E(p.author.name)+' · '+date(p.date)+'</p></div>'+state+'<div class="form-actions">'+btn(T('Ver'),'item','data-id="'+p.id+'"','small')+edit+btn(T('Historial'),'editorial-history','data-id="'+p.id+'"','small')+change+'</div></article>';
    }).join('');
    panel.innerHTML='<div class="admin-section-heading"><div><h2>'+E(T('Registro de galerías y recursos'))+'</h2><p>'+E(T('Ocultar retira el contenido del catálogo y conserva sus archivos e historial.'))+'</p></div>'+
      btn(I('plus')+' '+E(T(type==='gallery'?'Crear galería':'Crear recurso')),'editor','data-type="'+type+'"','primary')+'</div>'+
      '<form class="filters admin-filters editorial-registry-filters" data-form="editorial-filter">'+
      select('type','Contenido',[['gallery','Galerías'],['resource','Centro de Conocimiento']],type)+
      field('q','Buscar por título o contenido',filter.q||'','search','maxlength="150"')+
      select('status','Estado',[['','Todos los estados'],['publish','Publicado'],['ascla_hidden','Oculto / programado'],['draft','Borrador'],['pending','Pendiente de revisión'],['ascla_rejected','Rechazado']],filter.status||'')+
      '<button class="btn primary" type="submit">'+E(T('Buscar'))+'</button></form><p class="private-note">'+Number(list.total)+' '+E(T('registros'))+'</p>'+
      (rows||empty('No hay contenido en esta vista','Prueba otro estado o modifica la búsqueda.'))+
      (list.pages>1?'<div class="admin-pager">'+btn(T('Anterior'),'editorial-page','data-page="'+(list.page-1)+'" '+(list.page===1?'disabled':''),'small')+'<span>'+list.page+' / '+list.pages+'</span>'+btn(T('Siguiente'),'editorial-page','data-page="'+(list.page+1)+'" '+(list.page===list.pages?'disabled':''),'small')+'</div>':'');
  }
  async function editorialAction(action,id) {
    const p=await api('items/'+id);
    if (!['gallery','resource'].includes(p.type)) return;
    if (action==='editorial-history') {
      const rows=await api('admin/editorial/'+p.type+'/'+id+'/history');
      modal(T('Historial de publicación'),'<h3>'+E(p.title)+'</h3><p>'+E(T('Estado actual'))+': '+status(p.status)+'</p>'+
        (rows.length?'<ol class="ally-history">'+rows.map(row=>'<li>'+status(row.to)+'<span>'+E(row.actor)+' · '+date(row.date,{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'})+'</span>'+(row.reason?'<p>'+E(row.reason)+'</p>':'')+'</li>').join('')+'</ol>':'<p>'+E(T('Todavía no hay cambios de estado registrados.'))+'</p>'));
      return;
    }
    const hidden=p.status==='publish',label=hidden?'Ocultar del catálogo':'Volver a publicar';
    modal(T(label),'<h3>'+E(p.title)+'</h3><p>'+E(T(hidden?'El contenido dejará de aparecer en el catálogo. Sus archivos e historial se conservarán.':'El contenido volverá a estar disponible en el catálogo de la comunidad.'))+'</p>'+
      '<form data-form="moderate" data-id="'+id+'"><input type="hidden" name="decision" value="'+(hidden?'hide':'approve')+'">'+
      field('reason','Motivo del cambio','','textarea','required maxlength="1000"')+
      (!hidden && p.meta.generated?check('reviewed','He revisado las fuentes, identidades y derechos.',false).replace('name="reviewed"','name="reviewed" required'):'')+
      '<div class="form-actions">'+btn(T('Cancelar'),'close')+'<button type="submit" class="btn '+(hidden?'danger':'primary')+'">'+E(T(label))+'</button></div></form>');
  }
  function adminTabs() {
    const tabs = [];
    if(S.boot.admin) tabs.push(['resumen','Resumen','home']);
    if (S.boot.executive) tabs.push(["estadisticas", "Estadísticas", "users"]);
    tabs.push(["moderacion", S.boot.moderator ? "Revisar contenido" : "Publicaciones", "shield"]);
    if (S.boot.admin || S.boot.executive) tabs.push(["solicitudes", "Consultas de Contacto", "contact"]);
    if (S.boot.admin || S.boot.executive) tabs.push(["contenidos", "Galerías y recursos", "gallery"]);
    if (S.boot.admin) tabs.push(["importar", "Importar intereses", "book"], ["usuarios", "Usuarios e intereses", "users"], ["archivos", "Archivos", "book"],['aliados','Aliados','ally']);
    if (S.boot.admin || S.boot.executive) tabs.push(["microeventos", "Encuentros", "calendar"]);
    if (S.boot.admin) tabs.push(["logs", "Historial de cambios", "clock"]);
    if (S.boot.configure) tabs.push(["configuracion", "Configuración", "settings"]);
    return tabs;
  }
  function adminShell(tabs, tab) {
    const groups=[['Vista general',['resumen','estadisticas']],['Comunidad',['usuarios','solicitudes','importar']],['Contenido',['moderacion','contenidos','archivos','microeventos','aliados']],['Herramientas',['logs','configuracion']]];
    const navigation=groups.map(([group,keys])=>{
      const entries=tabs.filter(([key])=>keys.includes(key));
      if(!entries.length) return '';
      return `<div class="admin-nav-group"><span>${E(T(group))}</span>${entries.map(([key,label,icon])=>btn(I(icon)+E(T(label)),'admin-tab',("data-tab=\"" + (key) + "\" aria-pressed=\"" + (tab===key) + "\""),'admin-tab'+(tab===key?' active':''))).join('')}</div>`;
    }).join('');
    const wordpress = C.wpAdminUrl ? `<a class="btn ghost admin-wordpress-link" href="${E(C.wpAdminUrl)}">${I('settings')}${E(T('Administrar WordPress'))}</a>` : '';
    return `<div class="admin-workspace admin-workspace-v108"><header class="admin-hero"><div class="admin-hero-main"><div class="admin-hero-copy"><span class="eyebrow">${E(T("GESTIÓN DE LA COMUNIDAD"))}</span><h1>${E(T("Administración ASCLA"))}</h1><p>${E(T("Personas, contenido y atención en un mismo lugar."))}</p></div></div><div class="admin-hero-actions">${S.boot.admin?'':link('intranet',E(T('Ver intranet'))+' '+I('arrow'),'ghost admin-intranet-link')}${wordpress}</div></header><div class="admin-workspace-body"><nav class="admin-tabs" aria-label="${E(T("Secciones de administración"))}">${navigation}</nav><section id="admin-panel" class="admin-panel" aria-live="polite"></section></div></div>`;
  }
  function adminOverview(panel,d) {
    const metrics=[[d.counts.members,'Cuentas ASCLA','users','usuarios'],[d.pending.length,'Borradores y pendientes','shield','moderacion'],[d.reports.filter(r=>!r.reviewed).length,'Reportes por revisar','bell','moderacion']];
    const cards=[['usuarios','users','Conoce a tu comunidad','Consulta intereses y actividad de cada asociado, o administra sus cuentas.','Explorar usuarios'],['moderacion','shield','Revisa el contenido','Atiende publicaciones, comentarios pendientes y reportes de la comunidad.','Revisar publicaciones'],['solicitudes','contact','Atiende las solicitudes','Responde consultas y gestiona las solicitudes recibidas en Contacto.','Ver solicitudes'],['estadisticas','calendar','Mide la participación','Consulta inscripciones, asistencias y tendencias por tema y período.','Ver estadísticas'],['microeventos','hub','Organiza los encuentros','Revisa propuestas y prepara círculos de conversación.','Ver encuentros']].filter(([key])=>adminTabs().some(([tab])=>tab===key));
    panel.innerHTML=`<div class="admin-section-heading"><div><span class="eyebrow">${E(T('TU ESPACIO DE TRABAJO'))}</span><h2>${E(T('¿Qué quieres gestionar hoy?'))}</h2><p>${E(T('Empieza por una tarea o explora la actividad de tu comunidad.'))}</p></div>${btn(I('clock')+' '+E(T('Actualizar')),'admin-refresh','','small')}</div><div class="admin-overview">${metrics.map(([n,label,icon,target])=>btn(("<span class=\"stat-icon\">" + (I(icon)) + "</span><span class=\"admin-stat-copy\"><strong>" + (n) + "</strong><small>" + (E(T(label))) + "</small></span>"),'admin-tab',("data-tab=\"" + (target) + "\""),'admin-stat')).join('')}</div><p class="private-note admin-count-scope">${E(T('Los contadores muestran los elementos disponibles en cada sección.'))}</p><div class="admin-task-grid">${cards.map(([key,icon,title,description,action])=>("<article class=\"card admin-task-card\"><span class=\"admin-task-icon\">" + (I(icon)) + "</span><h3>" + (E(T(title))) + "</h3><p>" + (E(T(description))) + "</p>" + (btn(E(T(action))+' '+I('arrow'),'admin-tab',("data-tab=\"" + (key) + "\""),'ghost')) + "</article>")).join('')}</div><div class="admin-help-note">${I('shield')}<p>${E(T('Las acciones de cuenta y moderación mantienen sus permisos, confirmaciones e historial de auditoría.'))}</p></div>`;
  }
  async function adminMemberInsights(id) {
    if(!S.boot.admin) return;
    memberInsights ||= globalThis.ASCLAMembers({root,api,E,T,date,I,avatar,back:async panel=>{
      if(S.page==='directorio') { closeModal(); return; }
      await adminUsers(panel);
    },backLabel:()=>S.page==='directorio'?'Volver al Directorio':'Volver a usuarios',toast});
    let panel = document.getElementById('admin-panel');
    if(S.page==='directorio') {
      const dialog = modal(T('Actividad e intereses'),'<section data-directory-insights></section>',true);
      panel = dialog.querySelector('[data-directory-insights]');
    }
    await memberInsights.open(panel,id);
  }
  async function adminStatisticsTab(panel) {
    if (!S.boot.executive) return;
    statisticsPanel ||= globalThis.ASCLAStatistics({root,api,E,T,I,modal,closeModal,toast,date});
    await statisticsPanel.load(panel);
  }
  async function adminImportTab(panel) {
    if (!S.boot.admin) return;
    interestImports ||= globalThis.ASCLAInterestImports({root,api,E,T,modal,closeModal,toast,date});
    await interestImports.load(panel);
  }
  async function adminUsersTab(panel) { if (S.boot.admin) await adminUsers(panel); }
  async function adminFilesTab(_panel) {
    if (S.boot.admin) await files(1, "", "all", 0, true);
  }
  function adminModerationTab(panel, d) {
    panel.innerHTML=adminModeration(d);
    for (const button of panel.querySelectorAll('[data-moderation-view]')) button.addEventListener('click',()=>{
      S.moderationView=button.dataset.moderationView;
      adminModerationTab(panel,d);
      panel.querySelector(`[data-moderation-view="${S.moderationView}"]`)?.focus();
    });
  }
  function aiTaskLabel(kind) {
    return {answer:'Respuesta del asistente',multimedia:'Materiales de un video',video_metadata:'Información de un video',microevents:'Propuestas de encuentros',social:'Sugerencias de redes sociales',form_interests:'Intereses de formularios',interest_import:'Intereses de formularios',resource_notifications:'Avisos de nuevos recursos',recommendations:'Recomendaciones',discovery:'Recomendaciones'}[kind] || 'Tarea de ASCLA';
  }
  function adminJobsTab(panel, d) {
    const rows=d.jobs.map(j=>`<tr><td><strong>${E(T(aiTaskLabel(j.kind)))}</strong><br><small>#${Number(j.id)}</small></td><td>${status(j.status)}</td><td>${E(j.error || date(j.created_at))}</td><td>${btn('Ver','job-detail','data-id="'+Number(j.id)+'"','small')}${j.status==='error'?btn('Reintentar','retry-job','data-id="'+Number(j.id)+'"','small'):''}</td></tr>`).join('');
    panel.innerHTML=`<div class="admin-section-heading"><div><h2>${E(T('Tareas de IA'))}</h2><p>${E(T('Consulta lo que ASCLA está preparando. Si una tarea falla, puedes abrirla y volver a intentarlo.'))}</p></div></div><div class="alert">${E(T('El contenido creado con ayuda de IA debe revisarse antes de publicarlo.'))}</div><div class="admin-actions">${link('centro-conocimiento','Ver recursos','primary')}${S.boot.moderator?btn('Probar sugerencias de redes sociales','social-job'):''}${(S.boot.admin||S.boot.executive)?btn('Conectar YouTube','google-connect','data-service="youtube"'):''}${btn('Actualizar estados','admin-refresh')}</div><div class="card table-wrap"><table class="data-table"><thead><tr><th>${E(T('Tarea'))}</th><th>${E(T('Estado'))}</th><th>${E(T('Detalle'))}</th><th>${E(T('Acción'))}</th></tr></thead><tbody>${rows || '<tr><td colspan="4">'+E(T('Todavía no hay tareas de IA.'))+'</td></tr>'}</tbody></table></div>`;
  }
  async function adminMicroeventsTab(panel) {
    panel.innerHTML=`<div class="admin-section-heading"><div><span class="eyebrow">ENCUENTROS ENTRE ASOCIADOS</span><h2>Círculos de conversación</h2><p>Grupos con intereses comunes y cupos configurables, con una agenda para conversar.</p></div></div><div class="card"><h3>Preparar los próximos encuentros</h3><p class="detail-body">Se consideran el consentimiento y el historial de grupos. Revisa las propuestas y ajusta fecha y agenda antes de publicar.</p><div class="admin-actions">${(S.boot.admin||S.boot.executive)?btn(I('spark')+' Preparar propuestas del período','micro-job','','primary'):''}${link('eventos','Ver encuentros','small')}</div><div id="micro-job-result"></div></div>`;
    const [proposals,diagnostics]=await Promise.all([allContent("event",{micro:1,...(S.boot.admin?{}:{mine:1})}),api("microevents/diagnostics").catch(error=>({error:error.message}))]);
    panel.querySelector('#micro-job-result').innerHTML=microDiagnosticsHTML(diagnostics);
    panel.insertAdjacentHTML("beforeend",microHistoryMarkup(proposals));
  }
  function microDiagnosticsHTML(d) {
    if (!d || d.error) return '<div class="error">'+E(T('No se pudieron comprobar las condiciones para generar microeventos.'))+'</div>';
    const messages={ready:'Hay candidatos para preparar propuestas.',insufficient_candidates:'No hay suficientes asociados elegibles para alcanzar el mínimo.',insufficient_shared_interest:'Ningún interés visible reúne el mínimo de asociados requerido.',incompatible_group:'No se encontró un grupo compatible con los bloqueos actuales.',no_group:'No se encontró un grupo que cumpla las condiciones actuales.'};
    return '<div class="alert micro-diagnostics" role="status"><strong>'+E(T(messages[d.reason] || messages.no_group))+'</strong><p>'+E(T('Asociados elegibles'))+': '+Number(d.eligible)+' · '+E(T('Mínimo por grupo'))+': '+Number(d.minimum)+' · '+E(T('Mayor grupo con un interés común'))+': '+Number(d.max_shared_interest)+'</p><details><summary>'+E(T('Revisar condiciones'))+'</summary><p>'+E(T('Se respetan la membresía activa, el consentimiento para microeventos, el perfil completo, la privacidad de intereses y los bloqueos.'))+'</p><p>'+E(T('Excluidos por membresía'))+': '+Number(d.excluded?.inactive || 0)+' · '+E(T('Sin consentimiento'))+': '+Number(d.excluded?.no_consent || 0)+' · '+E(T('Perfil incompleto'))+': '+Number(d.excluded?.incomplete || 0)+'</p><p>'+E(T('Revisa los mínimos en Configuración → Participación y revisión y los intereses autorizados de los perfiles.'))+'</p></details></div>';
  }
  async function handleMicroArchive(a,b,id) {
    if (a === 'micro-archive-tab') { S.microShowArchived=b.dataset.archived==='true';await admin();return; }
    const archived=b.dataset.archived==='true';
    await api(`events/${id}/archive`,{archived});closeModal();
    toast(T(archived?'Microevento archivado. Su historial se conserva.':'Microevento restaurado en el panel.'));await admin();
  }
  function adminAuditActionName(action) {
    const labels={editorial_state_changed:'Estado de galería o recurso actualizado',board_role_authorization:'Autorización de roles de la Junta',member_roles_updated:'Roles de cuenta actualizados',account_permissions_changed:'Permisos de cuenta actualizados',technical_error:'Error técnico',settings_updated:'Configuración actualizada',content_saved:'Contenido guardado',content_trashed:'Contenido eliminado',comment_trashed:'Comentario eliminado',content_reported:'Contenido reportado',comment_reported:'Comentario reportado',report_reviewed:'Reporte revisado',moderation:'Decisión de moderación',comment_moderation:'Comentario moderado',member_created:'Usuario creado',member_updated:'Usuario actualizado',professional_profile_updated:'Datos profesionales actualizados',member_deleted:'Usuario eliminado',member_suspended:'Acceso suspendido',member_reactivated:'Acceso reactivado',contact_status:'Solicitud actualizada',event_registration:'Inscripción a evento',event_invited:'Invitaciones a evento',event_cancelled:'Evento cancelado',connection_requested:'Solicitud de conexión',connection_accepted:'Conexión aceptada',connection_rejected:'Conexión rechazada',connection_cancelled:'Solicitud de conexión cancelada',connection_removed:'Conexión eliminada',conversation_requested:'Solicitud de conversación',conversation_request_accepted:'Conversación autorizada',conversation_request_rejected:'Solicitud de conversación rechazada',conversation_request_cancelled:'Solicitud de conversación cancelada',conversation_started:'Conversación iniciada',group_conversation_created:'Grupo de mensajería creado',group_conversation_updated:'Grupo de mensajería actualizado',group_conversation_deleted:'Grupo de mensajería eliminado',message_deleted:'Mensaje eliminado',password_changed:'Contraseña cambiada',password_reset_requested:'Recuperación solicitada',media_deleted:'Archivo eliminado',oauth_connected:'Servicio Google conectado',oauth_disconnected:'Servicio Google desconectado',calendar_create:'Evento añadido a Google Calendar',calendar_delete:'Evento retirado de Google Calendar',calendar_save:'Evento guardado en Google Calendar',calendar_cancel:'Evento retirado o cancelado en Google Calendar',calendar_organize:'Evento organizador creado en Google Calendar',calendar_update:'Evento organizador actualizado en Google Calendar',turnstile_passed:'Verificación de seguridad superada',turnstile_failed:'Verificación de seguridad fallida',turnstile_error:'Error de verificación de seguridad',request_failed:'Solicitud con error',job_completed:'Trabajo en segundo plano completado',job_failed:'Trabajo en segundo plano con error',ai_generated:'Contenido asistido por IA creado'};
    return labels[action]||String(action||'Actividad del sistema').replaceAll('_',' ');
  }
  function adminLogsTab(panel, d) {
    const auditRows=d.audit||[];
    const auditActors=new Set(auditRows.map(a=>Number(a.actor_id)).filter(Boolean)).size;
    const latest=auditRows[0]?.created_at||'';
    panel.innerHTML=`<div class="admin-section-heading"><div><span class="eyebrow">${E(T("ACTIVIDAD REGISTRADA"))}</span><h2>${E(T("Historial de cambios"))}</h2><p>Este registro ayuda a entender qué cambios importantes ocurrieron en ASCLA, quién los realizó y sobre qué elemento actuaron.</p></div><span class="admin-total">${auditRows.length} registros</span></div><div class="audit-guide"><article>${I('clock')}<div><strong>¿Qué registra?</strong><p>Acciones relevantes como cambios de configuración, moderación, accesos, reportes, archivos e integraciones.</p></div></article><article>${I('users')}<div><strong>¿Para qué sirve?</strong><p>Permite revisar el historial cuando necesitas saber quién hizo un cambio o investigar un problema.</p></div></article><article>${I('shield')}<div><strong>¿Qué no guarda?</strong><p>No almacena contraseñas ni el contenido de mensajes privados. Solo registra metadatos de la acción.</p></div></article></div><div class="audit-summary"><article class="audit-summary-card"><span class="audit-summary-icon">${I('clock')}</span><div><small>Acciones registradas</small><strong>${auditRows.length}</strong></div></article><article class="audit-summary-card"><span class="audit-summary-icon">${I('users')}</span><div><small>Personas identificadas</small><strong>${auditActors}</strong></div></article><article class="audit-summary-card wide"><span class="audit-summary-icon">${I('shield')}</span><div><small>Última actividad</small><strong>${latest?E(date(latest,{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'})):'Sin actividad'}</strong></div></article></div><div class="card audit-card"><div class="audit-card-head"><div><h3>Historial de actividad</h3><p class="private-note">Los eventos más recientes aparecen primero. “Elemento” identifica el registro afectado cuando corresponde.</p></div><span class="audit-privacy">${I('shield')} Registro protegido</span></div><div class="table-wrap"><table class="data-table audit-table"><thead><tr><th>Cuándo</th><th>Qué ocurrió</th><th>Quién</th><th>Elemento</th><th>Detalle técnico</th></tr></thead><tbody>${auditRows.map(a=>("<tr><td><time class=\"audit-time\" datetime=\"" + (E(a.created_at)) + "\" title=\"" + (E(a.created_at)) + "\">" + (E(date(a.created_at,{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'}))) + "</time></td><td><span class=\"audit-action\">" + (E(adminAuditActionName(a.action))) + "</span></td><td><span class=\"audit-actor\">" + (E(a.actor_name||'Sistema')) + "</span></td><td><span class=\"audit-object\">" + (a.object_id?'#'+Number(a.object_id):'—') + "</span></td><td class=\"audit-detail\">" + (E(a.detail||'—')) + "</td></tr>")).join('')||'<tr><td colspan="5"><div class="audit-empty">No hay acciones registradas todavía.</div></td></tr>'}</tbody></table></div></div>`;
  }
  async function adminSettingsTab(panel) { if (S.boot.configure) await settings(panel); }
  const ADMIN_TAB_HANDLERS = {
    contenidos: panel=>adminEditorialRegistry(panel),
    aliados: panel=>alliesUI().registry(panel),
    resumen: (panel,d) => adminOverview(panel,d),
    estadisticas: (panel) => adminStatisticsTab(panel),
    importar: (panel) => adminImportTab(panel),
    solicitudes: (panel) => adminContacts(panel),
    usuarios: (panel) => adminUsersTab(panel),
    archivos: (panel) => adminFilesTab(panel),
    moderacion: (panel, d) => adminModerationTab(panel, d),
    trabajos: (panel, d) => adminJobsTab(panel, d),
    microeventos: (panel) => adminMicroeventsTab(panel),
    logs: (panel, d) => adminLogsTab(panel, d),
    configuracion: (panel) => adminSettingsTab(panel),
  };
  async function admin() {
    const tabs=adminTabs();
    const defaultTab=S.boot.admin?'resumen':'moderacion';
    const tab=tabs.some(([key])=>key===S.adminTab)?S.adminTab:defaultTab;
    S.adminTab=tab;
    content().innerHTML=adminShell(tabs, tab);
    const panel=document.getElementById('admin-panel');
    const handler=ADMIN_TAB_HANDLERS[tab];
    if (handler) {
      panel.innerHTML='<div class="view-loading" role="status"><span class="loading-dot"></span>'+E(T('Cargando sección…'))+'</div>';
      if (handler.length > 1) {
        const d = await api('admin?section='+encodeURIComponent(tab));
        if (!panel.isConnected) return;
        S.admin=d;
        await handler(panel, d);
      } else await handler(panel);
    }
  }
  function settingsSection(icon, title, description, body, wide = false) {
    return `<section class="settings-group${wide?' wide':''}"><header class="settings-group-head"><span class="settings-group-icon">${I(icon)}</span><div><h3>${E(title)}</h3><p>${E(description)}</p></div></header><div class="settings-group-body">${body}</div></section>`;
  }
  function syncAIProvider(form) {
    if (!form) return;
    const provider=form.elements.ai_provider?.value||'mock';
    form.querySelectorAll('[data-ai-provider-panel]').forEach(panel=>{
      const active=panel.dataset.aiProviderPanel===provider;
      panel.hidden=!active;
      panel.querySelectorAll('input,select,textarea').forEach(control=>{ control.disabled=!active || control.dataset.serverSecret === '1'; });
    });
    const test=form.querySelector('[data-ai-test-actions]');
    if (test) test.hidden=provider==='mock';
  }
  function credentialSettings(s) {
    const fields=[["password_min_length","Longitud mínima de contraseña",12,128],["password_reset_minutes","Vigencia del enlace de recuperación (minutos)",5,1440],["remember_days","Duración de Recordarme (días)",1,90],["login_challenge_after","Fallos antes de captcha",1,10],["login_lock_after","Fallos antes de bloquear la cuenta",2,20],["login_ip_lock_after","Fallos antes de bloquear la IP",2,100],["login_lock_minutes","Duración del bloqueo (minutos)",15,60]];
    return '<div class="form-grid">'+fields.map(([key,label,min,max])=>field(key,label,s[key],"number",'required min="'+min+'" max="'+max+'"')).join("")+'</div><p class="private-note">'+E(T("Recordarme no evita el cierre por 30 minutos de inactividad."))+'</p>';
  }
  function mailSettings(s) {
    const local = s.mail_local ? '<div class="alert">Buzón local activo: los correos se consultan en <a href="http://localhost:8025/" target="_blank" rel="noopener">Abrir buzón de pruebas</a>. No llegan a una bandeja externa.</div>' : '';
    const last = s.mail_last_result;
    let lastAttempt = 'Aún no hay intentos registrados.';
    if (last) {
      const deliveryStatus = last.status === 'accepted' ? 'aceptado por el transporte' : 'falló el envío';
      lastAttempt = 'Último intento: ' + E(deliveryStatus) + ' · ' + E(date(last.at, {day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'}));
    }
    const body=`${local}<p class="private-note">Usa el servicio de correo de tu hosting o un proveedor SMTP. Si otro plugin ya gestiona los envíos, conserva “Transporte de WordPress”. Guarda los cambios antes de enviar una prueba a tu correo de administrador.</p><div class="form-grid">${select('mail_mode', 'Envío de correos', [['wordpress', 'Transporte de WordPress / otro plugin'], ['smtp', 'Servidor SMTP']], s.mail_mode)}${field('smtp_host', 'Servidor SMTP', s.smtp_host, 'text', 'placeholder="smtp.tuproveedor.com" autocomplete="off"')}${select('smtp_port', 'Puerto', [[587,'587'],[465,'465'],[2525,'2525']], s.smtp_port)}${select('smtp_security', 'Cifrado', [['tls', 'STARTTLS (587 / 2525)'],['ssl', 'SSL/TLS (465)']], s.smtp_security)}${field('smtp_user','Usuario SMTP',s.smtp_user,'text','autocomplete="off"')}${field('smtp_password', s.has_smtp_password ? 'Contraseña SMTP (guardada; vacío para conservar)' : 'Contraseña SMTP', '', 'password', 'autocomplete="new-password"')}${field('smtp_from','Correo remitente autorizado',s.smtp_from,'email')}${field('smtp_name','Nombre del remitente',s.smtp_name)}</div>${check('clear_smtp_password','Eliminar contraseña SMTP guardada',false)}<p class="private-note">La contraseña se guarda cifrada. Para eliminarla, cambia primero al transporte de WordPress. ${lastAttempt}</p>${btn('Enviar correo de prueba a mi cuenta','mail-test','','small')}`;
    const counts=s.deliveries||{};
    const deliveries=`<p>${E(T('Entregas pendientes'))}: ${Number(counts.pending||0)} · ${E(T('Entregas que requieren revisión'))}: ${Number(counts.failed||0)}</p>${btn('Revisar entregas','delivery-list','','small')}`;
    return settingsSection('contact','Correo y recuperación','Configura la salida de correos, recuperación de contraseña y pruebas de entrega.',body+deliveries,true);
  }
  async function deliveryList() {
    const data=await api('admin/deliveries');
    const kinds={mail:'Correo',notification:'Notificación por correo',calendar:'Google Calendar',cache:'Actualización interna'};
    const states={pending:'Pendiente',processing:'Procesando',failed:'Requiere revisión'};
    const rows=data.items.map(row=>`<div class="admin-job"><strong>#${Number(row.id)} · ${E(T(kinds[row.kind]||'Entrega'))}</strong><p>${E(T(states[row.status]||'Pendiente'))} · ${E(T('Intentos'))}: ${Number(row.attempts)}</p>${row.status==='failed'?btn('Reintentar','delivery-retry',("data-id=\"" + (Number(row.id)) + "\""),'small'):''}</div>`).join('');
    modal('Entregas y actualizaciones',`<p>${E(T('Las entregas se reintentan automáticamente. Si requieren revisión, verifica primero la conexión del servicio.'))}</p>${rows||'<p>'+E(T('No hay entregas pendientes.'))+'</p>'}`);
  }
  function bindServerSecrets(form, settings) {
    for (const [name, source] of Object.entries(settings.secret_sources || {})) {
      if (!source.managed_externally) continue;
      const input = form.elements[name];
      if (input) {
        input.disabled = true;
        input.dataset.serverSecret = '1';
        input.value = '';
        const note = document.createElement('p');
        note.className = 'private-note';
        note.textContent = T('Esta credencial se administra en el servidor.') + ' ' + source.variable;
        input.parentElement.append(note);
      }
      const clear = form.elements['clear_' + name];
      if (clear) { clear.checked = false; clear.disabled = true; clear.dataset.serverSecret = '1'; }
    }
  }
  function serverSecretsStatus(settings) {
    const descriptions = {constant: 'Constante del servidor', environment: 'Variable de entorno', external_required: 'Pendiente de configurar en el servidor', encrypted_option: 'Almacenamiento cifrado anterior', absent: 'Sin configurar'};
    const rows = Object.values(settings.secret_sources || {}).map(source => `<tr><td><code>${E(source.variable)}</code></td><td>${E(T(descriptions[source.source] || 'Sin configurar'))}</td></tr>`).join('');
    return settingsSection('shield', 'Custodia de credenciales', 'Origen de las credenciales de servicio, sin mostrar sus valores.', `<p>${E(T(settings.external_secrets_only ? 'Modo externo: las claves de servicio se administran en el servidor.' : 'Compatibilidad activa con claves cifradas anteriores. Configura las variables en el servidor antes de activar el modo externo.'))}</p><div class="table-wrap"><table class="data-table"><thead><tr><th>${E(T('Variable'))}</th><th>${E(T('Origen'))}</th></tr></thead><tbody>${rows}</tbody></table></div>`);
  }
  async function settings(panel) {
    const s = await api("settings");
    const participation=settingsSection('users','Participación y revisión','Define cómo se publica, modera y organiza la participación dentro de la comunidad.',`${check("demo", "Modo demo (datos e integraciones identificados)", s.demo)}${check("moderation_required", "Revisar publicaciones del Hub antes de publicarlas", s.moderation_required)}${check("moderate_comments", "Revisar comentarios del Hub y otras secciones (excepto Foros)", s.moderate_comments)}${check("chatham_default", "Aplicar Chatham House por defecto", s.chatham_default)}${check("micro_enabled", "Preparar microeventos automáticamente", s.micro_enabled)}<p>${E(T("Todas las propuestas requieren aprobación administrativa."))}</p><div class="form-grid">${field("micro_interval_days","Generar propuestas cada (días)",s.micro_interval_days || 7,"number",'min="7" max="90" required')}${field("micro_min","Mínimo de participantes",s.micro_min,"number",'min="2" max="50" required')}${field("micro_capacity","Cupos por microevento",s.micro_capacity,"number",'min="2" max="100" required')}${field("micro_limit","Máximo de propuestas por período",s.micro_limit,"number",'min="1" max="4" required')}${field("micro_priority_hours","Horas de inscripción prioritaria",s.micro_priority_hours,"number",'min="0" max="168" required')}</div>`,true);
    const security=settingsSection('shield','Seguridad','Protección adaptativa del acceso con Cloudflare y validación real en el servidor.',`${credentialSettings(s)}${check("turnstile_enabled", "Activar Cloudflare Turnstile", s.turnstile_enabled)}<p class="private-note">El widget debe crearse en Cloudflare con modo <strong>Managed</strong>. En el login normal no aparece ningún desafío al inicio. Al alcanzar el umbral configurado, ASCLA muestra el widget oficial de Cloudflare y valida el token nuevamente en el servidor.</p><div class="form-grid">${field("turnstile_site_key", "Turnstile Site Key", s.turnstile_site_key || "", "text", 'autocomplete="off" placeholder="0x4AAAA..."')}${field("turnstile_secret", s.has_turnstile_secret ? "Turnstile Secret Key (guardada; vacío para conservar)" : "Turnstile Secret Key", "", "password", 'autocomplete="new-password"')}</div>${check("clear_turnstile_secret", "Eliminar Turnstile Secret Key guardada", false)}<div class="turnstile-protection-options"><strong>Formularios protegidos</strong>${check("turnstile_login", "Inicio de sesión · desafío adaptativo según el umbral configurado", s.turnstile_login)}${check("turnstile_recovery", "Recuperación de contraseña · exigir después de 2 solicitudes seguidas", s.turnstile_recovery)}${check("turnstile_public", "Otros formularios públicos ASCLA · activar solo ante señales sospechosas o demasiados envíos", s.turnstile_public)}</div><p class="private-note">Al alcanzar el máximo de fallos del mismo usuario/correo se aplica la espera configurada. Una ráfaga mayor de intentos desde la misma IP también puede bloquear el acceso temporalmente. Cuando Turnstile aparece, la marca “Success” confirma solo la comprobación anti-bot; ASCLA todavía valida usuario y contraseña.</p>`,true);
    const matching=settingsSection('spark','Motor de afinidad','Define el umbral y la cantidad máxima del lote semanal de recomendaciones.',`<div class="form-grid matching-threshold-setting">${field("matching_min_affinity", "Afinidad mínima para recomendar (%)", s.matching_min_affinity ?? 30, "number", 'min="0" max="100" step="1"')}${field("matching_max_suggestions", "Perfiles por lote semanal", s.matching_max_suggestions ?? 5, "number", 'min="1" max="5" step="1"')}</div><p class="private-note">${E(T("Solo se muestran perfiles elegibles que alcancen el umbral. El lote se recalcula cada siete días y nunca supera cinco personas."))}</p>`);
    const ai=settingsSection('spark','Inteligencia artificial','Elige un único proveedor activo. ASCLA utilizará solo ese proveedor para redactar respuestas y sugerencias.',`<div class="ai-provider-config">${select("ai_provider","Proveedor activo",[["mock","DEMO MODE · sin API"],["gemini","Google Gemini · API real"],["openai","OpenAI / ChatGPT · API real"],["deepseek","DeepSeek · API real"]],s.ai_provider)}<div class="ai-provider-panel" data-ai-provider-panel="mock"><div class="alert">Modo de demostración: ASCLA usa respuestas simuladas y no envía información a un proveedor externo.</div></div><div class="ai-provider-panel" data-ai-provider-panel="gemini"><div class="form-grid">${field("ai_model", "ID del modelo Gemini", s.ai_model, "text", 'placeholder="gemini-2.5-flash" autocomplete="off"')}${field("ai_key", s.has_ai_key ? "Gemini API Key (guardada; vacío para conservar)" : "Gemini API Key", "", "password", 'autocomplete="new-password"')}</div>${check("clear_ai_key", "Eliminar Gemini API Key guardada", false)}</div><div class="ai-provider-panel" data-ai-provider-panel="openai"><div class="form-grid">${field("openai_model", "ID del modelo OpenAI", s.openai_model || "gpt-5.6-luna", "text", 'placeholder="gpt-5.6-luna" autocomplete="off"')}${field("openai_key", s.has_openai_key ? "OpenAI API Key (guardada; vacío para conservar)" : "OpenAI API Key", "", "password", 'autocomplete="new-password"')}</div>${check("clear_openai_key", "Eliminar OpenAI API Key guardada", false)}</div><div class="ai-provider-panel" data-ai-provider-panel="deepseek"><div class="form-grid">${field("deepseek_model", "ID del modelo DeepSeek", s.deepseek_model || "deepseek-flash", "text", 'placeholder="deepseek-flash" autocomplete="off"')}${field("deepseek_key", s.has_deepseek_key ? "DeepSeek API Key (guardada; vacío para conservar)" : "DeepSeek API Key", "", "password", 'autocomplete="new-password"')}</div>${check("clear_deepseek_key", "Eliminar DeepSeek API Key guardada", false)}</div><div class="ai-secondary-setting">${select("youtube_mode","Transcripciones YouTube",[["mock","Transcripción manual"],["real","YouTube OAuth real · subtítulos autorizados"]],s.youtube_mode)}<p class="private-note">YouTube permite descargar subtítulos por API solo cuando la cuenta conectada tiene permisos suficientes sobre el video. Para otros videos, pega una transcripción autorizada manualmente.</p></div><p class="private-note">Las claves permanecen protegidas en el servidor. Guarda la configuración antes de probar la conexión. Los datos internos autorizados de ASCLA se preparan antes de consultar al proveedor activo.</p><div class="settings-actions-row" data-ai-test-actions>${btn("Probar conexión", "ai-test", "", "small")}<p id="ai-test-result" class="private-note" role="status" aria-live="polite"></p></div></div>`,true);
    const google=settingsSection('calendar','Google OAuth','Credenciales para que cada asociado conecte servicios autorizados de Google desde su cuenta.',`<div class="form-grid">${field("google_client_id", "Client ID", s.google_client_id)}${field("google_client_secret", s.has_google_secret ? "Client Secret (configurado)" : "Client Secret", "", "password", 'autocomplete="new-password"')}</div><div class="alert settings-code-alert"><span>URI de redirección</span><code>${E(s.google_redirect)}</code></div><p class="private-note">Cada asociado conecta su calendario desde Perfil. YouTube se conecta desde IA y trabajos.</p>`);
    const social=settingsSection('contact','Social Listening','Estado de conectores sociales y restricciones de integración externa.',`<div class="alert">LinkedIn y X permanecen en DEMO MODE. Los adaptadores requieren aprobación, permisos y planes oficiales; ASCLA no realiza scraping ni envía respuestas externas.</div>`);
    const legal=settingsSection('book','Identidad y propiedad intelectual','Texto institucional mostrado en las áreas correspondientes de la intranet.',`${field("copyright", "Propiedad intelectual", s.copyright)}`);
    panel.innerHTML=`<div class="admin-section-heading"><div><span class="eyebrow">PREFERENCIAS Y SERVICIOS</span><h2>Configuración</h2><p>Gestiona las reglas de comunidad, recomendaciones, seguridad e integraciones. Cada bloque agrupa opciones relacionadas para evitar configuraciones ambiguas.</p></div><span class="admin-config-status">${I('shield')} Cambios protegidos</span></div><form class="admin-settings" data-form="settings" data-guard-unsaved><div class="settings-grid">${participation}${settingsSection("mail","Categorías de Contacto","Opciones vigentes del formulario de solicitudes.",field("support_categories","Una categoría por línea",(s.support_categories || []).join("\n"),"textarea",'required rows="6"'))}${settingsSection("users","Perfiles y directorio","Frecuencia de revisión y tamaño de los resultados.", '<div class="form-grid">'+field("profile_review_days","Revisar el perfil cada (días)",s.profile_review_days,"number",'min="1" max="730" required')+field("profile_snooze_days","Posponer avisos durante (días)",s.profile_snooze_days,"number",'min="1" max="30" required')+field("directory_page_size","Perfiles por página",s.directory_page_size,"number",'min="12" max="12" readonly')+"</div>"+profileCompletionSettings(s))}${matching}${social}${serverSecretsStatus(s)}${security}${ai}${google}${legal}${mailSettings(s)}</div><div class="settings-savebar"><div><strong>Configuración de ASCLA</strong><span>Los cambios se aplican al guardar.</span></div><button class="btn primary">Guardar configuración</button></div></form><form class="card section-gap demo-settings-card" data-form="demo"><div class="admin-feature-icon">${I('spark')}</div><div><h2>Preparar datos de demostración</h2><p class="private-note">Crea 18 perfiles y 9 empresas ficticias. Las siguientes ejecuciones conservan datos y contraseñas existentes.</p>${field("password", "Contraseña para nuevas cuentas demo", "", "password", 'required minlength="12" autocomplete="new-password"')}</div><button class="btn">Crear / completar demo</button></form>`;
    const settingsForm=panel.querySelector('[data-form="settings"]');
    syncAIProvider(settingsForm);
    bindServerSecrets(settingsForm,s);
    registerUnsavedForm(settingsForm);
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
            ("<h3 class=\"section-heading-spaced\">" + (E(t)) + "</h3><p class=\"private-note\">" + (E(b)) + "</p>"),
        )
        .join("")}`,
    );
  }
  async function render() {
    if (S.boot?.admin && S.page==='intranet') { location.replace(C.adminUrl); return; }
    const version = S.viewVersion;
    stopChat();
    content().innerHTML = '<div class="view-loading" role="status"><span class="loading-dot"></span> Cargando sección…</div>';
    content().setAttribute('aria-busy', 'true');
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
      if (e.name === "AbortError" || version !== S.viewVersion) return;
      if (e.status === 401 || e.code === "rest_cookie_invalid_nonce") { location.assign(location.href); return; }
      content().innerHTML = `<div class="error">${E(e.message)} ${btn("Reintentar", "refresh", "", "small")} <a data-native href="${E(location.href)}">Recargar esta página</a></div>`;
    } finally {
      if (version === S.viewVersion) content().removeAttribute('aria-busy');
    }
  }
  async function navigateTo(url) {
    if (navigation?.matches(url)) await navigation.navigate(url);
    else location.assign(safeURL(url));
  }
  function prepareView(page) {
    discardPendingWithin(content());
    S.controller.abort(); pendingReads.clear(); S.controller = new AbortController(); S.viewVersion++;
    stopChat(); closeModal(); document.querySelector('.feedback-toast')?.remove();
    S.page = page; S.filter = {}; S.item = null; S.conversation = 0; S.messagesLoaded = false;
    const q = new URLSearchParams(location.search); if (q.has('q')) S.filter.q = q.get('q');
    accessibility?.setMenu(false);
  }
  async function routeDetails() {
    const q = new URLSearchParams(location.search);
    if (q.get("notification")) { await api("notifications/" + Number(q.get("notification")) + "/read", {}); await refreshNotifications(); }
    if (S.page === "asistente" && q.get("job")) await restoreAnswer(Number(q.get("job")));
    if (S.page === "asistente" && q.get("history")) await answerHistory();
    if (q.get("notice") === "unavailable") toast(T("El contenido de este aviso ya no está disponible. Puedes continuar en esta sección."));
    if (q.get("item")) await item(Number(q.get("item")));
    if (q.get("member")) await member(Number(q.get("member")));
  }
  function enableNavigation() {
    if (C.page === 'admin') return;
    navigation = globalThis.ASCLANavigation({root, pages: C.pages, prepare: prepareView,
      beforeNavigate: () => confirmUnsavedExit(),
      load: async () => { const version = S.viewVersion; await render(); if (version === S.viewVersion) await routeDetails(); },
      error: error => { if (error.name !== 'AbortError') toast(error.message); }
    });
  }
  // Guard every same-tab link while Perfil or Configuración contains unsaved changes.
  // Internal SPA links, wp-admin links, logout and other page changes all use the same ASCLA dialog.
  document.addEventListener("click", async (event) => {
    if (!hasUnsavedChanges()) return;
    const anchor = event.target.closest?.("a[href]");
    if (!anchor || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (anchor.target === "_blank" || anchor.hasAttribute("download")) return;
    let destination;
    try { destination = new URL(anchor.href, location.href); } catch { return; }
    if (!["http:", "https:"].includes(destination.protocol) || destination.href === location.href) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (!(await confirmUnsavedExit())) return;
    if (navigation?.matches(destination.href)) await navigateTo(destination.href);
    else location.assign(destination.href);
  }, true);
  // Keep contextual three-dot menus exclusive: opening one closes any other.
  root.addEventListener("click", (event) => {
    const summary = event.target.closest(".overflow-menu > summary");
    if (summary) {
      const current = summary.closest(".overflow-menu");
      root.querySelectorAll(".overflow-menu[open]").forEach(menu => { if (menu !== current) menu.removeAttribute("open"); });
      return;
    }
    if (!event.target.closest(".overflow-menu")) root.querySelectorAll(".overflow-menu[open]").forEach(menu => menu.removeAttribute("open"));
  });

  root.addEventListener("keydown", event => {
    const connectionTab = event.target.closest?.('[data-action="connection-tab"]');
    if (connectionTab && ['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) {
      event.preventDefault();
      const index = CONNECTION_TABS.findIndex(([key])=>key===connectionTab.dataset.tab);
      const direction=event.key==='ArrowRight'?1:-1;
      let next=(index+direction+CONNECTION_TABS.length)%CONNECTION_TABS.length;
      if(event.key==='Home')next=0;
      if(event.key==='End')next=CONNECTION_TABS.length-1;
      selectConnectionTab(CONNECTION_TABS[next][0],true);return;
    }
    const input = event.target.closest?.('.assistant-compose textarea[name="question"]');
    if (!input || event.key !== "Enter" || event.shiftKey || event.isComposing) return;
    event.preventDefault();
    input.form?.requestSubmit();
  });

    async function handleClickActions01(a, _b, _id, _event) {
    if (a === "close") {
      requestModalClose();
    }
    else if (a === "modal-unsaved-stay") {
      closeModalDecision();
    }
  }
  async function handleClickActions02(a, b, _id, _event) {
    if (a === "modal-unsaved-discard") {
      closeModalDecision(); S.modalUnsavedForm = null; closeModal();
    }
    else if (a === "modal-unsaved-save") {
      const guarded = S.modalUnsavedForm;
      closeModalDecision();
      if (guarded?.isConnected) {
        if (canSaveContentAsDraft(guarded)) prepareEditorDraft(guarded);
        guarded.requestSubmit();
      }
    }
    else if (a === "save-event-draft" || a === "save-content-draft") {
      const form = b.closest('form[data-form="editor"]');
      if (!form) return;
      prepareEditorDraft(form);
      form.requestSubmit();
    }
  }
  async function handleClickActions03(a, _b, _id, _event) {
    if (a === "profile-review-confirm" || a === "profile-nudge-later") {
      S.boot.profile_review = await api("profiles/review", {decision: a === "profile-review-confirm" ? "confirm" : "snooze"});
      closeModal();
    }
    else if (a === "profile-nudge-go") {
      closeModal(); await navigateTo(C.pages.perfil.url);
    }
  }
  async function handleClickActions04(a, b, _id, _event) {
    if (a === "unsaved-stay") {
      resolveUnsavedExit(false);
    }
    else if (a === "unsaved-discard") {
      resolveUnsavedExit(true);
    }
    else if (a === "unsaved-save") {
      if (S.pendingUnsavedSaveForm) return;
      const form = unsavedGuard.form;
      if (!form?.isConnected) { resolveUnsavedExit(true); return; }
      if (!form.checkValidity()) {
        resolveUnsavedExit(false);
        form.reportValidity();
        return;
      }
      S.pendingUnsavedSaveForm = form;
      b.setAttribute("aria-busy", "true");
      form.requestSubmit();
    }
  }
  async function handleClickActions05(a, b, _id, _event) {
    if (a === "theme-menu") {
      themeDialog();
    }
    else if (a === "theme-set") {
      applyTheme(b.dataset.theme || "system", true); closeModal(); toast(T("Apariencia actualizada."));
    }
  }
  async function handleClickActions06(a, _b, _id, _event) {
    if (a === "menu") {
      accessibility?.setMenu(!root.querySelector(".ascla-sidebar.open"), true);
    }
    else if (a === "refresh") {
      if (await confirmUnsavedExit()) await render();
    }
  }
  async function handleClickActions07(a, _b, id, _event) {
    if (a === "rules") {
      rules();
    }
    else if (a === "member") {
      await member(id);
    }
    else if (a === "recommendation-open") {
      await api(`recommendations/${id}/view`, {});
      await member(id);
    }
  }
  async function handleClickActions08(a, b, id, _event) {
    if (a === "item") {
      await item(id);
    }
    else if (a === "files") {
      await files(Number(b.dataset.page) || 1, S.files?.q || "", b.dataset.scope || S.files?.scope || "mine", S.files?.owner || 0);
    }
  }
  async function handleClickActions09(a, b, _id, _event) {
    if (a === "detach-media") {
      const row=b.closest('[data-media]'); if (row) { discardPendingWithin(row); row.remove(); }
    }
    else if (a === "event-cover-clear") {
      const preview = document.getElementById('event-cover-preview');
      if (preview) { discardPendingWithin(preview); preview.innerHTML = `<span class="event-cover-placeholder">${I('gallery')}<small>${E(T('Aún no has seleccionado una portada.'))}</small></span>`; }
    }
  }
  async function handleClickActions10(a, b, id, _event) {
    if (a === "delete-content") {
      const p = await api('items/' + id); confirmDeletion('content',id,{title:p.title,forum:p.type==='forum'});
    }
    else if (a === "delete-comment") {
      confirmDeletion('comment',id,{post:Number(b.dataset.post)||0});
    }
  }
  async function handleClickActions11(a, b, id, _event) {
    if (a === "delete-message") {
      confirmDeletion('message',id,{conversation:Number(b.dataset.conversation)||S.conversation});
    }
    else if (a === "comment-reply") {
      openCommentReply(b);
    }
  }
  async function handleClickActions12(a, b, id, _event) {
    if (a === "comment-reply-cancel") {
      b.closest('.comment-reply-form')?.remove();
    }
    else if (a === "report-comment") {
      reportDialog(id, "comment");
    }
  }
  async function handleClickActions13(a, b, id, _event) {
    if (a === "content-reaction") return changeContentReaction(b, id);
    if (a === "comment-like") {
      const r = await api(`comments/${id}/reaction`, {active:b.dataset.active === 'true'});
      b.dataset.active=String(!r.active); b.setAttribute('aria-pressed',String(!!r.active)); b.setAttribute('aria-label',T(r.active?'Quitar Me gusta':'Me gusta'));
      b.classList.toggle('active',!!r.active); const count=b.querySelector('span'); if(count) count.textContent=String(r.likes);
    }
    else if (a === "delete-media") {
      confirmDeletion('media',id,{post:Number(b.dataset.post)||0,title:b.dataset.name,library:b.dataset.library==='true'});
    }
  }
  async function cancelDeletionAction(b, id) {
    if (b.dataset.kind === 'message') closeModal();
    else if (b.dataset.library === 'true') { closeModal(); await files(S.files.page, S.files.q, S.files.scope, S.files.owner || 0); }
    else if (Number(b.dataset.post)) await item(Number(b.dataset.post));
    else if (b.dataset.kind === 'content') await item(id);
    else closeModal();
  }
  function deletionEndpoint(kind, id, conversation) {
    if (kind === 'content') return 'items/' + id;
    if (kind === 'comment') return 'comments/' + id;
    if (kind === 'message') return `conversations/${conversation}/messages/${id}`;
    return 'media/' + id;
  }
  async function refreshAfterDeletion(kind, id, post, library) {
    if (kind === 'message') {
      document.querySelector(`[data-message-id="${id}"]`)?.remove();
      S.chat?.ids?.delete(id);
      if (chatAlive(S.chat)) await syncChat();
      return;
    }
    if (library) { await files(S.files.page, S.files.q, S.files.scope, S.files.owner || 0); return; }
    if (post) { await item(post); return; }
    const url = new URL(location.href);
    url.searchParams.delete('item');
    history.replaceState(history.state, '', url);
    S.item = null;
    await render();
  }
  async function confirmDeletionAction(b, id) {
    const kind = b.dataset.kind;
    const post = Number(b.dataset.post);
    const library = b.dataset.library === 'true';
    const conversation = Number(b.dataset.conversation) || 0;
    await api(deletionEndpoint(kind, id, conversation), {}, 'DELETE');
    closeModal();
    toast(T('Eliminado correctamente.'));
    await refreshAfterDeletion(kind, id, post, library);
  }
  async function handleClickActions14(a, b, id, _event) {
    if (a === "delete-cancel") await cancelDeletionAction(b, id);
    else if (a === "delete-confirm") await confirmDeletionAction(b, id);
  }
  async function handleClickActions15(a, b, id, _event) {
    if (a === "editor") {
      await editor(b.dataset.type, id);
    }
    else if (a === "notifications") {
      S.noticePage = 1; await notifications();
    }
  }
  async function handleClickActions16(a, b, _id, _event) {
    if (a === "notification-filter") {
      S.noticeFilter = b.dataset.filter; S.noticePage = 1; await notifications();
    }
    else if (a === "notification-page") {
      S.noticePage = Number(b.dataset.page); await notifications();
    }
  }
  async function handleClickActions17(a, _b, id, _event) {
    if (a === "notifications-read-all") {
      await api("notifications/read-all", {}); await notifications();
    }
    else if (a === "notification-open") {
      const destination = await api("notifications/" + id + "/open", {}); await navigateTo(destination.url);
    }
  }
  async function handleClickActions18(a, _b, id, _event) {
    if (a === "answer-history") {
      await answerHistory();
    }
    else if (a === "report") {
      reportDialog(id, "content");
    }
  }
  async function handleClickActions19(a, b, id, _event) {
    if (a === "like") {
      await api(`items/${id}/reaction`, {
      kind: a,
      active: b.dataset.active === "true",
      });
      toast("Actualizado.");
      if (S.item?.id === id && document.querySelector(".modal"))
      await item(id);
      else await render();
    }
    else if (a === "recommendation-dismiss") {
      await api(`recommendations/${id}/dismiss`, {});
      toast(T("La recomendación se descartó de forma privada."));
      await dashboard();
    }
    else if (a === "recommendation-restore") {
      await api(`recommendations/${id}/restore`, {});
      toast(T("El perfil podrá volver a aparecer en próximas recomendaciones."));
      S.profileTab="privacy";
      await profile();
    }
    else if (a === "password-reset-email") {
      const result = await api("account/password-reset", {});
      toast(result.message);
    }
  }
  async function handleClickActions20(a, b, id, _event) {
    if (a === "connect") {
      await connectionRequestDialog(id);
    }
    else if (a === "connection-respond") {
      await api(`connections/${Number(b.dataset.request)}/respond`, {decision: b.dataset.decision});
      await refreshRelationshipState(id); await refreshConnectionsPanel(); await refreshNotifications();
      toast(b.dataset.decision === 'accept' ? 'Conexión confirmada. Ya pueden enviarse mensajes.' : 'Solicitud rechazada.');
    }
  }
  async function handleClickActions21(a, b, id, _event) {
    if (a === "conversation-request") {
      await conversationRequestDialog(id);
    }
    else if (a === "conversation-request-respond") {
      await api(`conversation-requests/${Number(b.dataset.request)}/respond`, {decision:b.dataset.decision});
      if (S.page === 'mensajeria') {
      if (b.dataset.decision === 'accept') S.messageTab = 'chats';
      if (b.dataset.decision === 'reject') {
      S.conversation = 0;
      const url=new URL(location.href); url.searchParams.delete('conversation'); history.replaceState(history.state,'',url);
      }
      await messages();
      }
      else { await refreshRelationshipState(id); await refreshConnectionsPanel(); }
      await refreshNotifications();
      toast(b.dataset.decision === 'accept' ? 'Solicitud aceptada. Ya pueden conversar.' : 'Solicitud de conversación rechazada.');
    }
  }
  async function handleClickActions22(a, b, id, _event) {
    if (a === "conversation-request-cancel") {
      await api(`conversation-requests/${id}/cancel`, {});
      if (S.page === 'mensajeria') {
      S.conversation = 0;
      const url=new URL(location.href); url.searchParams.delete('conversation'); history.replaceState(history.state,'',url);
      await messages();
      }
      else { await refreshRelationshipState(id); await refreshConnectionsPanel(); }
      await refreshNotifications();
      toast('Solicitud de conversación cancelada.');
    }
    else if (a === "connection-remove-request") {
      confirmConnectionRemoval(id, b.dataset.mode);
    }
  }
  async function handleClickActions23(a, b, id, _event) {
    if (a === "connection-tab") {
      selectConnectionTab(b.dataset.tab);
    }
    else if (a === "connection-remove-confirm") {
      const mode = b.dataset.mode;
      await api("relations", {target:id, kind:"connect", active:false});
      closeModal(); await refreshRelationshipState(id); await refreshConnectionsPanel(); await refreshNotifications();
      toast(mode === 'cancel' ? 'Solicitud de conexión cancelada.' : 'Conexión eliminada.');
    }
    else if (a === "directory-reset") {
      S.filter = {}; await render();
    }
    else if (a === "connections-refresh") {
      await refreshConnectionsPanel(); await refreshOpenConnection();
    }
  }
  async function handleClickActions24(a, _b, id, _event) {
    if (a === "intro") {
      await intro(id);
    }
    else if (a === "group-chat-open") {
      await groupChatDialog();
    }
  }
  async function handleClickActions25(a, _b, id, _event) {
    if (a === "group-photo-clear") {
      if (S.groupChat) S.groupChat.photo = null;
      const hidden=document.querySelector('[data-form="group-chat"] [name="photo_id"]'); if (hidden) { const old=Number(hidden.dataset.pendingMedia||0); if(old) { void discardTemporaryMedia(old); } hidden.value='0'; delete hidden.dataset.pendingMedia; }
      const preview=document.getElementById('group-photo-preview'); if (preview) preview.innerHTML=`<span class="group-photo-placeholder">${I('users')}</span>`;
    }
    else if (a === "group-edit-open") {
      await groupEditDialog(id);
    }
  }
  async function handleClickActions26(a, b, _id, _event) {
    if (a === "group-edit-photo-clear") {
      if (S.groupEdit) { S.groupEdit.photo_id = 0; S.groupEdit.photo_url = ''; }
      const hidden=document.querySelector('[data-form="group-edit"] [name="photo_id"]'); if (hidden) { const old=Number(hidden.dataset.pendingMedia||0); if(old) { void discardTemporaryMedia(old); } hidden.value='0'; delete hidden.dataset.pendingMedia; }
      const preview=document.getElementById('group-edit-photo-preview'); if (preview) preview.innerHTML=`<span class="group-photo-placeholder">${I('users')}</span>`;
    }
    else if (a === "conversation-open") {
      const destination=new URL(C.pages.mensajeria.url); destination.searchParams.set('conversation',Number(b.dataset.conversation)); await navigateTo(destination.href);
    }
  }
  async function handleClickActions27(a, b, id, _event) {
    if (a === "chat-tab") {
      S.messageTab = b.dataset.tab === 'requests' ? 'requests' : 'chats';
      S.conversation = 0;
      const url=new URL(location.href); url.searchParams.delete('conversation'); history.replaceState(history.state,'',url);
      await messages();
    }
    else if (a === "group-menu") {
      await groupInfoDialog(id);
    }
  }
  async function handleClickActions28(a, _b, id, _event) {
    if (a === "group-delete-request") {
      modal('Eliminar grupo', `<p>${E(T('¿Seguro que deseas eliminar este grupo? Se eliminarán sus mensajes para todos los participantes.'))}</p><div class="form-actions">${btn('Cancelar','close')}${btn('Eliminar grupo','group-delete-confirm',("data-id=\"" + (id) + "\""),'danger')}</div>`);
    }
    else if (a === "group-delete-confirm") {
      await api(`conversations/${id}`, null, 'DELETE');
      closeModal(); S.conversation=0; await messages(); toast('Grupo eliminado.');
    }
  }
  async function handleClickActions29(a, _b, id, _event) {
    if (a === "group-member-message") {
      const profile = await api('profiles/' + id);
      const request = profile.conversation || {};
      closeModal();
      if (request.can_message) {
      const c = await api('conversations', {target:id});
      const destination = new URL(C.pages.mensajeria.url); destination.searchParams.set('conversation', c.id); await navigateTo(destination.href);
      } else if (request.conversation_id && ['incoming_pending','outgoing_pending'].includes(request.state)) {
      const destination = new URL(C.pages.mensajeria.url); destination.searchParams.set('conversation', Number(request.conversation_id)); await navigateTo(destination.href);
      } else if (request.can_request) {
      await conversationRequestDialog(id);
      } else {
      toast(request.blocked ? 'No puedes iniciar una conversación privada con este integrante.' : 'La conversación privada no está disponible para este integrante.');
      }
    }
    else if (a === "message-start") {
      const c = await api("conversations", { target: id });
      const destination = new URL(C.pages.mensajeria.url); destination.searchParams.set("conversation", c.id); await navigateTo(destination.href);
    }
  }
  async function openConversationAction(id) {
    S.conversation = id;
    S.messagesLoaded = false;
    await messages();
  }
  async function backToChatList() {
    const draft = document.querySelector('.chat-compose');
    if (draft) chatDrafts.set(Number(draft.dataset.id), draft.elements.body.value);
    S.conversation = 0;
    const url = new URL(location.href);
    url.searchParams.delete('conversation');
    history.replaceState(history.state, '', url);
    await messages();
  }
  function blockConfirmation(id) {
    const confirmData='data-id="' + id + '" data-active="true" data-confirmed="true"';
    const actions=btn(T('Cancelar'),'close') + btn(T('Confirmar bloqueo'),'block',confirmData,'danger primary');
    return '<p>' + E(T('Se eliminará la conexión y se ocultará la conversación para ambos. Desbloquear no restablece la conexión.')) + '</p><div class="form-actions">' + actions + '</div>';
  }
  async function toggleBlockAction(b, id) {
    const active = b.dataset.active === "true";
    if (active && b.dataset.confirmed !== 'true') {
      modal(T('Bloquear asociado'), blockConfirmation(id));
      return;
    }
    if (active) closeModal();
    await api("relations", { target: id, kind: "block", active });
    if (S.page === 'mensajeria') {
      S.conversation=0;
      const url=new URL(location.href);
      url.searchParams.delete('conversation');
      history.replaceState(history.state,'',url);
      await messages();
    } else {
      const profile = await api('profiles/' + id);
      updateRelationshipState(profile);
      await refreshConnectionsPanel();
      if (active) {
        root.querySelector('.modal [data-profile-affinity]')?.remove();
        root.querySelector('.modal [data-profile-affinity-score]')?.replaceChildren();
      }
    }
    toast(T(active ? 'Asociado bloqueado.' : 'Asociado desbloqueado.'));
  }
  async function handleClickActions30(a, b, id, _event) {
    if (a === "conversation") { await openConversationAction(id);return; }
    if (a === "chat-back") { await backToChatList();return; }
    if (a === "block") await toggleBlockAction(b,id);
  }
  async function handleClickActions31(a, b, id, _event) {
    if (a === "older-messages") {
      await olderMessages(b);
    }
    else if (a === "event-cancel-request") {
      const title = T(b.dataset.micro === "true" ? "Cancelar microevento" : "Cancelar evento");
      modal(title, `<div class="event-cancellation"><p class="detail-body">${E(b.dataset.title || "")}</p><p>${E(T("¿Seguro que deseas cancelar este evento?"))}</p><p class="private-note">${E(T("El evento se conservará como cancelado y se notificará a las personas inscritas, invitadas o en lista de espera. Esta acción no elimina el historial."))}</p>${field("cancellation_reason","Motivo de cancelación","","textarea",'required maxlength="1000" rows="4"')}<div class="form-actions">${btn(T("Volver"),"item",("data-id=\"" + (id) + "\""))}${btn(title,"event-cancel-confirm",("data-id=\"" + (id) + "\""),'danger primary')}</div></div>`);
    }
  }
  async function handleClickActions32(a, b, id, _event) {
    if (a === "event-cancel-confirm") {
      const reasonField = document.querySelector('[name="cancellation_reason"]');
      if (!reasonField.reportValidity()) return;
      await api(`events/${id}/cancel`, {reason:reasonField.value.trim()});
      toast("Evento cancelado. Las personas relacionadas fueron notificadas.");
      if (S.page === "eventos") await listing();
      else if (S.page === "admin") await admin();
      await item(id);
    }
    else if (a === "register") {
      const requested = b.dataset.status;
      const updated = await api(`events/${id}/register`, { status: requested });
      const messages = {
      accepted: "Asistencia confirmada.",
      waitlisted: "Te añadimos a la lista de espera.",
      declined: "Actualizado. Si había un cupo reservado para ti, se ofreció al siguiente asociado.",
      cancelled: "Inscripción actualizada.",
      };
      toast(messages[requested] || "Inscripción actualizada.");
      S.event = updated;
      await item(id);
    }
  }
  async function handleClickActions33(a, b, _id, _event) {
    if (a === "page") {
      S.filter.page = b.dataset.page;
      await render();
      const title = content().querySelector("h1");
      if (title) { title.tabIndex = -1; title.focus({preventScroll:true}); title.scrollIntoView({block:"start"}); }
    }
    else if (a === "filter-reset") {
      S.filter = S.page==='foros' && S.filter.comments ? {comments:1} : {};
      await render();
    }
    else if (a.startsWith("filter-")) {
      S.filter = {
      ...S.filter, page: 1,
      mine: a === "filter-mine" ? 1 : "",
      drafts: a === "filter-drafts" ? 1 : "",
      past: a === "filter-past" ? 1 : "",
      comments: a === "filter-comments" ? 1 : "",
      };
      if (S.filter.drafts) { S.filter.mine = 1; delete S.filter.after; delete S.filter.before; }
      await render();
    }
  }
  async function handleClickActions34(a, b, _id, _event) {
    if (a === "calendar-prev" || a === "calendar-next") {
      S.calendar = new Date(
      S.calendar.getFullYear(),
      S.calendar.getMonth() + (a === "calendar-next" ? 1 : -1),
      1,
      );
      S.events = await calendarEvents();
      document.getElementById("calendar-body").innerHTML = calendar(S.events);
    }
    else if (a === "calendar-day") {
      const day = Number(b.dataset.day), from = new Date(S.calendar.getFullYear(), S.calendar.getMonth(), day), to = new Date(S.calendar.getFullYear(), S.calendar.getMonth(), day + 1);
      const events = S.events.filter(p => new Date(p.meta.start) < to && new Date(p.meta.end) > from);
      modal("Agenda del " + from.toLocaleDateString("es-PE"), events.map(eventMini).join(""));
    }
  }
  async function handleClickActions35(a, _b, id, _event) {
    if (a === "notification-read") {
      await api("notifications/" + id + "/read", {});
      await notifications();
      await refreshNotifications();
    }
    else if (a === "notification-delete-request") {
      modal(T('Eliminar notificación'), `<p class="detail-body">${E(T('Esta notificación se eliminará de tu bandeja de actividad.'))}</p><div class="form-actions">${btn(T('Cancelar'),'close')}${btn(T('Eliminar'),'notification-delete-confirm',("data-id=\"" + (id) + "\""),'danger primary')}</div>`);
    }
  }
  async function handleClickActions36(a, b, id, _event) {
    if (a === "notification-delete-confirm") {
      await api("notifications/" + id, null, "DELETE");
      closeModal(); await notifications(); await refreshNotifications(); toast('Notificación eliminada.');
    }
    else if (a === "ask-suggestion") {
      const input=document.querySelector('[data-form="ask"] [name="question"]');
      if (input) { input.value = b.dataset.question; document.querySelector("[data-form=ask]").requestSubmit(); }
    }
  }
  async function handleClickActions37(a, _b, _id, _event) {
    if (a === "assistant-new") {
      const url = new URL(location.href);
      ["job", "history", "notification"].forEach(key => url.searchParams.delete(key));
      history.replaceState(history.state, "", url);
      await assistant(true);
      document.querySelector('[data-form="ask"] [name="question"]')?.focus();
    }
    else if (a === "ai-test") {
      const target=document.getElementById('ai-test-result'); target.textContent=T('Probando el proveedor, el modelo y la clave guardados…');
      try { const r=await api('ai/test',{}); target.textContent=T(r.message)+' '+T('Modelo:')+' '+r.model; target.className='alert success'; }
      catch(error) { target.textContent=error.message; target.className='alert error'; }
    }
  }
  async function handleClickActions38(a, b, id, _event) {
    if (a === "admin-delete") {
      const kind=b.dataset.kind;
      modal(T('Confirmar eliminación'),`<p>${E(T(kind==='report'?'Se eliminará el reporte revisado. El contenido reportado se conservará.':'La solicitud cerrada se ocultará de la bandeja activa. El asociado conservará el contenido y el historial.'))}</p><div class="form-actions">${btn('Cancelar','close')}${btn(kind==='report'?'Eliminar':'Archivar','admin-delete-confirm',("data-kind=\"" + (kind) + "\" data-id=\"" + (id) + "\""),'danger primary')}</div>`);
    }
    else if (a === "admin-delete-confirm") {
      await api((b.dataset.kind==='report'?'admin/reports/':'admin/contact/')+id,{},'DELETE'); closeModal(); toast(b.dataset.kind==='report'?'Eliminado':'Solicitud archivada'); await admin();
    }
  }
  async function handleClickActions39(a, b, id, _event) {
    if (a === "report-reviewed") {
      reviewDecisionForm('report', id, 'review');
    }
    else if (a === "request-filter") {
      S.adminFilters.contacts={...S.adminFilters.contacts,state:b.dataset.state,page:1}; await adminContacts(document.getElementById('admin-panel'));
      [...document.querySelectorAll('.support-status-filter')].find(button=>button.dataset.state===b.dataset.state)?.focus();
    }
  }
  async function handleClickActions40(a, b, id, _event) {
    if (a === "admin-page") {
      const area=b.dataset.area;S.adminFilters[area].page=Number(b.dataset.page);await (area==='users'?adminUsers:adminContacts)(document.getElementById('admin-panel'));
    }
    else if (a === "user-access") {
      const suspended=b.dataset.suspended==='true';
      modal(suspended?'Suspender acceso':'Reactivar acceso',`<p>¿${suspended?'Suspender':'Reactivar'} el acceso de <strong>${E(b.dataset.name)}</strong> a la comunidad?</p><p class="private-note">La cuenta y su contenido se conservan.</p><div class="form-actions">${btn('Cancelar','close')}${btn('Confirmar','user-status',("data-id=\"" + (id) + "\" data-suspended=\"" + (suspended) + "\""),'primary')}</div>`);
    }
  }
  async function handleClickActions41(a, b, id, _event) {
    if (a === "user-status") {
      await api('admin/member/'+id,{suspended:b.dataset.suspended==='true'}); closeModal();toast('Acceso actualizado.');await refreshManagedUsers();
    }
    else if (a === "user-create") {
      let roles=[];
      try { roles=JSON.parse(b.dataset.roles||'[]'); } catch {}
      adminUserCreator(roles);
    }
  }
  async function handleClickActions42(a, b, id, _event) {
    if (a === "user-insights") {
      await adminMemberInsights(id);
    }
    else if (a === "user-edit") {
      await adminUserEditor(id);
    }
    else if (a === "directory-manage") {
      await directoryManage(id);
    }
    else if (a === "user-delete") {
      modal('Eliminar usuario',`<div class="admin-delete-warning">${I('shield')}<div><strong>Esta acción es permanente</strong><p>Se eliminará la cuenta de <strong>${E(b.dataset.name)}</strong> (@${E(b.dataset.login)}). Ya no podrá ingresar a ASCLA.</p></div></div><p class="detail-body">Las publicaciones y recursos ya creados se conservarán bajo administración de ASCLA. El historial técnico necesario para auditoría no se elimina.</p><p class="private-note">Correo de la cuenta: ${E(b.dataset.email)}</p><div class="form-actions">${btn('Cancelar','close')}${btn('Sí, eliminar usuario','user-delete-confirm',("data-id=\"" + (id) + "\" data-name=\"" + (E(b.dataset.name)) + "\""),'danger primary')}</div>`);
    }
  }
  async function handleClickActions43(a, b, id, _event) {
    if (a === 'admin-users-reset') {
      S.adminFilters.users={};await adminUsers(document.getElementById('admin-panel'));
    }
    else if (a === "user-delete-confirm") {
      const result=await api('admin/users/'+id,null,'DELETE'); closeModal(); toast(result.message||'Usuario eliminado.'); await refreshManagedUsers();
    }
    else if (a === "admin-tab") {
      if (!(await confirmUnsavedExit())) return;
      S.adminTab = b.dataset.tab;
      await admin();
    }
  }
  async function handleClickActions44(a, _b, id, _event) {
    if (a === "admin-refresh") {
      if (await confirmUnsavedExit()) await admin();
    }
    else if (a === "moderate") {
      await openContentReview(id);
    }
  }
  async function handleClickActions45(a, b, id, _event) {
    if (a === "comment-moderate") {
      reviewDecisionForm('comment', id, b.dataset.decision);
    }
    else if (a === "contact-status") {
      await api("admin/contact/" + id, { status: b.dataset.status });
      toast("Solicitud #"+id+": "+requestLabels[b.dataset.status]+". Asociado notificado.");
      await adminContacts(document.getElementById('admin-panel'));
    }
  }
  function reviewDecisionForm(kind, id, decision) {
    const title = kind === 'report' ? 'Revisar reporte' : 'Revisar comentario';
    let label = 'Mantener oculto';
    if (kind === 'report') label = 'Marcar como revisado';
    else if (decision === 'approve') label = 'Aprobar';
    modal(T(title), `<p>${E(T(label))}</p><form data-form="review-decision" data-kind="${kind}" data-id="${id}" data-decision="${decision}">${field('reason', 'Motivo de la decisión', '', 'textarea', 'required maxlength="1000"')}<div class="form-actions">${btn('Cancelar', 'close')}<button class="btn primary">${E(T('Guardar decisión'))}</button></div></form>`);
  }
  async function handleClickActions46(a, _b, id, _event) {
    if (a === "generate") {
      try { await ensureYouTubeDuration(id); } catch { /* Server-side metadata remains the primary path. */ }
      const j = await api("jobs", { kind: "multimedia", resource_id: id });
      modal("Procesar conferencia", '<div id="job-result"></div>');
      watchJob(j.id, document.getElementById("job-result"), (r) => {
      document.getElementById("job-result").innerHTML =
      `<div class="alert">${E(r.message)} · ${E(r.mode)}</div>${btn("Abrir publicación actualizada", "item", ("data-id=\"" + (r.resource_id) + "\""), "primary")}`;
      });
    }
    else if (a === "event-invite") {
      S.invite = null;
      await inviteMembers(id);
    }
  }
  async function handleClickActions47(a, b, id, _event) {
    if (a === "invite-page") {
      await inviteMembers(S.invite.id, Number(b.dataset.page), S.invite.query);
    }
  }
  async function handleClickActions48(a, _b, _id, _event) {
    if (a === "micro-job") {
      const target=document.getElementById("micro-job-result");
      const j = await api("jobs", { kind: "microevents" });
      watchJob(j.id, target, (r) => {
      if (!target.isConnected) return;
      const generatedLabel=r.events.length ? "Propuestas preparadas para revisión." : "No se generaron propuestas con las condiciones actuales.";
      const resultLabel=r.reused ? "Se recuperaron las propuestas existentes del período; no se crearon duplicados." : generatedLabel;
      target.innerHTML = `<div class="alert">${E(T(resultLabel))}<p>${Number(r.events.length)} ${E(T("microeventos"))} · ${Number(r.waiting.length)} ${E(T("asociados en espera"))}</p></div>${microDiagnosticsHTML(r.diagnostics)}${r.events.map((e) => btn("Revisar #" + e, "item", ("data-id=\"" + (e) + "\""), "small")).join("")}`;
      });
    }
    else if (a === "social-job") {
      const j = await api("jobs", { kind: "social" });
      toast("Curaduría DEMO en cola: #" + j.id);
      await admin();
    }
  }
  async function handleClickActions49(a, _b, id, _event) {
    if (a === "job-detail") {
      const j = await api("jobs/" + id);
      modal(
      "Trabajo #" + id,
      `${status(j.status)}<p class="detail-body">${E(j.error || j.result?.message || j.result?.answer || "")}</p>${j.result?.resource_id ? btn("Abrir recurso", "item", ("data-id=\"" + (j.result.resource_id) + "\"")) : ""}${j.result?.events ? j.result.events.map((e) => btn("Microevento #" + e, "item", ("data-id=\"" + (e) + "\""))).join("") : ""}${j.result?.items ? j.result.items.map((i) => ("<p>" + (E(i.suggested_reply || "")) + "</p>" + (btn("Abrir borrador", "item", ("data-id=\"" + (i.draft_id) + "\""))) + "")).join("") : ""}`,
      );
    }
    else if (a === "retry-job") {
      await api("jobs/" + id + "/retry", {});
      toast("Trabajo nuevamente en cola.");
      if (S.page === "admin") {
      closeModal();
      await admin();
      } else if (S.page === "asistente") {
      document.getElementById("answers").innerHTML = "";
      await restoreAnswer(id);
      }
    }
  }
  async function handleClickActions50(a, b, _id, _event) {
    if (a === "google-connect") {
      const r = await api("google/connect", { service: b.dataset.service });
      await navigateTo(r.url);
    }
    else if (a === "google-disconnect") {
      await api("google/disconnect", { service: b.dataset.service });
      toast("Conexión eliminada.");
      S.boot = await api("bootstrap");
      await profile();
    }
  }
  async function handleClickActions51(a, b, id, _event) {
    if (a === "google-event") {
      await api(`events/${id}/google`, { operation: b.dataset.operation });
      toast("Google Calendar actualizado.");
    }
    else if (a === "mail-test") {
      const result = await api("mail/test", {}); toast(result.message);
    }
    else if (a === "delivery-list") { await deliveryList(); }
    else if (a === "delivery-retry") { await api(`admin/deliveries/${id}/retry`,{}); await deliveryList(); }
  }
  async function handleClickActions52(a, _b, _id, _event) {
    if (a === "infographic") {
      infographic();
    }
  }
  const HANDLECLICKACTIONS_DISPATCH = [
    { test: a => ['micro-archive','micro-archive-tab'].includes(a), run: handleMicroArchive },
    { test: (a) => (a === "close") || (a === "modal-unsaved-stay"), run: handleClickActions01 },
    { test: (a) => (a === "modal-unsaved-discard") || (a === "modal-unsaved-save") || (a === "save-event-draft") || (a === "save-content-draft"), run: handleClickActions02 },
    { test: (a) => (a === "profile-review-confirm") || (a === "profile-nudge-later") || (a === "profile-nudge-go"), run: handleClickActions03 },
    { test: (a) => (a === "unsaved-stay") || (a === "unsaved-discard") || (a === "unsaved-save"), run: handleClickActions04 },
    { test: (a) => (a === "theme-menu") || (a === "theme-set"), run: handleClickActions05 },
    { test: (a) => (a === "menu") || (a === "refresh"), run: handleClickActions06 },
    { test: (a) => (a === "rules") || (a === "member") || (a === "recommendation-open"), run: handleClickActions07 },
    { test: (a) => (a === "item") || (a === "files"), run: handleClickActions08 },
    { test: (a) => (a === "detach-media") || (a === "event-cover-clear"), run: handleClickActions09 },
    { test: (a) => (a === "delete-content") || (a === "delete-comment"), run: handleClickActions10 },
    { test: (a) => a === 'chat-hide', run: async (a,b) => { await api(`conversations/${Number(b.dataset.id)}/hide`, {}); stopChat(); S.conversation = 0; history.replaceState(null, '', location.pathname); await messages(); } },
    { test: (a) => (a === "delete-message") || (a === "comment-reply"), run: handleClickActions11 },
    { test: (a) => (a === "comment-reply-cancel") || (a === "report-comment"), run: handleClickActions12 },
    { test: (a) => (a === "comment-like") || (a === "content-reaction") || (a === "delete-media"), run: handleClickActions13 },
    { test: (a) => (a === "delete-cancel") || (a === "delete-confirm"), run: handleClickActions14 },
    { test: (a) => (a === "editor") || (a === "notifications"), run: handleClickActions15 },
    { test: (a) => (a === "notification-filter") || (a === "notification-page"), run: handleClickActions16 },
    { test: (a) => (a === "notifications-read-all") || (a === "notification-open"), run: handleClickActions17 },
    { test: (a) => (a === "answer-history") || (a === "report"), run: handleClickActions18 },
    { test: (a) => a === "like" || (["recommendation-dismiss", "recommendation-restore", "password-reset-email"].includes(a)), run: handleClickActions19 },
    { test: (a) => (a === "connect") || (a === "connection-respond"), run: handleClickActions20 },
    { test: (a) => (a === "conversation-request") || (a === "conversation-request-respond"), run: handleClickActions21 },
    { test: (a) => (a === "conversation-request-cancel") || (a === "connection-remove-request"), run: handleClickActions22 },
    { test: (a) => ["connection-remove-confirm","connections-refresh","directory-reset","connection-tab"].includes(a), run: handleClickActions23 },
    { test: (a) => (a === "intro") || (a === "group-chat-open"), run: handleClickActions24 },
    { test: (a) => (a === "group-photo-clear") || (a === "group-edit-open"), run: handleClickActions25 },
    { test: (a) => (a === "group-edit-photo-clear") || (a === "conversation-open"), run: handleClickActions26 },
    { test: (a) => (a === "chat-tab") || (a === "group-menu"), run: handleClickActions27 },
    { test: (a) => (a === "group-delete-request") || (a === "group-delete-confirm"), run: handleClickActions28 },
    { test: (a) => (a === "group-member-message") || (a === "message-start"), run: handleClickActions29 },
    { test: (a) => (a === "conversation") || (a === "block") || (a === "chat-back"), run: handleClickActions30 },
    { test: (a) => (a === "older-messages") || (a === "event-cancel-request"), run: handleClickActions31 },
    { test: (a) => (a === "event-cancel-confirm") || (a === "register"), run: handleClickActions32 },
    { test: (a) => (a === "page") || (a.startsWith("filter-")), run: handleClickActions33 },
    { test: (a) => (a === "calendar-prev" || a === "calendar-next") || (a === "calendar-day"), run: handleClickActions34 },
    { test: (a) => (a === "notification-read") || (a === "notification-delete-request"), run: handleClickActions35 },
    { test: (a) => (a === "notification-delete-confirm") || (a === "ask-suggestion"), run: handleClickActions36 },
    { test: (a) => (a === "assistant-new") || (a === "ai-test"), run: handleClickActions37 },
    { test: (a) => (a === "admin-delete") || (a === "admin-delete-confirm"), run: handleClickActions38 },
    { test: (a) => (a === "report-reviewed") || (a === "request-filter"), run: handleClickActions39 },
    { test: (a) => (a === "admin-page") || (a === "user-access"), run: handleClickActions40 },
    { test: (a) => (a === "user-status") || (a === "user-create"), run: handleClickActions41 },
    { test: (a) => (a === "user-insights") || (a === "user-edit") || (a === "user-delete") || (a === "directory-manage"), run: handleClickActions42 },
    { test: (a) => (a === "user-delete-confirm") || (a === "admin-tab") || (a === "admin-users-reset"), run: handleClickActions43 },
    { test: (a) => (a === "admin-refresh") || (a === "moderate"), run: handleClickActions44 },
    { test: (a) => (a === "comment-moderate") || (a === "contact-status"), run: handleClickActions45 },
    { test: (a) => (a === "generate") || (a === "event-invite"), run: handleClickActions46 },
    { test: (a) => (a === "invite-page"), run: handleClickActions47 },
    { test: (a) => (a === "micro-job") || (a === "social-job"), run: handleClickActions48 },
    { test: (a) => (a === "job-detail") || (a === "retry-job"), run: handleClickActions49 },
    { test: (a) => (a === "google-connect") || (a === "google-disconnect"), run: handleClickActions50 },
    { test: (a) => ["google-event","mail-test","delivery-list","delivery-retry"].includes(a), run: handleClickActions51 },
    { test: (a) => (a === "infographic"), run: handleClickActions52 },
  ];
root.addEventListener("click", async (event) => {
    const b = event.target.closest("[data-action]");
    if (!b) return;
    if (b.dataset.action === "board-role-approval") { event.preventDefault(); boardApprovalEditor(Number(b.dataset.id)); return; }
    if (b.dataset.action === "notification-open" && (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey)) return;
    event.preventDefault();
    const a = b.dataset.action,
      id = Number(b.dataset.id || 0);
    S.actionFocus = b;
    b.disabled = true;
    try {
      if (a==='editorial-state' || a==='editorial-history') { await editorialAction(a,id);return; }
      if (a==='editorial-page') { S.editorialFilters={...S.editorialFilters,page:Number(b.dataset.page)};await adminEditorialRegistry(document.getElementById('admin-panel'));return; }
      const actionHandler = HANDLECLICKACTIONS_DISPATCH.find((entry) => entry.test(a));
      if (actionHandler) await actionHandler.run(a, b, id, event);
    } catch (e) {
      if (['connect','connection-respond','connection-remove-confirm','conversation-request-respond','conversation-request-cancel'].includes(a) && [404,409].includes(e.status)) { await refreshOpenConnection(); await refreshConnectionsPanel(); }
      if (e.name !== "AbortError") toast(e.message);
    } finally {
      b.disabled = false;
      if (S.actionFocus === b) S.actionFocus = null;
      if (b.isConnected && document.activeElement === document.body && !b.closest('[inert]')) b.focus();
    }
  });
  async function handleSubmitActions01(action, _form, data, _submit, _event) {
    if (action === "global-search") {
      const url = new URL(C.pages["centro-conocimiento"].url, location.href);
      url.searchParams.set("q", data.q);
      await navigateTo(url.href);
    }
    else if (action === "filters") {
      if (data.after && data.before && data.after > data.before) throw new Error(T("La fecha inicial no puede ser posterior a la fecha final."));
      if (S.page === "directorio") {
        const values = new FormData(_form);
        for (const key of ["industries", "interests", "areas"]) data[key] = values.getAll(key).map(Number);
      }
      S.filter = { ...S.filter, ...data, page: 1 };
      await render();
    }
  }
  async function handleSubmitActions02(action, form, data, _submit, _event) {
    if (action === "invite-search") {
      await inviteMembers(Number(form.dataset.id), 1, data.q);
    }
    else if (action === "file-search") {
      await files(1, data.q, S.files?.scope || "mine", Number(data.owner || 0));
    }
  }
  function googleInviteNote(result) {
    if (result.google_calendar?.queued) return ' '+T('La entrega a Google Calendar quedó registrada y se reintentará si el servicio falla.');
    const googleSent = Number(result.google_calendar?.sent || 0);
    if (googleSent > 0) {
      const suffix = googleSent === 1 ? "" : "es";
      return ` Google Calendar envió ${googleSent} invitación${suffix} por correo.`;
    }
    if (result.google_calendar?.error) return ` Google Calendar: ${result.google_calendar.error}`;
    if (result.sent > 0 && !result.google_calendar?.available) return " Google Calendar no está conectado en la cuenta organizadora.";
    return "";
  }
  async function submitEventInvites(form) {
    const result = await api("events/" + form.dataset.id + "/invite", { users: [...S.invite.selected] });
    closeModal();
    S.invite = null;
    toast(`${result.sent} invitaciones enviadas; ${result.skipped} asociados ya tenían una inscripción o invitación.${googleInviteNote(result)}`);
    await item(Number(form.dataset.id));
  }
  function collectProfileData(form, data) {
    const formData = new FormData(form);
    for (const key of Object.keys(profileTax)) data[key] = formData.getAll(key).map(Number);
    data.hidden = formData.getAll("hidden");
    for (const key of ["directory", "networking", "microevents"]) {
      const control = form.elements.namedItem(key);
      if (control) data[key] = control.checked;
    }
    data.email_notifications = {};
    for (const key of ["connections", "messages", "events", "support"]) {
      data.email_notifications[key] = !!form.elements[`email_${key}`]?.checked;
      delete data[`email_${key}`];
    }
    delete data.photo;
    return data;
  }
  async function submitProfile(form, data) {
    await validateProfileLocations(form);
    const profileData = collectProfileData(form, data);
    const missing = [];
    for (const key of ["first_name","last_name","position","company","bio"]) {
      if (!String(profileData[key] || "").replace(/[\s\u200B\uFEFF]/gu,"")) missing.push(profileLabels[key] || "Biografía");
    }
    if (!String(profileData.country || "").trim() && !String(profileData.city || "").trim()) missing.push("Ciudad o país");
    for (const [key,label] of [["industries","Sector profesional"],["interests","Al menos un interés"],["goals","Al menos un objetivo de networking"]]) {
      if (!profileData[key]?.length) missing.push(label);
    }
    if (missing.length) throw new Error(T("Completa los campos obligatorios:") + " " + missing.map(T).join(", "));
    await api("profiles/me", profileData);
    const savedPhotoInput = form.querySelector('[name="photo_id"]');
    if (savedPhotoInput) delete savedPhotoInput.dataset.pendingMedia;
    clearUnsavedGuard(form);
    S.boot = await api("bootstrap");
    const label = root.querySelector(".header-profile strong");
    if (label) label.textContent = S.boot.me.name;
    toast("Perfil actualizado.");
    await profile();
  }
  async function handleSubmitActions03(action, form, data, _submit, _event) {
    if (action === "event-invite") {
      await submitEventInvites(form);
      return;
    }
    if (action === "profile") await submitProfile(form, data);
  }
  async function handleSubmitActions04(action, form, data, _submit, _event) {
    if (action === "password-change") {
      const result = await api("account/password", {
      current_password: data.current_password,
      new_password: data.new_password,
      confirm_password: data.confirm_password,
      });
      form.reset();
      toast(result.message + " Actualizando tu sesión…");
      setTimeout(() => location.reload(), 900);
    }
    else if (action === "report") {
      const target = form.dataset.kind === "comment" ? `comments/${Number(form.dataset.id)}/report` : `items/${Number(form.dataset.id)}/report`;
      const result = await api(target, {
      reason: data.reason,
      detail: data.detail || "",
      });
      closeModal();
      toast(`${form.dataset.kind === "comment" ? "Comentario reportado" : "Reporte enviado"}: ${result.reason_label}.`);
    }
  }
  function microEventFields(m) {
    if (!m.micro) return "";
    return '<div class="form-section">'+E(T("Convocatoria del microevento"))+'</div><div class="form-grid">'+
      field("micro_min","Mínimo de participantes",m.micro_min || 4,"number",'min="2" max="50" required')+
      field("priority_hours","Horas de inscripción prioritaria",m.priority_hours ?? 48,"number",'min="0" max="168" required')+
      field("registration_deadline","Cierre de inscripciones",editorDateValue(m.registration_deadline),"datetime-local","required")+'</div>';
  }
  function editorMeta(form, data, type) {
    const meta = {};
    for (const key of [
      "start", "end", "capacity", "offer_hours", "micro_min", "priority_hours", "change_reason", "modality", "location", "url", "agenda", "resource_type", "source",
      "youtube_url", "copyright", "summary", "transcript", "identities", "alliance_type", "benefits",
      "initiatives", "event_id", "duration_seconds",'contact_name','contact_email','contact_phone','conditions',
    ]) {
      if (key in data) meta[key] = data[key];
    }
    if (type === "event") {
      meta.start = eventDateTimeISO(data.start_date, data.start_time);
      meta.end = eventDateTimeISO(data.end_date, data.end_time);
      if ("registration_deadline" in data) meta.registration_deadline = data.registration_deadline ? new Date(data.registration_deadline).toISOString() : "";
      if (data.reminder_hours) meta.reminder_hours = data.reminder_hours.split(",").map(v=>Number(v.trim()));
    }
    if (form.elements.chatham) meta.chatham = form.elements.chatham.checked;
    meta.media_ids = [...form.querySelectorAll("[data-media]")].map((element) => Number(element.dataset.media));
    return meta;
  }
  function editorSavedMessage(statusValue, meta = {}) {
    if (meta.publish_at) return statusValue === 'pending' ? 'Programación guardada. Falta aprobación administrativa.' : 'Publicación programada.';
    if (statusValue === "publish") return "Contenido publicado.";
    if (statusValue === "draft") return "Borrador guardado.";
    return "Contenido enviado a revisión.";
  }
  async function submitEditorForm(form, data) {
    const type = form.dataset.type;
    const id = Number(form.dataset.id);
    const p = await api("content/" + type + (id ? "/" + id : ""), {
      title: data.title,
      body: data.body,
      status: data.status,
      publish_at: data.status === 'scheduled' ? new Date(data.publish_at).toISOString() : '',
      parent: Number(data.parent || 0),
      interest: new FormData(form).getAll("interest").map(Number),
      category: data.category ? [Number(data.category)] : [],
      tag_names: data.tag_names.split(",").map(tag => tag.trim()).filter(Boolean),
      meta: editorMeta(form, data, type),
    });
    let calendarMessage = "";
    if (type === "event" && p.status === "publish" && S.boot.google_connected) {
      try {
        await api(`events/${p.id}/google`, { operation: "organize" });
        calendarMessage = " Sincronizado con tu Google Calendar.";
      } catch (error) {
        calendarMessage = ` El evento se guardó en ASCLA, pero Google Calendar no pudo sincronizarse: ${error.message}`;
      }
    }
    closeModal();
    toast(type==='ally'&&p.meta.ally_status==='inactive'?'Aliado guardado como inactivo. Su registro se conserva.':editorSavedMessage(p.status, p.meta) + calendarMessage);
    if (p.status === 'draft' && typeByPage[S.page] === (type === 'forum' ? 'topic' : type)) S.filter = {mine:1, drafts:1, page:1};
    S.boot = await api("bootstrap");
    await render();
  }
  async function handleSubmitActions05(action, form, data, _submit, _event) {
    if (action === "editor") {
      await submitEditorForm(form, data);
    } else if (action === "comment") {
      const r = await api("items/" + form.dataset.id + "/comments", { body: data.body });
      toast(r.status === "pending" ? "Comentario enviado a revisión." : "Comentario publicado.");
      await item(Number(form.dataset.id));
    }
  }
  async function handleSubmitActions06(action, form, data, _submit, _event) {
    if (action === "comment-reply") {
      const r = await api("items/" + form.dataset.id + "/comments", {
      body: data.body,
      parent: Number(form.dataset.parent),
      });
      toast(r.status === "pending" ? "Respuesta enviada a revisión." : "Respuesta publicada.");
      await item(Number(form.dataset.id));
    }
    else if (action === "message") {
      const chat = S.chat;
      form.dataset.sending = 'true';
      try {
      await api("conversations/" + form.dataset.id + "/messages", { body: data.body });
      if (form.elements.body.value === data.body) { form.reset(); chatDrafts.delete(Number(form.dataset.id)); }
      if (chatAlive(chat)) { await loadMessages(chat); await syncChat(); }
      } finally { delete form.dataset.sending; }
    }
  }
  async function handleSubmitActions07(action, form, data, _submit, _event) {
    if (action === "conversation-request-message") {
      const target=Number(form.dataset.id);
      const result=await api('conversation-requests',{target,body:data.body});
      closeModal(); await refreshNotifications();
      const destination=new URL(C.pages.mensajeria.url); if (result.conversation_id) { destination.searchParams.set('conversation',result.conversation_id); } await navigateTo(destination.href);
      toast('Mensaje enviado como solicitud de conversación.');
    }
    else if (action === "group-chat") {
      const users=[...form.querySelectorAll('[data-group-member]:checked')].map(input=>Number(input.value));
      if (users.length < 2) { toast('Selecciona al menos dos asociados para crear un grupo.'); return; }
      const conversation=await api('conversations/group',{title:data.title,description:data.description || '',users,photo_id:Number(data.photo_id || 0)});
      S.groupChat=null; closeModal();
      const destination=new URL(C.pages.mensajeria.url); destination.searchParams.set('conversation',conversation.id); await navigateTo(destination.href);
      toast('Grupo creado.');
    }
  }
  async function handleSubmitActions08(action, form, data, _submit, _event) {
    if (action === "group-edit") {
      const groupId=Number(form.dataset.id);
      await api(`conversations/${groupId}/group`,{title:data.title,description:data.description || '',photo_id:Number(data.photo_id || 0)});
      S.groupEdit=null;
      closeModal();
      if (S.page === 'mensajeria') await messages();
      await groupInfoDialog(groupId);
      toast('Información del grupo actualizada.');
    }
    else if (action === "intro") {
      const c = await api("conversations", {
      target: Number(form.dataset.id),
      });
      await api("conversations/" + c.id + "/messages", { body: data.body });
      closeModal();
      toast("Mensaje enviado.");
    }
  }
  async function handleSubmitActions09(action, form, data, _submit, _event) {
    if (action === "ask") {
      const question=String(data.question || "").trim();
      const area=document.getElementById("assistant-messages");
      if (!question || !area) return;
      area.insertAdjacentHTML("beforeend", assistantUserBubble(question));
      const target=document.createElement("article");target.className="assistant-message assistant";target.innerHTML=assistantLoadingBody();area.append(target);
      form.reset();assistantScroll();
      try {
      const j = await api("ask", { question, thread: assistantThread() });
      target.id=`assistant-job-${j.id}`;
      watchJob(j.id, target, (r) => { target.innerHTML = assistantAnswerBody(r); assistantScroll(); });
      } catch(error) { target.innerHTML=`<div class="error">${E(error.message)}</div>`; }
    }
    else if (action === "contact") {
      if (data.website_confirm) throw new Error("Solicitud no válida.");
      await api("content/contact", {
      title: data.title,
      body: data.body,
      meta: { description: data.category },
      });
      toast("Solicitud recibida.");
      await contact();
    }
  }
  async function handleSubmitActions10(action, form, data, _submit, _event) {
    if (action === "review-decision") {
      const route = form.dataset.kind === 'report' ? 'admin/reports/' + form.dataset.id + '/review' : 'admin/comments/' + form.dataset.id;
      await api(route, {decision: form.dataset.decision, reason: data.reason});
      closeModal();
      toast(T('Decisión registrada.'));
      await admin();
    }
    else if (action === "moderate") {
      const reviewed = await api("items/" + form.dataset.id + "/moderate", {
      decision: data.decision,
      reason: data.reason,
      reviewed: form.elements.reviewed?.checked || false,
      });
      closeModal();
      toast(T(reviewed.meta.micro && data.decision === "approve" ? "Propuesta aprobada. Elige cuándo publicarla desde su ficha." : "Decisión registrada."));
      await render();
      if (reviewed.meta.micro) await item(Number(form.dataset.id));
    }
    else if (action === "admin-user-create") {
      const result=await api('admin/users',{
      login:data.login,
      email:data.email,
      role:data.role,
      membership_status:data.membership_status,
      membership_until:data.membership_until||'',
      first_name:data.first_name,
      last_name:data.last_name,
      birth_date:data.birth_date||'',
      position:data.position||'',
      company:data.company||'',
      member_type:data.member_type||'',
      send_invite:!!form.elements.send_invite?.checked,
      });
      closeModal();
      toast(result.message||'Usuario creado correctamente.');
      S.adminFilters.users={};
      await refreshManagedUsers();
    }
  }
  async function handleSupportSubmit(action, form, data) {
    const id=Number(form.dataset.id);
    if (action === "contact-assign") await api("admin/contact/"+id+"/assign",{assignee:Number(data.assignee)});
    else await api("admin/contact/"+id,{status:data.status,response:data.response});
    toast(T("Atención actualizada."));
    S.adminContactOpen=id;
    await adminContacts(document.getElementById("admin-panel"));
  }
  async function handleSubmitActions11(action, form, data, _submit, _event) {
    if (action === "board-approval") {
      await api('admin/users/'+Number(form.dataset.id)+'/role-approval', {...data, roles:new FormData(form).getAll('roles'), board_confirmed:!!form.elements.board_confirmed.checked});
      closeModal();toast('Autorización registrada');
    }
    else if (action === "admin-user-edit") {
      const userId=Number(form.dataset.id);
      await validateProfileLocations(form);
      const details=formData(form);
      const result=await api('admin/users/'+userId,{
      ...Object.fromEntries(Object.keys(profileTax).map(key=>[key,new FormData(form).getAll(key).map(Number)])),
      ...Object.fromEntries(['country','city','bio','experience','linkedin','twitter','website','profile_revision'].map(key=>[key,details[key]||''])),
      assigned_roles:[...new Set([data.role,...new FormData(form).getAll("assigned_roles")])],
      permission_overrides:Object.fromEntries(Object.keys(data).filter(key => key.startsWith("permission_") && data[key] === "deny").map(key => [key.slice(11), false])),
      board_reference:data.board_reference || "",
      board_date:data.board_date || "",
      board_confirmed:!!form.elements.board_confirmed?.checked,
      professional_request_id:Number(data.professional_request_id||0),
      phone:data.phone,
      phone_visibility:data.phone_visibility,
      email:data.email,
      role:data.role,
      membership_status:data.membership_status,
      membership_until:data.membership_until||'',
      first_name:data.first_name,
      last_name:data.last_name,
      birth_date:data.birth_date||'',
      position:data.position||'',
      company:data.company||'',
      member_type:data.member_type||'',
      });
      closeModal();
      toast('Usuario actualizado: '+([result.first_name,result.last_name].filter(Boolean).join(' ')||result.login));
      await refreshManagedUsers();
    }
    else if (action === "admin-filter") {
      if(form.dataset.area==='users') {
        const values=new FormData(form);
        for(const key of ['industries','interests','areas']) data[key]=values.getAll(key);
      }
      const area=form.dataset.area; S.adminFilters[area]={...data,page:1}; await (area==='users'?adminUsers:adminContacts)(document.getElementById('admin-panel'));
    }
  }
  async function handleSubmitActions12(action, form, data, _submit, _event) {
    if (action === "settings") {
      for (const k of [
      "demo",
      "moderation_required",
      "moderate_comments",
      "chatham_default",
      "micro_enabled",
      "clear_smtp_password",
      "turnstile_enabled",
      "turnstile_login",
      "turnstile_recovery",
      "turnstile_public",
      "clear_turnstile_secret",
      ])
      data[k] = form.elements[k].checked;
      data.clear_ai_key = data.ai_provider === "gemini" && !!form.elements.clear_ai_key?.checked;
      data.clear_openai_key = data.ai_provider === "openai" && !!form.elements.clear_openai_key?.checked;
      data.clear_deepseek_key = data.ai_provider === "deepseek" && !!form.elements.clear_deepseek_key?.checked;
      data.profile_completion_extra = new FormData(form).getAll("profile_completion_extra");
      data.matching_min_affinity = Number(data.matching_min_affinity);
      data.matching_max_suggestions = Number(data.matching_max_suggestions);
      await api("settings", data);
      clearUnsavedGuard(form);
      toast("Configuración guardada.");
      await admin();
    }
    else if (action === "demo") {
      const r = await api("demo", { password: data.password });
      form.reset();
      toast(r.message);
      await admin();
    }
  }
  const HANDLESUBMITACTIONS_DISPATCH = [
    { test: action => action === "connection-request", run: submitConnectionRequest },
    { test: (action) => (action === "global-search") || (action === "filters"), run: handleSubmitActions01 },
    { test: (action) => (action === "invite-search") || (action === "file-search"), run: handleSubmitActions02 },
    { test: (action) => (action === "event-invite") || (action === "profile"), run: handleSubmitActions03 },
    { test: (action) => (action === "password-change") || (action === "report"), run: handleSubmitActions04 },
    { test: (action) => (action === "editor") || (action === "comment"), run: handleSubmitActions05 },
    { test: (action) => (action === "comment-reply") || (action === "message"), run: handleSubmitActions06 },
    { test: (action) => (action === "conversation-request-message") || (action === "group-chat"), run: handleSubmitActions07 },
    { test: (action) => (action === "group-edit") || (action === "intro"), run: handleSubmitActions08 },
    { test: (action) => (action === "ask") || (action === "contact"), run: handleSubmitActions09 },
    { test: (action) => (action === "moderate") || (action === "review-decision") || (action === "admin-user-create"), run: handleSubmitActions10 },
    { test: (action) => ["contact-assign","contact-update"].includes(action), run: handleSupportSubmit },
    { test: (action) => (action === "board-approval") || (action === "admin-user-edit") || (action === "admin-filter"), run: handleSubmitActions11 },
    { test: (action) => (action === "settings") || (action === "demo"), run: handleSubmitActions12 },
  ];
root.addEventListener("submit", async (event) => {
    const form = event.target.closest("[data-form]");
    if (!form) return;
    event.preventDefault();
    for (const input of form.querySelectorAll('[data-social-link]')) {
      validateSocialLinkInput(input);
      if (!input.checkValidity()) { input.reportValidity();return; }
    }
    const action = form.dataset.form,
      data = formData(form),
      submit = form.querySelector("button[type=submit],button:not([type])"),
      saveAndLeave = S.pendingUnsavedSaveForm === form && !!S.pendingUnsavedResolver;
    if (action==='editorial-filter') {
      S.editorialType=data.type;S.editorialFilters={q:data.q,status:data.status,page:1};
      await adminEditorialRegistry(document.getElementById('admin-panel'));return;
    }
    const invalidText = [...form.querySelectorAll('textarea[required],input[required]')].find(field =>
      (field.tagName === "TEXTAREA" || ["text", "search", "email", "url", "tel", "password"].includes(field.type))
      && !/[^\s\p{Z}\p{Cf}\p{Cc}]/u.test(field.value));
    if (invalidText) {
      invalidText.setCustomValidity(T("Completa este campo con texto visible."));
      requiredTextErrors.add(invalidText);
      invalidText.reportValidity();
      invalidText.focus();
      if (saveAndLeave) { S.pendingUnsavedSaveForm = null; resolveUnsavedExit(false); }
      return;
    }
    if (submit) submit.disabled = true;
    try {
      const actionHandler = HANDLESUBMITACTIONS_DISPATCH.find((entry) => entry.test(action));
      if (actionHandler) await actionHandler.run(action, form, data, submit, event);
      if (saveAndLeave && S.pendingUnsavedResolver) resolveUnsavedExit(true);
    } catch (e) {
      if (saveAndLeave) {
        S.pendingUnsavedSaveForm = null;
        resolveUnsavedExit(false);
      }
      if (e.name !== "AbortError") toast(e.message);
    } finally {
      if (submit) submit.disabled = false;
    }
  });
  root.addEventListener("input", (event) => {
    if (event.target.matches?.('[data-social-link]')) validateSocialLinkInput(event.target);
    if (requiredTextErrors.has(event.target)) {
      event.target.setCustomValidity("");
      requiredTextErrors.delete(event.target);
    }
    if (event.target.id === "connection-name-filter") {
      S.connectionFilter = event.target.value;
      filterConfirmedConnections();
    }
    const facet = event.target.closest?.('.directory-facet');
    if (facet) facet.querySelector('[data-facet-count]').textContent = facet.querySelectorAll('input:checked').length;
    const reportForm = event.target.closest?.('form[data-form="report"]');
    if (reportForm && event.target.name === "detail") updateReportDetailRequirement(reportForm);
    refreshUnsavedGuard(event.target);
  });
  async function uploadPrivateMedia(file, originalId = 0) {
    const fd = new FormData();
    fd.append("file", file, file.name || "archivo");
    if (originalId) fd.append("original_id", String(originalId));
    return api("media", fd);
  }
  function imageUploadContext(input) {
    if (input.dataset.upload === "photo") return "profile";
    if (input.dataset.upload === "group-photo" || input.dataset.upload === "group-photo-edit") return "group";
    if (input.dataset.upload === "event-cover") return "event";
    const type = input.closest('form[data-form="editor"]')?.dataset.type || "hub";
    return ["hub", "gallery", "resource", "ally", "event"].includes(type) ? type : "hub";
  }
  function imageEditorTitle(context) {
    const titles = {
      profile: "Ajustar fotografía",
      group: "Ajustar foto del grupo",
      event: "Ajustar portada del evento",
    };
    return titles[context] || "Ajustar imagen";
  }
  async function prepareImageUpload(input, file) {
    if (!globalThis.ASCLAImageEditor?.edit) throw new Error(T("El editor de imágenes no está disponible. Recarga la página e inténtalo de nuevo."));
    const context = imageUploadContext(input);
    return globalThis.ASCLAImageEditor.edit(file, {
      context,
      title: T(imageEditorTitle(context)),
      hint: T("Arrastra la imagen para moverla y usa el zoom para elegir el encuadre antes de guardarla."),
      ratioLabel: T("Proporción recomendada"),
      fullLabel: T("Usar imagen completa"),
      cropLabel: T("Recortar"),
      resetLabel: T("Restablecer"),
      cancelLabel: T("Cancelar"),
      applyLabel: T("Usar imagen"),
      zoomLabel: T("Zoom"),
      sourceLabel: T("Imagen fuente"),
      privacyLabel: T("ASCLA conserva una copia maestra sin recortar y optimizada, además de la versión preparada para mostrarse. El archivo de tu dispositivo no se modifica."),
      processingLabel: T("Optimizando imagen…"),
      messages: {
        invalid: T("Selecciona una imagen JPG, PNG o WebP."),
        tooLarge: T("La imagen original supera 15 MB. Reduce su tamaño antes de continuar."),
        readError: T("No se pudo leer la imagen seleccionada."),
        tooManyPixels: T("La imagen supera el límite de 28 megapíxeles."),
      },
    });
  }
  function preparedImageMarkup(media) {
    return `<span class="attached-file image-ready" data-media="${media.id}" data-pending-media="${media.id}"><img src="${E(media.url)}" alt=""><span>${E(media.name)}</span>${btn("Quitar", "detach-media", ("data-id=\"" + (media.id) + "\""), "ghost small")}</span>`;
  }
  function humanFileSize(bytes) {
    const value = Number(bytes) || 0;
    if (value < 1024) return value + " B";
    if (value < 1024 * 1024) return Math.round(value / 1024) + " KB";
    return (value / 1024 / 1024).toFixed(1) + " MB";
  }
  async function applyPhotoUpload(_input, media, prepared) {
    const photoId = document.querySelector("[name=photo_id]");
    replacePendingMedia(photoId, media.id);
    photoId.value = media.id;
    refreshUnsavedGuard(photoId);
    const avatarBox = document.querySelector('[data-form="profile"] .profile-summary .avatar');
    if (avatarBox && media.mime?.startsWith("image/")) {
      avatarBox.querySelector("img")?.remove();
      avatarBox.insertAdjacentHTML("afterbegin", `<img src="${E(media.url)}" alt="${E(S.boot.me.name || "")}">`);
    }
    const statusBox = document.getElementById("photo-status");
    if (statusBox) statusBox.textContent = prepared
      ? `${T("Fotografía preparada")}: ${humanFileSize(prepared.inputBytes)} → ${humanFileSize(prepared.storedBytes)}. ${T("Guarda el perfil para aplicar.")}`
      : T("Fotografía cargada. Guarda el perfil para aplicar.");
  }
  async function applyEventCoverUpload(_input, media) {
    const preview = document.getElementById('event-cover-preview');
    if (!preview) { await discardTemporaryMedia(media.id); return; }
    discardPendingWithin(preview);
    preview.innerHTML = `<span class="event-cover-ready" data-media="${Number(media.id)}" data-pending-media="${Number(media.id)}"><img src="${E(media.url)}" alt="${E(T('Portada del evento'))}"><span class="event-cover-actions"><strong>${E(media.name || T('Imagen del evento'))}</strong>${btn('Quitar portada','event-cover-clear','','ghost small')}</span></span>`;
  }
  async function applyGroupPhotoUpload(_input, media) {
    if (!S.groupChat) { await discardTemporaryMedia(media.id); return; }
    S.groupChat.photo = media;
    const hidden = document.querySelector('[data-form="group-chat"] [name="photo_id"]');
    if (hidden) { replacePendingMedia(hidden, media.id); hidden.value = String(media.id); }
    const preview = document.getElementById('group-photo-preview');
    if (preview) preview.innerHTML = `<img src="${E(media.url)}" alt="${E(T('Foto del grupo'))}">${btn('Quitar','group-photo-clear','','ghost small')}`;
  }
  async function applyGroupEditPhotoUpload(_input, media) {
    if (!S.groupEdit) { await discardTemporaryMedia(media.id); return; }
    S.groupEdit.photo_id = Number(media.id);
    S.groupEdit.photo_url = media.url || '';
    const hidden = document.querySelector('[data-form="group-edit"] [name="photo_id"]');
    if (hidden) { replacePendingMedia(hidden, media.id); hidden.value = String(media.id); }
    const preview = document.getElementById('group-edit-photo-preview');
    if (preview) preview.innerHTML = `<img src="${E(media.url)}" alt="${E(T('Foto del grupo'))}">${btn('Quitar foto','group-edit-photo-clear','','ghost small')}`;
  }
  async function applyAttachmentUpload(_input, media) {
    const attachments = document.getElementById("attachments");
    if (!attachments) { await discardTemporaryMedia(media.id); return; }
    const markup = media.mime?.startsWith("image/")
      ? preparedImageMarkup(media)
      : `<span class="attached-file" data-media="${media.id}" data-pending-media="${media.id}">${E(media.name)}${btn("Quitar", "detach-media", ("data-id=\"" + (media.id) + "\""), "ghost small")}</span>`;
    attachments.insertAdjacentHTML("beforeend", markup);
  }
  const UPLOAD_RESULT_HANDLERS = [
    { test: (input) => input.dataset.upload === "photo", run: applyPhotoUpload },
    { test: (input) => input.dataset.upload === "event-cover", run: applyEventCoverUpload },
    { test: (input) => input.dataset.upload === "group-photo", run: applyGroupPhotoUpload },
    { test: (input) => input.dataset.upload === "group-photo-edit", run: applyGroupEditPhotoUpload },
    { test: () => true, run: applyAttachmentUpload },
  ];
  async function handleUploadInput(input) {
    let sourceId = 0;
    try {
      const file = input.files[0];
      let media;
      let prepared = null;
      if (file.type.startsWith("image/")) {
        prepared = await prepareImageUpload(input, file);
        if (!prepared) return;
        toast(T("Guardando imagen optimizada…"));
        if (prepared.masterFile) {
          const source = await uploadPrivateMedia(prepared.masterFile);
          sourceId = Number(source.id) || 0;
        }
        media = await uploadPrivateMedia(prepared.outputFile, sourceId);
        sourceId = 0;
      } else {
        toast(T("Subiendo archivo privado…"));
        media = await uploadPrivateMedia(file);
      }
      if (!input.isConnected) { await discardTemporaryMedia(media.id); return; }
      const handler = UPLOAD_RESULT_HANDLERS.find((entry) => entry.test(input));
      await handler.run(input, media, prepared);
      toast(prepared ? T("Imagen preparada y cargada.") : T("Archivo cargado."));
    } catch (error) {
      if (sourceId) await discardTemporaryMedia(sourceId);
      if (error.name !== "AbortError") toast(error.message);
    } finally {
      input.value = "";
    }
  }
  root.addEventListener("change", async (event) => {
    const input = event.target;
    if (input.matches?.("[data-admin-language-select]")) { input.form?.requestSubmit(); return; }
    if (input.matches?.('form[data-form="settings"] [name="ai_provider"]')) syncAIProvider(input.form);
    const reportForm = input.closest?.('form[data-form="report"]');
    if (reportForm && input.name === "reason") updateReportDetailRequirement(reportForm);
    updateProfilePreference(input);
    publicationFieldState(input);
    syncEditorRequirements(input.closest?.('[data-form="editor"]'));
    refreshUnsavedGuard(input);
    if (!input.dataset.upload || !input.files?.length) return;
    await handleUploadInput(input);
  });
  applyTheme(themeMode());
  accessibility = globalThis.ASCLAAccessibility(root, T);
  themeMedia?.addEventListener?.("change", () => { if (themeMode() === "system") applyTheme("system"); });
  globalThis.addEventListener("storage", (event) => { if (event.key === THEME_KEY) applyTheme(themeMode()); });
  globalThis.addEventListener("beforeunload", (event) => {
    if (!hasUnsavedChanges() && ![...document.querySelectorAll("form[data-guard-modal-unsaved]")].some(modalHasUnsavedChanges)) return;
    event.preventDefault();
  });
  globalThis.addEventListener("pagehide", (event) => {
    if (!event.persisted) discardPendingWithin(root, true);
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      if (document.querySelector(".modal-decision-backdrop")) closeModalDecision();
      else if (document.querySelector(".modal-backdrop")) requestModalClose();
    }
  });
  root.addEventListener("error", (event) => {
    const image=event.target;
    if (!(image instanceof HTMLImageElement) || !image.classList.contains("resource-thumbnail")) return;
    image.hidden=true;
    image.setAttribute("aria-hidden","true");
  }, true);
  async function start() {
    try {
      S.boot = C.bootstrap?.me?.id ? C.bootstrap : await api("bootstrap");
      S.initialProfile = C.bootstrap && C.page === "perfil" ? S.boot.me : null;
      delete C.bootstrap;
      const q = new URLSearchParams(location.search);
      S.editorialType=['gallery','resource'].includes(q.get('type'))?q.get('type'):'gallery';
      if (q.has("q")) S.filter.q = q.get("q");
      if (S.page === "admin") {
        const menu = q.get("page") || "",
          maps = {
            "ascla-hub": "hub",
            "ascla-eventos": "eventos",
            "ascla-conocimiento": "centro-conocimiento",
            "ascla-galeria": "galeria",
            "ascla-aliados": "aliados",
            "ascla-networking": "directorio",
          };
        S.page = maps[menu] || "admin";
        S.adminTab = q.get("section") ||
          {
            "ascla-miembros": "usuarios",
            "ascla-solicitudes": "solicitudes",
            "ascla-estadisticas": "estadisticas",
            "ascla-importar-intereses": "importar",
            "ascla-ia": "trabajos",
            "ascla-microeventos": "microeventos",
            "ascla-logs": "logs",
            "ascla-integraciones": "configuracion",
            "ascla-configuracion": "configuracion",
          }[menu] || (S.boot.admin?'resumen':'moderacion');
      }
      shell();
      S.liveToastCursor = Number(S.boot.notification_cursor || 0);
      liveToasts = globalThis.ASCLALiveToasts?.({
        root, E, I, T, timeout: 7000, max: 3,
        shouldSuppress: liveToastRedundant,
        onOpen: openLiveNotification,
      }) || null;
      enableNavigation();
      const initialVersion = S.viewVersion;
      await render();
      void pollLiveNotifications();
      S.liveToastTimer = setInterval(pollLiveNotifications, 7000);
      document.addEventListener("visibilitychange", () => {
        if (document.hidden) clearTimeout(S.poll);
        else { refreshNotifications(); pollLiveNotifications(); syncChat(); }
      });
      setInterval(refreshOpenConnection, 8000);
      globalThis.addEventListener('focus', () => { syncChat(); refreshOpenConnection(); });
      globalThis.addEventListener('online', () => { syncChat(); });
      if (initialVersion === S.viewVersion) {
        await routeDetails();
        if (initialVersion === S.viewVersion && !maybeBirthdayGreeting()) maybeProfileCompletionNudge();
      }
    } catch (e) {
      (content() || root).innerHTML = `<div class="error">${E(e.message)} <a data-native href="${E(location.href)}">Recarga la página para renovar tu sesión.</a></div>`;
    }
  }
  start();
})();
