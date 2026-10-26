/* Real disposable WordPress: Contact submission/history and native admin capability. */
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Disposable local environment required');
const root=path.resolve(__dirname,'..'),base=process.env.ASCLA_SITE_URL||'http://ascla_audit-wordpress';
const fixture=JSON.parse(fs.readFileSync(root+'/test-results/contact-layout108-fixture.json','utf8'));
const checks=[],errors=[],views=[];let browser;
const pass=name=>{checks.push(name);console.log('PASS '+name);};
async function login(role){
 const context=await browser.newContext({viewport:{width:1440,height:1100}}),page=await context.newPage();
 page.on('pageerror',error=>errors.push(error.message));await page.goto(base+'/wp-login.php');
 await page.locator('#user_login').fill(fixture.actors[role].login);await page.locator('#user_pass').fill(fixture.actors[role].password);
 await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#wp-submit').click()]);return page;
}
async function capture(page,name,width,theme,scope){
 await page.setViewportSize({width,height:1100});await page.evaluate(value=>{localStorage.setItem('ascla-theme',value);document.documentElement.dataset.asclaTheme=value;document.documentElement.style.colorScheme=value;window.scrollTo(0,0);},theme);
 await page.evaluate(async()=>{await new Promise(requestAnimationFrame);await new Promise(requestAnimationFrame);});
 await page.mouse.move(0,0);
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,name+' overflow');
 await page.screenshot({path:root+`/test-results/${name}.png`,fullPage:true,animations:'disabled'});
 await page.addScriptTag({path:require.resolve('axe-core')});
 const violations=await page.evaluate(async selector=>(await axe.run(document.querySelector(selector),{runOnly:{type:'tag',values:['wcag2a','wcag2aa']}})).violations.map(v=>({id:v.id,impact:v.impact,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))})),scope);
 assert.deepEqual(violations,[],name+' automatic WCAG checks');views.push({name,width,theme,overflow:false,violations});
}
(async()=>{try{
 browser=await chromium.launch({headless:true});const member=await login('ascla_member');
 await member.goto(base+'/contacto/');await member.locator('.contact-form').waitFor();await member.keyboard.press('Escape');
 assert.equal(await member.evaluate(()=>ASCLA.wpAdminUrl),'');
 assert.equal(await member.locator('.contact-form').evaluate(form=>form.checkValidity()),false);
 const request=member.locator('.contact-request').filter({hasText:'Actualización de mis datos profesionales'});
 assert.equal(await request.locator('.contact-status').innerText(),'Resuelta');await request.locator('.request-history summary').click();
 await request.getByText('Tus datos se actualizaron correctamente.',{exact:true}).waitFor();
 await member.locator('.contact-question summary').first().click();
 assert.equal(await member.locator('.contact-question').first().getAttribute('open'),'');pass('Formulario obligatorio, preguntas desplegables e historial real conservados');
 for(const [name,width,theme] of [['contact108-light',1440,'light'],['contact108-dark',1440,'dark'],['contact108-mobile-light',390,'light'],['contact108-mobile-dark',390,'dark']])await capture(member,name,width,theme,'.contact-workspace');
 pass('Contacto: cuatro vistas sin desbordamiento ni infracciones automáticas WCAG A/AA');
 await member.locator('[data-form=contact] [name=title]').fill('Consulta de prueba '+fixture.prefix);
 await member.locator('[data-form=contact] [name=body]').fill('Necesito orientación para consultar los recursos de ASCLA.');
 assert.equal(await member.locator('[name=title]').getAttribute('maxlength'),'200');assert.equal(await member.locator('[name=body]').getAttribute('maxlength'),'5000');
 const response=member.waitForResponse(r=>r.url().includes('/content/contact')&&r.request().method()==='POST');
 await member.locator('[data-form=contact] button[type=submit]').click();assert.equal((await response).status(),200);
 const submitted=member.locator('.contact-request').filter({hasText:'Consulta de prueba '+fixture.prefix});await submitted.waitFor();
 assert.equal(await submitted.locator('.contact-status').innerText(),'Pendiente');await member.reload();await submitted.waitFor();pass('Envío real conserva la solicitud y el estado tras recargar');
 const admin=await login('administrator');await admin.goto(base+'/administracion/');await admin.locator('.admin-hero').waitFor();await admin.keyboard.press('Escape');
 const native=admin.locator('.admin-wordpress-link');assert.equal(new URL(await native.getAttribute('href')).pathname,'/wp-admin/');
 const colors=[];
 for(const [name,width,theme] of [['admin-actions108-light',1440,'light'],['admin-actions108-dark',1440,'dark'],['admin-actions108-mobile-light',390,'light'],['admin-actions108-mobile-dark',390,'dark']]){
  await capture(admin,name,width,theme,'.admin-hero');
  const color=await admin.locator('.admin-intranet-link').evaluate(el=>getComputedStyle(el).backgroundColor);colors.push(color);
  assert.equal(color,theme==='dark'?'rgb(23, 34, 45)':'rgb(255, 255, 255)');
 }
 assert.notEqual(colors[0],colors[1]);pass('Ver intranet cambia de color con el tema; ambos accesos son adaptables y accesibles');
 await native.click();await admin.locator('#wpbody-content').waitFor();assert.equal(new URL(admin.url()).pathname,'/wp-admin/');pass('El acceso WordPress abre el escritorio nativo con permiso real');
 for(const role of ['ascla_executive','ascla_moderator']){
  const page=await login(role);await page.goto(base+'/administracion/');await page.locator('.admin-hero').waitFor();
  assert.equal(await page.evaluate(()=>ASCLA.wpAdminUrl),'');assert.equal(await page.locator('.admin-wordpress-link').count(),0);assert.equal(await page.locator('.admin-intranet-link').count(),1);
  await page.goto(base+'/wp-admin/');assert.equal(new URL(page.url()).pathname,'/administracion/');
 }
 pass('Ejecutivo y moderador mantienen administración ASCLA sin acceso al escritorio WordPress');
 await member.goto(base+'/wp-admin/');assert.equal(new URL(member.url()).pathname,'/intranet/');pass('Un asociado mantiene la redirección de seguridad al intentar entrar en WordPress');
 await member.goto(base+'/contacto/');await member.locator('.contact-form').waitFor();
 await Promise.all([member.waitForNavigation({waitUntil:'domcontentloaded'}),member.locator('.header-language select[name="_ascla_locale"]').selectOption('en_US')]);
 await member.getByRole('heading',{name:'Send us your question',exact:true}).waitFor();await member.getByRole('button',{name:'Send request',exact:true}).waitFor();
 await member.getByRole('heading',{name:'Frequently asked questions',exact:true}).waitFor();await member.getByText('How do I update my interests?',{exact:true}).waitFor();
 assert.equal(await member.locator('.contact-request').filter({hasText:'Consulta de prueba '+fixture.prefix}).locator('.contact-status').innerText(),'Pending');pass('Contacto y estados se traducen al cambiar el idioma');
 assert.deepEqual(errors,[]);
}catch(error){errors.push(error.message);console.error(error.stack);process.exitCode=1;}finally{
 if(browser)await browser.close();fs.writeFileSync(root+'/test-results/contact-layout108.json',JSON.stringify({scope:'Real disposable local WordPress; no public QA deployment or full acceptance claim',checks,views,errors},null,2)+'\n');
}})();
