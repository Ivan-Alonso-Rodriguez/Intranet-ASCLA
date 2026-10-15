/* Institutional ally cards, details and administration. WordPress enforces every permission. */
(() => {
  'use strict';
  globalThis.ASCLAAllies=({root,api,E,T,I,modal,closeModal,toast,btn,field,select,date,config:C,safeURL,afterChange})=>{
    const text=value=>E(T(value));
    const states={active:'Activo',inactive:'Inactivo',draft:'Borrador',scheduled:'Programado',pending:'Pendiente de revisión'};
    const state=p=>p.meta.ally_status||({publish:'active',ascla_hidden:'inactive',draft:'draft'}[p.status]||'pending');
    const label=p=>`<span class="ally-state ally-state-${state(p)}">${text(states[state(p)])}</span>`;
    const action=(title,key,attrs='',cls='')=>`<button type="button" class="btn ${cls}" data-allies-action="${key}" ${attrs}>${title}</button>`;
    const logo=p=>(p.media||[]).find(file=>file.mime?.startsWith('image/'));
    const logoMarkup=p=>logo(p)?`<img src="${E(logo(p).url)}" alt="${text('Logo de')} ${E(p.title)}">`:`<span class="ally-logo-placeholder">${I('ally')}</span>`;
    const section=(title,value)=>value?`<section class="ally-info-section"><h3>${text(title)}</h3><p>${E(value)}</p></section>`:'';
    let registryPanel,filters={},generation=0;

    function card(p){
      return `<article class="card ally-card"><div class="ally-card-brand">${logoMarkup(p)}</div><div class="ally-card-copy"><span class="eyebrow">${text(p.meta.alliance_type||'Alianza institucional')}</span><h3>${E(p.title)}</h3><p>${E(p.body.slice(0,220))}${p.body.length>220?'…':''}</p>${p.meta.benefits?`<div class="ally-card-benefit">${I('check')}<span>${E(p.meta.benefits.slice(0,160))}${p.meta.benefits.length>160?'…':''}</span></div>`:''}</div>${btn(text('Conocer la alianza')+' '+I('arrow'),'item',`data-id="${p.id}"`,'ally-card-action')}</article>`;
    }
    function catalog(list,filter,admin){
      const controls=admin?`<div class="form-actions"><a class="btn" data-native href="${E(C.adminUrl+(C.adminUrl.includes('?')?'&':'?')+'section=aliados')}">${I('settings')}${text('Registro de aliados')}</a>${btn(I('plus')+' '+text('Crear aliado'),'editor','data-type="ally"','primary')}</div>`:'';
      return `<header class="ally-catalog-header"><div><span class="eyebrow">${text('ALIANZAS INSTITUCIONALES')}</span><h1>${text('Nuestros aliados')}</h1><p>${text('Conoce las organizaciones que colaboran con ASCLA y los beneficios para nuestra comunidad.')}</p></div>${controls}</header><form class="filters ally-catalog-search" data-form="filters" role="search" aria-label="${text('Buscar aliados')}"><label class="field"><span>${text('Buscar aliados')}</span><input type="search" name="q" maxlength="150" value="${E(filter.q||'')}" placeholder="${text('Nombre, tipo de convenio o descripción…')}"></label><button class="btn primary">${I('search')} ${text('Buscar')}</button>${btn('Limpiar filtros','filter-reset','','ghost')}</form><p class="private-note">${list.total} ${text('alianzas en el catálogo')}</p><div class="ally-cards">${list.items.map(card).join('')}</div>${!list.items.length?`<div class="card ally-empty"><h2>${text('No encontramos aliados')}</h2><p>${text('Prueba otro nombre o tipo de convenio, o limpia la búsqueda.')}</p></div>`:''}`;
    }
    async function detail(p){
      const m=p.meta,admin=p.can_edit;
      const website=m.url?`<a class="btn primary ally-website" href="${E(safeURL(m.url))}" target="_blank" rel="noopener noreferrer">${I('globe')}${text('Visitar sitio web')} ${I('arrow')}</a>`:'';
      const contact=m.contact_name||m.contact_email||m.contact_phone;
      const contactMarkup=contact?`<section class="ally-contact"><h3>${text('Contacto del aliado')}</h3>${m.contact_name?`<p>${E(m.contact_name)}</p>`:''}${m.contact_email?`<a href="mailto:${E(m.contact_email)}">${I('mail')}${E(m.contact_email)}</a>`:''}${m.contact_phone?`<a href="tel:${E(m.contact_phone.replace(/[^+\d]/g,''))}">${E(m.contact_phone)}</a>`:''}</section>`:'';
      const documents=(p.media||[]).filter(file=>!file.mime?.startsWith('image/'));
      const files=documents.length?`<section class="ally-info-section"><h3>${text('Documentos del aliado')}</h3><div class="form-actions">${documents.map(file=>`<a class="btn small" href="${E(file.url)}" target="_blank" rel="noopener noreferrer">${I('download')}${E(file.name)}</a>`).join('')}</div></section>`:'';
      const manage=admin?`<section class="ally-admin-note"><div><strong>${text('Gestión de esta alianza')}</strong><p>${text('Retirar del catálogo conserva la ficha y su historial. Puedes reactivarla más adelante.')}</p></div><div class="form-actions">${btn('Editar aliado','editor',`data-type="ally" data-id="${p.id}"`,'small')}${state(p)==='active'?action(text('Retirar del catálogo'),'status',`data-id="${p.id}" data-state="inactive" data-name="${E(p.title)}"`,'small danger'):action(text('Activar en el catálogo'),'status',`data-id="${p.id}" data-state="active" data-name="${E(p.title)}"`,'small primary')}</div><details><summary>${text('Últimos cambios de estado')}</summary><div data-ally-history-id="${p.id}" role="status">${text('Cargando historial…')}</div></details></section>`:'';
      modal(p.title,`<article class="ally-detail" data-ally-detail="${p.id}"><header class="ally-detail-hero"><div class="ally-detail-logo">${logoMarkup(p)}</div><div><span class="eyebrow">${text(m.alliance_type||'Alianza institucional')}</span><h3>${text('Una alianza con ASCLA')}</h3>${admin?label(p):''}</div></header>${admin&&state(p)!=='active'?`<p class="alert">${text('Esta ficha no está visible en el catálogo de los asociados.')}</p>`:''}<div class="ally-detail-columns"><div>${section('Sobre esta organización',p.body)}${section('Beneficios para asociados',m.benefits)}${section('Condiciones del beneficio',m.conditions)}${section('Iniciativas y recursos',m.initiatives)}${files}</div><aside class="ally-detail-contact">${website}${contactMarkup}${!website&&!contact?`<p>${text('Consulta con ASCLA para obtener más información sobre esta alianza.')}</p>`:''}<p class="ally-institutional-note">${I('ally')}${text('Organización aliada de ASCLA')}</p></aside></div>${manage}</article>`,true);
      if(admin) await loadHistory(p.id);
    }
    async function loadHistory(id){
      const target=root.querySelector(`[data-ally-history-id="${id}"]`);
      try{
        const rows=await api(`admin/allies/${id}/history`);
        if(!target?.isConnected)return;
        target.innerHTML=rows.length?`<ol class="ally-history">${rows.map(row=>`<li><strong>${text(states[row.to]||row.to)}</strong><span>${E(row.actor)} · ${E(date(row.date,{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}))}</span></li>`).join('')}</ol>`:`<p>${text('Todavía no hay cambios de estado registrados para esta ficha.')}</p>`;
        target.removeAttribute('role');
      }catch(error){if(target?.isConnected)target.textContent=T('No se pudo cargar el historial. Vuelve a abrir la ficha.');}
    }
    function editorMarkup({id,p,m,statusOptions,statusValue,publicationControl}){
      const attachments=(m.media_ids||[]).map(mid=>`<span class="attached-file" data-media="${Number(mid)}">${text('Archivo')} #${Number(mid)} ${btn('Quitar','detach-media',`data-id="${Number(mid)}"`,'ghost small')}</span>`).join('');
      const types=['Socio estratégico','Convenio','Otro'];if(m.alliance_type&&!types.includes(m.alliance_type))types.push(m.alliance_type);
      const preserved=p.tags.filter(tag=>tag.taxonomy==='ascla_interest').map(tag=>`<input type="hidden" name="interest" value="${Number(tag.id)}">`).join('');
      return `<form data-form="editor" data-guard-modal-unsaved data-type="ally" data-id="${id}" class="ally-editor"><p class="private-note">${text('Completa la ficha institucional. Su estado determina si aparece en el catálogo de asociados.')}</p><div class="form-grid">${field('title','Nombre del aliado',p.title,'text','required maxlength="200"')}${select('alliance_type','Tipo de alianza',types,m.alliance_type||types[0])}</div>${field('body','Descripción de la organización',p.body,'textarea','required maxlength="30000"')}<div class="form-section">${text('Beneficios de la alianza')}</div>${field('benefits','Beneficios para asociados',m.benefits||'','textarea','maxlength="10000"')}${field('conditions','Condiciones del beneficio',m.conditions||'','textarea','maxlength="10000"')}${field('initiatives','Iniciativas y recursos',m.initiatives||'','textarea','maxlength="10000"')}<div class="form-section">${text('Canales de contacto')}</div>${field('url','Sitio web',m.url||'','url','maxlength="2000"')}<div class="form-grid">${field('contact_name','Persona o área de contacto',m.contact_name||'','text','maxlength="200"')}${field('contact_email','Correo de contacto',m.contact_email||'','email','maxlength="100"')}${field('contact_phone','Teléfono de contacto',m.contact_phone||'','text','maxlength="60"')}</div><div class="form-section">${text('Logotipo y estado')}</div>${logo(p)?`<div class="ally-editor-logo"><img src="${E(logo(p).url)}" alt="${text('Logotipo actual')}"></div>`:''}<label class="btn small">${I('plus')}${text('Adjuntar logotipo')}<input type="file" data-upload="content" accept="image/jpeg,image/png,image/webp" hidden></label><div id="attachments">${attachments}</div><p class="private-note">${text('JPG, PNG o WebP. Para sustituir el logotipo, retira el archivo anterior y adjunta la nueva imagen.')}</p>${select('status','Estado del aliado',statusOptions,statusValue)}${publicationControl}<p class="ally-editor-state-help">${text('Activo aparece en el catálogo. Inactivo conserva el registro sin mostrarlo a los asociados. Borrador permite preparar la ficha.')}</p><input type="hidden" name="category" value="${E(p.tags.find(tag=>tag.taxonomy==='ascla_category')?.id||'')}"><input type="hidden" name="tag_names" value="${E(p.tags.filter(tag=>tag.taxonomy==='ascla_tag').map(tag=>tag.name).join(', '))}">${preserved}<div class="form-actions">${btn('Cancelar','close')}<button class="btn primary">${text('Guardar aliado')}</button></div></form>`;
    }
    function registryRow(p){
      const targetState=state(p)==='active'?'inactive':'active';
      return `<article class="ally-registry-row" data-ally-record="${p.id}"><div class="ally-registry-logo">${logoMarkup(p)}</div><div class="ally-registry-name"><h3>${E(p.title)}</h3><p>${text(p.meta.alliance_type||'Alianza institucional')}</p></div>${label(p)}<div class="form-actions">${btn('Ver ficha','item',`data-id="${p.id}"`,'small')}${btn('Editar','editor',`data-id="${p.id}" data-type="ally"`,'small')}${action(text(targetState==='active'?'Activar':'Retirar'),'status',`data-id="${p.id}" data-state="${targetState}" data-name="${E(p.title)}"`,targetState==='active'?'small primary':'small ghost danger')}</div></article>`;
    }
    async function registry(panel){
      registryPanel=panel;const request=++generation;
      panel.setAttribute('aria-busy','true');
      try{
        const list=await api('admin/allies?'+new URLSearchParams(filters));
        if(request!==generation||!panel.isConnected)return;
        panel.innerHTML=`<div class="admin-section-heading"><div><span class="eyebrow">${text('ALIANZAS INSTITUCIONALES')}</span><h2>${text('Registro de aliados')}</h2><p>${text('Consulta aliados activos, inactivos y borradores de toda la administración. Retirarlos del catálogo no elimina su información.')}</p></div>${btn(I('plus')+' '+text('Crear aliado'),'editor','data-type="ally"','primary')}</div><form class="filters ally-registry-filters" data-allies-search><label class="field"><span>${text('Buscar por nombre, tipo o descripción')}</span><input type="search" name="q" maxlength="150" value="${E(filters.q||'')}"></label>${select('state','Estado',[['','Todos los estados'],...Object.entries(states)],filters.state||'')}<button class="btn primary">${text('Buscar')}</button></form><p class="private-note">${list.total} ${text('aliados registrados')}</p><div class="ally-registry">${list.items.map(registryRow).join('')||`<div class="card ally-empty"><h3>${text('No hay aliados para estos filtros')}</h3><p>${text('Cambia la búsqueda o el estado seleccionado.')}</p></div>`}</div>${list.pages>1?`<nav class="member-pagination" aria-label="${text('Páginas de aliados')}">${action(text('Anterior'),'page',`data-page="${list.page-1}" ${list.page<=1?'disabled':''}`)}<span>${text('Página')} ${list.page} / ${list.pages}</span>${action(text('Siguiente'),'page',`data-page="${list.page+1}" ${list.page>=list.pages?'disabled':''}`)}</nav>`:''}`;
      }finally{if(request===generation)panel.removeAttribute('aria-busy');}
    }
    root.addEventListener('submit',async event=>{
      if(!event.target.matches('[data-allies-search]'))return;
      event.preventDefault();filters={...Object.fromEntries(new FormData(event.target)),page:1};
      try{await registry(registryPanel);}catch(error){if(error.name!=='AbortError')toast(error.message);}
    });
    root.addEventListener('click',async event=>{
      const control=event.target.closest('[data-allies-action]');if(!control||control.disabled)return;
      const key=control.dataset.alliesAction;
      if(key==='status'){
        const inactive=control.dataset.state==='inactive';
        modal(T(inactive?'Retirar aliado del catálogo':'Activar aliado en el catálogo'),`<p><strong>${E(control.dataset.name)}</strong></p><p>${text(inactive?'Dejará de estar visible para los asociados. Su información y su historial se conservarán.':'La ficha volverá a estar visible para los asociados en el catálogo de aliados.')}</p><div class="form-actions">${btn('Cancelar','close')}${action(text(inactive?'Retirar del catálogo':'Activar aliado'),'confirm',`data-id="${Number(control.dataset.id)}" data-state="${control.dataset.state}"`,'primary')}</div>`);return;
      }
      control.disabled=true;
      try{
        if(key==='page'){filters={...filters,page:Number(control.dataset.page)};await registry(registryPanel);}
        if(key==='confirm'){await api(`admin/allies/${Number(control.dataset.id)}/status`,{state:control.dataset.state});closeModal();toast(T(control.dataset.state==='inactive'?'Aliado retirado del catálogo. Su registro se conserva.':'Aliado activo en el catálogo.'));await afterChange();}
      }catch(error){if(error.name!=='AbortError')toast(error.message);}finally{control.disabled=false;}
    });
    return {catalog,detail,editorMarkup,registry};
  };
})();
