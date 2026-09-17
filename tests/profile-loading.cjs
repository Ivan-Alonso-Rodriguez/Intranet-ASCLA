/* Progressive profiles against disposable local WordPress; AI responses are intercepted, never billed. */
const {chromium}=require('playwright');
const {execFileSync}=require('node:child_process');
const assert=require('node:assert/strict'), fs=require('node:fs');
const base='http://localhost:8088', results=[], errors=[];
const fixture=input=>JSON.parse(execFileSync('docker',['compose','exec','-T','wordpress','php','/opt/ascla-tests/messaging-mail-fixture.php'],{input:JSON.stringify(input),encoding:'utf8'}));
const deferred=()=>{let resolve;const promise=new Promise(r=>{resolve=r;});return {promise,resolve};};
const affinity={score:62,shared:[],signals:[],explanation:'Afinidad calculada sin IA.'};
async function test(name,fn){await fn();results.push({name,status:'passed'});console.log('PASS '+name);}
async function login(browser,u){
 const context=await browser.newContext({viewport:{width:1366,height:900}}),p=await context.newPage();
 p.on('pageerror',e=>errors.push(e.message));
 await p.goto(base+'/wp-login.php');await p.locator('#user_login').fill(u.login);await p.locator('#user_pass').fill(u.password);
 await Promise.all([p.waitForNavigation(),p.locator('#wp-submit').click()]);
 await p.goto(base+'/directorio/');await p.locator('.member-card').first().waitFor();return p;
}
(async()=>{
 let users=[],browser,p,release;
 try{
  fs.mkdirSync('test-results',{recursive:true});users=fixture({action:'setup'});
  browser=await chromium.launch({headless:true,channel:'msedge'});p=await login(browser,users[0]);
  const b=users[1],c=users[2];let mode='slow',plainGate=deferred(),proseGate=deferred(),started=deferred();
  release=()=>{plainGate.resolve();proseGate.resolve();};
  await p.route(url=>/\/matching\/\d+$/.test(url.pathname),async route=>{
   const url=new URL(route.request().url()),id=Number(url.pathname.split('/').pop());
   if(url.searchParams.get('explain')==='0'){
    if(mode==='slow')await plainGate.promise;
    await route.fulfill({json:affinity});return;
   }
   started.resolve();
   if(['slow','stale','closed','navigation'].includes(mode)&&id===b.id){await proseGate.promise;}
   if(mode==='failed'){await route.fulfill({status:502,json:{message:'Provider unavailable'}});return;}
   if(mode==='invalid'){await route.fulfill({contentType:'application/json',body:'invalid-json'});return;}
   if(mode==='network'){await route.abort('timedout');return;}
   await route.fulfill({json:{...affinity,explanation:id===b.id?'Explicación de Bruno <img src=x>':'Explicación de Celia',conversation_proposal:'Conversemos.',mode:'IA de prueba',fallback:false}});
  });
  const open=async id=>{await p.locator(`.member-card [data-action="member"][data-id="${id}"]`).click();await p.locator('.modal .profile-summary').waitFor();};
  const close=async()=>{await p.locator('.modal [data-action="close"]').click();await p.locator('.modal').waitFor({state:'detached'});};
  const done=()=>p.locator('[data-profile-affinity][aria-busy="false"]').waitFor();
  await test('Datos y acciones visibles antes de afinidad e IA; la explicación actualiza solo su bloque',async()=>{
   await open(b.id);
   assert.equal(await p.locator('.modal-top h2').textContent(),'Bruno Pruebas');
   assert.ok(await p.locator('.modal .detail-body').isVisible());assert.ok(await p.locator('.modal [data-action="connect"]').isEnabled());
   await p.locator('.profile-summary').evaluate(el=>el.dataset.testPreserved='yes');
   plainGate.resolve();await started.promise;
   assert.match(await p.locator('[data-profile-affinity]').textContent(),/Preparando explicación/);
   await p.locator('.modal [data-action="connect"]').click();
   await p.locator('.modal [data-state="outgoing_pending"]').waitFor();
   proseGate.resolve();await done();
   assert.match(await p.locator('[data-profile-affinity]').textContent(),/Explicación de Bruno <img src=x>/);
   assert.equal(await p.locator('[data-profile-affinity] img').count(),0);
   assert.equal(await p.locator('.profile-summary').getAttribute('data-test-preserved'),'yes');
   assert.equal(await p.locator('.modal [data-state="outgoing_pending"]').count(),1);
   await p.screenshot({path:'test-results/profile-loading-success.png',fullPage:true});await close();
  });
  for(const failure of ['failed','invalid','network']){
   await test('Perfil usable con fallo de IA: '+failure,async()=>{
    mode=failure;await open(b.id);await done();
    const text=await p.locator('[data-profile-affinity]').textContent();
    assert.match(text,/Afinidad calculada sin IA/);assert.match(text,/Puedes seguir usando el perfil/);
    assert.ok(await p.locator('.modal .profile-summary').isVisible());
    assert.ok(await p.locator('.modal [data-action="connection-remove-request"]').isEnabled());await close();
   });
  }
  await test('Una respuesta tardía no sobrescribe otro perfil',async()=>{
   mode='stale';proseGate=deferred();started=deferred();await open(b.id);await started.promise;await close();
   await open(c.id);await done();
   const response=p.waitForResponse(r=>/\/matching\//.test(r.url())&&r.url().endsWith('/'+b.id));proseGate.resolve();await response;
   assert.equal(await p.locator('.modal-top h2').textContent(),'Celia Pruebas');
   assert.match(await p.locator('[data-profile-affinity]').textContent(),/Explicación de Celia/);await close();
  });
  await test('Cerrar el perfil durante la IA no vuelve a abrirlo',async()=>{
   mode='closed';proseGate=deferred();started=deferred();await open(b.id);await started.promise;await close();
   const response=p.waitForResponse(r=>r.url().endsWith('/matching/'+b.id));proseGate.resolve();await response;
   assert.equal(await p.locator('.modal').count(),0);
  });
  await test('Cerrar mientras llegan los datos principales no reabre el perfil',async()=>{
   mode='ready';const gate=deferred(),requested=deferred();
   const delayed=async route=>{requested.resolve();await gate.promise;await route.continue();};
   await p.route('**/profiles/'+b.id,delayed);
   await p.locator(`.member-card [data-action="member"][data-id="${b.id}"]`).click();await requested.promise;
   await p.locator('.modal .view-loading').waitFor();await close();
   const response=p.waitForResponse(r=>r.url().endsWith('/profiles/'+b.id));gate.resolve();await response;
   assert.equal(await p.locator('.modal').count(),0);await p.unroute('**/profiles/'+b.id,delayed);
  });
  await test('Navegar durante la IA conserva la nueva sección',async()=>{
   mode='navigation';proseGate=deferred();started=deferred();await open(b.id);await started.promise;await close();
   await p.locator('.header-profile').click();await p.locator('form[data-form="profile"]').waitFor();
   proseGate.resolve();assert.equal(await p.locator('.modal').count(),0);assert.ok(await p.locator('form[data-form="profile"]').isVisible());
  });
  assert.deepEqual(errors,[]);console.log('PASS '+results.length+' progressive profile tests; no uncaught browser errors.');
 }catch(error){console.error(error);process.exitCode=1;if(p)await p.screenshot({path:'test-results/profile-loading-failure.png',fullPage:true}).catch(()=>{});}
 finally{release?.();if(browser)await browser.close();if(users.length)fixture({action:'cleanup',users:users.map(u=>u.id)});fs.writeFileSync('test-results/profile-loading.json',JSON.stringify({results,errors},null,2));}
})();