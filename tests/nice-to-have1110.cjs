/* Live DOM, permissions and RNF-010 timings on a disposable loopback WordPress. */
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const base=process.env.ASCLA_SITE_URL,out=path.resolve(process.env.ASCLA_TEST_OUT||'test-results/nice-to-have-20261028');
if(process.env.ASCLA_E2E_EPHEMERAL!=='1'||!['127.0.0.1','localhost'].includes(new URL(base).hostname))throw Error('Explicitly disposable loopback required');
const fixture=JSON.parse(fs.readFileSync(process.env.ASCLA_STATISTICS_FIXTURE,'utf8'));
const checks=[],errors=[],scans=[],timings=[],contexts=[];let browser,page;
const pass=name=>{checks.push(name);console.log('PASS '+name);};
async function api(p,route,body){return p.evaluate(async({route,body})=>{const start=performance.now(),r=await fetch(ASCLA.api+route,{method:body?'POST':'GET',headers:{'X-WP-Nonce':ASCLA.nonce,'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})});const data=await r.json();return{status:r.status,data,ms:performance.now()-start};},{route,body});}
async function login(user){const c=await browser.newContext({viewport:{width:1440,height:1050},timezoneId:'America/Lima',reducedMotion:'reduce'});contexts.push(c);const p=await c.newPage();p.on('pageerror',e=>errors.push(e.message));await p.goto(base+'/wp-login.php');await p.locator('#user_login').fill(user.login);await p.locator('#user_pass').fill(user.password);await Promise.all([p.waitForNavigation(),p.locator('#wp-submit').click()]);return p;}
async function stats(p,kind){
 await p.addInitScript(()=>{new MutationObserver(()=>{if(!window.niceRendered&&document.querySelector('[data-stat="active_connections"]'))window.niceRendered=performance.now();}).observe(document,{childList:true,subtree:true});});
 await p.goto(base+'/administracion/?section=estadisticas');await p.locator('[data-stat="active_connections"]').waitFor();
 const measured=await p.evaluate(()=>{const entry=performance.getEntriesByType('resource').find(r=>/\/admin\/statistics(?:\?|$)/.test(r.name));return entry?{apiMs:entry.responseEnd-entry.startTime,apiToRenderMs:window.niceRendered-entry.startTime}:null;});
 assert.ok(measured,'Actual statistics request timing');assert.ok(measured.apiToRenderMs>0&&measured.apiToRenderMs<3000,JSON.stringify(measured));timings.push({kind,...measured});
}
async function capture(name){
 await page.evaluate(()=>document.activeElement?.blur());assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'No horizontal overflow');
 const axe=fs.readFileSync(process.env.ASCLA_AXE_PATH||require.resolve('axe-core/axe.min.js'),'utf8');await page.evaluate(axe);
 const result=await page.evaluate(()=>axe.run(document.querySelector('#admin-panel'),{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa']}}));
 scans.push({name,violations:result.violations.map(v=>({id:v.id,impact:v.impact,targets:v.nodes.map(n=>n.target)}))});assert.deepEqual(scans.at(-1).violations,[],name);
 await page.locator('[data-stat="active_connections"]').scrollIntoViewIfNeeded();await page.screenshot({path:path.join(out,name+'.png'),fullPage:true});
 await page.locator('.stats-community').nth(1).screenshot({path:path.join(out,name+'-detail.png')});
}
(async()=>{try{
 browser=await chromium.launch({headless:true,...(process.env.ASCLA_BROWSER_CHANNEL?{channel:process.env.ASCLA_BROWSER_CHANNEL}:{})});page=await login(fixture.users[0]);await stats(page,'cold');await stats(page,'warm');
 let result=await api(page,'admin/statistics');assert.equal(result.status,200);assert.ok(result.data.summary.events>=100);assert.ok(result.data.summary.registrations>=5000);assert.ok(result.data.summary.attendances>=3000);
 const c=result.data.community;
 for(const [key,value] of Object.entries({active_connections:c.active_connections,accepted_connections:c.accepted_connections,community_publications:c.publications+c.comments,forum_contributions:c.forum_topics+c.forum_comments}))assert.equal(await page.locator(`[data-stat="${key}"] > strong`).innerText(),new Intl.NumberFormat('es-ES').format(value));
 pass('Los cuatro indicadores coinciden con la API sobre el conjunto de carga');
 for(const theme of ['light','dark']){await page.evaluate(t=>{localStorage.setItem('ascla-theme',t);document.documentElement.dataset.asclaTheme=t;},theme);await capture('statistics-desktop-'+theme);await page.setViewportSize({width:390,height:844});await capture('statistics-mobile-'+theme);await page.setViewportSize({width:1440,height:1050});}
 pass('Escritorio y móvil, ambos temas: sin desbordamiento ni infracciones WCAG A/AA en el panel');
 for(const section of ['users','topics','trends','ai','overview']){await page.locator(`[data-stats-action="section"][data-section="${section}"]`).click();await page.locator('#stats-view .stats-section').first().waitFor();}
 pass('Las cinco vistas de estadísticas mantienen su navegación');
 await page.goto(base+'/wp-admin/admin.php?page=ascla-estadisticas');await page.locator('[data-stat="active_connections"]').waitFor();pass('El mismo panel funciona dentro de wp-admin');
 const member=await login(fixture.users[3]);await member.goto(base+'/intranet/');await member.locator('.nav-link').first().waitFor();
 assert.equal((await api(member,'admin/statistics')).status,403);assert.equal(await member.locator('.nav-link[href*="administracion"]').count(),0);pass('Un asociado no puede leer estadísticas ni acceder al menú administrativo');
 result=await api(member,'bootstrap');const bio=result.data.me.bio;
 const concurrent=await Promise.all([...Array.from({length:5},()=>api(page,'admin/statistics')),api(member,'profiles/me',{bio:'Biografía sintética actualizada durante cinco consultas analíticas.'})]);
 for(const r of concurrent){assert.equal(r.status,200);assert.ok(r.ms<3000,'Concurrent operation '+r.ms);}
 timings.push(...concurrent.map((r,i)=>({kind:i===5?'profile-write-during-analytics':'concurrent-analytics',apiMs:r.ms})));
 assert.equal((await api(member,'profiles/me',{bio})).status,200);pass('Cinco consultas simultáneas y un guardado de perfil terminan sin bloqueo, en menos de tres segundos');
 const executive=await login(fixture.users[1]);await stats(executive,'executive');assert.match(await executive.locator('#admin-panel h2').innerText(),/Community statistics/);assert.equal(await executive.locator('[data-stat="active_connections"]').count(),1);pass('Ejecutivo autorizado y traducción inglesa disponibles');
 const moderator=await login(fixture.users[2]);await moderator.goto(base+'/intranet/');await moderator.locator('.nav-link').first().waitFor();assert.equal((await api(moderator,'admin/statistics')).status,403);pass('Moderación no recibe las estadísticas reservadas a Administración y Ejecutivo');
 assert.deepEqual(errors,[]);pass('Sin errores JavaScript durante el recorrido');
 }catch(error){if(page)await page.screenshot({path:path.join(out,'browser-failure.png'),fullPage:true}).catch(()=>{});console.error(error);process.exitCode=1;
 }finally{if(browser)await browser.close();fs.writeFileSync(path.join(out,'browser.json'),JSON.stringify({checks,errors,scans,timings,status:process.exitCode?'FAIL':'PASS'},null,2));}
})();
