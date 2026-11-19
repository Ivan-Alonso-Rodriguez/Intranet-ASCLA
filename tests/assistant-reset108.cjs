/* Real local history and /ask requests; delayed job completions are explicitly controlled. */
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Disposable local WordPress required');
const root=path.resolve(__dirname,'..'),base=process.env.ASCLA_SITE_URL||'http://ascla_audit-wordpress';
const f=JSON.parse(fs.readFileSync(root+'/test-results/assistant-reset108-fixture.json','utf8'));
const key='ascla-assistant-thread-'+f.user,checks=[],errors=[];let browser,page;
const pass=name=>{checks.push(name);console.log('PASS '+name);};
const blank=async()=>{await page.waitForFunction(()=>document.querySelectorAll('.assistant-message.user').length===0,{},{timeout:3500});assert.equal(await page.locator('.assistant-message.welcome').count(),1);assert.equal(await page.locator('[name=question]').inputValue(),'');};
const reset=async()=>{await page.locator('[data-action=assistant-new]').click();await blank();};
async function ask(question){
 await page.locator('[name=question]').fill(question);const pending=page.waitForResponse(r=>r.url().includes('/ascla/v1/ask')&&r.request().method()==='POST');
 await page.locator('[data-form=ask]').evaluate(form=>form.requestSubmit());const response=await pending;assert.equal(response.status(),200);
 return {job:await response.json(),payload:response.request().postDataJSON()};
}
(async()=>{try{
 browser=await chromium.launch({headless:true});page=await browser.newPage({viewport:{width:1440,height:1000}});page.on('pageerror',error=>errors.push(error.message));
 await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill(f.login);await page.locator('#user_pass').fill(f.password);
 await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#wp-submit').click()]);
 await page.evaluate(({key,thread})=>{localStorage.setItem(key,thread);sessionStorage.setItem(key,thread);},{key,thread:f.thread});
 await page.goto(base+'/asistente/');await page.locator('#assistant-messages').waitFor();await page.keyboard.press('Escape');
 assert.equal(await page.locator('.assistant-message.user').count(),2);assert.ok((await page.locator('#assistant-messages').innerText()).includes('Pregunta anterior 1'));
 assert.ok(!(await page.locator('#assistant-messages').innerText()).includes('Pregunta anterior 2'));pass('Historial real restaurado únicamente para el hilo activo');
 await page.locator('[name=question]').fill('Borrador de la conversación anterior');
 await page.evaluate(key=>{globalThis.asclaOriginalSetItem=Storage.prototype.setItem;Storage.prototype.setItem=function(name,value){if(name===key)throw new DOMException('Controlled full storage','QuotaExceededError');return globalThis.asclaOriginalSetItem.call(this,name,value);};},key);
 await reset();assert.equal(await page.locator('[name=question]').evaluate(el=>el===document.activeElement),true);pass('Reinicio inmediato y borrador limpio aunque el navegador rechace guardar el identificador');
 const one=await ask('Hola, ¿quién daba esa charla anterior?');assert.notEqual(one.payload.thread,f.thread);
 const config=await page.evaluate(()=>({api:ASCLA.api,nonce:ASCLA.nonce}));
 const stored=await page.request.get(config.api+'jobs/'+one.job.id,{headers:{'X-WP-Nonce':config.nonce}});assert.equal(stored.status(),200);assert.equal((await stored.json()).thread,one.payload.thread);
 pass('La nueva pregunta llega al backend con un identificador diferente al historial anterior');
 let releaseOld,oldStarted;const oldReady=new Promise(resolve=>oldStarted=resolve);
 await page.route(config.api+'jobs/'+one.job.id,async route=>{const response=await route.fetch();oldStarted();await new Promise(resolve=>releaseOld=resolve);await route.fulfill({response,json:{...await response.json(),status:'completed',result:{answer:'RESPUESTA TARDÍA DEL HILO ANTERIOR',sources:[],mode:'Respuesta controlada local'}}});});
 await oldReady;await reset();const two=await ask('Hola, esta es otra conversación');assert.notEqual(two.payload.thread,one.payload.thread);
 await page.route(config.api+'jobs/'+two.job.id,route=>route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({status:'completed',result:{answer:'Respuesta de la conversación nueva',sources:[],mode:'Respuesta controlada local'}})}));
 const oldReturned=page.waitForResponse(r=>r.url()===config.api+'jobs/'+one.job.id);releaseOld();await oldReturned;
 await page.locator('#assistant-job-'+two.job.id).getByText('Respuesta de la conversación nueva',{exact:true}).waitFor();
 assert.ok(!(await page.locator('#assistant-messages').innerText()).includes('RESPUESTA TARDÍA'));assert.equal(await page.locator('.assistant-message.user').count(),1);pass('Una respuesta tardía queda fuera de la conversación nueva');
 await page.evaluate(()=>{Storage.prototype.setItem=globalThis.asclaOriginalSetItem;});await reset();
 const saved=await page.evaluate(key=>localStorage.getItem(key),key);assert.notEqual(saved,two.payload.thread);await page.reload();await page.locator('#assistant-messages').waitFor();await blank();pass('El hilo vacío se conserva al recargar con almacenamiento disponible');
 await page.locator('[data-action=answer-history]').click();await page.locator(`.answer-history-entry[href*="job=${f.jobs[0]}"]`).click();
 await page.waitForFunction(()=>document.querySelectorAll('.assistant-message.user').length===2);assert.ok((await page.locator('#assistant-messages').innerText()).includes('Pregunta anterior 0'));
 assert.equal(new URL(page.url()).searchParams.get('job'),String(f.jobs[0]));pass('Las consultas previas se conservan y solo se recuperan al elegirlas en Historial');
 await reset();assert.equal(new URL(page.url()).searchParams.has('job'),false);await page.reload();await page.locator('#assistant-messages').waitFor();await blank();pass('Nueva conversación retira el enlace antiguo y recargar no restaura su contexto');
 // A previously requested history entry may finish after the user starts afresh.
 for(const fail of [false,true]){
  let releaseRestore,restoreStarted;const restoring=new Promise(resolve=>restoreStarted=resolve);
  await page.route(config.api+'jobs/'+f.jobs[0],async route=>{const response=await route.fetch();restoreStarted();await new Promise(resolve=>releaseRestore=resolve);await route.fulfill(fail?{status:503,contentType:'application/json',body:JSON.stringify({message:'Fallo controlado de recuperación'})}:{response});});
  await page.locator('[data-action=answer-history]').click();await page.locator(`.answer-history-entry[href*="job=${f.jobs[0]}"]`).click();await restoring;await reset();
  const restoreReturned=page.waitForResponse(r=>r.url()===config.api+'jobs/'+f.jobs[0]);releaseRestore();await restoreReturned;
  // A completed history-list request provides a barrier after the delayed response.
  await page.locator('[data-action=answer-history]').click();await page.locator('.answer-history-entry').first().waitFor();await page.keyboard.press('Escape');await blank();
  assert.equal(new URL(page.url()).searchParams.has('job'),false);assert.equal(await page.locator('.toast').count(),0);
  pass(fail?'Un error de recuperación obsoleto no aparece en la conversación nueva':'Una recuperación tardía desde Historial no puede deshacer el reinicio');
  await page.unroute(config.api+'jobs/'+f.jobs[0]);
 }
 for(const fail of [false,true]){
  let releaseThread,threadStarted;const loading=new Promise(resolve=>threadStarted=resolve);
  const match=url=>url.pathname.endsWith('/assistant/thread')&&url.searchParams.get('thread')===f.thread;
  await page.route(match,async route=>{const response=await route.fetch();threadStarted();await new Promise(resolve=>releaseThread=resolve);await route.fulfill(fail?{status:503,contentType:'application/json',body:JSON.stringify({message:'Fallo controlado del historial anterior'})}:{response});});
  await page.locator('[data-action=answer-history]').click();await page.locator(`.answer-history-entry[href*="job=${f.jobs[0]}"]`).click();await loading;await reset();
  const threadReturned=page.waitForResponse(r=>new URL(r.url()).pathname.endsWith('/assistant/thread'));releaseThread();await threadReturned;
  await page.locator('[data-action=answer-history]').click();await page.locator('.answer-history-entry').first().waitFor();await page.keyboard.press('Escape');await blank();
  assert.equal(await page.locator('#page-content > .error').count(),0);await page.unroute(match);
  pass(fail?'Un error tardío del historial tampoco reemplaza el chat reiniciado':'Una carga tardía del hilo anterior no vuelve a dibujar sus mensajes');
 }
 await page.screenshot({path:root+'/test-results/assistant-reset108-desktop.png',fullPage:true,animations:'disabled'});
 await page.setViewportSize({width:390,height:844});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);await page.screenshot({path:root+'/test-results/assistant-reset108-mobile.png',fullPage:true,animations:'disabled'});
 assert.deepEqual(errors,[]);
}catch(error){errors.push(error.message);console.error(error.stack);if(page)await page.screenshot({path:root+'/test-results/assistant-reset108-failure.png',fullPage:true}).catch(()=>{});process.exitCode=1;}finally{
 if(browser)await browser.close();fs.writeFileSync(root+'/test-results/assistant-reset108.json',JSON.stringify({scope:'Disposable local WordPress history and actual /ask persistence; job completion timing controlled in Playwright, no live Gemini requests',checks,errors},null,2)+'\n');
}})();
