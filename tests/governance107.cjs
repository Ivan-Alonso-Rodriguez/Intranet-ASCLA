/* Role authorization and individual permissions against disposable WordPress. */
const {chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),crypto=require('node:crypto');
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Disposable environment required');
const root=path.resolve(__dirname,'..'),base=process.env.ASCLA_SITE_URL||'http://ascla_audit-wordpress';
const env=Object.fromEntries(fs.readFileSync(process.env.ASCLA_ENV_FILE||root+'/.env.audit','utf8').split(/\r?\n/).filter(v=>v.includes('=')).map(v=>[v.slice(0,v.indexOf('=')),v.slice(v.indexOf('=')+1)]));
const checks=[],errors=[],posts=[];let browser,admin,member;
async function api(page,route,body,method){return page.evaluate(async({route,body,method})=>{const url=route.startsWith('native:')?ASCLA.api.replace(/ascla\/v1\/?$/,'wp/v2/')+route.slice(7):ASCLA.api+route;const r=await fetch(url,{method:method||(body?'POST':'GET'),headers:{'X-WP-Nonce':ASCLA.nonce,'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})});return{status:r.status,body:await r.json()};},{route,body,method});}
async function ok(page,route,body,method){const r=await api(page,route,body,method);assert.ok(r.status>=200&&r.status<300,route+': '+r.status);return r.body;}
async function login(name,password){const context=await browser.newContext({viewport:{width:1440,height:1000}}),p=await context.newPage();p.on('pageerror',e=>errors.push(e.message));await p.goto(base+'/wp-login.php');await p.locator('#user_login').fill(name);await p.locator('#user_pass').fill(password);await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}),p.locator('#wp-submit').click()]);await p.goto(base+'/intranet/');await require('./helpers/browser.cjs').waitForHome(p);await p.keyboard.press('Escape');return p;}
async function edit(){
 await admin.goto(base+'/administracion/?section=usuarios');const search=admin.locator('[data-form="admin-filter"][data-area="users"]');await search.waitFor();await search.locator('[name="q"]').fill(member.username);const previous=await search.elementHandle();
 await Promise.all([admin.waitForResponse(r=>r.request().method()==='GET'&&new URL(r.url()).pathname.endsWith('/admin/users')&&new URL(r.url()).searchParams.get('q')===member.username),search.locator('button:not([type])').first().click()]);await previous.waitForElementState('hidden');
 await admin.locator('[data-action="user-edit"][data-id="'+member.id+'"]').click();const form=admin.locator('[data-form="admin-user-edit"]');await form.waitFor();if(!await form.locator('[name="member_type"]').inputValue())await form.locator('[name="member_type"]').selectOption('independent');await form.locator('.admin-edit-section > summary').filter({hasText:'Acceso y membresía'}).click();return form;
}
async function save(form){const response=admin.waitForResponse(r=>r.url().endsWith('/admin/users/'+member.id)&&r.request().method()==='POST');await form.locator('button:not([type])').last().click();const r=await response;assert.equal(r.status(),200);await form.waitFor({state:'detached'});return r.json();}
function pass(name){checks.push(name);console.log('PASS '+name);}
(async()=>{try{
 browser=await chromium.launch({headless:true,...(process.env.ASCLA_BROWSER_CHANNEL?{channel:process.env.ASCLA_BROWSER_CHANNEL}:{})});admin=await login('ascla.admin',env.ASCLA_ADMIN_PASSWORD);
 const zone=(await ok(admin,'native:settings')).timezone || 'UTC';const today=()=>new Intl.DateTimeFormat('en-CA',{timeZone:zone,year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
 const suffix=crypto.randomBytes(4).toString('hex'),password='J9!sK4#'+crypto.randomBytes(18).toString('base64url'),username='governance_'+suffix;
 member=await ok(admin,'native:users',{username,password,email:username+'@example.invalid',first_name:'Gestión',last_name:suffix,roles:['ascla_member']});member.username=username;member.page=await login(username,password);
 const boot=await ok(member.page,'bootstrap');await ok(member.page,'profiles/me',{first_name:'Gestión',last_name:suffix,position:'Analista',company:'Organización',city:'Lima',bio:'Descripción profesional',industries:[Number(boot.catalogs.industry[0].id)],interests:[Number(boot.catalogs.interest[0].id)],goals:[Number(boot.catalogs.goal[0].id)]});
 let form=await edit();
 assert.equal(await form.locator('[name="assigned_roles"],[name^="permission_"]').count(),0);
 assert.equal(await form.getByText('Permisos avanzados',{exact:true}).count(),0);
 assert.equal(await form.locator('[name="login"]').isEditable(),true);
 await save(form);
 pass('El editor conserva rol y membresía, permite editar el usuario y retira los permisos avanzados');
 await ok(admin,'admin/users/'+member.id,{email:member.email,first_name:'Gestión',last_name:suffix,role:'ascla_member',permission_overrides:{ascla_write:false}});
 form=await edit();await save(form);
 let state=await ok(admin,'admin/users/'+member.id);assert.equal(state.permission_overrides.ascla_write,false);
 assert.equal((await api(member.page,'content/hub',{title:'No permitido',body:'Prueba',status:'publish'})).status,403);
 pass('Editar el perfil conserva las restricciones existentes y el servidor sigue rechazando escrituras no autorizadas');
 await ok(admin,'admin/users/'+member.id,{email:member.email,first_name:'Gestión',last_name:suffix,role:'ascla_member',permission_overrides:{}});
 assert.equal((await api(admin,'admin/users/'+member.id,{email:member.email,first_name:'Gestión',last_name:suffix,role:'ascla_member',assigned_roles:['ascla_member','ascla_moderator']})).status,403);
 pass('La retirada del menú no permite asignar roles adicionales sin autorización');
 await admin.setViewportSize({width:390,height:844});form=await edit();
 assert.equal(await admin.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true);
 await admin.screenshot({path:root+'/test-results/governance-mobile.png'});await admin.setViewportSize({width:1440,height:1000});
 await save(form);pass('Editor administrativo sin desbordamiento móvil');
 const settingsRoute='**/ascla/v1/settings';
 await admin.route(settingsRoute,async route=>{
  if(route.request().method()!=='GET')return route.continue();
  const response=await route.fetch(),body=await response.json();body.external_secrets_only=true;
  for(const source of Object.values(body.secret_sources||{})){source.managed_externally=true;source.source='environment';}
  await route.fulfill({response,json:body});
 });
 await admin.goto(base+'/administracion/?section=configuracion');const settingsForm=admin.locator('[data-form="settings"]');await settingsForm.waitFor();await settingsForm.locator('[data-settings-group=servicios]').click();
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
