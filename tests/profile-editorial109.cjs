/* Requested profile, chat and editorial retirement flows, without coverage collection. */
const {chromium}=require('playwright'),fs=require('node:fs'),assert=require('node:assert/strict'),crypto=require('node:crypto');
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Owned disposable local QA required');
const out=process.env.ASCLA_SECURITY_OUT,fixture=JSON.parse(fs.readFileSync(out+'/private-actors.json')),base=fixture.base;
if(!/^https:\/\/ascla-zap109-[a-z0-9-]+\.test$/.test(base))throw Error('Owned HTTPS fixture required');
const result={checks:[],errors:[],violations:[],screenshots:[]};
const assetReads=[],appHashes=[];
const test=async(name,run)=>{await run();result.checks.push(name);console.log('PASS '+name);};
const api=(page,path,body)=>page.evaluate(async({path,body})=>{
 const response=await fetch(ASCLA.api+path,{method:body?'POST':'GET',headers:{'Content-Type':'application/json','X-WP-Nonce':ASCLA.nonce},body:body?JSON.stringify(body):undefined});
 return {status:response.status,body:await response.json()};
},{path,body});
(async()=>{let browser;try{
 browser=await chromium.launch({headless:true});
 async function login(role){
  const context=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:1440,height:1050}});
  await context.addInitScript(()=>document.addEventListener('securitypolicyviolation',e=>{if(e.disposition==='enforce')console.error('CSP '+e.effectiveDirective+' '+e.blockedURI);}));
  const page=await context.newPage();page.on('pageerror',e=>result.errors.push(e.message));page.on('console',m=>{if(m.text().startsWith('CSP '))result.violations.push(m.text());});
  page.on('response',response=>{
   if(new URL(response.url()).pathname.endsWith('/ascla-core/assets/app.js')) assetReads.push(response.body().then(body=>appHashes.push(crypto.createHash('sha256').update(body).digest('hex'))));
  });
  await page.goto(base+'/login/');await page.locator('#user_login').fill(fixture.actors[role].login);await page.locator('#user_pass').fill(fixture.actors[role].password);
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#wp-submit').click()]);await page.locator('#ascla-root').waitFor();return page;
 }
 const admin=await login('Administrador'),member=await login('Asociado');
 await test('LinkedIn and X domains are validated in the profile UI and on the server',async()=>{
  await member.goto(base+'/perfil/');const linkedin=member.locator('[data-form=profile] [name=linkedin]'),twitter=member.locator('[data-form=profile] [name=twitter]');
  await linkedin.fill('https://linkedin.com.evil.example/in/persona');assert.equal(await linkedin.evaluate(i=>i.checkValidity()),false);
  await twitter.fill('https://example.com/user');assert.equal(await twitter.evaluate(i=>i.checkValidity()),false);
  await linkedin.fill('https://www.linkedin.com/in/ascla-prueba');await twitter.fill('https://x.com/ascla_prueba');
  assert.equal(await linkedin.evaluate(i=>i.checkValidity()),true);assert.equal(await twitter.evaluate(i=>i.checkValidity()),true);
  assert.equal((await api(member,'profiles/me',{linkedin:'https://example.com/user'})).status,400);
  assert.equal((await api(member,'profiles/me',{linkedin:'https://www.linkedin.com/in/ascla-prueba',twitter:'https://x.com/ascla_prueba'})).status,200);
  await member.reload();assert.equal(await member.locator('[name=linkedin]').inputValue(),'https://www.linkedin.com/in/ascla-prueba');
 });
 await test('Administration offers Individual and validates social links when editing a member',async()=>{
  await admin.goto(base+'/administracion/?section=usuarios');
  await admin.locator('[data-action=user-edit][data-id="'+fixture.actors.Asociado.id+'"]').click();
  const form=admin.locator('[data-form=admin-user-edit]');
  await form.waitFor();
  assert.ok((await form.locator('[name=member_type] option').allTextContents()).includes('Individual'));
  assert.ok(!(await form.locator('[name=member_type] option').allTextContents()).includes('Independiente'));
  await form.locator('summary').filter({hasText:'Biografía, experiencia y enlaces'}).click();
  await form.locator('[name=linkedin]').fill('https://example.com');assert.equal(await form.locator('[name=linkedin]').evaluate(i=>i.checkValidity()),false);
  await form.locator('[name=linkedin]').fill('https://www.linkedin.com/in/ascla-prueba');
  await form.locator('[name=member_type]').selectOption('independent');
  assert.deepEqual(await form.evaluate(form=>[...form.elements].filter(input=>input.willValidate&&!input.checkValidity()).map(input=>({name:input.name,message:input.validationMessage}))),[]);
  const saved=admin.waitForResponse(r=>r.url().endsWith('/admin/users/'+fixture.actors.Asociado.id)&&r.request().method()==='POST');
  await form.locator('button[type=submit],button:not([type])').last().click();assert.equal((await saved).status(),200);
 });
 await test('Active connection ignores a legacy pending chat request and allows composing',async()=>{
  await member.goto(base+'/mensajeria/?conversation='+fixture.conversation_id);
  const input=member.locator('[data-form=message] textarea,[data-form=message] input[name=body]');
  await input.waitFor();assert.equal(await input.isEnabled(),true);assert.match(await input.getAttribute('placeholder'),/Escribe un mensaje/);
  assert.equal(await member.locator('.chat-request-banner').count(),0);
  await input.fill('El chat de una conexión activa permite escribir.');await input.fill('');
 });
 for(const type of ['gallery','resource'])await test(type+': hide, historical registry and republish preserve the record',async()=>{
  const created=await api(admin,'content/'+type,{title:'QA Retiro '+type+' '+Date.now(),body:'Contenido de prueba para conservar el historial.',status:'publish',meta:{chatham:false}});
  assert.equal(created.status,200);const id=created.body.id;
  await admin.goto(base+'/administracion/?section=contenidos&type='+type);
  let row=admin.locator('[data-editorial-record="'+id+'"]');await row.waitFor();
  await row.locator('[data-action=editorial-state]').click();
  await admin.locator('.modal [name=reason]').fill('Retiro temporal del catálogo.');
  await admin.locator('.modal [data-form=moderate] button[type=submit]').click();
  await row.locator('.status.ascla_hidden').waitFor();
  assert.equal((await api(member,'items/'+id)).status,404);
  assert.equal((await api(member,'content/'+type+'?q='+encodeURIComponent(created.body.title))).body.total,0);
  await row.locator('[data-action=editorial-history]').click();await admin.getByRole('heading',{name:'Historial de publicación',exact:true}).waitFor();
  assert.match(await admin.locator('.modal .ally-history').innerText(),/Oculto/);
  await admin.locator('.modal-top [data-action=close]').click();
  for(const [theme,width]of [['light',1440],['dark',390]]){
   await admin.evaluate(theme=>{document.documentElement.dataset.asclaTheme=theme;},theme);await admin.setViewportSize({width,height:1050});
   await admin.waitForTimeout(300);
   assert.equal(await admin.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
   assert.equal(await admin.locator('[data-form=editorial-filter]').evaluate(form=>[...form.querySelectorAll('input,select')].every(input=>{
    const box=input.getBoundingClientRect(),parent=input.closest('.field').getBoundingClientRect();return box.left>=parent.left-1&&box.right<=parent.right+1;
   })),true,'Filter controls must fit inside their columns');
   const name=type+'-'+theme+'-'+width+'.png';await admin.screenshot({path:out+'/'+name,fullPage:true,animations:'disabled'});result.screenshots.push(name);
  }
  await admin.setViewportSize({width:1440,height:1050});
  await row.locator('[data-action=editorial-state]').click();await admin.locator('.modal [name=reason]').fill('Contenido listo para volver al catálogo.');
  await admin.locator('.modal [data-form=moderate] button[type=submit]').click();await row.locator('.status.publish').waitFor();
  assert.equal((await api(member,'items/'+id)).status,200);
  const history=await api(admin,'admin/editorial/'+type+'/'+id+'/history');
  assert.equal(history.body.length,3);assert.equal(history.body[0].to,'publish');
 });
 await Promise.all(assetReads);
 result.app_sha256=crypto.createHash('sha256').update(fs.readFileSync('/work/wp-content/plugins/ascla-core/assets/app.js')).digest('hex');
 assert.deepEqual([...new Set(appHashes)],[result.app_sha256]);
 assert.deepEqual(result.errors,[]);assert.deepEqual(result.violations,[]);result.status='PASS';
}catch(e){result.status='FAIL';result.errors.push(e.message);console.error(e.stack);process.exitCode=1;}finally{
 if(browser)await browser.close();fs.writeFileSync(out+'/profile-editorial.json',JSON.stringify(result,null,2)+'\n');
}})();
