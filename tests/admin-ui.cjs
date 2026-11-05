/* Administrative UI checks against a dedicated disposable WordPress. */
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const out=path.resolve(process.env.ASCLA_ADMIN_UI_OUT||'test-results/admin-ui-20261022');
const fixture=JSON.parse(fs.readFileSync(path.join(out,'private-access.json'),'utf8'));
if(new URL(fixture.base).hostname!=='ascla-adminui.test')throw Error('Only the isolated administrative UI host is accepted.');
const checks=[],errors=[];
const pass=name=>{checks.push(name);console.log('PASS '+name);};
(async()=>{
 const browser=await chromium.launch({headless:true});const contexts=[];
 async function login(actor){
  const context=await browser.newContext({viewport:{width:1440,height:1000},timezoneId:'America/Lima'});contexts.push(context);
  const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
  await page.goto(fixture.base+'/login/');await page.locator('#user_login').fill(fixture.actors[actor].login);await page.locator('#user_pass').fill(fixture.actors[actor].password);
  await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);await page.locator('#page-content h1').waitFor();return page;
 }
 try{
  const admin=await login('admin');await admin.locator('.admin-overview').waitFor();
  for(const route of ['directorio','mensajeria','contacto'])assert.equal(await admin.locator('.ascla-sidebar .nav-link[href*="/'+route+'/" ]').count(),0);
  assert.equal(await admin.locator('[data-tab="trabajos"]').count(),0);assert.equal(await admin.getByText('Tareas de IA',{exact:true}).count(),0);
  await admin.goto(fixture.base+'/administracion/?section=trabajos');await admin.locator('.admin-overview').waitFor();
  pass('Administrador sin Directorio, Mensajería, Contacto ni tareas de IA; enlace antiguo vuelve al resumen');
  await admin.locator('.admin-tabs [data-tab="usuarios"]').click();
  let form=admin.locator('[data-form="admin-filter"][data-area="users"]');await form.waitFor();
  await form.locator('[name=q]').fill(fixture.prefix);await form.locator('[name=country]').fill('peru');
  for(const [key,ids] of [['industries',fixture.terms.industry],['interests',fixture.terms.interest],['areas',[fixture.terms.area[0]]]]){
   const facet=form.locator('.directory-facet').filter({has:admin.locator('input[name="'+key+'"]')});
   await facet.locator('summary').click();for(const id of ids)await facet.locator('input[value="'+id+'"]').check();
   assert.equal(await facet.locator('[data-facet-count]').textContent(),String(ids.length));await facet.locator('summary').click();
  }
  await form.locator('[name=role]').selectOption('ascla_member');
  const response=await Promise.all([admin.waitForResponse(r=>r.url().includes('/admin/users')),form.getByRole('button',{name:'Buscar',exact:true}).click()]);
  const data=await response[0].json();assert.equal(data.total,1,'Combined filters: '+response[0].url());assert.equal(data.items[0].id,fixture.actors.member.id);
  await admin.locator('.admin-user').waitFor();assert.equal(await admin.locator('.admin-user').count(),1);
  form=admin.locator('[data-form="admin-filter"][data-area="users"]');assert.equal(await form.locator('input[name=interests]:checked').count(),2);
  await admin.screenshot({path:path.join(out,'usuarios-filtros.png'),fullPage:true,animations:"disabled"});
  await admin.setViewportSize({width:390,height:844});assert.equal(await admin.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
  await admin.screenshot({path:path.join(out,'usuarios-filtros-movil.png'),fullPage:true,animations:"disabled"});await admin.setViewportSize({width:1440,height:1000});
  pass('Usuarios: país, facetas múltiples y rol se combinan, persisten y caben en móvil');
  await Promise.all([admin.waitForResponse(r=>r.url().includes('/admin/users')),form.getByRole('button',{name:'Limpiar filtros',exact:true}).click()]);
  await admin.waitForFunction(()=>document.querySelector('[data-area="users"] [name="q"]').value==='');
  assert.equal(await admin.locator('[data-area="users"] input:checked').count(),0);pass('Limpiar filtros restaura la lista y todas las selecciones');
  await admin.locator('.admin-tabs [data-tab="aliados"]').click();
  const row=admin.locator('[data-ally-record="'+fixture.ally+'"]');await row.waitFor();
  assert.equal(await row.locator('[data-allies-action="status"]:visible').count(),0);
  await row.locator('summary').focus();await admin.keyboard.press('Enter');await row.getByRole('button',{name:'Desactivar',exact:true}).waitFor();
  await admin.screenshot({path:path.join(out,'aliados-menu.png'),fullPage:true,animations:"disabled"});
  await row.getByRole('button',{name:'Desactivar',exact:true}).click();await admin.locator('.modal-backdrop').getByRole('button',{name:'Cancelar',exact:true}).click();
  assert.equal(await row.locator('.ally-state').textContent(),'Activo');
  await row.locator('summary').click();await row.getByRole('button',{name:'Desactivar',exact:true}).click();
  await admin.locator('.modal-backdrop').getByRole('button',{name:'Retirar del catálogo',exact:true}).click();await row.locator('.ally-state-inactive').waitFor();
  await row.locator('summary').click();await row.getByRole('button',{name:'Activar',exact:true}).click();await admin.locator('.modal-backdrop').getByRole('button',{name:'Activar aliado',exact:true}).click();await row.locator('.ally-state-active').waitFor();
  pass('Menú de tres puntos accesible: cancelar, desactivar y reactivar el mismo aliado');
  await row.locator('summary').click();await row.getByRole('button',{name:'Eliminar',exact:true}).click();await admin.locator('.modal-backdrop').getByRole('button',{name:'Cancelar',exact:true}).click();assert.equal(await row.count(),1);
  await row.locator('summary').click();await row.getByRole('button',{name:'Eliminar',exact:true}).click();await admin.locator('.modal-backdrop').getByRole('button',{name:'Eliminar aliado',exact:true}).click();await row.waitFor({state:'detached'});
  pass('Eliminar requiere confirmación y retira la ficha del registro');
  const community=await login('member');
  for(const route of ['directorio','mensajeria','contacto'])assert.equal(await community.locator('.ascla-sidebar .nav-link[href*="/'+route+'/" ]').count(),1);
  await community.goto(fixture.base+'/directorio/');const directory=community.locator('.directory-search');await directory.waitFor();
  await directory.locator('[name=q]').fill(fixture.prefix);await directory.locator('[name=country]').fill('');
  for(const [key,ids] of [['industries',fixture.terms.industry],['interests',fixture.terms.interest]]){
   const facet=directory.locator('.directory-facet').filter({has:community.locator('input[name="'+key+'"]')});await facet.locator('summary').click();
   for(const id of ids)await facet.locator('input[value="'+id+'"]').check();await facet.locator('summary').click();
  }
  for(const plain of [false,true]){
   if(plain)await community.evaluate(()=>{ASCLA.api=location.origin+'/?rest_route=/ascla/v1/';});
   const [response]=await Promise.all([community.waitForResponse(r=>r.url().includes(plain?'rest_route=':'/profiles?')&&r.request().method()==='GET'),community.locator('.directory-search').getByRole('button',{name:'Buscar',exact:true}).click()]);
   const data=await response.json();assert.equal(data.total,2);assert.deepEqual(data.items.map(p=>p.id).sort(),[fixture.actors.member.id,fixture.actors.other.id].sort());
   const query=new URL(response.url()).searchParams;assert.equal(query.getAll('industries[]').length,2);assert.equal(query.getAll('interests[]').length,2);
   await community.locator('.directory-search input[name=interests]:checked').nth(1).waitFor({state:'attached'});
   pass('Directorio conserva múltiples valores con '+(plain?'rest_route':'enlaces permanentes'));
  }
  await community.goto(fixture.base+'/perfil/');await community.locator('[data-form="profile"]').waitFor();
  const summary=community.locator('.profile-summary'),type=summary.locator('.profile-member-type');await type.waitFor();
  assert.equal(await type.locator('.profile-type-value').textContent(),'Pendiente de asignación');assert.equal(await community.locator('.form-grid .profile-member-type').count(),0);
  const box=await type.boundingBox(),person=await summary.locator('.profile-summary-person').boundingBox();assert.ok(box.x>=person.x+person.width-1);
  await community.screenshot({path:path.join(out,'perfil-tipo-cabecera.png'),fullPage:true,animations:"disabled"});
  await community.setViewportSize({width:390,height:844});assert.equal(await community.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);await community.screenshot({path:path.join(out,'perfil-tipo-movil.png'),fullPage:true,animations:"disabled"});
  pass('Tipo de asociado en la cabecera derecha del perfil, adaptable a móvil; Asociado conserva su navegación');
  const publisher=await login('executive');await publisher.goto(fixture.base+'/galeria/');
  const actions=publisher.locator('.page-heading-actions');await actions.waitFor();const buttons=actions.locator('a,button');assert.equal(await buttons.count(),2);
  const left=await buttons.nth(0).boundingBox(),right=await buttons.nth(1).boundingBox();assert.ok(right.x-left.x-left.width<=11);
  pass('Registro e historial permanece junto a Nueva galería');assert.deepEqual(errors,[]);
 }finally{
  for(const context of contexts)await context.close();await browser.close();
  fs.writeFileSync(path.join(out,'browser-result.json'),JSON.stringify({date:new Date().toISOString(),target:fixture.base,checks,errors},null,2)+'\n');
 }
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
