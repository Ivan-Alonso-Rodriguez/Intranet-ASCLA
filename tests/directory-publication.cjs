/* Local browser acceptance for directory visibility, layout and scheduled publication. */
const {chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const root=path.resolve(__dirname,'..'),base=process.env.ASCLA_SITE_URL||'http://ascla_audit-wordpress';
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Disposable environment required');
const env=Object.fromEntries(fs.readFileSync(process.env.ASCLA_ENV_FILE||root+'/.env.audit','utf8').split(/\r?\n/).filter(v=>v.includes('=')).map(v=>[v.slice(0,v.indexOf('=')),v.slice(v.indexOf('=')+1)]));
const checks=[],errors=[],users=[],posts=[];let browser,admin;
async function api(page,route,body,method){return page.evaluate(async({route,body,method})=>{const url=route.startsWith('native:')?ASCLA.api.replace(/ascla\/v1\/?$/,'wp/v2/')+route.slice(7):ASCLA.api+route;const r=await fetch(url,{method:method||(body?'POST':'GET'),headers:{'X-WP-Nonce':ASCLA.nonce,'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})});return{status:r.status,body:await r.json()};},{route,body,method});}
async function ok(page,route,body,method){const r=await api(page,route,body,method);assert.ok(r.status>=200&&r.status<300,route+': '+r.status+' '+r.body.message);return r.body;}
async function login(name,password){const context=await browser.newContext({viewport:{width:1440,height:1000}}),p=await context.newPage();p.on('pageerror',e=>errors.push(e.message));await p.goto(base+'/wp-login.php');await p.locator('#user_login').fill(name);await p.locator('#user_pass').fill(password);await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}),p.locator('#wp-submit').click()]);await p.goto(base+'/intranet/');await p.locator('.hero').waitFor();await p.keyboard.press('Escape');return p;}
async function goto(p,route){await p.bringToFront();await p.goto(base+'/'+route);await p.locator('#page-content h1').waitFor();await p.keyboard.press('Escape');}
function pass(name){checks.push(name);console.log('PASS '+name);}
(async()=>{try{
 browser=await chromium.launch({headless:true});admin=await login('ascla.admin',env.ASCLA_ADMIN_PASSWORD);
 const catalog=(await ok(admin,'bootstrap')).catalogs,suffix=crypto.randomBytes(4).toString('hex');
 const profile={first_name:'Directorio',last_name:'QA '+suffix,position:'Analista',company:'Prueba de directorio',country:'Perú',city:'Lima',bio:'Perfil de aceptación',industries:[catalog.industry[0].id],interests:[catalog.interest[0].id],goals:[catalog.goal[0].id],areas:[catalog.area[0].id]};
 for(const name of ['Incomplete','Private','Inactive','Viewer']){
  const username='directory_'+name.toLowerCase()+'_'+suffix,password='K8!tW3#'+crypto.randomBytes(18).toString('base64url');
  const user=await ok(admin,'native:users',{username,password,email:username+'@example.invalid',first_name:name,last_name:suffix,roles:['ascla_member']});
  users.push({...user,page:await login(username,password)});
 }
 const [incomplete,privateUser,inactive,viewer]=users;
 await ok(privateUser.page,'profiles/me',{...profile,first_name:'Private',directory:false});
 await ok(inactive.page,'profiles/me',{...profile,first_name:'Inactive'});
 await ok(admin,'admin/member/'+inactive.id,{suspended:true});
 await ok(viewer.page,'profiles/me',{...profile,first_name:'Viewer',position:'Confidencial '+suffix,hidden:['position']});
 const results=await ok(viewer.page,'profiles?q='+suffix);
 assert.ok(results.items.some(p=>p.id===incomplete.id),'Incomplete active profile missing');
 assert.ok(!results.items.some(p=>[privateUser.id,inactive.id].includes(p.id)),'Private or inactive account leaked');
 assert.equal((await ok(admin,'profiles?q='+encodeURIComponent('Confidencial '+suffix))).total,0);
 pass('Directorio incluye incompletos y respeta privacidad, campos ocultos y suspensión');
 await ok(viewer.page,'relations',{target:incomplete.id,kind:'block',active:true});
 assert.ok(!(await ok(viewer.page,'profiles?q='+suffix)).items.some(p=>p.id===incomplete.id));
 await ok(viewer.page,'relations',{target:incomplete.id,kind:'block',active:false});
 pass('Directorio excluye bloqueados y recupera su visibilidad al desbloquear');
 const first=await ok(viewer.page,'profiles'),ids=[];
 for(let page=1;page<=first.pages;page++){const result=await ok(viewer.page,'profiles?page='+page);ids.push(...result.items.map(p=>p.id));}
 assert.equal(ids.length,first.total);assert.equal(new Set(ids).size,first.total);pass('Paginación recorre todas las personas visibles sin duplicados');
 await goto(viewer.page,'directorio/');
 await viewer.page.locator('#directory-search').fill(suffix);await viewer.page.locator('.directory-search button[type="submit"], .directory-search button.btn.primary').click();
 await viewer.page.waitForFunction(()=>document.querySelector('.directory-results-heading')?.innerText.includes('2 perfiles'));
 await viewer.page.locator('[data-action="directory-reset"]').click();await viewer.page.waitForFunction(()=>document.querySelector('#directory-search')?.value==='');
 await viewer.page.locator('.directory-facet').first().locator('summary').click();
 await viewer.page.locator('.directory-facet input[name="industries"]').first().check();
 assert.equal(await viewer.page.locator('.directory-facet').first().locator('[data-facet-count]').innerText(),'1');
 await viewer.page.locator('.directory-search button.btn.primary').click();
 await viewer.page.waitForFunction(()=>document.querySelector('.directory-facet [data-facet-count]')?.textContent==='1'&&!document.querySelector('.view-loading'));
 await viewer.page.locator('[data-action="directory-reset"]').click();
 pass('Filtros con casillas, búsqueda y limpieza funcionan sin Ctrl/Cmd');
 for(const width of [1920,1440,768,390]){
  await viewer.page.setViewportSize({width,height:960});await goto(viewer.page,'directorio/');await viewer.page.locator('.connections-overview > summary').waitFor();
  await viewer.page.evaluate(()=>{document.documentElement.dataset.asclaTheme='dark';});
  assert.ok(await viewer.page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Overflow at '+width);
  if(width>=1440)assert.ok(await viewer.page.evaluate(()=>document.querySelector('#page-content').getBoundingClientRect().x-document.querySelector('.ascla-sidebar').getBoundingClientRect().right<65),'Unnecessary left gap');
  await viewer.page.screenshot({path:root+'/test-results/directory-'+width+'.png',fullPage:width!==390});
 }
 pass('Directorio verificado a 1920, 1440, 768 y 390 px');
 const input={title:'Publicación programada '+suffix,body:'Contenido institucional de aceptación.',status:'scheduled',publish_at:new Date(Date.now()+120000).toISOString(),meta:{start:new Date(Date.now()+3*86400000).toISOString(),end:new Date(Date.now()+3*86400000+3600000).toISOString(),capacity:8}};
 assert.equal((await api(viewer.page,'content/hub',input)).status,403);
 assert.equal((await api(admin,'content/event',{...input,publish_at:new Date(Date.now()-1000).toISOString()})).status,400);
 let event=await ok(admin,'content/event',input);posts.push(event.id);assert.equal(event.status,'ascla_hidden');assert.equal((await api(viewer.page,'items/'+event.id)).status,404);
 await goto(admin,'eventos/?item='+event.id);await admin.locator('[data-action="editor"][data-id="'+event.id+'"]').click();
 const form=admin.locator('[data-form="editor"]');assert.equal(await form.locator('[name="status"]').inputValue(),'scheduled');assert.ok(await form.locator('[name="publish_at"]').isVisible());
 await form.locator('[name="status"]').selectOption('draft');assert.equal(await form.locator('[name="publish_at"]').isVisible(),false);
 await form.locator('[name="status"]').selectOption('scheduled');await admin.screenshot({path:root+'/test-results/publication-editor.png'});
 // Leave the unchanged form before submitting date changes through the API.
 await admin.reload();await admin.keyboard.press('Escape');
 event=await ok(admin,'content/event/'+event.id,{...input,status:'draft'});assert.equal(event.status,'draft');assert.equal(event.meta.publish_at,undefined);
 pass('Programación privada, permisos, fecha futura, editor y cancelación comprobados');
 event=await ok(admin,'content/event/'+event.id,{...input,publish_at:new Date(Date.now()+15000).toISOString()});
 console.log('WAITING for real scheduled publication '+event.id);
 const deadline=Date.now()+55000;
 while(Date.now()<deadline){await new Promise(resolve=>setTimeout(resolve,1500));const current=await api(viewer.page,'items/'+event.id);if(current.status===200&&current.body.status==='publish')break;}
 const published=await ok(viewer.page,'items/'+event.id);assert.equal(published.status,'publish');assert.ok(published.meta.published_at);assert.equal(published.meta.publish_at,undefined);
 pass('WP-Cron publica automáticamente al alcanzar la hora real');
 const scheduled=[],when=new Date(Date.now()+18000).toISOString();
 for(const type of ['gallery','resource','hub','ally']){
  const post=await ok(admin,'content/'+type,{title:'Programación '+type+' '+suffix,body:'Publicación de aceptación por WP-Cron',status:'scheduled',publish_at:when});posts.push(post.id);scheduled.push(post);
  assert.equal(post.status,'ascla_hidden');assert.equal((await api(viewer.page,'items/'+post.id)).status,404);
 }
 await ok(admin,'native:users/'+incomplete.id,{roles:['ascla_executive']});
 const revoked=await ok(incomplete.page,'content/hub',{title:'Permiso revocado '+suffix,body:'Esta publicación debe seguir oculta',status:'scheduled',publish_at:when});posts.push(revoked.id);
 await ok(admin,'native:users/'+incomplete.id,{roles:['ascla_member']});
 const batchDeadline=Date.now()+55000;
 while(Date.now()<batchDeadline){
  await new Promise(resolve=>setTimeout(resolve,1500));
  const results=await Promise.all(scheduled.map(p=>api(viewer.page,'items/'+p.id)));
  const denied=await ok(admin,'items/'+revoked.id);
  if(results.every(r=>r.status===200&&r.body.status==='publish')&&denied.meta.schedule_error)break;
 }
 for(const post of scheduled){const current=await ok(viewer.page,'items/'+post.id);assert.equal(current.status,'publish');assert.ok(current.meta.published_at);assert.equal(current.meta.publish_at,undefined);}
 pass('WP-Cron publica Galería, Conocimiento, Hub y Aliados con visibilidad correcta antes y después');
 const denied=await ok(admin,'items/'+revoked.id);assert.equal(denied.status,'ascla_hidden');assert.ok(denied.meta.schedule_error);assert.equal((await api(viewer.page,'items/'+revoked.id)).status,404);
 pass('La publicación programada permanece oculta si su autor pierde los permisos');
 assert.deepEqual(errors,[]);fs.writeFileSync(root+'/test-results/directory-publication.json',JSON.stringify({checks,errors},null,2));
}catch(error){console.error(error.stack);fs.writeFileSync(root+'/test-results/directory-publication.json',JSON.stringify({checks,errors,failure:error.message},null,2));process.exitCode=1;}finally{
 if(admin){for(const id of posts)await api(admin,'items/'+id,null,'DELETE').catch(()=>{});for(const user of users)await api(admin,'native:users/'+user.id+'?force=true&reassign=1',null,'DELETE').catch(()=>{});}
 if(browser)await browser.close();
}})();
