/* Additional 1.11.0 UI checks against a disposable loopback WordPress. */
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const fixture=JSON.parse(fs.readFileSync(process.env.ASCLA_UX_FIXTURE||'test-results/sonar-preparation/release1110-users.json','utf8'));
if(fixture.origin!=='ascla-local-test-users'||!['localhost','127.0.0.1'].includes(new URL(fixture.base).hostname))throw Error('Disposable shared users required');
const out=path.resolve('test-results/visual1110');fs.mkdirSync(out,{recursive:true});
const checks=[],scans=[],errors=[],posts=[],contexts=[];let browser,admin,member;
const manifest=JSON.parse(fs.readFileSync('docs/evidence/release-1.11.0/package.json','utf8'));
function pass(name){checks.push(name);console.log('PASS '+name);}
async function api(page,route,body,method){return page.evaluate(async({route,body,method})=>{
 const response=await fetch(ASCLA.api+route,{method:method||(body?'POST':'GET'),headers:{'X-WP-Nonce':ASCLA.nonce,'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})});
 return{status:response.status,body:await response.json()};
},{route,body,method});}
async function ok(page,route,body,method){const result=await api(page,route,body,method);assert.ok(result.status>=200&&result.status<300,route+': '+result.status+' '+result.body.message);return result.body;}
async function go(page,route){await page.goto(fixture.base+'/'+route);await page.locator('#page-content h1').waitFor();}
async function login(actor){const context=await browser.newContext({viewport:{width:1440,height:1000},timezoneId:'America/Lima'});contexts.push(context);const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));await page.goto(fixture.base+'/wp-login.php');await page.locator('#user_login').fill(fixture.actors[actor].login);await page.locator('#user_pass').fill(fixture.actors[actor].password);await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);await go(page,'intranet/');return page;}
async function capture(page,name){
 assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),name+': horizontal overflow');
 if(!await page.evaluate(()=>!!window.axe))await page.addScriptTag({path:process.env.ASCLA_AXE_PATH||require.resolve('axe-core/axe.min.js')});
 const result=await page.evaluate(()=>axe.run(document.querySelector('#ascla-root'),{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa']}}));
 scans.push({name,violations:result.violations.map(v=>({id:v.id,impact:v.impact,nodes:v.nodes.map(n=>n.target)})),incomplete:result.incomplete.length});
 assert.deepEqual(scans.at(-1).violations,[],name+': axe accessibility');
 await page.screenshot({path:path.join(out,name+'.png'),fullPage:true});
}
async function neutral(locator){const colors=await locator.evaluate(n=>{const s=getComputedStyle(n);return{actual:s.backgroundColor,expected:s.getPropertyValue('--state-bg').trim(),color:s.color};});assert.ok(colors.actual!=='rgb(255, 255, 255)');assert.ok(colors.expected);return colors;}
(async()=>{try{
 browser=await chromium.launch({headless:true,channel:fixture.browser_channel||'msedge'});admin=await login('Administrador');member=await login('Ana');
 for(const [i,modality] of ['Virtual','Híbrido','Presencial'].entries()){
  const event=await ok(admin,'content/event',{title:['Gobierno corporativo: perspectivas de la comunidad','Encuentro de secretarios corporativos','Taller de gestión de riesgos'][i],body:'Compartamos experiencias y herramientas para fortalecer el gobierno corporativo en nuestra comunidad.',status:'publish',meta:{start:new Date(Date.now()+(i+2)*86400000).toISOString(),end:new Date(Date.now()+(i+2)*86400000+3600000).toISOString(),capacity:30,modality,chatham:i===0,location:i===0?'En línea':'Lima · Centro de encuentros ASCLA'}});posts.push(event.id);
 }
 await ok(admin,'events/'+posts[2]+'/cancel',{reason:'Cancelación de prueba visual en laboratorio aislado.'});
 for(const theme of ['light','dark'])for(const [screen,viewport] of [['desktop',{width:1440,height:1000}],['mobile',{width:390,height:844}]]){
  await member.setViewportSize(viewport);await member.evaluate(theme=>{localStorage.setItem('ascla-theme',theme);document.documentElement.dataset.asclaTheme=theme;},theme);
  const name=screen+'-'+theme;
  await go(member,'intranet/');assert.equal(await member.locator('.stat-link').count(),4);await capture(member,'inicio-'+name);
  for(const route of ['directorio','eventos','centro-conocimiento']){
   const link=member.locator('.stat-link[href="'+fixture.base+'/'+route+'/"]');assert.equal(await link.count(),1);await link.focus();await member.keyboard.press('Enter');await member.waitForURL('**/'+route+'/');await member.locator('#page-content h1').waitFor();await go(member,'intranet/');
  }
  await member.locator('.stat-link[data-action="notifications"]').focus();await member.keyboard.press('Space');await member.locator('.notification-modal').waitFor();await member.keyboard.press('Escape');await member.locator('.notification-modal').waitFor({state:'hidden'});pass('Indicadores navegables con teclado y bandeja de notificaciones: '+name);
  await go(member,'directorio/');await member.locator('.member-card-actions').first().waitFor();
  const actions=member.locator('.member-card').filter({hasText:'Bruno Prueba ASCLA'}).locator('.member-card-actions .btn');assert.equal(await actions.count(),3);
  const boxes=await actions.evaluateAll(nodes=>nodes.map(n=>{const r=n.getBoundingClientRect();return{top:r.top,bottom:r.bottom,font:getComputedStyle(n).font};}));
  const gaps=[boxes[1].top-boxes[0].bottom,boxes[2].top-boxes[1].bottom];assert.ok(gaps.every(g=>Math.abs(g-9)<1),'Equal compact gaps: '+gaps);
  const own=member.locator('.member-card').filter({hasText:'Ana Prueba ASCLA'}).locator('.member-card-actions .btn');assert.equal(await own.count(),2);const fonts=await own.evaluateAll(nodes=>nodes.map(n=>getComputedStyle(n).font));assert.equal(fonts[0],fonts[1]);assert.equal(fonts[0],boxes[0].font);
  const columns=await member.locator('.cards.directory').evaluate(n=>getComputedStyle(n).gridTemplateColumns.split(' ').length);assert.equal(columns,screen==='desktop'?4:1);await capture(member,'directorio-'+name);pass('Directorio: tipografía uniforme, dos espacios de 9 px y columnas adaptables: '+name);
  await go(member,'eventos/');await member.locator('.event-card').first().waitFor();assert.ok(await member.locator('.event-card-date strong').count());assert.ok(await member.locator('.event-card-location').count());await capture(member,'eventos-'+name);
  await go(member,'eventos/?item='+posts[0]);await member.locator('.alert.chatham').waitFor();const published=await neutral(member.locator('.modal .status.publish').first()),chatham=await neutral(member.locator('.alert.chatham'));assert.equal(published.actual,chatham.actual);await capture(member,'detalle-'+name);await member.keyboard.press('Escape');pass('Eventos: fecha destacada, horario y lugar; Publicado y Chatham House neutros: '+name);
 }
 await go(member,'eventos/');await member.locator('.event-card.is-cancelled').waitFor();assert.equal(await member.locator('.event-card.is-cancelled').filter({hasText:'Cancelado'}).count(),1);await capture(member,'evento-cancelado');pass('El evento cancelado conserva su estado visible y una tarjeta legible');
 assert.deepEqual(errors,[]);
}catch(error){errors.push(error.stack);console.error(error.stack);process.exitCode=1;if(member)await member.screenshot({path:path.join(out,'failure.png'),fullPage:true}).catch(()=>{});
}finally{
 if(admin)for(const id of posts)await api(admin,'items/'+id,null,'DELETE').catch(()=>{});
 const report={version:'1.11.0',date:new Date().toISOString(),scope:'isolated local WordPress; desktop 1440x1000 and mobile 390x844; light and dark; no remote deployment or security scan',package_sha256:manifest.sha256,checks,scans,errors};
 fs.writeFileSync(path.join(out,'summary.json'),JSON.stringify(report,null,2)+'\n');
 const escape=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 fs.writeFileSync(path.join(out,'index.html'),'<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>ASCLA 1.11.0 · Revisión visual</title><style>body{font:16px/1.6 system-ui;background:#f5f8fb;color:#18334d;max-width:1050px;margin:40px auto;padding:20px}li{margin:10px 0}img{width:100%;border:1px solid #d8e1e8;border-radius:12px}code{overflow-wrap:anywhere}a{color:#106b9c}</style><h1>ASCLA Core 1.11.0 · Revisión visual</h1><p>'+escape(report.date)+' · Laboratorio local aislado · '+checks.length+' controles aprobados · '+scans.length+' vistas con axe · '+errors.length+' errores.</p><p>ZIP SHA-256: <code>'+escape(manifest.sha256)+'</code>. No acredita despliegue, SonarQube ni un nuevo análisis de seguridad.</p><ol>'+checks.map(c=>'<li>'+escape(c)+'</li>').join('')+'</ol>'+errors.map(e=>'<pre>'+escape(e)+'</pre>').join('')+scans.map(s=>'<details><summary>'+escape(s.name)+' · '+s.violations.length+' infracciones automáticas</summary><p>axe: '+s.incomplete+' reglas requieren revisión humana. Las capturas se revisan visualmente.</p><a href="'+s.name+'.png"><img loading="lazy" alt="'+escape(s.name)+'" src="'+s.name+'.png"></a></details>').join('')+'</html>');
 for(const context of contexts)await context.close();if(browser)await browser.close();
}})();
