/* Browser coverage for 1.10.1 against disposable local WordPress. */
const {chromium}=require('playwright'),{execFileSync}=require('node:child_process');
const assert=require('node:assert/strict'),fs=require('node:fs');
const base=process.env.ASCLA_SITE_URL || 'http://localhost:8088',results=[],errors=[];
const ephemeral=process.env.ASCLA_E2E_EPHEMERAL==='1';
const {startCoverage,stopCoverage}=require('./helpers/browser.cjs');
const fixture=input=>ephemeral ? (input.action==='setup' ? JSON.parse(fs.readFileSync(process.env.ASCLA_STATISTICS_FIXTURE || 'test-results/statistics-fixture.json','utf8')) : {}) : JSON.parse(execFileSync('docker',['compose','exec','-T','wordpress','php','/opt/ascla-tests/statistics-fixture.php'],{input:JSON.stringify(input),encoding:'utf8'}));
async function api(p,path,method='GET',body,nonce=true){return p.evaluate(async({path,method,body,nonce})=>{const r=await fetch(ASCLA.api+path,{method,headers:{'Content-Type':'application/json',...(nonce?{'X-WP-Nonce':nonce===true?ASCLA.nonce:nonce}:{})},...(body?{body:JSON.stringify(body)}:{})});return {status:r.status,data:await r.json()};},{path,method,body,nonce});}
async function test(name,fn){await fn();results.push({name,status:'passed'});console.log('PASS '+name);}
async function runStatistics(){let f,browser,page;const coverage=[];
 try{
  fs.mkdirSync('test-results',{recursive:true});f=fixture({action:'setup'});browser=await chromium.launch(ephemeral ? {headless:true} : {headless:true,channel:process.env.ASCLA_BROWSER_CHANNEL || 'msedge'});
  for(const u of f.users){
   const context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'});page=await context.newPage();await startCoverage(page,coverage);page.on('pageerror',e=>errors.push(e.message));const publisher=['administrator','ascla_executive'].includes(u.role);
   await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill(u.login);await page.locator('#user_pass').fill(u.password);await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#wp-submit').click()]);
   await page.goto(base+'/intranet/');await page.locator('.nav-link').first().waitFor();
   await test(u.role+': estadísticas y asistencia requieren capacidad en REST',async()=>{
    for(const path of ['admin/statistics','admin/statistics/topics','admin/attendance/'+f.events[0]])assert.equal((await api(page,path)).status,publisher?200:403,path);
    assert.equal((await api(page,'admin/interest-imports')).status,u.role==='administrator'?200:403);
    if(!publisher)for(const path of ['admin/attendance/'+f.events[0],`admin/attendance/${f.events[0]}/complete`,`admin/attendance/${f.events[0]}/import`,'admin/statistics/topics'])assert.equal((await api(page,path,'POST',{complete:true})).status,403,path);
    assert.equal((await api(page,`admin/attendance/${f.events[0]}/complete`,'POST',{complete:true},'invalid')).status,403);
    assert.equal((await api(page,`admin/attendance/${f.events[0]}/complete`,'POST',{complete:true},false)).status,401);
   });
   if(u.role==='ascla_member'){assert.equal(await page.locator('.nav-link[href*="administracion"]').count(),0);await stopCoverage(page,coverage);await context.close();continue;}
   await page.goto(base+'/administracion/?section=estadisticas');await page.locator('.admin-tabs').waitFor();
   await test(u.role+': acceso al menú y vistas',async()=>{
    assert.equal(await page.locator('.admin-tabs [data-tab="estadisticas"]').count(),publisher?1:0);
    if(!publisher){assert.equal(await page.locator('.stats-tabs').count(),0);return;}
    await page.locator('[data-stats-form="filter"]').waitFor();await page.locator('[data-stats-form="filter"] [name="topic"]').selectOption(String(f.topic));await page.locator('[data-stats-form="filter"] button').click();await page.locator('.stats-table').first().waitFor();
    for(const section of ['users','topics','trends','ai','overview']){await page.locator(`[data-stats-action="section"][data-section="${section}"]`).click();await page.locator('#stats-view .stats-section').first().waitFor();}
   });
   if(u.role==='ascla_executive')assert.match(await page.locator('#admin-panel h2').innerText(),/Community statistics/);
   if(u.role==='administrator'){
    await test('IA: clasificación explícita sin inventar asistencias',async()=>{
     await page.locator('[data-stats-action="section"][data-section="ai"]').click();await page.locator('[data-stats-action="analyze"]').waitFor();
     await page.locator('[data-stats-action="analyze"]').click();await page.waitForFunction(()=>document.querySelector('[data-stats-action="analyze"]')?.disabled && !document.querySelector('[data-stats-action="analyze"]')?.hasAttribute('aria-busy'));
     const r=await api(page,'admin/statistics/topics?topic='+f.topic);assert.equal(r.status,200);assert.equal(r.data.pending,0);assert.equal(r.data.classified,2);assert.equal(r.data.groups[0].attendances,1);
     await page.locator('[data-stats-action="section"][data-section="overview"]').click();
    });
    await test('Vista previa CSV, corrección manual y cierre de asistencia',async()=>{
     await page.locator(`[data-stats-action="roster"][data-id="${f.events[0]}"]`).click();await page.locator('.stats-roster').waitFor();
     const stamp=m=>new Date(Date.parse(f.eventStart)+m*60000).toISOString().replace('.000Z','Z');
     const csv=`Name,User Email,Join Time,Leave Time,Duration (Minutes)\nAsociado,${f.users[3].email},${stamp(0)},${stamp(20)},20\nEjecutivo,${f.users[1].email},${stamp(0)},${stamp(30)},30\nEjecutivo,${f.users[1].email},${stamp(20)},${stamp(45)},25\nEjecutivo,${f.users[1].email},${stamp(20)},${stamp(45)},25\nExterno,external@example.invalid,${stamp(0)},${stamp(10)},10\n`;
     await page.locator('[data-stats-form="csv"] input[type="file"]').setInputFiles({name:'zoom.csv',mimeType:'text/csv',buffer:Buffer.from(csv)});
     await page.locator('[data-stats-form="csv"] button').first().click();await page.locator('[data-stats-action="import"]').waitFor();
     assert.match(await page.locator('#stats-csv-preview').innerText(),/external@example.invalid/);assert.match(await page.locator('#stats-csv-preview').innerText(),/Conservar manual/);
     await page.locator('[data-stats-action="import"]').click();await page.locator(`[data-stats-form="row"][data-user="${f.users[1].id}"]`).waitFor();
     let row=page.locator(`[data-stats-form="row"][data-user="${f.users[1].id}"]`);assert.equal(await row.locator('[name="minutes"]').inputValue(),'45');
     row=page.locator(`[data-stats-form="row"][data-user="${f.users[3].id}"]`);assert.equal(await row.locator('[name="minutes"]').inputValue(),'20');await row.locator('[name="minutes"]').fill('35');await row.locator('button').click();await page.waitForFunction(()=>!document.querySelector('[data-stats-form="row"] button[disabled]'));
     const manual=page.locator('[data-stats-form="manual"]');await manual.locator('[name="email"]').fill(f.users[2].email);await manual.locator('[name="status"]').selectOption('absent');await manual.locator('button').click();await page.waitForFunction(()=>!document.querySelector('[data-stats-form="manual"] button[disabled]'));
     await page.locator('[data-stats-form="complete"] input').check();await page.locator('[data-stats-form="complete"] button').click();await page.locator('[data-stats-action="reopen"]').waitFor();
     const r=await api(page,'admin/attendance/'+f.events[0]);assert.equal(r.data.complete,true);assert.equal(r.data.people.find(x=>x.id===f.users[3].id).minutes,35);assert.equal(r.data.people.find(x=>x.id===f.users[2].id).status,'absent');
     await page.locator('.modal-top [data-action="close"]').click();
     const d=await api(page,'admin/statistics?topic='+f.topic);assert.equal(d.data.summary.attendances,2);assert.equal(d.data.summary.rate,50);assert.equal(d.data.summary.attendees,2);
    });
    await test('Forms: previsualizar, revisar y confirmar sin modificar antes',async()=>{
     await page.locator('.admin-tabs [data-tab="importar"]').click();await page.locator('[data-import-form="file"]').waitFor();
     const csv=`Email,Code,Intereses,Otros\n${f.users[3].email},,"${f.topicName}",\n`;
     await page.locator('[data-import-form="file"] input').setInputFiles({name:'forms.csv',mimeType:'text/csv',buffer:Buffer.from(csv)});await page.locator('[data-import-form="file"] button').click();await page.locator('[data-import-form="mapping"]').waitFor();
     await page.locator('[data-import-form="mapping"] [name="email"]').selectOption('0');await page.locator('[data-import-form="mapping"] [name="structured"]').selectOption('2');await page.locator('[data-import-form="mapping"] [name="free"]').selectOption('3');await page.locator('[data-import-form="mapping"] button').first().click();await page.locator('[data-import-form="review"]').waitFor();
     const prior=await api(page,'profiles/'+f.users[3].id);assert.ok(!(prior.data.interests||[]).includes(f.topic));
     await page.locator('[data-import-form="review"] select').selectOption(String(f.topic));await page.locator('[data-import-form="review"] button').first().click();await page.waitForFunction(()=>!document.querySelector('[data-import-action="confirm"]')?.disabled);
     await page.locator('[data-import-action="confirm"]').click();await page.locator('[data-import-action="apply"]').click();await page.waitForFunction(()=>document.querySelector('#admin-panel .admin-section-heading p')?.textContent.includes('Completada'));
     const saved=await api(page,'profiles/'+f.users[3].id);assert.ok(saved.data.interests.includes(f.topic));const stats=await api(page,'admin/statistics?topic='+f.topic);assert.equal(stats.data.topics[0].forms,1);assert.equal(stats.data.topics[0].declared,1);
     await page.screenshot({path:'test-results/forms-reviewed.png',fullPage:true});
    });
    await test('Reportes: revisar antes de eliminar y conservar la publicación',async()=>{
     await page.locator('.admin-tabs [data-tab="moderacion"]').click();await page.locator(`[data-action="report-reviewed"][data-id="${f.report}"]`).waitFor();assert.equal(await page.locator(`[data-action="admin-delete"][data-kind="report"][data-id="${f.report}"]`).count(),0);
     assert.equal((await api(page,'admin/reports/'+f.report,'DELETE',{})).status,409);
     await page.locator(`[data-action="report-reviewed"][data-id="${f.report}"]`).click();await page.locator(`[data-action="admin-delete"][data-kind="report"][data-id="${f.report}"]`).click();await page.locator('[data-action="admin-delete-confirm"]').click();await page.locator('.modal-backdrop').waitFor({state:'detached'});
     assert.equal((await api(page,'items/'+f.hub)).status,200);assert.equal((await api(page,'admin/reports/'+f.report,'DELETE',{})).status,404);
    });
    await test('Solicitudes: resolver antes de eliminar',async()=>{
     await page.locator('.admin-tabs [data-tab="solicitudes"]').click();await page.locator(`[data-contact="${f.closed}"]`).waitFor();assert.equal(await page.locator(`[data-contact="${f.open}"] [data-action="admin-delete"]`).count(),0);
     await page.locator(`[data-contact="${f.closed}"] [data-action="admin-delete"]`).click();await page.locator('[data-action="admin-delete-confirm"]').click();await page.locator('.modal-backdrop').waitFor({state:'detached'});
     assert.equal((await api(page,'items/'+f.closed)).status,404);assert.equal((await api(page,'items/'+f.open,'DELETE',{})).status,409);
    });
    await test('Estadísticas en wp-admin y adaptación móvil',async()=>{
     await page.goto(base+'/wp-admin/admin.php?page=ascla-estadisticas');await page.locator('.stats-tabs').waitFor();assert.equal(await page.locator('#toplevel_page_ascla a[href="admin.php?page=ascla-estadisticas"]').count(),1);
     await page.goto(base+'/administracion/?section=estadisticas');await page.locator('.stats-tabs').waitFor();await page.screenshot({path:'test-results/statistics-desktop.png',fullPage:true});
     await page.setViewportSize({width:390,height:844});await page.screenshot({path:'test-results/statistics-mobile.png',fullPage:true});
     assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),'Page has horizontal overflow');await page.setViewportSize({width:1440,height:1000});
    });
   }
   await stopCoverage(page,coverage);await context.close();
  }
  assert.deepEqual(errors,[]);console.log('PASS '+results.length+' statistics browser tests; no uncaught errors.');
 }catch(e){if(page)await page.screenshot({path:'test-results/statistics-failure.png',fullPage:true}).catch(()=>{});throw e;}
 finally{if(page&&!page.isClosed())await stopCoverage(page,coverage).catch(()=>{});if(browser)await browser.close();if(f)fixture({action:'cleanup',users:f.users.map(u=>u.id),topic:f.topic});fs.writeFileSync('test-results/statistics.json',JSON.stringify({results,errors},null,2));}
 return coverage;
}
module.exports=runStatistics;
if(require.main===module)runStatistics().catch(error=>{console.error(error);process.exitCode=1;});
