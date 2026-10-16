/* Principal business actions use real controls. HTTP responses supply IDs, never actions. */
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');

module.exports=async function({fixture,out}) {
  const browser=await chromium.launch({headless:fixture.headless!==false,...(fixture.browser_channel?{channel:fixture.browser_channel}:{})});const results=[];
  const redact=value=>Object.values(fixture.actors).reduce((text,actor)=>actor.password?text.split(actor.password).join('[contraseña omitida]'):text,String(value));
  async function visit(page,route) {
    await page.goto(fixture.base+'/'+route);
    await page.locator('#page-content h1').waitFor();
    await page.waitForFunction(()=>!document.querySelector('#page-content')?.hasAttribute('aria-busy'));
    if(!/[?&](?:member|item)=/.test(page.url()))await page.keyboard.press('Escape');
  }
  async function scenario(name,run) {
    const contexts=[],pages=[],errors=[];const start=Date.now();let result={name,status:'passed',videos:[],screenshots:[]};
    async function login(actor) {
      const context=await browser.newContext({viewport:{width:1280,height:900},timezoneId:'America/Lima',recordVideo:{dir:path.join(out,'videos',name),size:{width:1280,height:900}}});contexts.push(context);
      const page=await context.newPage();pages.push(page);page.on('pageerror',error=>errors.push(error.message));
      await page.goto(fixture.base+'/login/');await page.locator('#user_login').fill(fixture.actors[actor].login);await page.locator('#user_pass').fill(fixture.actors[actor].password);
      await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#wp-submit').click()]);
      if(fixture.sharedAccounts){
        await page.locator('#page-content h1').waitFor();
        assert.equal(new URL(page.url()).pathname,actor==='admin'?'/administracion/':'/intranet/','El login debe abrir el inicio del rol.');
        assert.equal(await page.locator('#ascla-root img[alt="ASCLA"]:visible').count(),1,'Debe verse un único logo.');
        assert.equal(await page.locator('.ascla-sidebar').count(),1);
        if(actor==='admin'){
          await page.getByRole('heading',{name:'Administración ASCLA',exact:true}).waitFor();
          await page.locator('#admin-panel h2').waitFor();
          const nav=await page.locator('.admin-tabs').boundingBox(),panel=await page.locator('#admin-panel').boundingBox();
          assert.ok(nav.y+nav.height<=panel.y+1,'Las secciones van encima del panel.');
          assert.equal(await page.locator('.ascla-sidebar .nav-link[href*="administracion"]').count(),1);
        }
      }
      await visit(page,'intranet/');
      if(fixture.sharedAccounts){
        const nonce=await page.evaluate(()=>window.ASCLA.nonce);
        const response=await page.request.get(fixture.base+'/wp-json/ascla/v1/bootstrap',{headers:{'X-WP-Nonce':nonce}});
        assert.equal(response.status(),200);assert.equal(Number((await response.json()).me.id),Number(fixture.actors[actor].id));
      }
      return page;
    }
    try {await run(login);assert.deepEqual(errors,[]);} catch(error) {result.status='failed';result.error=redact(error.message);console.error('FAIL '+name+': '+result.error);} finally {
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
      const page=await login(fixture.actors.profile?'profile':'ui_a');await visit(page,'perfil/');
      const company=await page.locator('[name=company]').inputValue();const bio='QA: biografía conservada '+fixture.prefix;
      await page.locator('[name=bio]').fill(bio);await page.getByRole('button',{name:'Guardar perfil',exact:true}).click();await page.getByRole('status').filter({hasText:'Perfil actualizado'}).waitFor();
      await page.reload();await page.locator('[name=bio]').waitFor();assert.equal(await page.locator('[name=bio]').inputValue(),bio);
      await page.locator('[name=company]').fill('');await page.getByRole('button',{name:'Guardar perfil',exact:true}).click();assert.equal(await page.locator('[name=company]').evaluate(input=>input.validity.valid),false);
      await page.locator('[name=company]').fill(company);await page.reload();await page.locator('[name=company]').waitFor();assert.equal(await page.locator('[name=company]').inputValue(),company);assert.equal(await page.locator('[name=bio]').inputValue(),bio);
    });
    await scenario(fixture.sharedAccounts?'conexion-chat-bloqueo':'conexion-chat-historial',async login=>{
      const a=await login('ui_a'),b=await login('ui_b');const target=fixture.actors.ui_b.id;
      await visit(a,'directorio/?q='+encodeURIComponent(fixture.directoryQuery||fixture.prefix));await a.locator('[data-action="connect"][data-id="'+target+'"]').click();
      const form=a.locator('[data-form="connection-request"]');await form.locator('[name=message]').fill('QA: presentación desde la interfaz.');await form.getByRole('button',{name:'Enviar solicitud',exact:true}).click();await form.waitFor({state:'detached'});
      await visit(b,'directorio/');
      const incoming=b.locator('[data-connection-row="'+fixture.actors.ui_a.id+'"]');
      await incoming.locator('.connection-introduction > summary').click();
      await incoming.getByText('QA: presentación desde la interfaz.',{exact:true}).waitFor();
      await incoming.locator('[data-action="connection-respond"][data-decision="accept"]').click();
      await visit(a,'perfil/?member='+target);await a.locator('.modal [data-action="message-start"][data-id="'+target+'"]').click();await a.locator('.chat-compose textarea').waitFor();
      const text='QA: historial UI '+fixture.prefix;await a.locator('.chat-compose textarea').fill(text);await a.locator('.chat-compose button').click();await a.locator('.bubble').filter({hasText:text}).waitFor();
      const conversation=new URL(a.url()).searchParams.get('conversation');assert.ok(conversation);
      await visit(b,'mensajeria/?conversation='+conversation);await b.locator('.bubble').filter({hasText:text}).waitFor();assert.equal(await a.locator('[data-action="delete-message"]').count(),0);
      if(fixture.sharedAccounts){
        await visit(b,'perfil/?member='+fixture.actors.ui_a.id);
        await b.locator('.modal [data-action="block"][data-active="true"]').click();
        await b.getByRole('button',{name:'Confirmar bloqueo',exact:true}).click();
        await b.getByRole('status').filter({hasText:'Asociado bloqueado.'}).waitFor();
        await visit(a,'perfil/?member='+target);
        await a.getByText('La mensajería está bloqueada entre estas cuentas.',{exact:true}).waitFor();
        assert.equal(await a.locator('.modal [data-action="message-start"]').count(),0);
        await visit(b,'perfil/?member='+fixture.actors.ui_a.id);
        await b.locator('.modal [data-action="block"][data-active="false"]').click();
        await b.getByRole('status').filter({hasText:'Asociado desbloqueado.'}).waitFor();
        await visit(a,'perfil/?member='+target);
        await a.locator('.modal [data-action="connect"][data-id="'+target+'"]').waitFor();
        assert.equal(await a.locator('.modal [data-action="message-start"]').count(),0);
        return;
      }
      await visit(a,'perfil/?member='+target);await a.locator('.modal [data-action="connection-remove-request"]').click();await a.locator('[data-action="connection-remove-confirm"]').click();
      await visit(a,'mensajeria/?conversation='+conversation);await a.locator('.bubble').filter({hasText:text}).waitFor();assert.equal(await a.locator('.chat-compose textarea').isDisabled(),true);await a.getByText('Conexión eliminada: puedes consultar el historial, pero no enviar mensajes.',{exact:true}).waitFor();
      await visit(b,'mensajeria/?conversation='+conversation);await b.locator('.bubble').filter({hasText:text}).waitFor();assert.equal(await b.locator('.chat-compose textarea').isDisabled(),true);
    });
    await scenario('borrador-publicacion-inscripcion',async login=>{
      const admin=await login('admin'),member=await login('ui_a');const title=fixture.prefix+' Evento de interfaz';
      if(fixture.sharedAccounts){
        assert.equal(new URL(admin.url()).pathname,'/administracion/','Entrar a intranet también debe redirigir al administrador.');
        await visit(member,'directorio/?q=Administrador');
        assert.equal(await member.locator('.member-card [data-id="'+fixture.actors.admin.id+'"]').count(),0);
        await visit(admin,'directorio/');
        assert.equal(await admin.locator('#connections-panel').count(),0);
        assert.equal(await admin.locator('.connection-controls [data-action]').count(),0);
        await visit(admin,'perfil/?member='+fixture.actors.ui_a.id);
        assert.equal(await admin.locator('.modal [data-action="connect"],.modal [data-action="message-start"]').count(),0);
      }
      await visit(admin,'eventos/');await admin.locator('[data-action="editor"][data-type="event"]').click();const form=admin.locator('[data-form="editor"]');
      assert.equal(await form.locator('[name=status]:checked').inputValue(),'publish');
      await form.locator('[name=title]').fill(title);
      // Preserve the teammate's exit dialog and save a genuinely incomplete draft.
      await admin.keyboard.press('Escape');const decision=admin.locator('.modal-decision .modal-content');await decision.getByRole('button',{name:'Seguir editando',exact:true}).waitFor();await decision.getByRole('button',{name:'Salir sin guardar',exact:true}).waitFor();
      const [saved]=await Promise.all([admin.waitForResponse(r=>r.url().includes('/content/event')&&r.request().method()==='POST'),decision.getByRole('button',{name:'Guardar como borrador',exact:true}).click()]);const draft=await saved.json();assert.equal(draft.status,'draft');assert.equal(draft.meta.start,undefined);assert.equal(draft.meta.end,undefined);
      if(fixture.onEventCreated)fixture.onEventCreated({id:Number(draft.id),title});
      await visit(admin,'eventos/?item='+draft.id);await admin.locator('.modal [data-action="editor"]').click();const edit=admin.locator('[data-form="editor"]');assert.equal(await edit.locator('[name=status]:checked').inputValue(),'draft');
      await edit.locator('[name=status][value=scheduled]').check();assert.equal(await edit.locator('[name=publish_at]').isVisible(),true);await edit.locator('[name=status][value=publish]').check();assert.equal(await edit.locator('[name=publish_at]').isVisible(),false);
      let writes=0;const observe=r=>{if(r.url().includes('/content/event/')&&r.method()==='POST')writes++;};admin.on('request',observe);await edit.getByRole('button',{name:'Guardar evento',exact:true}).click();assert.equal(writes,0);assert.equal(await edit.locator('[name=body]').evaluate(n=>n.validity.valueMissing),true);admin.off('request',observe);
      await edit.locator('[name=body]').fill('Encuentro creado con los controles reales de ASCLA.');
      const day=new Date(Date.now()+5*86400000).toISOString().slice(0,10);for(const name of ['start','end'])await edit.locator('[name='+name+'_date]').fill(day);await edit.locator('[name=start_time]').fill('10:15');await edit.locator('[name=end_time]').fill('11:45');await edit.locator('[name=capacity]').fill('5');await edit.locator('[name=location]').fill('Sala QA');await edit.locator('[name=chatham]').uncheck();
      const [publish]=await Promise.all([admin.waitForResponse(r=>r.url().includes('/content/event/')&&r.request().method()==='POST'),edit.getByRole('button',{name:'Guardar evento',exact:true}).click()]);const published=await publish.json();assert.equal(published.status,'publish');assert.equal(Date.parse(published.meta.start),Date.parse(day+'T15:15:00Z'));assert.equal(Date.parse(published.meta.end),Date.parse(day+'T16:45:00Z'));
      await visit(admin,'eventos/?item='+draft.id);await admin.locator('.modal [data-action="editor"]').click();const reopened=admin.locator('[data-form="editor"]');assert.equal(await reopened.locator('[name=start_date]').inputValue(),day);assert.equal(await reopened.locator('[name=start_time]').inputValue(),'10:15');assert.equal(await reopened.locator('[name=end_time]').inputValue(),'11:45');await admin.keyboard.press('Escape');
      await visit(member,'eventos/?q='+encodeURIComponent(title));await member.locator('[data-action="item"][data-id="'+draft.id+'"]').click();await member.getByRole('button',{name:'Registrarme',exact:true}).click();await member.getByRole('button',{name:'Cancelar inscripción',exact:true}).waitFor();
      // A new page load confirms that the inscription survived the original action.
      await member.goto(fixture.base+'/eventos/?item='+draft.id);await member.getByRole('button',{name:'Cancelar inscripción',exact:true}).waitFor();
      if(fixture.sharedAccounts){
        await visit(admin,'eventos/?item='+draft.id);
        await admin.locator('.modal [data-action="event-cancel-request"]').click();
        await admin.locator('[name=cancellation_reason]').fill('Cierre del evento dedicado a las pruebas de interfaz.');
        const [cancelled]=await Promise.all([admin.waitForResponse(r=>r.url().endsWith('/events/'+draft.id+'/cancel')&&r.request().method()==='POST'),admin.locator('[data-action="event-cancel-confirm"]').click()]);
        assert.equal(cancelled.status(),200);assert.equal((await cancelled.json()).cancelled,true);
      }
    });
    if(fixture.sharedAccounts){
      await scenario('aforo-lista-espera-notificacion',async login=>{
        const admin=await login('admin'),ana=await login('ui_a'),bruno=await login('ui_b');
        const title=fixture.prefix+' Evento de aforo';
        await visit(admin,'eventos/');await admin.locator('[data-action="editor"][data-type="event"]').click();
        const form=admin.locator('[data-form="editor"]');
        await form.locator('[name=title]').fill(title);await form.locator('[name=body]').fill('Prueba de cupo único y oferta a la lista de espera desde la interfaz.');
        const day=new Date(Date.now()+6*86400000).toISOString().slice(0,10);
        for(const field of ['start','end'])await form.locator('[name='+field+'_date]').fill(day);
        await form.locator('[name=start_time]').fill('10:00');await form.locator('[name=end_time]').fill('11:00');
        await form.locator('[name=capacity]').fill('1');await form.locator('[name=location]').fill('Sala QA de aforo');await form.locator('[name=chatham]').uncheck();
        const invalid=await form.locator(':invalid').evaluateAll(fields=>fields.map(field=>({name:field.name,message:field.validationMessage})));
        assert.deepEqual(invalid,[],'El evento debe tener todos sus campos válidos antes de publicar.');
        const [createdResponse]=await Promise.all([admin.waitForResponse(r=>r.url().includes('/content/event')&&r.request().method()==='POST'),form.getByRole('button',{name:'Publicar evento',exact:true}).click()]);assert.equal(createdResponse.status(),200);
        const event=await createdResponse.json();fixture.onEventCreated?.({id:Number(event.id),title});
        assert.equal(event.status,'publish');assert.equal(Number(event.meta.capacity),1);
        async function register(page,status){
          const [response]=await Promise.all([page.waitForResponse(r=>r.url().endsWith('/events/'+event.id+'/register')&&r.request().method()==='POST'),page.locator('.modal [data-action="register"][data-id="'+event.id+'"][data-status="'+status+'"]').click()]);
          assert.equal(response.status(),200);const data=await response.json();assert.equal(data.registered,status);return data;
        }
        await visit(ana,'eventos/?item='+event.id);const accepted=await register(ana,'accepted');assert.equal(Number(accepted.attending),1);assert.equal(accepted.full,true);
        await visit(bruno,'eventos/?item='+event.id);
        await bruno.getByRole('button',{name:'Unirme a la lista de espera',exact:true}).waitFor();
        assert.equal(await bruno.locator('.modal [data-action="register"][data-status="accepted"]').count(),0);
        const queued=await register(bruno,'waitlisted');assert.equal(Number(queued.waitlist_position),1);
        await bruno.getByText('Estás en la lista de espera',{exact:true}).waitFor();
        await register(ana,'cancelled');
        await visit(bruno,'eventos/?item='+event.id);await bruno.getByText('Se liberó un cupo para ti',{exact:true}).waitFor();
        await bruno.getByRole('button',{name:'Confirmar asistencia',exact:true}).waitFor();
        await visit(bruno,'intranet/');await bruno.locator('.ascla-header [data-action="notifications"]').click();
        const notice=bruno.locator('.notification-modal .activity-card').filter({has:bruno.locator('a[href*="item='+event.id+'"]')}).filter({hasText:'Confirmar cupo'});
        await notice.waitFor();assert.equal(await notice.evaluate(node=>node.classList.contains('is-unread')),true);
        assert.ok(Number.isFinite(Date.parse(await notice.locator('time').getAttribute('datetime'))));
        const noticeId=await notice.getAttribute('data-notification');await notice.locator('[data-action="notification-open"]').click();
        await bruno.locator('.modal:not(.notification-modal)').getByText('Se liberó un cupo para ti',{exact:true}).waitFor();assert.equal(new URL(bruno.url()).searchParams.get('item'),String(event.id));
        await visit(bruno,'intranet/');await bruno.locator('.ascla-header [data-action="notifications"]').click();
        const readNotice=bruno.locator('.notification-modal [data-notification="'+noticeId+'"]');
        await readNotice.waitFor();assert.equal(await readNotice.evaluate(node=>node.classList.contains('is-read')),true);
        await readNotice.locator('[data-action="notification-open"]').click();await bruno.getByRole('button',{name:'Confirmar asistencia',exact:true}).waitFor();
        const filled=await register(bruno,'accepted');assert.equal(Number(filled.attending),1);
        await bruno.getByRole('button',{name:'Cancelar inscripción',exact:true}).waitFor();
        await visit(admin,'eventos/?item='+event.id);await admin.locator('.modal [data-action="event-cancel-request"]').click();
        await admin.locator('[name=cancellation_reason]').fill('Cierre de la prueba de cupos y lista de espera.');
        const [cancelled]=await Promise.all([admin.waitForResponse(r=>r.url().endsWith('/events/'+event.id+'/cancel')&&r.request().method()==='POST'),admin.locator('[data-action="event-cancel-confirm"]').click()]);assert.equal((await cancelled.json()).cancelled,true);
        await visit(bruno,'eventos/?item='+event.id);await bruno.getByText('Evento cancelado',{exact:true}).waitFor();
        assert.equal(await bruno.locator('.modal [data-action="register"]').count(),0);
      });
      await scenario('suspension-reactivacion-acceso',async login=>{
        const admin=await login('admin'),carla=await login('profile'),actor=fixture.actors.profile;
        await visit(carla,'perfil/');const originalBio=await carla.locator('[name=bio]').inputValue();
        await admin.locator('.ascla-sidebar').getByRole('link',{name:'Administración',exact:true}).click();
        await admin.getByRole('button',{name:'Usuarios e intereses',exact:true}).click();
        const search=admin.locator('[data-form="admin-filter"][data-area="users"]');
        await search.locator('[name=q]').fill(actor.login);await search.getByRole('button',{name:'Buscar',exact:true}).click();
        async function changeAccess(suspended){
          const card=admin.locator('[data-admin-user="'+actor.id+'"]');await card.waitFor();
          await card.locator('.admin-user-more > summary').click();
          await card.locator('[data-action="user-access"][data-suspended="'+suspended+'"]').click();
          const [response]=await Promise.all([admin.waitForResponse(r=>r.url().endsWith('/admin/member/'+actor.id)&&r.request().method()==='POST'),admin.locator('.modal [data-action="user-status"][data-suspended="'+suspended+'"]').click()]);
          assert.equal(response.status(),200);assert.equal((await response.json()).suspended,suspended);
          await admin.locator('[data-admin-user="'+actor.id+'"] .request-status').filter({hasText:suspended?'Acceso suspendido':'Acceso activo'}).waitFor();
        }
        await changeAccess(true);
        await carla.goto(fixture.base+'/intranet/');await carla.locator('#user_login').waitFor();
        assert.equal(await carla.locator('#page-content h1').count(),0);
        await carla.locator('#user_login').fill(actor.login);await carla.locator('#user_pass').fill(actor.password);
        await Promise.all([carla.waitForNavigation({waitUntil:'domcontentloaded'}),carla.locator('#wp-submit').click()]);
        await carla.getByText('Tu membresía está vencida o suspendida. Contacta con ASCLA.',{exact:true}).waitFor();
        assert.equal(await carla.locator('#page-content h1').count(),0);
        await changeAccess(false);
        const restored=await login('profile');await visit(restored,'perfil/');await restored.locator('[name=bio]').waitFor();
        assert.equal(await restored.locator('[name=bio]').inputValue(),originalBio);
      });
    }
  } finally {await browser.close();}
  fs.writeFileSync(path.join(out,'frontend.json'),JSON.stringify({date:new Date().toISOString(),results},null,2));
  return results;
};
