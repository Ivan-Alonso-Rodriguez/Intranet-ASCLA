/* Same ASCLA administration for Executive, except configuration and WordPress. */
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const out=path.resolve(process.env.ASCLA_EXECUTIVE_OUT||'test-results/executive-admin-20261022');
const fixture=JSON.parse(fs.readFileSync(path.join(out,'private-access.json'),'utf8'));
if(new URL(fixture.base).hostname!=='ascla-executive.test')throw Error('Only the isolated executive regression host is accepted.');
const checks=[],errors=[],contexts=[];
const pass=name=>{checks.push(name);console.log('PASS '+name);};
async function api(page,route,method='GET',body){return page.evaluate(async({route,method,body})=>{const response=await fetch(ASCLA.api+route,{method,headers:{'X-WP-Nonce':ASCLA.nonce,'Content-Type':'application/json'},body:body===undefined?undefined:JSON.stringify(body)});return {status:response.status,data:await response.json()};},{route,method,body});}
(async()=>{
 const browser=await chromium.launch({headless:true});
 async function login(actor){
  const context=await browser.newContext({viewport:{width:1440,height:1000},timezoneId:'America/Lima'});contexts.push(context);
  const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
  await page.goto(fixture.base+'/login/');await page.locator('#user_login').fill(fixture.actors[actor].login);await page.locator('#user_pass').fill(fixture.actors[actor].password);
  await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);await page.locator('#page-content h1').waitFor();return page;
 }
 try{
  const admin=await login('admin'),executive=await login('executive');await executive.locator('.admin-overview').waitFor();await admin.locator('.admin-overview').waitFor();
  assert.equal(new URL(executive.url()).pathname,'/administracion/');
  const tabs=page=>page.locator('.admin-tabs [data-tab]').evaluateAll(nodes=>nodes.map(n=>n.dataset.tab).sort());
  assert.deepEqual(await tabs(executive),(await tabs(admin)).filter(key=>key!=='configuracion'));
  assert.deepEqual(await executive.locator('.ascla-sidebar .nav-link').allTextContents(),await admin.locator('.ascla-sidebar .nav-link').allTextContents());
  assert.equal(await executive.locator('.admin-wordpress-link').count(),0);assert.equal(await admin.locator('.admin-wordpress-link').count(),1);
  await executive.screenshot({path:path.join(out,'ejecutivo-administracion.png'),fullPage:true,animations:'disabled'});
  pass('Ejecutivo inicia en Administración y comparte menú y secciones, excepto Configuración y WordPress');
  await executive.goto(fixture.base+'/administracion/?section=configuracion');await executive.locator('.admin-overview').waitFor();
  for(const [method,route,body] of [['GET','settings'],['POST','settings',{demo:false}],['POST','demo',{password:'NotUsedLocalFixture!9'}]])assert.equal((await api(executive,route,method,body)).status,403);
  await executive.goto(fixture.base+'/wp-admin/');assert.equal(new URL(executive.url()).pathname,'/administracion/');await executive.locator('.admin-overview').waitFor();
  pass('Configuración rechazada por REST y el acceso directo a WordPress regresa a Administración ASCLA');
  const sections=[['usuarios','[data-area="users"]'],['importar','[data-form="interest-import"]'],['archivos','.file-library'],['aliados','.ally-registry'],['logs','.data-table'],['moderacion','.admin-review-grid']];
  for(const [key] of sections){
   await executive.locator('.admin-tabs [data-tab="'+key+'"]').click();
   await executive.waitForFunction(()=>!document.querySelector('#admin-panel .view-loading'));
   assert.equal(await executive.locator('#admin-panel .error').count(),0,key);
  }
  await executive.locator('.admin-tabs [data-tab="usuarios"]').click();const filter=executive.locator('form[data-area="users"]');await filter.waitFor();
  await filter.locator('[name=q]').fill(fixture.prefix);
  await Promise.all([executive.waitForResponse(r=>r.url().includes('/admin/users?')),filter.getByRole('button',{name:'Buscar',exact:true}).click()]);
  pass('Ejecutivo accede a Usuarios, Importar intereses, Archivos, Aliados, Historial y Revisar contenido');
  await executive.locator('[data-action="user-edit"][data-id="'+fixture.actors.member.id+'"]').click();
  const edit=executive.locator('[data-form="admin-user-edit"]');await edit.waitFor();await edit.locator('[name=first_name]').fill('Editado por Ejecutivo');await edit.locator('[name=member_type]').selectOption('independent');
  const saved=await Promise.all([executive.waitForResponse(r=>r.url().includes('/admin/users/'+fixture.actors.member.id)&&r.request().method()==='POST'),edit.locator('button[type="submit"],button:not([type])').last().click()]);
  assert.equal(saved[0].status(),200);await edit.waitFor({state:'detached'});
  const member=await api(executive,'admin/users/'+fixture.actors.member.id);assert.equal(member.data.first_name,'Editado por Ejecutivo');
  pass('Ejecutivo edita y guarda un asociado desde el formulario administrativo');
  assert.equal(await executive.locator('[data-action="user-create"]').count(),1);
  const account=(await api(executive,'admin/users')).data.items.find(u=>u.id===fixture.actors.member.id);assert.equal(account.can_edit,true);assert.equal(account.can_delete,true);assert.equal(account.can_suspend,true);
  await executive.setViewportSize({width:390,height:844});assert.equal(await executive.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
  await executive.screenshot({path:path.join(out,'ejecutivo-usuarios-movil.png'),fullPage:true,animations:'disabled'});
  await executive.evaluate(()=>localStorage.setItem('ascla-theme','dark'));await executive.reload();await executive.locator('.admin-overview').waitFor();await executive.locator('#main').focus();
  assert.equal(await executive.locator('[data-tab="configuracion"],.admin-wordpress-link').count(),0);await executive.screenshot({path:path.join(out,'ejecutivo-oscuro-movil.png'),fullPage:true,animations:'disabled'});
  pass('Acciones de usuarios disponibles y panel adaptable a móvil y tema oscuro');
  assert.deepEqual(errors,[]);
 }finally{
  for(const context of contexts)await context.close();await browser.close();
  fs.writeFileSync(path.join(out,'browser-result.json'),JSON.stringify({date:new Date().toISOString(),checks,errors},null,2)+'\n');
 }
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
