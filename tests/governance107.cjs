/* Role authorization and individual permissions against disposable WordPress. */
const {chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),crypto=require('node:crypto');
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Disposable environment required');
const root=path.resolve(__dirname,'..'),base=process.env.ASCLA_SITE_URL||'http://ascla_audit-wordpress';
const env=Object.fromEntries(fs.readFileSync(process.env.ASCLA_ENV_FILE||root+'/.env.audit','utf8').split(/\r?\n/).filter(v=>v.includes('=')).map(v=>[v.slice(0,v.indexOf('=')),v.slice(v.indexOf('=')+1)]));
const checks=[],errors=[],posts=[];let browser,admin,member;
async function api(page,route,body,method){return page.evaluate(async({route,body,method})=>{const url=route.startsWith('native:')?ASCLA.api.replace(/ascla\/v1\/?$/,'wp/v2/')+route.slice(7):ASCLA.api+route;const r=await fetch(url,{method:method||(body?'POST':'GET'),headers:{'X-WP-Nonce':ASCLA.nonce,'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})});return{status:r.status,body:await r.json()};},{route,body,method});}
async function ok(page,route,body,method){const r=await api(page,route,body,method);assert.ok(r.status>=200&&r.status<300,route+': '+r.status);return r.body;}
async function login(name,password){const context=await browser.newContext({viewport:{width:1440,height:1000}}),p=await context.newPage();p.on('pageerror',e=>errors.push(e.message));await p.goto(base+'/wp-login.php');await p.locator('#user_login').fill(name);await p.locator('#user_pass').fill(password);await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}),p.locator('#wp-submit').click()]);await p.goto(base+'/intranet/');await p.locator('.hero').waitFor();await p.keyboard.press('Escape');return p;}
async function edit(){await admin.goto(base+'/administracion/?section=usuarios');await admin.locator('[data-form="admin-filter"][data-area="users"]').waitFor();const search=admin.locator('[data-form="admin-filter"][data-area="users"]');await search.locator('[name="q"]').fill(member.username);await search.locator('button:not([type])').first().click();await admin.locator('[data-action="user-edit"][data-id="'+member.id+'"]').click();const form=admin.locator('[data-form="admin-user-edit"]');await form.waitFor();return form;}
async function save(form){const response=admin.waitForResponse(r=>r.url().endsWith('/admin/users/'+member.id)&&r.request().method()==='POST');await form.locator('button:not([type])').last().click();const r=await response;assert.equal(r.status(),200);await form.waitFor({state:'detached'});return r.json();}
function pass(name){checks.push(name);console.log('PASS '+name);}
(async()=>{try{
 browser=await chromium.launch({headless:true});admin=await login('ascla.admin',env.ASCLA_ADMIN_PASSWORD);
 const suffix=crypto.randomBytes(4).toString('hex'),password='J9!sK4#'+crypto.randomBytes(18).toString('base64url'),username='governance_'+suffix;
 member=await ok(admin,'native:users',{username,password,email:username+'@example.invalid',first_name:'Gestión',last_name:suffix,roles:['ascla_member']});member.username=username;member.page=await login(username,password);
 const boot=await ok(member.page,'bootstrap');await ok(member.page,'profiles/me',{first_name:'Gestión',last_name:suffix,position:'Analista',company:'Organización',city:'Lima',bio:'Descripción profesional',industries:[Number(boot.catalogs.industry[0].id)],interests:[Number(boot.catalogs.interest[0].id)],goals:[Number(boot.catalogs.goal[0].id)]});
 let form=await edit();await form.locator('[name="permission_ascla_write"]').selectOption('deny');await save(form);
 const denied=await api(member.page,'content/hub',{title:'No autorizado',body:'Sin permiso de escritura',status:'publish'});if(denied.status===200)posts.push(denied.body.id);assert.equal(denied.status,403);
 let state=await ok(admin,'admin/users/'+member.id);assert.equal(state.permission_overrides.ascla_write,false);
 pass('Denegar escritura se persiste desde el formulario y bloquea publicaciones en el servidor');
 form=await edit();await form.locator('[name="permission_ascla_access"]').selectOption('deny');await form.locator('[name="permission_ascla_write"]').selectOption('');await save(form);
 assert.equal((await api(member.page,'bootstrap')).status,403);state=await ok(admin,'admin/users/'+member.id);assert.equal(state.permission_overrides.ascla_access,false);
 assert.equal(state.access_enabled,false);await admin.locator('[data-admin-user="'+member.id+'"] .request-status').getByText('Acceso restringido por permisos',{exact:true}).waitFor();
 form=await edit();await form.locator('[name="permission_ascla_access"]').selectOption('');await save(form);assert.equal((await api(member.page,'bootstrap')).status,200);
 pass('La administración puede restringir y restaurar el acceso sin perder la cuenta editable');
 form=await edit();await form.locator('[name="assigned_roles"][value="ascla_moderator"]').check();assert.equal(await form.locator('[name="board_reference"]').getAttribute('required'),'');assert.equal(await form.locator('[name="board_confirmed"]').getAttribute('required'),'');
 const pending=await ok(admin,'admin/users/'+member.id);const payload=Object.fromEntries(['email','role','first_name','last_name','position','company','phone','phone_visibility','membership_status','membership_until'].map(k=>[k,pending[k]]));
 assert.equal((await api(admin,'admin/users/'+member.id,{...payload,assigned_roles:['ascla_member','ascla_moderator']})).status,403);
 pass('Elegir varios roles exige autorización en la interfaz y la API rechaza una solicitud sin ella');
 await form.locator('[name="board_reference"]').fill('Acta de Junta 2026-'+suffix);await form.locator('[name="board_date"]').fill(new Date().toISOString().slice(0,10));await form.locator('[name="board_confirmed"]').check();await save(form);
 state=await ok(admin,'admin/users/'+member.id);assert.deepEqual(state.assigned_roles,['ascla_member','ascla_moderator']);assert.ok(state.role_approval.reference.endsWith(suffix));assert.ok(state.role_approval.recorded_by);assert.equal((await api(member.page,'admin')).status,200);
 const audit=(await ok(admin,'admin')).audit.find(row=>row.action==='board_role_authorization'&&Number(row.object_id)===member.id);assert.ok(audit);assert.deepEqual(JSON.parse(audit.detail).role_approval.after,state.role_approval);
 pass('La autorización registra acta, fecha, actor y roles; su alcance y auditoría habilitan moderación');
 form=await edit();await form.locator('[name="assigned_roles"][value="ascla_executive"]').check();assert.equal(await form.locator('[name="board_reference"]').getAttribute('required'),'');await form.locator('[name="assigned_roles"][value="ascla_executive"]').uncheck();
 await form.locator('[name="permission_ascla_moderate"]').selectOption('deny');await save(form);assert.equal((await api(member.page,'admin/comments/1',{decision:'approve',reason:'No autorizado'})).status,403);
 pass('Una aprobación no permite ampliar roles sin registrar otra; los permisos individuales limitan roles aprobados');
 form=await edit();await form.locator('[name="assigned_roles"][value="ascla_moderator"]').uncheck();await form.locator('[name="permission_ascla_moderate"]').selectOption('');await save(form);state=await ok(admin,'admin/users/'+member.id);assert.deepEqual(state.assigned_roles,['ascla_member']);assert.equal((await api(member.page,'admin')).status,403);
 pass('Reducir a un rol elimina las capacidades adicionales y conserva la evidencia de la Junta');
 await admin.locator('[data-action="board-role-approval"][data-id="'+member.id+'"]').click();const board=admin.locator('[data-form="board-approval"]');await board.waitFor();
 await board.locator('[name="roles"][value="administrator"]').check();await board.locator('[name="roles"][value="ascla_moderator"]').check();await board.locator('[name="board_reference"]').fill('Acta técnica '+suffix);await board.locator('[name="board_date"]').fill(new Date().toISOString().slice(0,10));await board.locator('[name="board_confirmed"]').check();
 const registered=admin.waitForResponse(r=>r.url().endsWith('/admin/users/'+member.id+'/role-approval')&&r.request().method()==='POST');await board.locator('button:not([type])').click();assert.equal((await registered).status(),200);await board.waitFor({state:'detached'});
 state=await ok(admin,'admin/users/'+member.id);assert.deepEqual(state.assigned_roles,['ascla_member']);assert.deepEqual(state.role_approval.roles,['administrator','ascla_moderator']);
 pass('Registro separado para decisiones sobre los cuatro roles: conserva la cuenta y no asigna privilegios');
 form=await edit();await admin.screenshot({path:root+'/test-results/governance-desktop.png'});await admin.setViewportSize({width:390,height:844});await form.locator('[name="assigned_roles"]').first().scrollIntoViewIfNeeded();await admin.screenshot({path:root+'/test-results/governance-mobile.png'});
 const widths=await admin.evaluate(()=>({width:innerWidth,document:document.documentElement.scrollWidth,overflow:[...document.querySelectorAll('#ascla-root *')].filter(el=>{const b=el.getBoundingClientRect();return !el.closest(".admin-tabs")&&b.width>0&&b.left>=0&&b.right>innerWidth+1;}).slice(0,18).map(el=>({tag:el.tagName,classes:el.className,width:Math.round(el.getBoundingClientRect().width),right:Math.round(el.getBoundingClientRect().right)}))}));if(widths.document>widths.width+1)console.log(JSON.stringify(widths));assert.equal(widths.document<=widths.width+1,true);
 pass('El formulario administrativo conserva roles/permisos legibles sin desbordamiento móvil');
 await admin.setViewportSize({width:1440,height:1000});
 const settingsRoute='**/ascla/v1/settings';
 await admin.route(settingsRoute,async route=>{
  if(route.request().method()!=='GET')return route.continue();
  const response=await route.fetch(),body=await response.json();body.external_secrets_only=true;
  for(const source of Object.values(body.secret_sources||{})){source.managed_externally=true;source.source='environment';}
  await route.fulfill({response,json:body});
 });
 await admin.goto(base+'/administracion/?section=configuracion');const settingsForm=admin.locator('[data-form="settings"]');await settingsForm.waitFor();
 await settingsForm.locator('[name="ai_provider"]').selectOption('openai');
 assert.equal(await settingsForm.locator('[name="openai_key"]').isDisabled(),true);assert.equal(await settingsForm.locator('[name="clear_openai_key"]').isDisabled(),true);assert.equal(await settingsForm.locator('[name="openai_key"]').inputValue(),'');
 await settingsForm.locator('[name="ai_provider"]').selectOption('gemini');await settingsForm.locator('[name="ai_provider"]').selectOption('openai');assert.equal(await settingsForm.locator('[name="openai_key"]').isDisabled(),true);
 assert.ok(await settingsForm.getByText('Esta credencial se administra en el servidor. ASCLA_OPENAI_KEY',{exact:true}).isVisible());
 pass('Interfaz con metadatos externos: clave vacía e inmutable al alternar proveedores, sin mostrar su valor');
 await admin.unroute(settingsRoute);
 assert.deepEqual(errors,[]);pass('Sin errores JavaScript');
}catch(e){errors.push(e.message);console.error(e.stack);process.exitCode=1;}finally{
 if(admin){for(const id of posts)await api(admin,'items/'+id,null,'DELETE').catch(()=>{});if(member)await api(admin,'native:users/'+member.id+'?force=true&reassign=1',null,'DELETE').catch(()=>{});}
 fs.mkdirSync(root+'/test-results',{recursive:true});fs.writeFileSync(root+'/test-results/governance107.json',JSON.stringify({checks,errors,ok:!errors.length},null,2));if(browser)await browser.close();
}})();
