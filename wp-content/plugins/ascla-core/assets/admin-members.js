/* Read-only member evidence. The server owns permissions, privacy, totals and pagination. */
(() => {
  'use strict';
  globalThis.ASCLAMembers = ({root, api, E, T, date, I, avatar, back, toast}) => {
    const labels={publications:'Publicaciones',comments:'Comentarios',likes:'Me gusta',registrations:'Inscripciones vigentes',attendances:'Asistencias registradas'};
    const icons={publications:'book',comments:'hub',likes:'heart',registrations:'calendar',attendances:'check'};
    const actions={publications:'Publicó',comments:'Comentó en',likes:'Le gusta',registrations:'Se inscribió en',attendances:'Asistencia registrada en'};
    const groupLabels={interests:'Temas de interés',areas:'Áreas de conocimiento',industries:'Industrias',goals:'Objetivos de networking',languages:'Idiomas',learn:'Quiere aprender',help:'Puede compartir',connect_topics:'Temas para conectar'};
    let panel, user=0, filters={}, generation=0;
    const text=value=>E(T(value));
    const number=value=>new Intl.NumberFormat().format(value);
    const button=(label,action,attrs='',cls='')=>`<button type="button" class="btn ${cls}" data-member-action="${action}" ${attrs}>${label}</button>`;
    const chips=values=>values.map(value=>`<span class="member-interest-chip">${E(value)}</span>`).join('');
    const formatted=value=>value?E(date(value,{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'})):text('Sin actividad publicada');
    const backButton=()=>button(I('arrow')+text('Volver a usuarios'),'back','','member-back');

    function render(d) {
      const u=d.user;
      const access=u.membership_status==='active' && u.access_enabled;
      const membership={active:'Acceso restringido por permisos',suspended:'Acceso suspendido',expired:'Membresía vencida'};
      const metrics=Object.entries(labels).map(([key,label])=>button(`<span class="member-metric-icon">${I(icons[key])}</span><strong>${number(d.summary[key])}</strong><span>${text(label)}</span>`, 'filter',`data-kind="${key}" aria-pressed="${filters.activity===key}"`,'member-metric'+(filters.activity===key?' selected':''))).join('');
      const groups=Object.entries(groupLabels).filter(([key])=>d.interests[key]?.length).map(([key,label])=>`<div class="member-interest-group"><h4>${text(label)}</h4><div class="member-interest-chips">${chips(d.interests[key])}</div></div>`).join('');
      const formInterests=d.form_interests.length?`<div class="member-interest-group"><h4>${text('Intereses importados de formularios')}</h4><div class="member-interest-chips">${chips(d.form_interests)}</div></div>`:'';
      const learned=d.learned_interests;
      const learnedTopics=(learned?.topics||[]).slice(0,12).map(topic=>`<li><span>${E(topic.name)}</span><strong>${number(topic.contents)} <small>${text(topic.contents===1?'contenido relacionado':'contenidos relacionados')}</small></strong></li>`).join('');
      const topics=d.liked_topics.map(topic=>`<li><span>${E(topic.name)}</span><strong>${number(topic.likes)} <small>${text('Me gusta')}</small></strong></li>`).join('');
      const items=d.items.map(item=>`<li><span class="member-activity-icon" aria-hidden="true">${I(icons[item.kind])}</span><div><span class="member-activity-kind">${text(actions[item.kind])}</span><a href="${E(item.target.url)}">${E(item.target.title||T('Sin título'))} ${I('arrow')}</a></div><time datetime="${E(item.date.replace(' ','T')+'Z')}">${formatted(item.date)}</time></li>`).join('');
      const pager=d.pages>1?`<nav class="member-pagination" aria-label="${text('Páginas de actividad')}">${button(text('Anterior'),'page',`data-page="${d.page-1}" ${d.page<=1?'disabled':''}`)}<span>${text('Página')} ${number(d.page)} / ${number(d.pages)}</span>${button(text('Siguiente'),'page',`data-page="${d.page+1}" ${d.page>=d.pages?'disabled':''}`)}</nav>`:'';
      panel.innerHTML=`<div class="member-insights">
        ${backButton()}
        <header class="member-insights-header">${avatar(u,'lg')}<div class="member-insights-identity"><span class="eyebrow">${text('ACTIVIDAD E INTERESES')}</span><h2>${E(u.name)}</h2><p>@${E(u.login)} · ${E(u.email)}</p><span class="request-status ${access?'open':'closed'}">${text(access?'Acceso activo':membership[u.membership_status]||'Acceso restringido por permisos')}</span></div><div class="member-insights-dates"><span>${text('En la comunidad desde')}<strong>${E(date(u.registered))}</strong></span><span>${text('Última actividad en este período')}<strong>${formatted(d.last_activity)}</strong></span></div></header>
        <div class="member-period"><div><h3>${text('Una mirada a su actividad')}</h3><p>${text('Selecciona una cifra para filtrar el historial.')}</p></div><label class="field">${text('Período de actividad')}<select data-member-period>${[[30,'Últimos 30 días'],[90,'Últimos 90 días'],[0,'Todo el historial']].map(([value,label])=>`<option value="${value}" ${d.filters.days===value?'selected':''}>${text(label)}</option>`).join('')}</select></label></div>
        <div class="member-metrics">${metrics}</div>
        <div class="member-insights-columns"><section class="card member-declared"><header><span class="member-section-icon">${I('users')}</span><div><h3>${text('Lo que comparte en su perfil')}</h3><p>${text('Intereses actuales del perfil, incluidos los incorporados automáticamente.')}</p></div></header>${groups||`<p class="member-empty">${text('Aún no hay intereses en este perfil.')}</p>`}${formInterests}</section>
        <section class="card member-liked-topics"><header><span class="member-section-icon">${I('heart')}</span><div><h3>${text('Temas en contenidos que le gustan')}</h3><p>${text('Basado en sus Me gusta durante el período seleccionado.')}</p></div></header>${topics?`<ul>${topics}</ul><p class="private-note">${text('Un contenido puede tener varios temas. Estas cifras cuentan interacciones, no preferencias declaradas.')}</p>`:`<p class="member-empty">${text('Aún no hay Me gusta en contenidos con temas para este período.')}</p>`}</section></div>
        <section class="card member-learned-topics"><header><span class="member-section-icon">${I('spark')}</span><div><h3>${text('Intereses aprendidos por ASCLA')}</h3><p>${text('Aprendizaje automático de los últimos 90 días. Las desmarcaciones manuales tienen prioridad.')}</p></div><span class="tag">${text(learned?.enabled?'Aprendizaje activo':'Aprendizaje desactivado')}</span></header>${learnedTopics?`<ul class="learned-topic-list">${learnedTopics}</ul>`:`<p class="member-empty">${text('Sin temas aprendidos para esta cuenta.')}</p>`}<p class="private-note">${text('Los temas del catálogo pueden seleccionarse automáticamente en el perfil. Si el asociado desmarca uno y guarda, ASCLA no lo vuelve a activar. Este resumen no cambia con los filtros del historial.')}</p></section>
        <section class="card member-timeline"><header><div><h3>${text('Historial de actividad')} <span class="tag">${number(d.total)}</span></h3><p>${text(filters.activity?labels[filters.activity]:'Toda la actividad publicada')}</p></div>${button(text('Ver toda la actividad'),'filter','data-kind="" '+(!filters.activity?'disabled':''),'ghost small')}</header>${items?`<ul class="member-activity-list">${items}</ul>`:`<p class="member-empty">${text('No hay actividad publicada para estos filtros.')}</p>`}${pager}</section>
        <details class="member-data-scope"><summary>${I('shield')}${text('Qué incluye esta vista')}</summary><p>${text('Datos guardados en ASCLA. No incluye mensajes privados, solicitudes de Contacto, borradores, contenido eliminado, microeventos ni contenido protegido por Chatham House. Los Me gusta muestran interacciones vigentes; las inscripciones no equivalen a asistencias. Las asistencias se fechan según su registro o última actualización.')}</p><p>${text('Los intereses del perfil son actuales y no cambian al filtrar el período.')}</p></details>
      </div>`;
    }

    async function load(focus='') {
      const target=panel, request=++generation;
      target.setAttribute('aria-busy','true');
      try {
        const data=await api(`admin/users/${user}/insights?`+new URLSearchParams(filters));
        if(request!==generation || !target.isConnected) return;
        render(data);
        const focusTarget=focus?target.querySelector(focus):null;
        if(focusTarget) { focusTarget.tabIndex=focusTarget.matches('h2,h3')?-1:focusTarget.tabIndex;focusTarget.focus({preventScroll:true}); }
      } catch(error) {
        if(error.name==='AbortError' || request!==generation || !target.isConnected) return;
        target.innerHTML=`<div class="member-insights">${backButton()}<div class="card" role="alert"><h2>${text('No se pudo cargar la actividad')}</h2><p>${E(error.message)}</p>${button(text('Reintentar'),'retry','','primary')}</div></div>`;
      } finally { if(request===generation) target.removeAttribute('aria-busy'); }
    }
    root.addEventListener('click',async event=>{
      const control=event.target.closest('[data-member-action]');
      if(!control || !panel?.contains(control)) return;
      if(control.dataset.memberAction==='back') {
        generation++;
        try { await back(panel);panel.querySelector('input[name=q]')?.focus({preventScroll:true}); }
        catch(error) { if(error.name!=='AbortError') toast(error.message); }
        return;
      }
      if(control.dataset.memberAction==='filter') filters={...filters,activity:control.dataset.kind,page:1};
      if(control.dataset.memberAction==='page') filters={...filters,page:Number(control.dataset.page)};
      await load(control.dataset.memberAction==='filter'?`[data-member-action="filter"][data-kind="${control.dataset.kind}"]`:'.member-timeline h3');
    });
    root.addEventListener('change',event=>{
      if(!event.target.matches('[data-member-period]') || !panel?.contains(event.target)) return;
      filters={...filters,days:Number(event.target.value),page:1};load('[data-member-period]');
    });
    return {open:async(target,id)=>{panel=target;user=Number(id);filters={days:90,page:1,activity:''};await load('.member-insights-identity h2');if(target.isConnected) target.scrollIntoView({block:'start'});}};
  };
})();
