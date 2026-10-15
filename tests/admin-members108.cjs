/* Real local REST data from admin-members-fixture.php; no mocked member metrics. */
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Disposable environment required');
const root=path.resolve(__dirname,'..'),base=process.env.ASCLA_SITE_URL||'http://ascla_audit-wordpress';
const fixture=JSON.parse(fs.readFileSync(root+'/test-results/admin-members108-fixture.json','utf8'));
const env=Object.fromEntries(fs.readFileSync(root+'/.env.audit','utf8').split(/\r?\n/).filter(v=>v.includes('=')).map(v=>[v.slice(0,v.indexOf('=')),v.slice(v.indexOf('=')+1)]));
const checks=[],errors=[];let browser,page,originalLocale;
const pass=name=>{checks.push(name);console.log('PASS '+name);};
async function openUser(id){await page.locator(`[data-action=user-insights][data-id="${id}"]`).click();await page.locator('.member-metrics').waitFor();}
async function ready(){await page.waitForFunction(()=>!document.querySelector('#admin-panel')?.hasAttribute('aria-busy'));}
async function capture(name,width,theme){
 await page.setViewportSize({width,height:1000});await page.evaluate(value=>{localStorage.setItem('ascla-theme',value);document.documentElement.dataset.asclaTheme=value;document.documentElement.style.colorScheme=value;window.scrollTo(0,0);},theme);
 await page.evaluate(async()=>{await new Promise(requestAnimationFrame);await new Promise(requestAnimationFrame);await Promise.all(document.getAnimations().map(animation=>animation.finished.catch(()=>{})));});
 assert.equal(await page.locator('.admin-intranet-link').evaluate(button=>getComputedStyle(button).backgroundColor),theme==='dark'?'rgb(23, 34, 45)':'rgb(255, 255, 255)','Intranet action adapts to the selected theme');
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,`${name} overflow`);
 await page.screenshot({path:root+`/test-results/${name}.png`,fullPage:true,animations:'disabled'});
 if(name==='admin108-member-light') await page.screenshot({path:root+'/test-results/admin108-member-preview.png',fullPage:false,animations:'disabled'});
}
(async()=>{try{
 browser=await chromium.launch({headless:true});page=await browser.newPage({viewport:{width:1440,height:1000}});page.on('pageerror',error=>errors.push(error.message));
 await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill('ascla.admin');await page.locator('#user_pass').fill(env.ASCLA_ADMIN_PASSWORD);
 await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#wp-submit').click()]);
 await page.goto(base+'/administracion/');await page.locator('.admin-task-grid').waitFor();await page.keyboard.press('Escape');
 originalLocale=await page.locator('.header-language select[name="_ascla_locale"]').inputValue();
 await capture('admin108-overview-light',1440,'light');await capture('admin108-overview-dark-mobile',390,'dark');pass('Resumen por tareas, navegación agrupada y adaptación a móvil sin desbordamiento');
 await page.setViewportSize({width:1440,height:1000});await page.locator('.admin-task-card [data-tab=usuarios]').click();
 await page.locator('[data-form=admin-filter][data-area=users] input[name=q]').fill(fixture.query);await page.locator('[data-form=admin-filter][data-area=users]').evaluate(form=>form.requestSubmit());
 await page.waitForFunction(()=>document.querySelectorAll('[data-admin-user]').length===2);await openUser(fixture.user);
 assert.equal(await page.locator('[data-member-action=filter][data-kind=likes] strong').textContent(),String(fixture.likes));
 assert.equal(await page.locator('[data-member-action=filter][data-kind=comments] strong').textContent(),String(fixture.comments));
 await page.locator('.member-interest-chip').first().waitFor();await page.locator('.member-liked-topics li').first().waitFor();pass('Ficha por asociado muestra cifras reales y separa intereses declarados de temas con Me gusta');
 await capture('admin108-member-light',1440,'light');await capture('admin108-member-dark',1440,'dark');await capture('admin108-member-dark-mobile',390,'dark');
 await page.locator('[data-member-action=filter][data-kind=likes]').click();await ready();assert.equal(await page.locator('.member-activity-list li').count(),20);
 await page.locator('[data-member-action=page][data-page="2"]').click();await ready();assert.equal(await page.locator('.member-activity-list li').count(),4);pass('Filtro de Me gusta y paginación real conservan los 24 registros');
 await page.locator('[data-member-period]').selectOption('30');await ready();assert.equal(await page.locator('.member-activity-list li').count(),20);pass('Cambiar de período restablece la primera página');
 await page.locator('[data-member-action=back]').click();await page.locator('[data-form=admin-filter]').waitFor();assert.equal(await page.locator('[data-area=users] input[name=q]').inputValue(),fixture.query);
 await openUser(fixture.empty_user);assert.equal(await page.locator('[data-kind=likes] strong').textContent(),'0');assert.equal(await page.locator('.member-activity-list').count(),0);assert.ok((await page.locator('.member-declared').innerText()).includes('Aún no hay intereses en este perfil.'));pass('Volver conserva la búsqueda y un asociado sin actividad muestra estados vacíos claros');
 await page.route('**/admin/users/*/insights?**',route=>route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({message:'Fallo temporal controlado'})}));
 await page.locator('[data-member-period]').selectOption('30');await page.locator('[data-member-action=retry]').waitFor();assert.equal(await page.locator('.member-metrics').count(),0);pass('Un fallo de API ofrece reintento sin presentar cifras falsas');
 await page.unroute('**/admin/users/*/insights?**');await page.locator('[data-member-action=retry]').click();await page.locator('.member-metrics').waitFor();
 const axe=require('axe-core');await page.addScriptTag({path:require.resolve('axe-core')});
 const accessibility=await page.evaluate(async()=>await axe.run(document.querySelector('.admin-workspace'),{runOnly:{type:'tag',values:['wcag2a','wcag2aa']}}));
 assert.deepEqual(accessibility.violations.map(v=>({id:v.id,impact:v.impact,nodes:v.nodes.length})),[]);pass('Panel sin infracciones automáticas WCAG A/AA en el alcance revisado');
 await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('.header-language select[name="_ascla_locale"]').selectOption('en_US')]);
 await page.locator('.admin-task-grid').waitFor();assert.ok((await page.locator('.admin-nav-group').allTextContents()).join(' ').includes('Users and interests'));
 await page.locator('.admin-task-card [data-tab=usuarios]').click();await page.locator('[data-area=users] input[name=q]').fill(fixture.query);await page.locator('[data-form=admin-filter][data-area=users]').evaluate(form=>form.requestSubmit());
 await page.waitForFunction(()=>document.querySelectorAll('[data-admin-user]').length===2);await openUser(fixture.user);assert.ok((await page.locator('.member-insights').innerText()).includes('What they share in their profile'));
 assert.equal(await page.locator('[data-kind=likes] strong').textContent(),String(fixture.likes));pass('Cambio real de idioma traduce la ficha y conserva las cifras');
 assert.deepEqual(errors,[]);
}catch(error){errors.push(error.message);console.error(error.stack);process.exitCode=1;}finally{
 if(page && originalLocale && !page.isClosed()){
  try{if(await page.locator('.header-language select[name="_ascla_locale"]').inputValue()!==originalLocale) await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('.header-language select[name="_ascla_locale"]').selectOption(originalLocale)]);}catch(error){errors.push('Could not restore local account locale: '+error.message);process.exitCode=1;}
 }
 if(browser)await browser.close();fs.writeFileSync(root+'/test-results/admin-members108.json',JSON.stringify({scope:'Real disposable local WordPress data; no public QA deployment claim',checks,errors},null,2));
}})();
