/* Publish selected real evidence and an importable collection, excluding private access. */
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),crypto=require('node:crypto');
const {createRequire}=require('node:module');
const root=path.resolve(__dirname,'..'),out=path.join(root,'pruebas'),args=process.argv.slice(2);
const option=name=>{const i=args.indexOf(name);return i<0?null:args[i+1];};
const cli=path.resolve(option('--cli')||process.env.BRU_BIN||path.join(root,'test-results/bruno-tools/node_modules/@usebruno/cli/bin/bru.js'));
const {parseCollection,stringifyCollection}=createRequire(fs.realpathSync(cli))('@usebruno/filestore');
const read=file=>JSON.parse(fs.readFileSync(file,'utf8'));
const pointer=file=>read(path.join(root,'test-results',file)).path;
const backend=path.resolve(option('--backend')||pointer('ultimo-backend-publicable.json'));
const frontend=path.resolve(option('--frontend')||pointer('ultimo-reporte-playwright-usuarios.json'));
const credentialsFile=path.join(root,'test-results/playwright-usuarios.json');
const secrets=[credentialsFile,path.join(root,'test-results/playwright-usuarios-local.json')].filter(file=>fs.existsSync(file)).flatMap(file=>Object.values(read(file).actors).map(actor=>actor.password).filter(Boolean));
const sha=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
const E=value=>String(value??'').replace(/\u001b\[[0-?]*[ -/]*[@-~]/g,'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const rel=file=>path.relative(out,file).replaceAll(path.sep,'/');
const origin=[];
function sanitizeText(text){
  for(const [from,to] of [[root,'repositorio'],[os.homedir(),'equipo']]){
    for(const variant of [from,from.replaceAll('\\','/'),from.replaceAll('\\','\\\\')])text=text.split(variant).join(to);
  }
  return text;
}
function collectionBytes(bytes){
  const c=parseCollection(bytes.toString('utf8'),{format:'bru'});
  for(const v of c.request?.vars?.req||[])if(/^(clave|sesion|nonce)/.test(v.name)||v.name==='respuestaAsistente'){v.value='';delete v.dataType;}
  return Buffer.from(stringifyCollection(c,{version:'1',name:'ASCLA - Integración y E2E',type:'collection'},{format:'bru'}));
}
function copy(from,to){
  const original=fs.readFileSync(from);let bytes=original;
  if(/\.(json|xml|html|cjs|bru|txt|log)$/.test(from)){
    let text=sanitizeText(original.toString('utf8'));
    if(from.endsWith('.xml'))text=text.replace(/\u001b\[[0-?]*[ -/]*[@-~]/g,'').replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/g,'');
    if(secrets.some(secret=>text.includes(secret)))throw Error('Se detectó una contraseña en un archivo seleccionado. No se publicó: '+path.basename(from));
    bytes=Buffer.from(text);
    if(/(?:collection|variables-finales)\.bru$/.test(from))bytes=collectionBytes(bytes);
  }
  fs.mkdirSync(path.dirname(to),{recursive:true});fs.writeFileSync(to,bytes);
  origin.push({source_set:path.basename(path.dirname(from)),source_file:path.basename(from),source_relative:path.relative(root,from).replaceAll(path.sep,'/'),published:rel(to),source_sha256:sha(original),published_sha256:sha(bytes),transformed:!original.equals(bytes)});
}
function files(folder){return fs.readdirSync(folder,{withFileTypes:true}).sort((a,b)=>a.name.localeCompare(b.name)).flatMap(entry=>{const p=path.join(folder,entry.name);if(entry.isSymbolicLink())throw Error('No se publican enlaces simbólicos.');return entry.isDirectory()?files(p):[p];});}
function copyTree(from,to){for(const file of files(from)){if(path.basename(file).startsWith('.')||path.basename(file)==='evidence-manifest.json')continue;const relative=path.relative(from,file).replace(/\.log$/,'.txt');copy(file,path.join(to,relative));}}
const style=`body{font:16px/1.65 system-ui;margin:0;background:#f1f5f9;color:#163047}main{max-width:1150px;margin:30px auto;padding:25px}h1{font-size:32px;line-height:1.2}h2{font-size:23px}section,.card,details{background:white;padding:22px;border-radius:12px;margin:18px 0}a{color:#1658a2}.stats,.grid{display:flex;gap:16px;flex-wrap:wrap}.stats .card{flex:1;min-width:160px}.stats strong{display:block;font-size:30px}.grid>section{flex:1;min-width:290px}.passed{color:#11663b;font-weight:bold}.failed{color:#b02929;font-weight:bold}.muted,small{color:#526a7d}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:12px;border-bottom:1px solid #dce5ee}video{width:100%;background:#0f2233}.media{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px}pre{white-space:pre-wrap;overflow-wrap:anywhere;font-size:13px;background:#f3f6f9;padding:15px}code{overflow-wrap:anywhere}summary{cursor:pointer}button{padding:9px 16px;margin:5px;border:1px solid #bbcad8;border-radius:8px;background:white;color:#163047;cursor:pointer}li{margin:7px 0}@media(max-width:650px){main{padding:14px}table{font-size:13px}td,th{padding:8px}.media{display:block}}`;
function page(file,title,body){fs.mkdirSync(path.dirname(file),{recursive:true});fs.writeFileSync(file,`<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${E(title)} · ASCLA</title><style>${style}section{overflow-x:auto}</style><main><p class="muted">ASCLA · Integración y E2E · Evidencias reales</p><h1>${E(title)}</h1>${body}</main></html>`);}
const nameOf={integracion:'Integración API','01-conexion-y-bloqueo':'Conexión, mensaje y bloqueo','02-evento-con-cupo':'Evento con cupo y lista de espera','03-suspension-y-reactivacion':'Suspensión y reactivación','04-asistente-ia':'Consulta al asistente IA',perfil:'Perfil del asociado','conexion-chat-bloqueo':'Conexión, chat y bloqueo','conexion-chat-historial':'Conexión, chat e historial','borrador-publicacion-inscripcion':'Publicación e inscripción','aforo-lista-espera-notificacion':'Aforo, espera y notificación','suspension-reactivacion-acceso':'Suspensión y recuperación de acceso'};
const bm=read(path.join(backend,'summary.json'));
if(!['https://wordpress.ingsoftware.lat','http://127.0.0.1:8094'].includes(bm.target)||bm.results.filter(r=>r.name!=='runner').length!==5)throw Error('Se requiere un lote completo de la colección publicable.');
const environmentNote=bm.mode==='local-shared-test-users'?'WordPress local aislado con el código actualizado. Asistente: proveedor mock local; no acredita una API externa.':'QA publicado; consultar el modo de IA registrado en sus variables finales.';
const backendDir=path.join(out,'backend/resultados',path.basename(backend));copyTree(backend,backendDir);
const backendRows=[];let requests=0,checks=0;
for(const result of bm.results.filter(r=>r.name!=='runner')){
  const native=read(path.join(backend,result.name+'.json'))[0];
  if(JSON.stringify(native.summary)!==JSON.stringify(result.summary))throw Error('Resumen de Bruno distinto del reporte original.');
  requests+=native.summary.totalRequests;checks+=native.summary.totalTests;
  backendRows.push(`<tr><td>${E(nameOf[result.name]||result.name)}</td><td class="${result.status}">${result.status==='passed'?'Aprobado':'Falló'}</td><td>${native.summary.passedRequests}/${native.summary.totalRequests}</td><td>${native.summary.passedTests}/${native.summary.totalTests}</td><td><a href="resultados/${path.basename(backend)}/${result.name}.html">Bruno HTML</a> · <a href="resultados/${path.basename(backend)}/${result.name}.json">JSON</a> · <a href="resultados/${path.basename(backend)}/${result.name}.xml">JUnit</a></td></tr>`);
}
page(path.join(out,'backend/dashboard.html'),'Bruno Run Dashboard',`<p><a href="../index.html">Entrega general</a> · <a href="README.md">Flujos y utilidad</a> · <a href="coleccion-bruno.zip">Descargar colección con variables</a></p><p>Destino: <b>${E(bm.target)}</b> · Ejecución: ${E(bm.date)}</p><p><b>${E(environmentNote)}</b></p><div class="stats"><div class="card"><strong>${requests}</strong>Peticiones Bruno</div><div class="card"><strong>${checks}</strong>Comprobaciones</div><div class="card"><strong>${bm.results.filter(r=>r.status==='passed').length}/5</strong>Grupos aprobados</div></div><section><h2>Integración y flujos E2E</h2><table><thead><tr><th>Grupo</th><th>Estado</th><th>Peticiones</th><th>Comprobaciones</th><th>Reporte nativo</th></tr></thead><tbody>${backendRows.join('')}</tbody></table><p>Los rechazos 400, 401, 403, 404 y 409 pueden ser resultados esperados: cada paso se evalúa por sus comprobaciones.</p></section><section><h2>Comprobaciones por petición</h2><button data-filter="all">Todas</button><button data-filter="pass">Aprobadas</button><button data-filter="fail">Fallidas</button>${bm.results.filter(r=>r.name!=='runner').map(group=>{const data=read(path.join(backend,group.name+'.json'))[0];return '<h3>'+E(nameOf[group.name])+'</h3>'+data.results.map(item=>`<details data-state="${item.status}"><summary><b>${E(item.name)}</b> · HTTP ${item.response?.status||'—'} · ${item.status==='pass'?'Aprobado':'Falló'}</summary><p><code>${E(item.request?.method)} ${E(item.request?.url)}</code></p><ul>${(item.testResults||[]).map(test=>'<li class="'+(test.status==='pass'?'passed':'failed')+'">'+E(test.description)+(test.error?'<pre>'+E(typeof test.error==='string'?test.error:JSON.stringify(test.error))+'</pre>':'')+'</li>').join('')}</ul></details>`).join('');}).join('')}</section><section><h2>Procedencia</h2><p>Commit del ejecutor: <code>${E(bm.commit)}</code> · Rama: ${E(bm.branch)} · Árbol: ${E(bm.working_tree)}.</p><p>Limpieza: ${E(bm.cleanup.status)}. El commit del ejecutor no acredita qué ZIP del plugin está instalado.</p><a href="resultados/${path.basename(backend)}/summary.json">Resumen de la ejecución</a> · <a href="../procedencia.json">Archivos originales y copias</a></section><script>document.querySelectorAll('[data-filter]').forEach(b=>b.addEventListener('click',()=>document.querySelectorAll('details[data-state]').forEach(d=>d.hidden=b.dataset.filter!=='all'&&b.dataset.filter!==d.dataset.state)));</script>`);
function frontendRun(from){
  const meta=read(path.join(from,'summary.json')),raw=read(path.join(from,'frontend.json'));
  const journeys=raw.results;
  const failed=journeys.some(j=>j.status!=='passed');
  const category=failed?'fallidas':'aprobadas';
  const dest=path.join(out,'frontend',category,path.basename(from));
  for(const file of ['summary.json','frontend.json','frontend.xml'])copy(path.join(from,file),path.join(dest,file));
  for(const journey of journeys){for(const file of [...(journey.videos||[]),...(journey.screenshots||[])])copy(path.join(from,file),path.join(dest,file));}
  for(const name of ['acceptance-ui.cjs','run-playwright-test-users.cjs','qa-access.cjs'])if(fs.existsSync(path.join(from,'sources',name)))copy(path.join(from,'sources',name),path.join(dest,'sources',name));
  const videos=journeys.reduce((n,j)=>n+(j.videos||[]).length,0),shots=journeys.reduce((n,j)=>n+(j.screenshots||[]).length,0);
  const note=meta.mode==='local-shared-test-users'?'Última ejecución en WordPress local aislado con el código actualizado y cuentas sintéticas Ana, Bruno, Carla y Administrador. No acredita una instalación en el servidor publicado.':'Última ejecución en el QA publicado con las cuentas dedicadas Ana, Bruno, Carla y Administrador.';
  page(path.join(dest,'index.html'),failed?'Playwright · Última ejecución con fallo':'Playwright · Recorridos aprobados',`<p><a href="../../dashboard.html">Dashboard de Playwright</a></p><p><b>${E(note)}</b></p><p>Destino: ${E(meta.target)} · Fecha original: ${E(meta.date)}</p><div class="stats"><div class="card"><strong>${journeys.filter(j=>j.status==='passed').length}/${journeys.length}</strong>Recorridos aprobados</div><div class="card"><strong>${videos}</strong>Videos</div><div class="card"><strong>${shots}</strong>Capturas</div></div>${journeys.map(j=>`<section><h2>${E(nameOf[j.name]||j.name)}</h2><p class="${j.status}">${j.status==='passed'?'Aprobado':'Falló'} · ${(j.ms/1000).toFixed(1)} segundos</p>${j.error?'<pre>'+E(j.error)+'</pre>':''}<div class="media">${(j.videos||[]).map((v,i)=>`<article><h3>Sesión ${i+1}</h3><video controls preload="metadata" src="${E(v)}"${j.screenshots?.[i]?' poster="'+E(j.screenshots[i])+'"':''}></video>${j.screenshots?.[i]?'<a href="'+E(j.screenshots[i])+'">Abrir captura</a>':''}</article>`).join('')}</div></section>`).join('')}<section><h2>Resultados originales</h2><a href="summary.json">Metadatos JSON</a> · <a href="frontend.json">Recorridos JSON</a> · <a href="frontend.xml">JUnit</a><p>Versiones registradas: Playwright ${E(meta.tools?.playwright)}; Node.js ${E(meta.tools?.node)}. Las fuentes usadas se conservan en sources/.</p><p>Limpieza: ${E(meta.cleanup?.status)}. El estado y los errores corresponden a la última ejecución.</p></section>`);
  return {id:path.basename(from),category,date:meta.date,target:meta.target,journeys:journeys.length,passed:journeys.filter(j=>j.status==='passed').length,videos,screenshots:shots,report:category+'/'+path.basename(from)+'/index.html',mode:meta.mode||'local-ephemeral'};
}
const front=frontendRun(frontend);
page(path.join(out,'frontend/dashboard.html'),'Playwright · E2E de interfaz',`<p><a href="../index.html">Entrega general</a> · <a href="README.md">Flujos, utilidad y ejecución</a></p><section><h2>Última ejecución seleccionada</h2><p><b>${E(environmentNote)}</b></p><p>Destino: ${E(front.target)} · ${E(front.date)}</p><p><b>${front.passed}/${front.journeys} recorridos aprobados</b>, ${front.videos} videos y ${front.screenshots} capturas.</p><a href="${front.report}">Abrir recorridos y reproducir videos</a></section><section><h2>Conservación de evidencias</h2><p>Esta carpeta contiene solo la última ejecución. Si falla, se guardan sus errores, videos y capturas; al actualizar la entrega se retira la ejecución anterior.</p></section><section><h2>Alcance</h2><p>Cinco recorridos importantes: perfil; conexión, chat y bloqueo; publicación e inscripción; aforo, lista de espera y aviso; suspensión y recuperación del acceso. Las acciones se realizan desde los controles reales con Ana, Bruno, Carla y Administrador. El backend conserva sus cuatro flujos HTTP.</p></section>`);
const collection=path.join(out,'backend/coleccion-bruno');
const collectionFile=path.join(collection,'collection.bru');fs.writeFileSync(collectionFile,collectionBytes(fs.readFileSync(collectionFile)));
const zip=require(path.join(root,'tests/acceptance-provenance.cjs')).zip;
const entries=files(collection).map(file=>['ASCLA-Pruebas/'+path.relative(collection,file).replaceAll(path.sep,'/'),fs.readFileSync(file)]);
fs.writeFileSync(path.join(out,'backend/coleccion-bruno.zip'),zip(entries));
const trace=read(path.join(out,'trazabilidad.json'));
page(path.join(out,'index.html'),'Pruebas de integración y E2E',`<p>Entrega preparada para GitHub. Cada reporte conserva su fecha y entorno; esta página no equivale a otra ejecución.</p><p><b>${E(environmentNote)}</b></p><div class="stats"><div class="card"><strong>${requests}</strong>Peticiones backend</div><div class="card"><strong>${checks}</strong>Comprobaciones backend</div><div class="card"><strong>${front.passed}/${front.journeys}</strong>Recorridos UI aprobados</div><div class="card"><strong>${front.videos}</strong>Videos de la ejecución seleccionada</div></div><div class="grid"><section><h2>1. Backend con Bruno</h2><p>18 peticiones de integración y cuatro flujos E2E: conexión/bloqueo, aforo/espera, suspensión/reactivación y asistente.</p><a href="backend/dashboard.html">Bruno Run Dashboard</a><p><a href="backend/coleccion-bruno.zip">Descargar ZIP con variables</a> · <a href="backend/README.md">Flujos y utilidad</a></p></section><section><h2>2. Frontend con Playwright</h2><p>Cinco flujos desde la interfaz: perfil; conexión/chat/bloqueo; publicación/inscripción; aforo/espera/aviso; suspensión/reactivación. Solo evidencias de la última ejecución, con su resultado aprobado o fallido.</p><a href="frontend/dashboard.html">Abrir dashboard y videos</a><p><a href="frontend/README.md">Flujos y ejecución</a></p></section></div><section><h2>Base documental y alcance</h2><p>Se consultaron el Documento de Análisis y Diseño y el Plan de Prueba y Casos de Prueba entregados por el responsable. <a href="referencias.json">Versiones y huellas de los Word</a>.</p><p>El plan identifica 103 casos: esta entrega acredita escenarios de ${trace.summary.escenario_cubierto}, cubre parcialmente ${trace.summary.parcial} y no incluye ${trace.summary.no_incluido}. Esto no es una aprobación integral del plan ni de UAT.</p><p><a href="trazabilidad.json">Revisar CP, CU, RF, RN y límites</a> · <a href="README.md">Guía de la entrega</a></p><ul>${trace.limitations.map(text=>'<li>'+E(text)+'</li>').join('')}</ul></section><section><h2>Accesos y procedencia</h2><p>El ZIP contiene URL, usuarios de prueba e IDs. Las contraseñas se cargan desde un archivo privado y las sesiones quedan en memoria. No se publican credenciales.</p><p><a href="procedencia.json">Origen y transformaciones de las copias</a> · <a href="manifest.json">Huellas SHA-256 de la entrega</a></p></section>`);
fs.writeFileSync(path.join(out,'procedencia.json'),JSON.stringify({prepared_at:new Date().toISOString(),note:'Los originales se conservan en test-results. Las copias retiran rutas personales y valores de sesión/contraseña/respuesta completa del asistente cuando corresponda. Las vistas HTML organizan los resultados sin alterar sus estados, conteos ni errores.',backend:{run:path.basename(backend),date:bm.date,target:bm.target,mode:bm.mode||'remote-shared-test-users',assistant_provider:bm.assistant_provider||'observado en respuesta'},retention:"latest-only",frontend:front,files:origin},null,2)+'\n');
// Only generated result directories inside pruebas/ may be pruned.
const removed=[];
function pruneRuns(relative,keep=[]){
  const folder=path.resolve(out,relative);
  if(!fs.existsSync(folder))return;
  const outReal=fs.realpathSync(out);
  const inside=target=>{const relative=path.relative(outReal,fs.realpathSync(target));if(!relative||relative==='..'||relative.startsWith('..'+path.sep)||path.isAbsolute(relative))throw Error('Ruta de limpieza fuera de pruebas/: '+target);};
  if(fs.lstatSync(folder).isSymbolicLink())throw Error('No se limpian enlaces simbólicos.');
  inside(folder);
  for(const entry of fs.readdirSync(folder,{withFileTypes:true})){
    if(keep.includes(entry.name))continue;
    const target=path.join(folder,entry.name);
    if(entry.isSymbolicLink())throw Error('No se limpian enlaces simbólicos.');
    inside(target);
    if(entry.isDirectory())files(target); // Reject nested symlinks before recursive removal.
    fs.rmSync(target,{recursive:entry.isDirectory(),force:true});removed.push(rel(target));
  }
  if(fs.readdirSync(folder).length===0)fs.rmdirSync(folder);
}
pruneRuns('backend/resultados',[path.basename(backend)]);
pruneRuns('backend/fallos');
for(const category of ['aprobadas','fallidas'])pruneRuns('frontend/'+category,category===front.category?[front.id]:[]);
const manifest={generated_at:new Date().toISOString(),algorithm:'SHA-256',files:{}};
for(const file of files(out)){if(file===path.join(out,'manifest.json'))continue;if(fs.statSync(file).size>45*1024*1024)throw Error('Archivo demasiado grande para esta entrega: '+rel(file));const bytes=fs.readFileSync(file);if(secrets.some(secret=>bytes.includes(Buffer.from(secret))))throw Error('Contraseña detectada antes de publicar: '+rel(file));manifest.files[rel(file)]=sha(bytes);}
fs.writeFileSync(path.join(out,'manifest.json'),JSON.stringify(manifest,null,2)+'\n');
console.log(JSON.stringify({folder:out,files:Object.keys(manifest.files).length,retention:"latest-only",removed,requests,checks,ui:front,zip:'pruebas/backend/coleccion-bruno.zip'}));
