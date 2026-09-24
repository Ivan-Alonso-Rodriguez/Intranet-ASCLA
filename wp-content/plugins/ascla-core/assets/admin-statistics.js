/* Administrative analytics: UI only. Capabilities, state and all calculations are checked on the server. */
(() => {
  "use strict";
  function attendanceSource(source) {
    if(source==='manual')return 'Manual';
    if(source==='zoom')return 'Zoom';
    return 'Sin verificar';
  }
  globalThis.ASCLAStatistics = ({root, api, E, T, modal, closeModal, toast, date}) => {
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
    const cell = value => `<td>${value}</td>`;
    const small = value => `<small>${value}</small>`;
    const row = values => `<tr>${values.map(cell).join("")}</tr>`;
    const table = (headers, rows) => {
      const headerHtml = headers.map(h => `<th scope="col">${label(h)}</th>`).join("");
      const bodyHtml = rows.length
        ? rows.join("")
        : `<tr><td colspan="${headers.length}">${label("No hay datos para este período.")}</td></tr>`;
      return `<div class="table-wrap" tabindex="0"><table class="data-table stats-table"><thead><tr>${headerHtml}</tr></thead><tbody>${bodyHtml}</tbody></table></div>`;
    };
    const tabs = [["overview","Estadísticas"],["users","Usuarios que más asisten"],["topics","Temas de mayor interés"],["trends","Tendencias"],["ai","IA y tendencias"]];
    const metrics = {events:"Eventos finalizados",registrations:"Inscripciones confirmadas",attendees:"Asistentes únicos",attendances:"Asistencias verificadas",recurring:"Usuarios recurrentes"};
    const attendanceStates=[['unknown','Sin verificar'],['present','Asistió'],['partial','Asistencia parcial'],['absent','No asistió'],['review','Revisión manual']];
    const attendanceOptions=status=>attendanceStates.map(([key,text])=>{
      const selected = status===key ? 'selected' : '';
      return `<option value="${key}" ${selected}>${label(text)}</option>`;
    }).join('');
    const delta = n => {
      if(n===null)return `<span class="stats-change">${label("Sin base previa")}</span>`;
      let cls='';
      if(n>0)cls='up';
      else if(n<0)cls='down';
      const sign=n>0?'+':'';
      return `<span class="stats-change ${cls}">${sign}${number(n)}%</span>`;
    };
    const select = (name, title, options, value) => {
      const optionsHtml=options.map(o=>{
        const selected=Number(value)===Number(o.id)?'selected':'';
        return `<option value="${Number(o.id)}" ${selected}>${E(o.name||o.title)}</option>`;
      }).join("");
      return `<label class="field"><span>${label(title)}</span><select name="${name}"><option value="0">${label("Todos")}</option>${optionsHtml}</select></label>`;
    };
    const pagerAttrs = (pageNumber, disabled, extra='') => 'data-page="'+pageNumber+'" '+(disabled?'disabled':'')+(extra?' '+extra:'');
    function render() {
      if(!panel?.isConnected || !data)return;
      const p=data.period;
      const tabsHtml=tabs.map(([key,title])=>{
        const attrs='data-section="'+key+'" aria-pressed="'+(section===key)+'"';
        return action(title,'section',attrs,section===key?'primary':'');
      }).join('');
      panel.innerHTML=`<div class="admin-section-heading"><div><span class="eyebrow">${label("PARTICIPACIÓN E INTERESES")}</span><h2>${label("Estadísticas de la comunidad")}</h2><p>${label("Consulta la participación y registra la asistencia de los eventos finalizados.")}</p></div><span class="tag">${E(p.timezone)}</span></div><form data-stats-form="filter" class="stats-filters"><label class="field"><span>${label("Desde")}</span><input required type="date" name="from" value="${E(p.from)}"></label><label class="field"><span>${label("Hasta")}</span><input required type="date" name="to" value="${E(p.to)}"></label>${select('topic','Tema',data.options.topics,filters.topic)}${select('category','Categoría',data.options.categories,filters.category)}<label class="field"><span>${label('Buscar evento')}</span><input name="event_search" value="${E(filters.event_search||'')}" placeholder="${label('Filtrar las primeras 100 opciones')}"></label>${select('event','Evento',data.options.events,filters.event)}<button class="btn primary">${label("Aplicar filtros")}</button></form><nav class="stats-tabs" aria-label="${label("Vistas de estadísticas")}">${tabsHtml}</nav><div class="stats-coverage" role="status"><strong>${number(data.summary.complete_events)} / ${number(data.summary.events)} ${label("eventos con registro completo")}</strong><span>${label("Las inscripciones no acreditan asistencia. Las tasas usan únicamente registros completos.")}</span></div><div id="stats-view"></div>`;
      const view=panel.querySelector('#stats-view');
      if(section==='overview') overview(view);
      else if(section==='users') users(view);
      else if(section==='topics') topics(view);
      else if(section==='trends') trends(view);
      else ai(view);
    }
    function renderEventRow(e) {
      const title=E(e.title)+small(E(date(e.end)));
      const attrs='data-id="'+e.id+'"';
      return row([title,number(e.registered),number(e.attended),label(e.complete?'Completo':'Por completar'),action('Registrar asistencia','roster',attrs,'small')]);
    }
    function overview(view) {
      const s=data.summary;
      const cards=[['Usuarios ASCLA',number(s.users),'Cuentas actuales, incluidas las suspendidas.'],['Usuarios nuevos',number(s.new_users),'Altas en el período seleccionado.'],['Usuarios con actividad registrada',number(s.active_users),'Personas con acciones en el registro de actividad.'],['Eventos finalizados',number(s.events),'Eventos publicados y no cancelados.'],['Inscripciones confirmadas',number(s.registrations),'Reservas aceptadas en los eventos del período.'],['Asistencias verificadas',number(s.attendances),'Una asistencia por persona y evento.'],['Asistentes únicos',number(s.attendees),'Personas con al menos una asistencia.'],['Tasa de asistencia',percent(s.rate),'Inscritos que asistieron / inscritos de eventos con registro completo.'],['Promedio por evento',number(s.average),'Asistentes por evento con registro completo.'],['Usuarios recurrentes',number(s.recurring),'Personas que asistieron a dos o más eventos.']];
      const cardsHtml=cards.map(([title,value,hint])=>`<article class="card stats-metric"><span>${label(title)}</span><strong>${value}</strong><small>${label(hint)}</small></article>`).join('');
      const eventTable=table(['Evento','Inscripciones','Asistencias','Registro','Acciones'],data.events.map(renderEventRow));
      const previous=action('Anterior','event-page',pagerAttrs(eventPage-1,eventPage===1));
      const next=action('Siguiente','event-page',pagerAttrs(eventPage+1,eventPage*20>=data.events_total));
      view.innerHTML=`<div class="stats-grid">${cardsHtml}</div>${note('Los indicadores de usuarios abarcan toda la comunidad. Los filtros de evento, tema y categoría se aplican a participación.')}<section class="card stats-section"><div class="stats-heading"><div><h3>${label('Registro de asistencia')}</h3>${note('Importa un CSV de Zoom o registra asistentes manualmente. Completa el registro cuando hayas verificado toda la lista.')}</div></div>${eventTable}<div class="admin-pager">${previous}<span>${eventPage} / ${Math.max(1,Math.ceil(data.events_total/20))}</span>${next}</div></section>`;
    }
    function renderUserRow(u) {
      const user=E(u.name)+small('#'+u.id);
      const rate=percent(u.rate)+small(number(u.eligible)+' '+label('inscripciones con registro completo'));
      const minutes=number(u.minutes)+small(u.duration_known+' / '+u.attended+' '+label('con duración'));
      const last=u.last_attendance?E(date(u.last_attendance)):'—';
      return row([user,number(u.registered),number(u.attended),rate,minutes,last,label(u.recurring?'Recurrente':'Ocasional')]);
    }
    function users(view) {
      const pages=Math.max(1,Math.ceil(data.ranking_total/20));page=data.ranking_page;
      const rankingTable=table(['Usuario','Eventos inscritos','Eventos asistidos','Tasa de asistencia','Minutos registrados','Última asistencia','Recurrencia'],data.ranking.map(renderUserRow));
      const previous=action('Anterior','page',pagerAttrs(page-1,page===1));
      const next=action('Siguiente','page',pagerAttrs(page+1,page===pages));
      view.innerHTML=`<section class="card stats-section"><h3>${label('Usuarios que más asisten')}</h3>${note('Ordenados por asistencias verificadas. La tasa usa sus inscripciones en eventos con registro completo; asistir sin inscripción no aumenta esa tasa.')}<p class="private-note">${label('La duración muestra solo los minutos disponibles. Estos datos no modifican roles ni permisos.')}</p>${rankingTable}<div class="admin-pager">${previous}<span>${page} / ${pages} · ${number(data.ranking_total)} ${label('usuarios')}</span>${next}</div></section>`;
    }
    function renderTopicRow(t) {
      return row([E(t.name),number(t.declared),number(t.profile),number(t.forms),number(t.content),number(t.events),number(t.registrations),number(t.attendees),number(t.attendances)]);
    }
    function topics(view) {
      const max=Math.max(1,...data.topics.map(t=>t.attendances));
      const bars=data.topics.filter(t=>t.attendances>0).slice(0,6).map(t=>`<div class="stats-bar"><span>${E(t.name)}</span><div><i style="width:${Math.round(t.attendances*100/max)}%"></i></div><strong>${number(t.attendances)}</strong></div>`).join('');
      const topicTable=table(['Tema','Interés declarado · usuarios','Perfil','Forms','Contenido publicado','Eventos','Inscripciones','Participación real · personas','Asistencias'],data.topics.map(renderTopicRow));
      const indexNote=data.index_complete?'':note('El índice de perfiles históricos se está actualizando. Los conteos declarados todavía son parciales.');
      view.innerHTML=`<section class="card stats-section"><h3>${label('Temas de mayor interés')}</h3>${note('Interés declarado: selección actual en los perfiles, independiente del período. Participación real: asistencia verificada a eventos vinculados al tema.')}<div class="stats-bars">${bars}</div>${topicTable}${note('Una persona puede interesarse en varios temas y un evento puede tener varios temas; las filas no se suman como personas únicas.')}${note('Fuentes: perfiles y Forms revisados (personas únicas, sin sumar duplicados), publicaciones por tema del período, inscripciones y asistencia verificada. Los filtros de evento y categoría afectan a participación, no al interés declarado ni al contenido publicado.')}${indexNote}</section>`;
    }
    function renderTrendRow(t) {
      return row([label(metrics[t.metric]),number(t.current),number(t.previous),delta(t.change)]);
    }
    function renderTopicTrendRow(t) {
      return row([E(t.name),number(t.attendances),number(t.previous_attendances),delta(t.change)]);
    }
    function trends(view) {
      const p=data.period;
      const currentNote=p.includes_today?note('El período actual incluye hoy y todavía puede cambiar.'):'';
      const trendsTable=table(['Indicador','Actual','Anterior','Variación'],data.trends.map(renderTrendRow));
      const topicsTable=table(['Tema','Asistencias actuales','Asistencias anteriores','Variación'],data.topics.map(renderTopicTrendRow));
      view.innerHTML=`<section class="card stats-section"><h3>${label('Tendencias')}</h3><p>${E(p.from)} — ${E(p.to)} · ${label('comparado con')} ${E(p.previous_from)} — ${E(p.previous_to)}</p>${note('Se comparan períodos consecutivos de igual número de días. Sin una base previa positiva no se calcula un porcentaje de crecimiento.')}${currentNote}<p class="stats-source">${label('Registros completos: actual / anterior')} <strong>${data.summary.complete_events}/${data.summary.events} · ${data.previous.complete_events}/${data.previous.events}</strong></p>${note('Las diferencias reflejan los registros disponibles; una cobertura incompleta puede cambiar la comparación.')}${trendsTable}<h3>${label('Evolución por tema')}</h3>${topicsTable}${note('El interés declarado es una foto actual del perfil: no se presenta como una tendencia histórica.')}</section>`;
    }
    function renderAiRow(g) {
      return row([E(g.name),number(g.events),number(g.previous_events),number(g.attendances),number(g.previous_attendances),delta(g.change)]);
    }
    function ai(view) {
      if(!insights){view.innerHTML=note('Cargando clasificación de temas…');return;}
      const s=insights;
      const providerText=s.provider==='mock'?'Clasificación local por palabras clave · modo demostración':'Clasificación semántica con el proveedor de IA configurado';
      const analyzeAttrs=s.pending?'':'disabled';
      const pendingNote=s.pending?note('La comparación refleja solo los eventos analizados. Continúa la clasificación para completar la cobertura.'):'';
      const aiTable=table(['Tema sugerido','Eventos actuales','Eventos anteriores','Asistencias actuales','Asistencias anteriores','Variación'],s.groups.map(renderAiRow));
      const last=s.last?`<p class="private-note">${label('Última clasificación')}: ${E(date(s.last))}</p>`:'';
      view.innerHTML=`<section class="card stats-section"><div class="stats-heading"><div><h3>${label('IA y tendencias')}</h3><p>${label(providerText)}</p></div>${action('Clasificar siguientes 30 eventos','analyze',analyzeAttrs,'primary')}</div>${note('Se envían únicamente títulos depurados y el catálogo de temas al proveedor configurado. Se excluyen microeventos privados y sesiones Chatham House. No se envían correos, perfiles ni listas de asistencia.')}${note('La clasificación es una propuesta y no cambia los temas publicados. Las cifras se calculan con los registros guardados, nunca con respuestas de IA.')}<div class="stats-ai-summary"><span><strong>${s.classified} / ${s.eligible}</strong> ${label('eventos analizados')}</span><span><strong>${s.pending}</strong> ${label('pendientes')}</span><span><strong>${s.unclassified}</strong> ${label('sin tema sugerido')}</span><span><strong>${s.excluded}</strong> ${label('excluidos por privacidad')}</span></div>${pendingNote}${aiTable}${last}</section>`;
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
    function renderSessions(sessions) {
      if(!sessions.length)return '';
      const items=sessions.map(x=>`<p>${E(x.join||'—')} → ${E(x.leave||'—')}</p>`).join('');
      return `<details><summary>${sessions.length} ${label('sesiones')}</summary>${items}</details>`;
    }
    function renderRosterRow(u) {
      const identity=E(u.name)+small(E(u.email));
      const attendanceForm=`<form data-stats-form="row" data-user="${u.id}" class="stats-attendance-row"><label><span class="screen-reader-text">${label('Asistencia')}</span><select name="status" aria-label="${label('Asistencia')}">${attendanceOptions(u.status)}</select></label><label><span class="screen-reader-text">${label('Minutos')}</span><input name="minutes" type="number" min="0" max="${roster.max_minutes}" step="1" value="${u.minutes??''}" placeholder="${label('Minutos')}" aria-label="${label('Minutos')}"></label><button class="btn small">${label('Guardar')}</button><small>${label(attendanceSource(u.source))}</small></form>`;
      const attendance=attendanceForm+small(percent(u.percent)+' '+reason(u.review_reason))+renderSessions(u.sessions);
      return row([identity,label(u.registered?'Inscrito':'Sin inscripción'),attendance]);
    }
    function showRoster(value) {
      roster=value;preview=null;csv='';
      const peopleTable=table(['Usuario','Inscripción','Asistencia y duración'],roster.people.map(renderRosterRow));
      const previous=action('Anterior','roster-page',pagerAttrs(roster.page-1,roster.page===1));
      const next=action('Siguiente','roster-page',pagerAttrs(roster.page+1,roster.page>=roster.pages));
      const completion=roster.complete
        ? action('Reabrir registro','reopen','','ghost')
        : `<form data-stats-form="complete" class="stats-complete"><label class="check"><input required name="confirm" type="checkbox"><span>${label('He verificado toda la lista. Los inscritos sin marcar se contarán como ausentes al calcular la tasa.')}</span></label><button class="btn primary">${label('Confirmar registro completo')}</button></form>`;
      const body=`<div class="stats-roster"><h3>${E(roster.title)}</h3><p class="stats-source">${label(roster.complete?'Registro completo':'Registro por completar')}</p>${note('Los cambios reabren el registro para que puedas verificarlo antes de volver a completarlo.')}<p>${label('Confirmados')}: ${roster.summary.present} · ${label('Parciales')}: ${roster.summary.partial} · ${label('Revisión manual')}: ${roster.summary.review} · ${label('Ausentes')}: ${roster.summary.absent} · ${label('Tasa')}: ${percent(roster.summary.rate)}</p><form data-stats-form="threshold" class="form-actions"><label>${label('Permanencia mínima (%)')} <input name="threshold" type="number" min="1" max="100" value="${roster.threshold}" required></label><button class="btn">${label('Guardar umbral')}</button></form>${action('Historial de CSV','zoom-history')}<div id="stats-zoom-history"></div><form data-stats-form="csv" class="card"><h4>${label('Importar CSV de Zoom')}</h4><label class="field"><span>${label('Archivo CSV · UTF-8 · hasta 512 KB')}</span><input type="file" name="file" accept=".csv,text/csv" required></label><div class="form-grid"><label class="field"><span>${label('Zona horaria del CSV')}</span><input name="timezone" value="${E(data.period.timezone)}" required></label><label class="field"><span>${label('Formato de fecha del CSV')}</span><select name="date_format"><option value="ymd">AAAA-MM-DD / ISO 8601</option><option value="dmy">DD/MM/AAAA</option><option value="mdy">MM/DD/AAAA</option></select></label></div>${note('Se identifica por correo, se agrupan reconexiones y se conservan las correcciones manuales. Primero verás una vista previa.')}<button class="btn">${label('Revisar CSV')}</button><div id="stats-csv-preview" aria-live="polite"></div></form><form data-stats-form="manual" class="card"><h4>${label('Registrar manualmente')}</h4><div class="stats-manual-fields"><label class="field"><span>${label('Correo del usuario ASCLA')}</span><input name="email" type="email" required maxlength="100"></label><label class="field"><span>${label('Asistencia')}</span><select name="status">${attendanceOptions('present')}</select></label><label class="field"><span>${label('Minutos conectados · opcional')}</span><input name="minutes" type="number" min="0" max="${roster.max_minutes}" step="1"></label><button class="btn primary">${label('Guardar asistencia')}</button></div></form><h4>${label('Participantes e inscritos')}</h4>${peopleTable}<div class="admin-pager">${previous}<span>${roster.page} / ${roster.pages} · ${roster.total}</span>${next}</div>${completion}</div>`;
      modal(T('Registro de asistencia'),body,true);
    }
    function renderPreviewMatchedRow(u) {
      const identity=E(u.name)+small(E(u.email));
      const status=label({present:'Asistió',partial:'Asistencia parcial',absent:'No asistió',review:'Revisión manual'}[u.status])+small(reason(u.review_reason));
      return row([identity,number(u.minutes),number(u.connections),status,label(u.protected?'Conservar manual':'Registrar asistencia')]);
    }
    function renderPreviewUnmatchedRow(u) {
      return row([u.line,E(u.email)||'—',label(u.reason)]);
    }
    function showPreview(result) {
      preview=result;
      const matchedTable=table(['Usuario','Minutos','Conexiones','Estado','Acción'],result.matched.map(renderPreviewMatchedRow));
      const unmatched=result.unmatched.length
        ? `<details open><summary>${label('Filas sin asociar')}</summary>${table(['Fila','Correo','Motivo'],result.unmatched.map(renderPreviewUnmatchedRow))}</details>`
        : '';
      const previous=action('Anterior','preview-page',pagerAttrs(result.page-1,result.page===1));
      const next=action('Siguiente','preview-page',pagerAttrs(result.page+1,result.page>=result.pages));
      root.querySelector('#stats-csv-preview').innerHTML=`<h4>${label('Vista previa de importación')}</h4><p><strong>${result.importable}</strong> ${label('usuarios por registrar')} · <strong>${result.unmatched_total}</strong> ${label('filas sin asociar')} · <strong>${result.protected}</strong> ${label('registros manuales conservados')} · <strong>${result.duplicates}</strong> ${label('filas duplicadas omitidas')}</p>${matchedTable}${unmatched}${note('Las filas sin asociar no crean cuentas ni asistencias. Puedes registrarlas manualmente cuando verifiques la identidad. Se unen intervalos solapados dentro del evento. Las reconexiones sin horarios requieren revisión manual.')}<div class="admin-pager">${previous}<span>${result.page} / ${result.pages}</span>${next}</div>${action('Confirmar importación','import',result.importable?'':'disabled','primary')}`;
    }
    function renderZoomHistoryRow(i) {
      const counts=(i.counts.updated||0)+' '+label('registrados')+' · '+(i.counts.unidentified||0)+' '+label('sin identificar')+' · '+(i.counts.conflict||0)+' '+label('conflictos');
      const detail=action('Ver detalle','zoom-detail','data-import="'+i.id+'"');
      const resume=i.status==='applying'?action('Continuar','zoom-resume','data-import="'+i.id+'"'):'';
      return row([E(i.filename),E(date(i.created_at+'Z')),label(i.status==='complete'?'Completada':'Aplicando'),counts,detail+resume]);
    }
    async function zoomHistory(pageNumber=1) {
      const h=await api(`admin/attendance/${roster.id}/imports?page=${pageNumber}`),target=root.querySelector('#stats-zoom-history');if(!target)return;
      const historyTable=table(['Archivo','Fecha','Estado','Resultado','Acciones'],h.items.map(renderZoomHistoryRow));
      const previous=action('Anterior','zoom-history',pagerAttrs(pageNumber-1,pageNumber===1));
      const next=action('Siguiente','zoom-history',pagerAttrs(pageNumber+1,pageNumber*20>=h.total));
      target.innerHTML=historyTable+`<div class="admin-pager">${previous}<span>${pageNumber} / ${Math.max(1,Math.ceil(h.total/20))}</span>${next}</div><div id="stats-zoom-detail"></div>`;
    }
    function renderZoomDetailRow(r,names) {
      const detail=reason(r.payload.reason||r.payload.review_reason)+small(E(r.payload.status||''));
      return row([r.payload.line||r.position,E(r.payload.email||''),label(names[r.state]||r.state),detail]);
    }
    async function zoomDetail(importId,pageNumber=1) {
      const d=await api(`admin/attendance/${roster.id}/imports/${importId}?page=${pageNumber}`),target=root.querySelector('#stats-zoom-detail');if(!target)return;
      const names={updated:'Registrada',protected:'Conservar manual',unidentified:'Sin identificar',conflict:'Revisar conflicto',pending:'Pendiente'};
      const rows=d.rows.map(r=>renderZoomDetailRow(r,names));
      const detailTable=table(['Fila','Participante','Resultado','Detalle'],rows);
      const extra='data-import="'+importId+'"';
      const previous=action('Anterior','zoom-detail',pagerAttrs(d.page-1,d.page===1,extra));
      const next=action('Siguiente','zoom-detail',pagerAttrs(d.page+1,d.page>=d.pages,extra));
      target.innerHTML=`<h4>${E(d.filename)}</h4>${detailTable}<div class="admin-pager">${previous}<span>${d.page} / ${d.pages}</span>${next}</div>`;
    }
    async function refresh() { await load(); }
    async function perform(control,work) {
      if(control.disabled)return;
      const initial=control.innerHTML;control.disabled=true;control.setAttribute('aria-busy','true');
      try{await work();}catch(error){toast(error.message);}finally{control.disabled=false;control.removeAttribute('aria-busy');if(control.isConnected)control.innerHTML=initial;}
    }
    async function handleStatsAction(b) {
      const key=b.dataset.statsAction;
      switch(key){
        case 'section': section=b.dataset.section;render();if(section==='ai')await loadInsights();break;
        case 'reload': await load();break;
        case 'page': page=Number(b.dataset.page);await load();break;
        case 'event-page': eventPage=Number(b.dataset.page);await load();break;
        case 'roster': showRoster(await api('admin/attendance/'+Number(b.dataset.id)));break;
        case 'roster-page': showRoster(await api(`admin/attendance/${roster.id}?page=${Number(b.dataset.page)}`));break;
        case 'preview-page': showPreview(await api(`admin/attendance/${roster.id}/preview`,{csv,...zoomOptions,page:Number(b.dataset.page)}));break;
        case 'zoom-history': await zoomHistory(Number(b.dataset.page)||1);break;
        case 'zoom-detail': await zoomDetail(Number(b.dataset.import),Number(b.dataset.page)||1);break;
        case 'zoom-resume': {let result;do{result=await api(`admin/attendance/${roster.id}/imports/${Number(b.dataset.import)}`,{});b.textContent=`${result.processed}/${result.total}`;}while(!result.complete);await refresh();showRoster(result.roster);break;}
        case 'reopen': {const result=await api(`admin/attendance/${roster.id}/complete`,{complete:false});await refresh();showRoster(result);break;}
        case 'analyze': {b.textContent=T('Clasificando…');const current=JSON.stringify(filters);const result=await api('admin/statistics/topics',{...filters});if(current===JSON.stringify(filters)){insights=result;render();}toast(T('Clasificación actualizada.'));break;}
        case 'import': {let result=await api(`admin/attendance/${roster.id}/import`,{csv,token:preview.token,...zoomOptions});while(!result.complete){b.textContent=`${result.processed}/${result.total}`;result=await api(`admin/attendance/${roster.id}/imports/${result.import_id}`,{});}await refresh();showRoster(result.roster);toast(T('Asistencia importada.'));break;}
      }
    }
    root.addEventListener('click',event=>{
      const b=event.target.closest('[data-stats-action]');
      if(!b)return;
      event.preventDefault();
      void perform(b,()=>handleStatsAction(b));
    });
    root.addEventListener('submit',event=>{
      const form=event.target.closest('form[data-stats-form]');
      if(!form)return;
      event.preventDefault();
      const button=form.querySelector('button[type="submit"],button:not([type])');
      void perform(button,async()=>{
        const values=Object.fromEntries(new FormData(form)),key=form.dataset.statsForm;
        if(key==='filter'){filters=values;page=1;eventPage=1;await load();}
        else if(key==='csv'){
          preview=null;root.querySelector('#stats-csv-preview').innerHTML='';
          const file=form.elements.file.files[0];if(!file || file.size>524288)throw new Error(T('El CSV debe ocupar como máximo 512 KB.'));
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
