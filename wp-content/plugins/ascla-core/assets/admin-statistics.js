/* Administrative analytics: UI only. Capabilities, state and all calculations are checked on the server. */
(() => {
  "use strict";
  function attendanceSource(source) {
    if(source==='manual')return 'Manual';
    if(source==='zoom')return 'Zoom';
    return 'Sin verificar';
  }
  globalThis.ASCLAStatistics = ({root, api, E, T, I, modal, closeModal, toast, date}) => {
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
    const locale=globalThis.ASCLA.locale;
    const number = value => value === null || value === undefined ? "—" : new Intl.NumberFormat(locale).format(value);
    const percent = value => value === null || value === undefined ? T('Sin datos') : number(value) + "%";
    const day = value => new Intl.DateTimeFormat(locale,{day:'numeric',month:'short',year:'numeric',timeZone:'UTC'}).format(new Date(value+'T12:00:00Z'));
    const range = (from,to) => E(day(from))+' — '+E(day(to));
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
      return `<p class="stats-table-hint">${label('Desliza la tabla para ver todas las columnas.')}</p><div class="table-wrap" tabindex="0" role="region" aria-label="${E(headers.map(T).join(' · '))}"><table class="data-table stats-table"><thead><tr>${headerHtml}</tr></thead><tbody>${bodyHtml}</tbody></table></div>`;
    };
    const tabs = [['overview','Resumen','La comunidad de un vistazo','users'],['users','Participación','Quiénes asisten a los eventos','calendar'],['topics','Intereses','Los temas que reúnen a la comunidad','heart'],['trends','Comparar períodos','Qué cambió respecto al período anterior','clock'],['ai','Temas con IA','Agrupar eventos por su contenido','spark']];
    const metrics = {events:"Eventos finalizados",registrations:"Inscripciones confirmadas",attendees:"Personas que asistieron",attendances:"Asistencias registradas",recurring:"Personas que regresan"};
    const attendanceStates=[['unknown','Sin verificar'],['present','Asistió'],['partial','Asistencia parcial'],['absent','No asistió'],['review','Revisión manual']];
    const attendanceOptions=status=>attendanceStates.map(([key,text])=>{
      const selected = status===key ? 'selected' : '';
      return `<option value="${key}" ${selected}>${label(text)}</option>`;
    }).join('');
    const delta = n => {
      if(n===null)return `<span class="stats-change">${label("Sin base previa")}</span>`;
      if(n===0)return `<span class="stats-change">${label('Sin cambios')}</span>`;
      let cls='';
      if(n>0)cls='up';
      else if(n<0)cls='down';
      const direction=n>0?'Aumentó':'Disminuyó';
      return `<span class="stats-change ${cls}">${n>0?'↑':'↓'} ${number(Math.abs(n))}%${small(label(direction))}</span>`;
    };
    const select = (name, title, options, value) => {
      const optionsHtml=options.map(o=>{
        const selected=Number(value)===Number(o.id)?'selected':'';
        return `<option value="${Number(o.id)}" ${selected}>${E(o.name||o.title)}</option>`;
      }).join("");
      return `<label class="field"><span>${label(title)}</span><select name="${name}"><option value="0">${label("Todos")}</option>${optionsHtml}</select></label>`;
    };
    const pagerAttrs = (pageNumber, disabled, extra='') => 'data-page="'+pageNumber+'" '+(disabled?'disabled':'')+(extra?' '+extra:'');
    const help = (title,body) => `<details class="stats-help"><summary>${label(title)}</summary><div>${body}</div></details>`;
    const intro = (title,text) => `<div class="stats-view-intro"><h3>${label(title)}</h3><p>${label(text)}</p></div>`;
    const metric = (key,title,value,hint,icon='users') => `<article class="card stats-metric" data-stat="${key}"><div class="stats-metric-label"><span class="stats-metric-icon" aria-hidden="true">${I(icon)}</span><span>${label(title)}</span></div><strong>${E(value)}</strong><small>${label(hint)}</small></article>`;
    const count = value => Number.isFinite(Number(value)) ? Math.max(0,Number(value)) : 0;
    function participationSummary() {
      const s=data.summary;
      const cards=[
        ['Actividad','calendar',['events',number(s.events),'Eventos finalizados'],['registrations',number(s.registrations),'Inscripciones confirmadas'],'Las inscripciones son reservas. No confirman que la persona haya asistido.'],
        ['Personas','users',['attendees',number(s.attendees),'Personas distintas que asistieron'],['recurring',number(s.recurring),'Personas que regresan'],'Cada persona cuenta una sola vez. Quienes regresan asistieron a dos o más eventos.'],
        ['Asistencias','check',['attendances',number(s.attendances),'Asistencias registradas'],['average',s.average===null?T('Sin datos'):number(s.average),'Promedio por evento verificado'],'Una persona en tres eventos suma tres asistencias. El promedio usa solo listas verificadas.']
      ].map(([title,icon,main,other,description])=>`<article class="card stats-summary-card"><header><span class="stats-metric-icon" aria-hidden="true">${I(icon)}</span><h4>${label(title)}</h4></header><div class="stats-summary-main" data-stat="${main[0]}"><strong>${E(main[1])}</strong><span>${label(main[2])}</span></div><div class="stats-summary-support" data-stat="${other[0]}"><strong>${E(other[1])}</strong><span>${label(other[2])}</span></div><p>${label(description)}</p></article>`).join('');
      const pending=Math.max(0,s.events-s.complete_events);
      let message='Las listas del período están verificadas. La tasa y el promedio utilizan esa base.';
      if (!s.events) message='No hay eventos finalizados con estos filtros. Prueba otras fechas para consultar la participación.';
      else if (pending && !s.attendances) message='Aún no hay asistencias registradas. Las listas pendientes pueden cambiar estas cifras.';
      else if (pending) message='Hay listas por revisar. Los totales registrados pueden crecer al completar la asistencia.';
      return `<section class="stats-participation-summary"><div class="stats-overview-heading">${intro('Participación en eventos','Lee la actividad, las personas y las asistencias como tres datos diferentes.')}<span class="stats-scope-label">${I('calendar')}${label('Eventos del período')}</span></div><div class="stats-summary-groups">${cards}</div><div class="stats-summary-note"><span class="stats-summary-review-icon" aria-hidden="true">${I(pending?'clock':'check')}</span><div class="stats-summary-review-copy"><strong>${label('¿Qué tan completos están los registros?')}</strong><p>${label(message)}</p></div><div class="stats-summary-completion"><span><b>${number(s.complete_events)} / ${number(s.events)}</b> ${label('listas verificadas')}</span><progress value="${count(s.complete_events)}" max="${Math.max(1,count(s.events))}" aria-label="${label('Listas de asistencia verificadas')}"></progress><small>${number(pending)} ${label('por revisar')}</small></div>${s.events?action('Revisar asistencias','show-events','','small'):''}</div></section>`;
    }
    function barsChart(title,description,items) {
      const rows=items.filter(x=>count(x.value)>0).sort((a,b)=>count(b.value)-count(a.value)).slice(0,6);
      const max=Math.max(1,...rows.map(x=>count(x.value)));
      const bars=rows.map(x=>`<li><div><span>${E(x.name)}</span><strong>${number(x.value)}</strong></div><progress aria-hidden="true" max="${max}" value="${count(x.value)}"></progress></li>`).join('');
      return `<figure class="card stats-visual"><figcaption><h4>${label(title)}</h4><p>${label(description)}</p></figcaption>${rows.length?`<ol class="stats-chart-rows">${bars}</ol>`:`<div class="stats-chart-empty">${I('calendar')}<p>${label('Aún no hay registros para representar en este gráfico.')}</p></div>`}</figure>`;
    }
    function attendanceChart() {
      const s=data.summary,eligible=count(s.rate_registered),attended=count(s.rate_attended);
      const known=eligible>0&&s.rate!==null&&s.rate!==undefined;
      const value=known?Math.max(0,Math.min(100,count(s.rate))):0;
      return `<figure class="card stats-visual stats-attendance-chart" data-stat="rate"><figcaption><h4>${label('Asistencia de inscritos')}</h4><p>${label('Porcentaje de inscritos que asistieron, solo en listas verificadas.')}</p></figcaption><div class="stats-rate-layout"><div class="stats-donut${known?'':' no-data'}"><svg viewBox="0 0 100 100" aria-hidden="true" focusable="false"><circle class="stats-donut-track" cx="50" cy="50" r="40"></circle><circle class="stats-donut-value${value?'':' is-zero'}" cx="50" cy="50" r="40" pathLength="100" stroke-dasharray="${value} 100" transform="rotate(-90 50 50)"></circle></svg><div><strong>${E(known?percent(s.rate):T('Sin datos'))}</strong><span>${label(known?'de inscritos':'Sin base verificada')}</span></div></div><dl class="stats-chart-legend"><div><dt><span class="stats-legend-dot"></span>${label('Inscritos que asistieron')}</dt><dd>${number(attended)}</dd></div><div><dt><span class="stats-legend-dot muted"></span>${label('Sin asistencia registrada')}</dt><dd>${number(Math.max(0,eligible-attended))}</dd></div><div class="stats-legend-total"><dt>${label('Inscripciones con lista verificada')}</dt><dd>${number(eligible)}</dd></div></dl></div>${!known?`<p class="stats-chart-footnote">${label('La tasa estará disponible cuando haya inscripciones con una lista de asistencia verificada.')}</p>`:''}</figure>`;
    }
    function comparisonChart() {
      const rows=data.trends.map(t=>{
        const max=Math.max(1,count(t.current),count(t.previous));
        return `<li><div class="stats-comparison-label"><strong>${label(metrics[t.metric])}</strong>${delta(t.change)}</div><div class="stats-comparison-pair"><span>${label('Período elegido')}</span><progress aria-hidden="true" max="${max}" value="${count(t.current)}"></progress><b>${number(t.current)}</b></div><div class="stats-comparison-pair previous"><span>${label('Período anterior')}</span><progress aria-hidden="true" max="${max}" value="${count(t.previous)}"></progress><b>${number(t.previous)}</b></div></li>`;
      }).join('');
      return `<figure class="stats-visual stats-comparison-chart"><figcaption><h4>${label('La evolución de un vistazo')}</h4><p>${label('Compara las dos barras de cada indicador. Cada indicador utiliza su propia escala.')}</p></figcaption><ol class="stats-comparison-rows">${rows}</ol></figure>`;
    }
    function coverage() {
      const s=data.summary,remaining=s.events-s.complete_events;
      const message=s.events?'Las tasas solo se calculan con listas verificadas. Las demás cifras pueden incluir registros parciales.':'Prueba otras fechas o quita los filtros para encontrar eventos finalizados.';
      return `<div class="stats-coverage"><div class="stats-coverage-copy"><span class="stats-metric-icon" aria-hidden="true">${I('check')}</span><div><strong>${number(s.complete_events)} ${label('de')} ${number(s.events)} ${label('listas de asistencia verificadas')}</strong><p>${label(message)}</p></div></div><div class="stats-coverage-progress"><progress value="${s.complete_events}" max="${Math.max(1,s.events)}" aria-label="${label('Listas de asistencia verificadas')}"></progress><span>${number(remaining)} ${label('por revisar')}</span></div>${action('Revisar asistencias','show-events',s.events?'':'disabled','small')}</div>`;
    }
    function render() {
      if(!panel?.isConnected || !data)return;
      const p=data.period;
      const tabsHtml=tabs.map(([key,title,description,icon])=>`<button type="button" class="stats-view-button ${section===key?'selected':''}" data-stats-action="section" data-section="${key}" aria-pressed="${section===key}" aria-controls="stats-view"><span class="stats-view-icon" aria-hidden="true">${I(icon)}</span><span><strong>${label(title)}</strong><small>${label(description)}</small></span></button>`).join('');
      const advanced=Number(filters.category)||Number(filters.event)||filters.event_search;
      const eventHint=number(data.options.events_total)+' '+label('eventos encontrados. El selector incluye el período elegido y el anterior. Busca por título si no aparece el evento: se muestran hasta 100 coincidencias. Elige una opción para filtrar las cifras.');
      panel.innerHTML=`<div class="admin-section-heading stats-title"><div><span class="eyebrow">${label('PARTICIPACIÓN E INTERESES')}</span><h2>${label('Estadísticas de la comunidad')}</h2><p>${label('Conoce la participación, descubre intereses y revisa la asistencia de tus eventos.')}</p></div><span class="stats-period-tag">${I('calendar')} ${number(p.days)} ${label('días')}</span></div>
        <form data-stats-form="filter" class="card stats-filter-card"><div class="stats-filter-heading"><div><h3>${label('¿Qué período quieres consultar?')}</h3><p>${label('Se incluyen los eventos que finalizaron entre estas fechas. Máximo 366 días.')}</p></div><span class="private-note">${label('Zona horaria')}: ${E(p.timezone)}</span></div>
          <div class="stats-filters"><label class="field"><span>${label('Desde')}</span><input required type="date" name="from" value="${E(p.from)}"></label><label class="field"><span>${label('Hasta')}</span><input required type="date" name="to" value="${E(p.to)}"></label>${select('topic','Tema',data.options.topics,filters.topic)}<button class="btn primary">${I('search')} ${label('Aplicar filtros')}</button></div>
          <details class="stats-advanced" ${advanced?'open':''}><summary>${label('Filtrar por categoría o evento')}${advanced?` <span class="tag">${label('Hay filtros adicionales')}</span>`:''}</summary><div class="stats-advanced-grid">${select('category','Categoría',data.options.categories,filters.category)}<label class="field"><span>${label('Buscar un evento en el selector')}</span><input name="event_search" value="${E(filters.event_search||'')}" maxlength="100" placeholder="${label('Escribe parte del título')}"></label>${select('event','Evento',data.options.events,filters.event)}<p class="private-note full">${eventHint}</p></div></details>
          <div class="stats-filter-footer"><p>${label('Mostrando')}: <strong>${range(p.from,p.to)}</strong></p>${action('Quitar filtros','clear-filters','title="'+label('Conserva las fechas y quita tema, categoría y evento.')+'"','ghost small')}</div><div data-stats-error role="alert" hidden></div>
        </form>
        <nav class="stats-tabs" aria-label="${label('Vistas de estadísticas')}">${tabsHtml}</nav>${section==='overview'?'':coverage()}<div id="stats-view"></div>`;
      const view=panel.querySelector('#stats-view');
      if(section==='overview') overview(view);
      else if(section==='users') users(view);
      else if(section==='topics') topics(view);
      else if(section==='trends') trends(view);
      else ai(view);
    }
    function renderEventRow(e) {
      const title=`<strong>${E(e.title)}</strong>`+small(E(date(e.end)));
      const state=`<span class="stats-state ${e.complete?'complete':'pending'}">${I(e.complete?'check':'clock')}${label(e.complete?'Lista verificada':'Por revisar')}</span>`;
      return row([title,number(e.registered),number(e.attended),state,action(e.complete?'Ver o corregir lista':'Revisar asistencia','roster','data-id="'+e.id+'"','small')]);
    }
    function overview(view) {
      const s=data.summary;
      const community=[
        ['users','Cuentas ASCLA',number(s.users),'Cuentas con acceso asignado a ASCLA; incluye membresías suspendidas.','users'],
        ['new_users','Nuevas cuentas',number(s.new_users),'Cuentas creadas durante las fechas elegidas.','users'],
        ['active_users','Cuentas con actividad',number(s.active_users),'Cuentas con acciones en el historial. No es un contador de visitas.','clock']
      ].map(args=>metric(...args)).join('');
      const guide=help('Cómo leer estos indicadores',`<div class="stats-explainer"><div><strong>${label('Persona o asistencia')}</strong><p>${label('Si una persona asiste a varios eventos, aparece una vez en personas y una vez por evento en asistencias.')}</p></div><div><strong>${label('¿Por qué aparece Sin datos?')}</strong><p>${label('La tasa necesita inscritos con una lista verificada. El promedio necesita al menos una lista verificada. Sin esa base, no se muestra un cero.')}</p></div></div>`);
      const eventTable=table(['Evento','Inscripciones','Asistencias','Estado de la lista','Acciones'],data.events.map(renderEventRow));
      const previous=action('Anterior','event-page',pagerAttrs(eventPage-1,eventPage===1));
      const next=action('Siguiente','event-page',pagerAttrs(eventPage+1,eventPage*20>=data.events_total));
      const topicsChart=barsChart('Temas con más asistencias','Cada barra muestra asistencias, no personas distintas.',data.topics.map(t=>({name:t.name,value:t.attendances})));
      view.innerHTML=`${participationSummary()}<div class="stats-visual-grid">${attendanceChart()}${topicsChart}</div>${guide}
        <section class="stats-community"><div class="stats-subheading"><h3>${label('La comunidad en cifras')}</h3><p>${label('Este bloque no cambia al filtrar por evento, tema o categoría. Las nuevas cuentas y la actividad sí usan las fechas elegidas.')}</p></div><div class="stats-grid stats-secondary">${community}</div></section>
        <section class="card stats-section" id="stats-attendance"><div class="stats-heading"><div><h3 tabindex="-1">${label('Registro de asistencia')}</h3><p>${label('Abre un evento, registra o importa sus asistentes y verifica la lista al terminar.')}</p></div><span class="tag">${number(data.events_total)} ${label('eventos')}</span></div>${eventTable}<div class="admin-pager">${previous}<span>${eventPage} / ${Math.max(1,Math.ceil(data.events_total/20))}</span>${next}</div></section>`;
    }
    function renderUserRow(u) {
      const last=u.last_attendance?label('Última asistencia')+': '+E(date(u.last_attendance)):label('Todavía no registra asistencias');
      const user=`<strong>${E(u.name)}</strong>`+small(last);
      const rate=E(percent(u.rate))+small(number(u.eligible)+' '+label('inscripciones con lista verificada'));
      const minutes=number(u.minutes)+small(number(u.duration_known)+' / '+number(u.attended)+' '+label('asistencias con duración conocida'));
      const recurrence=`<span class="stats-state ${u.recurring?'complete':''}">${label(u.recurring?'Regresa a los eventos':'Participación ocasional')}</span>`;
      return row([user,number(u.registered),number(u.attended),rate,minutes,recurrence]);
    }
    function users(view) {
      const pages=Math.max(1,Math.ceil(data.ranking_total/20));page=data.ranking_page;
      const rankingTable=table(['Persona','Inscripciones','Asistencias','Asistencia de inscritos','Minutos registrados','Frecuencia'],data.ranking.map(renderUserRow));
      const previous=action('Anterior','page',pagerAttrs(page-1,page===1));
      const next=action('Siguiente','page',pagerAttrs(page+1,page===pages));
      view.innerHTML=`<section class="card stats-section">${intro('¿Quiénes participan más?','Personas inscritas o con asistencia registrada, ordenadas por cantidad de asistencias.')}${note('La tasa usa sus inscripciones en listas verificadas. Asistir sin inscripción no aumenta esa tasa.')}<div class="stats-reading-note">${I('users')}<p>${label('Regresa a los eventos significa que asistió al menos a dos. Los minutos solo suman duraciones conocidas; estos datos no cambian roles ni permisos.')}</p></div>${rankingTable}<div class="admin-pager">${previous}<span>${page} / ${pages} · ${number(data.ranking_total)} ${label('personas')}</span>${next}</div></section>`;
    }
    function renderTopicRow(t) {
      return row([E(t.name),number(t.declared),number(t.profile),number(t.forms),number(t.content),number(t.events),number(t.registrations),number(t.attendees),number(t.attendances)]);
    }
    function topics(view) {
      const chart=`<div class="stats-visual-grid">${barsChart('Intereses que más se comparten','Personas con ese interés guardado; refleja los perfiles actuales.',data.topics.map(t=>({name:t.name,value:t.declared})))}${barsChart('Temas con más asistencias','Cada barra muestra asistencias, no personas distintas.',data.topics.map(t=>({name:t.name,value:t.attendances})))}</div>`;
      const topicTable=table(['Tema','Personas con interés guardado','Eventos','Personas que asistieron','Asistencias'],data.topics.map(t=>row([`<strong>${E(t.name)}</strong>`,number(t.declared),number(t.events),number(t.attendees),number(t.attendances)])));
      const sources=table(['Tema','Personas con interés guardado','Perfil','Forms','Contenido publicado','Eventos','Inscripciones','Personas que asistieron','Asistencias'],data.topics.map(renderTopicRow));
      const indexNote=data.index_complete?'':`<div class="stats-reading-note">${I('clock')}<p>${label('El índice de perfiles históricos se está actualizando. Los conteos declarados todavía son parciales.')}</p></div>`;
      view.innerHTML=`<section class="card stats-section">${intro('¿Qué temas reúnen a la comunidad?','Compara los intereses guardados con la asistencia real a eventos.')}<div class="stats-explainer"><div><strong>${label('Intereses guardados')}</strong><p>${label('Selecciones actuales de los perfiles y Forms revisados. No dependen de las fechas elegidas.')}</p></div><div><strong>${label('Participación en eventos')}</strong><p>${label('Asistencias del período a eventos con ese tema. Cambia con los filtros aplicados.')}</p></div></div>${chart}${topicTable}${help('Ver fuentes y cifras de cada tema',sources+note('Perfil y Forms cuentan personas por fuente; una misma persona puede estar en ambas. El total de intereses guardados elimina duplicados. El contenido publicado usa las fechas, pero no el filtro de evento o categoría.'))}${note('Una persona y un evento pueden estar en varios temas. No sumes las filas como si fueran personas distintas.')}${indexNote}</section>`;
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
      const trendsTable=table(['Indicador','Período elegido','Período anterior','Cambio'],data.trends.map(renderTrendRow));
      const topicsTable=table(['Tema','Asistencias actuales','Asistencias anteriores','Cambio'],data.topics.map(renderTopicTrendRow));
      const comparison=comparisonChart();
      view.innerHTML=`<section class="card stats-section">${intro('¿Qué cambió entre períodos?','Se comparan dos períodos consecutivos con la misma cantidad de días.')}<div class="stats-period-comparison"><div><span>${label('Período elegido')}</span><strong>${range(p.from,p.to)}</strong><small>${number(data.summary.complete_events)} / ${number(data.summary.events)} ${label('listas verificadas')}</small></div><div><span>${label('Período anterior')}</span><strong>${range(p.previous_from,p.previous_to)}</strong><small>${number(data.previous.complete_events)} / ${number(data.previous.events)} ${label('listas verificadas')}</small></div></div>${currentNote}${note('Las listas pendientes pueden cambiar la comparación. Aumentó o Disminuyó describe la diferencia; no es una evaluación de desempeño.')}${comparison}${trendsTable}${help('Cómo se calcula el cambio',note('Cambio porcentual = (valor actual − valor anterior) / valor anterior × 100. Sin un valor anterior positivo, un aumento se muestra como Sin base previa. Si ambos son cero, se muestra Sin cambios.'))}<h4>${label('Evolución por tema')}</h4>${topicsTable}${note('Los intereses del perfil reflejan su estado actual; no se usan para inventar una evolución histórica.')}</section>`;
    }
    function renderAiRow(g) {
      return row([E(g.name),number(g.events),number(g.previous_events),number(g.attendances),number(g.previous_attendances),delta(g.change)]);
    }
    function ai(view) {
      if(!insights){view.innerHTML=`<section class="card stats-section" aria-live="polite">${intro('Temas con IA','La IA propone grupos de eventos; las cifras siguen viniendo de registros reales.')}${note('Cargando clasificación de temas…')}</section>`;return;}
      const s=insights;
      const providerText=s.provider==='mock'?'Modo demostración: clasificación local por palabras clave.':'Se usará el proveedor de IA configurado por administración.';
      const aiTable=table(['Tema sugerido','Eventos actuales','Eventos anteriores','Asistencias actuales','Asistencias anteriores','Cambio'],s.groups.map(renderAiRow));
      const stats=[['Analizados',s.classified],['Por analizar',s.pending],['Sin tema sugerido',s.unclassified],['Excluidos por privacidad',s.excluded]].map(([title,value])=>`<div><strong>${number(value)}</strong><span>${label(title)}</span></div>`).join('');
      const last=s.last?note('Última clasificación')+`<p>${E(date(s.last))}</p>`:'';
      view.innerHTML=`<section class="card stats-section">${intro('Agrupa los eventos por temas similares','La IA propone grupos de eventos; las cifras siguen viniendo de registros reales.')}<div class="stats-ai-callout"><span class="stats-metric-icon" aria-hidden="true">${I('spark')}</span><div><strong>${label(s.pending?'Hay eventos pendientes de analizar':'El análisis está al día')}</strong><p>${label(providerText)}</p><p>${label('El botón analiza hasta 30 eventos pendientes. La propuesta no cambia los temas publicados.')}</p></div>${action(s.pending?'Analizar temas pendientes':'Sin eventos pendientes','analyze',s.pending?'':'disabled','primary')}</div><div class="stats-ai-summary">${stats}</div>${s.pending?note('La comparación incluye solo los eventos analizados. Completa el análisis para contar con más información.'):''}${aiTable}${help('Qué información utiliza la IA',note('Se envían únicamente títulos depurados y el catálogo de temas al proveedor configurado. Se excluyen microeventos privados y sesiones Chatham House. No se envían correos, perfiles ni listas de asistencia.')+note('Las cifras se calculan con registros guardados. La IA no inventa asistencias ni porcentajes.'))}${last}</section>`;
    }
    async function load(target=panel) {
      panel=target;const ticket=++generation;
      if(!panel.querySelector('[data-stats-form="filter"]'))panel.innerHTML=note('Cargando estadísticas…');
      panel.setAttribute('aria-busy','true');
      try {
        const result=await api('admin/statistics?'+new URLSearchParams({...filters,page,event_page:eventPage}));
        if(ticket!==generation || !panel.isConnected)return;
        data=result;filters={...data.filters,from:data.period.from,to:data.period.to};insights=null;render();
        if(section==='ai')await loadInsights();
      } catch(error) {
        if(ticket!==generation || !panel?.isConnected)return;
        const alert=panel.querySelector('[data-stats-error]');
        if(alert){alert.hidden=false;alert.textContent=error.message;filters={...data.filters,from:data.period.from,to:data.period.to};}
        else panel.innerHTML=`<div class="card" role="alert">${E(error.message)} ${action('Reintentar','reload')}</div>`;
      } finally {if(ticket===generation && panel?.isConnected)panel.removeAttribute('aria-busy');}
    }
    async function loadInsights() {
      const ticket=generation;
      try {
        const result=await api('admin/statistics/topics?'+new URLSearchParams(filters));
        if(ticket!==generation || section!=='ai' || !panel?.isConnected)return;
        insights=result;render();
      } catch(error) {
        if(ticket===generation && section==='ai' && panel?.isConnected)panel.querySelector('#stats-view').innerHTML=`<section class="card stats-section" role="alert"><p>${E(error.message)}</p>${action('Reintentar','reload-insights')}</section>`;
      }
    }
    function renderSessions(sessions) {
      if(!sessions.length)return '';
      const items=sessions.map(x=>`<p>${E(x.join||'—')} → ${E(x.leave||'—')}</p>`).join('');
      return `<details><summary>${sessions.length} ${label('sesiones')}</summary>${items}</details>`;
    }
    function renderRosterRow(u) {
      const identity=E(u.name)+small(E(u.email));
      const attendanceForm=`<form data-stats-form="row" data-user="${u.id}" class="stats-attendance-row"><label><span>${label('Asistencia')}</span><select name="status" aria-label="${label('Asistencia')}">${attendanceOptions(u.status)}</select></label><label><span>${label('Minutos')}</span><input name="minutes" type="number" min="0" max="${roster.max_minutes}" step="1" value="${u.minutes??''}" placeholder="${label('Minutos')}" aria-label="${label('Minutos')}"></label><button class="btn small">${label('Guardar')}</button><small>${label(attendanceSource(u.source))}</small></form>`;
      const duration=u.percent==null?label('Duración no registrada'):E(percent(u.percent))+' '+label('del tiempo del evento');
      const attendance=attendanceForm+small(duration)+(u.review_reason?small(reason(u.review_reason)):'')+renderSessions(u.sessions);
      return row([identity,label(u.registered?'Inscrito':'Sin inscripción'),attendance]);
    }
    function showRoster(value) {
      roster=value;preview=null;csv='';
      const peopleTable=table(['Persona','Inscripción','Asistencia y duración'],roster.people.map(renderRosterRow));
      const previous=action('Anterior','roster-page',pagerAttrs(roster.page-1,roster.page===1));
      const next=action('Siguiente','roster-page',pagerAttrs(roster.page+1,roster.page>=roster.pages));
      const completion=roster.complete
        ? `<div class="stats-completion-done">${I('check')}<div><strong>${label('Esta lista ya está verificada')}</strong><p>${label('Se utiliza para calcular la tasa de asistencia. Si corriges un dato, tendrás que verificarla otra vez.')}</p></div>${action('Volver a revisar la lista','reopen','','small')}</div>`
        : `<form data-stats-form="complete" class="stats-complete"><h4>${label('3. Finaliza la revisión')}</h4><p>${label('Los inscritos sin una marca de asistencia se contarán como ausentes al calcular la tasa. Comprueba la lista antes de confirmar.')}</p><label class="check"><input required name="confirm" type="checkbox"><span>${label('He revisado toda la lista y confirmo que está completa.')}</span></label><button class="btn primary">${label('Confirmar lista verificada')}</button></form>`;
      const states=help('Qué significa cada estado',`<dl class="stats-definitions"><div><dt>${label('Asistió')}</dt><dd>${label('Cuenta como asistencia en los indicadores.')}</dd></div><div><dt>${label('Asistencia parcial')}</dt><dd>${label('Se registra su participación, pero no cuenta como Asistió.')}</dd></div><div><dt>${label('Sin verificar / Revisión manual')}</dt><dd>${label('Falta confirmar la evidencia. Revisa estos casos antes de cerrar la lista.')}</dd></div><div><dt>${label('No asistió')}</dt><dd>${label('Se conserva como ausencia, sin sumar una asistencia.')}</dd></div></dl>`);
      const summary=[['Asistió',roster.summary.present],['Asistencia parcial',roster.summary.partial],['Revisión manual',roster.summary.review],['No asistió',roster.summary.absent]].map(([title,value])=>`<div><strong>${number(value)}</strong><span>${label(title)}</span></div>`).join('');
      const body=`<div class="stats-roster"><div class="stats-roster-heading"><h3>${E(roster.title)}</h3><span class="stats-state ${roster.complete?'complete':'pending'}">${label(roster.complete?'Lista verificada':'Revisión pendiente')}</span></div><ol class="stats-steps"><li><strong>${label('Registra')}</strong><span>${label('Añade personas o importa Zoom.')}</span></li><li><strong>${label('Revisa')}</strong><span>${label('Comprueba estados y duración.')}</span></li><li><strong>${label('Confirma')}</strong><span>${label('Verifica la lista al terminar.')}</span></li></ol><div class="stats-roster-summary">${summary}</div>
        <details class="stats-workflow" data-stats-workflow="manual" open><summary>${label('1. Añadir o corregir una persona')}</summary><div><p>${label('Usa el correo de su cuenta ASCLA. Si ya está en la lista, se actualizará su registro.')}</p><form data-stats-form="manual"><div class="stats-manual-fields"><label class="field"><span>${label('Correo del usuario ASCLA')}</span><input name="email" type="email" required maxlength="100"></label><label class="field"><span>${label('Asistencia')}</span><select name="status">${attendanceOptions('present')}</select></label><label class="field"><span>${label('Minutos conectados · opcional')}</span><input name="minutes" type="number" min="0" max="${roster.max_minutes}" step="1"></label><button class="btn primary">${label('Guardar asistencia')}</button></div></form></div></details>
        <details class="stats-workflow" data-stats-workflow="csv"><summary>${label('O importar participantes desde Zoom')}</summary><div><form data-stats-form="csv"><p>${label('Carga el CSV, revisa la vista previa y confirma la importación. Cargar el archivo todavía no guarda asistencias.')}</p><label class="field"><span>${label('Archivo CSV · UTF-8 · hasta 512 KB')}</span><input type="file" name="file" accept=".csv,text/csv" required></label><div class="form-grid"><label class="field"><span>${label('Zona horaria del CSV')}</span><input name="timezone" value="${E(data.period.timezone)}" required></label><label class="field"><span>${label('Formato de fecha del CSV')}</span><select name="date_format"><option value="ymd">AAAA-MM-DD / ISO 8601</option><option value="dmy">DD/MM/AAAA</option><option value="mdy">MM/DD/AAAA</option></select></label></div>${note('Se identifica por correo, se agrupan reconexiones y se conservan las correcciones manuales. Primero verás una vista previa.')}<button class="btn">${label('Revisar archivo antes de importar')}</button><div id="stats-csv-preview" aria-live="polite"></div></form><div class="stats-history-action">${action('Ver importaciones anteriores','zoom-history')}</div><div id="stats-zoom-history"></div></div></details>
        <div class="stats-subheading"><h4>${label('2. Revisa participantes e inscritos')}</h4><p>${label('Puedes corregir el estado y los minutos de cada persona. Guarda cada fila que modifiques.')}</p></div>${peopleTable}<div class="admin-pager">${previous}<span>${roster.page} / ${roster.pages} · ${roster.total}</span>${next}</div>${states}${completion}
        ${help('Opciones del registro de Zoom',`<p>${label('El porcentaje mínimo de permanencia decide si una conexión de Zoom cuenta como asistencia completa o parcial. Las correcciones manuales se conservan.')}</p><form data-stats-form="threshold" class="stats-threshold"><label class="field"><span>${label('Permanencia mínima (%)')}</span><input name="threshold" type="number" min="1" max="100" value="${roster.threshold}" required></label><button class="btn">${label('Guardar criterio de Zoom')}</button></form>`)}
      </div>`;
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
        case 'section': {
          const selected=b.dataset.section;section=selected;render();if(section==='ai')await loadInsights();
          if(section===selected && panel?.isConnected)panel.querySelector('[data-section="'+selected+'"]').focus({preventScroll:true});
          break;
        }
        case 'clear-filters': filters={from:data.period.from,to:data.period.to};page=1;eventPage=1;await load();break;
        case 'show-events': section='overview';render();panel.querySelector('#stats-attendance h3').focus();panel.querySelector('#stats-attendance').scrollIntoView({block:'start'});break;
        case 'reload-insights': await loadInsights();break;
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
        if(key==='filter'){
          const days=(Date.parse(values.to)-Date.parse(values.from))/86400000+1;
          if(days<1 || days>366)throw new Error(T('La fecha de inicio no puede superar la final. Elige hasta 366 días.'));
          filters=values;page=1;eventPage=1;await load();
        }
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
