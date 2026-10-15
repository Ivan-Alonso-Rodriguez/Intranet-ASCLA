/* Browser coverage of delivery controls; failure rows are simulated, PHP exercises real retries. */
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Disposable environment required');
const root=path.resolve(__dirname,'..'),base=process.env.ASCLA_SITE_URL||'http://ascla_audit-wordpress';
const env=Object.fromEntries(fs.readFileSync(process.env.ASCLA_ENV_FILE||root+'/.env.audit','utf8').split(/\r?\n/).filter(v=>v.includes('=')).map(v=>[v.slice(0,v.indexOf('=')),v.slice(v.indexOf('=')+1)]));
const checks=[],errors=[];let browser;
const pass=name=>{checks.push(name);console.log('PASS '+name);};
(async()=>{try{
 browser=await chromium.launch({headless:true});
 const context=await browser.newContext({viewport:{width:1440,height:1000}}),page=await context.newPage();
 page.on('pageerror',e=>errors.push(e.message));
 await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill('ascla.admin');await page.locator('#user_pass').fill(env.ASCLA_ADMIN_PASSWORD);
 await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#wp-submit').click()]);
 await page.goto(base+'/administracion/?section=configuracion');await page.locator('[data-form="settings"]').waitFor();
 const status=await page.evaluate(async()=>{const r=await fetch(ASCLA.api+'admin/deliveries',{headers:{'X-WP-Nonce':ASCLA.nonce}});return {status:r.status,body:await r.json()};});
 assert.equal(status.status,200);assert.deepEqual(Object.keys(status.body.counts).sort(),['failed','pending','processing']);
 for(const item of status.body.items)assert.deepEqual(Object.keys(item).sort(),['attempts','available_at','created_at','id','kind','status']);
 const anonymous=await browser.newContext(),unauth=await anonymous.request.get(base+'/wp-json/ascla/v1/admin/deliveries');assert.equal(unauth.status(),401);await anonymous.close();
 pass('Estado real protegido, sin destinatarios, credenciales ni contenido');
 let retried=false,retryRequests=0;
 await page.route('**/ascla/v1/admin/deliveries',async route=>route.fulfill({json:{counts:{pending:retried?0:1,processing:0,failed:retried?0:1},items:retried?[]:[{id:999999,kind:'calendar',status:'failed',attempts:8,created_at:'2026-10-13 00:00:00',available_at:'2026-10-13 00:00:00'},{id:999998,kind:'cache',status:'pending',attempts:3,created_at:'2026-10-14 00:00:00',available_at:'2026-10-14 00:00:00'}]}}));
 await page.route('**/ascla/v1/admin/deliveries/999999/retry',async route=>{assert.equal(route.request().method(),'POST');retryRequests++;retried=true;await route.fulfill({json:{counts:{pending:0,processing:0,failed:0},items:[]}});});
 await page.locator('[data-action="delivery-list"]').click();await page.getByText('#999999 · Google Calendar',{exact:true}).waitFor();
 assert.ok(await page.getByText('Requiere revisión · Intentos: 8',{exact:true}).isVisible());
 pass('Fallo simulado: identifica servicio, estado e intentos y permite reintentar');
 assert.ok(await page.getByText('#999998 · Actualización interna',{exact:true}).isVisible());assert.ok(await page.getByText('Pendiente · Intentos: 3',{exact:true}).isVisible());
 pass('Actualización interna pendiente identificada sin claves ni datos privados');
 await page.setViewportSize({width:390,height:844});
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true);
 await page.screenshot({path:root+'/test-results/delivery107-mobile.png'});
 pass('Controles de entrega legibles sin desbordamiento móvil');
 await page.locator('[data-action="delivery-retry"]').click();await page.getByText('No hay entregas pendientes.',{exact:true}).waitFor();assert.equal(retryRequests,1);
 pass('Reintento simulado: POST único y lista actualizada al finalizar');
 assert.deepEqual(errors,[]);pass('Sin errores JavaScript');
}catch(error){errors.push(error.message);console.error(error.stack);process.exitCode=1;}finally{
 fs.mkdirSync(root+'/test-results',{recursive:true});fs.writeFileSync(root+'/test-results/delivery107.json',JSON.stringify({checks,errors,ok:!errors.length,simulated_failure_rows:true},null,2));if(browser)await browser.close();
}})();
