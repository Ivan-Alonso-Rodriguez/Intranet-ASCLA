  function asclaInfographic(p, T = (text) => text) {
    const ns = "http://www.w3.org/2000/svg";
    const svg = document.createElementNS(ns, "svg");
    const info = p.meta.infographic || {};
    let y = 165;
    function add(tag, attrs, text = "") {
      const node = document.createElementNS(ns, tag);
      for (const [key, val] of Object.entries(attrs))
        node.setAttribute(key, String(val));
      node.textContent = text;
      svg.append(node);
      return node;
    }
    function paragraph(text, x = 54, color = "#324860", size = 17) {
      const words = String(text).match(/.{1,68}(?:\s|$)|.{1,68}/g) || [];
      for (const line of words) {
        add("text", { x, y, fill: color, "font-size": size }, line.trim());
        y += 25;
      }
      y += 12;
    }
    function section(title, entries) {
      if (!entries.length) return;
      y += 15;
      paragraph(title, 54, "#116da3", 21);
      for (const text of entries.slice(0, 8)) paragraph(text);
    }
    const background = add("rect", {
      width: 760,
      height: 10000,
      fill: "#f4f8fb",
    });
    add("rect", { width: 760, height: 110, fill: "#233156" });
    add(
      "text",
      { x: 54, y: 40, fill: "#bcd5ef", "font-size": 14 },
      T("ASCLA · CENTRO DE CONOCIMIENTO"),
    );
    add(
      "text",
      { x: 54, y: 80, fill: "white", "font-size": 25 },
      String(info.title || T("Claves de la sesión")).slice(0, 45),
    );
    section(
      T("Puntos clave"),
      (info.key_points || info.sections || []).filter(
        (x) => typeof x === "string",
      ),
    );
    if (["extractive-source-sentences-v1", "source-references-v2"].includes(p.meta.grounding?.policy)) {
      section(
        T("Estadísticas de la sesión"),
        (info.statistics || []).filter((x) => typeof x === "string"),
      );
      const timeline = (info.timeline || []).filter(
        (x) => x && typeof x.text === "string",
      );
      if (timeline.length) {
        y += 15;
        paragraph(T("Cronología de la fuente"), 54, "#116da3", 21);
        for (const item of timeline.slice(0, 8)) {
          add("circle", { cx: 60, cy: y - 6, r: 5, fill: "#116da3" });
          paragraph(`${item.date} · ${item.text}`, 82);
        }
      }
    }
    if (p.meta.demo_source_note)
      section(T("Datos ficticios de demostración"), [p.meta.demo_source_note]);
    y += 18;
    paragraph(
      `${T("Fuente")}: ${T("Centro de Conocimiento ASCLA")} #${p.meta.source_id || p.id}`,
      54,
      "#526078",
      13,
    );
    paragraph(
      "© ASCLA – Asociación de Secretarios Corporativos de América Latina",
      54,
      "#526078",
      12,
    );
    const height = y + 20;
    svg.setAttribute("width", "760");
    svg.setAttribute("height", String(height));
    svg.setAttribute("viewBox", `0 0 760 ${height}`);
    svg.setAttribute("font-family", "Arial, sans-serif");
    background.setAttribute("height", String(height));
    return new XMLSerializer().serializeToString(svg);
  }
/* Content views share the app's escaping and controls. */
window.ASCLAContent = function ({ escape: E, icon: I, config: C, T = (text) => text }) {
  const option = (name, label, values, selected = "") =>
    `<label class="filter-control"><span>${E(T(label))}</span><select name="${E(name)}" aria-label="${E(T(label))}"><option value="">${E(T("Todos"))}</option>${values.map((t) => `<option value="${E(t.id)}" ${String(selected) === String(t.id) ? "selected" : ""}>${E(t.name)}</option>`).join("")}</select></label>`;
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
    return `<details class="advanced-filters" ${["category", "tag", "keyword", "author", "parent"].some((k) => filter[k] !== undefined && filter[k] !== "") ? "open" : ""}><summary>${E(T("Más filtros"))}</summary><div class="filter-grid">${controls}</div></details>`;
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
  function fragments(moments) {
    const items = moments.map((m) => {
      const timing = `${timestamp(m.start)} – ${timestamp(m.end)} · ${duration(m.end - m.start)}`;
      let link = "";
      if (
        /^https:\/\/www\.youtube\.com\/watch\?v=[\w-]{11}&t=\d+s$/.test(
          m.youtube_url || "",
        )
      ) {
        link = `<a class="btn small" href="${E(m.youtube_url)}" target="_blank" rel="noopener noreferrer">${E(T("Abrir referencia en YouTube ↗"))}</a>`;
      }
      return `<li><strong>${E(T("Cápsula sugerida"))} · ${timing}</strong><p>${E(m.description || m.title)}</p><small>${E(m.reason || m.selection)}</small><p>${link}</p></li>`;
    });
    return `<ol>${items.join("")}</ol><p class="private-note">${E(T("Referencias temporales al video de origen; no son archivos recortados."))}</p>`;
  }
  function agenda(p) {
    const data = p.meta.agenda_ai;
    if (!data) return "";
    const mode = `<span class="tag">${E(data.mode || "DEMO MODE")}</span>`;
    return `<section class="generated-results"><h3>${E(T("Agenda de conversación"))} ${mode}</h3><p>${E(data.objective)}</p><p><strong>${E(T("Para comenzar"))}:</strong> ${E(data.icebreaker)}</p><p><strong>${E(T("Para cerrar"))}:</strong> ${E(data.closing_question)}</p><p class="private-note">${E(T("Propuesta de"))} ${Number(data.duration_minutes)} ${E(T("minutos. Revisa la agenda y la fecha antes de aprobar."))}</p></section>`;
  }
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
      moments: "Cápsulas sugeridas",
    };
    let html = Object.entries(sections)
      .filter(([key]) => p.meta[key]?.length)
      .map(
        ([key, label]) =>
          `<details class="generated-section"><summary>${E(T(label))}</summary>${key === "moments" ? fragments(p.meta[key]) : value(p.meta[key])}</details>`,
      )
      .join("");
    if (p.meta.source_id) {
      const url = new URL(C.pages["centro-conocimiento"].url, location.href);
      url.searchParams.set("item", p.meta.source_id);
      html += `<a class="btn small" href="${E(url.href)}">${E(T("Consultar recurso de origen"))} ${I("arrow")}</a>`;
    }
    return `<section class="generated-results"><h3>${E(T("Resultados y fuentes"))}</h3>${html}</section>`;
  }
  return {
    option,
    termFilter,
    duration,
    images,
    attachments,
    filters,
    generated,
    infographic: (p) => asclaInfographic(p, T),
    agenda,
  };
};
