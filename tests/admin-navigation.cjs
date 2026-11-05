/* Role separation and navigation against an isolated local WordPress. */
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const fixture=JSON.parse(fs.readFileSync(process.env.ASCLA_ADMIN_UI_ACCESS||path.join(root,'test-results/admin-role-access.json'),'utf8').replace(/^\uFEFF/,''));
const destination=new URL(fixture.base);
if(!['localhost','127.0.0.1'].includes(destination.hostname))throw Error('This regression suite requires isolated local WordPress.');
const out=path.join(root,'test-results','admin-role-ui-'+new Date().toISOString().replace(/[:.]/g,'-'));
fs.mkdirSync(out,{recursive:true});const results=[],errors=[],contexts=[];
const redact=value=>Object.values(fixture.actors).reduce((text,actor)=>text.split(actor.password).join('[contraseña omitida]'),String(value));
async function api(page,route,body){return page.evaluate(async({route,body})=>{const response=await fetch(ASCLA.api+route,{method:body===undefined?'GET':'POST',headers:{'X-WP-Nonce':ASCLA.nonce,'Content-Type':'application/json'},body:body===undefined?undefined:JSON.stringify(body)});return {status:response.status,data:await response.json()};},{route,body});}
async function test(name,action){try{await action();results.push({name,status:'passed'});console.log('PASS '+name);}catch(error){results.push({name,status:'failed',error:redact(error.message)});throw error;}}
(async()=>{
 const browser=await chromium.launch({channel:'msedge',headless:true});let admin,member;
 async function login(name){const actor=fixture.actors[name],context=await browser.newContext({viewport:{width:1440,height:1000},recordVideo:{dir:path.join(out,'videos'),size:{width:1440,height:1000}}});contexts.push(context);const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));await page.goto(fixture.base+'/login/');await page.locator('#user_login').fill(actor.login);await page.locator('#user_pass').fill(actor.password);await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#wp-submit').click()]);await page.locator('#page-content h1').waitFor();return page;}
 try{
  admin=await login('Administrador');
  await test('Administrador inicia en su panel y tiene una única entrada de administración',async()=>{
   assert.equal(new URL(admin.url()).pathname,'/administracion/');await admin.getByRole('heading',{name:'Administración ASCLA',exact:true}).waitFor();
   assert.equal(await admin.locator('.ascla-sidebar .nav-link[href*="administracion"]').count(),1);
   assert.equal(new URL(await admin.locator('.ascla-sidebar .brand').getAttribute('href')).pathname,'/administracion/');
   await admin.goto(fixture.base+'/intranet/');assert.equal(new URL(admin.url()).pathname,'/administracion/');await admin.locator('.admin-tabs').waitFor();
  });
  await test('Escritorio: un logo visible y las secciones de administración encima del contenido',async()=>{
   await admin.locator('#admin-panel h2').waitFor();assert.equal(await admin.locator('#ascla-root img[alt="ASCLA"]:visible').count(),1);
   assert.equal(await admin.locator('.ascla-sidebar').count(),1);
   const nav=await admin.locator('.admin-tabs').boundingBox(),panel=await admin.locator('#admin-panel').boundingBox();
   assert.ok(nav.y+nav.height<=panel.y+1);assert.ok(Math.abs(nav.x-panel.x)<2);assert.ok(nav.width>=panel.width-2);
   assert.equal(await admin.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
   await admin.screenshot({path:path.join(out,'administracion-claro.png'),fullPage:true});
   await admin.evaluate(()=>localStorage.setItem('ascla-theme','dark'));await admin.reload();await admin.locator('#admin-panel h2').waitFor();
   assert.equal(await admin.locator('#ascla-root img[alt="ASCLA"]:visible').count(),1);await admin.screenshot({path:path.join(out,'administracion-oscuro.png'),fullPage:true});
  });
  await test('Las secciones administrativas mantienen su navegación',async()=>{
   for(const [tab,target] of [['usuarios','[data-form="admin-filter"][data-area="users"]'],['moderacion','.admin-review-grid'],['configuracion','[data-form="settings"]']]){
    await admin.locator('.admin-tabs [data-tab="'+tab+'"]').click();await admin.locator(target).waitFor();
   }
   await admin.locator('.admin-tabs [data-tab="resumen"]').click();await admin.locator('.admin-overview').waitFor();
  });
  await test('Móvil: menú disponible, sin dos logos ni desbordamiento horizontal',async()=>{
   await admin.setViewportSize({width:390,height:844});assert.equal(await admin.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
   await admin.locator('[data-action="menu"]').click();await admin.locator('.ascla-sidebar.open').waitFor();assert.equal(await admin.locator('#ascla-root img[alt="ASCLA"]:visible').count(),1);
   await admin.screenshot({path:path.join(out,'administracion-movil.png'),fullPage:true});await admin.keyboard.press('Escape');await admin.setViewportSize({width:1440,height:1000});
  });
  member=await login('Asociado');
  await test('El asociado conserva su inicio y el administrador queda fuera del directorio',async()=>{
   assert.equal(new URL(member.url()).pathname,'/intranet/');const result=await api(member,'profiles?q=Prueba');assert.equal(result.status,200);
   const ids=result.data.items.map(item=>Number(item.id));assert.ok(!ids.includes(fixture.actors.Administrador.id));assert.ok(ids.includes(fixture.actors.Moderador.id));assert.ok(!ids.includes(fixture.actors.Ejecutivo.id));
   assert.equal((await api(member,'profiles/'+fixture.actors.Administrador.id)).status,404);
   await member.goto(fixture.base+'/directorio/?q=Prueba');await member.locator('.directory-view').waitFor();
   assert.equal(await member.locator('.member-card [data-id="'+fixture.actors.Administrador.id+'"]').count(),0);
   await member.screenshot({path:path.join(out,'directorio-asociado.png'),fullPage:true});
  });
  await test('REST rechaza solicitudes hacia y desde la administración',async()=>{
   for(const [page,target,status] of [[member,fixture.actors.Administrador.id,400],[admin,fixture.actors.Asociado.id,403]]){
    assert.equal((await api(page,'relations',{target,kind:'connect',active:true})).status,status);
    assert.equal((await api(page,'conversation-requests',{target,body:'Presentación de prueba.'})).status,status);
   }
  });
  await test('Administrador consulta perfiles sin controles sociales ni preferencias de participación',async()=>{
   await admin.goto(fixture.base+'/directorio/');await admin.locator('.directory-view').waitFor();assert.equal(await admin.locator('.connection-controls [data-action]').count(),0);assert.equal(await admin.locator('#connections-panel').count(),0);
   await admin.goto(fixture.base+'/perfil/');await admin.locator('[data-form="profile"]').waitFor();
   await admin.locator('[data-profile-tab="privacy"]').click();await admin.getByText('La cuenta de administración gestiona la comunidad y no participa en el directorio, las conexiones ni los microeventos.',{exact:true}).waitFor();
   assert.equal(await admin.locator('input[name="networking"],input[name="directory"],input[name="microevents"]').count(),0);
   const result=await api(admin,'profiles/'+fixture.actors.Administrador.id);assert.equal(result.status,200);assert.equal(result.data.community_participant,false);assert.equal(result.data.directory,false);
  });
  assert.deepEqual(errors,[]);
 }catch(error){for(const [name,page] of [['admin',admin],['asociado',member]])if(page&&!page.isClosed())await page.screenshot({path:path.join(out,name+'-fallo.png'),fullPage:true});throw error;}
 finally{const videos=[];for(const context of contexts){const pages=context.pages();await context.close();for(const page of pages)if(page.video())videos.push(path.relative(out,await page.video().path()).replaceAll('\\','/'));}await browser.close();fs.writeFileSync(path.join(out,'summary.json'),JSON.stringify({target:fixture.base,date:new Date().toISOString(),results,videos,browserErrors:errors},null,2)+'\n');console.log('Evidencias: '+out);}
})().catch(error=>{console.error(redact(error.message));process.exitCode=1;});
