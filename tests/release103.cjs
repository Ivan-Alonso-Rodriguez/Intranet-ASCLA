/* Run with local fixtures from release103-fixtures.php; never uses real accounts. */
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'..');
const fixtures=JSON.parse(fs.readFileSync(path.join(root,'test-results/release103-fixtures.json'),'utf8').replace(/^\uFEFF/,''));
const base=process.env.ASCLA_SITE_URL;
if (!base || process.env.ASCLA_E2E_EPHEMERAL!=='1') throw Error('ASCLA_SITE_URL and ASCLA_E2E_EPHEMERAL=1 are required for this local-only test.');
const results=[],errors=[];
async function api(page,route,body) {
  return page.evaluate(async ({route,body})=>{
    const response=await fetch(ASCLA.api+route,{method:body?'POST':'GET',headers:{'X-WP-Nonce':ASCLA.nonce,'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})});
    return {status:response.status,body:await response.json()};
  },{route,body});
}
async function test(name,fn) {
  await fn();results.push({name,status:'passed'});console.log('PASS '+name);
}
async function navigate(page,route) {
  await page.goto(base+'/'+route+'/',{waitUntil:'domcontentloaded'});
  await page.locator('#page-content h1').waitFor();
}
async function login(browser,user) {
  const context=await browser.newContext({viewport:{width:1440,height:1000}});
  const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
  await page.goto(base+'/login/',{waitUntil:'domcontentloaded'});
  await page.locator('#user_login').fill(user.login);
  await page.locator('#user_pass').fill(user.password);
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#wp-submit').click()]);
  await navigate(page,'intranet');
  return page;
}
(async()=>{
  const browser=await chromium.launch({headless:true});
  let failure;
  try {
    const member=await login(browser,fixtures.member);
    const admin=await login(browser,fixtures.admin);
    const executive=await login(browser,fixtures.executive);
    await test('Required profile fields block blank form submission and REST bypass',async()=>{
      await navigate(member,'perfil');
      const form=member.locator('[data-form="profile"]');
      for (const key of ['first_name','last_name','position','company']) {
        const field=form.locator('[name="'+key+'"]');
        assert.equal(await field.getAttribute('required'),'');
        await field.fill('');
      }
      await form.getByRole('button',{name:'Guardar perfil',exact:true}).click();
      assert.equal(await form.evaluate(el=>el.checkValidity()),false);
      const before=await api(member,'profiles/'+fixtures.member.id);
      assert.equal(before.body.position,'Analista');
      assert.equal((await api(member,'profiles/me',{first_name:'',last_name:'',position:'',company:''})).status,400);
      await member.screenshot({path:path.join(root,'test-results/release103-required.png'),fullPage:true});
    });
    await test('Administrator needs a Contact request selected in the editor',async()=>{
      await navigate(admin,'administracion');
      await admin.locator('.admin-tabs [data-tab="usuarios"]').click();
      const search=admin.locator('[data-form="admin-filter"][data-area="users"] [name="q"]');
      await search.fill(fixtures.member.login);
      await search.press('Enter');
      await admin.locator('[data-action="user-edit"][data-id="'+fixtures.member.id+'"]').click();
      const form=admin.locator('[data-form="admin-user-edit"]');
      assert.equal(await form.locator('[name="position"]').evaluate(el=>el.readOnly),true);
      assert.equal(await form.locator('[name="professional_request_id"] option').count(),1);
      await admin.locator('.modal-top [data-action="close"]').click();
      await navigate(member,'contacto');
      const contact=member.locator('[data-form="contact"]');
      await contact.locator('[name="title"]').fill('Actualizar mis datos profesionales QA103');
      await contact.locator('[name="body"]').fill('Solicito cambiar mi cargo a Gerente QA103 y mi empresa a Empresa QA103.');
      const sent=member.waitForResponse(r=>r.url().includes('/content/contact')&&r.request().method()==='POST');
      await contact.getByRole('button',{name:'Enviar solicitud',exact:true}).click();
      const request=(await (await sent).json());
      await admin.locator('[data-action="user-edit"][data-id="'+fixtures.member.id+'"]').click();
      await form.locator('[name="professional_request_id"]').selectOption(String(request.id));
      assert.equal(await form.locator('[name="position"]').evaluate(el=>el.readOnly),false);
      assert.match(await form.locator('[data-professional-request-preview]').textContent(),/Gerente QA103/);
      await form.locator('[name="position"]').fill('Gerente QA103');
      await form.locator('[name="company"]').fill('Empresa QA103');
      await admin.screenshot({path:path.join(root,'test-results/release103-request.png'),fullPage:true});
      const updated=admin.waitForResponse(r=>r.url().includes('/admin/users/'+fixtures.member.id)&&r.request().method()==='POST');
      await form.getByRole('button',{name:'Guardar cambios',exact:true}).click();
      assert.equal((await updated).status(),200);
      await form.waitFor({state:'detached'});
      const saved=await api(admin,'admin/users/'+fixtures.member.id);
      assert.equal(saved.body.position,'Gerente QA103');
      assert.equal(saved.body.company,'Empresa QA103');
      assert.deepEqual(saved.body.professional_requests,[]);
    });
    await test('Directory searches the visible Miembro ASCLA fallback',async()=>{
      await navigate(member,'directorio');
      const form=member.locator('form.directory-filters');
      await form.locator('[name="q"]').fill('Miembro ASCLA');
      const response=member.waitForResponse(r=>r.url().includes('/profiles?')&&r.request().method()==='GET');
      await form.locator('[name="q"]').press('Enter');
      const data=await (await response).json();
      assert.ok(data.items.some(item=>Number(item.id)===fixtures.locked.id));
      const card=member.locator('[data-action="member"][data-id="'+fixtures.locked.id+'"]').first();
      await card.waitFor();
      await member.screenshot({path:path.join(root,'test-results/release103-directory.png'),fullPage:true});
    });
    await test('Executive can prepare microevents from Administration',async()=>{
      await navigate(executive,'administracion');
      await executive.locator('.admin-tabs [data-tab="microeventos"]').click();
      const created=executive.waitForResponse(r=>r.url().endsWith('/jobs')&&r.request().method()==='POST');
      await executive.locator('[data-action="micro-job"]').click();
      const response=await created;assert.equal(response.status(),200);
      const job=await response.json();
      for(let attempt=0;attempt<40;attempt++){
        const result=await api(executive,'jobs/'+job.id);
        if(result.body.status==='error')throw Error(result.body.error);
        if(result.body.status==='completed')break;
        if(attempt===39)throw Error('Microevent job did not finish');
        await new Promise(resolve=>setTimeout(resolve,500));
      }
      assert.equal(await executive.locator('.admin-tabs [data-tab="usuarios"]').count(),0);
      await executive.screenshot({path:path.join(root,'test-results/release103-executive.png'),fullPage:true});
    });
    await test('Correct password remains blocked after five failed frontend logins',async()=>{
      const context=await browser.newContext();
      const page=await context.newPage();
      for(let attempt=0;attempt<6;attempt++){
        await page.goto(base+'/login/',{waitUntil:'domcontentloaded'});
        await page.locator('#user_login').fill(fixtures.locked.login);
        await page.locator('#user_pass').fill(attempt===5?fixtures.locked.password:'wrong-password-103');
        await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#wp-submit').click()]);
      }
      assert.match(new URL(page.url()).pathname,/\/login\/|wp-login\.php/);
      await page.getByText(/Demasiados intentos fallidos/).waitFor();
      const protectedPage=await page.request.get(base+'/wp-json/ascla/v1/bootstrap');
      assert.equal(protectedPage.status(),401);
      await page.screenshot({path:path.join(root,'test-results/release103-lock.png'),fullPage:true});
      await context.close();
    });
    assert.deepEqual(errors,[]);
  } catch(error) { failure=error; }
  finally {
    await browser.close();
    fs.writeFileSync(path.join(root,'test-results/release103-browser.json'),JSON.stringify({results,errors,failure:failure?.message||null},null,2));
  }
  if(failure)throw failure;
})().catch(error=>{console.error(error);process.exitCode=1;});
