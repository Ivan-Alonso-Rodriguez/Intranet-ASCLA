/* Content views share the app's escaping and controls. */
window.ASCLAContent = function ({ escape: E, icon: I, config: C }) {
  const option = (name, label, values, selected = "") =>
    `<label class="filter-control"><span>${E(label)}</span><select name="${E(name)}" aria-label="${E(label)}"><option value="">Todos</option>${values.map((t) => `<option value="${E(t.id)}" ${String(selected) === String(t.id) ? "selected" : ""}>${E(t.name)}</option>`).join("")}</select></label>`;
  const termFilter = (name, label, terms, filter) =>
    option(name, label, terms || [], filter[name]);
  const duration = (seconds) => {
    const total = Math.max(0, Math.floor(Number(seconds) || 0));
    if (!total) return "";
    const hours = Math.floor(total / 3600),
      minutes = Math.floor(total / 60) % 60;
    return hours
      ? `${hours} h ${minutes} min`
      : `${minutes} min ${total % 60 ? (total % 60) + " s" : ""}`.trim();
  };
  const images = (p) =>
    (p.media || []).filter((m) => m.mime.startsWith("image/"));
  function attachments(p, compact = false) {
    const files = compact ? images(p).slice(0, 3) : p.media || [];
    if (!files.length) return "";
    return `<div class="${compact ? "feed-images" : "gallery-images"}">${files
      .map((m) => {
        const visual = m.mime.startsWith("image/")
          ? `<img src="${E(m.url)}" alt="${E(m.name)}" loading="lazy">`
          : `<span>${I("download")} ${E(m.name)}</span>`;
        return `<a href="${E(compact ? p.url : m.url)}"${compact ? "" : ' target="_blank" rel="noopener"'}>${visual}</a>`;
      })
      .join("")}</div>`;
  }
  function filters(type, filter, catalogs, authors = [], forums = []) {
    let controls = termFilter(
      "category",
      "Categoría",
      catalogs.category,
      filter,
    );
    if (type === "resource") {
      controls += termFilter("tag", "Tema", catalogs.interest, filter);
      controls += termFilter("keyword", "Etiqueta", catalogs.tag, filter);
      controls += option("author", "Autor", authors, filter.author);
    }
    if (type === "topic")
      controls =
        option(
          "parent",
          "Foro",
          [{ id: 0, name: "Conversación general" }, ...forums],
          filter.parent,
        ) + controls;
    return `<details class="advanced-filters" ${["category", "tag", "keyword", "author", "parent"].some((k) => filter[k] !== undefined && filter[k] !== "") ? "open" : ""}><summary>Más filtros</summary><div class="filter-grid">${controls}</div></details>`;
  }
  const labels = {
    title: "Título",
    name: "Nombre",
    description: "Descripción",
    source: "Fuente",
    evidence: "Evidencia",
    reference: "Referencia",
    explanation: "Explicación",
  };
  const timestamp = (seconds) => {
    const total = Math.max(0, Math.floor(Number(seconds) || 0));
    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, "0")}`;
  };
  const fragments = (moments) =>
    `<ol>${moments.map((m) => `<li><strong>${timestamp(m.start)} – ${timestamp(m.end)}</strong><p>${E(m.title)}</p><small>${E(m.selection)}</small></li>`).join("")}</ol>`;
  function value(data, depth = 0) {
    if (depth > 3 || data == null) return "";
    if (Array.isArray(data))
      return `<ul>${data
        .slice(0, 20)
        .map((x) => `<li>${value(x, depth + 1)}</li>`)
        .join("")}</ul>`;
    if (typeof data === "object")
      return Object.entries(data)
        .slice(0, 12)
        .map(
          ([key, val]) =>
            `<div><strong>${E(labels[key] || key.replaceAll("_", " "))}:</strong> ${value(val, depth + 1)}</div>`,
        )
        .join("");
    return E(data);
  }
  function generated(p) {
    if (!p.meta.generated) return "";
    const sections = {
      summary: "Resumen",
      frameworks: "Marcos de trabajo",
      conclusions: "Conclusiones",
      norms: "Normativas mencionadas",
      concepts: "Conceptos importantes",
      tags: "Palabras clave",
      moments: "Fragmentos propuestos",
    };
    let html = Object.entries(sections)
      .filter(([key]) => p.meta[key]?.length)
      .map(
        ([key, label]) =>
          `<details class="generated-section"><summary>${label}</summary>${key === "moments" ? fragments(p.meta[key]) : value(p.meta[key])}</details>`,
      )
      .join("");
    if (p.meta.source_id) {
      const url = new URL(C.pages["centro-conocimiento"].url, location.href);
      url.searchParams.set("item", p.meta.source_id);
      html += `<a class="btn small" href="${E(url.href)}">Consultar recurso de origen ${I("arrow")}</a>`;
    }
    return `<section class="generated-results"><h3>Resultados y fuentes</h3>${html}</section>`;
  }
  return {
    option,
    termFilter,
    duration,
    images,
    attachments,
    filters,
    generated,
  };
};
