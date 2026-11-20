/* Share the intranet appearance preference without reloading or touching login fields. */
(() => {
  "use strict";
  const select = document.getElementById("ascla-login-theme");
  if (!select) return;
  const html = document.documentElement;
  const key = "ascla-theme";
  const modes = new Set(["system", "light", "dark"]);
  const media = window.matchMedia?.("(prefers-color-scheme: dark)");
  const apply = (value, persist = false) => {
    const mode = modes.has(value) ? value : "system";
    const theme = mode === "system" ? (media?.matches ? "dark" : "light") : mode;
    html.dataset.asclaTheme = theme;
    html.dataset.asclaThemeMode = mode;
    html.style.colorScheme = theme;
    select.value = mode;
    if (persist) {
      try { localStorage.setItem(key, mode); } catch (_) { /* The control still works with storage disabled. */ }
    }
  };
  apply(html.dataset.asclaThemeMode);
  select.closest(".ascla-appearance-switcher").hidden = false;
  select.addEventListener("change", () => apply(select.value, true));
  media?.addEventListener?.("change", () => {
    if (select.value === "system") apply("system");
  });
  window.addEventListener("storage", event => {
    if (event.key === key || event.key === null) apply(event.newValue);
  });
})();
