/* Actual local WordPress content and reactions; no mocked REST responses. */
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Disposable local WordPress required');
const root=path.resolve(__dirname,'..'),base=process.env.ASCLA_SITE_URL||'http://ascla_audit-wordpress';
const f=JSON.parse(fs.readFileSync(root+'/test-results/follow-removal108-fixture.json','utf8'));
const checks=[],errors=[],views=[];let browser,page;
const pass=name=>{checks.push(name);console.log('PASS '+name);};
async function api(route,body){return page.evaluate(async({route,body})=>{const r=await fetch(ASCLA.api+route,{method:body?'POST':'GET',headers:{'X-WP-Nonce':ASCLA.nonce,'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})});return {status:r.status,body:await r.json()};},{route,body});}
async function open(p){await page.goto(base+'/'+p.page+'/?item='+p.id);await page.locator('.modal').getByRole('heading',{name:p.title,exact:true}).waitFor();}
(async()=>{try{
 browser=await chromium.launch({headless:true});page=await (await browser.newContext({viewport:{width:1440,height:1000}})).newPage();page.on('pageerror',e=>errors.push(e.message));
 await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill(f.login);await page.locator('#user_pass').fill(f.password);
 await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#wp-submit').click()]);
 for(const locale of ['es_ES','en_US']){
   if(locale==='en_US')await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('.header-language select[name="_ascla_locale"]').selectOption(locale)]);
   for(const [type,p] of Object.entries(f.posts)){
     await open(p);
     assert.equal(await page.locator('[data-action=follow]').count(),0);
     assert.doesNotMatch(await page.locator('.modal').innerText(),/Seguir conversación|Dejar de seguir|Follow conversation|Unfollow/);
     const detail=await api('items/'+p.id);assert.equal(detail.status,200);assert.equal(Object.hasOwn(detail.body,'following'),false);
     if(locale==='es_ES'&&type!=='ally'){
       const like=page.locator('.modal [data-action=content-reaction][data-comment="false"][data-reaction=like]');
       await Promise.all([page.waitForResponse(r=>r.url().includes('/items/'+p.id+'/reaction')&&r.request().method()==='POST'),like.click()]);
       assert.equal((await api('items/'+p.id)).body.liked,true);
     }
     pass(type+': sin seguimiento en '+locale+(locale==='es_ES'&&type!=='ally'?', Me gusta operativo':''));
   }
 }
 await open(f.posts.topic);const form=page.locator('[data-form=comment]');await form.locator('[name=body]').fill('Aporte local tras retirar seguimiento');
 const submitted=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes('/items/'+f.posts.topic.id+'/comments'));
 await form.locator('button[type=submit],button:not([type])').click();assert.equal((await submitted).status(),200);
 await page.locator('#comments-list').getByText('Aporte local tras retirar seguimiento',{exact:true}).waitFor();pass('Publicar comentarios sigue operativo sin seguimiento');
 assert.equal((await api('items/'+f.posts.topic.id+'/reaction',{kind:'follow',active:true})).status,400);pass('Una petición directa de seguimiento se rechaza');
 for(const [name,width,theme] of [['desktop-dark',1440,'dark'],['mobile-light',390,'light']]){
   await page.setViewportSize({width,height:1000});await page.evaluate(theme=>{document.documentElement.dataset.asclaTheme=theme;document.documentElement.style.colorScheme=theme;},theme);
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);
   const file='follow-removal108-'+name+'.png';await page.locator('.modal').screenshot({path:root+'/test-results/'+file,animations:'disabled'});views.push(file);
 }
 assert.deepEqual(errors,[]);
}catch(error){errors.push(error.message);console.error(error.stack);if(page)await page.screenshot({path:root+'/test-results/follow-removal108-failure.png',fullPage:true}).catch(()=>{});process.exitCode=1;}finally{
 if(browser)await browser.close();fs.writeFileSync(root+'/test-results/follow-removal108.json',JSON.stringify({scope:'Disposable local WordPress; seven content types in Spanish/English, actual likes/comments and rejected follow request; no remote deployment',checks,views,errors},null,2)+'\n');
}})();
