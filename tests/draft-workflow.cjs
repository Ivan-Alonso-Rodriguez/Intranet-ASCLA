/* Real browser regression for #573–#576 against the dedicated disposable WordPress. */
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const out=path.resolve(process.env.ASCLA_DRAFT_OUT||'test-results/ui573-20261022');
const fixture=JSON.parse(fs.readFileSync(path.join(out,'private-access.json'),'utf8'));
if(new URL(fixture.base).hostname!=='ascla-ui573.test')throw Error('Only the isolated draft regression host is accepted.');
const checks=[],errors=[],drafts=[],run=Date.now().toString(36);
const pass=message=>{checks.push(message);console.log('PASS '+message);};
(async()=>{
 const browser=await chromium.launch({headless:true});
 async function login(actor){
  const context=await browser.newContext({viewport:{width:1280,height:900},timezoneId:'America/Lima'});
  const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
  await page.goto(fixture.base+'/login/');await page.locator('#user_login').fill(fixture.actors[actor].login);await page.locator('#user_pass').fill(fixture.actors[actor].password);
  await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);return page;
 }
 async function visit(page,route){
  await page.goto(fixture.base+'/'+route+'/');await page.locator('#page-content h1').waitFor();
  await page.waitForFunction(()=>!document.querySelector('#page-content')?.hasAttribute('aria-busy'));
 }
 try{
  const executive=await login('executive'),admin=await login('admin');
  for(const [type,route] of [['hub','hub'],['forum','foros'],['gallery','galeria'],['resource','centro-conocimiento'],['event','eventos'],['ally','aliados']]){
   const page=type==='ally'?admin:executive;await visit(page,route);
   await page.locator('[data-action="editor"][data-type="'+type+'"]').first().click();
   const form=page.locator('[data-form="editor"]');await form.waitFor();
   const title=fixture.prefix+' '+type+' sin descripción '+run;await form.locator('[name=title]').fill(title);
   if(type==='forum'){
    await form.getByRole('button',{name:'Cancelar',exact:true}).click();
    const decision=page.getByRole('alertdialog',{name:'Cambios sin guardar'});
    await decision.getByRole('button',{name:'Guardar como borrador',exact:true}).waitFor();
    const saved=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes('/content/'+type));
    await decision.getByRole('button',{name:'Guardar como borrador',exact:true}).click();
    const response=await saved;assert.equal(response.status(),200);drafts.push({type,id:(await response.json()).id,title,route});
   }else{
    const saved=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes('/content/'+type));
    await form.locator('[data-editor-draft]').click();const response=await saved;
    assert.equal(response.status(),200);const post=await response.json();assert.equal(post.status,'draft');drafts.push({type,id:post.id,title,route});
   }
   await form.waitFor({state:'detached'});
   const draft=drafts.at(-1),card=page.locator('[data-draft-id="'+draft.id+'"]');
   await card.waitFor();assert.ok((await page.locator('[data-action="filter-drafts"]').getAttribute('class')).includes('active'));
   if(type==='event')assert.equal(await page.locator('[data-action="filter-past"].active').count(),0);
   await card.getByRole('button',{name:'Continuar borrador',exact:true}).click();
   await form.waitFor();assert.equal(await form.locator('[name=title]').inputValue(),title);
   assert.equal(await form.locator('[name=body]').evaluate(input=>input.required),false);
   if(type==='event')await form.locator('[name=status][value=publish]').check();else await form.locator('[name=status]').selectOption('publish');
   assert.equal(await form.locator('[name=body]').evaluate(input=>input.required),true);
   let writes=0;const observe=request=>{if(request.method()==='POST'&&request.url().includes('/content/'+type))writes++;};
   page.on('request',observe);await form.locator('[data-editor-primary]').click();assert.equal(writes,0);page.off('request',observe);
   await form.getByRole('button',{name:'Cancelar',exact:true}).click();
   await page.getByRole('alertdialog').getByRole('button',{name:'Salir sin guardar',exact:true}).click();
   await page.locator('[data-action="filter-all"]').click();await page.waitForFunction(()=>!document.querySelector('#page-content')?.hasAttribute('aria-busy'));
   assert.equal(await page.getByText(title,{exact:true}).count(),0);
   pass(type+': borrador incompleto guardado, recuperable y fuera de Comunidad; publicar exige contenido');
  }
  await visit(executive,'eventos');await executive.locator('[data-action="filter-mine"]').click();
  await executive.getByRole('heading',{name:drafts.find(draft=>draft.type==='event').title,exact:true}).waitFor();
  pass('Eventos: Mis publicaciones incluye el borrador sin fecha');
  await visit(executive,'foros');const forum=executive.locator('.feed-card[data-content-id="'+fixture.forum+'"]');
  await forum.waitFor();await forum.getByText('Un espacio para compartir experiencias de gobierno corporativo.',{exact:true}).waitFor();
  assert.equal(await executive.getByText('Explorar foros',{exact:true}).count(),0);
  await executive.screenshot({path:path.join(out,'foros-desktop.png'),fullPage:true});
  await executive.setViewportSize({width:390,height:844});
  await visit(executive,'foros');await executive.keyboard.press('Escape');
  assert.equal(await executive.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  await executive.screenshot({path:path.join(out,'foros-mobile.png'),fullPage:true});
  pass('Foros: lista única con descripción y sin desbordamiento en móvil');
  await visit(executive,'galeria');await executive.locator('.gallery-card-cover[data-id="'+fixture.gallery+'"]').focus();await executive.keyboard.press('Enter');
  await executive.getByRole('dialog').getByRole('heading',{name:fixture.prefix+' Galería visible',exact:true}).waitFor();
  pass('Galería: la portada abre la galería con teclado');
  await executive.getByRole('button',{name:'Cerrar',exact:true}).first().click();
  await executive.locator('.gallery-card-cover[data-id="'+fixture.gallery+'"]').click();
  await executive.getByRole('dialog').getByRole('heading',{name:fixture.prefix+' Galería visible',exact:true}).waitFor();
  pass('Galería: la portada abre la misma galería con clic');
  await executive.screenshot({path:path.join(out,'galeria-mobile.png'),fullPage:true});
  assert.deepEqual(errors,[]);
 }catch(error){errors.push(error.message);console.error(error.stack);process.exitCode=1;}
 finally{await browser.close();fs.writeFileSync(path.join(out,'browser-result.json'),JSON.stringify({date:new Date().toISOString(),checks,errors,drafts},null,2)+'\n');}
})().catch(error=>{console.error(error);process.exitCode=1;});
