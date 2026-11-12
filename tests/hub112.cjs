/* Hub navigation, publication and draft regression on disposable WordPress.
 * Uses an ASCLA_ACCEPTANCE_FIXTURE with base, password and actors.member/member1. */
const {chromium}=require('playwright');
const fs=require('node:fs'),assert=require('node:assert/strict');
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Disposable environment required');
const f=JSON.parse(fs.readFileSync(process.env.ASCLA_ACCEPTANCE_FIXTURE,'utf8'));
const actor=f.actors.member||f.actors.member1,created=[],errors=[];
let browser,page;
async function api(route,body,method){
 return page.evaluate(async({route,body,method})=>{
  const r=await fetch(ASCLA.api+route,{method:method||(body?'POST':'GET'),headers:{'Content-Type':'application/json','X-WP-Nonce':ASCLA.nonce},...(body?{body:JSON.stringify(body)}:{})});
  return {status:r.status,body:await r.json()};
 },{route,body,method});
}
(async()=>{try{
 browser=await chromium.launch({channel:process.env.ASCLA_BROWSER_CHANNEL||'chrome',headless:true});
 page=await browser.newPage({viewport:{width:1280,height:940}});
 page.setDefaultTimeout(15000);page.on('pageerror',e=>errors.push(e.message));
 await page.goto(f.base+'/wp-login.php');await page.locator('#user_login').fill(actor.login);await page.locator('#user_pass').fill(actor.password||f.password);
 await Promise.all([page.waitForURL(u=>!u.pathname.endsWith('wp-login.php')),page.locator('#wp-submit').click()]);
 await page.locator('#page-content h1').waitFor();
 const loaded=page.waitForResponse(r=>r.url().includes('/content/hub?'));
 await page.locator('.ascla-sidebar a[href*="/hub/"]').click();
 assert.equal((await loaded).status(),200);await page.locator('#page-content h1').filter({hasText:'Hub ASCLA'}).waitFor();
 assert.equal(await page.getByText('Sección no válida.',{exact:true}).count(),0);
 console.log('PASS Hub opens through the sidebar with the valid content route.');
 await page.locator('#page-content [data-action=editor][data-type=hub]').click();
 let form=page.locator('[data-form=editor]');
 const title='Aporte Hub '+Date.now();await form.locator('[name=title]').fill(title);await form.locator('[name=body]').fill('Experiencias y aprendizajes de la comunidad.');
 await form.locator('[name=status]').selectOption('publish');
 const save=page.waitForResponse(r=>r.url().includes('/content/hub')&&r.request().method()==='POST');
 await form.locator('[data-editor-primary]').click();const response=await save,result=await response.json();assert.equal(response.status(),200);created.push(result.id);
 await page.locator('.feed-card').filter({hasText:title}).waitFor();await page.reload();await page.locator('.feed-card').filter({hasText:title}).waitFor();
 console.log('PASS A publication can be created and stays visible after reload.');
 await page.locator('#page-content [data-action=editor][data-type=hub]').click();form=page.locator('[data-form=editor]');
 const draftTitle='Borrador Hub '+Date.now();await form.locator('[name=title]').fill(draftTitle);
 const savedDraft=page.waitForResponse(r=>r.url().includes('/content/hub')&&r.request().method()==='POST');
 await form.locator('[data-editor-draft]').click();const draft=await (await savedDraft).json();created.push(draft.id);assert.equal(draft.status,'draft');
 await page.locator('[data-action=filter-drafts]').click();await page.locator('[data-draft-id="'+draft.id+'"]').waitFor();
 await page.locator('[data-draft-id="'+draft.id+'"] [data-action=editor]').click();form=page.locator('[data-form=editor]');
 await form.locator('[name=body]').fill('Borrador completado para compartirlo con la comunidad.');await form.locator('[name=status]').selectOption('publish');
 const published=page.waitForResponse(r=>r.url().includes('/content/hub/'+draft.id)&&r.request().method()==='POST');
 await form.locator('[data-editor-primary]').click();assert.equal((await published).status(),200);
 await page.locator('[data-action=filter-all]').click();await page.locator('.feed-card').filter({hasText:draftTitle}).waitFor();
 await page.locator('[data-action=filter-mine]').click();await page.locator('.feed-card').filter({hasText:title}).waitFor();
 assert.deepEqual(errors,[]);console.log('PASS Drafts can be opened, completed and published, and My publications loads.');
}finally{
 if(page)for(const id of created)await api('items/'+id,undefined,'DELETE').catch(()=>{});
 if(browser)await browser.close();
}})().catch(e=>{console.error(e);process.exitCode=1});
