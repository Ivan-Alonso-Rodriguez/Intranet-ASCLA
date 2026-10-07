/* Focused local browser acceptance for QA cases 024–058. Does not run PHPUnit. */
const {chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const root=path.resolve(__dirname,'..'),base=process.env.ASCLA_SITE_URL||'http://ascla_audit-wordpress';
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Disposable environment required');
const env=Object.fromEntries(fs.readFileSync(process.env.ASCLA_ENV_FILE||root+'/.env.audit','utf8').split(/\r?\n/).filter(v=>v.includes('=')).map(v=>[v.slice(0,v.indexOf('=')),v.slice(v.indexOf('=')+1)]));
const checks=[],errors=[],users=[],posts=[];let browser,admin;
async function api(page,route,body,method){return page.evaluate(async({route,body,method})=>{const url=route.startsWith('native:')?ASCLA.api.replace(/ascla\/v1\/?$/,'wp/v2/')+route.slice(7):ASCLA.api+route;const r=await fetch(url,{method:method||(body?'POST':'GET'),headers:{'X-WP-Nonce':ASCLA.nonce,'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})});return{status:r.status,body:await r.json()};},{route,body,method});}
async function ok(page,route,body,method){const r=await api(page,route,body,method);assert.ok(r.status>=200&&r.status<300,route+': '+r.status+' '+r.body.message);return r.body;}
async function login(name,password){const context=await browser.newContext({viewport:{width:1440,height:1000}}),p=await context.newPage();p.on('pageerror',e=>errors.push(e.message));await p.goto(base+'/wp-login.php');await p.locator('#user_login').fill(name);await p.locator('#user_pass').fill(password);await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}),p.locator('#wp-submit').click()]);await p.goto(base+'/intranet/');await p.locator('.hero').waitFor();await p.keyboard.press('Escape');return p;}
async function goto(p,route){await p.bringToFront();await p.goto(base+'/'+route);await p.locator('#page-content h1').waitFor();await p.keyboard.press('Escape');}
function pass(name){checks.push(name);console.log('PASS '+name);}
(async()=>{try{
 browser=await chromium.launch({headless:true});admin=await login('ascla.admin',env.ASCLA_ADMIN_PASSWORD);
 const catalog=(await ok(admin,'bootstrap')).catalogs;
 const suffix=crypto.randomBytes(4).toString('hex');
 for(const name of ['Alfa','Beta','Gamma','Delta']){
   const username='journey_bug_'+name.toLowerCase()+'_'+suffix,password='M7!qR9#'+crypto.randomBytes(18).toString('base64url');
   const u=await ok(admin,'native:users',{username,password,email:username+'@example.invalid',first_name:name,last_name:'QA '+suffix,roles:['ascla_member']});
   users.push({id:u.id,username});const p=await login(username,password);users.at(-1).page=p;
   await ok(p,'profiles/me',{first_name:name,last_name:'QA '+suffix,position:'Analista',company:'ASCLA QA',city:'Lima',bio:'Prueba local de los casos reportados',interests:[catalog.interest[0].id],industries:[catalog.industry[0].id],goals:[catalog.goal[0].id],networking:true,directory:true});
 }
 const [a,b,c,d]=users,A=a.page,B=b.page,C=c.page,D=d.page;
 await goto(A,'directorio/?q=Beta');
 await A.locator('[data-action="connect"][data-id="'+b.id+'"]').click();
 const requestForm=A.locator('[data-form="connection-request"]');await requestForm.locator('[name="message"]').fill('Hola, compartimos interés en gobierno corporativo.');await requestForm.locator('button[type="submit"]').click();await requestForm.waitFor({state:'detached'});
 let incoming=(await ok(B,'connections')).incoming.find(p=>p.id===a.id);assert.equal(incoming.connection.initial_message,'Hola, compartimos interés en gobierno corporativo.');
 await goto(B,'directorio/');await B.getByText(incoming.connection.initial_message,{exact:true}).waitFor();pass('024: presentación opcional visible al destinatario');
 await ok(B,'connections/'+incoming.connection.request_id+'/respond',{decision:'reject'});
 await goto(A,'directorio/?q=Beta');const connect=A.locator('[data-action="connect"][data-id="'+b.id+'"]');assert.equal(await connect.isEnabled(),true);await connect.click();await A.getByText('No es posible enviar la solicitud en este momento.',{exact:true}).waitFor();assert.equal(await A.locator('[data-form="connection-request"]').count(),0);
 const denied=await api(A,'relations',{target:b.id,kind:'connect',active:true});assert.equal(denied.status,409);assert.equal(denied.body.message,'No es posible enviar la solicitud en este momento.');assert.equal((await ok(A,'profiles/'+b.id)).connection.retry_at,null);pass('028: rechazo silencioso y bloqueo de reenvío');
 let rel=await ok(A,'relations',{target:c.id,kind:'connect',active:true,message:''});await ok(C,'connections/'+rel.request_id+'/respond',{decision:'accept'});
 await goto(A,'directorio/');await A.locator('.confirmed-connections summary').click();const filter=A.locator('#connection-name-filter');await filter.fill('sin-coincidencia');assert.equal(await A.locator('[data-confirmed-connections] .connection-row:visible').count(),0);await filter.fill('gAmMa');assert.equal(await A.locator('[data-confirmed-connections] .connection-row:visible').count(),1);await A.screenshot({path:root+'/test-results/connections-filter.png',fullPage:true});pass('030: filtro local inmediato en Mis conexiones');
 const chat=await ok(A,'conversations',{target:c.id}),message=await ok(A,'conversations/'+chat.id+'/messages',{body:'Historial inmutable de QA'});
 assert.equal((await api(A,'conversations/'+chat.id+'/messages/'+message.id,null,'DELETE')).status,403);assert.equal((await api(A,'conversations/'+chat.id+'/messages/'+message.id,{body:'Alterado'})).status,404);
 await goto(A,'mensajeria/?conversation='+chat.id);assert.equal(await A.locator('[data-action="message-delete-request"]').count(),0);pass('038: la API y la interfaz impiden borrar mensajes');
 await ok(A,'relations',{target:c.id,kind:'connect',active:false});assert.ok((await ok(A,'conversations/'+chat.id+'/messages')).items.some(m=>m.body==='Historial inmutable de QA'));assert.equal((await api(A,'conversations/'+chat.id+'/messages',{body:'No permitido'})).status,403);
 await goto(A,'mensajeria/?conversation='+chat.id);await A.locator('.chat-messages .bubble-body').filter({hasText:'Historial inmutable de QA'}).waitFor();assert.equal(await A.locator('.chat-compose textarea').isDisabled(),true);await A.screenshot({path:root+'/test-results/chat-readonly.png',fullPage:true});pass('039: historial accesible de solo lectura al desconectar');
 assert.equal((await api(A,'relations',{target:c.id,kind:'connect',active:true})).status,409);assert.equal((await api(C,'relations',{target:a.id,kind:'connect',active:true})).status,409);pass('034: enfriamiento bilateral tras desconexión');
 rel=await ok(A,'relations',{target:d.id,kind:'connect',active:true});await ok(D,'connections/'+rel.request_id+'/respond',{decision:'accept'});await ok(A,'relations',{target:d.id,kind:'block',active:true});assert.ok(!(await ok(A,'connections')).connected.some(p=>p.id===d.id));await ok(A,'relations',{target:d.id,kind:'block',active:false});assert.equal((await ok(A,'profiles/'+d.id)).connection.state,'none');assert.equal((await ok(A,'profiles/'+d.id)).connection.can_request,true);pass('033: bloquear elimina vínculo y desbloquear no lo restaura');
 const input={title:'Congreso Anual de Secretarios Operativos 2026',body:'Agenda profesional del congreso.',status:'draft',meta:{start:new Date(Date.now()+3*86400000).toISOString(),end:new Date(Date.now()+3*86400000+3600000).toISOString(),capacity:1,chatham:true}};
 let event=await ok(admin,'content/event',input);posts.push(event.id);assert.equal(event.status,'draft');assert.equal((await api(A,'items/'+event.id)).status,404);
 await goto(admin,'eventos/?item='+event.id);await admin.locator('[data-action="editor"][data-id="'+event.id+'"]').click();await admin.locator('[data-form="editor"] [name="status"]').selectOption('draft');await admin.keyboard.press('Escape');
 event=await ok(admin,'content/event/'+event.id,{...input,status:'publish'});assert.equal(event.status,'publish');pass('049: borrador conservado y publicación posterior');
 assert.equal((await ok(A,'items/'+event.id)).title,input.title);await goto(A,'eventos/?item='+event.id);await A.getByRole('heading',{name:input.title,exact:true}).waitFor();pass('047: el asociado ve el título institucional completo');
 await ok(A,'events/'+event.id+'/register',{status:'accepted'});await ok(B,'events/'+event.id+'/register',{status:'waitlisted'});await ok(C,'events/'+event.id+'/register',{status:'waitlisted'});await ok(A,'events/'+event.id+'/register',{status:'cancelled'});
 let detail=await ok(B,'events/'+event.id);assert.equal(detail.registered,'offered');assert.ok(Date.parse(detail.offer_expires_at)>Date.now());await goto(B,'eventos/?item='+event.id);await B.locator('time[datetime="'+detail.offer_expires_at+'"]').waitFor();pass('058: oferta con fecha límite visible y cupo reservado al primero');
 await ok(B,'events/'+event.id+'/register',{status:'accepted'});await ok(admin,'content/event/'+event.id,{...input,status:'publish',meta:{...input.meta,start:new Date(Date.now()+4*86400000).toISOString(),end:new Date(Date.now()+4*86400000+3600000).toISOString(),change_reason:'Cambio de fecha del congreso'}});assert.equal((await ok(B,'events/'+event.id)).registered,'reconfirm');pass('050: reprogramar exige reconfirmación');
 await A.setViewportSize({width:390,height:844});await goto(A,'directorio/');assert.ok(await A.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await A.screenshot({path:root+'/test-results/connections-mobile.png',fullPage:true});pass('Directorio usable en móvil sin desbordamiento');
 assert.deepEqual(errors,[]);fs.writeFileSync(root+'/test-results/bugs-ui.json',JSON.stringify({checks,errors},null,2));
}catch(error){console.error(error.message);fs.writeFileSync(root+'/test-results/bugs-ui.json',JSON.stringify({checks,errors,failure:error.message},null,2));process.exitCode=1;}finally{
 if(admin){for(const id of posts)await api(admin,'items/'+id,null,'DELETE').catch(()=>{});for(const user of users)await api(admin,'native:users/'+user.id+'?force=true&reassign=1',null,'DELETE').catch(()=>{});}
 if(browser)await browser.close();
}})();
