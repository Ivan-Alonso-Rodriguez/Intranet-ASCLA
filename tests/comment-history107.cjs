const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),crypto=require('node:crypto');
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Disposable environment required');
const root=path.resolve(__dirname,'..'),base=process.env.ASCLA_SITE_URL||'http://ascla_audit-wordpress';
const env=Object.fromEntries(fs.readFileSync(process.env.ASCLA_ENV_FILE||root+'/.env.audit','utf8').split(/\r?\n/).filter(v=>v.includes('=')).map(v=>[v.slice(0,v.indexOf('=')),v.slice(v.indexOf('=')+1)]));
const checks=[],errors=[],posts=[];let browser,page;
async function api(route,body,method){return page.evaluate(async({route,body,method})=>{const r=await fetch(ASCLA.api+route,{method:method||(body?'POST':'GET'),headers:{'X-WP-Nonce':ASCLA.nonce,'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})});return {status:r.status,body:await r.json()};},{route,body,method});}
const pass=name=>{checks.push(name);console.log('PASS '+name);};
(async()=>{try{
 browser=await chromium.launch({headless:true});page=await (await browser.newContext({viewport:{width:1440,height:1000}})).newPage();page.on('pageerror',e=>errors.push(e.message));
 await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill('ascla.admin');await page.locator('#user_pass').fill(env.ASCLA_ADMIN_PASSWORD);
 await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#wp-submit').click()]);
 await page.goto(base+'/foros/');await page.locator('[data-action="filter-comments"]').waitFor();
 const token=crypto.randomBytes(5).toString('hex'),title='Tema propio '+token,body='Comentario propio '+token;
 const result=await api('content/topic',{title,body:'Conversación de prueba',status:'publish'});assert.equal(result.status,200);const id=result.body.id;posts.push(id);
 const comment=await api('items/'+id+'/comments',{body});assert.equal(comment.status,200);
 await page.locator('[data-action="filter-mine"]').click();await page.getByRole('heading',{name:title,exact:true}).waitFor();assert.ok(await page.getByRole('button',{name:'Mis temas',exact:true}).isVisible());
 pass('Mis temas conserva las publicaciones propias separadas de comentarios');
 await page.locator('[data-action="filter-comments"]').click();const form=page.locator('[data-form="filters"]');await form.locator('[name="q"]').fill(token);await form.locator('button:not([type])').click();
 const entry=page.locator('[data-own-comment]').filter({hasText:body});await entry.waitFor();assert.equal(await page.locator('[data-own-comment]').count(),1);assert.ok(await page.getByText('Fecha del comentario',{exact:true}).isVisible());
 pass('Mis comentarios busca aportes propios y muestra el tema original y fechas');
 await entry.locator('[data-action="item"]').click();await page.locator('[role="dialog"]').getByText(body,{exact:true}).waitFor();await page.keyboard.press('Escape');
 pass('Ver conversación abre el tema y conserva el comentario en su contexto');
 await page.locator('[data-action="filter-reset"]').click();await page.getByRole('heading',{name:'Mis comentarios',exact:true}).waitFor();
 await page.setViewportSize({width:390,height:844});await page.waitForFunction(()=>document.querySelector('.ascla-sidebar').getBoundingClientRect().right<=1);
 const layout=await page.evaluate(()=>({width:innerWidth,overflow:[...document.querySelectorAll('#page-content input,#page-content button,#page-content .own-comment')].filter(el=>el.getBoundingClientRect().right>innerWidth+1).map(el=>({tag:el.tagName,action:el.dataset.action,name:el.name,right:el.getBoundingClientRect().right})),parents:['#ascla-root','.ascla-main','.page-wrap','#page-content'].map(selector=>{const el=document.querySelector(selector);return {selector,width:el.getBoundingClientRect().width,right:el.getBoundingClientRect().right};})}));
 if(layout.overflow.length)console.log(JSON.stringify(layout));assert.deepEqual(layout.overflow,[]);await page.screenshot({path:root+'/test-results/comment-history107-mobile.png'});
 pass('Limpiar filtros conserva la sección y el historial funciona en móvil');
 assert.deepEqual(errors,[]);pass('Sin errores JavaScript');
}catch(error){errors.push(error.message);console.error(error.stack);process.exitCode=1;}finally{
 if(page)for(const id of posts)await api('items/'+id,null,'DELETE').catch(()=>{});
 fs.mkdirSync(root+'/test-results',{recursive:true});fs.writeFileSync(root+'/test-results/comment-history107.json',JSON.stringify({checks,errors,ok:!errors.length},null,2));if(browser)await browser.close();
}})();
