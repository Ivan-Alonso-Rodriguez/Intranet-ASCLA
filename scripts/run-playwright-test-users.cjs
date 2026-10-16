/* UI journeys using the same dedicated accounts as Bruno, in QA or local fixtures. */
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const {execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),args=process.argv.slice(2);
const option=name=>{const i=args.indexOf(name);return i<0?null:args[i+1];};
const access=JSON.parse(fs.readFileSync(path.resolve(option('--fixture')||path.join(root,'test-results/playwright-usuarios.json')),'utf8'));
const mode=require('./qa-access.cjs')(access);
const base=access.base,run=new Date().toISOString().replace(/[:.]/g,'-');
const out=path.join(root,'test-results','playwright-usuarios-'+run),prefix='ascla_ui_'+run.replace(/[^0-9]/g,'')+'_';
fs.mkdirSync(out,{recursive:true});
const secrets=Object.values(access.actors).map(actor=>actor.password);
const redact=value=>secrets.reduce((text,secret)=>text.split(secret).join('[contraseña omitida]'),String(value));
const E=value=>String(value??'').replace(/\u001b\[[0-?]*[ -/]*[@-~]/g,'').replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/g,'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const events=[];let admin,bruno,results=[],cleanup={status:'pending'},failure=false;
const fixture={base,prefix,directoryQuery:'Prueba ASCLA',sharedAccounts:true,headless:!args.includes('--headed'),browser_channel:access.browser_channel||'msedge',actors:{admin:access.actors.Administrador,ui_a:access.actors.Ana,ui_b:access.actors.Bruno,profile:access.actors.Carla},onEventCreated(event){if(!Number.isInteger(event.id)||event.id<1||![' Evento de interfaz',' Evento de aforo'].some(suffix=>event.title===prefix+suffix))throw Error('Evento de prueba inesperado.');events.push(event);fs.writeFileSync(path.join(out,'created-events.json'),JSON.stringify(events,null,2)+'\n');}};
async function login(actor){
  const r=await fetch(base+'/wp-login.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded',Cookie:'wordpress_test_cookie=WP%20Cookie%20check'},body:new URLSearchParams({log:actor.login,pwd:actor.password,testcookie:'1',redirect_to:base+'/intranet/'}),redirect:'manual',signal:AbortSignal.timeout(45000)});
  if(r.status!==302)throw Error('No se pudo iniciar sesión para '+actor.login+' (HTTP '+r.status+')');
  const cookie=r.headers.getSetCookie().map(c=>c.split(';')[0]).join('; ');
  const page=await fetch(base+'/intranet/',{headers:{Cookie:cookie},signal:AbortSignal.timeout(45000)});
  const match=(await page.text()).match(/var ASCLA\s*=\s*(\{[\s\S]*?\});/);
  if(!match)throw Error('No se encontró la sesión REST para '+actor.login);
  const session={cookie,nonce:JSON.parse(match[1]).nonce};
  const identity=await api('/bootstrap',session);
  if(identity.status!==200||Number(identity.data.me?.id)!==actor.id)throw Error('La identidad no coincide con la cuenta de prueba configurada.');
  return {...session,admin:identity.data.admin};
}
async function api(route,session,body){
  const r=await fetch(base+'/wp-json/ascla/v1'+route,{method:body===undefined?'GET':'POST',headers:{Cookie:session.cookie,'X-WP-Nonce':session.nonce,...(body===undefined?{}:{'Content-Type':'application/json'})},body:body===undefined?undefined:JSON.stringify(body),redirect:'manual',signal:AbortSignal.timeout(45000)});
  const data=await r.json();return {status:r.status,data};
}
function ok(result,label){if(result.status!==200)throw Error(label+': HTTP '+result.status+'; '+(result.data?.message||'respuesta inesperada'));return result.data;}
async function prepare(){
  admin=await login(access.actors.Administrador);if(!admin.admin)throw Error('La cuenta de administración no tiene los permisos de ASCLA.');
  const carla=ok(await api('/admin/users/'+access.actors.Carla.id,admin),'Verificar cuenta dedicada de Carla');
  if(carla.login!==access.actors.Carla.login)throw Error('La identidad de Carla no corresponde a la cuenta dedicada.');
  if(carla.suspended)ok(await api('/admin/member/'+access.actors.Carla.id,admin,{suspended:false}),'Preparar acceso activo de Carla');
  bruno=await login(access.actors.Bruno);
  const state=ok(await api('/profiles/'+access.actors.Ana.id,bruno),'Consultar la pareja de prueba').connection;
  if(state.retry_at)throw Error('La pareja tiene un período de espera activo; no se omite esa regla.');
  if(state.state!=='none'||state.blocked){for(const active of [true,false])ok(await api('/relations',bruno,{target:access.actors.Ana.id,kind:'block',active}),'Preparar la pareja dedicada');}
  const sources=path.join(out,'sources');fs.mkdirSync(sources,{recursive:true});
  for(const [from,name] of [[path.join(root,'tests/acceptance-ui.cjs'),'acceptance-ui.cjs'],[__filename,'run-playwright-test-users.cjs'],[path.join(__dirname,'qa-access.cjs'),'qa-access.cjs']]){
    const bytes=fs.readFileSync(from);if(secrets.some(secret=>bytes.includes(Buffer.from(secret))))throw Error('Credenciales inesperadas en las fuentes.');fs.writeFileSync(path.join(sources,name),bytes);
  }
}
async function restore(){
  const status={status:'passed',unblocked:false,carla_active:false,events_cancelled:[]};
  if(admin){
    let carla=ok(await api('/admin/users/'+access.actors.Carla.id,admin),'Verificar estado final de Carla');
    if(carla.login!==access.actors.Carla.login)throw Error('La cuenta de restauración no es la cuenta dedicada.');
    if(carla.suspended){ok(await api('/admin/member/'+access.actors.Carla.id,admin,{suspended:false}),'Restaurar a Carla tras el recorrido');carla=ok(await api('/admin/users/'+access.actors.Carla.id,admin),'Verificar reactivación final de Carla');}
    if(carla.suspended)throw Error('No se confirmó la reactivación de Carla.');status.carla_active=true;
  }
  if(bruno){ok(await api('/relations',bruno,{target:access.actors.Ana.id,kind:'block',active:false}),'Retirar bloqueo de la pareja de prueba');status.unblocked=true;}
  for(const event of events){
    const data=ok(await api('/events/'+event.id,admin),'Verificar el evento propio');
    if(data.title!==event.title||!event.title.startsWith(prefix))throw Error('El evento no pertenece a esta ejecución.');
    if(!data.cancelled&&!ok(await api('/events/'+event.id+'/cancel',admin,{reason:'Cierre del evento dedicado a las pruebas de interfaz.'}),'Cancelar evento propio').cancelled)throw Error('No se confirmó la cancelación del evento propio.');
    status.events_cancelled.push(event.id);
  }
  return status;
}
function render(meta){
  const names={perfil:'Perfil · Carla','conexion-chat-bloqueo':'Conexión, chat y bloqueo · Ana y Bruno','borrador-publicacion-inscripcion':'Evento e inscripción · Administrador y Ana','aforo-lista-espera-notificacion':'Aforo, lista de espera y notificación','suspension-reactivacion-acceso':'Suspensión y recuperación del acceso',runner:'Preparación o ejecución'};
  const flow={perfil:'Login de Carla → editar biografía → guardar → recargar → comprobar persistencia → rechazar Empresa vacía → conservar datos anteriores.','conexion-chat-bloqueo':'Login de Ana y Bruno → solicitar conexión → aceptar → enviar y leer mensaje → bloquear → comprobar que no puede iniciar un chat → desbloquear → comprobar que la conexión no se restaura.','borrador-publicacion-inscripcion':'Login de Administrador y Ana → crear borrador → validar campos obligatorios → publicar → comprobar fechas → inscribirse → recargar y comprobar inscripción → cancelar el evento de prueba.'};
  flow['aforo-lista-espera-notificacion']='Crear evento con un cupo → Ana confirma → Bruno entra en espera → Ana cancela → cupo ofrecido → abrir notificación del evento → confirmar vacante → cancelar evento.';
  flow['suspension-reactivacion-acceso']='Carla accede → Administrador suspende → sesión anterior pierde acceso → nuevo login rechazado → Administrador reactiva → login nuevo válido → biografía conservada.';
  const cards=meta.results.map(result=>`<section><h2>${E(names[result.name]||result.name)}</h2><p class="${result.status}">${result.status==='passed'?'Aprobado':'Falló'} · ${(Number(result.ms||0)/1000).toFixed(1)} s</p><p>${E(flow[result.name]||'')}</p>${result.error?'<pre>'+E(result.error)+'</pre>':''}<div class="media">${(result.videos||[]).map((video,index)=>`<article><h3>Sesión ${index+1}</h3><video controls preload="metadata" src="${E(video)}"${result.screenshots?.[index]?' poster="'+E(result.screenshots[index])+'"':''}></video>${result.screenshots?.[index]?'<p><a href="'+E(result.screenshots[index])+'">Abrir captura</a></p>':''}</article>`).join('')}</div></section>`).join('');
  fs.writeFileSync(path.join(out,'index.html'),`<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ASCLA · Playwright con usuarios de prueba</title><style>body{font:17px/1.6 system-ui;margin:0;background:#f1f5f9;color:#153047}main{max-width:1100px;margin:30px auto;padding:28px}section{background:white;border-radius:14px;padding:24px;margin:20px 0}h1{font-size:30px}.passed{color:#167144;font-weight:bold}.failed,pre{color:#b52929}.media{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px}video{width:100%;background:#101820}pre{white-space:pre-wrap;font-size:14px}a{color:#145aa2}small{color:#526a7c}</style><main><h1>Playwright con usuarios de prueba</h1><p>Destino: <a href="${base}">${base}</a> · ${new Intl.DateTimeFormat('es-PE',{timeZone:'America/Lima',dateStyle:'medium',timeStyle:'short'}).format(new Date(meta.date))} (Lima)</p><p><strong>${meta.results.filter(r=>r.status==='passed').length}/${meta.results.filter(r=>r.name!=='runner').length} recorridos aprobados</strong> · ${meta.results.reduce((n,r)=>n+(r.videos||[]).length,0)} videos</p><p>Carla: perfil y suspensión/reactivación. Ana y Bruno: conexión y chat. Administrador y Ana: publicación. Administrador, Ana y Bruno: aforo y espera. Las cuentas se conservan; el ejecutor prepara la pareja y cierra sus propios eventos mediante REST. Las acciones principales de cada recorrido se realizan desde la interfaz.</p>${cards}<section><h2>Procedencia y estado final</h2><p>Playwright ${E(meta.tools.playwright)} · ${E(meta.browser_channel)} · ${E(meta.mode)}</p><p>Limpieza: ${E(meta.cleanup.status)}. ${meta.cleanup.error?E(meta.cleanup.error):'Cuentas conservadas, Carla activa, pareja desbloqueada y eventos propios cancelados.'}</p><p><a href="summary.json">Resumen JSON</a> · <a href="frontend.xml">JUnit</a> · <a href="evidence-manifest.json">Huellas de las evidencias</a></p><small>Los videos y capturas se guardan tanto al aprobar como al fallar. Las fuentes utilizadas están en sources/. Este reporte corresponde al destino y modo registrados en esta ejecución.</small></section></main></html>`);
}
(async()=>{
  try{await prepare();results=await require(path.join(out,'sources/acceptance-ui.cjs'))({fixture,out});if(results.length!==5||results.some(result=>result.status!=='passed'))failure=true;}
  catch(error){failure=true;results.push({name:'runner',status:'failed',error:redact(error.message),videos:[],screenshots:[]});console.error(redact(error.message));}
  finally{
    try{cleanup=await restore();}catch(error){failure=true;cleanup={status:'failed',error:redact(error.message)};}
    let commit=null,branch=null,workingTree=null;try{commit=execFileSync('git',['rev-parse','HEAD'],{cwd:root,encoding:'utf8'}).trim();branch=execFileSync('git',['branch','--show-current'],{cwd:root,encoding:'utf8'}).trim();workingTree=execFileSync('git',['status','--porcelain'],{cwd:root,encoding:'utf8'}).trim()?'uncommitted':'clean';}catch{}
    const meta={date:new Date().toISOString(),target:base,mode,browser_channel:fixture.browser_channel,tools:{node:process.version,playwright:require('playwright/package.json').version},commit,branch,working_tree:workingTree,actors:Object.fromEntries(Object.entries(access.actors).map(([name,actor])=>[name,{id:actor.id,login:actor.login}])),results,cleanup};
    fs.writeFileSync(path.join(out,'summary.json'),JSON.stringify(meta,null,2)+'\n');
    fs.writeFileSync(path.join(out,'frontend.xml'),'<testsuites><testsuite name="ASCLA UI usuarios de prueba" tests="'+results.length+'" failures="'+results.filter(r=>r.status!=='passed').length+'">'+results.map(r=>'<testcase name="'+E(r.name)+'" time="'+Number(r.ms||0)/1000+'">'+(r.error?'<failure>'+E(r.error)+'</failure>':'')+'</testcase>').join('')+'</testsuite></testsuites>');
    render(meta);
    const hashes={};function walk(folder){for(const entry of fs.readdirSync(folder,{withFileTypes:true})){const file=path.join(folder,entry.name);if(entry.isDirectory())walk(file);else{if(/\.(json|xml|html|cjs|log)$/.test(entry.name)&&secrets.some(secret=>fs.readFileSync(file,'utf8').includes(secret)))throw Error('El reporte contiene datos privados y requiere revisión.');hashes[path.relative(out,file).replaceAll(path.sep,'/')]=crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');}}}walk(out);
    fs.writeFileSync(path.join(out,'evidence-manifest.json'),JSON.stringify({algorithm:'SHA-256',files:hashes},null,2)+'\n');
    fs.writeFileSync(path.join(root,'test-results/ultimo-reporte-playwright-usuarios.json'),JSON.stringify({path:out},null,2)+'\n');
    console.log('Reporte de Playwright: '+path.join(out,'index.html'));if(failure)process.exitCode=1;
  }
})().catch(error=>{console.error(redact(error.message));process.exitCode=1;});
