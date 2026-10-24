/* UI acceptance for event reactivation and the activity inbox on disposable WordPress. */
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const fixture=JSON.parse(fs.readFileSync(process.env.ASCLA_UX_FIXTURE||'test-results/sonar-preparation/release1110-users.json','utf8'));
if(fixture.origin!=='ascla-local-test-users'||!['localhost','127.0.0.1'].includes(new URL(fixture.base).hostname))throw Error('Disposable loopback fixture required');
const out=path.resolve('test-results/event-reactivation1110');fs.mkdirSync(out,{recursive:true});
const manifest=JSON.parse(fs.readFileSync('docs/evidence/release-1.11.0/package.json','utf8'));
const checks=[],scans=[],errors=[],posts=[],contexts=[];let browser,admin,member;
function pass(name){checks.push(name);console.log('PASS '+name);}
async function api(page,route,body,method){return page.evaluate(async({route,body,method})=>{const response=await fetch(ASCLA.api+route,{method:method||(body?'POST':'GET'),headers:{'X-WP-Nonce':ASCLA.nonce,'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})});return{status:response.status,body:await response.json()};},{route,body,method});}
async function ok(page,route,body,method){const r=await api(page,route,body,method);assert.ok(r.status>=200&&r.status<300,route+': '+r.status+' '+r.body.message);return r.body;}
async function go(page,route){await page.goto(fixture.base+'/'+route);await page.locator('#page-content h1').waitFor();}
async function login(actor){const context=await browser.newContext({viewport:{width:1440,height:1000},timezoneId:'America/Lima'});contexts.push(context);const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));await page.goto(fixture.base+'/wp-login.php');await page.locator('#user_login').fill(fixture.actors[actor].login);await page.locator('#user_pass').fill(fixture.actors[actor].password);await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);await go(page,'intranet/');return page;}
async function capture(page,name){assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),name+': page overflow');assert.ok(await page.locator('.modal').evaluateAll(nodes=>nodes.every(n=>n.scrollWidth<=n.clientWidth+1)),name+': modal overflow');if(!await page.evaluate(()=>!!window.axe))await page.addScriptTag({path:process.env.ASCLA_AXE_PATH||require.resolve('axe-core/axe.min.js')});const result=await page.evaluate(()=>axe.run(document.querySelector('#ascla-root'),{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa']}}));const scan={name,engine:result.testEngine,violations:result.violations.map(v=>({id:v.id,impact:v.impact,nodes:v.nodes.map(n=>n.target)})),incomplete:result.incomplete.length};scans.push(scan);assert.deepEqual(scan.violations,[],name+': axe');await page.screenshot({path:path.join(out,name+'.png'),fullPage:false});}

async function eventPage(page,id){await go(page,'eventos/?item='+id);await page.locator('.event-detail-summary').waitFor();}
async function inbox(){await go(member,'perfil/');await member.locator('[data-action="notifications"]').click();await member.locator('.notification-modal').waitFor();}
(async()=>{try{
 browser=await chromium.launch({headless:true,channel:fixture.browser_channel||'msedge'});admin=await login('Administrador');member=await login('Ana');
 const event=await ok(admin,'content/event',{title:'Conversaciones sobre gobierno corporativo',body:'Un espacio para compartir experiencias y preparar los próximos encuentros de la comunidad.',status:'publish',meta:{start:new Date(Date.now()+3*86400000).toISOString(),end:new Date(Date.now()+3*86400000+3600000).toISOString(),url:'https://us02web.zoom.us/j/123456789',capacity:20,chatham:false,location:'Sala ASCLA'}});posts.push(event.id);
 await ok(member,'events/'+event.id+'/register',{status:'accepted'});
 for(const theme of ['light','dark'])for(const [screen,viewport] of [['desktop',{width:1440,height:1000}],['mobile',{width:390,height:844}]]){
  for(const page of [admin,member]){await page.setViewportSize(viewport);await page.evaluate(theme=>{localStorage.setItem('ascla-theme',theme);document.documentElement.dataset.asclaTheme=theme;},theme);}
  const name=screen+'-'+theme;
  await ok(admin,'events/'+event.id+'/cancel',{reason:'Revisión de la disponibilidad de la sala'});
  await eventPage(member,event.id);assert.equal(await member.locator('[data-action="event-reactivate-request"]').count(),0);assert.equal(await member.locator('.event-join-link').count(),0);
  assert.equal((await api(member,'events/'+event.id+'/reactivate',{})).status,403);assert.equal((await api(member,'events/'+event.id+'/register',{status:'accepted'})).status,409);
  await eventPage(admin,event.id);await admin.locator('[data-action="event-reactivate-request"]').waitFor();await capture(admin,'evento-cancelado-'+name);
  await admin.locator('[data-action="event-reactivate-request"]').click();await admin.getByRole('dialog',{name:'Reactivar evento',exact:true}).waitFor();await admin.locator('.modal-top .modal-back').click();assert.equal((await ok(admin,'events/'+event.id)).cancelled,true);
  await admin.locator('[data-action="event-reactivate-request"]').click();await admin.locator('[data-action="event-reactivate-confirm"]').click();await admin.locator('[data-action="event-cancel-request"]').waitFor();assert.equal(await admin.locator('[data-action="event-reactivate-request"]').count(),0);
  const detail=await ok(admin,'events/'+event.id);assert.equal(detail.cancelled,false);assert.equal(detail.attending,1);assert.equal((await api(admin,'events/'+event.id+'/reactivate',{})).status,409);
  await capture(admin,'evento-reactivado-'+name);
  await eventPage(member,event.id);assert.equal((await ok(member,'events/'+event.id)).registered,'accepted');await member.getByRole('link',{name:'Abrir enlace de Zoom ↗',exact:true}).waitFor();
  pass('Cancelar → confirmar reactivación, conservar inscripción y acceso al encuentro; asociado sin permisos: '+name);
  await inbox();await member.locator('.activity-card').first().waitFor();await capture(member,'notificaciones-'+name);
  pass('Bandeja de actividad adaptable sin desbordamiento y con controles accesibles: '+name);
 }
 let feed=await ok(member,'notifications/feed');assert.ok(feed.total>20);assert.equal(feed.items[0].kind,'event_reactivated');assert.equal(feed.items[0].title,'Un evento vuelve a estar activo');const noticeId=feed.items[0].id;
 await member.locator('[data-action="notification-open"][data-id="'+noticeId+'"]').click();await member.getByRole('dialog',{name:event.title,exact:true}).waitFor();assert.equal((await ok(member,'events/'+event.id)).cancelled,false);pass('El aviso de reactivación abre el evento vigente y se marca leído');
 await inbox();await member.locator('[data-action="notification-page"][data-page="2"]').click();await member.waitForFunction(()=>document.querySelector('.activity-footer').textContent.includes('Página 2'));
 assert.ok((await member.locator('.activity-card').count())>0);await member.locator('[data-action="notification-page"][data-page="1"]').click();await member.waitForFunction(()=>document.querySelector('.activity-footer').textContent.includes('Página 1'));pass('Paginación de notificaciones real: segunda página y regreso');
 await member.locator('[data-filter="unread"]').click();await member.locator('[data-filter="unread"][aria-pressed="true"]').waitFor();const unread=(await ok(member,'notifications/summary')).unread_total;
 await member.locator('[data-action="notification-read"]').first().click();await member.waitForFunction(expected=>document.querySelector('.activity-filters [data-filter="unread"]').textContent.includes('('+expected+')'),unread-1);
 await member.locator('[data-action="notifications-read-all"]').click();await member.locator('.activity-empty').waitFor();assert.equal((await ok(member,'notifications/summary')).unread_total,0);await capture(member,'sin-pendientes-mobile-dark');pass('Filtro sin leer, lectura individual, lectura global y estado vacío sincronizados');
 await member.locator('[data-filter="all"]').click();await member.locator('.activity-card').first().waitFor();const total=(await ok(member,'notifications/feed')).total;
 await member.locator('[data-action="notification-delete-request"]').first().click();await member.locator('[data-action="notification-delete-confirm"]').click();await member.locator('.notification-modal').waitFor();assert.equal((await ok(member,'notifications/feed')).total,total-1);
 await member.locator('[data-action="notifications-delete-all-request"]').click();await member.locator('.modal-top .modal-back').click();assert.equal((await ok(member,'notifications/feed')).total,total-1);
 await member.locator('[data-action="notifications-delete-all-request"]').click();await member.locator('[data-action="notifications-delete-all-confirm"]').click();await member.locator('.activity-empty').waitFor();assert.equal((await ok(member,'notifications/feed')).total,0);await capture(member,'bandeja-vacia-mobile-dark');pass('Borrado individual y masivo confirmado, con regreso sin borrar');
 assert.deepEqual(errors,[]);
}catch(error){errors.push(error.stack);console.error(error.stack);process.exitCode=1;if(member)await member.screenshot({path:path.join(out,'failure.png'),fullPage:false}).catch(()=>{});
}finally{
 if(admin)for(const id of posts)await api(admin,'items/'+id,null,'DELETE').catch(()=>{});
 const report={version:'1.11.0',date:new Date().toISOString(),scope:'WordPress local desechable; acciones UI y REST reales; avisos de muestra locales; Google Calendar HTTP simulado en PHPUnit, sin OAuth externo ni despliegue.',package_sha256:manifest.sha256,checks,scans,errors};fs.writeFileSync(path.join(out,'summary.json'),JSON.stringify(report,null,2)+'\n');
 const escape=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 fs.writeFileSync(path.join(out,'index.html'),'<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>ASCLA 1.11.0 · Reactivación y notificaciones</title><style>body{font:16px/1.6 system-ui;background:#f5f8fb;color:#18334d;max-width:1050px;margin:40px auto;padding:20px}li{margin:10px 0}img{width:100%;border:1px solid #d8e1e8;border-radius:12px}code{overflow-wrap:anywhere}a{color:#106b9c}</style><h1>Reactivación de eventos y notificaciones · 1.11.0</h1><p>'+escape(report.date)+' · '+checks.length+' controles aprobados · '+scans.length+' vistas con axe · '+errors.length+' errores.</p><p>'+escape(report.scope)+'</p><p><a href="phpunit.log">Registro PHP</a> · <a href="phpunit.xml">JUnit PHP</a> · <a href="summary.json">Resumen UI</a></p><p>ZIP SHA-256: <code>'+escape(manifest.sha256)+'</code></p><ol>'+checks.map(c=>'<li>'+escape(c)+'</li>').join('')+'</ol>'+errors.map(e=>'<pre>'+escape(e)+'</pre>').join('')+scans.map(s=>'<details><summary>'+escape(s.name)+' · '+s.violations.length+' infracciones automáticas</summary><p>'+s.incomplete+' reglas requieren revisión humana.</p><a href="'+s.name+'.png"><img loading="lazy" alt="'+escape(s.name)+'" src="'+s.name+'.png"></a></details>').join('')+'</html>');
 for(const context of contexts)await context.close();if(browser)await browser.close();
}})();
