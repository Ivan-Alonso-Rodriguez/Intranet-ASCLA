/* Local acceptance for publication, reactions, moderation and gettext. */
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
 const suffix=crypto.randomBytes(4).toString('hex');
 for(const label of ['writer','reader']){
  const username='editorial_'+label+'_'+suffix,password='J9!rV2#'+crypto.randomBytes(18).toString('base64url');
  const user=await ok(admin,'native:users',{username,password,email:username+'@example.invalid',first_name:'Editorial',last_name:label+suffix,roles:['ascla_member']});
  users.push({...user,page:await login(username,password)});
 }
 const [writer,reader]=users;
 const hub=await ok(writer.page,'content/hub',{title:'Hub institucional '+suffix,body:'Publicación manual de aceptación '+suffix,status:'publish'});posts.push(hub.id);assert.equal(hub.status,'publish');assert.equal((await ok(reader.page,'items/'+hub.id)).title,hub.title);
 const draft=await ok(writer.page,'content/hub',{title:'Borrador '+suffix,body:'Contenido aún privado',status:'draft'});posts.push(draft.id);assert.equal(draft.status,'draft');assert.equal((await api(reader.page,'items/'+draft.id)).status,404);
 await goto(writer.page,'hub/');await writer.page.locator('[data-action="editor"][data-type="hub"]').first().click();assert.equal(await writer.page.locator('[data-form="editor"] [name="status"]').inputValue(),'publish');await writer.page.keyboard.press('Escape');
 const mine=await ok(writer.page,'content/hub?mine=1&q='+suffix);assert.ok(mine.items.some(p=>p.id===hub.id));assert.ok(mine.items.some(p=>p.id===draft.id));
 pass('Hub: publicación explícita inmediata, borrador privado, editor e historial propio');
 const topic=await ok(writer.page,'content/topic',{title:'Tema '+suffix,body:'Tema publicado para probar reacciones',status:'publish'});posts.push(topic.id);const comment=await ok(writer.page,'items/'+topic.id+'/comments',{body:'Comentario del foro'});
 const route='items/'+topic.id+'/reaction';
 const reaction=await ok(reader.page,route,{kind:'like',active:true});assert.equal(reaction.reaction,'like');assert.equal(reaction.reaction_total,1);
 assert.deepEqual(reaction.reaction_counts,{like:1});
 for(const removed of ['useful','celebrate']){
  assert.equal((await api(reader.page,route,{kind:'like',reaction:removed,active:true})).status,400);
  assert.equal((await api(reader.page,'comments/'+comment.id+'/reaction',{reaction:removed,active:true})).status,400);
 }
 await Promise.all(Array.from({length:3},()=>ok(reader.page,route,{kind:'like',reaction:'like',active:true})));
 assert.equal((await ok(reader.page,'items/'+topic.id)).reaction_total,1);
 assert.equal((await api(reader.page,route,{kind:'like',reaction:'invalid',active:true})).status,400);
 await ok(writer.page,route,{kind:'like',reaction:'like',active:true});assert.equal((await ok(reader.page,'items/'+topic.id)).reaction_total,2);
 await ok(reader.page,route,{kind:'like',active:false});assert.equal((await ok(reader.page,'items/'+topic.id)).reaction_total,1);
 pass('Solo Me gusta: API rechaza las reacciones retiradas; concurrencia sin duplicados y retiro');
 await goto(reader.page,'foros/?item='+topic.id);
 const topicButton=reader.page.locator('[data-action="content-reaction"][data-comment="false"][data-id="'+topic.id+'"][data-reaction="like"]');await topicButton.click();await reader.page.waitForFunction(id=>document.querySelector('[data-action="content-reaction"][data-comment="false"][data-id="'+id+'"][data-reaction="like"]')?.getAttribute('aria-pressed')==='true',topic.id);
 const commentButton=reader.page.locator('[data-action="content-reaction"][data-comment="true"][data-id="'+comment.id+'"][data-reaction="like"]');await commentButton.click();await reader.page.waitForFunction(id=>document.querySelector('[data-action="content-reaction"][data-comment="true"][data-id="'+id+'"][data-reaction="like"]')?.getAttribute('aria-pressed')==='true',comment.id);
 assert.equal((await ok(reader.page,'items/'+topic.id+'/comments')).find(c=>c.id===comment.id).reaction,'like');
 assert.deepEqual(await reader.page.locator('.reaction-picker').evaluateAll(groups=>groups.map(group=>[...group.querySelectorAll('button')].map(button=>button.dataset.reaction))),[['like'],['like']]);
 await reader.page.screenshot({path:root+'/test-results/editorial-reactions.png'});
 pass('Temas y comentarios muestran únicamente Me gusta, con estado accesible');
 const resource=await ok(admin,'content/resource',{title:'Recurso '+suffix,body:'Recurso publicado para comprobar Me gusta',status:'publish'});posts.push(resource.id);
 for(const [item,section] of [[hub,'hub'],[resource,'centro-conocimiento']]){
  await goto(reader.page,section+'/?item='+item.id);
  const buttons=reader.page.locator('.reaction-picker [data-comment="false"][data-id="'+item.id+'"]');await buttons.first().waitFor();
  assert.deepEqual(await buttons.evaluateAll(nodes=>nodes.map(node=>node.dataset.reaction)),['like']);
  await buttons.click();await reader.page.waitForFunction(id=>document.querySelector('.reaction-picker [data-comment="false"][data-id="'+id+'"]')?.getAttribute('aria-pressed')==='true',item.id);
 }
 pass('Hub y Centro de Conocimiento también conservan únicamente Me gusta');
 const moderation='admin/comments/'+comment.id;
 assert.equal((await api(reader.page,moderation,{decision:'reject',reason:'Sin permisos'})).status,403);
 assert.equal((await api(admin,moderation,{decision:'reject',reason:'  '})).status,400);
 assert.equal((await api(admin,moderation,{decision:'invalid',reason:'Decisión inválida'})).status,400);
 await ok(admin,moderation,{decision:'reject',reason:'Oculto para revisión de contexto'});
 assert.ok(!(await ok(reader.page,'items/'+topic.id+'/comments')).some(c=>c.id===comment.id));
 assert.equal((await api(reader.page,'comments/'+comment.id+'/reaction',{active:true})).status,404);
 await goto(admin,'administracion/?section=moderacion');await admin.locator('[data-action="comment-moderate"][data-id="'+comment.id+'"][data-decision="approve"]').click();
 let decisionForm=admin.locator('[data-form="review-decision"]');assert.equal(await decisionForm.locator('[name="reason"]').getAttribute('required'),'');await decisionForm.locator('[name="reason"]').fill('Contexto revisado, comentario permitido');
 await Promise.all([admin.waitForResponse(r=>r.url().endsWith('/'+moderation)&&r.request().method()==='POST'&&r.status()===200),decisionForm.locator('button[type="submit"],button:not([type])').click()]);
 assert.ok((await ok(reader.page,'items/'+topic.id+'/comments')).some(c=>c.id===comment.id));
 let decisionAudit=(await ok(admin,'admin')).audit.find(row=>row.action==='comment_moderation'&&Number(row.object_id)===comment.id);let decisionDiff=JSON.parse(decisionAudit.detail);
 assert.equal(decisionDiff.state.before,'0');assert.equal(decisionDiff.state.after,'1');assert.equal(decisionDiff.reason.before,'Oculto para revisión de contexto');assert.equal(decisionDiff.reason.after,'Contexto revisado, comentario permitido');assert.equal(decisionDiff.context.post_id,topic.id);
 pass('Moderación de comentarios: permisos, motivo obligatorio, formulario y auditoría antes/después');
 await ok(reader.page,'comments/'+comment.id+'/report',{reason:'spam'});
 let report=(await ok(admin,'admin')).reports.find(r=>r.report_type==='comment'&&Number(r.comment_id)===comment.id);assert.ok(report);
 assert.equal((await api(admin,'admin/reports/'+report.id,null,'DELETE')).status,409);
 assert.equal((await api(admin,'admin/reports/'+report.id+'/review',{})).status,400);
 assert.equal((await api(reader.page,'admin/reports/'+report.id+'/review',{reason:'Sin permisos'})).status,403);
 await goto(admin,'administracion/?section=moderacion');await admin.locator('[data-action="report-reviewed"][data-id="'+report.id+'"]').click();decisionForm=admin.locator('[data-form="review-decision"]');await decisionForm.locator('[name="reason"]').fill('Reporte evaluado; el comentario cumple las normas');
 await Promise.all([admin.waitForResponse(r=>r.url().endsWith('/admin/reports/'+report.id+'/review')&&r.request().method()==='POST'&&r.status()===200),decisionForm.locator('button[type="submit"],button:not([type])').click()]);
 decisionAudit=(await ok(admin,'admin')).audit.find(row=>row.action==='report_reviewed'&&Number(row.object_id)===Number(report.id));decisionDiff=JSON.parse(decisionAudit.detail);assert.equal(decisionDiff.state.before,'pending');assert.equal(decisionDiff.state.after,'reviewed');assert.equal(decisionDiff.reason.after,'Reporte evaluado; el comentario cumple las normas');assert.equal(decisionDiff.context.target_id,comment.id);assert.equal(Number(decisionAudit.actor_id),decisionDiff.reviewed_by.after);assert.ok(decisionDiff.reviewed_at.after);
 await ok(admin,'admin/reports/'+report.id,null,'DELETE');
 decisionAudit=(await ok(admin,'admin')).audit.find(row=>row.action==='report_deleted'&&Number(row.object_id)===Number(report.id));decisionDiff=JSON.parse(decisionAudit.detail);assert.equal(decisionDiff.state.before,'reviewed');assert.equal(decisionDiff.state.after,'deleted');assert.equal(decisionDiff.context.kind,'comment_report');assert.ok((await ok(reader.page,'items/'+topic.id+'/comments')).some(c=>c.id===comment.id));
 pass('Reportes: justificación, actor y UTC obligatorios; la auditoría conserva el resultado después de eliminarlos');
 await ok(reader.page,'items/'+topic.id+'/report',{reason:'other',detail:'Detalle de denuncia reservado'});
 report=(await ok(admin,'admin')).reports.find(r=>r.report_type==='content'&&Number(r.target_id)===topic.id);await ok(admin,'admin/reports/'+report.id+'/review',{reason:'Revisión de publicación completada'});
 decisionAudit=(await ok(admin,'admin')).audit.find(row=>row.action==='report_reviewed'&&Number(row.object_id)===Number(report.id));assert.equal(JSON.parse(decisionAudit.detail).context.kind,'report');assert.ok(!decisionAudit.detail.includes('Detalle de denuncia reservado'));
 pass('Reportes de publicaciones también registran resolución sin copiar la denuncia privada a la bitácora');
 const previous=(await ok(admin,'admin')).audit.reduce((n,row)=>Math.max(n,Number(row.id)),0);
 await ok(admin,'items/'+topic.id+'/moderate',{decision:'hide',reason:'Contenido retirado para revisión QA'});
 assert.equal((await api(reader.page,'comments/'+comment.id+'/reaction',{active:true,reaction:'like'})).status,404);
 const audit=(await ok(admin,'admin')).audit.find(row=>Number(row.id)>previous&&row.action==='moderation'&&Number(row.object_id)===topic.id);assert.ok(audit);const diff=JSON.parse(audit.detail);assert.equal(diff.state.before,'publish');assert.equal(diff.state.after,'ascla_hidden');assert.equal(diff.reason.after,'Contenido retirado para revisión QA');
 pass('Retirar contenido impide nuevas reacciones y registra estado/motivo antes y después');
 await ok(reader.page,'native:users/me',{locale:'en_US'});await goto(reader.page,'directorio/');assert.equal(await reader.page.evaluate(()=>ASCLA.translations['Me gusta']),'Like');assert.ok(await reader.page.getByRole('heading',{name:'Your professional network'}).isVisible());
 assert.equal(await writer.page.evaluate(()=>ASCLA.language),'es');
 await reader.page.setViewportSize({width:390,height:900});await reader.page.screenshot({path:root+'/test-results/editorial-english-mobile.png'});assert.ok(await reader.page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
 pass('Inglés por cuenta, diccionario gettext para JavaScript y móvil sin desbordamiento');
 assert.deepEqual(errors,[]);fs.writeFileSync(root+'/test-results/editorial107.json',JSON.stringify({checks,errors},null,2));
}catch(error){console.error(error.stack);fs.writeFileSync(root+'/test-results/editorial107.json',JSON.stringify({checks,errors,failure:error.message},null,2));process.exitCode=1;}finally{
 if(admin){for(const id of posts)await api(admin,'items/'+id,null,'DELETE').catch(()=>{});for(const user of users)await api(admin,'native:users/'+user.id+'?force=true&reassign=1',null,'DELETE').catch(()=>{});}
 if(browser)await browser.close();
}})();
