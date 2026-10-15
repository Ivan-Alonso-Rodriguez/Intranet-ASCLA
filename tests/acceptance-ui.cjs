/* Principal business actions use real controls. HTTP responses supply IDs, never actions. */
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');

module.exports=async function({fixture,out}) {
  const browser=await chromium.launch({headless:true});const results=[];
  async function visit(page,route) {
    await page.goto(fixture.base+'/'+route);
    await page.locator('#page-content h1').waitFor();
    await page.waitForFunction(()=>!document.querySelector('#page-content')?.hasAttribute('aria-busy'));
    if(!/[?&](?:member|item)=/.test(page.url()))await page.keyboard.press('Escape');
  }
  async function scenario(name,run) {
    const contexts=[],pages=[],errors=[];const start=Date.now();let result={name,status:'passed',videos:[],screenshots:[]};
    async function login(actor) {
      const context=await browser.newContext({viewport:{width:1280,height:900},timezoneId:'America/Lima',recordVideo:{dir:path.join(out,'videos',name)}});contexts.push(context);
      const page=await context.newPage();pages.push(page);page.on('pageerror',error=>errors.push(error.message));
      await page.goto(fixture.base+'/login/');await page.locator('#user_login').fill(fixture.actors[actor].login);await page.locator('#user_pass').fill(fixture.actors[actor].password);
      await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#wp-submit').click()]);
      await visit(page,'intranet/');return page;
    }
    try {await run(login);assert.deepEqual(errors,[]);} catch(error) {result.status='failed';result.error=error.message;console.error('FAIL '+name+': '+error.message);} finally {
      for(let i=0;i<pages.length;i++) {
        const page=pages[i];if(!page.isClosed()) {const file=name+'-'+i+'-'+result.status+'.png';await page.screenshot({path:path.join(out,file),fullPage:true});result.screenshots.push(file);}
      }
      for(const context of contexts)await context.close();
      for(const page of pages)if(page.video())result.videos.push(path.relative(out,await page.video().path()).replaceAll('\\','/'));
      result.ms=Date.now()-start;results.push(result);console.log(result.status.toUpperCase()+' UI '+name);
    }
  }
  try {
    await scenario('perfil',async login=>{
      const page=await login('ui_a');await visit(page,'perfil/');
      const company=await page.locator('[name=company]').inputValue();const bio='QA: biografía conservada '+fixture.prefix;
      await page.locator('[name=bio]').fill(bio);await page.getByRole('button',{name:'Guardar perfil',exact:true}).click();await page.getByRole('status').filter({hasText:'Perfil actualizado'}).waitFor();
      await page.reload();await page.locator('[name=bio]').waitFor();assert.equal(await page.locator('[name=bio]').inputValue(),bio);
      await page.locator('[name=company]').fill('');await page.getByRole('button',{name:'Guardar perfil',exact:true}).click();assert.equal(await page.locator('[name=company]').evaluate(input=>input.validity.valid),false);
      await page.locator('[name=company]').fill(company);await page.reload();await page.locator('[name=company]').waitFor();assert.equal(await page.locator('[name=company]').inputValue(),company);assert.equal(await page.locator('[name=bio]').inputValue(),bio);
    });
    await scenario('conexion-chat-historial',async login=>{
      const a=await login('ui_a'),b=await login('ui_b');const target=fixture.actors.ui_b.id;
      await visit(a,'directorio/?q='+encodeURIComponent(fixture.prefix));await a.locator('[data-action="connect"][data-id="'+target+'"]').click();
      const form=a.locator('[data-form="connection-request"]');await form.locator('[name=message]').fill('QA: presentación desde la interfaz.');await form.getByRole('button',{name:'Enviar solicitud',exact:true}).click();await form.waitFor({state:'detached'});
      await visit(b,'directorio/');await b.getByText('QA: presentación desde la interfaz.',{exact:true}).waitFor();await b.locator('.connections-panel [data-action="connection-respond"][data-decision="accept"]').click();
      await visit(a,'perfil/?member='+target);await a.locator('.modal [data-action="message-start"][data-id="'+target+'"]').click();await a.locator('.chat-compose textarea').waitFor();
      const text='QA: historial UI '+fixture.prefix;await a.locator('.chat-compose textarea').fill(text);await a.locator('.chat-compose button').click();await a.locator('.bubble').filter({hasText:text}).waitFor();
      const conversation=new URL(a.url()).searchParams.get('conversation');assert.ok(conversation);
      await visit(b,'mensajeria/?conversation='+conversation);await b.locator('.bubble').filter({hasText:text}).waitFor();assert.equal(await a.locator('[data-action="delete-message"]').count(),0);
      await visit(a,'perfil/?member='+target);await a.locator('.modal [data-action="connection-remove-request"]').click();await a.locator('[data-action="connection-remove-confirm"]').click();
      await visit(a,'mensajeria/?conversation='+conversation);await a.locator('.bubble').filter({hasText:text}).waitFor();assert.equal(await a.locator('.chat-compose textarea').isDisabled(),true);await a.getByText('Conexión eliminada: puedes consultar el historial, pero no enviar mensajes.',{exact:true}).waitFor();
      await visit(b,'mensajeria/?conversation='+conversation);await b.locator('.bubble').filter({hasText:text}).waitFor();assert.equal(await b.locator('.chat-compose textarea').isDisabled(),true);
    });
    await scenario('borrador-publicacion-inscripcion',async login=>{
      const admin=await login('admin'),member=await login('ui_a');const title=fixture.prefix+' Evento de interfaz';
      await visit(admin,'eventos/');await admin.locator('[data-action="editor"][data-type="event"]').click();const form=admin.locator('[data-form="editor"]');
      assert.equal(await form.locator('[name=status]:checked').inputValue(),'publish');
      await form.locator('[name=title]').fill(title);
      // Preserve the teammate's exit dialog and save a genuinely incomplete draft.
      await admin.keyboard.press('Escape');const decision=admin.locator('.modal-decision .modal-content');await decision.getByRole('button',{name:'Seguir editando',exact:true}).waitFor();await decision.getByRole('button',{name:'Salir sin guardar',exact:true}).waitFor();
      const saved=admin.waitForResponse(r=>r.url().includes('/content/event')&&r.request().method()==='POST');await decision.getByRole('button',{name:'Guardar como borrador',exact:true}).click();const draft=await (await saved).json();assert.equal(draft.status,'draft');assert.equal(draft.meta.start,undefined);assert.equal(draft.meta.end,undefined);
      await visit(admin,'eventos/?item='+draft.id);await admin.locator('.modal [data-action="editor"]').click();const edit=admin.locator('[data-form="editor"]');assert.equal(await edit.locator('[name=status]:checked').inputValue(),'draft');
      await edit.locator('[name=status][value=scheduled]').check();assert.equal(await edit.locator('[name=publish_at]').isVisible(),true);await edit.locator('[name=status][value=publish]').check();assert.equal(await edit.locator('[name=publish_at]').isVisible(),false);
      let writes=0;const observe=r=>{if(r.url().includes('/content/event/')&&r.method()==='POST')writes++;};admin.on('request',observe);await edit.getByRole('button',{name:'Guardar evento',exact:true}).click();assert.equal(writes,0);assert.equal(await edit.locator('[name=body]').evaluate(n=>n.validity.valueMissing),true);admin.off('request',observe);
      await edit.locator('[name=body]').fill('Encuentro creado con los controles reales de ASCLA.');
      const day=new Date(Date.now()+5*86400000).toISOString().slice(0,10);for(const name of ['start','end'])await edit.locator('[name='+name+'_date]').fill(day);await edit.locator('[name=start_time]').fill('10:15');await edit.locator('[name=end_time]').fill('11:45');await edit.locator('[name=capacity]').fill('5');await edit.locator('[name=location]').fill('Sala QA');await edit.locator('[name=chatham]').uncheck();
      const publish=admin.waitForResponse(r=>r.url().includes('/content/event/')&&r.request().method()==='POST');await edit.getByRole('button',{name:'Guardar evento',exact:true}).click();const published=await (await publish).json();assert.equal(published.status,'publish');assert.equal(Date.parse(published.meta.start),Date.parse(day+'T15:15:00Z'));assert.equal(Date.parse(published.meta.end),Date.parse(day+'T16:45:00Z'));
      await visit(admin,'eventos/?item='+draft.id);await admin.locator('.modal [data-action="editor"]').click();const reopened=admin.locator('[data-form="editor"]');assert.equal(await reopened.locator('[name=start_date]').inputValue(),day);assert.equal(await reopened.locator('[name=start_time]').inputValue(),'10:15');assert.equal(await reopened.locator('[name=end_time]').inputValue(),'11:45');await admin.keyboard.press('Escape');
      await visit(member,'eventos/?q='+encodeURIComponent(title));await member.locator('[data-action="item"][data-id="'+draft.id+'"]').click();await member.getByRole('button',{name:'Registrarme',exact:true}).click();await member.getByRole('button',{name:'Cancelar inscripción',exact:true}).waitFor();
      // A new page load confirms that the inscription survived the original action.
      await member.goto(fixture.base+'/eventos/?item='+draft.id);await member.getByRole('button',{name:'Cancelar inscripción',exact:true}).waitFor();
    });
  } finally {await browser.close();}
  fs.writeFileSync(path.join(out,'frontend.json'),JSON.stringify({date:new Date().toISOString(),results},null,2));
  return results;
};
