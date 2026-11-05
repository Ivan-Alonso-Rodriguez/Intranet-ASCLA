/* Acceptance of configurable completion and publication date ranges in a disposable site. */
const {chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),crypto=require('node:crypto');
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Disposable environment required');
const root=path.resolve(__dirname,'..'),base=process.env.ASCLA_SITE_URL||'http://ascla_audit-wordpress';
const env=Object.fromEntries(fs.readFileSync(process.env.ASCLA_ENV_FILE||root+'/.env.audit','utf8').split(/\r?\n/).filter(v=>v.includes('=')).map(v=>[v.slice(0,v.indexOf('=')),v.slice(v.indexOf('=')+1)]));
const checks=[],errors=[],posts=[];let browser,admin,member,settings;
async function api(page,route,body,method){return page.evaluate(async({route,body,method})=>{const url=route.startsWith('native:')?ASCLA.api.replace(/ascla\/v1\/?$/,'wp/v2/')+route.slice(7):ASCLA.api+route;const r=await fetch(url,{method:method||(body?'POST':'GET'),headers:{'X-WP-Nonce':ASCLA.nonce,'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})});return{status:r.status,body:await r.json()};},{route,body,method});}
async function ok(page,route,body,method){const r=await api(page,route,body,method);assert.ok(r.status>=200&&r.status<300,route+': '+r.status);return r.body;}
async function login(name,password){const context=await browser.newContext({viewport:{width:1440,height:1000}}),p=await context.newPage();p.on('pageerror',e=>errors.push(e.message));await p.goto(base+'/wp-login.php');await p.locator('#user_login').fill(name);await p.locator('#user_pass').fill(password);await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}),p.locator('#wp-submit').click()]);await goto(p,'intranet/');return p;}
async function goto(p,route,dismiss=true){await p.goto(base+'/'+route);await p.locator('#page-content h1').waitFor();if(dismiss)await p.keyboard.press('Escape');}
function pass(name){checks.push(name);console.log('PASS '+name);}
(async()=>{try{
 browser=await chromium.launch({headless:true});admin=await login('ascla.admin',env.ASCLA_ADMIN_PASSWORD);settings=await ok(admin,'settings');
 const zone=(await ok(admin,'native:settings')).timezone || 'UTC';
 const suffix=crypto.randomBytes(4).toString('hex'),password='J9!sK4#'+crypto.randomBytes(18).toString('base64url');
 const user=await ok(admin,'native:users',{username:'range_'+suffix,password,email:'range_'+suffix+'@example.invalid',first_name:'Fechas',last_name:suffix,roles:['ascla_member']});member={...user,page:await login('range_'+suffix,password)};
 const boot=await ok(member.page,'bootstrap');
 await ok(member.page,'profiles/me',{first_name:'Fechas',last_name:suffix,position:'Analista',company:'Organización',city:'Lima',bio:'Descripción profesional',industries:[Number(boot.catalogs.industry[0].id)],interests:[Number(boot.catalogs.interest[0].id)],goals:[Number(boot.catalogs.goal[0].id)]});
 for(const [type,route] of [['hub','hub/'],['topic','foros/'],['gallery','galeria/']]){
  const post=await ok(admin,'content/'+type,{title:'Rango '+suffix+' '+type,body:'Publicación para comprobar rangos',status:'publish',meta:{chatham:false}});posts.push(post.id);
  const day=new Intl.DateTimeFormat('en-CA',{timeZone:zone,year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date(post.date.replace(' ','T')+'Z'));await goto(member.page,route);const form=member.page.locator('.content-list-filters');
  await form.locator('[name="q"]').fill('Rango '+suffix+' '+type);await form.locator('[name="after"]').fill(day);await form.locator('[name="before"]').fill(day);
  const response=member.page.waitForResponse(r=>r.url().includes('/content/'+type+'?')&&r.url().includes('before='+day));
  await form.locator('button:not([type])').first().click();const data=await (await response).json();assert.equal(data.total,1,JSON.stringify(data));assert.equal(data.items[0].id,post.id);
  await member.page.locator('[data-action="item"][data-id="'+post.id+'"]').first().waitFor();
  assert.equal(await form.locator('[name="after"]').inputValue(),day);assert.equal(await form.locator('[name="before"]').inputValue(),day);
  await form.locator('[name="after"]').fill('2099-12-31');await form.locator('button:not([type])').first().click();
  await member.page.getByText('La fecha inicial no puede ser posterior a la fecha final.',{exact:true}).waitFor();
  await member.page.locator('[data-action="filter-reset"]').click();await member.page.waitForFunction(()=>document.querySelector('.content-list-filters [name="after"]')?.value==='');
  assert.equal(await form.locator('[name="before"]').inputValue(),'');
  await member.page.setViewportSize({width:390,height:844});
  assert.equal(await member.page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),true);await member.page.setViewportSize({width:1440,height:1000});
  pass(type+': rango inclusivo, controles persistentes, aviso de rango inválido, limpieza y ancho móvil');
 }
 await goto(admin,'administracion/?section=configuracion');const form=admin.locator('[data-form="settings"]');await form.waitFor();
 for(const choice of await form.locator('[name="profile_completion_extra"]').all())await choice.uncheck();
 await form.locator('[name="profile_completion_extra"][value="experience"]').check();
 let response=admin.waitForResponse(r=>r.url().endsWith('/settings')&&r.request().method()==='POST');await form.locator('.settings-savebar button').click();assert.equal((await response).status(),200);
 assert.deepEqual((await ok(admin,'settings')).profile_completion_extra,['experience']);
 const progress=(await ok(member.page,'bootstrap')).profile_completion;assert.equal(progress.total,9);assert.equal(progress.percent,89);assert.deepEqual(progress.missing,['Experiencia profesional']);
 pass('Administración: criterios guardados desde casillas y reflejados en el cálculo tras autenticación');
 await goto(member.page,'intranet/',false);await member.page.getByText('Completa tu perfil',{exact:true}).waitFor();await member.page.getByText('Ahora no',{exact:true}).click();
 await goto(member.page,'hub/');assert.equal((await ok(member.page,'bootstrap')).profile_review.due,false);
 pass('Aviso de completitud adicional: no bloquea la navegación y conserva la posposición');
 await goto(admin,'administracion/?section=configuracion');const reset=admin.locator('[data-form="settings"]');await reset.waitFor();await reset.locator('[name="profile_completion_extra"][value="experience"]').uncheck();
 response=admin.waitForResponse(r=>r.url().endsWith('/settings')&&r.request().method()==='POST');await reset.locator('.settings-savebar button').click();assert.equal((await response).status(),200);
 assert.deepEqual((await ok(admin,'settings')).profile_completion_extra,[]);assert.equal((await ok(member.page,'bootstrap')).profile_completion.percent,100);
 pass('Desmarcar todos los criterios adicionales restaura los ocho mínimos y el 100 %');
 assert.deepEqual(errors,[]);pass('Sin errores JavaScript');
}catch(e){errors.push(e.message);console.error(e.stack);process.exitCode=1;}finally{
 if(admin){if(settings)await api(admin,'settings',{profile_completion_extra:settings.profile_completion_extra||[]}).catch(()=>{});for(const id of posts)await api(admin,'items/'+id,null,'DELETE').catch(()=>{});if(member)await api(admin,'native:users/'+member.id+'?force=true&reassign=1',null,'DELETE').catch(()=>{});}
 fs.mkdirSync(root+'/test-results',{recursive:true});fs.writeFileSync(root+'/test-results/analysis-filters107.json',JSON.stringify({checks,errors,ok:!errors.length},null,2));if(browser)await browser.close();
}})();
