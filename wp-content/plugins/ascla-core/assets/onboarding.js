/* Contextual guides. WordPress owns claims and progress; DOM targets are optional. */
globalThis.ASCLAOnboarding = ({ root, api, T, toast }) => {
  const escape = value => String(value).replaceAll(/[&<>"']/g, character => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
  })[character]);
  const text = value => escape(T(value));
  let data, pending, module = "", context = {}, generation = 0, session, layer, button, timer, frame;
  let restoreFocus, resizeObserver;
  const isolated = new Map(), attempted = new Set(), opening = new Set();
  const blocked = () => !!root.querySelector('.modal-backdrop,.ascla-image-editor-backdrop,.ascla-sidebar.open,.account-menu[open]');
  const visible = node => node?.getClientRects().length && getComputedStyle(node).visibility !== "hidden";
  function target(step) {
    for (const selector of Array.isArray(step.target) ? step.target : [step.target]) {
      const found = [...root.querySelectorAll(selector)].find(visible);
      if (found) return found;
    }
    return null;
  }

  async function registry(refresh = false) {
    if (data && !refresh) return data;
    pending ||= api("onboarding");
    try { data = await pending; return data; }
    finally { pending = null; }
  }

  function findModule() {
    return Object.keys(data?.tours || {}).find(key => {
      const tour = data.tours[key];
      return tour.page === context.page && tour.section === (context.page === "admin" ? context.section : "");
    }) || "";
  }

  function mountHelp() {
    const header = root.querySelector(".header-right");
    if (!header) return;
    button = header.querySelector("[data-onboarding-help]");
    if (!button) {
      button = document.createElement("button");
      button.type = "button";
      button.className = "btn icon-button onboarding-help";
      button.dataset.onboardingHelp = "";
      button.textContent = "?";
      button.setAttribute("aria-label", T("Ayuda de este módulo"));
      button.title = T("Ayuda de este módulo");
      button.addEventListener("click", () => { void manual(); });
      header.prepend(button);
    }
    button.hidden = !!data && !module;
  }

  function dismiss() {
    clearTimeout(timer);
    cancelAnimationFrame(frame);
    frame = null;
    resizeObserver?.disconnect();
    layer?.remove();
    layer = null;
    session = null;
    for (const [node, previous] of isolated) node.inert = previous;
    isolated.clear();
    if (restoreFocus?.isConnected && !restoreFocus.closest("[inert]")) restoreFocus.focus({ preventScroll: true });
    restoreFocus = null;
  }

  function createLayer() {
    dismiss();
    restoreFocus = document.activeElement;
    layer = document.createElement("div");
    layer.className = "ascla-tour";
    layer.innerHTML = '<div class="ascla-tour-spotlight" aria-hidden="true"></div><section class="ascla-tour-bubble" role="dialog" aria-modal="true" aria-labelledby="ascla-tour-title" aria-describedby="ascla-tour-description"></section>';
    document.body.append(layer);
    for (const node of document.body.children) {
      if (node === layer || ["SCRIPT", "STYLE", "LINK"].includes(node.tagName)) continue;
      isolated.set(node, node.inert);
      node.inert = true;
    }
    layer.addEventListener("click", event => {
      const control = event.target.closest("[data-tour-op]");
      if (control && !control.disabled) void operate(control.dataset.tourOp);
    });
    resizeObserver = new ResizeObserver(schedulePosition);
    resizeObserver.observe(layer.querySelector(".ascla-tour-bubble"));
  }

  function schedulePosition() {
    if (frame) return;
    frame = requestAnimationFrame(() => { frame = null; position(); });
  }

  function position() {
    if (!layer) return;
    const bubble = layer.querySelector(".ascla-tour-bubble");
    const spotlight = layer.querySelector(".ascla-tour-spotlight");
    const anchor = session && target(session.steps[session.index]);
    const width = innerWidth, height = innerHeight, gap = 14;
    const bounds = anchor?.getBoundingClientRect();
    spotlight.hidden = !bounds;
    layer.classList.toggle("without-target", !bounds);
    if (bounds) {
      const top = Math.max(8, bounds.top - 5), left = Math.max(8, bounds.left - 5);
      Object.assign(spotlight.style, {
        top: top + "px", left: left + "px",
        width: Math.max(0, Math.min(bounds.right + 5, width - 8) - left) + "px",
        height: Math.max(0, Math.min(bounds.bottom + 5, height - 8) - top) + "px",
      });
    }
    const size = bubble.getBoundingClientRect();
    let top = (height - size.height) / 2, left = (width - size.width) / 2;
    bubble.dataset.placement = "";
    if (bounds) {
      left = Math.min(width - size.width - gap, Math.max(gap, bounds.left));
      top = bounds.bottom + gap;
      bubble.dataset.placement = "below";
      if (top + size.height > height - gap) {
        top = bounds.top - size.height - gap;
        bubble.dataset.placement = "above";
      }
      if (top < gap) {
        top = height - size.height - gap;
        bubble.dataset.placement = "";
      }
    }
    bubble.style.left = Math.max(gap, left) + "px";
    bubble.style.top = Math.max(gap, Math.min(top, height - size.height - gap)) + "px";
  }

  function showStep() {
    const { tour, steps, index } = session;
    const step = steps[index], bubble = layer.querySelector(".ascla-tour-bubble");
    bubble.innerHTML = `<div class="ascla-tour-progress">${escape(tour.label)}<span>${index + 1} ${text("de")} ${steps.length}</span></div>
      <h2 id="ascla-tour-title">${escape(step.title)}</h2>
      <p id="ascla-tour-description">${escape(step.body)}</p>
      <p class="ascla-tour-error" role="alert" hidden></p>
      <div class="ascla-tour-actions"><button type="button" data-tour-op="skip" class="ascla-tour-skip">${text("Omitir")}</button>
        <button type="button" data-tour-op="previous" ${index === 0 ? "disabled" : ""}>${text("Anterior")}</button>
        <button type="button" data-tour-op="next" class="ascla-tour-next">${text(index === steps.length - 1 ? "Finalizar" : "Siguiente")}</button></div>`;
    target(step)?.scrollIntoView({ block: "center", inline: "nearest", behavior: "instant" });
    position();
    bubble.querySelector('[data-tour-op="next"]').focus({ preventScroll: true });
  }

  async function start(automatic, restart = false) {
    const selected = module, current = generation, tour = data.tours[selected];
    if (!tour || blocked() || opening.has(selected)) return;
    const steps = tour.steps.filter(step => target(step));
    if (!steps.length) return;
    attempted.add(selected);
    opening.add(selected);
    try {
      const response = await api("onboarding/" + selected, { action: "start", automatic, restart });
      data.progress[selected] = response.progress;
      if (!response.started || current !== generation || module !== selected || blocked()) return;
      createLayer();
      const saved = steps.findIndex(step => step.id === response.progress.step);
      session = { module: selected, tour, steps, progress: response.progress, index: Math.max(0, saved), busy: false };
      showStep();
    } finally { opening.delete(selected); }
  }

  async function maybeStart() {
    if (session || layer || !module || !data?.eligible || attempted.has(module) || blocked()) return;
    try { await start(true); }
    catch (error) {
      if (error.name !== "AbortError") attempted.add(module);
    }
  }

  async function manual() {
    try {
      await registry(true);
      module = findModule();
      if (!module || blocked() || opening.has(module)) return;
      const progress = data.progress[module];
      if (progress?.status !== "started") { await start(false, true); return; }
      createLayer();
      const bubble = layer.querySelector(".ascla-tour-bubble");
      bubble.innerHTML = `<h2 id="ascla-tour-title">${text("Guía del módulo")}</h2>
        <p id="ascla-tour-description">${text("Continúa donde te quedaste o vuelve a empezar.")}</p>
        <div class="ascla-tour-resume"><button type="button" data-tour-op="resume" class="ascla-tour-next">${text("Continuar recorrido")}</button>
        <button type="button" data-tour-op="restart">${text("Repetir desde el inicio")}</button>
        <button type="button" data-tour-op="close">${text("Cerrar")}</button></div>`;
      position();
      bubble.querySelector("button").focus();
    } catch (error) { if (error.name !== "AbortError") toast("No se pudo cargar la guía. Vuelve a intentarlo desde Ayuda."); }
  }

  async function save(action, step) {
    const active = session;
    const response = await api("onboarding/" + active.module, {
      action, step, run: active.progress.run, revision: active.progress.revision,
    });
    data.progress[active.module] = response.progress;
    if (session === active) session.progress = response.progress;
  }

  function controlsBusy(busy) {
    layer?.querySelectorAll("button").forEach(control => {
      control.disabled = busy || (control.dataset.tourOp === "previous" && session?.index === 0);
    });
  }

  function progressError(error,operation,active) {
      if (session !== active || error.name === "AbortError") return;
      if ([401, 403, 404, 409].includes(error.status)) {
        dismiss();
        toast(error.message);
        return;
      }
      if (operation === "skip") {
        dismiss();
        toast("No se pudo guardar el cierre de la guía. Tu avance anterior está guardado.");
      } else {
        const message = layer.querySelector(".ascla-tour-error");
        message.hidden = false;
        message.textContent = T("No se pudo guardar el avance. Vuelve a pulsar el botón para reintentarlo.");
      }
  }

  async function operate(operation) {
    if (operation === "close") { dismiss(); return; }
    if (operation === "resume" || operation === "restart") {
      dismiss();
      try { await start(false, operation === "restart"); }
      catch (error) { if (error.name !== "AbortError") toast(error.message); }
      return;
    }
    if (!session || session.busy) return;
    const active = session;
    active.busy = true;
    controlsBusy(true);
    try {
      if (operation === "skip") { await save("skip"); dismiss(); return; }
      const next = active.index + (operation === "previous" ? -1 : 1);
      if (next === active.steps.length) { await save("complete"); dismiss(); return; }
      if (next < 0) return;
      await save("move", active.steps[next].id);
      if (session !== active) return;
      active.index = next;
      showStep();
    } catch (error) {
      progressError(error,operation,active);
    } finally {
      if (session === active) { active.busy = false; controlsBusy(false); }
    }
  }

  function enter(next) {
    const changed = context.page !== next.page || context.section !== next.section;
    context = next;
    if (changed) { generation++; dismiss(); }
    void registry().then(() => {
      module = findModule();
      mountHelp();
      clearTimeout(timer);
      timer = setTimeout(maybeStart, 300);
    }).catch(() => { mountHelp(); });
  }

  function stop() {
    generation++;
    dismiss();
    module = "";
  }

  new MutationObserver(() => {
    if (layer && blocked()) dismiss();
    if (!layer && module && data?.eligible && !attempted.has(module)) {
      clearTimeout(timer);
      timer = setTimeout(maybeStart, 300);
    }
  }).observe(root, { childList: true, subtree: true, attributes: true, attributeFilter: ['open'] });
  globalThis.addEventListener("scroll", schedulePosition, { capture: true, passive: true });
  globalThis.addEventListener("resize", schedulePosition);
  globalThis.addEventListener("pagehide", stop);
  function trapFocus(event) {
      const controls = [...layer.querySelectorAll("button:not(:disabled)")];
      const first = controls[0], last = controls.at(-1);
      if (!controls.length) { event.preventDefault(); return; }
      if (!layer.contains(document.activeElement) || (event.shiftKey && document.activeElement === first) || (!event.shiftKey && document.activeElement === last)) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus();
      }
  }
  document.addEventListener("keydown", event => {
    if (!layer) return;
    if (event.key === "Escape") {
      event.preventDefault();
      event.stopImmediatePropagation();
      if (session?.busy) dismiss(); else void operate(session ? "skip" : "close");
    } else if (event.key === "Tab") {
      trapFocus(event);
    }
  }, true);
  return { enter, stop };
};
