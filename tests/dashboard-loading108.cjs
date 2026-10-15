/* Controlled slow/error responses verify that optional recommendations cannot block home. */
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Disposable environment required');
const root=path.resolve(__dirname,'..'),base=process.env.ASCLA_SITE_URL||'http://ascla_audit-wordpress';
const env=Object.fromEntries(fs.readFileSync(process.env.ASCLA_ENV_FILE||root+'/.env.audit','utf8').split(/\r?\n/).filter(v=>v.includes('=')).map(v=>[v.slice(0,v.indexOf('=')),v.slice(v.indexOf('=')+1)]));
const checks=[],errors=[];let browser,page,mode='hold';const held=[];
const pass=name=>{checks.push(name);console.log('PASS '+name);};
const disabled={eligible:false,reason:'disabled',missing:[],completion:100,people:[],limit:5,low_match:false};
async function clickAfterReview(locator){
 try{await locator.click({timeout:2000});}catch(error){
  // An existing account may receive its periodic profile-review dialog after home renders.
  if(!await page.locator('.modal-backdrop').isVisible())throw error;
  await page.keyboard.press('Escape');await locator.click();
 }
}
(async()=>{try{
 browser=await chromium.launch({headless:true});page=await browser.newPage({viewport:{width:1280,height:900}});page.on('pageerror',error=>errors.push(error.message));
 await page.route(url=>url.pathname.endsWith('/ascla/v1/recommendations')||url.searchParams.get('rest_route')==='/ascla/v1/recommendations',async route=>{
  const responseMode=mode;
  if(responseMode==='hold')await new Promise(resolve=>held.push(resolve));
  await route.fulfill({status:responseMode==='error'?503:200,contentType:'application/json',body:JSON.stringify(responseMode==='error'?{message:'Controlled QA failure'}:disabled)}).catch(()=>{});
 });
 await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill('ascla.admin');await page.locator('#user_pass').fill(env.ASCLA_ADMIN_PASSWORD);
 await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#wp-submit').click()]);
 await page.goto(base+'/intranet/');await page.locator('.hero h1').waitFor({timeout:10000});
 assert.ok(held.length);await page.waitForFunction(()=>!document.querySelector('#page-content').hasAttribute('aria-busy'));await page.keyboard.press('Escape');
 await page.locator('#dashboard-recommendations [role=status]').waitFor();assert.equal(await page.locator('.stat-grid .stat').count(),4);
 await page.screenshot({path:root+'/test-results/dashboard-loading108-pending.png',fullPage:true});pass('Inicio, estadísticas y agenda disponibles mientras recomendaciones permanece sin responder');
 await clickAfterReview(page.locator('.ascla-sidebar .nav-link[href$="/perfil/"]'));
 await page.locator('[data-form=profile]').waitFor();
 for(const release of held.splice(0))release();await page.locator('[name=company]').waitFor();assert.equal(await page.locator('#dashboard-recommendations').count(),0);pass('Navegar a Perfil cancela la consulta y una respuesta tardía no reemplaza la nueva vista');
 mode='error';await page.goto(base+'/intranet/');await page.locator('.hero h1').waitFor();await page.locator('#dashboard-recommendations [data-action=refresh]').waitFor();
 assert.equal(await page.locator('.stat-grid .stat').count(),4);assert.equal(await page.locator('#page-content > .error').count(),0);pass('Un error de recomendaciones queda limitado a su bloque y permite reintentar');
 await page.setViewportSize({width:390,height:844});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);await page.screenshot({path:root+'/test-results/dashboard-loading108-error-mobile.png',fullPage:true});pass('Aviso de error y reintento sin desbordamiento en móvil');
 mode='success';await clickAfterReview(page.locator('#dashboard-recommendations [data-action=refresh]'));await page.locator('#dashboard-recommendations .recommendations-gate').waitFor();
 assert.equal(await page.locator('#dashboard-recommendations [role=status]').count(),0);assert.equal(await page.locator('.hero h1').count(),1);pass('Reintentar sustituye el aviso por la respuesta y conserva el contenido de Inicio');
 assert.deepEqual(errors,[]);
}catch(error){errors.push(error.message);console.error(error.stack);process.exitCode=1;}finally{
 for(const release of held.splice(0))release();
 if(browser)await browser.close();fs.writeFileSync(root+'/test-results/dashboard-loading108.json',JSON.stringify({scope:'Injected delayed/error responses on disposable local WordPress; no provider performance claim',checks,errors},null,2));
}})();
