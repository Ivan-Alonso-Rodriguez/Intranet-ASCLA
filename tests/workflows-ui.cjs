/* Local acceptance for Contact authorization, native account policies and microevents. */
const {chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..'),base=process.env.ASCLA_SITE_URL||'http://ascla_audit-wordpress';
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Disposable environment required');
const checks=[],errors=[];let browser,admin;
async function api(page,route,body,method){return page.evaluate(async({route,body,method})=>{const url=route.startsWith('native:')?ASCLA.api.replace(/ascla\/v1\/?$/,'wp/v2/')+route.slice(7):ASCLA.api+route;const r=await fetch(url,{method:method||(body?'POST':'GET'),headers:{'X-WP-Nonce':ASCLA.nonce,'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})});return{status:r.status,body:await r.json()};},{route,body,method});}
async function ok(page,route,body,method){const r=await api(page,route,body,method);assert.ok(r.status>=200&&r.status<300,route+': '+r.status+' '+r.body.message);return r.body;}
async function login(name,password){const context=await browser.newContext({viewport:{width:1440,height:1000}}),p=await context.newPage();p.on('pageerror',e=>errors.push(e.message));await p.goto(base+'/wp-login.php');await p.locator('#user_login').fill(name);await p.locator('#user_pass').fill(password);await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}),p.locator('#wp-submit').click()]);await p.goto(base+'/intranet/');await p.locator('.hero').waitFor();await p.keyboard.press('Escape');return p;}
async function goto(p,route){await p.bringToFront();await p.goto(base+'/'+route);await p.locator('#page-content h1').waitFor();await p.keyboard.press('Escape');}
function pass(name){checks.push(name);console.log('PASS '+name);}
async function until(check, timeout=45000){const end=Date.now()+timeout;while(Date.now()<end){if(await check())return;await new Promise(resolve=>setTimeout(resolve,1000));}throw Error('Timed out waiting for lifecycle transition');}
(async()=>{try{
 const fixture=JSON.parse(fs.readFileSync(root+'/test-results/workflow-fixture.json','utf8'));
 browser=await chromium.launch({headless:true});
 const pages=[];for(const user of fixture.users)pages.push(await login(user.login,user.password));
 [admin]=pages;const executive=pages[1],member=pages[2],other=pages[3],account=fixture.users[2],second=fixture.users[3];
 const categories=['Asistencia de perfil QA','Consulta institucional QA'];
 await ok(admin,'settings',{support_categories:categories,micro_interval_days:14});
 assert.deepEqual((await ok(member,'bootstrap')).support_categories,categories);
 assert.equal((await ok(admin,'settings')).micro_interval_days,14);
 assert.equal((await api(admin,'settings',{micro_interval_days:1})).status,400);
 assert.equal((await api(member,'content/contact',{title:'Soporte QA',body:'Necesito ayuda.',meta:{description:'Categoría inexistente'}})).status,400);
 const request=await ok(member,'content/contact',{title:'Ayuda con mi perfil',body:'Solicito corregir mis nombres y teléfono.',meta:{description:categories[0]}});
 await ok(admin,'admin/contact/'+request.id+'/assign',{assignee:fixture.users[0].id});
 await goto(member,'contacto/');assert.deepEqual(await member.locator('[data-form="contact"] [name="category"] option').allTextContents(),categories);
 pass('Contacto usa categorías vigentes, configurables y validadas por el servidor');
 let u=await ok(admin,'admin/users/'+account.id);
 assert.equal((await api(admin,'admin/users/'+account.id,{...u,first_name:'Nombre asistido'})).status,403);
 assert.equal((await api(admin,'native:users/'+account.id,{first_name:'Edición externa'})).status,403);
 assert.equal((await api(admin,'native:users/'+account.id,{password:'weak'})).status,400);
 await goto(admin,'administracion/?section=usuarios');await admin.locator('[data-form="admin-filter"][data-area="users"] [name="q"]').fill(account.login);await admin.locator('[data-form="admin-filter"][data-area="users"] button').click();
 await admin.locator('[data-action="user-edit"][data-id="'+account.id+'"]').click();
 const form=admin.locator('[data-form="admin-user-edit"]');assert.equal(await form.locator('[name="first_name"]').getAttribute('readonly'),'');
 await form.locator('[name="professional_request_id"]').selectOption(String(request.id));
 await form.locator('[name="first_name"]').fill('Nombre asistido');await form.locator('[name="phone"]').fill('+51987654321');
 await form.getByRole('button',{name:'Guardar cambios',exact:true}).click();await admin.locator('.modal-backdrop').waitFor({state:'detached'});
 u=await ok(admin,'admin/users/'+account.id);assert.equal(u.first_name,'Nombre asistido');assert.ok(u.phone.includes('987654321'));
 assert.equal((await api(admin,'admin/users/'+account.id,{...u,first_name:'Segundo cambio',professional_request_id:request.id})).status,409);
 pass('Datos personales requieren solicitud asignada de uso único; formularios y REST nativo protegidos');
 const prior=(await ok(admin,'admin')).audit.reduce((max,row)=>Math.max(max,Number(row.id)),0);
 await ok(admin,'native:users/'+second.id,{roles:['ascla_moderator']});
 const audit=(await ok(admin,'admin')).audit.filter(row=>Number(row.id)>prior&&Number(row.object_id)===second.id&&row.action==='account_permissions_changed').map(row=>JSON.parse(row.detail));
 assert.ok(audit.some(diff=>diff.capabilities.before.ascla_member===true),'Previous native role missing');assert.ok(audit.some(diff=>diff.capabilities.after.ascla_moderator===true),'New native role missing');
 pass('Cambios de permisos desde WordPress registran diferencias antes/después');
 const eventId=fixture.events[0],event=await ok(executive,'items/'+eventId),at=new Date(Date.now()+28000).toISOString();
 let scheduled=await ok(executive,'content/event/'+eventId,{title:event.title,body:event.body,status:'scheduled',publish_at:at,meta:event.meta});
 assert.equal(scheduled.status,'pending');assert.equal((await api(member,'items/'+eventId)).status,404);
 scheduled=await ok(admin,'items/'+eventId+'/moderate',{decision:'approve',reason:'Revisión humana de prueba',reviewed:true});
 assert.equal(scheduled.status,'ascla_hidden');assert.equal(scheduled.meta.micro_approved,true);assert.equal(scheduled.meta.invited,false);assert.equal(scheduled.meta.public_at,undefined);
 await until(async()=>{const response=await api(member,'items/'+eventId);return response.status===200&&response.body.status==='publish';});
 const published=await ok(admin,'items/'+eventId);assert.equal(published.meta.invited,true);assert.ok(published.meta.public_at>Date.now()/1000+3600);
 pass('Ejecutivo programa; administración aprueba; cron publica e inicia la prioridad al divulgar');
 const own=(await ok(member,'bootstrap')).me;await ok(member,'profiles/me',{...own,microevents:false});
 await until(async()=>Boolean((await ok(admin,'items/'+fixture.events[1])).meta.cancelled));
 const cancelled=await ok(admin,'items/'+fixture.events[1]);assert.equal(cancelled.status,'ascla_hidden');assert.equal(cancelled.meta.micro_approved,false);assert.ok(cancelled.meta.micro_history.some(row=>row.state==='cancelled'&&row.reason));
 assert.equal((await api(admin,'items/'+fixture.events[1]+'/moderate',{decision:'approve',reason:'No debe reabrirse'})).status,409);
 pass('Pérdida de candidatos cancela la propuesta y conserva motivo e historial');
 assert.deepEqual(errors,[]);fs.writeFileSync(root+'/test-results/workflows-ui.json',JSON.stringify({checks,errors},null,2));
}catch(error){console.error(error.stack);fs.writeFileSync(root+'/test-results/workflows-ui.json',JSON.stringify({checks,errors,failure:error.message},null,2));process.exitCode=1;}finally{if(browser)await browser.close();}})();
