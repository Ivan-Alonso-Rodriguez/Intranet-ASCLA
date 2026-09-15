/* ASCLA image editor: local, dependency-free crop/zoom workflow with client-side optimization. */
(() => {
  'use strict';

  const MB = 1024 * 1024;
  const contexts = {
    profile: { aspect: 1, width: 800, height: 800, label: '1:1', allowFull: false },
    group: { aspect: 1, width: 800, height: 800, label: '1:1', allowFull: false },
    hub: { aspect: 16 / 9, width: 1600, height: 900, label: '16:9', allowFull: true },
    resource: { aspect: 16 / 9, width: 1600, height: 900, label: '16:9', allowFull: true },
    gallery: { aspect: 4 / 3, width: 1600, height: 1200, label: '4:3', allowFull: true },
    ally: { aspect: 1, width: 1000, height: 1000, label: '1:1', allowFull: true, defaultFull: true },
  };

  const escapeHTML = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  })[char]);

  function filenameBase(name) {
    return String(name || 'imagen').replace(/\.[^.]+$/, '').replace(/[^a-zA-Z0-9._-]+/g, '-').replace(/^-+|-+$/g, '') || 'imagen';
  }

  function fileFromBlob(blob, name) {
    return new File([blob], name, { type: blob.type, lastModified: Date.now() });
  }

  function blobFromCanvas(canvas, type, quality) {
    return new Promise((resolve, reject) => {
      canvas.toBlob((blob) => blob ? resolve(blob) : reject(new Error('No se pudo procesar la imagen.')), type, quality);
    });
  }

  let webpPromise;
  async function webpSupported() {
    if (webpPromise) return webpPromise;
    webpPromise = (async () => {
      try {
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = 2;
        const blob = await blobFromCanvas(canvas, 'image/webp', .8);
        return blob.type === 'image/webp';
      } catch { return false; }
    })();
    return webpPromise;
  }

  function imageFromFile(file) {
    return new Promise((resolve, reject) => {
      const url = URL.createObjectURL(file);
      const image = new Image();
      image.decoding = 'async';
      image.onload = () => resolve({ image, url });
      image.onerror = () => { URL.revokeObjectURL(url); reject(new Error('No se pudo leer la imagen seleccionada.')); };
      image.src = url;
    });
  }

  function drawCanvas(image, width, height, sx = 0, sy = 0, sw = image.naturalWidth, sh = image.naturalHeight) {
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(width));
    canvas.height = Math.max(1, Math.round(height));
    const ctx = canvas.getContext('2d', { alpha: true });
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(image, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);
    return canvas;
  }

  async function encodeCanvas(canvas, preferredType, startQuality, maxBytes) {
    let type = preferredType;
    let quality = startQuality;
    let blob = await blobFromCanvas(canvas, type, quality);
    if (blob.type !== type && type === 'image/webp') {
      type = 'image/jpeg';
      blob = await blobFromCanvas(canvas, type, quality);
    }
    if (type === 'image/png') return blob;
    while (blob.size > maxBytes && quality > .7) {
      quality = Math.max(.7, quality - .05);
      blob = await blobFromCanvas(canvas, type, quality);
    }
    return blob;
  }

  async function optimizedMaster(file, image) {
    const maxEdge = 2560;
    const naturalW = image.naturalWidth;
    const naturalH = image.naturalHeight;
    const scale = Math.min(1, maxEdge / Math.max(naturalW, naturalH));
    let width = Math.max(1, Math.round(naturalW * scale));
    let height = Math.max(1, Math.round(naturalH * scale));
    const preferWebP = await webpSupported();
    let type = preferWebP ? 'image/webp' : (file.type === 'image/png' ? 'image/png' : 'image/jpeg');
    let canvas = drawCanvas(image, width, height);
    let blob = await encodeCanvas(canvas, type, .9, 2.35 * MB);
    // Very detailed images can still exceed the private-media cap. Downscale in controlled steps.
    while (blob.size > 2.75 * MB && Math.max(width, height) > 1600) {
      const step = .82;
      width = Math.round(width * step);
      height = Math.round(height * step);
      canvas = drawCanvas(image, width, height);
      blob = await encodeCanvas(canvas, type, .86, 2.35 * MB);
    }
    const extension = blob.type === 'image/webp' ? 'webp' : blob.type === 'image/png' ? 'png' : 'jpg';
    return fileFromBlob(blob, `${filenameBase(file.name)}-master.${extension}`);
  }

  function outputDimensions(config, sourceW, sourceH, full) {
    if (!full) {
      // Never upscale a small source just to hit the presentation preset.
      const scale = Math.min(1, config.width / sourceW, config.height / sourceH);
      return { width: Math.max(1, Math.round(sourceW * scale)), height: Math.max(1, Math.round(sourceH * scale)) };
    }
    const maxEdge = Math.min(1800, Math.max(config.width, config.height));
    const scale = Math.min(1, maxEdge / Math.max(sourceW, sourceH));
    return { width: Math.max(1, Math.round(sourceW * scale)), height: Math.max(1, Math.round(sourceH * scale)) };
  }

  async function outputFile(file, image, config, crop, full) {
    const preferWebP = await webpSupported();
    const type = preferWebP ? 'image/webp' : (file.type === 'image/png' ? 'image/png' : 'image/jpeg');
    const dims = outputDimensions(config, full ? image.naturalWidth : crop.sw, full ? image.naturalHeight : crop.sh, full);
    const canvas = full
      ? drawCanvas(image, dims.width, dims.height)
      : drawCanvas(image, dims.width, dims.height, crop.sx, crop.sy, crop.sw, crop.sh);
    const blob = await encodeCanvas(canvas, type, .87, 1.45 * MB);
    const extension = blob.type === 'image/webp' ? 'webp' : blob.type === 'image/png' ? 'png' : 'jpg';
    return fileFromBlob(blob, `${filenameBase(file.name)}-${full ? 'optimizada' : 'recorte'}.${extension}`);
  }

  function human(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < MB) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / MB).toFixed(bytes < 10 * MB ? 1 : 0)} MB`;
  }

  function edit(file, options = {}) {
    return new Promise(async (resolve, reject) => {
      const messages = {
        invalid: options.messages?.invalid || 'Selecciona una imagen JPG, PNG o WebP.',
        tooLarge: options.messages?.tooLarge || 'La imagen original supera 15 MB. Reduce su tamaño antes de continuar.',
        readError: options.messages?.readError || 'No se pudo leer la imagen seleccionada.',
        tooManyPixels: options.messages?.tooManyPixels || 'La imagen supera el límite de 28 megapíxeles.',
      };
      if (!(file instanceof File) || !/^image\/(jpeg|png|webp)$/.test(file.type)) {
        reject(new Error(messages.invalid));
        return;
      }
      if (file.size > 15 * MB) {
        reject(new Error(messages.tooLarge));
        return;
      }
      const context = options.context in contexts ? options.context : 'hub';
      const config = { ...contexts[context], ...(options.config || {}) };
      let loaded;
      try { loaded = await imageFromFile(file); } catch (error) { reject(new Error(messages.readError)); return; }
      const { image, url } = loaded;
      if (!image.naturalWidth || !image.naturalHeight || image.naturalWidth * image.naturalHeight > 28_000_000) {
        URL.revokeObjectURL(url);
        reject(new Error(messages.tooManyPixels));
        return;
      }

      const labels = {
        title: options.title || 'Ajustar imagen',
        hint: options.hint || 'Arrastra la imagen para moverla y usa el control de zoom antes de guardar.',
        ratio: options.ratioLabel ? `${options.ratioLabel} ${config.label}` : `Proporción recomendada ${config.label}`,
        full: options.fullLabel || 'Usar imagen completa',
        crop: options.cropLabel || 'Recortar',
        reset: options.resetLabel || 'Restablecer',
        cancel: options.cancelLabel || 'Cancelar',
        apply: options.applyLabel || 'Usar imagen',
        zoom: options.zoomLabel || 'Zoom',
        source: options.sourceLabel || 'Imagen fuente',
        privacy: options.privacyLabel || 'ASCLA crea una copia optimizada sin recortar y una versión para mostrar. La edición no modifica el archivo de tu dispositivo.',
      };

      const overlay = document.createElement('div');
      overlay.className = 'ascla-image-editor-backdrop';
      overlay.innerHTML = `<section class="ascla-image-editor" role="dialog" aria-modal="true" aria-label="${escapeHTML(labels.title)}">
        <header class="ascla-image-editor-head"><div><h2>${escapeHTML(labels.title)}</h2><p>${escapeHTML(labels.hint)}</p></div><button type="button" class="image-editor-close" aria-label="${escapeHTML(labels.cancel)}">×</button></header>
        <div class="ascla-image-editor-body">
          <div class="image-editor-workspace">
            <div class="image-editor-stage" tabindex="0" aria-label="${escapeHTML(labels.title)}"><img alt="" draggable="false"><div class="image-editor-crop-frame"><span></span></div></div>
            <div class="image-editor-tools">
              <button type="button" data-image-mode="crop" class="btn small primary">${escapeHTML(labels.crop)} · ${escapeHTML(config.label)}</button>
              ${config.allowFull ? `<button type="button" data-image-mode="full" class="btn small">${escapeHTML(labels.full)}</button>` : ''}
              <button type="button" data-image-reset class="btn small ghost">${escapeHTML(labels.reset)}</button>
            </div>
            <label class="image-editor-zoom"><span>${escapeHTML(labels.zoom)}</span><button type="button" data-image-zoom="out" aria-label="Zoom -">−</button><input type="range" min="100" max="300" value="100" step="1" aria-label="${escapeHTML(labels.zoom)}"><button type="button" data-image-zoom="in" aria-label="Zoom +">+</button></label>
          </div>
          <aside class="image-editor-info"><strong>${escapeHTML(labels.source)}</strong><span>${escapeHTML(file.name)}</span><span>${image.naturalWidth} × ${image.naturalHeight}px · ${human(file.size)}</span><div class="image-editor-ratio">${escapeHTML(labels.ratio)}</div><p>${escapeHTML(labels.privacy)}</p></aside>
        </div>
        <footer class="ascla-image-editor-actions"><span class="image-editor-status" role="status" aria-live="polite"></span><button type="button" class="btn" data-image-cancel>${escapeHTML(labels.cancel)}</button><button type="button" class="btn primary" data-image-apply>${escapeHTML(labels.apply)}</button></footer>
      </section>`;
      (document.getElementById('ascla-root') || document.body).append(overlay);

      const stage = overlay.querySelector('.image-editor-stage');
      const cropFrame = overlay.querySelector('.image-editor-crop-frame');
      const display = stage.querySelector('img');
      const slider = overlay.querySelector('.image-editor-zoom input');
      const status = overlay.querySelector('.image-editor-status');
      const apply = overlay.querySelector('[data-image-apply]');
      let mode = config.defaultFull ? 'full' : 'crop';
      let zoom = 1;
      let offsetX = 0;
      let offsetY = 0;
      let dragging = false;
      let pointer = null;
      let startX = 0, startY = 0, initialX = 0, initialY = 0;
      let frameRect = null;
      let baseScale = 1;

      display.src = url;

      function layoutFrame() {
        const rect = stage.getBoundingClientRect();
        const pad = Math.max(18, Math.min(44, rect.width * .055));
        const maxW = Math.max(80, rect.width - pad * 2);
        const maxH = Math.max(80, rect.height - pad * 2);
        let width = maxW;
        let height = width / config.aspect;
        if (height > maxH) { height = maxH; width = height * config.aspect; }
        frameRect = { left: (rect.width - width) / 2, top: (rect.height - height) / 2, width, height };
        Object.assign(cropFrame.style, { left: `${frameRect.left}px`, top: `${frameRect.top}px`, width: `${width}px`, height: `${height}px` });
        baseScale = Math.max(width / image.naturalWidth, height / image.naturalHeight);
        clampOffsets();
        renderImage();
      }

      function clampOffsets() {
        if (!frameRect || mode === 'full') { offsetX = 0; offsetY = 0; return; }
        const scale = baseScale * zoom;
        const renderW = image.naturalWidth * scale;
        const renderH = image.naturalHeight * scale;
        const maxX = Math.max(0, (renderW - frameRect.width) / 2);
        const maxY = Math.max(0, (renderH - frameRect.height) / 2);
        offsetX = Math.max(-maxX, Math.min(maxX, offsetX));
        offsetY = Math.max(-maxY, Math.min(maxY, offsetY));
      }

      function renderImage() {
        if (!frameRect) return;
        if (mode === 'full') {
          const rect = stage.getBoundingClientRect();
          const scale = Math.min((rect.width - 36) / image.naturalWidth, (rect.height - 36) / image.naturalHeight);
          const width = image.naturalWidth * scale, height = image.naturalHeight * scale;
          Object.assign(display.style, { width: `${width}px`, height: `${height}px`, left: `${(rect.width - width) / 2}px`, top: `${(rect.height - height) / 2}px` });
          cropFrame.hidden = true;
          stage.classList.add('is-full');
          return;
        }
        stage.classList.remove('is-full');
        cropFrame.hidden = false;
        const scale = baseScale * zoom;
        const width = image.naturalWidth * scale;
        const height = image.naturalHeight * scale;
        const centerX = frameRect.left + frameRect.width / 2;
        const centerY = frameRect.top + frameRect.height / 2;
        Object.assign(display.style, { width: `${width}px`, height: `${height}px`, left: `${centerX - width / 2 + offsetX}px`, top: `${centerY - height / 2 + offsetY}px` });
      }

      function cropData() {
        const scale = baseScale * zoom;
        const width = image.naturalWidth * scale;
        const height = image.naturalHeight * scale;
        const centerX = frameRect.left + frameRect.width / 2;
        const centerY = frameRect.top + frameRect.height / 2;
        const imageLeft = centerX - width / 2 + offsetX;
        const imageTop = centerY - height / 2 + offsetY;
        const sx = Math.max(0, (frameRect.left - imageLeft) / scale);
        const sy = Math.max(0, (frameRect.top - imageTop) / scale);
        return {
          sx,
          sy,
          sw: Math.min(image.naturalWidth - sx, frameRect.width / scale),
          sh: Math.min(image.naturalHeight - sy, frameRect.height / scale),
        };
      }

      function reset() {
        zoom = 1; offsetX = 0; offsetY = 0; slider.value = '100'; clampOffsets(); renderImage();
      }

      function setMode(next) {
        mode = next === 'full' && config.allowFull ? 'full' : 'crop';
        overlay.querySelectorAll('[data-image-mode]').forEach((button) => button.classList.toggle('primary', button.dataset.imageMode === mode));
        overlay.querySelector('.image-editor-zoom').hidden = mode === 'full';
        overlay.querySelector('.image-editor-ratio').textContent = mode === 'full' ? labels.full : labels.ratio;
        reset();
      }

      function setZoom(next) {
        zoom = Math.max(1, Math.min(3, next));
        slider.value = String(Math.round(zoom * 100));
        clampOffsets(); renderImage();
      }

      function cleanup(value, error = null) {
        URL.revokeObjectURL(url);
        window.removeEventListener('resize', layoutFrame);
        overlay.remove();
        if (error) reject(error); else resolve(value);
      }

      overlay.addEventListener('click', async (event) => {
        const target = event.target.closest('button');
        if (!target) return;
        if (target.matches('[data-image-cancel], .image-editor-close')) { cleanup(null); return; }
        if (target.dataset.imageMode) { setMode(target.dataset.imageMode); return; }
        if (target.hasAttribute('data-image-reset')) { reset(); return; }
        if (target.dataset.imageZoom === 'in') { setZoom(zoom + .1); return; }
        if (target.dataset.imageZoom === 'out') { setZoom(zoom - .1); return; }
        if (target.hasAttribute('data-image-apply')) {
          apply.disabled = true;
          status.textContent = options.processingLabel || 'Optimizando imagen…';
          try {
            const master = await optimizedMaster(file, image);
            const full = mode === 'full';
            const output = full ? master : await outputFile(file, image, config, cropData(), false);
            const total = full ? output.size : master.size + output.size;
            cleanup({
              masterFile: full ? null : master,
              outputFile: output,
              full,
              context,
              inputBytes: file.size,
              storedBytes: total,
              sourceWidth: image.naturalWidth,
              sourceHeight: image.naturalHeight,
              outputWidth: full ? Math.round(image.naturalWidth * Math.min(1, Math.min(1800, Math.max(config.width, config.height)) / Math.max(image.naturalWidth, image.naturalHeight))) : config.width,
              outputHeight: full ? Math.round(image.naturalHeight * Math.min(1, Math.min(1800, Math.max(config.width, config.height)) / Math.max(image.naturalWidth, image.naturalHeight))) : config.height,
            });
          } catch (error) {
            apply.disabled = false;
            status.textContent = error.message || 'No se pudo procesar la imagen.';
          }
        }
      });

      slider.addEventListener('input', () => setZoom(Number(slider.value) / 100));
      stage.addEventListener('wheel', (event) => {
        if (mode === 'full') return;
        event.preventDefault();
        setZoom(zoom + (event.deltaY < 0 ? .08 : -.08));
      }, { passive: false });
      stage.addEventListener('pointerdown', (event) => {
        if (mode === 'full') return;
        dragging = true; pointer = event.pointerId; startX = event.clientX; startY = event.clientY; initialX = offsetX; initialY = offsetY;
        stage.setPointerCapture?.(pointer); stage.classList.add('is-dragging');
      });
      stage.addEventListener('pointermove', (event) => {
        if (!dragging || event.pointerId !== pointer) return;
        offsetX = initialX + event.clientX - startX;
        offsetY = initialY + event.clientY - startY;
        clampOffsets(); renderImage();
      });
      const endDrag = (event) => {
        if (!dragging || (event.pointerId !== undefined && event.pointerId !== pointer)) return;
        dragging = false; stage.classList.remove('is-dragging');
        try { stage.releasePointerCapture?.(pointer); } catch {}
        pointer = null;
      };
      stage.addEventListener('pointerup', endDrag);
      stage.addEventListener('pointercancel', endDrag);
      overlay.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); cleanup(null); return; }
        if (mode !== 'crop') return;
        const step = event.shiftKey ? 12 : 3;
        if (event.key === 'ArrowLeft') offsetX -= step;
        else if (event.key === 'ArrowRight') offsetX += step;
        else if (event.key === 'ArrowUp') offsetY -= step;
        else if (event.key === 'ArrowDown') offsetY += step;
        else return;
        event.preventDefault(); clampOffsets(); renderImage();
      });
      window.addEventListener('resize', layoutFrame);
      requestAnimationFrame(() => { layoutFrame(); setMode(mode); stage.focus(); });
    });
  }

  window.ASCLAImageEditor = { edit, contexts };
})();
