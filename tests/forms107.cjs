/* Real browser and REST checks against a disposable WordPress installation. */
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),crypto=require('node:crypto');
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Disposable environment required');
const root=path.resolve(__dirname,'..'),base=process.env.ASCLA_SITE_URL||'http://ascla_audit-wordpress';
const env=Object.fromEntries(fs.readFileSync(process.env.ASCLA_ENV_FILE||root+'/.env.audit','utf8').split(/\r?\n/).filter(v=>v.includes('=')).map(v=>[v.slice(0,v.indexOf('=')),v.slice(v.indexOf('=')+1)]));
const checks=[],errors=[],posts=[],originalLocales=new Map();let browser,page,adminPage,memberPage;
async function api(route,body,method){return page.evaluate(async({route,body,method})=>{const url=route.startsWith('native:')?ASCLA.api.replace(/ascla\/v1\/?$/,'wp/v2/')+route.slice(7):ASCLA.api+route;const r=await fetch(url,{method:method||(body?'POST':'GET'),headers:{'X-WP-Nonce':ASCLA.nonce,'Content-Type':'application/json'},...(body!==undefined&&body!==null?{body:JSON.stringify(body)}:{})});return{status:r.status,body:await r.json()};},{route,body,method});}
async function ok(route,body,method){const r=await api(route,body,method);assert.ok(r.status>=200&&r.status<300,route+': '+r.status+' '+r.body.message);return r.body;}
async function goto(route){await page.goto(base+'/'+route);await page.locator('#page-content h1').waitFor();if(!new URL(page.url()).searchParams.has('item'))await page.keyboard.press('Escape');}
async function locale(value){await page.evaluate(async locale=>{const r=await fetch(location.href,{method:'POST',body:new URLSearchParams({_ascla_change_language:'1',_ascla_locale:locale,_ascla_language_nonce:ASCLA.languageNonce})});if(!r.ok)throw Error('Language update failed');},value);assert.equal((await ok('native:users/me?context=edit')).locale,value);}
function pass(name){checks.push(name);console.log('PASS '+name);}
(async()=>{try{
 browser=await chromium.launch({headless:true});
 for(const [role,login,password] of [['admin','ascla.admin',env.ASCLA_ADMIN_PASSWORD],['member','demo.asociado',env.ASCLA_DEMO_PASSWORD]]){
  page=await browser.newPage({viewport:{width:1280,height:900}});page.on('pageerror',e=>errors.push(e.message));
  await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill(login);await page.locator('#user_pass').fill(password);await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#wp-submit').click()]);await goto('intranet/');
  originalLocales.set(page,(await ok('native:users/me?context=edit')).locale);await locale('en_US');
  if(role==='admin')adminPage=page;else memberPage=page;
 }
 page=memberPage;await goto('mensajeria/');await page.locator('[data-action="group-chat-open"]').click();
 const group=page.locator('[data-form="group-chat"]');await group.waitFor();assert.equal(await group.locator('[name="title"]').getAttribute('maxlength'),'120');assert.equal(await group.locator('[name="description"]').getAttribute('maxlength'),'240');
 const groupText=await group.innerText();for(const text of ['Create group','Choose photo','JPG, PNG or WebP','selected','50 members'])assert.ok(groupText.includes(text),text);for(const text of ['Crear grupo','Elegir foto','seleccionados','JPG, PNG o WebP'])assert.ok(!groupText.includes(text),text);
 assert.ok((await group.locator('[name="title"]').getAttribute('placeholder')).startsWith('E.g.'));await page.keyboard.press('Escape');pass('Grupo en inglés: textos, ayuda, placeholders y límites coherentes');
 page=adminPage;await goto('eventos/');await page.locator('[data-action="editor"][data-type="event"]').first().click();const editor=page.locator('[data-form="editor"]');await editor.waitFor();
 for(const [name,min,max] of [['capacity',0,100000],['offer_hours',1,168]]){const f=editor.locator('[name="'+name+'"]');assert.equal(await f.getAttribute('min'),String(min));assert.equal(await f.getAttribute('max'),String(max));assert.equal(await f.getAttribute('step'),'1');}
 assert.equal(await editor.locator('[name="location"]').getAttribute('maxlength'),'10000');assert.equal(await editor.locator('[name="url"]').getAttribute('maxlength'),'2000');assert.equal(await editor.locator('[name="agenda"]').getAttribute('maxlength'),'10000');
 await editor.locator('[name="title"]').fill('Browser bounds check');await editor.locator('[name="body"]').fill('Never persisted');for(const name of ['start','end'])await editor.locator('[name="'+name+'_date"]').fill('2027-01-15');await editor.locator('[name="start_time"]').fill('10:00');await editor.locator('[name="end_time"]').fill('11:00');await editor.locator('[name="capacity"]').fill('1.5');
 let requests=0;const observe=r=>{if(r.method()==='POST'&&r.url().includes('/content/event'))requests++;};page.on('request',observe);await editor.evaluate(f=>f.requestSubmit());assert.equal(requests,0);assert.ok(await editor.locator('[name="capacity"]').evaluate(n=>n.validity.stepMismatch));await editor.locator('[name="capacity"]').fill('10');await editor.locator('[name="offer_hours"]').fill('0');await editor.evaluate(f=>f.requestSubmit());assert.equal(requests,0);assert.ok(await editor.locator('[name="offer_hours"]').evaluate(n=>n.validity.rangeUnderflow));page.off('request',observe);
 await page.keyboard.press('Escape');if(await page.locator('[data-action="modal-unsaved-discard"]').count())await page.locator('[data-action="modal-unsaved-discard"]').click();await page.locator('.modal').waitFor({state:'hidden'});pass('Editor de evento: límites compartidos, enteros obligatorios y bloqueo nativo sin guardar');
 const suffix=crypto.randomBytes(4).toString('hex'),title='Complete título y contenido. '+suffix;
 const event=await ok('content/event',{title,body:'Original preserved',status:'publish',meta:{start:new Date(Date.now()+3600000).toISOString(),end:new Date(Date.now()+7200000).toISOString(),capacity:10,offer_hours:24}});posts.push(event.id);
 for(const meta of [{capacity:1.5},{capacity:-1},{offer_hours:0},{modality:'unknown'}])assert.equal((await api('content/event/'+event.id,{title:'Must not change',body:'Changed',status:'publish',meta})).status,400);
 assert.equal((await ok('items/'+event.id)).title,title);pass('REST forzado rechaza números y modalidad inválidos sin alterar el evento publicado');
 const bad=await api('content/hub',{title:'x'.repeat(201),body:'Valid'});assert.equal(bad.status,400);assert.equal(bad.body.message,'The text exceeds the limit of 200 characters.');assert.equal((await api('account/password',{current_password:['invalid']})).body.message,'Invalid text.');pass('Errores REST y formatos con números se traducen según la cuenta');
 const topic=await ok('content/topic',{title,body:'Texto español original',status:'publish'});posts.push(topic.id);const comment=await ok('items/'+topic.id+'/comments',{body:'Texto original del comentario'});await goto('foros/?item='+topic.id);await page.locator('[data-action="comment-reply"][data-id="'+comment.id+'"]').click();const reply=page.locator('.comment-reply-form');await reply.waitFor();
 assert.equal(await reply.locator('label').getAttribute('for'),await reply.locator('textarea').getAttribute('id'));assert.equal(await reply.locator('textarea').getAttribute('maxlength'),'5000');assert.ok((await reply.locator('label').innerText()).startsWith('Reply to '));assert.ok(await reply.locator('textarea').evaluate(n=>n===document.activeElement));assert.equal((await ok('items/'+topic.id)).title,title);pass('Respuesta a comentario: etiqueta única, foco, límite y traducción sin reescribir contenido');
 await reply.locator('[data-action="comment-reply-cancel"]').click();const reaction=page.locator('[data-action="content-reaction"][data-comment="true"][data-id="'+comment.id+'"]').first();await reaction.click();await page.waitForFunction(id=>{const b=document.querySelector('[data-action="content-reaction"][data-comment="true"][data-id="'+id+'"]');return b?.getAttribute('aria-pressed')==='true'&&b===document.activeElement;},comment.id);pass('Acción de comentario conserva el foco después de la petición');
 page=memberPage;await goto('contacto/');assert.equal(await page.locator('[data-form="contact"] [name="body"]').getAttribute('maxlength'),'5000');assert.equal((await api('content/contact',{title:'Limit check',body:'x'.repeat(5001)})).status,400);pass('Contacto conserva el mismo límite en navegador y servidor');
 assert.deepEqual(errors,[]);
}catch(error){console.error(error.stack);errors.push(error.message);process.exitCode=1;}finally{
 if(adminPage){page=adminPage;for(const id of posts)await api('items/'+id,null,'DELETE').catch(()=>{});}
 for(const [actorPage,originalLocale] of originalLocales){page=actorPage;await locale(originalLocale).catch(e=>{errors.push(e.message);process.exitCode=1;});}
 fs.writeFileSync(root+'/test-results/forms107.json',JSON.stringify({checks,errors},null,2));if(browser)await browser.close();
}})();
