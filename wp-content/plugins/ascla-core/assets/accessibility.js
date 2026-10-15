/* Keyboard and background access follow the currently visible dialog/drawer. */
(() => {
  "use strict";
  globalThis.ASCLAAccessibility = (root, T) => {
    const mobile = matchMedia("(max-width: 900px)"), background = new Map();
    const dialog = () => root.querySelector(".ascla-image-editor") || root.querySelector(".modal-decision") || root.querySelector(".modal");
    const focusable = container => [...container.querySelectorAll("a[href],button,input,select,textarea,[tabindex],summary")]
      .filter(node => !node.disabled && node.tabIndex >= 0 && !node.closest("[inert]") && node.getClientRects().length);
    function refresh() {
      for (const [node, previous] of background) node.inert = previous;
      background.clear();
      for (const label of root.querySelectorAll('label:has(input[type="file"][hidden])')) {
        label.tabIndex = label.querySelector('input[type="file"]').disabled ? -1 : 0;
        label.setAttribute("role", "button");
        label.setAttribute("aria-disabled", String(label.querySelector('input[type="file"]').disabled));
      }
      for (const table of root.querySelectorAll(".table-wrap")) {
        table.tabIndex = 0;
        table.setAttribute("role", "region");
        table.setAttribute("aria-label", table.closest(".card,.stats-section,.import-row")?.querySelector("h2,h3")?.textContent || T("Tabla de datos"));
      }
      const sidebar = root.querySelector(".ascla-sidebar"), trigger = root.querySelector('[data-action="menu"]');
      const open = !!sidebar?.classList.contains("open") && mobile.matches;
      if (sidebar) { sidebar.id = "ascla-navigation"; sidebar.inert = mobile.matches && !open; }
      if (trigger) {
        trigger.setAttribute("aria-controls", "ascla-navigation");
        trigger.setAttribute("aria-expanded", String(open));
        trigger.setAttribute("aria-label", T(open ? "Cerrar navegación" : "Abrir navegación"));
      }
      const active = dialog()?.closest(".modal-backdrop,.ascla-image-editor-backdrop");
      if (!active) return;
      const nodes = [...root.children, ...document.body.children].filter(node => node !== root && node !== active && !["SCRIPT", "STYLE", "LINK"].includes(node.tagName));
      for (const node of nodes) { background.set(node, node.inert); node.inert = true; }
    }
    function setMenu(open, focus = false) {
      const sidebar = root.querySelector(".ascla-sidebar");
      sidebar?.classList.toggle("open", open && mobile.matches);
      refresh();
      if (focus) (open ? sidebar?.querySelector('a[aria-current="page"]') || sidebar?.querySelector("a") : root.querySelector('[data-action="menu"]'))?.focus();
    }
    document.addEventListener("keydown", event => {
      const upload = event.target.matches?.('label[role="button"]') && event.target.querySelector('input[type="file"][hidden]');
      if (upload && ["Enter", " "].includes(event.key)) {
        event.preventDefault();
        if (upload.disabled) return;
        if (typeof upload.showPicker === "function") upload.showPicker();
        else upload.click();
        return;
      }
      const active = dialog();
      if (!active) {
        if (event.key === "Escape" && root.querySelector(".ascla-sidebar.open")) setMenu(false, true);
        return;
      }
      if (event.key !== "Tab") return;
      const controls = focusable(active), first = controls[0], last = controls.at(-1);
      if (!first) { event.preventDefault(); active.tabIndex = -1; active.focus(); }
      else if (!active.contains(document.activeElement) || (event.shiftKey && document.activeElement === first) || (!event.shiftKey && document.activeElement === last)) {
        event.preventDefault(); (event.shiftKey ? last : first).focus();
      }
    }, true);
    document.addEventListener("focusin", event => {
      const active = dialog();
      if (active && !active.contains(event.target)) focusable(active)[0]?.focus();
    });
    document.addEventListener("pointerdown", event => {
      if (!dialog() && root.querySelector(".ascla-sidebar.open") && !event.target.closest('.ascla-sidebar,[data-action="menu"]')) setMenu(false);
    });
    const observer = new MutationObserver(refresh);
    root.addEventListener("ascla-dialog-change", refresh);
    observer.observe(root, {childList: true, subtree: true});
    observer.observe(document.body, {childList: true});
    const sidebar = root.querySelector(".ascla-sidebar");
    if (sidebar) observer.observe(sidebar, {attributes: true, attributeFilter: ["class"]});
    mobile.addEventListener("change", () => setMenu(false));
    refresh();
    return {refresh, setMenu};
  };
})();
