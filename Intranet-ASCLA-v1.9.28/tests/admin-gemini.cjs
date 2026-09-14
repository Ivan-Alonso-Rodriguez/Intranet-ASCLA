const {chromium}=require('playwright'),fs=require('node:fs'),assert=require('node:assert/strict'),{execFileSync}=require('node:child_process');
const base='http://localhost:8088',results=[],errors=[];
const fixture=input=>JSON.parse(execFileSync('docker',['compose','exec','-T','wordpress','php','/opt/ascla-tests/admin-gemini-fixture.php'],{input:JSON.stringify(input),encoding:'utf8'}));
async function api(p,route,body,nonce=true){return p.evaluate(async({route,body,nonce})=>{const r=await fetch(ASCLA.api+route,{method:body?'POST':'GET',headers:{...(nonce?{'X-WP-Nonce':ASCLA.nonce}:{}),'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})});return {status:r.status,data:await r.json()};},{route,body,nonce});}
async function ok(p,route,body){const r=await api(p,route,body);assert.equal(r.status,200,route+' '+JSON.stringify(r.data));return r.data;}
async function login(browser,u){const c=await browser.newContext({viewport:{width:1440,height:1100}}),p=await c.newPage();p.on('pageerror',e=>errors.push(e.message));await p.goto(base+'/wp-login.php');await p.locator('#user_login').fill(u.login);await p.locator('#user_pass').fill(u.password);await Promise.all([p.waitForNavigation(),p.locator('#wp-submit').click()]);await p.goto(base+'/intranet/');await p.locator('.hero').waitFor();return p;}
async function test(name,fn){const t=Date.now();await fn();results.push({name,status:'passed',ms:Date.now()-t});console.log('PASS '+name);}
(async()=>{let data,browser;try{
 data=fixture({action:'setup'});browser=await chromium.launch({channel:'msedge',headless:true});const [ua,um,uu]=data.users,a=await login(browser,ua),m=await login(browser,um),u=await login(browser,uu);let request,forum;
 await test('Solicitudes: cambia a En atención, muestra historial y persiste tras recarga',async()=>{
  request=await ok(u,'content/contact',{title:'Consulta de prueba '+data.token,body:'Necesito orientación para participar en un encuentro de asociados.',meta:{description:'Participación'}});
  await a.goto(base+'/wp-admin/admin.php?page=ascla-solicitudes');await a.locator('[data-contact="'+request.id+'"]').waitFor();
  await a.locator('[aria-label="Buscar solicitudes"]').fill(data.token);await a.locator('[data-area="contacts"] button[type="submit"], [data-area="contacts"] button:not([type])').click();
  const card=a.locator('[data-contact="'+request.id+'"]');await card.locator('[data-status="progress"]').click();await a.locator('[data-contact="'+request.id+'"][data-state="progress"]').waitFor();assert.ok(await card.locator('.request-history').count());
  await a.reload();await a.locator('[data-contact="'+request.id+'"][data-state="progress"]').waitFor();
  await a.locator('[aria-label="Buscar solicitudes"]').fill(data.token);await a.locator('[data-area="contacts"] button:not([type])').click();await a.evaluate(()=>scrollTo(0,0));await a.screenshot({path:'test-results/admin-requests.png',fullPage:true});
 });
 await test('Solicitudes: resolver, filtrar, reabrir y mostrar estado al asociado',async()=>{
  await a.locator('[data-contact="'+request.id+'"] [data-status="closed"]').click();await a.locator('[data-contact="'+request.id+'"][data-state="closed"]').waitFor();
  await a.locator('[data-action="request-filter"][data-state="closed"]').click();await a.locator('[data-contact="'+request.id+'"] [data-status="open"]').click();await a.locator('[data-contact="'+request.id+'"]').waitFor({state:'detached'});
  assert.equal((await ok(u,'items/'+request.id)).meta.request_status,'open');assert.equal((await api(u,'admin/contact/'+request.id,{status:'closed'})).status,403);
 });
 await test('Usuarios: buscar, ver rol/perfil, suspender y reactivar acceso',async()=>{
  await a.locator('[data-action="admin-tab"][data-tab="usuarios"]').last().click();await a.locator('[aria-label="Buscar usuarios"]').fill(uu.login);await a.locator('[data-area="users"] button:not([type])').click();
  let card=a.locator('[data-admin-user="'+uu.id+'"]');await card.waitFor();assert.ok(await card.getByText('Asociado ASCLA',{exact:true}).count());assert.ok(await card.getByRole('link',{name:'Ver perfil',exact:true}).count());assert.ok(await card.getByRole('link',{name:'Editar cuenta'}).count());
  await a.waitForFunction(()=>document.querySelectorAll('[data-admin-user]').length===1);await a.evaluate(()=>scrollTo(0,0));await a.screenshot({path:'test-results/admin-users.png',fullPage:true});await card.getByRole('button',{name:'Suspender acceso'}).click();await a.locator('.modal [data-action="user-status"]').click();await card.getByRole('button',{name:'Reactivar acceso'}).waitFor();
  assert.equal((await api(u,'connections')).status,403);await card.getByRole('button',{name:'Reactivar acceso'}).click();await a.locator('.modal [data-action="user-status"]').click();await card.getByRole('button',{name:'Suspender acceso'}).waitFor();assert.equal((await api(u,'connections')).status,200);
  assert.equal((await api(m,'admin/users')).status,403);
 });
 await test('Configuración Gemini: campos, guardar modelo, eliminar clave y prueba de error segura',async()=>{
  await a.locator('[data-action="admin-tab"][data-tab="configuracion"]').click();await a.locator('[name="ai_provider"] option[value="gemini"]').waitFor({state:'attached'});assert.equal(await a.locator('[name="ai_provider"] option[value="gemini"]').innerText(),'Google Gemini · API real');assert.equal(await a.locator('[name="ai_provider"] option[value="openai"]').innerText(),'OpenAI / ChatGPT · API real');
  await a.locator('[name="ai_provider"]').selectOption('gemini');await a.locator('[name="ai_model"]').fill('gemini-2.5-flash');await a.locator('[name="clear_ai_key"]').check();const settingsSaved=a.waitForResponse(r=>r.url().includes('/settings')&&r.request().method()==='POST');await a.getByRole('button',{name:'Guardar configuración',exact:true}).click();assert.equal((await settingsSaved).status(),200);await a.waitForFunction(()=>document.querySelector('[name=clear_ai_key]')?.checked===false);assert.equal((await ok(a,'settings')).ai_model,'gemini-2.5-flash');
  const probed=a.waitForResponse(r=>r.url().includes('/ai/test'));await a.getByRole('button',{name:'Probar conexión',exact:true}).click();assert.equal((await probed).status(),400);await a.waitForFunction(()=>document.getElementById('ai-test-result')?.textContent.includes('Configura una Gemini API Key'));assert.equal((await api(u,'ai/test',{})).status,403);assert.equal((await api(a,'ai/test',{},false)).status,401);
  await a.screenshot({path:'test-results/admin-gemini-settings.png',fullPage:true});
 });
 await test('Galería y Conocimiento: sólo administrador crea y publica',async()=>{
  for(const page of ['galeria','centro-conocimiento'])for(const p of [m,u]){await p.goto(base+'/'+page+'/');await p.locator('h1').filter({hasText:page==='galeria'?'Galería':'Centro de Conocimiento'}).waitFor();assert.equal(await p.locator('[data-action="editor"]').count(),0);}
  for(const type of ['gallery','resource']){
   for(const p of [m,u])assert.equal((await api(p,'content/'+type,{title:'No autorizado',body:'Texto',status:'publish'})).status,403);
   const created=await ok(a,'content/'+type,{title:'Publicación administrativa de prueba',body:'Contenido publicado por administración.',status:'publish',meta:{chatham:false}});assert.equal(created.status,'publish');
  }
 });
 await test('Foros: asociado publica tema y respuesta sin aprobación',async()=>{
  forum=await ok(a,'content/forum',{title:'Foro de prueba '+data.token,body:'Preguntas de la comunidad.',status:'publish'});
  await u.goto(base+'/foros/');await u.getByRole('button',{name:'Nuevo tema',exact:true}).click();await u.locator('.modal [name="title"]').fill('Tema inmediato de prueba');await u.locator('.modal [name="body"]').fill('¿Cómo comparten los asociados sus experiencias?');await u.locator('.modal [name="parent"]').selectOption(String(forum.id));assert.equal(await u.locator('.modal [name="status"]').inputValue(),'publish');
  const saved=u.waitForResponse(r=>r.url().includes('/content/topic')&&r.request().method()==='POST');await u.getByRole('button',{name:'Guardar tema',exact:true}).click();const topic=await(await saved).json();assert.equal(topic.status,'publish');await u.goto(base+'/foros/?item='+topic.id);await u.locator('.modal [name="body"]').fill('Respuesta publicada sin espera.');await u.getByRole('button',{name:'Publicar comentario',exact:true}).click();await u.locator('#comments-list').getByText('Respuesta publicada sin espera.').waitFor();
 });
 await test('Panel administrador responsive y navegación por todas sus secciones',async()=>{
  await a.goto(base+'/wp-admin/admin.php?page=ascla');await a.locator('.admin-tabs').waitFor();for(const tab of ['moderacion','trabajos','microeventos','logs','solicitudes','usuarios','configuracion']){await a.locator('.admin-tabs [data-tab="'+tab+'"]').click();await a.locator('#admin-panel h2, #admin-panel .data-table, #admin-panel [data-form="settings"]').first().waitFor();}
  await a.locator('.admin-tabs [data-tab="usuarios"]').click();await a.locator('[aria-label="Buscar usuarios"]').fill(uu.login);await a.locator('[data-area="users"] button:not([type])').click();await a.waitForFunction(()=>document.querySelectorAll('[data-admin-user]').length===1);await a.setViewportSize({width:390,height:844});await a.evaluate(()=>scrollTo(0,0));await a.screenshot({path:'test-results/admin-mobile.png',fullPage:true});assert.equal(await a.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
 });
 assert.deepEqual(errors,[]);
}finally{if(browser)await browser.close();if(data)fixture({action:'cleanup',token:data.token,users:data.users.map(u=>u.id)});fs.writeFileSync('test-results/admin-gemini.json',JSON.stringify({date:new Date().toISOString(),version:'1.6.0',results,errors},null,2));}})().catch(e=>{console.error(e);process.exitCode=1;});
