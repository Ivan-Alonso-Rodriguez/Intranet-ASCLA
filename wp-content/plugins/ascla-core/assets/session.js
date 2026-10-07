/* Session expiry uses server time. Background polls cannot extend inactivity. */
(() => {
  "use strict";
  const config = globalThis.ASCLA;
  if (!config) return;
  const text = value => config.translations?.[value] || value;
  let deadline = 0, lastActivity = 0, pending = null, warning = null, expired = false;
  async function update(renew = false) {
    if (pending || expired) return pending;
    pending = (async () => {
      const response = await fetch(config.api + "account/session", {
        method: renew ? "POST" : "GET", credentials: "same-origin",
        headers: {"X-WP-Nonce": config.nonce},
      });
      if (response.status === 401 || response.status === 403) {
        expired = true;
        location.assign(config.login);
        return;
      }
      if (!response.ok) throw new Error("Session check unavailable");
      const state = await response.json();
      deadline = performance.now() + Number(state.remaining) * 1000;
      if (renew) lastActivity = performance.now();
      if (Number(state.remaining) > 120 && warning) { warning.remove(); warning = null; }
    })();
    try { await pending; } catch { /* Keep the deadline; a network error never extends it. */ }
    finally { pending = null; }
  }
  function showWarning() {
    if (warning || document.hidden || expired) return;
    warning = document.createElement("section");
    warning.setAttribute("role", "status");
    warning.className = "ascla-session-warning";
    warning.setAttribute("aria-labelledby", "ascla-session-warning-title");
    const heading = document.createElement("h2");
    heading.id = "ascla-session-warning-title";
    heading.textContent = text("Tu sesión está por vencer");
    const body = document.createElement("p");
    body.textContent = text("La sesión se cierra tras 30 minutos sin actividad. Puedes extenderla para continuar.");
    const extend = document.createElement("button");
    extend.type = "button"; extend.className = "btn primary";
    extend.textContent = text("Continuar mi sesión");
    extend.addEventListener("click", async () => { extend.disabled = true; await update(true); extend.disabled = false; });
    warning.append(heading, body, extend);
    document.body.append(warning);
  }
  function activity(event) {
    if (!event.isTrusted || document.hidden || warning || performance.now() - lastActivity < 60000) return;
    void update(true);
  }
  for (const name of ["pointerdown", "keydown", "scroll"]) document.addEventListener(name, activity, {passive:true});
  document.addEventListener("visibilitychange", () => { if (!document.hidden) void update(); });
  setInterval(() => {
    if (!deadline || expired) return;
    if (deadline <= performance.now()) void update();
    else if (deadline - performance.now() <= 120000) showWarning();
  }, 10000);
  setInterval(() => { if (!document.hidden) void update(); }, 60000);
  void update();
})();
