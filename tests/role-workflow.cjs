/* End-to-end capability checks against disposable local WordPress. No external accounts. */
const {chromium}=require('playwright');
const {execFileSync}=require('node:child_process');
const assert=require('node:assert/strict'),fs=require('node:fs');
const base='http://localhost:8088',results=[],errors=[];
const fixture=input=>JSON.parse(execFileSync('docker',['compose','exec','-T','wordpress','php','/opt/ascla-tests/role-workflow-fixture.php'],{input:JSON.stringify(input),encoding:'utf8'}));
async function api(p,route,method='GET',body,nonce=true){
 return p.evaluate(async({route,method,body,nonce})=>{
  const r=await fetch(ASCLA.api+route,{method,headers:{'Content-Type':'application/json',...(nonce?{'X-WP-Nonce':nonce===true?ASCLA.nonce:nonce}:{})},...(body?{body:JSON.stringify(body)}:{})});
  return {status:r.status,data:await r.json()};
 },{route,method,body,nonce});
}
async function test(name,fn){await fn();results.push({name,status:'passed'});console.log('PASS '+name);}
(async()=>{
 let f,browser,page;
 try{
  fs.mkdirSync('test-results',{recursive:true});f=fixture({action:'setup'});
  browser=await chromium.launch({headless:true,channel:'msedge'});
  for(const u of f.users){
   const context=await browser.newContext({viewport:{width:1366,height:900}});page=await context.newPage();
   page.on('pageerror',e=>errors.push(e.message));
   const admin=u.role==='administrator',publisher=admin||u.role==='ascla_executive',staff=u.role!=='ascla_member';
   await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill(u.login);await page.locator('#user_pass').fill(u.password);
   await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);
   await test(u.role+': navegación, foros y edición',async()=>{
    await page.goto(base+'/foros/');await page.locator('form.filters').waitFor();
    assert.equal(await page.locator('.nav-link[href*="administracion"]').count(),staff?1:0);
    assert.equal(await page.locator('[data-action="editor"][data-type="forum"]').count(),1);
    assert.equal(await page.locator('[data-action="editor"][data-type="topic"]').count(),1);
    await page.locator('[data-action="editor"][data-type="forum"]').click();
    await page.locator('form[data-form="editor"][data-type="forum"]').waitFor();
    await page.locator('.modal [data-action="close"]').first().click();
    await page.goto(base+'/hub/?item='+f.post);await page.locator('.modal [data-action="like"]').waitFor();
    for(const action of ['editor','moderate','delete-content'])assert.equal(await page.locator('.modal [data-action="'+action+'"]').count(),staff?1:0,action);
   });
   await test(u.role+': publicación editorial y seguridad REST',async()=>{
    await page.goto(base+'/eventos/');await page.locator('form.filters').waitFor();
    assert.equal(await page.locator('[data-action="editor"][data-type="event"]').count(),publisher?1:0);
    const boot=await api(page,'bootstrap');assert.equal(boot.status,200);
    assert.equal(boot.data.moderator,staff);assert.equal(boot.data.executive,publisher);assert.equal(boot.data.admin,admin);
    for(const route of ['settings','admin/users'])assert.equal((await api(page,route)).status,admin?200:403);
    assert.equal((await api(page,'admin/contacts')).status,staff?200:403);
    if(!publisher)assert.equal((await api(page,'content/gallery','POST',{title:'No autorizado',body:'Prueba'})).status,403);
    if(!admin)assert.equal((await api(page,'settings','POST',{demo:true})).status,403);
    assert.equal((await api(page,'content/forum','POST',{title:'Nonce inválido',body:'Prueba'},'invalid')).status,403);
    assert.equal((await api(page,'content/forum','POST',{title:'Sin nonce',body:'Prueba'},false)).status,401);
   });
   await test(u.role+': archivos privados y ruta admin-post',async()=>{
    const privateResponse=await context.request.get(base+'/wp-admin/admin-post.php?action=ascla_media&id='+f.private);
    assert.equal(privateResponse.status(),admin||u.id===f.users[0].id?200:404);
    const communityResponse=await context.request.get(base+'/wp-admin/admin-post.php?action=ascla_media&id='+f.attached);
    assert.equal(communityResponse.status(),200);
   });
   await test(u.role+': vistas y menús administrativos',async()=>{
    const response=await page.goto(base+'/administracion/');assert.equal(response.status(),staff?200:403);
    if(staff){
     await page.locator('.admin-tabs').waitFor();
     for(const tab of ['moderacion','solicitudes','trabajos'])assert.equal(await page.locator('.admin-tabs [data-tab="'+tab+'"]').count(),1,tab);
     for(const tab of ['usuarios','archivos','microeventos','logs','configuracion'])assert.equal(await page.locator('.admin-tabs [data-tab="'+tab+'"]').count(),admin?1:0,tab);
     if(!admin){
      // URL tampering cannot unlock a forbidden tab or its REST data.
      await page.goto(base+'/administracion/?page=ascla-configuracion');await page.locator('.admin-tabs').waitFor();
      assert.equal(await page.locator('[data-tab="configuracion"]').count(),0);
     }
     await page.screenshot({path:'test-results/roles-'+u.role+'.png',fullPage:true});
    }
    await page.goto(base+'/wp-admin/');
    assert.equal(new URL(page.url()).pathname,admin?'/wp-admin/':staff?'/administracion/':'/intranet/');
    if(admin){
     await page.goto(base+'/wp-admin/admin.php?page=ascla');await page.locator('.admin-tabs').waitFor();
     assert.equal(await page.locator('#toplevel_page_ascla').count(),1);
     assert.ok(await page.locator('#toplevel_page_ascla a[href="admin.php?page=ascla-moderacion"]').count());
    }
   });
   await context.close();
  }
  assert.deepEqual(errors,[]);console.log('PASS '+results.length+' role browser tests; no uncaught errors.');
 }catch(error){console.error(error);process.exitCode=1;if(page)await page.screenshot({path:'test-results/role-workflow-failure.png',fullPage:true}).catch(()=>{});}
 finally{if(browser)await browser.close();if(f)fixture({action:'cleanup',users:f.users.map(u=>u.id)});fs.writeFileSync('test-results/role-workflow.json',JSON.stringify({results,errors},null,2));}
})();
