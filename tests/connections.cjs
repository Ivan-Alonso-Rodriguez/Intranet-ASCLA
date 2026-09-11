/* Real REST/browser acceptance for RF-0008, RF-0009, RF-0012 and RN-010. Local fixtures only. */
const {chromium}=require('playwright'), {execFileSync}=require('node:child_process'), fs=require('node:fs'), assert=require('node:assert/strict');
const base='http://localhost:8088', results=[], errors=[], rejected=[];
const fixture=input=>JSON.parse(execFileSync('docker',['compose','exec','-T','wordpress','php','/opt/ascla-tests/messaging-mail-fixture.php'],{input:JSON.stringify(input),encoding:'utf8'}));
const pause=ms=>new Promise(r=>setTimeout(r,ms));
async function until(fn,message,ms=18000){const end=Date.now()+ms;while(Date.now()<end){if(await fn())return;await pause(120);}throw Error(message);}
async function test(name,fn){const start=Date.now();await fn();results.push({name,status:'passed',ms:Date.now()-start});console.log('PASS '+name);}
async function api(p,route,body,nonce=true){return p.evaluate(async({route,body,nonce})=>{const url=new URL(ASCLA.api),[path,query='']=route.split('?');if(url.searchParams.has('rest_route'))url.searchParams.set('rest_route',url.searchParams.get('rest_route')+path);else url.pathname+=path;new URLSearchParams(query).forEach((v,k)=>url.searchParams.set(k,v));const r=await fetch(url,{method:body?'POST':'GET',headers:{...(nonce?{'X-WP-Nonce':nonce===true?ASCLA.nonce:nonce}:{}),'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})});return {status:r.status,data:await r.json()};},{route,body,nonce});}
async function ok(p,route,body){const r=await api(p,route,body);assert.equal(r.status,200,'Unexpected API status: '+route);return r.data;}
async function denied(p,route,body,status,nonce=true){const r=await api(p,route,body,nonce);assert.equal(r.status,status,'Authorization: '+route);rejected.push({route,status:r.status});return r;}
async function login(browser,u){const ctx=await browser.newContext({viewport:{width:1440,height:1050}}),p=await ctx.newPage();p.on('pageerror',e=>errors.push(e.message));await p.goto(base+'/wp-login.php');await p.locator('#user_login').fill(u.login);await p.locator('#user_pass').fill(u.password);await Promise.all([p.waitForNavigation(),p.locator('#wp-submit').click()]);await p.goto(base+'/intranet/');await p.locator('.hero').waitFor();return p;}
async function profile(p,id,state){await p.goto(base+'/perfil/?member='+id);await p.locator(`.modal [data-member-connection="${id}"][data-state="${state}"]`).waitFor();}
async function uploadPhoto(p){await p.goto(base+'/perfil/');await p.locator('[data-upload="photo"]').setInputFiles('wp-content/plugins/ascla-core/assets/ascla-logo.png');await until(async()=>Number(await p.locator('[name="photo_id"]').inputValue())>0,'Photo upload did not finish');const id=Number(await p.locator('[name="photo_id"]').inputValue());await ok(p,'profiles/me',{photo_id:id});return id;}
(async()=>{
 let users=[],browser,requestAB,conversation;
 try{
  fs.mkdirSync('test-results',{recursive:true});users=fixture({action:'setup'});browser=await chromium.launch({headless:true,channel:'msedge'});
  const [ua,ub,uc]=users,a=await login(browser,ua),b=await login(browser,ub),c=await login(browser,uc);
  await test('01 · Sin conexión: perfil y REST impiden iniciar mensajes',async()=>{
   await profile(a,ub.id,'none');assert.equal(await a.locator('.modal [data-action="message-start"]').count(),0);
   await denied(a,'conversations',{target:ub.id},403);await denied(b,'conversations',{target:ua.id},403);
  });
  await test('02 · A solicita conexión y ve pendiente sin poder duplicar',async()=>{
   const response=a.waitForResponse(r=>r.url().endsWith('/relations')&&r.request().method()==='POST');
   await a.locator('.modal [data-action="connect"]').click();requestAB=await(await response).json();
   await a.locator('.modal [data-state="outgoing_pending"]').waitFor();assert.equal(await a.locator('.modal [data-action="connect"]').count(),0);
   await denied(a,'relations',{target:ub.id,kind:'connect',active:true},409);await denied(b,'relations',{target:ua.id,kind:'connect',active:true},409);
   await denied(a,'conversations',{target:ub.id},403);await denied(b,'conversations',{target:ua.id},403);
  });
  await test('03 · Solo el receptor puede decidir; nonce y autenticación obligatorios',async()=>{
   const route=`connections/${requestAB.request_id}/respond`;
   await denied(a,route,{decision:'accept'},404);await denied(c,route,{decision:'reject'},404);
   await denied(b,route,{decision:'accept'},401,false);await denied(b,route,{decision:'accept'},403,'invalid-nonce');
   const guest=await browser.newContext(),r=await guest.request.post(base+'/wp-json/ascla/v1/'+route,{data:{decision:'accept'}});assert.equal(r.status(),401);await guest.close();
  });
  await test('04 · B ve la solicitud recibida y Aceptar/Rechazar en el perfil de A',async()=>{
   await b.goto(base+'/directorio/');await b.locator(`#connections-panel [data-member-connection="${ua.id}"][data-state="incoming_pending"]`).waitFor();
   await profile(b,ua.id,'incoming_pending');assert.equal(await b.locator('.modal [data-action="connect"]').count(),0);
   assert.equal(await b.locator('.modal [data-decision="accept"]').count(),1);assert.equal(await b.locator('.modal [data-decision="reject"]').count(),1);
   await b.screenshot({path:'test-results/connections-incoming.png',fullPage:true});
  });
  await test('05 · B acepta, ambos quedan conectados y la solicitud deja de estar pendiente',async()=>{
   await b.locator('.modal [data-decision="accept"]').click();await b.locator('.modal [data-state="connected"]').waitFor();
   await until(()=>a.locator('.modal [data-state="connected"]').count(),'Sender did not see acceptance');
   const left=(await ok(a,'profiles/'+ub.id)).connection,right=(await ok(b,'profiles/'+ua.id)).connection;
   assert.equal(left.can_message,true);assert.equal(right.can_message,true);assert.equal((await ok(b,'connections')).incoming.length,0);
   assert.equal(await b.locator('.modal [data-action="message-start"]').count(),1);
  });
  await test('06 · Fotos reales subidas al módulo privado se guardan en ambos perfiles',async()=>{
   await uploadPhoto(a);await uploadPhoto(b);assert.ok((await ok(a,'profiles/'+ub.id)).photo_url);assert.ok((await ok(b,'profiles/'+ua.id)).photo_url);
  });
  await test('07 · Conexión simétrica: una sola conversación y mensajes en ambos sentidos',async()=>{
   await profile(a,ub.id,'connected');await a.locator('.modal [data-action="message-start"]').click();await a.locator('.chat-compose').waitFor();
   conversation={id:Number(await a.locator('.chat-compose').getAttribute('data-id'))};const reverse=await ok(b,'conversations',{target:ua.id});assert.equal(Number(reverse.id),conversation.id);
   await b.goto(base+'/mensajeria/?conversation='+conversation.id);await b.locator('.chat-compose').waitFor();
   await a.locator('.chat-compose textarea').fill('Mensaje A a B tras aceptación');await a.locator('.chat-compose button').click();
   await until(()=>b.locator('.bubble').filter({hasText:'Mensaje A a B tras aceptación'}).count(),'B did not receive A');
   await b.locator('.chat-compose textarea').fill('Respuesta B a A con conexión confirmada');await b.locator('.chat-compose button').click();
   await until(()=>a.locator('.bubble').filter({hasText:'Respuesta B a A con conexión confirmada'}).count(),'A did not receive B');
   const chat=await ok(a,'conversations/'+conversation.id),p=await ok(a,'profiles/'+ub.id);assert.equal(chat.other.photo_url,p.photo_url);
  });
  await test('08 · C no puede abrir, leer o enviar manipulando IDs de A y B',async()=>{
   await denied(c,'conversations',{target:ua.id},403);await denied(c,'conversations/'+conversation.id,undefined,404);
   await denied(c,`conversations/${conversation.id}/messages`,undefined,404);await denied(c,`conversations/${conversation.id}/messages`,{body:'Unauthorized',target:ua.id},404);
   assert.equal((await ok(c,'conversations')).length,0);
  });
  await test('09 · Avatar y nombre del chat abren el perfil existente correcto',async()=>{
   for(const [p,other] of [[a,ub],[b,ua]]){
    await p.locator('.chat-title .avatar img').waitFor();await until(()=>p.locator('.chat-title .avatar img').evaluate(im=>im.complete&&im.naturalWidth>0),'Chat avatar did not load');
    assert.match(await p.locator('.chat-profile').getAttribute('href'),new RegExp('member='+other.id));
    await p.locator('.chat-profile').click();await p.waitForURL(u=>u.searchParams.get('member')===String(other.id));await p.locator(`.modal [data-member-connection="${other.id}"][data-state="connected"]`).waitFor();
   }
  });
  await test('10 · Sin fotografía y con foto privada se usa fallback sin imagen rota',async()=>{
   const request=await ok(c,'relations',{target:ua.id,kind:'connect',active:true});await ok(a,`connections/${request.request_id}/respond`,{decision:'accept'});
   const ca=await ok(a,'conversations',{target:uc.id});await a.goto(base+'/mensajeria/?conversation='+ca.id);await a.locator('.chat-profile').waitFor();assert.equal(await a.locator('.chat-title img').count(),0);assert.ok(await a.locator('.chat-title .avatar-initials').innerText());
   await ok(b,'profiles/me',{hidden:['photo_id']});await a.goto(base+'/mensajeria/?conversation='+conversation.id);await a.locator('.chat-profile').waitFor();assert.equal(await a.locator('.chat-title img').count(),0);
   await ok(b,'profiles/me',{hidden:[]});
  });
  await test('11 · Fallo de carga de foto muestra iniciales y no un icono roto',async()=>{
   const profile=await ok(a,'profiles/'+ub.id);await a.route(profile.photo_url,r=>r.abort('failed'));await a.reload();await a.locator('.chat-profile').waitFor();
   await until(async()=>await a.locator('.chat-title img').count()===0,'Broken avatar was not replaced');assert.ok(await a.locator('.chat-title .avatar-initials').innerText());await a.unroute(profile.photo_url);
  });
  await test('12 · Rechazar desde Mis conexiones no confirma y permite otra solicitud',async()=>{
   await ok(c,'relations',{target:ub.id,kind:'connect',active:true});await b.goto(base+'/directorio/');const row=b.locator(`#connections-panel [data-member-connection="${uc.id}"]`);await row.locator('[data-decision="reject"]').click();
   await until(async()=>await b.locator(`#connections-panel [data-member-connection="${uc.id}"]`).count()===0,'Rejected request remains pending');
   assert.equal((await ok(c,'profiles/'+ub.id)).connection.state,'none');await denied(c,'conversations',{target:ub.id},403);
   const again=await ok(c,'relations',{target:ub.id,kind:'connect',active:true});assert.equal(again.state,'outgoing_pending');
  });
  await test('13 · Recarga conserva conexión, mensajes, foto y acceso al perfil',async()=>{
   await a.goto(base+'/mensajeria/?conversation='+conversation.id);await a.locator('.chat-title img').waitFor();await until(()=>a.locator('.chat-title img').evaluate(im=>im.complete&&im.naturalWidth>0),'Reload avatar missing');
   await until(()=>a.locator('.bubble').filter({hasText:'Respuesta B a A'}).count(),'Messages missing on reload');
   await a.screenshot({path:'test-results/connections-chat.png',fullPage:true});await a.setViewportSize({width:390,height:844});await until(()=>a.locator('.ascla-sidebar').evaluate(el=>el.getBoundingClientRect().right<=1),'Mobile sidebar covers chat');assert.equal(await a.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);await a.screenshot({path:'test-results/connections-mobile.png',fullPage:true});
  });
  await test('14 · Se impiden solicitudes y conversaciones con uno mismo o IDs inválidos',async()=>{
   for(const target of [ua.id,0,-1,999999999]){await denied(a,'relations',{target,kind:'connect',active:true},400);await denied(a,'conversations',{target},400);}
  });
  assert.deepEqual(errors,[]);
 }finally{
  if(browser)await browser.close();if(users.length)fixture({action:'cleanup',users:users.map(u=>u.id)});
  fs.writeFileSync('test-results/connections.json',JSON.stringify({date:new Date().toISOString(),results,expectedRejections:rejected,jsErrors:errors},null,2));
 }
})().catch(e=>{console.error(e.message);process.exitCode=1;});
