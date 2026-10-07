const fs=require("node:fs"),assert=require("node:assert/strict");
module.exports=async({browser,admin,member,base,request,test,login,coverage,root})=>{
  const f=JSON.parse(fs.readFileSync(root+"/test-results/statistics-fixture.json","utf8"));
  const account=f.users.find(u=>u.role==="ascla_member");
  const executive=f.users.find(u=>u.role==="ascla_executive");
  await test("Membresía: vencer, denegar acceso y reactivar conserva el perfil",async()=>{
    const before=(await request(admin,"admin/users/"+account.id)).body;
    await admin.goto(base+"/administracion/?section=usuarios");
    const search=admin.locator('[data-form="admin-filter"][data-area="users"]');
    await search.locator('[name="q"]').fill(account.login);await search.locator("button").click();
    await admin.locator('[data-action="user-edit"][data-id="'+account.id+'"]').click();
    const form=admin.locator('[data-form="admin-user-edit"]');
    await form.locator('[name="membership_status"]').selectOption("expired");
    await form.locator('[name="membership_until"]').fill("2000-01-01");
    await form.getByRole("button",{name:"Guardar cambios",exact:true}).click();
    await admin.locator(".modal-backdrop").waitFor({state:"detached"});
    assert.equal((await request(admin,"admin/users/"+account.id)).body.membership_status,"expired");
    const context=await browser.newContext();const p=await context.newPage();
    try{
      await p.goto(base+"/wp-login.php");await p.locator("#user_login").fill(account.login);await p.locator("#user_pass").fill(account.password);
      await Promise.all([p.waitForNavigation({waitUntil:"domcontentloaded"}),p.locator("#wp-submit").click()]);
      assert.match(await p.locator("body").innerText(),/vencida|suspendida/);
    }finally{
      await context.close();
      const restored=await request(admin,"admin/users/"+account.id,{...before,membership_status:"active",membership_until:""});
      assert.equal(restored.status,200);
    }
    const after=(await request(admin,"admin/users/"+account.id)).body;
    for(const key of ["first_name","last_name","position","company"])assert.equal(after[key],before[key]);
    const session=await login(browser,account.login,account.password);
    try{assert.equal((await request(session.page,"bootstrap")).status,200);}
    finally{await require("./helpers/browser.cjs").stopCoverage(session.page,coverage);await session.context.close();}
  });
  await test("Contacto: asignar ejecutivo revoca acceso del responsable anterior",async()=>{
    const ticket=(await request(member,"content/contact",{title:"E2E Asignación confidencial",body:"Contenido reservado para su responsable."})).body;
    const assigned=await request(admin,"admin/contact/"+ticket.id+"/assign",{assignee:executive.id});assert.equal(assigned.status,200);
    assert.equal((await request(admin,"items/"+ticket.id)).status,404);
    const session=await login(browser,executive.login,executive.password);
    try{
      await session.page.goto(base+"/administracion/?section=solicitudes");
      const form=session.page.locator('[data-form="contact-update"][data-id="'+ticket.id+'"]');
      await form.locator('[name="status"]').selectOption("progress");
      await form.locator('[name="response"]').fill("Recibido por el equipo ejecutivo.");await form.locator("button").click();
      await session.page.locator('[data-contact="'+ticket.id+'"][data-state="progress"]').waitFor();
      assert.equal((await request(member,"items/"+ticket.id)).body.meta.request_status,"progress");
      await session.page.setViewportSize({width:390,height:844});
      await session.page.screenshot({path:root+"/test-results/support-mobile.png",fullPage:true});
      assert.ok(await session.page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1));
    }finally{await require("./helpers/browser.cjs").stopCoverage(session.page,coverage);await session.context.close();}
  });
};
