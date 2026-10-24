/* Content views share the app's escaping and controls. */
globalThis.ASCLAContent = function ({ escape: E, icon: I, config: C, T = (text) => text }) {
  const option = (name, label, values, selected = "") =>
    `<label class="filter-control"><span>${E(T(label))}</span><select name="${E(name)}" aria-label="${E(T(label))}"><option value="">${E(T("Todos"))}</option>${values.map((t) => ("<option value=\"" + (E(t.id)) + "\" " + (String(selected) === String(t.id) ? "selected" : "") + ">" + (E(t.name)) + "</option>")).join("")}</select></label>`;
  const termFilter = (name, label, terms, filter) =>
    option(name, label, terms || [], filter[name]);
  const duration = (seconds) => {
    const total = Math.max(0, Math.floor(Number(seconds) || 0));
    if (!total) return "";
    const hours = Math.floor(total / 3600),
      minutes = Math.floor(total / 60) % 60;
    if (hours) return `${hours} h ${minutes} min`;
    const secondsPart = total % 60 ? (total % 60) + " s" : "";
    return `${minutes} min ${secondsPart}`.trim();
  };
  const images = (p) =>
    (p.media || []).filter((m) => m.mime.startsWith("image/"));
  function pdfAttachment(file) {
    return `<section class="pdf-attachment"><div class="pdf-file-heading"><span>${I('book')}<strong>${E(file.name)}</strong></span><a class="btn small pdf-download" href="${E(file.url)}" target="_blank" rel="noopener noreferrer">${I('download')} ${E(T('Descargar'))}</a></div><details class="pdf-preview" open data-pdf-url="${E(file.url)}" data-pdf-name="${E(file.name)}"><summary>${E(T('Previsualizar PDF'))}${I('chevron')}</summary><div class="pdf-preview-body"><div class="pdf-controls" role="group" aria-label="${E(T('Controles del PDF'))}"><div class="pdf-pagination"><button type="button" class="btn small pdf-page-prev" data-pdf-step="-1" aria-label="${E(T('Página anterior'))}" disabled>${I('arrow')}</button><span class="pdf-position" role="status" aria-live="polite"></span><button type="button" class="btn small" data-pdf-step="1" aria-label="${E(T('Página siguiente'))}" disabled>${I('arrow')}</button></div><label>${E(T('Zoom'))}<select data-pdf-zoom><option value="100">100 %</option><option value="125">125 %</option><option value="150">150 %</option></select></label></div><p class="pdf-status" role="status" aria-live="polite"></p><button type="button" class="btn small pdf-retry" data-pdf-retry hidden>${E(T("Reintentar previsualización"))}</button><div class="pdf-page" data-zoom="100" tabindex="0" role="region" aria-label="${E(T('Vista previa del PDF'))}"><canvas class="pdf-canvas" role="img" aria-label="${E(file.name)}" hidden></canvas></div><div class="pdf-extracted-text pdf-accessible-text"></div></div></details></section>`;
  }
  function attachments(p, compact = false) {
    const files = compact ? images(p).slice(0, 3) : p.media || [];
    if (!files.length) return "";
    return `<div class="${compact ? "feed-images" : "gallery-images"}">${files
      .map((m) => {
        if(!compact && m.mime==='application/pdf') return pdfAttachment(m);
        const visual = m.mime.startsWith("image/")
          ? ("<img src=\"" + (E(m.url)) + "\" alt=\"" + (E(m.name)) + "\" loading=\"lazy\">")
          : ("<span>" + (I("download")) + " " + (E(m.name)) + "</span>");
        return ("<a href=\"" + (E(compact ? p.url : m.url)) + "\"" + (compact ? "" : ' target="_blank" rel="noopener"') + ">" + (visual) + "</a>");
      })
      .join("")}</div>`;
  }
  function gallery(p) {
    const photos = images(p);
    const files = (p.media || []).filter(m => !m.mime.startsWith('image/'));
    const documents = files.length ? `<div class="gallery-documents">${files.map(m => m.mime==='application/pdf'?pdfAttachment(m):`<a class="btn small" href="${E(m.url)}" target="_blank" rel="noopener noreferrer">${I('download')} ${E(m.name)}</a>`).join('')}</div>` : '';
    if (!photos.length) return documents;
    const first = photos[0];
    const navigation = photos.length > 1 ? `<div class="gallery-viewer-controls"><button type="button" class="btn gallery-side-arrow gallery-prev" data-action="gallery-step" data-direction="-1" aria-label="${E(T('Imagen anterior'))}" title="${E(T('Imagen anterior'))}" disabled><span class="gallery-arrow-icon" aria-hidden="true">${I('arrow')}</span></button><button type="button" class="btn gallery-side-arrow" data-action="gallery-step" data-direction="1" aria-label="${E(T('Imagen siguiente'))}" title="${E(T('Imagen siguiente'))}"><span class="gallery-arrow-icon" aria-hidden="true">${I('arrow')}</span></button></div>` : '';
    const thumbnails = photos.length > 1 ? `<div class="gallery-thumbnails" role="group" aria-label="${E(T('Imágenes'))}">${photos.map((m,index) => `<button type="button" class="gallery-thumbnail" data-action="gallery-select" data-index="${index}" data-src="${E(m.url)}" data-name="${E(m.name)}" aria-pressed="${index===0}" aria-label="${E(T('Ver imagen'))} ${index+1}: ${E(m.name)}"><img src="${E(m.url)}" alt="" loading="lazy"><span aria-hidden="true">${index+1}</span></button>`).join('')}</div>` : '';
    return `<section class="gallery-viewer" aria-label="${E(T('Imágenes'))}" data-gallery-index="0"><figure class="gallery-figure"><div class="gallery-stage-shell"><a class="gallery-stage" href="${E(first.url)}" target="_blank" rel="noopener noreferrer" aria-label="${E(T('Ver imagen completa'))}: ${E(first.name)}"><img src="${E(first.url)}" alt="${E(first.name)}"></a>${navigation}</div><figcaption><span class="gallery-position" role="status" aria-live="polite">1 / ${photos.length}</span></figcaption></figure><div class="gallery-viewer-toolbar"><a class="btn small gallery-original" href="${E(first.url)}" target="_blank" rel="noopener noreferrer">${E(T('Ver imagen completa'))} ↗</a></div>${thumbnails}</section>${documents}`;
  }
  function selectGallery(control, index) {
    const viewer = control.closest('.gallery-viewer');
    const thumbnails = [...viewer.querySelectorAll('.gallery-thumbnail')];
    const selected = thumbnails[index];
    if (!selected) return;
    viewer.dataset.galleryIndex = String(index);
    const stage = viewer.querySelector('.gallery-stage');
    stage.href = selected.dataset.src;
    stage.setAttribute('aria-label',T('Ver imagen completa')+': '+selected.dataset.name);
    stage.querySelector('img').src = selected.dataset.src;
    stage.querySelector('img').alt = selected.dataset.name;
    viewer.querySelector('.gallery-original').href = selected.dataset.src;
    viewer.querySelector('.gallery-position').textContent = `${index+1} / ${thumbnails.length}`;
    thumbnails.forEach((thumb,i) => thumb.setAttribute('aria-pressed',String(i===index)));
    viewer.querySelector('[data-direction="-1"]').disabled = index===0;
    viewer.querySelector('[data-direction="1"]').disabled = index===thumbnails.length-1;
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
    if (type === "event") {
      controls += option("modality", "Modalidad", ["Virtual","Presencial","Híbrido"].map(name=>({id:name,name:T(name)})), filter.modality);
    }
    if (type === "topic")
      controls =
        option(
          "parent",
          "Foro",
          [{ id: 0, name: "Conversación general" }, ...forums],
          filter.parent,
        ) + controls;
    return `<details class="advanced-filters" ${["category", "tag", "keyword", "author", "parent", "modality"].some((k) => filter[k] !== undefined && filter[k] !== "") ? "open" : ""}><summary>${E(T("Más filtros"))}</summary><div class="filter-grid">${controls}</div></details>`;
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
        .map((x) => ("<li>" + (value(x, depth + 1)) + "</li>"))
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
    if (!p.meta.generated && !p.meta.ai_enriched && !p.meta.generated_sections) return "";
    const sections = {
      summary: "Resumen",
      technical_note: "Nota técnica",
      conclusions: "Conclusiones",
      frameworks: "Marcos de trabajo",
      norms: "Normativas mencionadas",
      concepts: "Conceptos importantes",
      tags: "Palabras clave",
      moments: "Cápsulas sugeridas",
    };
    const prose = (text) => typeof text === 'string'
      ? `<div class="knowledge-prose">${text.trim().split(/\r?\n\s*\r?\n/).map(paragraph => `<p>${E(paragraph).replaceAll('\n','<br>')}</p>`).join('')}</div>`
      : value(text);
    const list = (items, kind) => {
      if (!Array.isArray(items)) return prose(items);
      const tag = kind === 'conclusions' ? 'ol' : 'ul';
      const style = ['frameworks','concepts','tags'].includes(kind) ? 'knowledge-tag-list' : 'knowledge-reading-list';
      return `<${tag} class="${style}">${items.map(entry => `<li>${value(entry)}</li>`).join('')}</${tag}>`;
    };
    const html = Object.entries(sections)
      .filter(([key]) => p.meta[key]?.length)
      .map(([key, label]) => {
        let body;
        if (key === "moments") body = fragments(p.meta[key]);
        else if (['summary','technical_note'].includes(key)) body = prose(p.meta[key]);
        else body = list(p.meta[key],key);
        return `<section class="knowledge-inline-section knowledge-reading-card knowledge-reading-${key} knowledge-reading-wide ${key === "moments" ? "knowledge-inline-capsules" : ""}"><h3>${E(T(label))}</h3>${body}</section>`;
      })
      .join("");
    return html ? `<div class="generated-inline-content knowledge-reading">${html}</div>` : '';
  }
  return {
    option,
    termFilter,
    duration,
    images,
    attachments,
    gallery,
    selectGallery,
    filters,
    generated,
    agenda,
  };
};
