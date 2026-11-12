/* Reviewed imports are processed in resumable server batches; no user data changes on upload. */
(() => {
  'use strict';
  function orderedHeader(header,first,last) {
    return header.toLowerCase().split(/[\r\n]/).some(line=>{
      const start=line.indexOf(first);
      return start>=0 && line.includes(last,start+first.length);
    });
  }
  globalThis.ASCLAInterestImports=({root,api,E,T,I,modal,closeModal,toast,date,onCatalogChange})=>{
    let panel, csv='',filename='',mime='',source={},inspected,record,running=false,historyPage=1,generation=0;
    const L=t=>E(T(t));const B=(text,key,attrs='',style='')=>`<button type="button" class="btn ${style}" data-import-action="${key}" ${attrs}>${L(text)}</button>`;
    const labels={processing:'Analizando',review:'Revisión pendiente',approved:'Aceptada',ignored:'Ignorada',applying:'Aplicando',complete:'Completada',cancelled:'Cancelada',pending:'Por analizar',updated:'Actualizada',unchanged:'Sin cambios',conflict:'Revisar conflicto'};
    const endpoint=()=>`admin/interest-imports/${record.id}`;
    let topics=[];
    const statusBadge=(status)=>`<span class="tag import-status" data-state="${E(status)}">${L(labels[status]||status)}</span>`;

    function uploadPanel() {
      return `<section class="card interest-upload" aria-labelledby="interest-upload-title">
        <div class="import-panel-heading">
          <span class="import-panel-icon">${I('upload')}</span>
          <div>
            <h3 id="interest-upload-title">${L('Cargar respuestas')}</h3>
            <p>${L('Selecciona un archivo exportado de Google Forms o Sheets.')}</p>
          </div>
        </div>
        <form data-import-form="file" class="interest-upload-form">
          <label class="field"><span>${L('Archivo de respuestas')}</span>
            <input type="file" name="file" accept=".csv,.pdf,.html,.htm,.zip" aria-describedby="interest-file-formats" required>
          </label>
          <p id="interest-file-formats" class="import-hint">${L('CSV hasta 512 KB. PDF con tablas, HTML o ZIP de una hoja hasta 5 MB. Máximo 5000 filas.')}</p>
          <button type="submit" class="btn primary">${L('Analizar columnas')}</button>
        </form>
        <ol class="import-steps">
          <li><strong>${L('Relaciona las columnas')}</strong><span>${L('Indica dónde están los nombres y las respuestas.')}</span></li>
          <li><strong>${L('Revisa la propuesta')}</strong><span>${L('Verifica la cuenta y los temas de cada fila.')}</span></li>
          <li><strong>${L('Aplica los cambios')}</strong><span>${L('Los perfiles se actualizan al confirmar.')}</span></li>
        </ol>
      </section>`;
    }

    function catalogPanel() {
      return `<section class="card interest-catalog" aria-labelledby="interest-catalog-title">
        <div class="import-panel-heading">
          <span class="import-panel-icon">${I('book')}</span>
          <div>
            <h3 id="interest-catalog-title">${L('Catálogo de temas')}</h3>
            <p>${L('Estos temas se usan en las importaciones, los perfiles y los eventos.')}</p>
          </div>
        </div>
        <form data-import-form="topic" class="interest-topic-form">
          <label class="field"><span>${L('Nombre del tema')}</span><input name="name" maxlength="100" required></label>
          <button type="submit" class="btn">${L('Añadir tema')}</button>
        </form>
        <div data-catalog-items></div>
      </section>`;
    }

    function toolsPanel() {
      return `<details class="card interest-tools">
        <summary>${L('Herramientas para Google Forms')}</summary>
        <div class="interest-tools-grid">
          <div>
            <p>${L('Añade al catálogo los temas base de Forms que todavía falten. Los temas existentes se conservan.')}</p>
            ${B('Añadir temas base de Forms','seed')}
          </div>
          <div>
            <p>${L('Comprueba si el proveedor de IA configurado puede sugerir temas. La prueba usa un texto de ejemplo.')}</p>
            ${B('Probar conexión de IA','ai-test')}
          </div>
        </div>
      </details>`;
    }

    function historyPanel(result) {
      const rows=result.items.map(item=>`<tr>
        <th scope="row">${E(item.filename)}</th>
        <td>${E(date(item.created_at+'Z'))}<small>${E(item.actor_name)}</small></td>
        <td>${statusBadge(item.status)}</td>
        <td>${E(item.total)}</td>
        <td>
          ${E(item.summary.found)} ${L('cuentas identificadas')} · ${E(item.summary.not_found)} ${L('sin identificar')}
          <small>${E(item.counts.updated||0)} ${L('actualizadas')} · ${E(item.counts.unchanged||0)} ${L('sin cambios')} · ${E(item.counts.conflict||0)} ${L('conflictos')}</small>
        </td>
        <td>${B('Abrir','open',("data-id=\"" + (Number(item.id)) + "\" aria-label=\"" + (L('Abrir importación')) + " " + (E(item.filename)) + "\""),'small')}</td>
      </tr>`).join('');
      const pages=Math.max(1,Math.ceil(result.total/20));
      const previousDisabled=historyPage===1?'disabled':'';
      const nextDisabled=historyPage>=pages?'disabled':'';
      return `<section class="card interest-history" aria-labelledby="interest-history-title">
        <div class="import-panel-heading">
          <span class="import-panel-icon">${I('clock')}</span>
          <div>
            <h3 id="interest-history-title">${L('Historial de importaciones')}</h3>
            <p>${L('Consulta los resultados o abre una importación pendiente para continuar.')}</p>
          </div>
        </div>
        ${rows?("<div class=\"table-wrap\" role=\"region\" aria-labelledby=\"interest-history-title\" tabindex=\"0\">\n          <table class=\"data-table import-history-table\">\n            <thead><tr>" + (['Archivo','Fecha y administrador','Estado','Filas','Resultado','Acciones'].map(t=>("<th scope=\"col\">" + (L(t)) + "</th>")).join('')) + "</tr></thead>\n            <tbody>" + (rows) + "</tbody>\n          </table>\n        </div>"):("<p class=\"import-empty\">" + (L('Aún no hay importaciones.')) + "</p>")}
        ${pages>1?("<div class=\"admin-pager\">\n          " + (B('Anterior','history',("data-page=\"" + (historyPage-1) + "\" " + (previousDisabled)))) + "\n          <span>" + (historyPage) + " / " + (pages) + "</span>\n          " + (B('Siguiente','history',("data-page=\"" + (historyPage+1) + "\" " + (nextDisabled)))) + "\n        </div>"):''}
      </section>`;
    }

    function renderCatalog(items) {
      topics=items;
      onCatalogChange(items);
      const container=panel?.querySelector('[data-catalog-items]');
      if (!container) return;
      const rows=items.map(topic=>`<li>
        <span>${E(topic.name)}</span>
        ${B('Eliminar','confirm-topic-delete',("data-id=\"" + (Number(topic.id)) + "\" aria-label=\"" + (L('Eliminar')) + " " + (E(topic.name)) + "\""),'small import-topic-remove')}
      </li>`).join('');
      container.innerHTML=rows?`<ul class="interest-topic-list" role="list">${rows}</ul>`:`<p class="import-empty">${L('Aún no hay temas en el catálogo.')}</p>`;
    }
    function confirmTopicDeletion(id) {
      const topic=topics.find(item=>Number(item.id)===id);
      if (!topic) return;
      modal(T('Eliminar tema'),`<p><strong>${E(topic.name)}</strong></p><p>${L('El tema se quitará de los eventos y de los intereses de los perfiles que lo tienen seleccionado.')}</p><div class="form-actions">${B('Cancelar','cancel-topic-delete')}${B('Eliminar tema','delete-topic',("data-id=\"" + (id) + "\""),'danger')}</div>`);
    }
    async function load(target=panel){panel=target;running=false;record=null;await history();}
    async function history(){
      const ticket=++generation;
      const [result,catalog]=await Promise.all([api('admin/interest-imports?page='+historyPage),api('interest-catalog')]);
      if(ticket!==generation||!panel.isConnected)return;
      panel.innerHTML=`<div class="interest-import-workspace">
        <div class="admin-section-heading"><div>
          <h2 tabindex="-1">${L('Importar intereses')}</h2>
          <p>${L('Actualiza los intereses de los asociados a partir de las respuestas de un formulario.')}</p>
        </div></div>
        <div class="interest-import-grid">${uploadPanel()}${catalogPanel()}${toolsPanel()}</div>
        ${historyPanel(result)}
      </div>`;
      renderCatalog(catalog);
    }
    function mapping(){
      const choices=(name,title,guess)=>`<label class="field">
        <span>${L(title)}</span>
        <select name="${name}">
          <option value="-1">${L('No utilizar')}</option>
          ${inspected.headers.map((header,i)=>("<option value=\"" + (i) + "\" " + (guess.test(header)?'selected':'') + ">" + (E(header)) + "</option>")).join('')}
        </select>
      </label>`;
      panel.innerHTML=`<div class="interest-import-workspace">
        <div class="admin-section-heading">
          <div>
            <h2 tabindex="-1">${L('Relacionar columnas')}</h2>
            <p class="import-filename">${E(source.source_filename||filename)} · ${E(inspected.total)} ${L('filas')}</p>
          </div>
          ${B('Volver','history','data-page="1"')}
        </div>
        <form data-import-form="mapping" class="card interest-mapping">
          <fieldset>
            <legend>${L('Identificar a los asociados')}</legend>
            <p class="import-hint">${L('Elige una columna de correo, código o nombre. Si hay correo o código, se usan primero.')}</p>
            <div class="form-grid">
              ${choices('email','Correo',/correo|email|e-mail/i)}
              ${choices('code','Código de asociado',{test:header=>/c[oó]digo/i.test(header) && /asociad/i.test(header)})}
              ${choices('person_name','Nombre o nombre completo',/^(nombre(s)?( completo)?( 1)?|name|first name)$/i)}
              ${choices('name_extra','Segundo nombre (opcional)',/^nombre 2$/i)}
              ${choices('last_name','Apellidos',/^(apellido(s)?( 1)?|last name)$/i)}
              ${choices('last_extra','Segundo apellido (opcional)',/^apellido 2$/i)}
            </div>
            <label class="check"><input type="checkbox" name="only_existing" checked>
              <span>${L('Omitir filas sin una cuenta ASCLA identificada')}</span>
            </label>
          </fieldset>
          <fieldset>
            <legend>${L('Relacionar las respuestas')}</legend>
            <p class="import-hint">${L('Selecciona las columnas que contienen intereses. Si el archivo trata de un evento, puedes proponer sus temas para revisarlos.')}</p>
            <div class="form-grid">
              ${choices('structured','Temas seleccionados en el formulario',{test:header=>orderedHeader(header,'3','tema') || orderedHeader(header,'temas','valor') || /tem[aá]tica|intereses/i.test(header)})}
              ${choices('free','Respuesta abierta / Otros',/otros|respuesta abierta|texto libre/i)}
              ${choices('event_text','Eventos elegidos / tema contextual',{test:header=>orderedHeader(header,'escoge','event') || orderedHeader(header,'evento','particip') || /eventos elegidos/i.test(header)})}
              <label class="field"><span>${L('Tema del formulario o evento (opcional)')}</span>
                <input name="context" maxlength="1000" value="${E(source.context||'')}">
              </label>
              <label class="field"><span>${L('Intereses del evento para proponer · máximo 3 (opcional)')}</span>
                <select name="context_topics" multiple size="5" data-max-selections="3">${inspected.catalog.map(t=>("<option value=\"" + (E(t.id)) + "\">" + (E(t.name)) + "</option>")).join('')}</select>
              </label>
            </div>
          </fieldset>
          <fieldset>
            <legend>${L('Elegir cómo actualizar los perfiles')}</legend>
            <p class="import-hint">${L('Elige si quieres añadir los intereses aprobados o sustituir los existentes. Podrás revisar cada fila antes de aplicar los cambios.')}</p>
            <label class="field"><span>${L('Modo de actualización')}</span>
              <select name="mode">
                <option value="merge">${L('Añadir conservando intereses actuales')}</option>
                <option value="replace">${L('Sustituir intereses tras revisión')}</option>
              </select>
            </label>
          </fieldset>
          ${(source.warnings||[]).map(w=>("<p class=\"import-warnings\">" + (L(w)) + "</p>")).join('')}
          <div class="import-submit">
            <button type="submit" class="btn primary">${L('Crear previsualización')}</button>
            <p class="import-hint">${L('Este paso prepara las propuestas para revisión.')}</p>
          </div>
        </form>
        <section class="card import-sample" aria-labelledby="interest-sample-title">
          <h3 id="interest-sample-title">${L('Muestra del archivo')}</h3>
          <p class="import-hint">${L('Consulta las primeras filas para comprobar que elegiste las columnas correctas.')}</p>
          <div class="table-wrap" role="region" aria-labelledby="interest-sample-title" tabindex="0">
            <table class="data-table">
              <thead><tr>${inspected.headers.map(h=>("<th scope=\"col\">" + (E(h)) + "</th>")).join('')}</tr></thead>
              <tbody>${inspected.sample.map(row=>("<tr>" + (row.values.map(v=>("<td>" + (E(String(v).slice(0,200))) + "</td>")).join('')) + "</tr>")).join('')}</tbody>
            </table>
          </div>
        </section>
      </div>`;
      panel.querySelector('h2').focus();
    }
    function rowMethod(row) {
      const p=row.payload;
      if (p.ai_provider) return p.ai_provider;
      if (!row.user_id) return T('Sin interpretar');
      return T(p.context||p.event_text?'Propuesta contextual':'Normalización determinista');
    }
    function reviewForm(row) {
      const p=row.payload;
      if(record.status!=='review'||['updated','unchanged'].includes(row.state))return '';
      return ("<form data-import-form=\"review\" data-row=\"" + (row.id) + "\">\n          <div class=\"form-grid\">\n            <label class=\"field\"><span>" + (L('Correo para asignación manual')) + "</span><input type=\"email\" name=\"email\" value=\"\" placeholder=\"" + (E(p.email||'')) + "\" maxlength=\"200\"></label>\n            <label class=\"field\"><span>" + (L('Intereses propuestos · máximo 3')) + "</span>\n              <select multiple name=\"topics\" size=\"6\" data-max-selections=\"3\">" + (record.catalog.map(t=>("<option value=\"" + (t.id) + "\" " + ((p.topics||[]).includes(t.id)?'selected':'') + ">" + (E(t.name)) + "</option>")).join('')) + "</select>\n            </label>\n          </div>\n          " + (p.warnings?.length?("<label class=\"check\"><input type=\"checkbox\" name=\"acknowledge\" required><span>" + (L('He revisado las advertencias y confirmo esta selección.')) + "</span></label>"):'') + "\n          <div class=\"import-submit\">\n            <button type=\"submit\" class=\"btn primary\">" + (L('Aceptar fila')) + "</button>\n            " + (B('Ignorar','ignore',("data-row=\"" + (row.id) + "\""))) + "\n            " + (row.user_id&&(p.free||p.context||p.event_text)?B('Proponer con IA','ai',("data-row=\"" + (row.id) + "\"")):'') + "\n          </div>\n          " + (row.user_id&&(p.free||p.context||p.event_text)?("<p class=\"import-hint\">" + (L('La IA recibe solo la respuesta o el tema del evento depurado y el catálogo. No recibe identificadores ni perfiles. Su propuesta siempre requiere tu revisión.')) + "</p>"):'') + "\n        </form>");
    }
    function reviewRow(row) {
      const p=row.payload;
      const account=p.name||p.email||[p.person_name,p.name_extra,p.last_name,p.last_extra].filter(Boolean).join(' ')||p.code||T('Sin identificar');
      const identityMethod=({email:'Correo',code:'Código de asociado',name:'Nombre',similar_name:'Nombre similar'})[p.identity_method]||'Sin identificar';
      return `<article class="card import-row" data-import-row="${row.id}">
        <header class="import-row-heading">
          <div><p>${L('Fila')} ${E(p.line||row.position)}</p><h3>${E(account)}</h3></div>
          ${statusBadge(row.state)}
        </header>
        <dl>
          <dt>${L('Identificación de la cuenta')}</dt><dd>${L(identityMethod)} · ${E([p.person_name,p.last_name].filter(Boolean).join(' ')||p.email||'—')}</dd>
          <dt>${L('Tema contextual')}</dt><dd>${E([p.context,p.event_text].filter(Boolean).join(' · ')||'—')}</dd>
          <dt>${L('Respuesta estructurada original')}</dt><dd>${E(p.structured||'—')}</dd>
          <dt>${L('Texto libre original')}</dt><dd>${E(p.free||'—')}</dd>
          <dt>${L('Método / confianza')}</dt><dd>${E(rowMethod(row))} · ${L(row.user_id?(p.confidence||'high'):'unknown')}</dd>
        </dl>
        ${p.classification?.length?("<details><summary>" + (L('Origen de cada categoría')) + "</summary><ul>" + (p.classification.map(c=>("<li>" + (E(c.original)) + " → " + (E(record.catalog.find(t=>t.id===c.topic_id)?.name||T('Revisión manual'))) + " · " + (L(c.confidence)) + "</li>")).join('')) + "</ul></details>"):''}
        ${p.warnings?.length?("<ul class=\"import-warnings\">" + (p.warnings.map(w=>("<li>" + (L(w)) + "</li>")).join('')) + "</ul>"):''}
        ${reviewForm(row)}
      </article>`;
    }
    function render(){
      if(!panel?.isConnected)return;
      const counts=record.counts;
      const remaining=record.status==='processing'?(counts.pending||0):(counts.approved||0);
      const completed=record.total-remaining;
      const previousDisabled=record.page===1?'disabled':'';
      const nextDisabled=record.page>=record.pages?'disabled':'';
      const pending=(counts.review||0)+(counts.conflict||0)+(counts.pending||0);
      const confirmDisabled=pending?'disabled':'';
      const runLabel=running?'Pausar':'Continuar';
      panel.innerHTML=`<div class="interest-import-workspace">
        <div class="admin-section-heading">
          <div>
            <h2 tabindex="-1" class="import-filename">${E(record.config.source_filename||record.filename)}</h2>
            <p>${E(record.total)} ${L('filas')} · ${L(record.config.mode==='replace'?'Sustituir intereses tras revisión':'Añadir conservando intereses actuales')}</p>
          </div>
          ${B('Historial','history','data-page="1"')}
        </div>
        <section class="card import-progress" aria-labelledby="interest-progress-title">
          <header class="import-row-heading">
            <h3 id="interest-progress-title">${L('Progreso de la importación')}</h3>
            ${statusBadge(record.status)}
          </header>
          <progress aria-labelledby="interest-progress-title" max="${record.total}" value="${completed}">${completed}/${record.total}</progress>
          <p class="import-counts">${Object.entries(counts).map(([state,n])=>("<span><strong>" + (E(n)) + "</strong> " + (L(labels[state]||state)) + "</span>")).join('')}</p>
          ${record.status==='review'?("<p class=\"import-hint\">" + (L('Acepta o ignora cada fila pendiente. Cuando termines, confirma para actualizar los perfiles.')) + "</p>"):''}
          <div class="import-submit">
            ${['processing','applying'].includes(record.status)?B(runLabel,'run','','primary'):''}
            ${record.status==='review'?B('Confirmar y aplicar','confirm',confirmDisabled,'primary'):''}
            ${['processing','review'].includes(record.status)?B('Cancelar importación','cancel','','danger'):''}
          </div>
          ${['processing','applying'].includes(record.status)?("<p class=\"import-hint\">" + (L('Puedes pausar y continuar desde el historial.')) + "</p>"):''}
        </section>
        <div class="import-rows">${record.rows.map(reviewRow).join('')}</div>
        ${record.pages>1?("<div class=\"admin-pager\">\n          " + (B('Anterior','page',("data-page=\"" + (record.page-1) + "\" " + (previousDisabled)))) + "\n          <span>" + (record.page) + " / " + (record.pages) + "</span>\n          " + (B('Siguiente','page',("data-page=\"" + (record.page+1) + "\" " + (nextDisabled)))) + "\n        </div>"):''}
      </div>`;
    }
    async function processBatches(ticket) {
      while(running&&panel.isConnected&&ticket===generation&&['processing','applying'].includes(record.status)) {
        const result=await api(endpoint()+(record.status==='processing'?'/process':'/apply'),{});
        if(ticket!==generation)return;
        record=result;render();
      }
    }
    async function run(){
      if(running){running=false;render();return;}
      const ticket=generation;running=true;render();
      try{await processBatches(ticket);}
      finally{if(ticket===generation){running=false;render();}}
    }
    async function work(control,fn){if(control.disabled){return;}const label=control.textContent;control.disabled=true;try{await fn();}catch(e){toast(T(e.message));}finally{control.disabled=false;if(control.isConnected){control.textContent=label;}}}
    async function aiAction(a,b) {
      if(a==='ai-test'){const result=await api('admin/interest-imports/ai-test',{});modal(T('Diagnóstico IA · Google Forms'),`<p>${L('Conexión y contrato de clasificación correctos.')}</p><dl><dt>${L('Proveedor')}</dt><dd>${E(result.provider)}</dd><dt>${L('Modelo')}</dt><dd>${E(result.model||'—')}</dd><dt>${L('Confianza devuelta')}</dt><dd>${L(result.confidence)}</dd><dt>${L('IDs válidos')}</dt><dd>${result.topics.map(t=>("" + (E(t.name)) + " (#" + (t.id) + ")")).join(', ')}</dd></dl><p class="private-note">${L('La prueba usa texto sintético y el catálogo actual; no envía respuestas, correos ni perfiles de asociados.')}</p>`);}
      else if(a==='ai'){await api(endpoint()+`/rows/${Number(b.dataset.row)}/ai`,{});record=await api(endpoint()+'?page='+record.page);render();}
    }
    root.addEventListener('click',event=>{const b=event.target.closest('[data-import-action]');if(!b){return;}
      event.preventDefault();void work(b,async()=>{
      const a=b.dataset.importAction;
      if(a==='history'){running=false;historyPage=Number(b.dataset.page);await history();panel.querySelector('h2')?.focus();}
      else if(a==='open'){running=false;++generation;record=await api('admin/interest-imports/'+Number(b.dataset.id));render();panel.querySelector('h2')?.focus();}
      else if(a==='page'){record=await api(endpoint()+'?page='+Number(b.dataset.page));render();}
      else if(a==='run'){await run();}
      else if(a==='seed'){renderCatalog(await api('admin/interest-catalog',{seed:true}));toast(T('Catálogo preparado.'));}
      else if(a==='confirm-topic-delete'){confirmTopicDeletion(Number(b.dataset.id));}
      else if(a==='cancel-topic-delete'){closeModal();}
      else if(a==='delete-topic'){
        renderCatalog(await api('admin/interest-catalog/'+Number(b.dataset.id),null,'DELETE'));
        closeModal();
        toast(T('Tema eliminado.'));
        panel?.querySelector('[data-import-form="topic"] [name="name"]')?.focus();
      }
      else if(['ai-test','ai'].includes(a)){await aiAction(a,b);}
      else if(a==='ignore'){record=await api(endpoint()+`/rows/${Number(b.dataset.row)}`,{decision:'ignore',page:record.page});render();}
      else if(a==='cancel'){running=false;record=await api(endpoint()+'/cancel',{});render();}
      else if(a==='confirm'){modal(T('Confirmar actualización de perfiles'),`<p>${record.counts.approved||0} ${L('filas aceptadas se aplicarán a los perfiles.')}</p><p>${L(record.config.mode==='replace'?'Se sustituirán los intereses de las respuestas no vacías aceptadas.':'Se añadirán los intereses aceptados conservando los actuales.')}</p><div class="form-actions">${B('Aplicar cambios','apply','','primary')}</div>`);}
      else if(a==='apply'){record=await api(endpoint()+'/confirm',{});closeModal();render();await run();}
    });});
    root.addEventListener('submit',event=>{const form=event.target.closest('form[data-import-form]');if(!form){return;}
      event.preventDefault();const b=form.querySelector('button:not([type]),button[type="submit"]');void work(b,async()=>{
      const values=Object.fromEntries(new FormData(form));
      if(form.dataset.importForm==='file'){
        const f=form.elements.file.files[0];
        if(!f) return;
        b.textContent=T('Leyendo archivo…');
        source=await globalThis.ASCLAInterestFileReader(f,(n,total)=>{b.textContent=T('Leyendo PDF')+' '+n+'/'+total;});
        csv=source.csv;
        filename=source.filename;
        mime=source.mime;
        b.textContent=T('Analizando columnas…');
        inspected=await api('admin/interest-imports/inspect',{csv,filename,mime});
        mapping();
      }
      else if(form.dataset.importForm==='mapping'){record=await api('admin/interest-imports',{csv,filename,mime,source_filename:source.source_filename,source_type:source.source_type,context:values.context,context_topics:new FormData(form).getAll('context_topics').map(Number),only_existing:!!values.only_existing,mode:values.mode,columns:Object.fromEntries(['email','code','person_name','name_extra','last_name','last_extra','event_text','structured','free'].map(k=>[k,Number(values[k])]))});csv='';render();await run();}
      else if(form.dataset.importForm==='review'){const topics=new FormData(form).getAll('topics').map(Number);if(topics.length>3){throw new Error(T('Selecciona como máximo 3 intereses provenientes del formulario.'));}record=await api(endpoint()+`/rows/${Number(form.dataset.row)}`,{decision:'accept',email:values.email,topics,acknowledge:!!values.acknowledge,page:record.page});render();}
      else if(form.dataset.importForm==='topic'){
        const topic=await api('admin/interest-catalog',{name:values.name});
        renderCatalog(await api('interest-catalog'));
        toast(T(topic.existing?'El tema ya existe en el catálogo.':'Tema preparado.'));
        form.reset();
      }
    });});
    return {load};
  };
})();
