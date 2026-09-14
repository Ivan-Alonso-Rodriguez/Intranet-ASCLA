/* Local browser/SMTP journeys. Only disposable users; never save reset links or passwords. */
const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const base = 'http://localhost:8088', mailbox = 'http://localhost:8025';
const results = [], errors = [], pollIntervalsMs = [];
const fixture = input => JSON.parse(execFileSync('docker', ['compose','exec','-T','wordpress','php','/opt/ascla-tests/messaging-mail-fixture.php'], {input:JSON.stringify(input), encoding:'utf8'}));
const sleep = ms => new Promise(resolve => setTimeout(resolve,ms));
async function until(fn, label, ms=14000) { const end=Date.now()+ms; while(Date.now()<end) {if(await fn()) return; await sleep(150);} throw new Error(label); }
async function test(name, fn) { if (process.env.ASCLA_JOURNEY_FILTER && !new RegExp(process.env.ASCLA_JOURNEY_FILTER).test(name)) return; const start=Date.now(); await fn(); results.push({name,status:'passed',ms:Date.now()-start}); console.log('PASS '+name); }
async function api(page, route, body) {
  return page.evaluate(async ({route,body}) => {
    const url=new URL(ASCLA.api), [path,query='']=route.split('?');
    if(url.searchParams.has('rest_route')) url.searchParams.set('rest_route',url.searchParams.get('rest_route')+path); else url.pathname+=path;
    new URLSearchParams(query).forEach((v,k)=>url.searchParams.set(k,v));
    const r=await fetch(url,{method:body?'POST':'GET',headers:{'X-WP-Nonce':ASCLA.nonce,'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})});
    if(!r.ok) throw new Error('API test status '+r.status); return r.json();
  },{route,body});
}
async function login(browser,user) {
 const context=await browser.newContext({viewport:{width:1440,height:1000}}),page=await context.newPage();
 page.on('pageerror',e=>errors.push(e.message));
 await page.goto(base+'/wp-login.php'); await page.locator('#user_login').fill(user.login); await page.locator('#user_pass').fill(user.password);
 await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);
 await page.goto(base+'/mensajeria/'); await page.locator('.chat-layout').waitFor(); return page;
}
(async()=>{
 let browser,users=[];
 try {
  users=fixture({action:'setup'}); browser=await chromium.launch({channel:'msedge',headless:true});
  const a=await login(browser,users[0]),b=await login(browser,users[1]),c=await login(browser,users[2]);
  let ab,cb;
  const bubbles=b.locator('#chat-messages .bubble');
  await test('Conversación nueva y primer mensaje llegan a una bandeja vacía sin recargar',async()=>{
   assert.equal(await b.locator('.chat-person').count(),0);
   const requestAB=await api(a,'relations',{target:users[1].id,kind:'connect',active:true}); await api(b,`connections/${requestAB.request_id}/respond`,{decision:'accept'});
   ab=await api(a,'conversations',{target:users[1].id});
   await api(a,`conversations/${ab.id}/messages`,{body:'Primer mensaje recibido automáticamente'});
   await until(()=>bubbles.filter({hasText:'Primer mensaje recibido automáticamente'}).count(),'Primer mensaje no recibido');
  });
  await test('Actualización automática: intervalo observado de dos segundos',async()=>{
   const times=[];const listener=r=>{if(r.method()==='GET' && new URL(r.url()).pathname.endsWith('/conversations'))times.push(Date.now());};
   b.on('request',listener);try{await until(()=>times.length>=3,'No se observan consultas periódicas',12000);}finally{b.off('request',listener);}
   for(let i=1;i<times.length;i++){const delta=times[i]-times[i-1];pollIntervalsMs.push(delta);assert.ok(delta>=1700&&delta<3800,'Intervalo inesperado: '+delta);}
  });
  await test('Mensajes incrementales conservan borrador y no duplican burbujas' ,async()=>{
   const origin=await b.evaluate(()=>performance.timeOrigin);
   await b.locator('.chat-compose textarea').fill('Mi borrador sin enviar');
   for(const body of ['Segundo mensaje','Tercer mensaje']) await api(a,`conversations/${ab.id}/messages`,{body});
   await until(()=>bubbles.filter({hasText:'Tercer mensaje'}).count(),'Mensaje incremental no recibido');
   assert.equal(await b.locator('.chat-compose textarea').inputValue(),'Mi borrador sin enviar');
   assert.equal(await b.evaluate(()=>performance.timeOrigin),origin);
   await sleep(4500); assert.equal(await bubbles.count(),3);
  });
  await test('La lista muestra otras conversaciones, vista previa y contador sin leer',async()=>{
   const requestCB=await api(c,'relations',{target:users[1].id,kind:'connect',active:true}); await api(b,`connections/${requestCB.request_id}/respond`,{decision:'accept'});
   cb=await api(c,'conversations',{target:users[1].id}); await api(c,`conversations/${cb.id}/messages`,{body:'Mensaje desde Celia'});
   const row=b.locator(`.chat-person[data-id="${cb.id}"]`);
   await until(()=>row.locator('.tag').count(),'Conversación nueva sin contador');
   assert.match(await row.innerText(),/Mensaje desde Celia/);
   assert.equal(await b.locator('.chat-compose textarea').inputValue(),'Mi borrador sin enviar');
   await row.click(); await until(()=>bubbles.filter({hasText:'Mensaje desde Celia'}).count(),'No abre conversación');
   await until(async()=>await b.locator(`.chat-person[data-id="${cb.id}"] .tag`).count()===0,'No marca leído');
   await b.locator(`.chat-person[data-id="${ab.id}"]`).click();
   await b.locator(`.chat-compose[data-id="${ab.id}"] textarea`).waitFor(); assert.equal(await b.locator('.chat-compose textarea').inputValue(),'Mi borrador sin enviar');
  });
  await test('Historial paginado y posición de lectura sobreviven a nuevas recepciones',async()=>{
   fixture({action:'history',users:[users[0].id,users[1].id],conversation:ab.id});
   await b.goto(base+`/mensajeria/?conversation=${ab.id}`); await b.locator('[data-action="older-messages"]').waitFor();
   await b.locator('[data-action="older-messages"]').click();
   await until(async()=>await bubbles.count()===120,'Segunda página incompleta');
   await b.locator('[data-action="older-messages"]').click(); await until(async()=>await bubbles.count()===133,'Tercera página incompleta');
   await b.locator('#chat-messages').evaluate(el=>el.scrollTop=0);
   await a.goto(base+`/mensajeria/?conversation=${ab.id}`); await a.locator('.chat-compose textarea').fill('Enviado desde el formulario real');
   await a.locator('.chat-compose button').click();
   await until(()=>bubbles.filter({hasText:'Enviado desde el formulario real'}).count(),'Mensaje desde formulario no recibido');
   assert.equal(await bubbles.count(),134); assert.equal(await b.locator('#chat-messages').evaluate(el=>el.scrollTop),0);
   assert.equal(await b.locator('[data-action="older-messages"]').count(),0);
   await b.screenshot({path:'test-results/messaging-live-desktop.png'});
  });
  await test('Bloqueo remoto deshabilita y vuelve a habilitar el envío',async()=>{
   await api(b,'relations',{target:users[0].id,kind:'block',active:true});
   await until(()=>a.locator('.chat-compose textarea').isDisabled(),'Bloqueo remoto no actualizado');
   await api(b,'relations',{target:users[0].id,kind:'block',active:false});
   await until(async()=>!(await a.locator('.chat-compose textarea').isDisabled()),'Desbloqueo no actualizado');
  });
  await test('Fallo de red muestra reintento y conserva el borrador al reconectar',async()=>{
   await b.locator('.chat-compose textarea').fill('Borrador durante interrupción');
   await b.route('**/conversations?*',r=>r.abort('failed'));
   await until(()=>b.locator('#chat-sync.is-error').count(),'Fallo de red sin estado');
   await b.unroute('**/conversations?*'); await b.evaluate(()=>window.dispatchEvent(new Event('online')));
   await until(async()=>await b.locator('#chat-sync.is-error').count()===0,'No recupera conexión');
   assert.equal(await b.locator('.chat-compose textarea').inputValue(),'Borrador durante interrupción');
  });
  await test('Cambio rápido de conversación descarta la respuesta anterior',async()=>{
   await b.locator('.chat-compose textarea').fill('Borrador durante interrupción');
   let release,finish,captured=false; const gate=new Promise(resolve=>release=resolve), finished=new Promise(resolve=>finish=resolve);
   const pattern=`**/conversations/${cb.id}/messages`;
   await b.route(pattern, async route=>{captured=true; await gate; try { await route.continue(); } finally { finish(); }});
   await b.locator(`.chat-person[data-id="${cb.id}"]`).click(); await b.locator(`.chat-compose[data-id="${cb.id}"]`).waitFor(); await until(()=>captured,'No se interceptó la lectura retrasada');
   await b.locator(`.chat-person[data-id="${ab.id}"]`).click(); await b.locator(`.chat-compose[data-id="${ab.id}"]`).waitFor();
   release(); await finished; await b.unroute(pattern); await sleep(1000);
   assert.equal(await bubbles.filter({hasText:'Mensaje desde Celia'}).count(),0);
   assert.equal(await b.locator('.chat-compose textarea').inputValue(),'Borrador durante interrupción');
  });
  await test('Diseño móvil y salida de mensajería detienen las consultas',async()=>{
   await b.setViewportSize({width:390,height:844}); await until(()=>b.locator('.ascla-sidebar').evaluate(el=>el.getBoundingClientRect().right<=1),'Menú móvil no se oculta'); await b.screenshot({path:'test-results/messaging-live-mobile.png'});
   assert.equal(await b.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
   await b.locator('.ascla-sidebar a[href="'+base+'/perfil/"]').evaluate(el=>el.click()); await b.locator('[data-form="profile"]').waitFor();
   await sleep(1000); let requests=0; const listener=r=>{if(r.url().includes('/conversations')) requests++;}; b.on('request',listener);
   await sleep(4500); b.off('request',listener); assert.equal(requests,0);
  });
  await test('SMTP configurable en administración y envío de prueba al buzón local',async()=>{
   const admin=await login(browser,users[3]); await admin.goto(base+'/wp-admin/admin.php?page=ascla-configuracion');
   await admin.locator('[name="smtp_host"]').waitFor();
   assert.equal(await admin.locator('[name="smtp_password"]').inputValue(),'');
   await admin.locator('[data-action="mail-test"]').click(); await until(()=>admin.locator('.toast').filter({hasText:'buzón local'}).count(),'Prueba SMTP sin confirmación');
   const list=await (await fetch(mailbox+'/api/v1/search?query='+encodeURIComponent('to:'+users[3].email))).json(); assert.ok(list.messages.length>0);
  });
  await test('Recuperación nativa: solicitar correo → SMTP → enlace → nueva contraseña → login',async()=>{
   const context=await browser.newContext(),p=await context.newPage();
   await p.goto(base+'/wp-login.php?action=lostpassword');
   assert.ok(await p.getByRole('link',{name:'buzón de pruebas'}).count());
   await p.locator('#user_login').fill(users[1].email);
   await Promise.all([p.waitForNavigation(),p.locator('#wp-submit').click()]);
   assert.ok(p.url().includes('checkemail=confirm'),'Solicitud de recuperación no confirmada');
   const list=await (await fetch(mailbox+'/api/v1/search?query='+encodeURIComponent('to:'+users[1].email))).json();
   assert.ok(list.messages.length>0,'Correo SMTP no encontrado');
   const mail=await (await fetch(mailbox+'/api/v1/message/'+list.messages[0].ID)).json();
   const reset=(mail.Text||'').match(/http:\/\/localhost:8088\/wp-login\.php\?[^\s<>]+/g)?.find(url=>url.includes('action=rp'));
   assert.ok(reset,'No se encontró enlace de restablecimiento');
   await p.goto(reset.replace(/&amp;/g,'&')); await p.locator('#pass1').waitFor(); await p.waitForFunction(()=>typeof zxcvbn==='function' && document.querySelector('#pass1')?.type==='text');
   const newPassword='Recovery!'+require('node:crypto').randomBytes(15).toString('hex');
   await p.locator('#pass1').fill(newPassword); if(await p.locator('#pass2').isVisible()) await p.locator('#pass2').fill(newPassword);
   const submittedPromise=p.waitForRequest(r=>r.method()==='POST' && r.url().includes('action=resetpass')); await Promise.all([p.waitForNavigation(),p.locator('#wp-submit').click()]); const submitted=new URLSearchParams((await submittedPromise).postData()); assert.ok(submitted.get('pass1')===newPassword && submitted.get('pass2')===newPassword,'Password form changed before submission'); await p.locator('.reset-pass').waitFor(); assert.equal(await p.locator('#pass1').count(),0,'Reset form remains: '+(await p.locator('#login_error').count() ? await p.locator('#login_error').innerText() : 'no explicit error')); await p.goto(base+'/wp-login.php');
   await p.locator('#user_login').fill(users[1].login); await p.locator('#user_pass').fill(newPassword);
   await Promise.all([p.waitForNavigation(),p.locator('#wp-submit').click()]); assert.ok(!p.url().includes('wp-login.php'),'Nueva contraseña no permite acceder: '+(await p.locator('#login_error').count() ? await p.locator('#login_error').innerText() : 'no explicit error'));
   await p.locator('#ascla-root').waitFor();
   const other=await browser.newContext(),reused=await other.newPage(); await reused.goto(reset.replace(/&amp;/g,'&'));
   assert.equal(await reused.locator('#pass1').count(),0,'Se reutilizó un enlace consumido');
   const safe=await context.newPage(); await safe.goto(base+'/wp-login.php?action=lostpassword'); await safe.screenshot({path:'test-results/password-recovery-local.png'});
  });
  assert.deepEqual(errors,[]);
 } finally {
  if(browser) await browser.close();
  if(users.length) {
   for(const u of users) await fetch(mailbox+'/api/v1/search?query='+encodeURIComponent('to:'+u.email),{method:'DELETE'});
   fixture({action:'cleanup',users:users.map(u=>u.id)});
  }
  fs.mkdirSync('test-results',{recursive:true}); fs.writeFileSync('test-results/messaging-mail.json',JSON.stringify({date:new Date().toISOString(),results,jsErrors:errors,pollIntervalsMs},null,2));
 }
})().catch(e=>{console.error(String(e.message).replace(/fill\("[^"]*"\)/g,'fill("[redacted]")').replace(/([?&](?:key|login)=)[^&\s]+/g,'$1[redacted]'));process.exitCode=1;});
