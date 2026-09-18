/* Administrative analytics: UI only. Capabilities, state and all calculations are checked on the server. */
(() => {
  "use strict";
  window.ASCLAStatistics = ({root, api, E, T, modal, closeModal, toast, date}) => {
    let panel, data, filters = {}, section = "overview", insights, roster, preview, csv = "", page = 1;
    let generation = 0, zoomOptions={}, eventPage=1;
    const label = text => E(T(text));
    const reason = text => {
      let value=String(text||"");
      [
        "Horario no válido o ambiguo; revisa el formato y la zona horaria.",
        "Falta evidencia de duración.",
        "Reconexiones sin horarios: no se pueden descartar intervalos solapados."
      ].forEach(key=>{value=value.split(key).join(T(key));});
      return E(value);
    };
    const number = value => value === null || value === undefined ? "—" : new Intl.NumberFormat().format(value);
    const percent = value => value === null ? "—" : number(value) + "%";
    const action = (text, key, attrs = "", cls = "") => `<button type="button" class="btn ${cls}" data-stats-action="${key}" ${attrs}>${label(text)}</button>`;
    const note = text => `<p class="private-note">${label(text)}</p>`;
    const table = (headers, rows) => `<div class="table-wrap" tabindex="0"><table class="data-table stats-table"><thead><tr>${headers.map(h=>`<th scope="col">${label(h)}</th>`).join("")}</tr></thead><tbody>${rows.length?rows.join(""):`<tr><td colspan="${headers.length}">${label("No hay datos para este período.")}</td></tr>`}</tbody></table></div>`;
    const cell = value => `<td>${value}</td>`;
    const tabs = [["overview","Estadísticas"],["users","Usuarios que más asisten"],["topics","Temas de mayor interés"],["trends","Tendencias"],["ai","IA y tendencias"]];
    const metrics = {events:"Eventos finalizados",registrations:"Inscripciones confirmadas",attendees:"Asistentes únicos",attendances:"Asistencias verificadas",recurring:"Usuarios recurrentes"};
    const delta = n => n === null ? `<span class="stats-change">${label("Sin base previa")}</span>` : `<span class="stats-change ${n>0?'up':n<0?'down':''}">${n>0?'+':''}${number(n)}%</span>`;
    const select = (name, title, options, value) => `<label class="field"><span>${label(title)}</span><select name="${name}"><option value="0">${label("Todos")}</option>${options.map(o=>`<option value="${Number(o.id)}" ${Number(value)===Number(o.id)?'selected':''}>${E(o.name||o.title)}</option>`).join("")}</select></label>`;
    function render() {
      if(!panel?.isConnected || !data)return;
      const p=data.period;
      panel.innerHTML=`<div class="admin-section-heading"><div><span class="eyebrow">${label("PARTICIPACIÓN E INTERESES")}</span><h2>${label("Estadísticas de la comunidad")}</h2><p>${label("Consulta la participación y registra la asistencia de los eventos finalizados.")}</p></div><span class="tag">${E(p.timezone)}</span></div><form data-stats-form="filter" class="stats-filters"><label class="field"><span>${label("Desde")}</span><input required type="date" name="from" value="${E(p.from)}"></label><label class="field"><span>${label("Hasta")}</span><input required type="date" name="to" value="${E(p.to)}"></label>${select('topic','Tema',data.options.topics,filters.topic)}${select('category','Categoría',data.options.categories,filters.category)}<label class="field"><span>${label('Buscar evento')}</span><input name="event_search" value="${E(filters.event_search||'')}" placeholder="${label('Filtrar las primeras 100 opciones')}"></label>${select('event','Evento',data.options.events,filters.event)}<button class="btn primary">${label("Aplicar filtros")}</button></form><nav class="stats-tabs" aria-label="${label("Vistas de estadísticas")}">${tabs.map(([key,title])=>action(title,'section',`data-section="${key}" aria-pressed="${section===key}"`,section===key?'primary':'')).join('')}</nav><div class="stats-coverage" role="status"><strong>${number(data.summary.complete_events)} / ${number(data.summary.events)} ${label("eventos con registro completo")}</strong><span>${label("Las inscripciones no acreditan asistencia. Las tasas usan únicamente registros completos.")}</span></div><div id="stats-view"></div>`;
      const view=panel.querySelector('#stats-view');
      if(section==='overview') overview(view);
      else if(section==='users') users(view);
      else if(section==='topics') topics(view);
      else if(section==='trends') trends(view);
      else ai(view);
    }
    function overview(view) {
      const s=data.summary;
      const cards=[['Usuarios ASCLA',number(s.users),'Cuentas actuales, incluidas las suspendidas.'],['Usuarios nuevos',number(s.new_users),'Altas en el período seleccionado.'],['Usuarios con actividad registrada',number(s.active_users),'Personas con acciones en el registro de actividad.'],['Eventos finalizados',number(s.events),'Eventos publicados y no cancelados.'],['Inscripciones confirmadas',number(s.registrations),'Reservas aceptadas en los eventos del período.'],['Asistencias verificadas',number(s.attendances),'Una asistencia por persona y evento.'],['Asistentes únicos',number(s.attendees),'Personas con al menos una asistencia.'],['Tasa de asistencia',percent(s.rate),'Inscritos que asistieron / inscritos de eventos con registro completo.'],['Promedio por evento',number(s.average),'Asistentes por evento con registro completo.'],['Usuarios recurrentes',number(s.recurring),'Personas que asistieron a dos o más eventos.']];
      view.innerHTML=`<div class="stats-grid">${cards.map(([title,value,hint])=>`<article class="card stats-metric"><span>${label(title)}</span><strong>${value}</strong><small>${label(hint)}</small></article>`).join('')}</div>${note('Los indicadores de usuarios abarcan toda la comunidad. Los filtros de evento, tema y categoría se aplican a participación.')}<section class="card stats-section"><div class="stats-heading"><div><h3>${label('Registro de asistencia')}</h3>${note('Importa un CSV de Zoom o registra asistentes manualmente. Completa el registro cuando hayas verificado toda la lista.')}</div></div>${table(['Evento','Inscripciones','Asistencias','Registro','Acciones'],data.events.map(e=>`<tr>${cell(E(e.title)+`<small>${E(date(e.end))}</small>`)}${cell(number(e.registered))}${cell(number(e.attended))}${cell(label(e.complete?'Completo':'Por completar'))}${cell(action('Registrar asistencia','roster',`data-id="${e.id}"`,'small'))}</tr>`))}<div class="admin-pager">${action('Anterior','event-page',`data-page="${eventPage-1}" ${eventPage===1?'disabled':''}`)}<span>${eventPage} / ${Math.max(1,Math.ceil(data.events_total/20))}</span>${action('Siguiente','event-page',`data-page="${eventPage+1}" ${eventPage*20>=data.events_total?'disabled':''}`)}</div></section>`;
    }
    function users(view) {
      const pages=Math.max(1,Math.ceil(data.ranking_total/20));page=data.ranking_page;
      view.innerHTML=`<section class="card stats-section"><h3>${label('Usuarios que más asisten')}</h3>${note('Ordenados por asistencias verificadas. La tasa usa sus inscripciones en eventos con registro completo; asistir sin inscripción no aumenta esa tasa.')}<p class="private-note">${label('La duración muestra solo los minutos disponibles. Estos datos no modifican roles ni permisos.')}</p>${table(['Usuario','Eventos inscritos','Eventos asistidos','Tasa de asistencia','Minutos registrados','Última asistencia','Recurrencia'],data.ranking.map(u=>`<tr>${cell(E(u.name)+`<small>#${u.id}</small>`)}${cell(number(u.registered))}${cell(number(u.attended))}${cell(percent(u.rate)+`<small>${number(u.eligible)} ${label('inscripciones con registro completo')}</small>`)}${cell(number(u.minutes)+`<small>${u.duration_known} / ${u.attended} ${label('con duración')}</small>`)}${cell(u.last_attendance?E(date(u.last_attendance)):'—')}${cell(label(u.recurring?'Recurrente':'Ocasional'))}</tr>`))}<div class="admin-pager">${action('Anterior','page',`data-page="${page-1}" ${page===1?'disabled':''}`)}<span>${page} / ${pages} · ${number(data.ranking_total)} ${label('usuarios')}</span>${action('Siguiente','page',`data-page="${page+1}" ${page===pages?'disabled':''}`)}</div></section>`;
    }
    function topics(view) {
      const max=Math.max(1,...data.topics.map(t=>t.attendances));
      view.innerHTML=`<section class="card stats-section"><h3>${label('Temas de mayor interés')}</h3>${note('Interés declarado: selección actual en los perfiles, independiente del período. Participación real: asistencia verificada a eventos vinculados al tema.')}<div class="stats-bars">${data.topics.filter(t=>t.attendances>0).slice(0,6).map(t=>`<div class="stats-bar"><span>${E(t.name)}</span><div><i style="width:${Math.round(t.attendances*100/max)}%"></i></div><strong>${number(t.attendances)}</strong></div>`).join('')}</div>${table(['Tema','Interés declarado · usuarios','Perfil','Forms','Contenido publicado','Eventos','Inscripciones','Participación real · personas','Asistencias'],data.topics.map(t=>`<tr>${cell(E(t.name))}${cell(number(t.declared))}${cell(number(t.profile))}${cell(number(t.forms))}${cell(number(t.content))}${cell(number(t.events))}${cell(number(t.registrations))}${cell(number(t.attendees))}${cell(number(t.attendances))}</tr>`))}${note('Una persona puede interesarse en varios temas y un evento puede tener varios temas; las filas no se suman como personas únicas.')}${note('Fuentes: perfiles y Forms revisados (personas únicas, sin sumar duplicados), publicaciones por tema del período, inscripciones y asistencia verificada. Los filtros de evento y categoría afectan a participación, no al interés declarado ni al contenido publicado.')}${!data.index_complete?note('El índice de perfiles históricos se está actualizando. Los conteos declarados todavía son parciales.'):''}</section>`;
    }
    function trends(view) {
      const p=data.period;
      view.innerHTML=`<section class="card stats-section"><h3>${label('Tendencias')}</h3><p>${E(p.from)} — ${E(p.to)} · ${label('comparado con')} ${E(p.previous_from)} — ${E(p.previous_to)}</p>${note('Se comparan períodos consecutivos de igual número de días. Sin una base previa positiva no se calcula un porcentaje de crecimiento.')}${p.includes_today?note('El período actual incluye hoy y todavía puede cambiar.'):''}<p class="stats-source">${label('Registros completos: actual / anterior')} <strong>${data.summary.complete_events}/${data.summary.events} · ${data.previous.complete_events}/${data.previous.events}</strong></p>${note('Las diferencias reflejan los registros disponibles; una cobertura incompleta puede cambiar la comparación.')}${table(['Indicador','Actual','Anterior','Variación'],data.trends.map(t=>`<tr>${cell(label(metrics[t.metric]))}${cell(number(t.current))}${cell(number(t.previous))}${cell(delta(t.change))}</tr>`))}<h3>${label('Evolución por tema')}</h3>${table(['Tema','Asistencias actuales','Asistencias anteriores','Variación'],data.topics.map(t=>`<tr>${cell(E(t.name))}${cell(number(t.attendances))}${cell(number(t.previous_attendances))}${cell(delta(t.change))}</tr>`))}${note('El interés declarado es una foto actual del perfil: no se presenta como una tendencia histórica.')}</section>`;
    }
    function ai(view) {
      if(!insights){view.innerHTML=note('Cargando clasificación de temas…');return;}
      const s=insights;
      view.innerHTML=`<section class="card stats-section"><div class="stats-heading"><div><h3>${label('IA y tendencias')}</h3><p>${label(s.provider==='mock'?'Clasificación local por palabras clave · modo demostración':'Clasificación semántica con el proveedor de IA configurado')}</p></div>${action('Clasificar siguientes 30 eventos','analyze',s.pending?'':'disabled','primary')}</div>${note('Se envían únicamente títulos depurados y el catálogo de temas al proveedor configurado. Se excluyen microeventos privados y sesiones Chatham House. No se envían correos, perfiles ni listas de asistencia.')}${note('La clasificación es una propuesta y no cambia los temas publicados. Las cifras se calculan con los registros guardados, nunca con respuestas de IA.')}<div class="stats-ai-summary"><span><strong>${s.classified} / ${s.eligible}</strong> ${label('eventos analizados')}</span><span><strong>${s.pending}</strong> ${label('pendientes')}</span><span><strong>${s.unclassified}</strong> ${label('sin tema sugerido')}</span><span><strong>${s.excluded}</strong> ${label('excluidos por privacidad')}</span></div>${s.pending?note('La comparación refleja solo los eventos analizados. Continúa la clasificación para completar la cobertura.'):''}${table(['Tema sugerido','Eventos actuales','Eventos anteriores','Asistencias actuales','Asistencias anteriores','Variación'],s.groups.map(g=>`<tr>${cell(E(g.name))}${cell(number(g.events))}${cell(number(g.previous_events))}${cell(number(g.attendances))}${cell(number(g.previous_attendances))}${cell(delta(g.change))}</tr>`))}${s.last?`<p class="private-note">${label('Última clasificación')}: ${E(date(s.last))}</p>`:''}</section>`;
    }
    async function load(target=panel) {
      panel=target;const ticket=++generation;
      panel.innerHTML=note('Cargando estadísticas…');
      try {
        const result=await api('admin/statistics?'+new URLSearchParams({...filters,page,event_page:eventPage}));
        if(ticket!==generation || !panel.isConnected)return;
        data=result;filters={...data.filters,from:data.period.from,to:data.period.to};insights=null;render();
        if(section==='ai')await loadInsights();
      } catch(error) {if(ticket===generation && panel?.isConnected)panel.innerHTML=`<div class="card" role="alert">${E(error.message)} ${action('Reintentar','reload')}</div>`;}
    }
    async function loadInsights() {
      const ticket=generation,result=await api('admin/statistics/topics?'+new URLSearchParams(filters));
      if(ticket!==generation || section!=='ai' || !panel?.isConnected)return;
      insights=result;render();
    }
    function showRoster(value) {
      roster=value;preview=null;csv='';
      const states=[['unknown','Sin verificar'],['present','Asistió'],['partial','Asistencia parcial'],['absent','No asistió'],['review','Revisión manual']];
      const options=status=>states.map(([k,v])=>`<option value="${k}" ${status===k?'selected':''}>${label(v)}</option>`).join('');
      modal(T('Registro de asistencia'),`<div class="stats-roster"><h3>${E(roster.title)}</h3><p class="stats-source">${label(roster.complete?'Registro completo':'Registro por completar')}</p>${note('Los cambios reabren el registro para que puedas verificarlo antes de volver a completarlo.')}<p>${label('Confirmados')}: ${roster.summary.present} · ${label('Parciales')}: ${roster.summary.partial} · ${label('Revisión manual')}: ${roster.summary.review} · ${label('Ausentes')}: ${roster.summary.absent} · ${label('Tasa')}: ${percent(roster.summary.rate)}</p><form data-stats-form="threshold" class="form-actions"><label>${label('Permanencia mínima (%)')} <input name="threshold" type="number" min="1" max="100" value="${roster.threshold}" required></label><button class="btn">${label('Guardar umbral')}</button></form>${action('Historial de CSV','zoom-history')}<div id="stats-zoom-history"></div><form data-stats-form="csv" class="card"><h4>${label('Importar CSV de Zoom')}</h4><label class="field"><span>${label('Archivo CSV · UTF-8 · hasta 512 KB')}</span><input type="file" name="file" accept=".csv,text/csv" required></label><div class="form-grid"><label class="field"><span>${label('Zona horaria del CSV')}</span><input name="timezone" value="${E(data.period.timezone)}" required></label><label class="field"><span>${label('Formato de fecha del CSV')}</span><select name="date_format"><option value="ymd">AAAA-MM-DD / ISO 8601</option><option value="dmy">DD/MM/AAAA</option><option value="mdy">MM/DD/AAAA</option></select></label></div>${note('Se identifica por correo, se agrupan reconexiones y se conservan las correcciones manuales. Primero verás una vista previa.')}<button class="btn">${label('Revisar CSV')}</button><div id="stats-csv-preview" aria-live="polite"></div></form><form data-stats-form="manual" class="card"><h4>${label('Registrar manualmente')}</h4><div class="stats-manual-fields"><label class="field"><span>${label('Correo del usuario ASCLA')}</span><input name="email" type="email" required maxlength="100"></label><label class="field"><span>${label('Asistencia')}</span><select name="status">${options('present')}</select></label><label class="field"><span>${label('Minutos conectados · opcional')}</span><input name="minutes" type="number" min="0" max="${roster.max_minutes}" step="1"></label><button class="btn primary">${label('Guardar asistencia')}</button></div></form><h4>${label('Participantes e inscritos')}</h4>${table(['Usuario','Inscripción','Asistencia y duración'],roster.people.map(u=>`<tr>${cell(E(u.name)+`<small>${E(u.email)}</small>`)}${cell(label(u.registered?'Inscrito':'Sin inscripción'))}${cell(`<form data-stats-form="row" data-user="${u.id}" class="stats-attendance-row"><label><span class="screen-reader-text">${label('Asistencia')}</span><select name="status" aria-label="${label('Asistencia')}">${options(u.status)}</select></label><label><span class="screen-reader-text">${label('Minutos')}</span><input name="minutes" type="number" min="0" max="${roster.max_minutes}" step="1" value="${u.minutes??''}" placeholder="${label('Minutos')}" aria-label="${label('Minutos')}"></label><button class="btn small">${label('Guardar')}</button><small>${label(u.source==='manual'?'Manual':u.source==='zoom'?'Zoom':'Sin verificar')}</small></form><small>${percent(u.percent)} ${reason(u.review_reason)}</small>${u.sessions.length?`<details><summary>${u.sessions.length} ${label('sesiones')}</summary>${u.sessions.map(x=>`<p>${E(x.join||'—')} → ${E(x.leave||'—')}</p>`).join('')}</details>`:''}`)}</tr>`))}<div class="admin-pager">${action('Anterior','roster-page',`data-page="${roster.page-1}" ${roster.page===1?'disabled':''}`)}<span>${roster.page} / ${roster.pages} · ${roster.total}</span>${action('Siguiente','roster-page',`data-page="${roster.page+1}" ${roster.page>=roster.pages?'disabled':''}`)}</div>${roster.complete?action('Reabrir registro','reopen','','ghost'):`<form data-stats-form="complete" class="stats-complete"><label class="check"><input required name="confirm" type="checkbox"><span>${label('He verificado toda la lista. Los inscritos sin marcar se contarán como ausentes al calcular la tasa.')}</span></label><button class="btn primary">${label('Confirmar registro completo')}</button></form>`}</div>`,true);
    }
    function showPreview(result) {
      preview=result;
      root.querySelector('#stats-csv-preview').innerHTML=`<h4>${label('Vista previa de importación')}</h4><p><strong>${result.importable}</strong> ${label('usuarios por registrar')} · <strong>${result.unmatched_total}</strong> ${label('filas sin asociar')} · <strong>${result.protected}</strong> ${label('registros manuales conservados')} · <strong>${result.duplicates}</strong> ${label('filas duplicadas omitidas')}</p>${table(['Usuario','Minutos','Conexiones','Estado','Acción'],result.matched.map(u=>`<tr>${cell(E(u.name)+`<small>${E(u.email)}</small>`)}${cell(number(u.minutes))}${cell(number(u.connections))}${cell(label({present:'Asistió',partial:'Asistencia parcial',absent:'No asistió',review:'Revisión manual'}[u.status])+`<small>${reason(u.review_reason)}</small>`)}${cell(label(u.protected?'Conservar manual':'Registrar asistencia'))}</tr>`))}${result.unmatched.length?`<details open><summary>${label('Filas sin asociar')}</summary>${table(['Fila','Correo','Motivo'],result.unmatched.map(u=>`<tr>${cell(u.line)}${cell(E(u.email)||'—')}${cell(label(u.reason))}</tr>`))}</details>`:''}${note('Las filas sin asociar no crean cuentas ni asistencias. Puedes registrarlas manualmente cuando verifiques la identidad. Se unen intervalos solapados dentro del evento. Las reconexiones sin horarios requieren revisión manual.')}<div class="admin-pager">${action('Anterior','preview-page',`data-page="${result.page-1}" ${result.page===1?'disabled':''}`)}<span>${result.page} / ${result.pages}</span>${action('Siguiente','preview-page',`data-page="${result.page+1}" ${result.page>=result.pages?'disabled':''}`)}</div>${action('Confirmar importación','import',result.importable?'':'disabled','primary')}`;
    }
    async function zoomHistory(pageNumber=1) {
      const h=await api(`admin/attendance/${roster.id}/imports?page=${pageNumber}`),target=root.querySelector('#stats-zoom-history');if(!target)return;
      target.innerHTML=table(['Archivo','Fecha','Estado','Resultado','Acciones'],h.items.map(i=>`<tr>${cell(E(i.filename))}${cell(E(date(i.created_at+'Z')))}${cell(label(i.status==='complete'?'Completada':'Aplicando'))}${cell(`${i.counts.updated||0} ${label('registrados')} · ${i.counts.unidentified||0} ${label('sin identificar')} · ${i.counts.conflict||0} ${label('conflictos')}`)}${cell(action('Ver detalle','zoom-detail',`data-import="${i.id}"`)+(i.status==='applying'?action('Continuar','zoom-resume',`data-import="${i.id}"`):''))}</tr>`))+`<div class="admin-pager">${action('Anterior','zoom-history',`data-page="${pageNumber-1}" ${pageNumber===1?'disabled':''}`)}<span>${pageNumber} / ${Math.max(1,Math.ceil(h.total/20))}</span>${action('Siguiente','zoom-history',`data-page="${pageNumber+1}" ${pageNumber*20>=h.total?'disabled':''}`)}</div><div id="stats-zoom-detail"></div>`;
    }
    async function zoomDetail(importId,pageNumber=1) {
      const d=await api(`admin/attendance/${roster.id}/imports/${importId}?page=${pageNumber}`),target=root.querySelector('#stats-zoom-detail');if(!target)return;
      const names={updated:'Registrada',protected:'Conservar manual',unidentified:'Sin identificar',conflict:'Revisar conflicto',pending:'Pendiente'};
      target.innerHTML=`<h4>${E(d.filename)}</h4>`+table(['Fila','Participante','Resultado','Detalle'],d.rows.map(r=>`<tr>${cell(r.payload.line||r.position)}${cell(E(r.payload.email||''))}${cell(label(names[r.state]||r.state))}${cell(reason(r.payload.reason||r.payload.review_reason)+`<small>${E(r.payload.status||'')}</small>`)}</tr>`))+`<div class="admin-pager">${action('Anterior','zoom-detail',`data-import="${importId}" data-page="${d.page-1}" ${d.page===1?'disabled':''}`)}<span>${d.page} / ${d.pages}</span>${action('Siguiente','zoom-detail',`data-import="${importId}" data-page="${d.page+1}" ${d.page>=d.pages?'disabled':''}`)}</div>`;
    }
    async function refresh() { await load(); }
    async function perform(control,work) {
      if(control.disabled)return;const initial=control.innerHTML;control.disabled=true;control.setAttribute('aria-busy','true');
      try{await work();}catch(error){toast(error.message);}finally{control.disabled=false;control.removeAttribute('aria-busy');if(control.isConnected)control.innerHTML=initial;}
    }
    root.addEventListener('click',event=>{
      const b=event.target.closest('[data-stats-action]');if(!b)return;event.preventDefault();
      void perform(b,async()=>{
        const key=b.dataset.statsAction;
        if(key==='section'){section=b.dataset.section;render();if(section==='ai')await loadInsights();}
        else if(key==='reload')await load();
        else if(key==='page'){page=Number(b.dataset.page);await load();}
        else if(key==='event-page'){eventPage=Number(b.dataset.page);await load();}
        else if(key==='roster')showRoster(await api('admin/attendance/'+Number(b.dataset.id)));
        else if(key==='roster-page')showRoster(await api(`admin/attendance/${roster.id}?page=${Number(b.dataset.page)}`));
        else if(key==='preview-page')showPreview(await api(`admin/attendance/${roster.id}/preview`,{csv,...zoomOptions,page:Number(b.dataset.page)}));
        else if(key==='zoom-history')await zoomHistory(Number(b.dataset.page)||1);
        else if(key==='zoom-detail')await zoomDetail(Number(b.dataset.import),Number(b.dataset.page)||1);
        else if(key==='zoom-resume'){let r;do{r=await api(`admin/attendance/${roster.id}/imports/${Number(b.dataset.import)}`,{});b.textContent=`${r.processed}/${r.total}`;}while(!r.complete);await refresh();showRoster(r.roster);}
        else if(key==='reopen'){const r=await api(`admin/attendance/${roster.id}/complete`,{complete:false});await refresh();showRoster(r);}
        else if(key==='analyze'){
          b.textContent=T('Clasificando…');const current=JSON.stringify(filters);
          const result=await api('admin/statistics/topics',{...filters});
          if(current===JSON.stringify(filters)){insights=result;render();}toast(T('Clasificación actualizada.'));
        } else if(key==='import'){
          let result=await api(`admin/attendance/${roster.id}/import`,{csv,token:preview.token,...zoomOptions});
          while(!result.complete){b.textContent=`${result.processed}/${result.total}`;result=await api(`admin/attendance/${roster.id}/imports/${result.import_id}`,{});}
          await refresh();showRoster(result.roster);toast(T('Asistencia importada.'));
        }
      });
    });
    root.addEventListener('submit',event=>{
      const form=event.target.closest('form[data-stats-form]');if(!form)return;event.preventDefault();
      const button=form.querySelector('button[type="submit"],button:not([type])');
      void perform(button,async()=>{
        const values=Object.fromEntries(new FormData(form)),key=form.dataset.statsForm;
        if(key==='filter'){filters=values;page=1;eventPage=1;await load();}
        else if(key==='csv'){
          preview=null;root.querySelector('#stats-csv-preview').innerHTML='';
          const file=form.elements.file.files[0];if(!file || file.size>524288)throw Error(T('El CSV debe ocupar como máximo 512 KB.'));
          csv=await file.text();zoomOptions={timezone:values.timezone,date_format:values.date_format,filename:file.name};showPreview(await api(`admin/attendance/${roster.id}/preview`,{csv,...zoomOptions}));
        } else if(key==='threshold'){const r=await api(`admin/attendance/${roster.id}/settings`,values);await refresh();showRoster(r);
        } else if(key==='manual' || key==='row'){
          const result=await api(`admin/attendance/${roster.id}`,{...values,...(key==='row'?{user_id:Number(form.dataset.user)}:{})});
          await refresh();showRoster(result);toast(T('Asistencia guardada.'));
        } else if(key==='complete'){
          const result=await api(`admin/attendance/${roster.id}/complete`,{complete:true});
          await refresh();showRoster(result);toast(T('Registro completo.'));
        }
      });
    });
    return {load};
  };
})();
