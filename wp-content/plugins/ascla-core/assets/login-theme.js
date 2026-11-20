/* Share the intranet appearance preference without reloading or touching login fields. */
(() => {
  "use strict";
  const button = document.getElementById("ascla-login-theme");
  const dialog = document.getElementById("ascla-login-appearance");
  if (!button || !dialog || typeof dialog.showModal !== "function") return;
  const choices = [...dialog.querySelectorAll("[data-theme]")];
  const title = document.getElementById("ascla-login-appearance-title").textContent;
  const html = document.documentElement;
  const key = "ascla-theme";
  const modes = new Set(["system", "light", "dark"]);
  const media = window.matchMedia?.("(prefers-color-scheme: dark)");
  let selectedMode = "system";
  const apply = (value, persist = false) => {
    const mode = modes.has(value) ? value : "system";
    const theme = mode === "system" ? (media?.matches ? "dark" : "light") : mode;
    html.dataset.asclaTheme = theme;
    html.dataset.asclaThemeMode = mode;
    html.style.colorScheme = theme;
    selectedMode = mode;
    choices.forEach(choice => choice.setAttribute("aria-pressed", String(choice.dataset.theme === mode)));
    const name = choices.find(choice => choice.dataset.theme === mode).querySelector(".ascla-login-theme-title").textContent;
    button.dataset.theme = theme;
    button.setAttribute("aria-label", `${title}: ${name}`);
    button.title = `${title}: ${name}`;
    if (persist) {
      try { localStorage.setItem(key, mode); } catch (_) { /* The control still works with storage disabled. */ }
    }
  };
  apply(html.dataset.asclaThemeMode);
  button.closest(".ascla-appearance-switcher").hidden = false;
  button.addEventListener("click", () => {
    dialog.showModal();
    button.setAttribute("aria-expanded", "true");
    dialog.querySelector('[aria-pressed="true"]').focus();
  });
  choices.forEach(choice => choice.addEventListener("click", () => {
    apply(choice.dataset.theme, true);
    dialog.close();
  }));
  dialog.querySelector(".ascla-login-theme-close").addEventListener("click", () => dialog.close());
  dialog.addEventListener("keydown", event => {
    if (event.key !== "Tab") return;
    const first = dialog.querySelector(".ascla-login-theme-close");
    const last = choices[choices.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });
  dialog.addEventListener("close", () => button.setAttribute("aria-expanded", "false"));
  dialog.addEventListener("click", event => {
    const rect = dialog.getBoundingClientRect();
    if (event.target === dialog && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) dialog.close();
  });
  media?.addEventListener?.("change", () => {
    if (selectedMode === "system") apply("system");
  });
  window.addEventListener("storage", event => {
    if (event.key === key || event.key === null) apply(event.newValue);
  });
})();
