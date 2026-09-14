/* ASCLA frontend. WordPress owns authentication and authorization; this UI never grants permissions. */
(() => {
  "use strict";
  const root = document.getElementById("ascla-root");
  if (!root || !window.ASCLA) return;
  const C = window.ASCLA;
  const T = text => C.translations?.[text] || text;
  const THEME_KEY = "ascla-theme";
  const themeMedia = window.matchMedia?.("(prefers-color-scheme: dark)");
  const themeMode = () => {
    try {
      const mode = localStorage.getItem(THEME_KEY);
      return ["light", "dark", "system"].includes(mode) ? mode : "system";
    } catch { return "system"; }
  };
  const effectiveTheme = (mode = themeMode()) => mode === "system" ? (themeMedia?.matches ? "dark" : "light") : mode;
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
  const labelHTML = label => String(label).replace(/(^|>)([^<>]+)(?=<|$)/g, (all, prefix, text) => {
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
    calendar: new Date(),
    adminTab: "moderacion",
    adminFilters: {users: {}, contacts: {}},
    pendingUnsavedResolver: null,
    poll: null,
    noticeFilter: "all",
    noticePage: 1,
    assistantThread: "",
    locations: null,
    locationTimer: null,
  };
  const ASSISTANT_THREAD_PREFIX = "ascla-assistant-thread-";
  function assistantThread(reset = false, forced = "") {
    const key = ASSISTANT_THREAD_PREFIX + String(S.boot?.me?.id || "member");
    let value = forced;
    if (!value && !reset) { try { value = localStorage.getItem(key) || ""; } catch {} }
    if (!/^[a-zA-Z0-9_-]{6,80}$/.test(value)) {
      const random = globalThis.crypto?.randomUUID?.() || (Date.now().toString(36) + Math.random().toString(36).slice(2));
      value = "chat_" + random.replace(/[^a-zA-Z0-9_-]/g, "");
    }
    S.assistantThread = value;
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
    return `<form class="header-language admin-language-control" method="post" action="" data-native aria-label="${E(T("Cambiar idioma"))}"><span class="admin-language-icon">${I("globe")}</span><label class="screen-reader-text" for="ascla-admin-language">${E(T("Idioma"))}</label><select id="ascla-admin-language" name="_ascla_locale" data-admin-language-select aria-label="${E(T("Idioma"))}">${options.map(([value,label]) => `<option value="${E(value)}" ${value===selected?"selected":""}>${E(label)}</option>`).join("")}</select><input type="hidden" name="_ascla_change_language" value="1"><input type="hidden" name="_ascla_language_nonce" value="${E(C.languageNonce || "")}"></form>`;
  }
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
        ).toLocaleDateString(C.locale || "es-PE", opts)
      : T("Por confirmar");
  const time = (value) =>
    value
      ? new Date(value).toLocaleTimeString(C.locale || "es-PE", {
          hour: "2-digit",
          minute: "2-digit",
        })
      : "";
  const status = (value) =>
    `<span class="status ${E(value)}">${E(T({ publish: "Publicado", draft: "Borrador", pending: "Pendiente de revisión", private: "Solicitud privada", ascla_rejected: "Rechazado", ascla_hidden: "Oculto", completed: "Completado", processing: "Procesando", error: "Error", accepted: "Inscrito", invited: "Invitado", cancelled: "Cancelado", declined: "Rechazado" }[value] || value))}</span>`;
  const initials = (name) =>
    String(name || "AS")
      .split(" ")
      .slice(0, 2)
      .map((x) => x[0])
      .join("");
  const avatar = (p, size = "") => {
    let url = '';
    try { const candidate = new URL(p.photo_url); if (['http:', 'https:'].includes(candidate.protocol)) url = candidate.href; } catch { /* Initials are the shared fallback. */ }
    return `<span class="avatar ${size}">${url ? `<img src="${E(url)}" alt="${E(p.name)}">` : ''}<span class="avatar-initials">${E(initials(p.name))}</span></span>`;
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
  const UI = window.ASCLAContent({ escape: E, icon: I, config: C, T });
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
    t.className = "toast";
    t.role = "status";
    t.textContent = T(message);
    root.append(t);
    setTimeout(() => t.remove(), 5500);
  }
  function modal(title, body, wide = false) {
    closeModal();
    const div = document.createElement("div");
    div.className = "modal-backdrop";
    div.innerHTML = `<section role="dialog" aria-modal="true" aria-label="${E(title)}" class="modal ${wide ? "wide" : ""}"><div class="modal-top"><h2>${E(title)}</h2>${btn(I("close"), "close", `aria-label="${E(T("Cerrar"))}"`, "ghost")}</div><div class="modal-content">${body}</div></section>`;
    root.append(div);
    S.focus = document.activeElement;
    div.querySelector("button,input")?.focus();
  }
  function closeModal() {
    const pending = S.pendingUnsavedResolver;
    S.pendingUnsavedResolver = null;
    document.querySelector(".modal-backdrop")?.remove();
    S.focus?.focus();
    if (pending) pending(false);
  }
  function field(name, label, value = "", type = "text", extra = "") {
    return `<div class="field"><label for="f-${E(name)}">${E(T(label))}</label>${type === "textarea" ? `<textarea id="f-${E(name)}" name="${E(name)}" ${extra}>${E(value)}</textarea>` : `<input id="f-${E(name)}" name="${E(name)}" type="${type}" value="${E(value)}" ${extra}>`}</div>`;
  }
  const check = (name, label, value) =>
    `<label class="check"><input type="checkbox" name="${E(name)}" ${value ? "checked" : ""}> <span>${E(T(label))}</span></label>`;
  const select = (name, label, values, value = "") =>
    `<div class="field"><label for="f-${E(name)}">${E(T(label))}</label><select name="${E(name)}" id="f-${E(name)}">${values
      .map((v) => {
        const pair = Array.isArray(v) ? v : [v, v];
        return `<option value="${E(pair[0])}" ${String(value) === String(pair[0]) ? "selected" : ""}>${E(T(pair[1]))}</option>`;
      })
      .join("")}</select></div>`;
  function formData(form) {
    return Object.fromEntries(new FormData(form));
  }
  const locationNormalize = value => String(value || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/[^\p{L}\p{N}]+/gu, " ").trim();
  function profileLocationFields(p) {
    const countryPlaceholder = E(T("Busca un país o región"));
    const cityPlaceholder = E(T("Busca una ciudad"));
    return `<div class="field location-combobox" data-location-box="country"><label for="f-country">${E(T("País/región"))}</label><input type="hidden" name="country" value="${E(p.country || "")}" data-location-country-value><div class="location-input-wrap"><input id="f-country" type="text" value="${E(p.country || "")}" maxlength="120" autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" data-form-type="other" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="ascla-country-options" placeholder="${countryPlaceholder}" data-location-country>${I("chevron")}</div><div id="ascla-country-options" class="location-suggestions" role="listbox" hidden></div></div><div class="field location-combobox" data-location-box="city"><label for="f-city">${E(T("Ciudad"))}</label><input type="hidden" name="city" value="${E(p.city || "")}" data-location-city-value><div class="location-input-wrap"><input id="f-city" type="text" value="${E(p.city || "")}" maxlength="120" autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" data-form-type="other" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="ascla-city-options" placeholder="${cityPlaceholder}" data-location-city>${I("chevron")}</div><div id="ascla-city-options" class="location-suggestions" role="listbox" hidden></div></div><p class="private-note full location-helper">${E(T("Escribe para ver sugerencias y selecciona una opción de la lista."))}</p>`;
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
      return `<button type="button" class="location-option" role="option" id="ascla-country-option-${i}" data-country-code="${E(country.code)}" data-country-value="${E(primary)}"><span>${E(primary)}</span>${secondary && secondary !== primary ? `<small>${E(secondary)}</small>` : ''}</button>`;
    }, "No encontramos países con ese nombre.");
  }
  async function loadCitySuggestions(form, exact = false) {
    const countryInput = form?.querySelector('[data-location-country]');
    const cityInput = form?.querySelector('[data-location-city]');
    const box = cityInput?.closest('.location-combobox');
    if (!countryInput || !cityInput || !box) return null;
    const country = locationCountryMatch(countryInput.value);
    const query = cityInput.value.trim();
    if (!country || (!exact && query.length < 2) || (exact && !query)) {
      if (!exact) locationRender(box, [], () => '', query && !country ? "Selecciona primero un país." : "");
      return null;
    }
    if (!exact) {
      locationRender(box, [], () => '', "Cargando ciudades…");
      box.classList.add('is-loading');
    }
    try {
      const result = await api(`locations/cities?country=${encodeURIComponent(country.code)}&q=${encodeURIComponent(query)}${exact ? "&exact=1" : ""}`);
      if (!exact) {
        box.classList.remove('is-loading');
        const items = result.items || [];
        locationRender(box, items, (city, i) => `<button type="button" class="location-option" role="option" id="ascla-city-option-${i}" data-city-value="${E(city)}"><span>${E(city)}</span><small>${E(C.language === 'en' ? country.en : country.es)}</small></button>`, result.available ? "No encontramos ciudades con ese nombre." : "No se pudieron cargar las ciudades en este momento.");
      }
      return result;
    } catch (error) {
      box.classList.remove('is-loading');
      if (error.name === "AbortError") throw error;
      if (!exact) locationRender(box, [], () => '', "No se pudieron cargar las ciudades en este momento.");
      return {available:false, items:[]};
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
      if (clearCity && previous && previous !== next) {
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
      clearTimeout(S.locationTimer);
      S.locationTimer = setTimeout(() => loadCitySuggestions(form).catch(() => {}), 220);
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
    countryValue.value = country ? country.es : '';
    if (!countryInput.value.trim()) cityValue.value = '';
    if (cityInput.value.trim() && country && (cityChanged || countryChanged)) {
      const result = await loadCitySuggestions(form, true);
      if (result?.available && !result.exact) {
        cityInput.focus();
        throw new Error(T("Selecciona una ciudad real de las sugerencias para el país elegido."));
      }
      cityValue.value = result?.available === false ? cityInput.value.trim() : (result?.exact ? cityInput.value.trim() : '');
    } else if (!cityInput.value.trim()) {
      cityValue.value = '';
    }
  }
  function maybeProfileCompletionNudge() {
    const completion = S.boot?.profile_completion;
    if (!completion || Number(completion.percent) >= Number(completion.minimum || 40) || S.page !== "intranet") return;
    const key = `ascla-profile-completion-${S.boot.me.id}`;
    try { if (sessionStorage.getItem(key)) return; sessionStorage.setItem(key, "1"); } catch {}
    modal(T("Completa tu perfil"), `<div class="profile-completion-nudge"><div class="completion-ring" style="--completion:${Math.max(0, Math.min(100, Number(completion.percent) || 0))}"><strong>${Number(completion.percent) || 0}%</strong></div><div><h3>${E(T("Tu perfil todavía tiene información pendiente"))}</h3><p>${E(T("Completar al menos el 40% ayuda a ASCLA a ofrecerte mejores recomendaciones, conexiones y experiencias dentro de la comunidad."))}</p></div></div><div class="form-actions">${btn(T("Ahora no"), "profile-nudge-later")}${btn(T("Completar mi perfil"), "profile-nudge-go", "", "primary")}</div>`);
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
    return JSON.stringify(values);
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
      modal(T("Cambios sin guardar"), `<p class="detail-body">${E(T("Has realizado cambios que todavía no se han guardado."))}</p><p class="private-note">${E(T("Si sales de esta pantalla ahora, esos cambios se perderán."))}</p><div class="form-actions">${btn(T("Seguir editando"), "unsaved-stay")}${btn(T("Descartar cambios"), "unsaved-discard", "", "danger primary")}</div>`);
      S.pendingUnsavedResolver = resolve;
    });
  }
  function resolveUnsavedExit(leave) {
    const resolve = S.pendingUnsavedResolver;
    S.pendingUnsavedResolver = null;
    document.querySelector(".modal-backdrop")?.remove();
    S.focus?.focus();
    if (leave) clearUnsavedGuard(null);
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
    if (!form || form.dataset.form !== "report") return;
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
      `<form data-form="report" data-kind="${isComment ? 'comment' : 'content'}" data-id="${Number(id)}" class="report-form"><h3>${E(T("Selecciona el motivo de la denuncia"))}</h3><p class="private-note">${E(T("Tu reporte será revisado por el equipo de moderación de ASCLA."))}</p><div class="report-reasons">${reportReasons.map(([value, label]) => `<label class="report-reason"><input type="radio" name="reason" value="${E(value)}" required><span>${E(T(label))}</span></label>`).join("")}</div><div class="field report-detail-field"><label for="f-detail" data-report-detail-label>${E(T("Información adicional (opcional)"))}</label><textarea id="f-detail" name="detail" maxlength="1000" placeholder="${placeholder}"></textarea></div><div class="form-actions"><button type="button" class="btn" data-action="close">${E(T("Cancelar"))}</button><button class="btn primary">${E(T("Enviar reporte"))}</button></div></form>`,
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
    const links = navOrder.map((k) => `<a class="nav-link ${S.page === k ? "active" : ""}" href="${E(C.pages[k].url)}">${I(pageIcon[k])}<span>${E(C.pages[k].label)}</span></a>`).join("");
    const adminLink = (S.boot.moderator || S.boot.executive) ? `<a class="nav-link" href="${E(C.adminUrl)}">${I("settings")}${E(T("Administración"))}</a>` : "";
    root.innerHTML = `<aside class="ascla-sidebar"><a class="brand" href="${E(C.pages.intranet.url)}" aria-label="${E(T("ASCLA inicio"))}"><img src="${E(C.logoWhite || C.logo)}" alt="ASCLA"></a><div class="brand-sub">${E(T("COMUNIDAD DE ASOCIADOS"))}</div><nav aria-label="${E(T("Navegación principal"))}">${links}</nav><div class="nav-bottom">${adminLink}<a class="nav-link" href="${E(C.logout)}">${I("logout")}${E(T("Cerrar sesión"))}</a></div></aside><div class="ascla-main"><header class="ascla-header"><a class="header-brand" href="${E(C.pages.intranet.url)}" aria-label="${E(T("ASCLA inicio"))}"><img class="brand-logo-normal" src="${E(C.logo)}" alt="ASCLA"><img class="brand-logo-inverse" src="${E(C.logoWhite || C.logo)}" alt="" aria-hidden="true"></a>${btn(I("menu"), "menu", `aria-label="${E(T("Abrir navegación"))}"`, "icon-button mobile-menu")}<form class="header-search" data-form="global-search">${I("search")}<input name="q" aria-label="${E(T("Buscar en ASCLA"))}" placeholder="${E(T("Buscar en tu comunidad…"))}" autocomplete="off"></form><div class="header-right">${S.boot.demo ? '<span class="demo-badge">DEMO MODE</span>' : ""}${C.page === "admin" ? adminLanguageControl() + btn(themeIcons(), "theme-menu", `aria-label="${E(T("Apariencia"))}"`, "icon-button theme-button") : ""}${btn(I("bell") + '<span class="notification-count" hidden></span>', "notifications", `aria-label="${E(T("Notificaciones"))}"`, "icon-button")}<a class="header-profile" aria-label="${E(T("Mi perfil"))}" href="${E(C.pages.perfil.url)}">${avatar(p)}<span><strong>${E(p.name)}</strong><small class="muted">${E(p.member_type || T("Comunidad ASCLA"))}</small></span>${I("chevron")}</a></div></header><main id="main" class="page-wrap"><div class="breadcrumb">ASCLA ${I("chevron")} ${E(C.pages[S.page]?.label || T("Administración"))}</div><div id="page-content"></div><div class="demo-footer">© ${new Date().getFullYear()} ASCLA · ${E(T("Conectamos conocimiento, fortalecemos la gobernanza."))}${S.boot.demo ? " · " + E(T("Datos ficticios de demostración.")) : ""}</div></main></div>`;
    refreshNotifications();
  }
  function heading(title, subtitle, action = "") {
    return `<div class="page-heading"><div><h1>${E(T(title))}</h1><p>${E(T(subtitle))}</p></div>${action}</div>`;
  }
  const content = () => document.getElementById("page-content");
  function connectionActions(p, suggested = false, compactConnected = false) {
    if (Number(p.id) === S.boot.me.id || !p.connection) return '';
    const c = p.connection, id = Number(p.id);
    let actions = '';
    if (c.state === 'incoming_pending') {
      actions = btn('Aceptar conexión', 'connection-respond', `data-id="${id}" data-request="${c.request_id}" data-decision="accept" ${c.blocked ? 'disabled' : ''}`, 'primary small') + btn('Rechazar solicitud', 'connection-respond', `data-id="${id}" data-request="${c.request_id}" data-decision="reject"`, 'small');
    } else if (c.state === 'outgoing_pending') {
      actions = `<span class="connection-state pending">${E(T('Solicitud enviada · Pendiente'))}</span>` + btn(T('Cancelar solicitud'), 'connection-remove-request', `data-id="${id}" data-mode="cancel"`, 'ghost danger small');
    } else if (c.state === 'connected') {
      actions = compactConnected ? '' : '<span class="connection-state connected">' + I('check') + ' ' + E(T('Conectados')) + '</span>';
      if (c.can_message) actions += btn(I('mail') + ' ' + T('Enviar mensaje'), 'message-start', `data-id="${id}"`, 'primary small') + (suggested ? btn(T('Mensaje sugerido'), 'intro', `data-id="${id}"`, 'small') : '');
      actions += btn(T('Eliminar conexión'), 'connection-remove-request', `data-id="${id}" data-mode="disconnect"`, 'ghost danger small');
    } else actions = btn('Enviar solicitud de conexión', 'connect', `data-id="${id}" ${c.can_request ? '' : 'disabled'}`, 'small');
    const note = c.blocked ? T('La mensajería está bloqueada entre estas cuentas.') : c.state !== 'connected' ? (c.state === 'none' && !c.can_request ? T('Ambos asociados deben tener activado networking para conectar.') : T('La mensajería se habilita al aceptar la conexión.')) : '';
    if (c.blocked_by_me) actions += btn('Desbloquear', 'block', `data-id="${id}" data-active="false"`, 'small');
    return `<div class="connection-controls" data-member-connection="${id}" data-suggested="${suggested}" data-compact-connected="${compactConnected}" data-state="${E(c.state)}"><div class="connection-actions">${actions}</div>${note ? '<p class="private-note">' + E(note) + '</p>' : ''}</div>`;
  }
  function memberRelationshipState(connection) {
    return connection?.state === 'connected' ? `<span class="connection-state connected compact">${I('check')} ${E(T('Conectados'))}</span>` : '';
  }
  function updateConnectionState(id, state) {
    for (const element of [...root.querySelectorAll(`[data-member-connection="${Number(id)}"]`)]) {
      const card = element.closest('.member-card');
      const suggested = element.dataset.suggested === 'true';
      const compactConnected = element.dataset.compactConnected === 'true';
      element.outerHTML = connectionActions({id, connection: state}, suggested, compactConnected);
      if (card) { const holder=card.querySelector('.member-card-relationship-state'); if (holder) holder.innerHTML=memberRelationshipState(state); }
    }
  }
  function connectionRow(p) {
    return `<article class="connection-row"><div class="connection-person">${avatar(p)}<div><strong>${E(p.name)}</strong>${p.profile_url ? `<a href="${E(p.profile_url)}">${E(T('Ver perfil'))}</a>` : `<small>${E(T('Perfil no disponible'))}</small>`}</div></div>${connectionActions(p)}</article>`;
  }
  async function refreshConnectionsPanel() {
    const panel = document.getElementById('connections-panel'); if (!panel) return;
    const data = await api('connections'); if (!panel.isConnected) return;
    panel.innerHTML = `<div class="section-top"><h2>${E(T('Mis conexiones'))}</h2>${btn('Actualizar conexiones','connections-refresh','','ghost small')}</div><h3>${E(T('Solicitudes recibidas'))} (${data.incoming.length})</h3>${data.incoming.map(connectionRow).join('') || `<p class="private-note">${E(T('No tienes solicitudes pendientes.'))}</p>`}<details><summary>${E(T('Solicitudes enviadas'))} (${data.outgoing.length})</summary>${data.outgoing.map(connectionRow).join('') || `<p class="private-note">${E(T('No hay solicitudes enviadas pendientes.'))}</p>`}</details><details><summary>${E(T('Conexiones confirmadas'))} (${data.connected.length})</summary>${data.connected.map(connectionRow).join('') || `<p class="private-note">${E(T('Tus conexiones aparecerán aquí cuando acepten la solicitud.'))}</p>`}</details>`;
  }
  let relationshipRefreshing = false;
  async function refreshOpenConnection() {
    const element = root.querySelector('.modal [data-member-connection]');
    if (!element || relationshipRefreshing || document.hidden) return;
    relationshipRefreshing = true;
    try {
      const p = await api('profiles/' + element.dataset.memberConnection);
      if (element.isConnected) updateConnectionState(p.id, p.connection);
    } catch { /* The action endpoints still validate the latest state and permissions. */ }
    finally { relationshipRefreshing = false; }
  }
  function confirmConnectionRemoval(id, mode) {
    const cancelling = mode === 'cancel';
    const title = cancelling ? T('Cancelar solicitud de conexión') : T('Eliminar conexión');
    const description = cancelling
      ? T('La solicitud se cancelará y la otra persona dejará de ver la notificación asociada.')
      : T('Dejarán de aparecer como conectados y la mensajería quedará deshabilitada. El historial de mensajes no se elimina.');
    const keep = cancelling ? T('Mantener solicitud') : T('Mantener conexión');
    const remove = cancelling ? T('Cancelar solicitud') : T('Eliminar conexión');
    modal(title, `<p class="detail-body">${E(description)}</p><div class="form-actions">${btn(keep,'close')}${btn(remove,'connection-remove-confirm',`data-id="${Number(id)}" data-mode="${E(mode)}"`,'danger primary')}</div>`);
  }
  function memberCard(p) {
    const isMe = Number(p.id) === Number(S.boot.me.id);
    const signal = p.affinity
      ? `<div class="match-pill">${I("spark")}${p.affinity.score}% ${E(T("de afinidad"))}</div>`
      : `<div class="tag-row">${(p.terms?.interests || [])
          .slice(0, 2)
          .map((t) => `<span class="tag">${E(t)}</span>`)
          .join("")}</div>`;
    const relationship = isMe
      ? `<div class="connection-controls member-own-actions"><div class="connection-actions">${link("perfil", "Editar mi perfil", "small")}</div></div>`
      : connectionActions(p, false, true);
    const relationshipState = isMe ? '' : memberRelationshipState(p.connection);
    return `<article class="card member-card"><div class="member-card-profile">${avatar(p, "lg")}<h3>${E(p.name)}</h3><div class="role">${E(p.position || T("Miembro ASCLA"))}</div><div class="company">${E(p.company || T("Comunidad profesional"))}</div><span class="country">${I("pin")}${E(p.country || T("América Latina"))}</span><div class="member-card-signal">${signal}<span class="member-card-relationship-state">${relationshipState}</span></div></div><div class="member-card-actions">${btn("Ver perfil " + I("arrow"), "member", `data-id="${p.id}"`, "small")}${relationship || '<div class="connection-controls member-action-placeholder" aria-hidden="true"></div>'}</div></article>`;
  }
  function resourceCard(p, i = 0) {
    const cover = p.meta.thumbnail_url
      ? `<a href="${E(p.url)}" class="resource-video-cover"><img class="resource-thumbnail" src="${E(p.meta.thumbnail_url)}" alt="${E(T("Miniatura de"))} ${E(p.title)}" loading="lazy"><span>${I("play")} ${p.meta.duration_seconds ? E(UI.duration(p.meta.duration_seconds)) : E(T("Duración por confirmar"))}</span></a>`
      : `<a href="${E(p.url)}" class="resource-cover v${i % 3}"><div class="cover-label">${E(T("ASCLA · CONOCIMIENTO"))}</div><strong>${E(p.title.split(":")[0])}</strong><span class="cover-icon">${I(p.meta.resource_type === "Video" ? "play" : "book")}</span></a>`;
    const editorialState = p.status !== "publish" ? status(p.status) : "";
    return `<article class="card resource-card">${cover}<div class="resource-content"><div class="resource-card-badges"><span class="tag">${E(T(p.meta.resource_type || "Artículo"))}</span>${editorialState}</div><h3><a href="${E(p.url)}">${E(p.title)}</a></h3><p>${E((p.meta.summary || p.body).slice(0, 115))}${(p.meta.summary || p.body).length > 115 ? "…" : ""}</p><div class="resource-footer"><span>${date(p.date)}</span>${btn("Explorar " + I("arrow"), "item", `data-id="${p.id}"`, "ghost")}</div></div></article>`;
  }
  function eventMini(p) {
    const d = new Date(p.meta.start);
    return `<div class="event-mini"><div class="date-box"><small>${d.toLocaleDateString(C.locale || "es-PE", { month: "short" })}</small><strong>${d.getDate()}</strong></div><div><h3>${E(p.title)}</h3><p>${E(T(p.meta.modality || "Virtual"))} · ${time(p.meta.start)}</p><a href="${E(p.url)}">${E(T("Ver evento"))} ${I("arrow")}</a></div></div>`;
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
    const weekdays = Array.from({length:7},(_,i)=>new Intl.DateTimeFormat(C.locale || "es-PE",{weekday:"narrow"}).format(new Date(2024,0,1+i)));
    return `<div class="calendar-head"><strong>${E(d.toLocaleDateString(C.locale || "es-PE", { month: "long", year: "numeric" }))}</strong><span>${btn("‹", "calendar-prev", `aria-label="${E(T("Mes anterior"))}"`, "ghost")}${btn("›", "calendar-next", `aria-label="${E(T("Mes siguiente"))}"`, "ghost")}</span></div><div class="calendar">${weekdays.map((x) => `<span class="weekday">${E(x)}</span>`).join("")}${"<span></span>".repeat(first)}${Array.from({ length: days }, (_, i) => `<span class="${today.getFullYear() === d.getFullYear() && today.getMonth() === d.getMonth() && today.getDate() === i + 1 ? "today" : eventDays.includes(i + 1) ? "event-day" : ""}">${eventDays.includes(i + 1) ? `<button class="calendar-day" data-action="calendar-day" data-day="${i + 1}" aria-label="${E(T("Ver eventos del día"))} ${i + 1}">${i + 1}</button>` : i + 1}</span>`).join("")}</div><div class="calendar-legend"><i></i> ${E(T("Eventos de la comunidad"))}</div>`;
  }
  async function dashboard() {
    const [people, events, resources, hub, directory, notes, monthEvents, recentResources] =
      await Promise.all([
        api("matching"),
        api("content/event?past=0&status=publish"),
        api("content/resource?recommended=1"),
        api("content/hub"),
        api("profiles"),
        api("notifications/summary"),
        calendarEvents(),
        api("content/resource?status=publish"),
      ]);
    S.events = monthEvents;
    const upcoming = events.items
      .filter((p) => new Date(p.meta.end) > new Date())
      .sort((a, b) => new Date(a.meta.start) - new Date(b.meta.start));
    const p = S.boot.me;
    content().innerHTML = `<section class="hero"><div class="hero-copy"><div class="eyebrow">${E(T("TU COMUNIDAD, MÁS CERCA"))}</div><h1>${E(T("Hola"))} ${E(p.first_name || p.name.split(" ")[0])},<br>${E(T("sigamos conectando ideas."))}</h1><p>${E(T("Un espacio para compartir experiencias, fortalecer tu red y construir el futuro del gobierno corporativo."))}</p>${link("directorio", "Explorar la comunidad " + I("arrow"), "white small")}</div><svg class="hero-art" viewBox="0 0 300 260" fill="none" aria-hidden="true"><g stroke="#7ac0e5" stroke-width=".7"><circle cx="160" cy="130" r="105"/><circle cx="160" cy="130" r="77"/><ellipse cx="160" cy="130" rx="45" ry="105"/><path d="M55 130h210M80 64l170 134M80 196 247 65M68 80l191 89M70 173l180-89"/></g><g fill="#8dc4e3" stroke="#c7edff" stroke-width="4"><circle cx="90" cy="76" r="9"/><circle cx="236" cy="70" r="7"/><circle cx="160" cy="130" r="11"/><circle cx="87" cy="195" r="7"/><circle cx="244" cy="193" r="10"/><circle cx="181" cy="27" r="5"/></g></svg><div class="hero-tag"><i></i> ${E(T("Una red que crece contigo"))}</div></section><div class="stat-grid">${[
      [directory.total, "Asociados en la comunidad", "users"],
      [events.total, "Próximos encuentros", "calendar"],
      [recentResources.total, "Recursos para aprender", "book"],
      [notes.unread_total, "Nuevas notificaciones", "bell"],
    ]
      .map(
        ([num, label, icon]) =>
          `<div class="stat"><span class="stat-icon">${I(icon)}</span><div><strong>${num}</strong><small>${E(T(label))}</small></div></div>`,
      )
      .join(
        "",
      )}</div><div class="dashboard-columns"><div><div class="section-top"><h2>${E(T("Conexiones que suman"))}</h2>${link("directorio", "Ver directorio " + I("arrow"), "ghost")}</div><div class="cards">${people.slice(0, 3).map(memberCard).join("") || (S.boot.me.networking ? empty("Aún no hay coincidencias suficientes", "Las recomendaciones aparecen cuando alcanzan el mínimo de afinidad definido por la comunidad.") : empty("Activa tu networking", "Completa tus intereses en Perfil para descubrir conexiones."))}</div><div class="section-gap"><div class="section-top"><h2>${E(T("Conocimiento para tu día a día"))}</h2>${link("centro-conocimiento", "Ver todo " + I("arrow"), "ghost")}</div><div class="cards two">${resources.items.slice(0, 2).map(resourceCard).join("") || empty("Completa tus intereses para ver recomendaciones")}</div><h3 class="section-gap recent-heading">${E(T("Publicados recientemente"))}</h3><div class="cards two">${recentResources.items.slice(0, 2).map(resourceCard).join("") || empty("Tu biblioteca está por comenzar")}</div></div><div class="section-gap"><div class="section-top"><h2>${E(T("La conversación en nuestra comunidad"))}</h2>${link("hub", "Ir al Hub " + I("arrow"), "ghost")}</div>${hub.items.slice(0, 1).map(feedCard).join("") || empty("Comparte la primera idea")}</div></div><aside class="dashboard-aside"><div><div class="section-top"><h2>${E(T("Tu agenda ASCLA"))}</h2>${I("calendar")}</div><div class="card calendar-card"><div id="calendar-body">${calendar(monthEvents)}</div><div style="border-top:1px solid var(--line);margin-top:16px;padding-top:8px">${upcoming.slice(0, 2).map(eventMini).join("") || `<p class="muted">${E(T("Sin próximos eventos."))}</p>`}</div></div></div><div class="notice-card">${I("spark")}<h3>${E(T("El conocimiento, a una pregunta"))}</h3><p>${E(T("Encuentra respuestas en los recursos de nuestra comunidad con el Asistente ASCLA."))}</p>${link("asistente", "Hacer una pregunta " + I("arrow"), "ghost small")}</div><div class="notice-card trust-card">${I("shield")}<h3>${E(T("Un espacio de confianza"))}</h3><p>${E(T("Compartimos conocimiento con respeto, confidencialidad y bajo la Regla de Chatham House."))}</p>${btn("Normas de la comunidad " + I("arrow"), "rules", "", "ghost small")}</div></aside></div>`;
  }
  function feedCard(p) {
    return `<article class="card feed-card"><div class="feed-top">${avatar({ name: p.author.name })}<div><strong>${E(p.author.name)}</strong><small>${date(p.date)} · Comunidad ASCLA</small></div><span style="margin-left:auto">${p.status !== "publish" ? status(p.status) : ""}</span></div><h3><a href="${E(p.url)}">${E(p.title)}</a></h3><p>${E(p.body.slice(0, 450))}${p.body.length > 450 ? "…" : ""}</p>${UI.attachments(p, true)}${p.tags.length ? `<div class="tag-row">${p.tags.map((t) => `<span class="tag">${E(t.name)}</span>`).join("")}</div>` : ""}<div class="feed-actions">${btn(I("heart") + " " + p.reactions, "like", `data-id="${p.id}" data-active="${!p.liked}" aria-label="${E(T("Me gusta"))}"`)}${btn(I("hub") + " " + p.comments + " " + T(p.comments === 1 ? "comentario" : "comentarios"), "item", `data-id="${p.id}"`)}${btn(I("arrow") + " " + T("Ver conversación"), "item", `data-id="${p.id}"`)}</div></article>`;
  }
  function pager(list) {
    return list.pages > 1
      ? `<div class="pagination">${btn("Anterior", "page", `data-page="${list.page - 1}" ${list.page <= 1 ? "disabled" : ""}`, "small")}<span>${E(T("Página"))} ${list.page} ${E(T("de"))} ${list.pages}</span>${btn("Siguiente", "page", `data-page="${list.page + 1}" ${list.page >= list.pages ? "disabled" : ""}`, "small")}</div>`
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
      `<section class="card connections-panel" id="connections-panel" aria-label="${E(T("Mis conexiones"))}"></section><form class="filters directory-filters" data-form="filters"><input aria-label="${E(T("Buscar perfiles"))}" name="q" placeholder="${E(T("Nombre, cargo, empresa o experiencia…"))}" value="${E(S.filter.q || "")}"><input aria-label="${E(T("País"))}" name="country" placeholder="${E(T("País"))}" value="${E(S.filter.country || "")}" style="max-width:180px;min-width:120px"><select name="industries" aria-label="${E(T("Industria"))}" style="max-width:200px"><option value="">${E(T("Todas las industrias"))}</option>${S.boot.catalogs.industry.map((t) => `<option value="${t.id}" ${String(S.filter.industries) === String(t.id) ? "selected" : ""}>${E(t.name)}</option>`).join("")}</select>${UI.termFilter("interests", "Interés", S.boot.catalogs.interest, S.filter)}${UI.termFilter("areas", "Área de conocimiento", S.boot.catalogs.area, S.filter)}<button class="btn primary">${I("search")} ${E(T("Buscar"))}</button></form><div class="section-top"><span class="muted" style="font-size:12px">${list.total} ${E(T("perfiles en la comunidad"))}</span>${link("perfil", "Editar mis intereses", "ghost")}</div><div class="cards directory">${list.items.map(memberCard).join("")}</div>${!list.items.length ? empty("No encontramos perfiles", "Prueba con otro nombre, país o interés.") : ""}${pager(list)}`;
    await refreshConnectionsPanel();
  }
  async function member(id) {
    const p = await api("profiles/" + id);
    let match = null;
    try {
      match = await api("matching/" + id);
    } catch (error) { if (error.name === "AbortError") throw error; }
    const actions = Number(id) !== S.boot.me.id ? connectionActions(p, !!match) : link("perfil", "Editar perfil", "primary");
    const affinity = match ? `<span class="match-pill">${I("spark")}${match.score}% ${E(T("de afinidad"))}</span>` : "";
    const matchInfo = match ? `<div class="alert">${E(match.explanation)}<p class="private-note">${E(match.mode || T("Afinidad determinística"))}</p><p>${E(match.conversation_proposal || "")}</p></div>` : "";
    const terms = Object.entries(p.terms || {}).filter(([, v]) => v.length).map(([k, v]) => `<h3>${E(T(profileLabels[k] || k))}</h3><div class="tag-row">${v.map((t) => `<span class="tag">${E(t)}</span>`).join("")}</div>`).join("");
    const sources = ["linkedin", "twitter", "website"].filter((k) => p[k]).map((k) => `<a href="${E(safeURL(p[k]))}" target="_blank" rel="noopener noreferrer">${E({ linkedin: "LinkedIn", twitter: "X / Twitter", website: T("Sitio personal") }[k])} ↗</a>`).join("");
    modal(
      p.name,
      `<div class="profile-summary">${avatar(p, "xl")}<div><h2>${E(p.position || T("Miembro ASCLA"))}</h2><p class="muted">${E(p.company || "")}</p><p class="muted">${E(p.country || "")} ${E(p.city || "")}</p>${affinity}</div></div>${matchInfo}<p class="detail-body">${E(p.bio || T("Este miembro aún no ha añadido su biografía."))}</p>${p.experience ? `<h3>${E(T("Experiencia profesional"))}</h3><p class="detail-body">${E(p.experience)}</p>` : ""}${terms}<div class="sources">${sources}</div><div class="form-actions">${actions}</div>`,
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
  function profileTopic(key, tax, p) {
    const terms = S.boot.catalogs[tax], selected = new Set(p[key] || []);
    const names = terms.filter(t => selected.has(t.id)).map(t => t.name);
    const [icon, hint] = profileTopics[key];
    const choices = terms.map(t => `<label class="topic-choice"><input type="checkbox" name="${key}" value="${t.id}" data-choice-label="${E(t.name)}" ${selected.has(t.id) ? "checked" : ""}><span>${I("check")}${E(t.name)}</span></label>`).join("");
    return `<details class="profile-topic" ${["interests", "areas"].includes(key) ? "open" : ""}>
      <summary><span class="topic-icon">${I(icon)}</span><span class="topic-caption"><span class="topic-title">${E(T(profileLabels[key]))}</span><span class="topic-selection">${E(names.join(" · ") || T("Aún no has elegido opciones"))}</span></span><span class="topic-count" aria-label="${names.length} ${E(T("seleccionados"))}">${names.length}</span><span class="topic-chevron">${I("chevron")}</span></summary>
      <div class="topic-options"><p>${E(T(hint))}. ${E(T("Puedes elegir varias opciones."))}</p><div class="topic-choices">${choices || `<span class="muted">${E(T("No hay opciones disponibles."))}</span>`}</div></div>
    </details>`;
  }
  function profileKnowledge(p) {
    return `<section class="profile-preferences" aria-labelledby="profile-knowledge-title"><div class="preference-heading"><span class="preference-emblem">${I("spark")}</span><div><h2 id="profile-knowledge-title">${E(T("Conocimiento e intereses"))}</h2><p>${E(T("Haz que tu perfil conecte con las personas y las ideas que te interesan."))}</p></div></div><div class="profile-topics">${Object.entries(profileTax).map(([key, tax]) => profileTopic(key, tax, p)).join("")}</div></section>`;
  }
  function profileParticipation(p) {
    const options = [
      ["directory", "users", "Aparecer en el directorio", "Permite que otros asociados encuentren tu perfil."],
      ["networking", "spark", "Descubrir nuevas conexiones", "Recibe recomendaciones de personas afines a tus intereses."],
      ["microevents", "calendar", "Participar en microeventos", "Recibe propuestas para conversar en grupos de 4 a 6 personas."],
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
  function updateProfilePreference(input) {
    const topic = input.closest(".profile-topic");
    if (!topic) return;
    const selected = [...topic.querySelectorAll("input:checked")];
    const count = topic.querySelector(".topic-count");
    count.textContent = selected.length;
    count.setAttribute("aria-label", selected.length + " " + T("seleccionados"));
    topic.querySelector(".topic-selection").textContent = selected.map(choice => choice.dataset.choiceLabel).join(" · ") || T("Aún no has elegido opciones");
  }
  async function profile() {
    const p = await api("profiles/" + S.boot.me.id);
    content().innerHTML =
      heading(
        "Mi perfil",
        "Tu experiencia es el punto de partida de nuevas conexiones.",
      ) +
      `<form class="card" data-form="profile" data-guard-unsaved><div class="profile-summary">${avatar(p, "xl")}<div><h2>${E(p.name)}</h2><p class="muted">${E(p.email || "")}</p><label class="btn small" style="margin-top:10px">${I("edit")} ${E(T("Cambiar fotografía"))}<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" hidden data-upload="photo"></label><input type="hidden" name="photo_id" value="${p.photo_id || 0}"><div id="photo-status" class="private-note">${E(T("JPG, PNG o WebP. Podrás mover, ampliar y recortar antes de guardar; ASCLA optimiza la imagen automáticamente."))}</div></div></div><div class="form-section">${E(T("Información profesional"))}</div><div class="form-grid">${["first_name", "last_name", "position", "company"].map((k) => field(k, profileLabels[k], p[k] || "", "text", 'maxlength="200"')).join("")}${profileLocationFields(p)}${["member_type", "linkedin", "twitter", "website"].map((k) => field(k, profileLabels[k], p[k] || "", k === "linkedin" || k === "twitter" || k === "website" ? "url" : "text", 'maxlength="200"')).join("")}<div class="full">${field("bio", "Biografía", p.bio || "", "textarea", 'maxlength="3000"')}${field("experience", "Experiencia profesional", p.experience || "", "textarea", 'maxlength="3000"')}</div></div>${profileKnowledge(p)}${profilePrivacy(p)}<div class="form-actions"><button class="btn primary">${I("check")} ${E(T("Guardar perfil"))}</button></div></form><div class="card section-gap account-security"><div class="form-section">${E(T("Seguridad de la cuenta"))}</div><h3>${E(T("Cambiar contraseña"))}</h3><p class="private-note">${E(T("Para cambiarla desde la intranet, confirma primero tu contraseña actual."))}</p><form data-form="password-change"><div class="password-current">${field("current_password", "Contraseña actual", "", "password", 'required autocomplete="current-password"')}</div><div class="form-grid password-new-grid">${field("new_password", "Nueva contraseña", "", "password", 'required minlength="12" autocomplete="new-password"')}${field("confirm_password", "Confirmar nueva contraseña", "", "password", 'required minlength="12" autocomplete="new-password"')}</div><div class="form-actions"><button class="btn primary">${E(T("Cambiar contraseña"))}</button></div></form><div class="password-recovery"><h3>${E(T("¿No recuerdas tu contraseña actual?"))}</h3><p class="private-note">${E(T("Puedes recibir un enlace seguro de recuperación en"))} <strong>${E(p.email || S.boot.me.email || T("tu correo registrado"))}</strong>.</p>${btn(I("mail") + " Enviar enlace de recuperación", "password-reset-email", "", "small")}</div></div><div class="card section-gap"><h3>${E(T("Mis archivos"))}</h3><p class="private-note">${E(T("Consulta tus archivos y elimina los que ya no necesitas. Los archivos eliminados también se retiran de las publicaciones y de tu fotografía de perfil."))}</p>${btn("Administrar mis archivos", "files", 'data-scope="mine"', "small")}<h3 class="section-gap">${E(T("Mi calendario"))}</h3><p class="private-note">${E(T(S.boot.google_connected ? "Tu calendario Google está conectado." : "Integración Google Calendar no configurada para tu cuenta. Puedes conectarlo para guardar próximos eventos."))}</p><div class="admin-actions">${btn("Conectar Google Calendar", "google-connect", 'data-service="calendar"')}${S.boot.google_connected ? btn("Desconectar", "google-disconnect", 'data-service="calendar"') : ""}</div></div>`;
    const profileForm = content().querySelector('[data-form="profile"]');
    registerUnsavedForm(profileForm);
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
      ["gallery", "resource", "event"].includes(type) ? (S.boot.admin || S.boot.executive) : S.boot.moderator || ["hub", "topic"].includes(type);
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
              I("plus") + " " + (type === "topic" ? T("Crear foro") : "Nuev" + (type === "hub" || type === "gallery" ? "a " : "o ") + typeLabel[type]),
              "editor",
              `data-type="${type}"`,
              "primary",
            )
          : "",
      ) +
      `<form class="filters" data-form="filters"><input name="q" aria-label="${E(T("Buscar contenido"))}" value="${E(S.filter.q || "")}" placeholder="${E(T(type === "resource" ? "Buscar por tema, autor o contenido…" : "Buscar en esta sección…"))}">${type === "resource" ? `<select name="resource_type" aria-label="${E(T("Tipo de recurso"))}" style="max-width:180px"><option value="">${E(T("Todos los tipos"))}</option>${["Artículo", "Video", "Podcast", "Nota técnica", "Infografía", "Documento"].map((t) => `<option value="${E(t)}" ${S.filter.resource_type === t ? "selected" : ""}>${E(T(t))}</option>`).join("")}</select><input type="date" name="after" value="${E(S.filter.after || "")}" aria-label="${E(T("Desde fecha"))}" style="max-width:160px;min-width:100px">` : ""}${UI.filters(type, S.filter, S.boot.catalogs, authors, forums)}<button class="btn">${I("search")} ${E(T("Buscar"))}</button></form><div class="tabs">${btn(type === "event" ? "Próximos eventos" : "Comunidad", "filter-all", "", "tab " + (!S.filter.mine && !S.filter.past ? "active" : ""))}${type === "event" ? btn("Eventos anteriores", "filter-past", "", "tab " + (S.filter.past ? "active" : "")) : ""}${canWrite ? btn("Mis publicaciones", "filter-mine", "", "tab " + (S.filter.mine ? "active" : "")) : ""}</div>${type === "topic" && forums.length ? `<details class="card forum-directory"><summary>${E(T("Explorar foros"))} (${forums.length})</summary><div class="form-actions">${forums.map(f => btn(E(f.name), "item", `data-id="${f.id}"`, "small")).join("")}</div></details>` : ""}${type === "resource" ? `<div class="cards">${items.map(resourceCard).join("")}</div>` : type === "event" ? `<div class="cards two">${items.map(eventCard).join("")}</div>` : type === "gallery" ? `<div class="cards">${items.map(galleryCard).join("")}</div>` : type === "ally" ? `<div class="cards">${items.map(allyCard).join("")}</div>` : items.map(feedCard).join("")}${!items.length ? empty("Aún no hay contenido aquí", S.filter.q ? "Prueba una búsqueda diferente." : "Comparte un aporte o vuelve pronto para ver novedades.") : ""}${pager(list)}`;
  }
  function eventCard(p) {
    return `<article class="card"><div class="section-top"><span class="tag">${I("calendar")} ${E(T(p.meta.modality || "Virtual"))}</span>${p.status !== "publish" ? status(p.status) : ""}</div><h3>${E(p.title)}</h3><p class="detail-body" style="font-size:12px">${E(p.body.slice(0, 160))}</p><div class="detail-meta"><span>${I("calendar")} ${date(p.meta.start)}</span><span>${I("clock")} ${time(p.meta.start)}</span></div><div class="form-actions" style="justify-content:space-between">${p.meta.chatham ? '<span class="tag">Chatham House</span>' : "<span></span>"}${btn("Ver encuentro " + I("arrow"), "item", `data-id="${p.id}"`, "small primary")}</div></article>`;
  }
  function galleryCard(p) {
    return `<article class="card resource-card">${p.meta.media_ids?.length ? `<img src="${E(C.mediaUrl + p.meta.media_ids[0])}" alt="${E(p.title)}" style="height:190px;object-fit:cover;width:100%">` : `<div class="resource-cover v1"><div class="cover-label">${E(T("ASCLA · ENCUENTROS"))}</div><strong>${E(p.title)}</strong><span class="cover-icon">${I("gallery")}</span></div>`}<div class="resource-content"><h3>${E(p.title)}</h3><p>${E(p.body.slice(0, 120))}</p><div class="resource-footer"><span>${date(p.date)}</span>${btn("Ver galería", "item", `data-id="${p.id}"`, "ghost")}</div></div></article>`;
  }
  function allyCard(p) {
    return `<article class="card">${UI.images(p).length ? `<img class="ally-logo" src="${E(UI.images(p)[0].url)}" alt="${E(T("Logo de"))} ${E(p.title)}" loading="lazy">` : `<div class="stat-icon" style="margin-bottom:17px">${I("ally")}</div>`}<span class="tag">${E(T(p.meta.alliance_type || "Alianza"))}</span><h3 style="margin-top:12px">${E(p.title)}</h3><p class="detail-body" style="font-size:12px">${E(p.body)}</p>${btn("Conoce más " + I("arrow"), "item", `data-id="${p.id}"`, "ghost")}</article>`;
  }
  function overflowDelete(action, id, extra = "", label = "Eliminar") {
    return `<details class="overflow-menu"><summary aria-label="${E(T("Más opciones"))}" title="${E(T("Más opciones"))}">${I("more")}</summary><div class="overflow-popover"><button type="button" class="overflow-item danger-text" data-action="${action}" data-id="${Number(id)}" ${extra}>${E(T(label))}</button></div></details>`;
  }
  function commentOverflow(c, postId) {
    const items = [];
    if (c.can_report) items.push(`<button type="button" class="overflow-item" data-action="report-comment" data-id="${Number(c.id)}" data-post="${Number(postId)}">${E(T("Reportar comentario"))}</button>`);
    if (c.can_delete) items.push(`<button type="button" class="overflow-item danger-text" data-action="delete-comment" data-id="${Number(c.id)}" data-post="${Number(postId)}">${E(T("Eliminar comentario"))}</button>`);
    if (!items.length) return "";
    return `<details class="overflow-menu"><summary aria-label="${E(T("Más opciones"))}" title="${E(T("Más opciones"))}">${I("more")}</summary><div class="overflow-popover">${items.join("")}</div></details>`;
  }
  function commentsHTML(comments, postId) {
    if (!comments.length) return `<p class="private-note">${E(T('Sé la primera persona en compartir una idea.'))}</p>`;
    const byId = new Map(comments.map(c => [Number(c.id), c]));
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
      const actions = `<div class="comment-actions">${btn(I('heart') + ` <span>${Number(c.likes || 0)}</span>`, 'comment-like', `data-id="${Number(c.id)}" data-post="${Number(postId)}" data-active="${!c.liked}" aria-pressed="${!!c.liked}" aria-label="${E(T(c.liked ? 'Quitar Me gusta' : 'Me gusta'))}"`, `ghost small comment-like ${c.liked ? 'active' : ''}`)}${canReply ? btn(I('reply') + ' ' + T('Responder'), 'comment-reply', `data-id="${Number(c.id)}" data-post="${Number(postId)}" data-author="${E(c.author)}"`, 'ghost small') : ''}${c.status === 'pending' ? status(c.status) : ''}</div>`;
      const replies = (children.get(Number(c.id)) || []).map(child => renderOne(child, depth + 1)).join('');
      return `<article class="comment ${depth ? 'comment-reply' : ''}" data-comment-id="${Number(c.id)}" style="--comment-depth:${Math.min(depth, 4)}"><div class="comment-head"><div><strong>${E(c.author)}</strong> <small class="muted">${date(c.date)}</small></div>${menu}</div><p>${E(c.body)}</p>${actions}<div class="comment-reply-slot"></div>${replies}</article>`;
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
    slot.innerHTML = `<form class="comment-reply-form" data-form="comment-reply" data-id="${Number(button.dataset.post)}" data-parent="${Number(button.dataset.id)}"><label>${E(T("Responder a"))} ${E(button.dataset.author || T('este comentario'))}</label><textarea name="body" required maxlength="5000" placeholder="${E(T("Escribe una respuesta…"))}"></textarea><div class="form-actions">${btn('Cancelar','comment-reply-cancel','','ghost small')}<button class="btn primary small">${E(T("Responder"))}</button></div></form>`;
    slot.querySelector('textarea')?.focus();
  }
  async function item(id) {
    const p = await api("items/" + id);
    S.item = p;
    let extra = "";
    if (p.type === "event") {
      const d = await api("events/" + id);
      S.event = d;
      const registrationActions = d.is_past
        ? `<span class="tag">${E(T("Evento finalizado"))}</span>`
        : `${d.registered === "accepted" ? btn("Cancelar inscripción", "register", `data-id="${id}" data-status="cancelled"`) : btn("Registrarme", "register", `data-id="${id}" data-status="accepted"`, "primary")}${d.registered === "invited" ? btn("Rechazar invitación", "register", `data-id="${id}" data-status="declined"`) : ""}`;
      extra = `<div class="card" style="background:var(--bg);margin:20px 0"><div class="detail-meta"><span>${I("calendar")} ${date(p.meta.start, { weekday: "long", day: "numeric", month: "long", year: "numeric" })}</span><span>${I("clock")} ${time(p.meta.start)} – ${time(p.meta.end)}</span><span>${I("pin")} ${E(p.meta.location || T("Por confirmar"))}</span></div><p class="private-note">${d.attending} ${E(T("inscritos"))}${p.meta.capacity ? " · " + p.meta.capacity + " " + T("cupos") : " · " + T("Sin límite de cupos")}${d.is_past ? " · " + T("Finalizado") : " · " + status(d.registered)}</p>${p.meta.agenda ? `<p class="detail-body">${E(p.meta.agenda).replace(/\n/g, "<br>")}</p>` : ""}<div class="form-actions">${registrationActions}${!d.is_past && d.google_url ? `<a class="btn small" href="${E(d.google_url)}" target="_blank" rel="noopener noreferrer">${E(T("Añadir a Google Calendar ↗"))}</a>` : ""}${!d.is_past && S.boot.google_connected ? btn("Guardar en Google conectado", "google-event", `data-id="${id}" data-operation="save"`, "small") + btn("Quitar de Google", "google-event", `data-id="${id}" data-operation="cancel"`, "small") : ""}</div>${d.participants ? `<details><summary class="private-note">${E(T("Participantes (moderación)"))}</summary>${d.participants.map((x) => `<p>${E(x.name)} · ${status(x.status)}</p>`).join("")}</details>` : ""}</div>`;
    }
    const canEdit = ["gallery", "resource", "event"].includes(p.type) ? (S.boot.admin || S.boot.executive) : S.boot.moderator || p.author.id === S.boot.me.id;
    const reviewedLabel = T(p.meta.reviewed ? "Revisado" : "Requiere revisión de fuentes, anonimización y derechos.");
    const clipQuery = p.meta.clip ? "?start=" + Number(p.meta.clip.start) + "&end=" + Number(p.meta.clip.end) : "";
    const videoDuration = p.meta.duration_seconds ? E(UI.duration(p.meta.duration_seconds)) : E(T("Duración por confirmar"));
    const eventIsPast = p.type === "event" && !!S.event?.is_past;
    const followingLabel = p.following ? "Dejar de seguir" : "Seguir conversación";
    const videoModeTag = p.meta.video_metadata_mode ? `<span class="tag">${E(p.meta.video_metadata_mode)}</span>` : "";
    const demoTag = p.meta.demo ? `<span class="demo-badge">${E(T("DATOS DEMO"))}</span>` : "";
    const chatham = p.meta.chatham ? `<div class="alert chatham" style="margin-top:18px">${I("shield")} ${E(T("Regla de Chatham House: utiliza el conocimiento sin revelar identidades ni afiliaciones."))}</div>` : "";
    const generated = (p.meta.generated || p.meta.ai_enriched || p.meta.generated_sections)
      ? `<div class="alert">${E(T(p.meta.ai_enriched ? "Publicación enriquecida con IA" : "Contenido generado"))} · ${E(p.meta.ai_mode || p.meta.social_mode || "IA")}${p.meta.ai_enriched ? "" : " · " + E(reviewedLabel)}</div>`
      : "";
    const video = p.meta.video_id ? `<div class="video-wrap"><iframe loading="lazy" referrerpolicy="strict-origin-when-cross-origin" src="https://www.youtube-nocookie.com/embed/${E(p.meta.video_id)}${clipQuery}" title="${E(p.title)}" allow="accelerometer; encrypted-media; picture-in-picture" allowfullscreen></iframe></div><div class="video-metadata"><span>${videoDuration}</span>${videoModeTag}</div>` : "";
    const deleteMedia = (p.media || []).filter(m => m.can_delete).map(m => btn(`${T("Eliminar archivo")}: ${E(m.name)}`, "delete-media", `data-id="${m.id}" data-post="${id}" data-name="${E(m.name)}"`, "ghost danger small")).join("");
    const external = p.meta.url ? `<a class="btn" href="${E(safeURL(p.meta.url))}" target="_blank" rel="noopener noreferrer">${E(T("Abrir enlace ↗"))}</a>` : "";
    const benefits = p.meta.benefits ? `<h3>${E(T("Beneficios"))}</h3><p class="detail-body">${E(p.meta.benefits)}</p>` : "";
    const initiatives = p.meta.initiatives ? `<h3>${E(T("Iniciativas"))}</h3><p class="detail-body">${E(p.meta.initiatives)}</p>` : "";
    const clip = p.meta.clip ? `<div class="alert">${E(T("Cápsula sugerida"))}: ${p.meta.clip.start}s – ${p.meta.clip.end}s · ${E(T("Referencia temporal al video de origen. No existe un archivo recortado."))}</div>` : "";
    const actions = `<div class="form-actions">${p.can_delete ? btn("Eliminar", "delete-content", `data-id="${id}"`, "danger") : ""}${canEdit ? btn(I("edit") + " Editar", "editor", `data-type="${p.type}" data-id="${id}"`) : ""}${S.boot.moderator && p.type === "event" && p.status === "publish" && !p.meta.micro && !eventIsPast ? btn("Invitar asociados", "event-invite", `data-id="${id}"`) : ""}${(S.boot.admin || S.boot.executive) && p.type === "resource" && p.meta.video_id ? btn("Actualizar datos de YouTube", "video-metadata", `data-id="${id}"`) : ""}${(S.boot.admin || S.boot.executive) && p.type === "resource" ? btn(I("spark") + " Generar resumen y nota", "generate", `data-id="${id}"`) : ""}${p.type !== "contact" && (["gallery", "resource", "event"].includes(p.type) ? (S.boot.admin || S.boot.executive) : S.boot.moderator) ? btn(I("shield") + " Moderar", "moderate", `data-id="${id}"`) : ""}${p.status === "publish" ? btn(I("heart") + " " + p.reactions, "like", `data-id="${id}" data-active="${!p.liked}"`) + (!eventIsPast ? btn(followingLabel, "follow", `data-id="${id}" data-active="${!p.following}"`) : "") + (Number(p.author.id) !== Number(S.boot.me.id) ? btn("Reportar", "report", `data-id="${id}"`, "ghost") : "") : ""}</div>`;
    const commentsSection = p.status === "publish" ? `<section class="comments"><h3>${E(T("Conversación"))}</h3><div id="comments-list">${E(T("Cargando comentarios…"))}</div><form data-form="comment" data-id="${id}" style="margin-top:18px">${field("body", "Comparte tu opinión", "", "textarea", 'required maxlength="5000"')}<button class="btn primary small">${E(T("Publicar comentario"))}</button></form></section>` : "";

    modal(
      p.title,
      `<div class="detail-meta"><span>${E(p.author.name)}</span><span>${date(p.date)}</span>${status(p.status)}${demoTag}</div>${chatham}${generated}<p class="detail-body">${E(p.body)}</p>${video}${extra}${UI.attachments(p)}${deleteMedia}${UI.generated(p)}${UI.agenda(p)}${p.meta.demo_source_note ? `<p class="alert">${E(p.meta.demo_source_note)}</p>` : ""}${external}${benefits}${initiatives}${clip}${p.meta.copyright ? `<p class="private-note">${E(p.meta.copyright)}</p>` : ""}${actions}${commentsSection}`,
      true,
    );
    if (p.status === "publish") {
      const comments = await api("items/" + id + "/comments");
      const target = document.getElementById("comments-list");
      if (target) target.innerHTML = commentsHTML(comments, id);
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
      extra = `<div class="form-grid">${select("resource_type", "Tipo de recurso", ["Artículo", "Video", "Podcast", "Nota técnica", "Infografía", "Documento"], m.resource_type || "Artículo")}${field("source", "Fuente / autoría", m.source || "")}${field("youtube_url", "URL de YouTube", m.youtube_url || "", "url")}${field("url", "URL del recurso externo", m.url || "", "url")}</div>${m.video_id ? `<p class="private-note">${E(T("Duración detectada automáticamente"))}${m.duration_seconds ? `: ${E(UI.duration(m.duration_seconds))}` : "."}</p>` : ""}${field("copyright", "Propiedad intelectual", m.copyright || "© ASCLA – Asociación de Secretarios Corporativos de América Latina")}${field("summary", "Resumen", m.summary || "", "textarea")}${(S.boot.moderator || S.boot.executive) ? `${field("transcript", "Transcripción autorizada (opcional)", m.transcript || "", "textarea", 'maxlength="100000"')}${field("identities", "Identidades y afiliaciones que deben anonimizarse (una por línea)", m.identities || "", "textarea")}` : ""}<p class="private-note">Las conferencias completas permanecen en YouTube. La duración se actualiza automáticamente al guardar o procesar el video.</p>`;
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
    const directResourcePublisher = type === "resource" && (S.boot.admin || S.boot.executive);
    const directContentPublisher = (["gallery", "resource"].includes(type) ? (S.boot.admin || S.boot.executive) : S.boot.moderator);
    const statusOptions = (["topic", "forum"].includes(type) || (type === "event" && !m.micro && !m.generated))
      ? [["draft", "Borrador"], ["publish", "Publicar ahora"]]
      : directContentPublisher && (!m.generated || directResourcePublisher)
        ? [["draft", "Borrador"], ["pending", "Pendiente de revisión"], ["publish", m.generated ? "Publicar ahora (revisado)" : "Publicado"]]
        : [["draft", "Borrador"], ["pending", "Enviar a revisión"]];
    const statusValue = (["topic", "forum"].includes(type) || (type === "event" && !m.micro && !m.generated))
      ? (id && p.status === "draft" ? "draft" : "publish")
      : (!id && type === "resource" && directResourcePublisher
          ? "publish"
          : (p.status === "publish" && directContentPublisher ? "publish" : p.status));
    modal(
      (id ? "Editar " : "Crear ") + typeLabel[type],
      `<form data-form="editor" data-type="${type}" data-id="${id}">${field("title", "Título", p.title, "text", 'required maxlength="200"')}${field("body", "Contenido", p.body, "textarea", 'required maxlength="30000"')}${extra}${select("category", "Categoría", [["", "Sin categoría"], ...S.boot.catalogs.category.map(t => [t.id, t.name])], p.tags.find(t => t.taxonomy === "ascla_category")?.id || "")}${field("tag_names", "Etiquetas (separadas por comas)", p.tags.filter(t => t.taxonomy === "ascla_tag").map(t => t.name).join(", ") || (m.tags || []).join(", "))}<label>Temas</label><div class="multi-select">${S.boot.catalogs.interest.map((t) => `<label class="chip-check"><input type="checkbox" name="interest" value="${t.id}" ${p.tags.some((x) => x.id === t.id) ? "checked" : ""}>${E(t.name)}</label>`).join("")}</div>${["hub", "gallery", "resource", "ally"].includes(type) ? `<label class="btn small">${I("plus")} ${E(T("Adjuntar imagen o PDF"))}<input type="file" data-upload="content" accept="image/jpeg,image/png,image/webp,application/pdf" hidden></label><div id="attachments">${(m.media_ids || []).map((mid) => `<span class="attached-file" data-media="${mid}">Archivo #${mid}${btn("Quitar", "detach-media", `data-id="${mid}"`, "ghost small")}</span>`).join("")}</div><p class="private-note">${E(T("Las imágenes se previsualizan, recortan y optimizan antes de guardarse; PDF hasta 5 MB. Sólo acceso autenticado."))}</p>` : ""}${(S.boot.moderator || S.boot.executive) ? check("chatham", "Aplicar Regla de Chatham House", m.chatham !== false) : ""}${select("status", "Guardar como", statusOptions, statusValue)}${m.generated && directResourcePublisher ? `<p class="private-note">${E(T("Si eliges Publicar ahora, confirmas que revisaste fuentes, anonimización y derechos antes de publicar."))}</p>` : ""}<div class="alert">Respeta la confidencialidad, la propiedad intelectual y la diversidad. No se admite spam ni promoción comercial directa.</div><div class="form-actions">${btn("Cancelar", "close")}<button class="btn primary">Guardar ${typeLabel[type]}</button></div></form>`,
      true,
    );
  }
  async function files(page = 1, q = "", scope = S.files?.scope || "mine", owner = S.files?.owner || 0) {
    const previousScope = S.files?.scope || "";
    scope = scope === "all" && S.boot.admin ? "all" : "mine";
    if (previousScope && previousScope !== scope) owner = 0;
    owner = scope === "all" ? Math.max(0, Number(owner) || 0) : Number(S.boot.me.id);
    const list = await api("media?" + new URLSearchParams({page, q, scope, owner: scope === "all" ? owner : 0}));
    S.files = {page, q, scope, owner: Number(list.owner || owner || 0)};
    const ownerFilter = scope === "all" ? `<div class="field"><label for="file-owner">${E(T("Usuario propietario"))}</label><select id="file-owner" name="owner"><option value="0">${E(T("Todos los usuarios"))}</option>${(list.owners || []).map(u => `<option value="${Number(u.id)}" ${Number(S.files.owner)===Number(u.id)?"selected":""}>${E(u.name)} · ${E(u.email)}</option>`).join("")}</select></div>` : "";
    modal(scope === "all" ? "Archivos de la comunidad" : "Mis archivos", `<form data-form="file-search" class="filters file-library-filters"><div class="field"><label for="file-query">${E(T("Buscar archivos"))}</label><input id="file-query" name="q" aria-label="${E(T("Buscar archivos"))}" placeholder="${E(T("Buscar por nombre…"))}" value="${E(q)}"></div>${ownerFilter}<button class="btn">${E(T("Buscar"))}</button></form><p class="private-note">${list.total} ${E(T("archivos"))} · ${E(T("La eliminación es permanente y retira sus referencias."))}</p><div class="file-library">${list.items.map(m => `<article class="file-row" data-file="${m.id}"><div>${I(m.mime.startsWith('image/') ? 'gallery' : 'book')}<strong>${E(m.name)}</strong><small>${E(m.author)} · ${Math.ceil((m.stored_size || m.size) / 1024)} KB${m.has_master ? " · " + E(T("incluye copia maestra optimizada")) : ""} · ${date(m.date)}</small></div><div class="form-actions"><a class="btn small" href="${E(m.url)}" target="_blank" rel="noopener">${E(T("Abrir archivo"))}</a>${btn("Eliminar archivo", "delete-media", `data-id="${m.id}" data-name="${E(m.name)}" data-library="true"`, "danger small")}</div></article>`).join("") || empty("No hay archivos")}</div><div class="pagination">${btn("Anterior", "files", `data-page="${page-1}" ${page<=1?'disabled':''}`)}<span>${page} / ${list.pages}</span>${btn("Siguiente", "files", `data-page="${page+1}" ${page>=list.pages?'disabled':''}`)}</div>`, true);
  }
  function confirmDeletion(kind, id, {post = 0, title = "", library = false, forum = false, conversation = 0} = {}) {
    const description = kind === 'media' ? 'El archivo se eliminará permanentemente, junto con su copia maestra si existe, y se retirará de las publicaciones y de la foto de perfil que lo utilicen.' : kind === 'comment' ? 'El comentario dejará de mostrarse en la conversación.' : kind === 'message' ? 'El mensaje se eliminará de esta conversación para ambos participantes.' : 'El contenido dejará de estar disponible en la comunidad.';
    modal(T('Confirmar eliminación'), `<p class="detail-body">${E(title)}</p><p>${E(T(description))}</p>${forum?'<p>Los temas de este foro se conservarán en la lista general.</p>':''}<div class="form-actions">${btn('Cancelar','delete-cancel',`data-kind="${kind}" data-id="${id}" data-post="${post}" data-library="${library}" data-conversation="${conversation}"`)}${btn('Eliminar','delete-confirm',`data-kind="${kind}" data-id="${id}" data-post="${post}" data-library="${library}" data-conversation="${conversation}"`,'danger primary')}</div>`);
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
  const chatDrafts = new Map();
  root.addEventListener('input', event => {
    const form = event.target.closest('.chat-compose');
    if (form) chatDrafts.set(Number(form.dataset.id), form.elements.body.value);
  });
  function stopChat() {
    clearTimeout(S.poll);
    if (S.chat) S.chat.active = false;
    S.chat = null;
  }
  function chatAlive(chat) { return chat?.active && S.chat === chat && S.page === "mensajeria"; }
  function chatStatus(text, failed = false) {
    const label = document.getElementById("chat-sync");
    if (label) {
      label.textContent = text || "";
      label.hidden = !text;
      label.classList.toggle("is-error", failed);
    }
  }
  function messageBubble(m) {
    const mine = Number(m.sender_id) === Number(S.boot.me.id);
    const menu = m.can_delete ? overflowDelete('delete-message', m.id, `data-conversation="${Number(m.conversation_id || S.conversation)}"`, 'Eliminar mensaje') : '';
    return `<div data-message-id="${Number(m.id)}" class="bubble ${mine ? "me" : ""}"><div class="bubble-top"><span class="bubble-body">${E(m.body)}</span>${menu}</div><small>${date(m.created_at, { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" })}</small></div>`;
  }
  function chatSidebar() {
    const aside = document.querySelector(".chat-sidebar");
    if (!aside) return;
    const markup = S.conversations.map(c => `<button class="chat-person ${Number(c.id) === S.conversation ? "active" : ""}" data-action="conversation" data-id="${Number(c.id)}">${avatar(c.other)}<span><strong>${E(c.other.name)} ${c.unread ? '<span class="tag" aria-label="' + Number(c.unread) + ' sin leer">' + Number(c.unread) + '</span>' : ''}</strong><small>${E(c.preview.slice(0, 42))}</small></span></button>`).join("") || '<p class="private-note">No hay conversaciones para mostrar. Puedes iniciar una desde el directorio.</p>';
    if (aside.innerHTML !== markup) {
      const focused = aside.contains(document.activeElement) ? document.activeElement.dataset.id : null;
      aside.innerHTML = markup;
      if (focused) aside.querySelector(`[data-id="${Number(focused)}"]`)?.focus({ preventScroll: true });
    }
  }
  function chatControls(current) {
    const form = document.querySelector('.chat-compose');
    if (!form) return;
    form.querySelectorAll('textarea, button').forEach(el => { el.disabled = !!current.blocked || (el.tagName === 'BUTTON' && form.dataset.sending === 'true'); });
    const block = document.querySelector('.chat-title [data-action="block"]');
    if (block) { block.textContent = current.blocked_by_me ? 'Desbloquear' : 'Bloquear'; block.dataset.active = String(!current.blocked_by_me); }
  }
  function openChat(chat, current) {
    S.conversation = Number(current.id); chat.id = S.conversation;
    chat.last = 0; chat.loaded = false; chat.ids = new Set();
    document.querySelector('.chat-conversation').innerHTML = `<div class="chat-title">${current.other.profile_url ? `<a class="chat-profile" href="${E(current.other.profile_url)}" aria-label="Ver perfil de ${E(current.other.name)}">${avatar(current.other)}<span><strong>${E(current.other.name)}</strong><small>Ver perfil</small></span></a>` : `<span class="chat-profile">${avatar(current.other)}<strong>${E(current.other.name)}</strong></span>`}${btn(current.blocked_by_me ? "Desbloquear" : "Bloquear", "block", `data-id="${Number(current.other.id)}" data-active="${!current.blocked_by_me}"`, "ghost small")}</div><div class="chat-messages" id="chat-messages" role="log" aria-label="Mensajes de la conversación" aria-live="polite" aria-relevant="additions"></div><form class="chat-compose" data-form="message" data-id="${chat.id}"><textarea name="body" aria-label="Escribir mensaje" placeholder="Escribe un mensaje…" required maxlength="5000"></textarea><button class="btn primary">${I("contact")} Enviar</button></form>`;
    document.querySelector('.chat-compose textarea').value = chatDrafts.get(chat.id) || '';
    chatControls(current); chatSidebar();
  }
  async function messages() {
    const previous = document.querySelector('.chat-compose');
    if (previous) chatDrafts.set(Number(previous.dataset.id), previous.elements.body.value);
    stopChat();
    const chat = S.chat = { active: true, id: 0, pending: null, syncing: false, halted: false };
    const conversations = await api('conversations?' + new URLSearchParams({q: S.filter.q || ''}));
    if (!chatAlive(chat)) return;
    S.conversations = conversations;
    const selected = S.conversation || Number(new URLSearchParams(location.search).get('conversation')) || Number(conversations[0]?.id) || 0;
    let current = conversations.find(c => Number(c.id) === selected);
    if (selected && !current) current = await api('conversations/' + selected);
    if (!chatAlive(chat)) return;
    content().innerHTML = heading('Mensajería', 'Una conversación puede ser el inicio de una gran colaboración.', link('directorio', I('plus') + ' Nueva conversación', 'primary')) + `<div class="chat-sync" id="chat-sync" role="status" hidden></div><form class="filters" data-form="filters"><input name="q" aria-label="Buscar conversaciones" placeholder="Buscar conversaciones…" value="${E(S.filter.q || '')}"><button class="btn">Buscar</button></form><div class="chat-layout"><aside class="chat-sidebar" aria-label="Conversaciones"></aside><section class="chat-conversation">${empty('Inicia una conversación', 'Podrás conversar aquí con tus conexiones confirmadas.')}</section></div>`;
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
      chat.last = Math.max(chat.last, Number(data.after)); chat.loaded = true;
      chat.more = !initial && data.has_more;
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
    area.scrollTop = top + area.scrollHeight - height;
  }
  async function syncChat() {
    const chat = S.chat;
    if (!chatAlive(chat) || chat.syncing || chat.halted) return;
    clearTimeout(S.poll);
    if (document.hidden) return;
    chat.syncing = true;
    let delay = 2000;
    try {
      const conversations = await api('conversations?' + new URLSearchParams({q: S.filter.q || ''}));
      if (!chatAlive(chat)) return;
      S.conversations = conversations;
      if (!chat.id && conversations.length) openChat(chat, conversations[0]);
      let current = conversations.find(c => Number(c.id) === chat.id);
      if (chat.id && !current) current = await api('conversations/' + chat.id);
      if (!chatAlive(chat)) return;
      if (current) chatControls(current);
      await loadMessages(chat);
      if (!chatAlive(chat)) return;
      if (current && !chat.more) current.unread = 0;
      chatSidebar(); chatStatus('');
      if (chat.more) delay = 100;
    } catch (error) {
      if (!chatAlive(chat) || error.name === 'AbortError') return;
      if ([401, 403, 404].includes(error.status)) {
        chat.halted = true;
        const form = document.querySelector('.chat-compose');
        if (form) chatDrafts.set(Number(form.dataset.id), form.elements.body.value);
        const pane = document.querySelector('.chat-conversation');
        if (pane) pane.innerHTML = empty('Conversación no disponible', error.message) + link('directorio', 'Revisar mis conexiones', 'small');
        chatStatus(error.message || 'No se puede acceder a los mensajes. Recarga la página para revisar tu sesión.', true);
      } else { chatStatus('Sin conexión con el servidor. Reintentando…', true); delay = 8000; }
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
    return `<article class="assistant-message assistant" ${id ? `id="${E(id)}"` : ""}>${content}</article>`;
  }
  function assistantLoadingBody(status = "pending") {
    const label = status === "processing" ? T("Consultando la intranet…") : T("Preparando respuesta…");
    return `<div class="assistant-message-meta"><span>${I("spark")} ASCLA</span></div><div class="assistant-thinking"><span></span><span></span><span></span><em>${E(label)}</em></div>`;
  }
  function assistantScroll() {
    const area = document.getElementById("assistant-messages");
    if (area) area.scrollTop = area.scrollHeight;
  }
  async function assistant() {
    const thread = assistantThread();
    const rows = await api("assistant/thread?" + new URLSearchParams({ thread }));
    const suggestions = [
      "¿Hay alguna reunión o evento pronto?",
      "¿Qué se publicó recientemente?",
      "¿Qué recursos tenemos sobre gobierno corporativo?",
      "¿A quién me recomiendas conocer?",
    ];
    content().innerHTML = `<section class="assistant-intro"><div class="assistant-mark">${I("spark")}</div><div class="eyebrow">${E(T("ASISTENTE ASCLA"))}</div><h1>${E(T("Pregunta a tu comunidad ASCLA"))}</h1><p>${E(T("Conversa con el asistente sobre eventos, publicaciones, recursos y conexiones. Primero consulta la información disponible en la propia intranet."))}</p><span class="demo-badge" style="display:inline-block;margin-top:15px">${E(S.boot.ai_mode)}</span></section><div class="ask-suggestions">${suggestions.map(q => btn(E(T(q)) + " " + I("arrow"), "ask-suggestion", `data-question="${E(T(q))}"`)).join("")}</div><section class="assistant-chat-shell"><header class="assistant-chat-head"><div><strong>${E(T("Conversación con ASCLA"))}</strong><span>${E(T("Los datos internos actuales tienen prioridad sobre el historial de la conversación."))}</span></div><div>${btn(I("plus") + " " + T("Nueva conversación"), "assistant-new", "", "ghost small")}${btn(I("clock") + " " + T("Historial"), "answer-history", "", "ghost small")}</div></header><div class="assistant-messages" id="assistant-messages" role="log" aria-live="polite"><article class="assistant-message assistant welcome"><div class="assistant-message-meta"><span>${I("spark")} ASCLA</span></div><div class="assistant-message-copy">${E(T("Hola. Puedo revisar lo que hay en ASCLA y ayudarte a encontrar eventos próximos, publicaciones recientes, recursos y conexiones recomendadas. ¿Qué quieres saber?"))}</div></article>${rows.map(j => {
      const user = assistantUserBubble(j.question);
      if (j.status === "completed" && j.result) return user + assistantBotBubble(assistantAnswerBody(j.result), `assistant-job-${j.id}`);
      if (j.status === "error") return user + assistantBotBubble(`<div class="error">${E(T(j.error || "No se pudo completar la consulta."))} ${btn(T("Reintentar"), "retry-job", `data-id="${j.id}"`, "small")}</div>`, `assistant-job-${j.id}`);
      return user + assistantBotBubble(assistantLoadingBody(j.status), `assistant-job-${j.id}`);
    }).join("")}</div><form class="assistant-compose" data-form="ask"><textarea name="question" aria-label="${E(T("Tu pregunta"))}" placeholder="${E(T("Escribe una pregunta sobre ASCLA…"))}" required maxlength="2000" rows="2"></textarea><button class="btn primary" aria-label="${E(T("Enviar pregunta"))}">${I("arrow")} <span>${E(T("Enviar"))}</span></button></form><p class="private-note assistant-grounding-note">${E(T("El asistente prioriza la información que tu cuenta puede consultar en ASCLA. Si un dato no puede verificarse dentro de la intranet, te lo indicará."))}</p></section>`;
    rows.filter(j => ["pending", "processing"].includes(j.status)).forEach(j => {
      const target = document.getElementById(`assistant-job-${j.id}`);
      if (target) watchJob(j.id, target, result => { target.innerHTML = assistantAnswerBody(result); assistantScroll(); });
    });
    assistantScroll();
  }
  async function watchJob(id, target, onDone) {
    let attempts = 0;
    const assistantMode = target.classList?.contains("assistant-message");
    target.innerHTML = assistantMode ? assistantLoadingBody("pending") :
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
          target.innerHTML = `<div class="error">${E(j.error)} ${btn("Reintentar", "retry-job", 'data-id="' + id + '"', "small")}</div>`;
          return;
        }
        target.innerHTML = assistantMode ? assistantLoadingBody(j.status) : `<div class="alert">${I("clock")} ${j.status === "processing" ? "Procesando" : "Pendiente"} · Trabajo #${id}</div>`;
        if (++attempts < 90) setTimeout(poll, 2500);
        else
          target.innerHTML = assistantMode ? `<div class="assistant-message-meta"><span>${I("spark")} ASCLA</span></div><div class="assistant-message-copy">${E(T("La consulta sigue procesándose. Puedes volver al asistente más tarde para ver la respuesta."))}</div>` :
            '<div class="alert">El trabajo continúa en segundo plano. Recibirás una notificación cuando esté listo.</div>';
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
  function notificationCount(total) {
    const badge = document.querySelector(".notification-count"), bell = document.querySelector('[data-action="notifications"]');
    if (badge) { badge.hidden = !total; badge.textContent = total > 99 ? "99+" : total; }
    bell?.setAttribute("aria-label", T("Notificaciones") + (total ? `, ${total} ${T("sin leer")}` : `, ${T("estás al día")}`));
  }
  async function refreshNotifications() {
    try { notificationCount((await api("notifications/summary")).unread_total); } catch {}
  }
  async function notifications() {
    const feed = await api("notifications/feed?" + new URLSearchParams({ filter: S.noticeFilter, page: S.noticePage }));
    S.noticePage = feed.page;
    notificationCount(feed.unread_total);
    const html = window.ASCLANotifications({ E, I, btn, T, locale: C.locale || "es-PE" }).panel(feed, S.noticeFilter);
    const existing = document.querySelector(".notification-modal .modal-content");
    if (existing) { existing.innerHTML = html; document.querySelector(`.activity-filters [data-filter="${S.noticeFilter}"]`)?.focus(); }
    else { modal(T("Tus notificaciones"), html); document.querySelector(".modal").classList.add("notification-modal"); S.focus = document.querySelector('[data-action="notifications"]'); }
  }
  function answerHTML(question, r) {
    return assistantUserBubble(question) + assistantBotBubble(assistantAnswerBody(r));
  }
  function answerTarget() {
    const area = document.getElementById("assistant-messages");
    const target = document.createElement("article"); target.className = "assistant-message assistant";
    (area || document.getElementById("answers") || content()).append(target); return target;
  }
  async function restoreAnswer(id) {
    const j = await api("jobs/" + id);
    if (j.kind !== "answer") { toast("Este aviso corresponde a una tarea de administración."); return; }
    if (j.thread) {
      assistantThread(false, j.thread);
      await assistant();
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
    const rows = await api("answers");
    modal(T("Mis consultas anteriores"), `<p class="private-note">${E(T("Tus últimas 50 consultas, guardadas para volver a ellas cuando las necesites."))}</p>${rows.map(j => {
      const url = new URL(C.pages.asistente.url); url.searchParams.set("job", j.id);
      const status = T({ completed: "Respuesta lista", pending: "Pendiente", processing: "Preparando respuesta", error: "Necesita atención" }[j.status] || j.status);
      return `<a class="answer-history-entry" href="${E(url.href)}"><span>${I("spark")}</span><span><strong>${E(j.question)}</strong><small>${E(status)} · ${date(j.created_at)}</small></span>${I("arrow")}</a>`;
    }).join("") || empty("Aún no tienes consultas", "Haz tu primera pregunta al asistente para empezar.")}`);
  }
  const requestLabels = {open: "Recibida", progress: "En atención", closed: "Resuelta"};
  function adminPager(list, area) {
    if (list.pages < 2) return '';
    return `<div class="admin-pager">${btn("Anterior", "admin-page", `data-area="${area}" data-page="${list.page-1}" ${list.page===1?'disabled':''}`, "small")}<span>Página ${list.page} de ${list.pages}</span>${btn("Siguiente", "admin-page", `data-area="${area}" data-page="${list.page+1}" ${list.page===list.pages?'disabled':''}`, "small")}</div>`;
  }
  async function adminContacts(panel) {
    const f=S.adminFilters.contacts, list=await api('admin/contacts?'+new URLSearchParams(f));
    panel.innerHTML=`<div class="admin-section-heading"><div><span class="eyebrow">ATENCIÓN A LA COMUNIDAD</span><h2>Solicitudes</h2><p>Revisa cada caso y registra su avance. El asociado verá el estado actualizado.</p></div><span class="admin-total">${list.total} resultados</span></div><div class="request-stats">${Object.entries(requestLabels).map(([k,label])=>btn(`<strong>${list.counts[k]}</strong><span>${label}</span>`, 'request-filter', `data-state="${k}" aria-pressed="${f.state===k}"`, 'request-stat '+k+(f.state===k?' selected':''))).join('')}</div><form class="filters admin-filters" data-form="admin-filter" data-area="contacts"><input name="q" aria-label="Buscar solicitudes" placeholder="Buscar por asunto o contenido…" value="${E(f.q||'')}">${select('state','Estado',[['','Todos los estados'],...Object.entries(requestLabels)],f.state||'')}<button class="btn primary">${I('search')} Buscar</button></form><div class="request-list">${list.items.map(p=>{
      const state=p.meta.request_status||'open';
      return `<article class="request-card ${E(state)}" data-contact="${p.id}" data-state="${E(state)}"><div class="request-card-top"><span class="request-number">SOLICITUD #${p.id}</span><span class="request-status ${E(state)}">${E(requestLabels[state])}</span></div><h3>${E(p.title)}</h3><div class="request-author">${I('users')}<strong>${E(p.author.name)}</strong><span>· ${date(p.date)}</span>${p.meta.description?`<span class="tag">${E(p.meta.description)}</span>`:''}</div><p class="request-body">${E(p.body)}</p><div class="request-footer"><div><small>Cambiar estado</small><div class="request-actions">${Object.entries(requestLabels).map(([key,label])=>btn((state===key?'✓ ':'')+label,'contact-status',`data-id="${p.id}" data-status="${key}" ${state===key?'disabled aria-pressed="true"':'aria-pressed="false"'}`,'small '+(state===key?'selected':''))).join('')}</div></div>${p.meta.request_updated_at?`<small>Actualizada ${date(p.meta.request_updated_at,{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'})}</small>`:''}</div>${p.meta.request_history?.length?`<details class="request-history"><summary>Historial de atención</summary><ol>${p.meta.request_history.slice().reverse().map(h=>`<li><strong>${E(requestLabels[h.to])}</strong><span>${E(h.actor)} · ${date(h.at,{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'})}</span></li>`).join('')}</ol></details>`:''}</article>`;
    }).join('')||empty('No hay solicitudes en esta vista','Prueba otro estado o modifica la búsqueda.')}</div>${adminPager(list,'contacts')}`;
  }
  async function adminUsers(panel) {
    const f=S.adminFilters.users,list=await api('admin/users?'+new URLSearchParams(f));
    const roles=Object.fromEntries(list.roles.map(r=>[r.id,r.name]));
    panel.innerHTML=`<div class="admin-section-heading"><div><span class="eyebrow">PERSONAS Y ACCESOS</span><h2>Usuarios de la comunidad</h2><p>Encuentra cuentas, consulta sus roles y administra su acceso.</p></div>${list.create_url?`<a class="btn primary" data-native href="${E(list.create_url)}">${I('plus')} Añadir usuario</a>`:''}</div><form class="filters admin-filters" data-form="admin-filter" data-area="users"><input name="q" aria-label="Buscar usuarios" placeholder="Nombre, usuario o correo…" value="${E(f.q||'')}">${select('role','Rol',[['','Todos los roles'],...list.roles.map(r=>[r.id,r.name])],f.role||'')}${select('state','Acceso',[['','Todos'],['active','Activo'],['suspended','Suspendido']],f.state||'')}<button class="btn primary">${I('search')} Buscar</button></form><p class="private-note">${list.total} usuarios encontrados</p><div class="admin-users">${list.items.map(u=>`<article class="admin-user" data-admin-user="${u.id}"><div class="admin-user-person">${avatar(u)}<div><h3>${E(u.name)}</h3><span>@${E(u.login)}</span><a href="mailto:${E(u.email)}">${E(u.email)}</a></div></div><div class="admin-user-access">${u.roles.map(r=>`<span class="tag">${E(roles[r]||r)}</span>`).join('')}<span class="request-status ${u.suspended?'closed':'open'}">${u.suspended?'Acceso suspendido':'Acceso activo'}</span><small>Registro: ${date(u.registered)}</small></div><div class="admin-user-actions">${u.profile_url?`<a class="btn small" href="${E(u.profile_url)}">Ver perfil</a>`:''}${u.edit_url?`<a class="btn small" data-native href="${E(u.edit_url)}">Editar cuenta</a>`:''}${u.can_suspend?btn(u.suspended?'Reactivar acceso':'Suspender acceso','user-access',`data-id="${u.id}" data-suspended="${!u.suspended}" data-name="${E(u.name)}"`,'ghost small'):''}</div></article>`).join('')||empty('No encontramos usuarios','Prueba otro nombre o cambia los filtros.')}</div>${adminPager(list,'users')}`;
  }
  async function admin() {
    const d = await api("admin"); S.admin=d;
    const tab=S.adminTab;
    const tabs=[["moderacion","Moderación","shield"],["solicitudes","Solicitudes","contact"],...(S.boot.admin?[["usuarios","Usuarios","users"],["archivos","Archivos","book"]]:[]),["trabajos","IA y trabajos","spark"],["microeventos","Microeventos","calendar"],["logs","Auditoría","clock"],...(S.boot.admin?[["configuracion","Configuración","settings"]]:[])];
    content().innerHTML=`<div class="admin-workspace"><header class="admin-hero"><div class="admin-hero-main"><div class="admin-hero-logo"><img src="${E(C.logoWhite || C.logo)}" alt="ASCLA"></div><div class="admin-hero-copy"><span class="eyebrow">${E(T("GESTIÓN DE LA COMUNIDAD"))}</span><h1>${E(T("Administración ASCLA"))}</h1><p>${E(T("Personas, contenido y atención en un mismo lugar."))}</p></div></div><div class="admin-hero-actions">${link('intranet',E(T('Ver intranet'))+' '+I('arrow'),'ghost')}</div></header><div class="admin-overview">${[[d.counts.members,'Miembros','users',S.boot.admin?'usuarios':'moderacion'],[d.pending.length,'Contenidos por revisar','shield','moderacion'],[d.jobs.filter(j=>['pending','processing'].includes(j.status)).length,'Trabajos activos','spark','trabajos'],[d.reports.filter(r=>!r.reviewed).length,'Reportes por revisar','bell','moderacion']].map(([n,label,icon,target])=>btn(`<span class="stat-icon">${I(icon)}</span><span class="admin-stat-copy"><strong>${n}</strong><small>${E(T(label))}</small></span><span class="admin-stat-arrow">${I('chevron')}</span>`,'admin-tab',`data-tab="${target}" aria-label="${E(T(label))}"`,'admin-stat')).join('')}</div><nav class="admin-tabs" aria-label="${E(T("Secciones de administración"))}">${tabs.map(([key,label,icon])=>btn(I(icon)+E(T(label)),'admin-tab',`data-tab="${key}" aria-pressed="${tab===key}"`,'admin-tab'+(tab===key?' active':''))).join('')}</nav><section id="admin-panel" class="admin-panel"></section></div>`;
    const panel=document.getElementById('admin-panel');
    if(tab==='solicitudes') await adminContacts(panel);
    else if(tab==='usuarios' && S.boot.admin) await adminUsers(panel);
    else if(tab==='archivos' && S.boot.admin) panel.innerHTML=`<div class="admin-section-heading"><div><span class="eyebrow">${E(T('BIBLIOTECA PRIVADA'))}</span><h2>${E(T('Archivos de la comunidad'))}</h2><p>${E(T('Consulta los archivos privados de todos los asociados y elimina los que corresponda.'))}</p></div></div><div class="card admin-feature-card"><div class="admin-feature-icon">${I('book')}</div><div><h3>${E(T('Biblioteca administrativa'))}</h3><p class="detail-body">${E(T('Busca por archivo o propietario y revisa el material privado almacenado en ASCLA.'))}</p></div>${btn('Administrar archivos','files','data-scope="all"','primary')}</div>`;
    else if(tab==='moderacion') panel.innerHTML=`<div class="admin-section-heading"><div><span class="eyebrow">CALIDAD Y CONVIVENCIA</span><h2>Revisión de contenido</h2><p>Los foros se publican directamente. Galería, Eventos y Conocimiento los gestiona el Ejecutivo o un administrador.</p></div></div><div class="card"><h3>Contenido pendiente y borradores</h3><div class="table-wrap"><table class="data-table"><thead><tr><th>Contenido</th><th>Autor</th><th>Estado</th><th>Acción</th></tr></thead><tbody>${d.pending.map(p=>`<tr><td><strong>${E(p.title)}</strong><br><small>${E(typeLabel[p.type])}${p.meta.generated?' · IA':''}${p.meta.chatham?' · Chatham House':''}</small></td><td>${E(p.author.name)}</td><td>${status(p.status)}</td><td>${btn('Revisar','item',`data-id="${p.id}"`,'small')}${!S.boot.admin&&!S.boot.executive&&['gallery','resource'].includes(p.type)?'<small>Publicación administrativa</small>':''}</td></tr>`).join('')||'<tr><td colspan="4">Todo al día. No hay contenido pendiente.</td></tr>'}</tbody></table></div></div><div class="admin-review-grid"><div class="card"><h3>Reportes de la comunidad</h3>${d.reports.map(r=>`<div class="admin-report ${r.reviewed?'is-reviewed':''}"><div><div class="admin-report-heading"><strong>${E(r.title || `Publicación #${r.target_id}`)}</strong><span class="tag ${r.reviewed?'success':''}">${E(T(r.reviewed?'Revisado':'Pendiente'))}</span></div>${r.excerpt ? `<p>${E(r.excerpt)}</p>` : ''}<p class="private-note"><b>Motivo:</b> ${E(r.reason_label || 'Sin motivo registrado')}${r.detail ? `<br><b>Detalle:</b> ${E(r.detail)}` : ''}${r.reviewed && r.reviewed_by_name ? `<br><b>${E(T('Revisado por:'))}</b> ${E(r.reviewed_by_name)}` : ''}</p></div><div class="form-actions">${Number(r.target_id) ? btn('Abrir contenido','item',`data-id="${r.target_id}"`,'small') : ''}${!r.reviewed && S.boot.moderator ? btn('Marcar como revisado','report-reviewed',`data-id="${r.id}"`,'primary small') : ''}</div></div>`).join('')||'<p class="private-note">'+E(T('No hay reportes registrados.'))+'</p>'}</div><div class="card"><h3>Comentarios pendientes</h3>${d.comments.map(c=>`<div class="comment"><strong>${E(c.author)}</strong><p>${E(c.body)}</p>${btn('Aprobar','comment-moderate',`data-id="${c.id}" data-decision="approve"`,'small')}${btn('Mantener oculto','comment-moderate',`data-id="${c.id}" data-decision="reject"`,'small')}${c.can_delete?btn('Eliminar comentario','delete-comment',`data-id="${c.id}"`,'danger small'):''}</div>`).join('')||'<p class="private-note">No hay comentarios pendientes.</p>'}</div></div>`;
    else if(tab==='trabajos') panel.innerHTML=`<div class="admin-section-heading"><div><span class="eyebrow">PROCESAMIENTO Y RESULTADOS</span><h2>IA y trabajos</h2><p>Consulta el avance, abre resultados y reintenta los trabajos con error.</p></div></div><div class="alert">Los derivados de IA quedan en borrador para revisión.</div><div class="admin-actions">${link('centro-conocimiento','Ver recursos','primary')}${btn('Curaduría social demo','social-job')}${S.boot.admin?btn('Conectar YouTube OAuth','google-connect','data-service="youtube"'):''}${btn('Actualizar estados','admin-refresh')}</div><div class="card table-wrap"><table class="data-table"><thead><tr><th>Trabajo</th><th>Estado</th><th>Detalle</th><th>Acción</th></tr></thead><tbody>${d.jobs.map(j=>`<tr><td><strong>#${j.id}</strong><br>${E(j.kind)}</td><td>${status(j.status)}</td><td>${E(j.error||date(j.created_at))}</td><td>${btn('Ver','job-detail',`data-id="${j.id}"`,'small')}${j.status==='error'?btn('Reintentar','retry-job',`data-id="${j.id}"`,'small'):''}</td></tr>`).join('')||'<tr><td colspan="4">No hay trabajos registrados.</td></tr>'}</tbody></table></div>`;
    else if(tab==='microeventos') panel.innerHTML=`<div class="admin-section-heading"><div><span class="eyebrow">ENCUENTROS ENTRE ASOCIADOS</span><h2>Círculos de conversación</h2><p>Grupos de 4 a 6 personas, con intereses comunes y una agenda para conversar.</p></div></div><div class="card"><h3>Preparar los encuentros del mes</h3><p class="detail-body">Se consideran el consentimiento y el historial de grupos. Revisa las propuestas y ajusta fecha y agenda antes de publicar.</p><div class="admin-actions">${S.boot.admin?btn(I('spark')+' Preparar propuesta del mes','micro-job','','primary'):''}${link('eventos','Ver encuentros','small')}</div><div id="micro-job-result"></div></div>`;
    else if(tab==='logs') {
      const auditRows=d.audit||[];
      const auditActors=new Set(auditRows.map(a=>Number(a.actor_id)).filter(Boolean)).size;
      const latest=auditRows[0]?.created_at||'';
      const auditLabels={settings_updated:'Configuración actualizada',content_saved:'Contenido guardado',content_trashed:'Contenido eliminado',comment_trashed:'Comentario eliminado',content_reported:'Contenido reportado',comment_reported:'Comentario reportado',report_reviewed:'Reporte revisado',moderation:'Decisión de moderación',comment_moderation:'Comentario moderado',member_suspended:'Acceso suspendido',member_reactivated:'Acceso reactivado',contact_status:'Solicitud actualizada',event_registration:'Inscripción a evento',event_invited:'Invitaciones a evento',connection_requested:'Solicitud de conexión',connection_accepted:'Conexión aceptada',connection_rejected:'Conexión rechazada',connection_cancelled:'Solicitud de conexión cancelada',connection_removed:'Conexión eliminada',message_deleted:'Mensaje eliminado',password_changed:'Contraseña cambiada',password_reset_requested:'Recuperación solicitada',media_deleted:'Archivo eliminado',oauth_connected:'Servicio Google conectado',oauth_disconnected:'Servicio Google desconectado',calendar_create:'Evento añadido a Google Calendar',calendar_delete:'Evento retirado de Google Calendar',turnstile_passed:'Verificación de seguridad superada',turnstile_failed:'Verificación de seguridad fallida',turnstile_error:'Error de verificación de seguridad',request_failed:'Solicitud con error',job_completed:'Trabajo en segundo plano completado',job_failed:'Trabajo en segundo plano con error',ai_generated:'Contenido asistido por IA creado'};
      const actionName=a=>auditLabels[a]||String(a||'Actividad del sistema').replaceAll('_',' ');
      panel.innerHTML=`<div class="admin-section-heading"><div><span class="eyebrow">TRAZABILIDAD Y CONTROL</span><h2>Auditoría</h2><p>Este registro ayuda a entender qué cambios importantes ocurrieron en ASCLA, quién los realizó y sobre qué elemento actuaron.</p></div><span class="admin-total">${auditRows.length} registros</span></div><div class="audit-guide"><article>${I('clock')}<div><strong>¿Qué registra?</strong><p>Acciones relevantes como cambios de configuración, moderación, accesos, reportes, archivos e integraciones.</p></div></article><article>${I('users')}<div><strong>¿Para qué sirve?</strong><p>Permite revisar el historial cuando necesitas saber quién hizo un cambio o investigar un problema.</p></div></article><article>${I('shield')}<div><strong>¿Qué no guarda?</strong><p>No almacena contraseñas ni el contenido de mensajes privados. Solo registra metadatos de la acción.</p></div></article></div><div class="audit-summary"><article class="audit-summary-card"><span class="audit-summary-icon">${I('clock')}</span><div><small>Acciones registradas</small><strong>${auditRows.length}</strong></div></article><article class="audit-summary-card"><span class="audit-summary-icon">${I('users')}</span><div><small>Personas identificadas</small><strong>${auditActors}</strong></div></article><article class="audit-summary-card wide"><span class="audit-summary-icon">${I('shield')}</span><div><small>Última actividad</small><strong>${latest?E(date(latest,{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'})):'Sin actividad'}</strong></div></article></div><div class="card audit-card"><div class="audit-card-head"><div><h3>Historial de actividad</h3><p class="private-note">Los eventos más recientes aparecen primero. “Elemento” identifica el registro afectado cuando corresponde.</p></div><span class="audit-privacy">${I('shield')} Registro protegido</span></div><div class="table-wrap"><table class="data-table audit-table"><thead><tr><th>Cuándo</th><th>Qué ocurrió</th><th>Quién</th><th>Elemento</th><th>Detalle técnico</th></tr></thead><tbody>${auditRows.map(a=>`<tr><td><time class="audit-time" datetime="${E(a.created_at)}" title="${E(a.created_at)}">${E(date(a.created_at,{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'}))}</time></td><td><span class="audit-action">${E(actionName(a.action))}</span></td><td><span class="audit-actor">${E(a.actor_name||'Sistema')}</span></td><td><span class="audit-object">${a.object_id?'#'+Number(a.object_id):'—'}</span></td><td class="audit-detail">${E(a.detail||'—')}</td></tr>`).join('')||'<tr><td colspan="5"><div class="audit-empty">No hay acciones registradas todavía.</div></td></tr>'}</tbody></table></div></div>`;
    }
    else if(tab==='configuracion' && S.boot.admin) await settings(panel);
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
      panel.querySelectorAll('input,select,textarea').forEach(control=>{ control.disabled=!active; });
    });
    const test=form.querySelector('[data-ai-test-actions]');
    if (test) test.hidden=provider==='mock';
  }
  function mailSettings(s) {
    const local = s.mail_local ? '<div class="alert">Buzón local activo: los correos se consultan en <a href="http://localhost:8025/" target="_blank" rel="noopener">Abrir buzón de pruebas</a>. No llegan a una bandeja externa.</div>' : '';
    const last = s.mail_last_result;
    const body=`${local}<p class="private-note">Usa el servicio de correo de tu hosting o un proveedor SMTP. Si otro plugin ya gestiona los envíos, conserva “Transporte de WordPress”. Guarda los cambios antes de enviar una prueba a tu correo de administrador.</p><div class="form-grid">${select('mail_mode', 'Envío de correos', [['wordpress', 'Transporte de WordPress / otro plugin'], ['smtp', 'Servidor SMTP']], s.mail_mode)}${field('smtp_host', 'Servidor SMTP', s.smtp_host, 'text', 'placeholder="smtp.tuproveedor.com" autocomplete="off"')}${select('smtp_port', 'Puerto', [[587,'587'],[465,'465'],[2525,'2525']], s.smtp_port)}${select('smtp_security', 'Cifrado', [['tls', 'STARTTLS (587 / 2525)'],['ssl', 'SSL/TLS (465)']], s.smtp_security)}${field('smtp_user','Usuario SMTP',s.smtp_user,'text','autocomplete="off"')}${field('smtp_password', s.has_smtp_password ? 'Contraseña SMTP (guardada; vacío para conservar)' : 'Contraseña SMTP', '', 'password', 'autocomplete="new-password"')}${field('smtp_from','Correo remitente autorizado',s.smtp_from,'email')}${field('smtp_name','Nombre del remitente',s.smtp_name)}</div>${check('clear_smtp_password','Eliminar contraseña SMTP guardada',false)}<p class="private-note">La contraseña se guarda cifrada. Para eliminarla, cambia primero al transporte de WordPress. ${last ? 'Último intento: ' + E(last.status === 'accepted' ? 'aceptado por el transporte' : 'falló el envío') + ' · ' + E(date(last.at, {day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'})) : 'Aún no hay intentos registrados.'}</p>${btn('Enviar correo de prueba a mi cuenta','mail-test','','small')}`;
    return settingsSection('contact','Correo y recuperación','Configura la salida de correos, recuperación de contraseña y pruebas de entrega.',body,true);
  }
  async function settings(panel) {
    const s = await api("settings");
    const participation=settingsSection('users','Participación y revisión','Define cómo se publica, modera y organiza la participación dentro de la comunidad.',`${check("demo", "Modo demo (datos e integraciones identificados)", s.demo)}${check("moderation_required", "Revisar publicaciones del Hub antes de publicarlas", s.moderation_required)}${check("moderate_comments", "Revisar comentarios del Hub y otras secciones (excepto Foros)", s.moderate_comments)}${check("chatham_default", "Aplicar Chatham House por defecto", s.chatham_default)}${check("micro_enabled", "Preparar microeventos mensualmente con WP-Cron", s.micro_enabled)}${check("micro_approval", "Exigir aprobación administrativa de microeventos", s.micro_approval)}`,true);
    const security=settingsSection('shield','Seguridad','Protección adaptativa del acceso y formularios públicos sin interrumpir a usuarios legítimos.',`${check("turnstile_enabled", "Activar Cloudflare Turnstile", s.turnstile_enabled)}<p class="private-note">El widget debe crearse en Cloudflare con modo <strong>Managed</strong>. ASCLA valida cada token en el servidor y recuerda durante 24 horas los navegadores/IP que ya superaron una verificación.</p><div class="form-grid">${field("turnstile_site_key", "Turnstile Site Key", s.turnstile_site_key || "", "text", 'autocomplete="off" placeholder="0x4AAAA..."')}${field("turnstile_secret", s.has_turnstile_secret ? "Turnstile Secret Key (guardada; vacío para conservar)" : "Turnstile Secret Key", "", "password", 'autocomplete="new-password"')}</div>${check("clear_turnstile_secret", "Eliminar Turnstile Secret Key guardada", false)}<div class="turnstile-protection-options"><strong>Formularios protegidos</strong>${check("turnstile_login", "Inicio de sesión · mostrar después de 3 fallos; bloqueo temporal desde 5", s.turnstile_login)}${check("turnstile_recovery", "Recuperación de contraseña · exigir después de 2 solicitudes seguidas", s.turnstile_recovery)}${check("turnstile_public", "Otros formularios públicos ASCLA · activar solo ante señales sospechosas o demasiados envíos", s.turnstile_public)}</div><p class="private-note">Los intentos se contabilizan principalmente por IP + usuario/correo para reducir bloqueos injustos en redes compartidas. Los usuarios autenticados nunca ven Turnstile.</p>`,true);
    const matching=settingsSection('spark','Motor de afinidad','Define el umbral mínimo que debe alcanzar una coincidencia antes de mostrarse como recomendación.',`<div class="matching-threshold-setting">${field("matching_min_affinity", "Afinidad mínima para recomendar (%)", s.matching_min_affinity ?? 30, "number", 'min="0" max="100" step="1"')}</div><p class="private-note">${E(T("Solo aparecerán como personas recomendadas los perfiles que alcancen al menos este porcentaje de afinidad. Valor predeterminado: 30%."))}</p>`);
    const ai=settingsSection('spark','Inteligencia artificial','Elige un único proveedor activo. ASCLA utilizará solo ese proveedor para redactar respuestas y sugerencias.',`<div class="ai-provider-config">${select("ai_provider","Proveedor activo",[["mock","DEMO MODE · sin API"],["gemini","Google Gemini · API real"],["openai","OpenAI / ChatGPT · API real"]],s.ai_provider)}<div class="ai-provider-panel" data-ai-provider-panel="mock"><div class="alert">Modo de demostración: ASCLA usa respuestas simuladas y no envía información a un proveedor externo.</div></div><div class="ai-provider-panel" data-ai-provider-panel="gemini"><div class="form-grid">${field("ai_model", "ID del modelo Gemini", s.ai_model, "text", 'placeholder="gemini-2.5-flash" autocomplete="off"')}${field("ai_key", s.has_ai_key ? "Gemini API Key (guardada; vacío para conservar)" : "Gemini API Key", "", "password", 'autocomplete="new-password"')}</div>${check("clear_ai_key", "Eliminar Gemini API Key guardada", false)}</div><div class="ai-provider-panel" data-ai-provider-panel="openai"><div class="form-grid">${field("openai_model", "ID del modelo OpenAI", s.openai_model || "gpt-5.6-luna", "text", 'placeholder="gpt-5.6-luna" autocomplete="off"')}${field("openai_key", s.has_openai_key ? "OpenAI API Key (guardada; vacío para conservar)" : "OpenAI API Key", "", "password", 'autocomplete="new-password"')}</div>${check("clear_openai_key", "Eliminar OpenAI API Key guardada", false)}</div><div class="ai-secondary-setting">${select("youtube_mode","Transcripciones YouTube",[["mock","DEMO MODE / transcripción manual"],["real","YouTube OAuth real"]],s.youtube_mode)}</div><p class="private-note">Las claves permanecen protegidas en el servidor. Guarda la configuración antes de probar la conexión. Los datos internos autorizados de ASCLA se preparan antes de consultar al proveedor activo.</p><div class="settings-actions-row" data-ai-test-actions>${btn("Probar conexión", "ai-test", "", "small")}<p id="ai-test-result" class="private-note" role="status" aria-live="polite"></p></div></div>`,true);
    const google=settingsSection('calendar','Google OAuth','Credenciales para que cada asociado conecte servicios autorizados de Google desde su cuenta.',`<div class="form-grid">${field("google_client_id", "Client ID", s.google_client_id)}${field("google_client_secret", s.has_google_secret ? "Client Secret (configurado)" : "Client Secret", "", "password", 'autocomplete="new-password"')}</div><div class="alert settings-code-alert"><span>URI de redirección</span><code>${E(s.google_redirect)}</code></div><p class="private-note">Cada asociado conecta su calendario desde Perfil. YouTube se conecta desde IA y trabajos.</p>`);
    const social=settingsSection('contact','Social Listening','Estado de conectores sociales y restricciones de integración externa.',`<div class="alert">LinkedIn y X permanecen en DEMO MODE. Los adaptadores requieren aprobación, permisos y planes oficiales; ASCLA no realiza scraping ni envía respuestas externas.</div>`);
    const legal=settingsSection('book','Identidad y propiedad intelectual','Texto institucional mostrado en las áreas correspondientes de la intranet.',`${field("copyright", "Propiedad intelectual", s.copyright)}`);
    panel.innerHTML=`<div class="admin-section-heading"><div><span class="eyebrow">PREFERENCIAS Y SERVICIOS</span><h2>Configuración</h2><p>Gestiona las reglas de comunidad, recomendaciones, seguridad e integraciones. Cada bloque agrupa opciones relacionadas para evitar configuraciones ambiguas.</p></div><span class="admin-config-status">${I('shield')} Cambios protegidos</span></div><form class="admin-settings" data-form="settings" data-guard-unsaved><div class="settings-grid">${participation}${matching}${social}${security}${ai}${google}${legal}${mailSettings(s)}</div><div class="settings-savebar"><div><strong>Configuración de ASCLA</strong><span>Los cambios se aplican al guardar.</span></div><button class="btn primary">Guardar configuración</button></div></form><form class="card section-gap demo-settings-card" data-form="demo"><div class="admin-feature-icon">${I('spark')}</div><div><h2>Preparar datos de demostración</h2><p class="private-note">Crea 18 perfiles y 9 empresas ficticias. Las siguientes ejecuciones conservan datos y contraseñas existentes.</p>${field("password", "Contraseña para nuevas cuentas demo", "", "password", 'required minlength="12" autocomplete="new-password"')}</div><button class="btn">Crear / completar demo</button></form>`;
    const settingsForm=panel.querySelector('[data-form="settings"]');
    syncAIProvider(settingsForm);
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
            `<h3 style="margin-top:18px">${E(t)}</h3><p class="private-note">${E(b)}</p>`,
        )
        .join("")}`,
    );
  }
  async function render() {
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
    S.controller.abort(); S.controller = new AbortController(); S.viewVersion++;
    stopChat(); closeModal(); document.querySelector('.toast')?.remove();
    S.page = page; S.filter = {}; S.item = null; S.conversation = 0; S.messagesLoaded = false;
    const q = new URLSearchParams(location.search); if (q.has('q')) S.filter.q = q.get('q');
    root.querySelector('.ascla-sidebar')?.classList.remove('open');
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
    navigation = window.ASCLANavigation({root, pages: C.pages, prepare: prepareView,
      beforeNavigate: () => confirmUnsavedExit(),
      load: async () => { const version = S.viewVersion; await render(); if (version === S.viewVersion) await routeDetails(); },
      error: error => { if (error.name !== 'AbortError') toast(error.message); }
    });
  }
  // WordPress admin links live outside #ascla-root. When Configuración is dirty,
  // intercept same-tab navigation so ASCLA can show its own confirmation dialog.
  document.addEventListener("click", async (event) => {
    if (C.page !== "admin" || !hasUnsavedChanges()) return;
    const anchor = event.target.closest?.("a[href]");
    if (!anchor || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (anchor.target === "_blank" || anchor.hasAttribute("download")) return;
    let destination;
    try { destination = new URL(anchor.href, location.href); } catch { return; }
    if (!["http:", "https:"].includes(destination.protocol)) return;
    if (destination.href === location.href) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (await confirmUnsavedExit()) location.assign(destination.href);
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
    const input = event.target.closest?.('.assistant-compose textarea[name="question"]');
    if (!input || event.key !== "Enter" || event.shiftKey || event.isComposing) return;
    event.preventDefault();
    input.form?.requestSubmit();
  });

  root.addEventListener("click", async (event) => {
    const b = event.target.closest("[data-action]");
    if (!b) return;
    if (b.dataset.action === "notification-open" && (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey)) return;
    event.preventDefault();
    const a = b.dataset.action,
      id = Number(b.dataset.id || 0);
    b.disabled = true;
    try {
      if (a === "close") closeModal();
      else if (a === "profile-nudge-later") closeModal();
      else if (a === "profile-nudge-go") { closeModal(); await navigateTo(C.pages.perfil.url); }
      else if (a === "unsaved-stay") resolveUnsavedExit(false);
      else if (a === "unsaved-discard") resolveUnsavedExit(true);
      else if (a === "theme-menu") themeDialog();
      else if (a === "theme-set") { applyTheme(b.dataset.theme || "system", true); closeModal(); toast(T("Apariencia actualizada.")); }
      else if (a === "menu")
        document.querySelector(".ascla-sidebar").classList.toggle("open");
      else if (a === "refresh") { if (await confirmUnsavedExit()) await render(); }
      else if (a === "rules") rules();
      else if (a === "member") await member(id);
      else if (a === "item") await item(id);
      else if (a === "files") await files(Number(b.dataset.page) || 1, S.files?.q || "", b.dataset.scope || S.files?.scope || "mine", S.files?.owner || 0);
      else if (a === "detach-media") b.closest('[data-media]').remove();
      else if (a === "delete-content") { const p = await api('items/' + id); confirmDeletion('content',id,{title:p.title,forum:p.type==='forum'}); }
      else if (a === "delete-comment") confirmDeletion('comment',id,{post:Number(b.dataset.post)||0});
      else if (a === "delete-message") confirmDeletion('message',id,{conversation:Number(b.dataset.conversation)||S.conversation});
      else if (a === "comment-reply") openCommentReply(b);
      else if (a === "comment-reply-cancel") b.closest('.comment-reply-form')?.remove();
      else if (a === "report-comment") reportDialog(id, "comment");
      else if (a === "comment-like") {
        const r = await api(`comments/${id}/reaction`, {active:b.dataset.active === 'true'});
        b.dataset.active=String(!r.active); b.setAttribute('aria-pressed',String(!!r.active)); b.setAttribute('aria-label',T(r.active?'Quitar Me gusta':'Me gusta'));
        b.classList.toggle('active',!!r.active); const count=b.querySelector('span'); if(count) count.textContent=String(r.likes);
      }
      else if (a === "delete-media") confirmDeletion('media',id,{post:Number(b.dataset.post)||0,title:b.dataset.name,library:b.dataset.library==='true'});
      else if (a === "delete-cancel") {
        if(b.dataset.kind==='message') closeModal();
        else if(b.dataset.library==='true') await files(S.files.page,S.files.q,S.files.scope,S.files.owner||0);
        else if(Number(b.dataset.post)) await item(Number(b.dataset.post));
        else if(b.dataset.kind==='content') await item(id);
        else closeModal();
      }
      else if (a === "delete-confirm") {
        const kind=b.dataset.kind, post=Number(b.dataset.post), library=b.dataset.library==='true', conversation=Number(b.dataset.conversation)||0;
        const endpoint=kind==='content'?'items/'+id:kind==='comment'?'comments/'+id:kind==='message'?`conversations/${conversation}/messages/${id}`:'media/'+id;
        await api(endpoint,{},'DELETE');
        closeModal();toast(T('Eliminado correctamente.'));
        if(kind==='message') {
          document.querySelector(`[data-message-id="${id}"]`)?.remove();
          S.chat?.ids?.delete(id);
          if (chatAlive(S.chat)) await syncChat();
        }
        else if(library) await files(S.files.page,S.files.q,S.files.scope,S.files.owner||0);
        else if(post) await item(post);
        else { const url=new URL(location.href);url.searchParams.delete('item');history.replaceState(history.state,'',url);S.item=null;await render(); }
      }
      else if (a === "editor") await editor(b.dataset.type, id);
      else if (a === "notifications") { S.noticePage = 1; await notifications(); }
      else if (a === "notification-filter") { S.noticeFilter = b.dataset.filter; S.noticePage = 1; await notifications(); }
      else if (a === "notification-page") { S.noticePage = Number(b.dataset.page); await notifications(); }
      else if (a === "notifications-read-all") { await api("notifications/read-all", {}); await notifications(); }
      else if (a === "notification-open") { const destination = await api("notifications/" + id + "/open", {}); await navigateTo(destination.url); }
      else if (a === "answer-history") await answerHistory();
      else if (a === "report") reportDialog(id, "content");
      else if (["like", "follow"].includes(a)) {
        await api(`items/${id}/reaction`, {
          kind: a,
          active: b.dataset.active === "true",
        });
        toast("Actualizado.");
        if (S.item?.id === id && document.querySelector(".modal"))
          await item(id);
        else await render();
      } else if (a === "password-reset-email") {
        const result = await api("account/password-reset", {});
        toast(result.message);
      } else if (a === "connect") {
        const state = await api("relations", { target: id, kind: "connect", active: true });
        updateConnectionState(id, state); await refreshConnectionsPanel();
        toast("Solicitud de conexión enviada.");
      } else if (a === "connection-respond") {
        const state = await api(`connections/${Number(b.dataset.request)}/respond`, {decision: b.dataset.decision});
        updateConnectionState(id, state); await refreshConnectionsPanel(); await refreshNotifications();
        toast(b.dataset.decision === 'accept' ? 'Conexión confirmada. Ya pueden enviarse mensajes.' : 'Solicitud rechazada.');
      } else if (a === "connection-remove-request") {
        confirmConnectionRemoval(id, b.dataset.mode);
      } else if (a === "connection-remove-confirm") {
        const mode = b.dataset.mode;
        const state = await api("relations", {target:id, kind:"connect", active:false});
        closeModal(); updateConnectionState(id, state); await refreshConnectionsPanel(); await refreshNotifications();
        toast(mode === 'cancel' ? 'Solicitud de conexión cancelada.' : 'Conexión eliminada.');
      } else if (a === "connections-refresh") {
        await refreshConnectionsPanel(); await refreshOpenConnection();
      } else if (a === "intro") await intro(id);
      else if (a === "message-start") {
        const c = await api("conversations", { target: id });
        const destination = new URL(C.pages.mensajeria.url); destination.searchParams.set("conversation", c.id); await navigateTo(destination.href);
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
        if (S.page === 'mensajeria') await messages();
        else { const p = await api('profiles/' + id); updateConnectionState(id, p.connection); await refreshConnectionsPanel(); }
      } else if (a === "older-messages") {
        await olderMessages(b);
      } else if (a === "register") {
        await api(`events/${id}/register`, { status: b.dataset.status });
        toast("Inscripción actualizada.");
        await item(id);
      } else if (a === "page") {
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
      } else if (a === "notification-delete-request") {
        modal(T('Eliminar notificación'), `<p class="detail-body">${E(T('Esta notificación se eliminará de tu bandeja de actividad.'))}</p><div class="form-actions">${btn(T('Cancelar'),'close')}${btn(T('Eliminar'),'notification-delete-confirm',`data-id="${id}"`,'danger primary')}</div>`);
      } else if (a === "notification-delete-confirm") {
        await api("notifications/" + id, null, "DELETE");
        closeModal(); await notifications(); await refreshNotifications(); toast('Notificación eliminada.');
      } else if (a === "ask-suggestion") {
        const input=document.querySelector('[data-form="ask"] [name="question"]');
        if (input) { input.value = b.dataset.question; document.querySelector("[data-form=ask]").requestSubmit(); }
      } else if (a === "assistant-new") {
        assistantThread(true);
        await assistant();
        document.querySelector('[data-form="ask"] [name="question"]')?.focus();
      } else if (a === "ai-test") {
        const target=document.getElementById('ai-test-result'); target.textContent=T('Probando el proveedor, el modelo y la clave guardados…');
        try { const r=await api('ai/test',{}); target.textContent=T(r.message)+' '+T('Modelo:')+' '+r.model; target.className='alert success'; }
        catch(error) { target.textContent=error.message; target.className='alert error'; }
      } else if (a === "report-reviewed") {
        await api('admin/reports/' + id + '/review', {});
        toast(T('Reporte marcado como revisado.'));
        await admin();
      } else if (a === "request-filter") {
        S.adminFilters.contacts={...S.adminFilters.contacts,state:b.dataset.state,page:1}; await adminContacts(document.getElementById('admin-panel'));
      } else if (a === "admin-page") {
        const area=b.dataset.area;S.adminFilters[area].page=Number(b.dataset.page);await (area==='users'?adminUsers:adminContacts)(document.getElementById('admin-panel'));
      } else if (a === "user-access") {
        const suspended=b.dataset.suspended==='true';
        modal(suspended?'Suspender acceso':'Reactivar acceso',`<p>¿${suspended?'Suspender':'Reactivar'} el acceso de <strong>${E(b.dataset.name)}</strong> a la comunidad?</p><p class="private-note">La cuenta y su contenido se conservan.</p><div class="form-actions">${btn('Cancelar','close')}${btn('Confirmar','user-status',`data-id="${id}" data-suspended="${suspended}"`,'primary')}</div>`);
      } else if (a === "user-status") {
        await api('admin/member/'+id,{suspended:b.dataset.suspended==='true'}); closeModal();toast('Acceso actualizado.');await adminUsers(document.getElementById('admin-panel'));
      } else if (a === "admin-tab") {
        if (!(await confirmUnsavedExit())) return;
        S.adminTab = b.dataset.tab;
        await admin();
      } else if (a === "admin-refresh") {
        if (await confirmUnsavedExit()) await admin();
      }
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
        toast("Solicitud #"+id+": "+requestLabels[b.dataset.status]+".");
        await adminContacts(document.getElementById('admin-panel'));
      } else if (a === "generate") {
        const j = await api("jobs", { kind: "multimedia", resource_id: id });
        modal("Procesar conferencia", '<div id="job-result"></div>');
        watchJob(j.id, document.getElementById("job-result"), (r) => {
          document.getElementById("job-result").innerHTML =
            `<div class="alert">${E(r.message)} · ${E(r.mode)}</div>${btn("Abrir publicación actualizada", "item", `data-id="${r.resource_id}"`, "primary")}`;
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
        } else if (S.page === "asistente") {
          document.getElementById("answers").innerHTML = "";
          await restoreAnswer(id);
        }
      } else if (a === "google-connect") {
        const r = await api("google/connect", { service: b.dataset.service });
        await navigateTo(r.url);
      } else if (a === "google-disconnect") {
        await api("google/disconnect", { service: b.dataset.service });
        toast("Conexión eliminada.");
        S.boot = await api("bootstrap");
        await profile();
      } else if (a === "google-event") {
        await api(`events/${id}/google`, { operation: b.dataset.operation });
        toast("Google Calendar actualizado.");
      } else if (a === "mail-test") { const result = await api("mail/test", {}); toast(result.message); }
      else if (a === "infographic") infographic();
    } catch (e) {
      if (['connect','connection-respond','connection-remove-confirm'].includes(a) && [404,409].includes(e.status)) { await refreshOpenConnection(); await refreshConnectionsPanel(); }
      if (e.name !== "AbortError") toast(e.message);
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
        await navigateTo(url.href);
      }
      else if (action === "filters") {
        S.filter = { ...S.filter, ...data, page: 1 };
        await render();
      } else if (action === "invite-search") {
        await inviteMembers(Number(form.dataset.id), 1, data.q);
      } else if (action === "file-search") {
        await files(1, data.q, S.files?.scope || "mine", Number(data.owner || 0));
      } else if (action === "event-invite") {
        const r = await api("events/" + form.dataset.id + "/invite", { users: [...S.invite.selected] });
        closeModal(); S.invite = null;
        toast(`${r.sent} invitaciones enviadas; ${r.skipped} asociados ya tenían una inscripción o invitación.`);
        await item(Number(form.dataset.id));
      } else if (action === "profile") {
        await validateProfileLocations(form);
        for (const key of Object.keys(profileTax))
          data[key] = new FormData(form).getAll(key).map(Number);
        data.hidden = new FormData(form).getAll("hidden");
        for (const key of ["directory", "networking", "microevents"])
          data[key] = form.elements[key].checked;
        delete data.photo;
        await api("profiles/me", data);
        clearUnsavedGuard(form);
        S.boot = await api("bootstrap");
        const label = root.querySelector(".header-profile strong"); if (label) label.textContent = S.boot.me.name;
        toast("Perfil actualizado.");
        await profile();
      } else if (action === "password-change") {
        const result = await api("account/password", {
          current_password: data.current_password,
          new_password: data.new_password,
          confirm_password: data.confirm_password,
        });
        form.reset();
        toast(result.message + " Actualizando tu sesión…");
        setTimeout(() => location.reload(), 900);
      } else if (action === "report") {
        const target = form.dataset.kind === "comment" ? `comments/${Number(form.dataset.id)}/report` : `items/${Number(form.dataset.id)}/report`;
        const result = await api(target, {
          reason: data.reason,
          detail: data.detail || "",
        });
        closeModal();
        toast(`${form.dataset.kind === "comment" ? "Comentario reportado" : "Reporte enviado"}: ${result.reason_label}.`);
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
      } else if (action === "comment-reply") {
        const r = await api("items/" + form.dataset.id + "/comments", {
          body: data.body,
          parent: Number(form.dataset.parent),
        });
        toast(r.status === "pending" ? "Respuesta enviada a revisión." : "Respuesta publicada.");
        await item(Number(form.dataset.id));
      } else if (action === "message") {
        const chat = S.chat;
        form.dataset.sending = 'true';
        try {
          await api("conversations/" + form.dataset.id + "/messages", { body: data.body });
          if (form.elements.body.value === data.body) { form.reset(); chatDrafts.delete(Number(form.dataset.id)); }
          if (chatAlive(chat)) { await loadMessages(chat); await syncChat(); }
        } finally { delete form.dataset.sending; }
      } else if (action === "intro") {
        const c = await api("conversations", {
          target: Number(form.dataset.id),
        });
        await api("conversations/" + c.id + "/messages", { body: data.body });
        closeModal();
        toast("Mensaje enviado.");
      } else if (action === "ask") {
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
      } else if (action === "admin-filter") {
        const area=form.dataset.area; S.adminFilters[area]={...data,page:1}; await (area==='users'?adminUsers:adminContacts)(document.getElementById('admin-panel'));
      } else if (action === "settings") {
        for (const k of [
          "demo",
          "moderation_required",
          "moderate_comments",
          "chatham_default",
          "micro_enabled",
          "micro_approval",
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
        data.matching_min_affinity = Number(data.matching_min_affinity);
        await api("settings", data);
        clearUnsavedGuard(form);
        toast("Configuración guardada.");
        await admin();
      } else if (action === "demo") {
        const r = await api("demo", { password: data.password });
        form.reset();
        toast(r.message);
        await admin();
      }
    } catch (e) {
      if (e.name !== "AbortError") toast(e.message);
    } finally {
      if (submit) submit.disabled = false;
    }
  });
  root.addEventListener("input", (event) => {
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
    const type = input.closest('form[data-form="editor"]')?.dataset.type || "hub";
    return ["hub", "gallery", "resource", "ally"].includes(type) ? type : "hub";
  }
  async function prepareImageUpload(input, file) {
    if (!window.ASCLAImageEditor?.edit) throw new Error(T("El editor de imágenes no está disponible. Recarga la página e inténtalo de nuevo."));
    const context = imageUploadContext(input);
    return window.ASCLAImageEditor.edit(file, {
      context,
      title: T(context === "profile" ? "Ajustar fotografía" : "Ajustar imagen"),
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
    return `<span class="attached-file image-ready" data-media="${media.id}"><img src="${E(media.url)}" alt=""><span>${E(media.name)}</span>${btn("Quitar", "detach-media", `data-id="${media.id}"`, "ghost small")}</span>`;
  }
  function humanFileSize(bytes) {
    const value = Number(bytes) || 0;
    if (value < 1024) return value + " B";
    if (value < 1024 * 1024) return Math.round(value / 1024) + " KB";
    return (value / 1024 / 1024).toFixed(1) + " MB";
  }
  root.addEventListener("change", async (event) => {
    const input = event.target;
    if (input.matches?.("[data-admin-language-select]")) { input.form?.requestSubmit(); return; }
    if (input.matches?.('form[data-form="settings"] [name="ai_provider"]')) syncAIProvider(input.form);
    const reportForm = input.closest?.('form[data-form="report"]');
    if (reportForm && input.name === "reason") updateReportDetailRequirement(reportForm);
    updateProfilePreference(input);
    refreshUnsavedGuard(input);
    if (!input.dataset.upload || !input.files?.length) return;
    let sourceId = 0;
    try {
      const file = input.files[0];
      let m;
      let prepared = null;
      if (file.type.startsWith("image/")) {
        prepared = await prepareImageUpload(input, file);
        if (!prepared) return;
        toast(T("Guardando imagen optimizada…"));
        if (prepared.masterFile) {
          const source = await uploadPrivateMedia(prepared.masterFile);
          sourceId = Number(source.id) || 0;
        }
        m = await uploadPrivateMedia(prepared.outputFile, sourceId);
        sourceId = 0;
      } else {
        toast(T("Subiendo archivo privado…"));
        m = await uploadPrivateMedia(file);
      }
      if (input.dataset.upload === "photo") {
        const photoId = document.querySelector("[name=photo_id]");
        photoId.value = m.id;
        refreshUnsavedGuard(photoId);
        const avatarBox = document.querySelector('[data-form="profile"] .profile-summary .avatar');
        if (avatarBox && m.mime?.startsWith("image/")) {
          avatarBox.querySelector("img")?.remove();
          avatarBox.insertAdjacentHTML("afterbegin", `<img src="${E(m.url)}" alt="${E(S.boot.me.name || "")}">`);
        }
        const statusBox = document.getElementById("photo-status");
        if (statusBox) statusBox.textContent = prepared
          ? `${T("Fotografía preparada")}: ${humanFileSize(prepared.inputBytes)} → ${humanFileSize(prepared.storedBytes)}. ${T("Guarda el perfil para aplicar.")}`
          : T("Fotografía cargada. Guarda el perfil para aplicar.");
      } else {
        const attachments = document.getElementById("attachments");
        attachments?.insertAdjacentHTML("beforeend", m.mime?.startsWith("image/") ? preparedImageMarkup(m) : `<span class="attached-file" data-media="${m.id}">${E(m.name)}${btn("Quitar", "detach-media", `data-id="${m.id}"`, "ghost small")}</span>`);
      }
      toast(prepared ? T("Imagen preparada y cargada.") : T("Archivo cargado."));
    } catch (e) {
      if (sourceId) {
        try { await api("media/" + sourceId, null, "DELETE"); } catch {}
      }
      if (e.name !== "AbortError") toast(e.message);
    } finally {
      input.value = "";
    }
  });
  applyTheme(themeMode());
  themeMedia?.addEventListener?.("change", () => { if (themeMode() === "system") applyTheme("system"); });
  window.addEventListener("storage", (event) => { if (event.key === THEME_KEY) applyTheme(themeMode()); });
  window.addEventListener("beforeunload", (event) => {
    if (!hasUnsavedChanges()) return;
    event.preventDefault();
    event.returnValue = "";
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
            "ascla-miembros": "usuarios",
            "ascla-solicitudes": "solicitudes",
            "ascla-ia": "trabajos",
            "ascla-microeventos": "microeventos",
            "ascla-logs": "logs",
            "ascla-integraciones": "configuracion",
            "ascla-configuracion": "configuracion",
          }[menu] || "moderacion";
      }
      shell();
      await render();
      enableNavigation();
      setInterval(() => { if (!document.hidden) refreshNotifications(); }, 30000);
      document.addEventListener("visibilitychange", () => {
        if (document.hidden) clearTimeout(S.poll);
        else { refreshNotifications(); syncChat(); }
      });
      setInterval(refreshOpenConnection, 8000);
      window.addEventListener('focus', () => { syncChat(); refreshOpenConnection(); });
      window.addEventListener('online', () => { syncChat(); });
      await routeDetails();
      maybeProfileCompletionNudge();
    } catch (e) {
      (content() || root).innerHTML = `<div class="error">${E(e.message)} <a data-native href="${E(location.href)}">Recarga la página para renovar tu sesión.</a></div>`;
    }
  }
  start();
})();
