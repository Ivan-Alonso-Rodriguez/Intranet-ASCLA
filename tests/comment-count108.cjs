/* Real disposable WordPress posts/comments; no mocked submission or total. */
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Disposable local environment required');
const root=path.resolve(__dirname,'..'),base=process.env.ASCLA_SITE_URL||'http://ascla_audit-wordpress';
const f=JSON.parse(fs.readFileSync(root+'/test-results/comment-count108-fixture.json','utf8'));
const checks=[],errors=[];let browser,page,navigations=0;
const pass=name=>{checks.push(name);console.log('PASS '+name);};
const card=id=>page.locator('.feed-card').filter({has:page.locator(`[data-action=item][data-id="${id}"]`)});
const counter=id=>card(id).locator(`[data-action=item][data-id="${id}"]`).filter({hasText:/\b(?:comentarios?|comments?)\b/});
async function login(role){
 const p=await (await browser.newContext({viewport:{width:1440,height:1000}})).newPage();p.on('pageerror',error=>errors.push(error.message));
 if(process.env.ASCLA_BASELINE_APP)await p.route(url=>url.pathname.endsWith('/ascla-core/assets/app.js'),route=>route.fulfill({status:200,contentType:'application/javascript',body:fs.readFileSync(process.env.ASCLA_BASELINE_APP,'utf8')}));
 await p.goto(base+'/wp-login.php');await p.locator('#user_login').fill(f.actors[role].login);await p.locator('#user_pass').fill(f.actors[role].password);
 await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}),p.locator('#wp-submit').click()]);return p;
}
async function submit(form,text){
 const before=await form.elementHandle();await form.locator('[name=body]').fill(text);
 const pending=page.waitForResponse(r=>r.request().method()==='POST'&&/\/items\/\d+\/comments/.test(r.url()));
 await form.locator('button[type=submit],button:not([type])').click();const response=await pending;assert.equal(response.status(),200);
 await page.waitForFunction(el=>!el.isConnected,before);await page.locator('[data-form=comment]').waitFor();return response.json();
}
async function close(mode='escape'){
 if(mode==='button')await page.locator('.modal-top [data-action=close]').click();
 else if(mode==='backdrop')await page.locator('.modal-backdrop').click({position:{x:3,y:3}});
 else await page.keyboard.press('Escape');
 await page.locator('.modal-backdrop').waitFor({state:'detached'});
}
async function api(p,route,body){return p.evaluate(async({route,body})=>{const r=await fetch(ASCLA.api+route,{method:body?'POST':'GET',headers:{'X-WP-Nonce':ASCLA.nonce,'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})});return {status:r.status,body:await r.json()};},{route,body});}
(async()=>{try{
 browser=await chromium.launch({headless:true,...(process.env.ASCLA_BROWSER_CHANNEL?{channel:process.env.ASCLA_BROWSER_CHANNEL}:{})});page=await login('ascla_member');
 await page.goto(base+'/foros/?q='+encodeURIComponent(f.prefix));await counter(f.posts.main.id).waitFor();await page.keyboard.press('Escape');
 page.on('framenavigated',frame=>{if(frame===page.mainFrame())navigations++;});
 await counter(f.posts.main.id).evaluate(el=>{el.dataset.originalCounter='true';});
 assert.equal(await counter(f.posts.main.id).innerText(),'0 comentarios');await counter(f.posts.main.id).click();
 const first=await submit(page.locator('[data-form=comment]'),'Mi primer aporte al foro');assert.equal(first.status,'publish');await close();
 assert.equal(await counter(f.posts.main.id).innerText(),'1 comentario');assert.equal(await counter(f.posts.main.id).getAttribute('data-original-counter'),'true');pass('Publicar y cerrar actualiza de cero a un comentario sin sustituir la tarjeta ni recargar');
 await counter(f.posts.main.id).click();await page.locator(`[data-comment-id="${first.id}"] [data-action=comment-reply]`).click();
 const reply=await submit(page.locator('[data-form=comment-reply]'),'Respuesta al primer comentario');assert.equal(reply.status,'publish');await close('button');
 assert.equal(await counter(f.posts.main.id).innerText(),'2 comentarios');assert.equal(await counter(f.posts.other.id).innerText(),'2 comentarios');pass('Una respuesta actualiza solo el tema correspondiente y usa el plural');
 await counter(f.posts.main.id).click();await page.locator(`[data-comment-thread="${first.id}"] [data-action=comment-expand]`).click();await page.locator(`[data-comment-id="${reply.id}"] summary`).click();await page.locator(`[data-comment-id="${reply.id}"] [data-action=delete-comment]`).click();
 const removed=page.waitForResponse(r=>r.request().method()==='DELETE'&&r.url().includes('/comments/'+reply.id));await page.locator('[data-action=delete-confirm]').click();assert.equal((await removed).status(),200);
 await page.waitForFunction(id=>document.querySelector(`[data-comment-count="${id}"]`)?.textContent==='1 comentario',f.posts.main.id);await page.locator('[data-form=comment]').waitFor();await close('backdrop');assert.equal(await counter(f.posts.main.id).innerText(),'1 comentario');pass('Eliminar una respuesta disminuye el contador al cerrar por el fondo');
 assert.equal(await page.locator('[data-form=filters] [name=q]').inputValue(),f.prefix);assert.equal(navigations,0);pass('Se conservan la búsqueda y el documento durante publicación, respuesta y eliminación');
 await counter(f.posts.large.id).click();await page.locator('#comments-list .comment').first().waitFor();while(await page.locator('[data-action=comment-more]').count())await page.locator('[data-action=comment-more]').click();await page.locator('#comments-list .comment').nth(99).waitFor();assert.equal(await page.locator('#comments-list .comment').count(),100);
 await submit(page.locator('[data-form=comment]'),'Nuevo aporte fuera de los primeros cien');await close();assert.equal(await counter(f.posts.large.id).innerText(),'102 comentarios');pass('El total usa los 102 comentarios del servidor aunque el detalle solo cargue cien');
 await page.goto(base+'/hub/?q='+encodeURIComponent(f.prefix));await counter(f.posts.hub.id).waitFor();await counter(f.posts.hub.id).click();
 const pending=await submit(page.locator('[data-form=comment]'),'Comentario que requiere revisión');assert.equal(pending.status,'pending');await close();assert.equal(await counter(f.posts.hub.id).innerText(),'0 comentarios');pass('El Hub comparte la actualización y los comentarios pendientes no aumentan el total publicado');
 const admin=await login('administrator');await admin.goto(base+'/administracion/');await admin.locator('.admin-hero').waitFor();
 assert.equal((await api(admin,'admin/comments/'+pending.id,{decision:'approve',reason:'Verificación local del contador'})).status,200);
 await counter(f.posts.hub.id).click();await page.locator('#comments-list').getByText('Comentario que requiere revisión',{exact:true}).waitFor();await close();assert.equal(await counter(f.posts.hub.id).innerText(),'1 comentario');pass('Abrir el detalle sincroniza también un comentario aprobado por moderación');
 await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('.header-language select[name="_ascla_locale"]').selectOption('en_US')]);
 await page.goto(base+'/foros/?q='+encodeURIComponent(f.prefix));await counter(f.posts.main.id).waitFor();assert.equal(await counter(f.posts.main.id).innerText(),'1 comment');
 await counter(f.posts.main.id).click();await submit(page.locator('[data-form=comment]'),'Another contribution');await close();assert.equal(await counter(f.posts.main.id).innerText(),'2 comments');pass('El contador conserva singular y plural en inglés');
 await page.setViewportSize({width:390,height:844});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);
 await page.screenshot({path:root+'/test-results/comment-count108-mobile.png',fullPage:true,animations:'disabled'});assert.deepEqual(errors,[]);
}catch(error){errors.push(error.message);console.error(error.stack);if(page)await page.screenshot({path:root+'/test-results/comment-count108-failure.png',fullPage:true}).catch(()=>{});process.exitCode=1;}finally{
 if(browser)await browser.close();fs.writeFileSync(root+'/test-results/comment-count108.json',JSON.stringify({scope:'Disposable local WordPress, actual submissions/replies/deletion/moderation and totals; no remote deployment',baseline:!!process.env.ASCLA_BASELINE_APP,checks,errors},null,2)+'\n');
}})();
