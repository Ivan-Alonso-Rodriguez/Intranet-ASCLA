/* Layout and library behavior against a disposable local WordPress. */
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const out=path.resolve(process.env.ASCLA_LAYOUT_OUT||'test-results/layout-20261022');
const fixture=JSON.parse(fs.readFileSync(path.join(out,'private-access.json'),'utf8'));
if(new URL(fixture.base).hostname!=='ascla-layout.test')throw Error('Only the isolated layout host is accepted.');
const checks=[],errors=[],contexts=[];
const pass=name=>{checks.push(name);console.log('PASS '+name);};
(async()=>{
 const browser=await chromium.launch({headless:true});
 async function login(actor){
  const context=await browser.newContext({viewport:{width:1440,height:1000},timezoneId:'America/Lima'});contexts.push(context);
  const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
  await page.goto(fixture.base+'/login/');await page.locator('#user_login').fill(fixture.actors[actor].login);await page.locator('#user_pass').fill(fixture.actors[actor].password);
  await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);return page;
 }
 try{
  const member=await login('member');await member.goto(fixture.base+'/foros/');await member.locator('.feed-card[data-content-id="'+fixture.forum+'"]').waitFor();
  assert.equal(await member.locator('.feed-card[data-content-id="'+fixture.topic+'"]').count(),1);assert.equal(await member.locator('.feed-card').count(),2);
  assert.equal(await member.getByText('Explorar foros',{exact:true}).count(),0);assert.equal(await member.locator('.forum-directory,.forum-topics-heading').count(),0);
  await member.screenshot({path:path.join(out,'foros-lista-unica.png'),fullPage:true,animations:'disabled'});
  await member.locator('.feed-card[data-content-id="'+fixture.forum+'"] [data-action="item"]').last().click();await member.getByRole('dialog').waitFor();
  await member.getByRole('dialog').getByRole('heading',{name:fixture.prefix+' Foro de prueba',exact:true}).waitFor();await member.getByRole('button',{name:'Cerrar',exact:true}).first().click();
  pass('Foros y publicaciones comparten lista, sin Explorar foros, y se abre la conversación');
  const admin=await login('admin');await admin.goto(fixture.base+'/administracion/?section=archivos');await admin.locator('#admin-panel .file-library').waitFor();
  assert.equal(await admin.getByRole('button',{name:'Administrar archivos',exact:true}).count(),0);assert.equal(await admin.getByRole('dialog').count(),0);
  let form=admin.locator('[data-form="file-search"]');await form.locator('[name=q]').fill(fixture.prefix);await form.locator('[name=owner]').selectOption(String(fixture.actors.other.id));
  await Promise.all([admin.waitForResponse(r=>r.url().includes('/media?')),form.getByRole('button',{name:'Buscar',exact:true}).click()]);
  await admin.waitForFunction(()=>document.querySelectorAll('.file-row').length===20);
  await admin.locator('#admin-panel').getByRole('button',{name:'Siguiente',exact:true}).click();await admin.waitForFunction(()=>document.querySelectorAll('.file-row').length===1);
  const last=admin.locator('.file-row');const id=await last.getAttribute('data-file');
  const response=await admin.request.get(await last.getByRole('link',{name:'Abrir archivo',exact:true}).getAttribute('href'));assert.equal(response.status(),200);
  assert.match(await response.text(),/Archivo sintético de prueba/);
  await admin.screenshot({path:path.join(out,'archivos-en-pagina.png'),fullPage:true,animations:'disabled'});
  pass('Biblioteca administrativa visible directamente: búsqueda, propietario, paginación y apertura de archivos');
  await last.getByRole('button',{name:'Eliminar archivo',exact:true}).click();await admin.getByRole('dialog').getByRole('button',{name:'Cancelar',exact:true}).click();
  await admin.locator('.file-row[data-file="'+id+'"]').waitFor();assert.equal(await admin.getByRole('dialog').count(),0);
  await admin.locator('.file-row[data-file="'+id+'"] [data-action="delete-media"]').click();await admin.getByRole('dialog').getByRole('button',{name:'Eliminar',exact:true}).click();
  await admin.waitForFunction(()=>document.querySelectorAll('.file-row').length===20);
  assert.equal(await admin.locator('.file-row[data-file="'+id+'"]').count(),0);assert.equal(await admin.getByRole('dialog').count(),0);
  assert.equal(await admin.locator('[data-form="file-search"] [name=owner]').inputValue(),String(fixture.actors.other.id));
  assert.equal(await admin.locator('[data-form="file-search"] [name=q]').inputValue(),fixture.prefix);
  assert.equal(await admin.locator('#admin-panel').getByRole('button',{name:'Siguiente',exact:true}).isDisabled(),true);
  pass('Cancelar conserva el archivo; eliminar el último de una página conserva filtros y vuelve a una página válida');
  await admin.setViewportSize({width:390,height:844});assert.equal(await admin.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
  await admin.screenshot({path:path.join(out,'archivos-movil.png'),fullPage:true,animations:'disabled'});
  const executive=await login('executive');await executive.goto(fixture.base+'/administracion/?section=archivos');await executive.locator('#admin-panel .file-library').waitFor();
  assert.equal(await executive.getByRole('dialog').count(),0);assert.equal(await executive.getByRole('button',{name:'Administrar archivos',exact:true}).count(),0);
  pass('Biblioteca integrada también para Ejecutivo y sin desbordamiento en móvil');
  await member.goto(fixture.base+'/perfil/');await member.locator('[data-profile-tab="account"]').click();await member.locator('[data-action="files"][data-scope="mine"]').click();
  const own=member.getByRole('dialog');await own.locator('.file-library').waitFor();assert.equal(await own.locator('.file-row').count(),1);assert.equal(await own.locator('.file-row').getAttribute('data-file'),String(fixture.own_file));
  assert.equal(await own.locator('[name=owner]').count(),0);await own.getByRole('button',{name:'Cerrar',exact:true}).first().click();
  pass('Mis archivos del perfil mantiene su ventana y solo muestra archivos propios');
  await member.goto(fixture.base+'/directorio/?q='+fixture.prefix);await member.locator('.directory-view').waitFor();
  const card=member.locator('.member-card').filter({has:member.locator('[data-action="member"][data-id="'+fixture.actors.other.id+'"]')});
  for(const width of [1440,390]){
   await member.setViewportSize({width,height:1000});await card.locator('[data-action="conversation-request"]').waitFor();
   const boxes=[];for(const action of ['member','connect','conversation-request'])boxes.push(await card.locator('[data-action="'+action+'"]').boundingBox());
   const gaps=[boxes[1].y-boxes[0].y-boxes[0].height,boxes[2].y-boxes[1].y-boxes[1].height];
   assert.ok(Math.abs(gaps[0]-gaps[1])<1,JSON.stringify(gaps));assert.ok(Math.abs(gaps[0]-12)<1,JSON.stringify(gaps));
   await card.screenshot({path:path.join(out,'botones-'+width+'.png'),animations:'disabled'});
  }
  pass('Ver perfil, Conectar y Enviar mensaje mantienen 12 px de separación en escritorio y móvil');assert.deepEqual(errors,[]);
 }finally{
  for(const context of contexts)await context.close();await browser.close();
  fs.writeFileSync(path.join(out,'browser-result.json'),JSON.stringify({date:new Date().toISOString(),checks,errors},null,2)+'\n');
 }
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
