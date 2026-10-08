/* Local acceptance: isolated database faults must roll back every affected state. */
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
async function fault(action,user=0,mode='audit',email='',post=0){
 const token=crypto.randomBytes(8).toString('hex'),file=root+'/test-results/atomic-fault.json';
 fs.writeFileSync(file,JSON.stringify({token,action,user,mode,post,...(email?{email}:{})}));const deadline=Date.now()+20000;
 while(Date.now()<deadline){await new Promise(resolve=>setTimeout(resolve,200));const reply=JSON.parse(fs.readFileSync(file,'utf8'));if(reply.token===token&&reply.done){if(reply.error)throw Error(reply.error);return reply.result;}}
 throw Error('Local fault runner did not respond');
}
(async()=>{try{
 browser=await chromium.launch({headless:true});admin=await login('ascla.admin',env.ASCLA_ADMIN_PASSWORD);
 const cat=(await ok(admin,'bootstrap')).catalogs,suffix=crypto.randomBytes(4).toString('hex');
 const complete={first_name:'Atomicidad',last_name:suffix,position:'Analista',company:'QA local',country:'Perú',city:'Lima',bio:'Perfil de pruebas locales',industries:[cat.industry[0].id],interests:[cat.interest[0].id],goals:[cat.goal[0].id],areas:[cat.area[0].id]};
 for(let i=0;i<2;i++){
  const username='atomic_'+suffix+'_'+i,password='V9!zF3#'+crypto.randomBytes(18).toString('base64url');
  const user=await ok(admin,'native:users',{username,password,email:username+'@example.invalid',first_name:'Atomicidad',last_name:suffix+i,roles:['ascla_member']});
  users.push({...user,page:await login(username,password)});await ok(users.at(-1).page,'profiles/me',complete);
 }
 const [a,b]=users;await ok(a.page,'relations',{target:b.id,kind:'connect',active:true,message:'Prueba transaccional'});const request=(await ok(b.page,'connections')).incoming.find(row=>row.id===a.id).connection.request_id;await ok(b.page,'connections/'+request+'/respond',{decision:'accept'});
 const event=await ok(admin,'content/event',{title:'Aforo transaccional '+suffix,body:'Evento para pruebas de reversión',status:'publish',meta:{start:new Date(Date.now()+86400000).toISOString(),end:new Date(Date.now()+90000000).toISOString(),capacity:1}});posts.push(event.id);
 await ok(a.page,'events/'+event.id+'/register',{status:'accepted'});await ok(b.page,'events/'+event.id+'/register',{status:'waitlisted'});
 await fault('enable',a.id);
 assert.equal((await api(a.page,'relations',{target:b.id,kind:'connect',active:false})).status,500);
 assert.ok((await ok(a.page,'connections')).connected.some(row=>row.id===b.id));
 assert.equal((await api(a.page,'relations',{target:b.id,kind:'block',active:true})).status,500);
 assert.ok((await ok(a.page,'connections')).connected.some(row=>row.id===b.id));assert.equal((await ok(a.page,'connections')).blocked.length,0);
 pass('Rollback conserva conexión y permisos si falla la auditoría al disolver o bloquear');
 assert.equal((await api(a.page,'events/'+event.id+'/register',{status:'cancelled'})).status,500);
 assert.equal((await ok(a.page,'events/'+event.id)).registered,'accepted');const waiting=await ok(b.page,'events/'+event.id);assert.equal(waiting.registered,'waitlisted');assert.equal(waiting.reserved,1);
 pass('Rollback restaura el cupo confirmado y la lista de espera tras un fallo intermedio');
 await fault('disable');await ok(a.page,'events/'+event.id+'/register',{status:'cancelled'});assert.equal((await ok(b.page,'events/'+event.id)).registered,'offered');
 await ok(a.page,'relations',{target:b.id,kind:'connect',active:false});assert.ok(!(await ok(a.page,'connections')).connected.some(row=>row.id===b.id));
 pass('La misma operación confirma cambios y ofrece el cupo cuando desaparece el fallo');
 await ok(admin,'native:users/'+a.id,{roles:['ascla_moderator']});
 const topic=await ok(b.page,'content/topic',{title:'Moderación transaccional '+suffix,body:'Contenido para probar rollback de moderación',status:'publish'});posts.push(topic.id);
 const comment=await ok(b.page,'items/'+topic.id+'/comments',{body:'Comentario para revisión'});
 await ok(admin,'admin/comments/'+comment.id,{decision:'reject',reason:'Revisión pendiente'});
 await ok(a.page,'items/'+topic.id+'/report',{reason:'spam'});
 const report=(await ok(a.page,'admin')).reports.find(r=>r.report_type==='content'&&Number(r.target_id)===topic.id);
 await fault('enable',a.id);
 assert.equal((await api(a.page,'admin/comments/'+comment.id,{decision:'approve',reason:'Decisión con fallo de auditoría'})).status,500);
 assert.ok((await ok(a.page,'admin')).comments.some(c=>Number(c.id)===comment.id));
 assert.ok(!(await ok(a.page,'items/'+topic.id+'/comments')).some(c=>c.id===comment.id));
 assert.equal((await api(a.page,'admin/reports/'+report.id+'/review',{reason:'Revisión con fallo de auditoría'})).status,500);
 assert.equal((await ok(a.page,'admin')).reports.find(r=>Number(r.id)===Number(report.id)).reviewed,false);
 assert.equal((await api(a.page,'items/'+topic.id+'/moderate',{decision:'hide',reason:'Retiro con fallo de auditoría'})).status,500);
 assert.equal((await ok(b.page,'items/'+topic.id)).status,'publish');
 pass('Moderación atómica: un fallo de auditoría revierte comentario, reporte y publicación');
 await fault('disable');
 await ok(a.page,'admin/comments/'+comment.id,{decision:'approve',reason:'Revisión completada'});
 await ok(a.page,'admin/reports/'+report.id+'/review',{reason:'Reporte evaluado'});
 await fault('enable',a.id);
 assert.equal((await api(a.page,'admin/reports/'+report.id,null,'DELETE')).status,500);
 assert.equal((await ok(a.page,'admin')).reports.find(r=>Number(r.id)===Number(report.id)).reviewed,true);
 await fault('disable');await ok(a.page,'admin/reports/'+report.id,null,'DELETE');
 assert.ok(!(await ok(a.page,'admin')).reports.some(r=>Number(r.id)===Number(report.id)));
 pass('Un reporte revisado solo se elimina si su auditoría queda confirmada');
 await ok(admin,'native:users/'+a.id,{roles:['administrator']});
 const beforeTopic=await ok(a.page,'items/'+topic.id),changedTopic={title:'Edición revertida '+suffix,body:'Texto que debe revertirse',status:'publish',interest:[cat.interest[0].id],tag_names:['atomic_tag_'+suffix]};
 await fault('enable',a.id);
 assert.equal((await api(a.page,'content/topic/'+topic.id,changedTopic)).status,500);
 const preserved=await ok(b.page,'items/'+topic.id);assert.equal(preserved.title,beforeTopic.title);assert.equal(preserved.body,beforeTopic.body);assert.deepEqual(preserved.tags,beforeTopic.tags);
 const orphanTitle='Alta revertida '+suffix;assert.equal((await api(a.page,'content/topic',{title:orphanTitle,body:'No debe quedar ningún borrador',status:'publish'})).status,500);
 assert.equal((await ok(a.page,'content/topic?mine=1&q='+encodeURIComponent(orphanTitle))).total,0);
 assert.equal((await api(a.page,'items/'+topic.id,null,'DELETE')).status,500);assert.equal((await ok(b.page,'items/'+topic.id)).status,'publish');
 await fault('disable');await ok(a.page,'content/topic/'+topic.id,changedTopic);
 pass('Publicaciones: alta, edición, taxonomías y papelera revierten juntas cuando falla la auditoría');
 const eventInput={title:'Evento completo '+suffix,body:'Evento de aceptación de atomicidad',status:'publish',meta:{start:new Date(Date.now()+86400000*3).toISOString(),end:new Date(Date.now()+86400000*3+3600000).toISOString(),capacity:3}};
 const localEvent=await ok(a.page,'content/event',eventInput);posts.push(localEvent.id);
 await ok(b.page,'events/'+localEvent.id+'/register',{status:'accepted'});
 await fault('enable',a.id,'metadata');
 assert.equal((await api(a.page,'content/event/'+localEvent.id,{...eventInput,title:'Título que debe revertirse',meta:{...eventInput.meta,capacity:9}})).status,500);
 const metadataPreserved=await ok(a.page,'items/'+localEvent.id);assert.equal(metadataPreserved.title,localEvent.title);assert.equal(metadataPreserved.meta.capacity,3);
 await fault('disable');pass('Los fallos silenciosos de metadatos de WordPress revierten el título y el resto del evento');
 const rescheduled={...eventInput,meta:{...eventInput.meta,start:new Date(Date.now()+86400000*4).toISOString(),end:new Date(Date.now()+86400000*4+3600000).toISOString()}};
 await fault('enable',a.id);
 assert.equal((await api(a.page,'content/event/'+localEvent.id,rescheduled)).status,500);
 assert.equal((await ok(b.page,'events/'+localEvent.id)).registered,'accepted');assert.equal((await ok(a.page,'items/'+localEvent.id)).meta.start,localEvent.meta.start);
 assert.equal((await api(a.page,'events/'+localEvent.id+'/cancel',{reason:'Cancelación que debe revertirse'})).status,500);assert.equal((await ok(b.page,'events/'+localEvent.id)).cancelled,false);
 const adminId=Number((await ok(admin,'bootstrap')).me.id);
 assert.equal((await api(a.page,'events/'+localEvent.id+'/invite',{users:[adminId]})).status,500);
 await fault('disable');assert.equal((await ok(a.page,'events/'+localEvent.id+'/invite',{users:[adminId]})).sent,1);
 await ok(a.page,'content/event/'+localEvent.id,rescheduled);assert.equal((await ok(b.page,'events/'+localEvent.id)).registered,'reconfirm');
 await ok(a.page,'events/'+localEvent.id+'/cancel',{reason:'Cancelación confirmada'});assert.equal((await ok(b.page,'events/'+localEvent.id)).cancelled,true);
 pass('Eventos: reprogramación, reconfirmación, invitaciones y cancelación se confirman o revierten completas');
 const contact=await ok(b.page,'content/contact',{title:'Cambio autorizado '+suffix,body:'Solicito actualizar mi cargo',meta:{description:(await ok(b.page,'bootstrap')).support_categories[0]}});posts.push(contact.id);
 await ok(admin,'admin/contact/'+contact.id+'/assign',{assignee:a.id});
 await fault('enable',a.id);assert.equal((await api(a.page,'admin/contact/'+contact.id,{status:'progress'})).status,500);assert.equal((await ok(b.page,'items/'+contact.id)).meta.request_status,'open');
 await fault('disable');await ok(a.page,'admin/contact/'+contact.id,{status:'progress'});
 const oldUser=await ok(a.page,'admin/users/'+b.id),newEmail='atomic_changed_'+suffix+'@example.invalid';
 const changedUser={...oldUser,email:newEmail,position:'Director QA',role:'ascla_executive',membership_status:'suspended',professional_request_id:contact.id};
 const mailBefore=(await fault('mail-count',b.id)).count;
 await fault('enable',a.id,'professional');assert.equal((await api(a.page,'admin/users/'+b.id,changedUser)).status,500);
 const userPreserved=await ok(a.page,'admin/users/'+b.id);for(const field of ['email','position','role','membership_status'])assert.equal(userPreserved[field],oldUser[field]);assert.ok(userPreserved.professional_requests.some(r=>r.id===contact.id));
 assert.equal((await api(b.page,'bootstrap')).status,200);assert.equal((await fault('mail-count',b.id)).count,mailBefore);
 await fault('disable');await ok(a.page,'admin/users/'+b.id,{...changedUser,membership_status:'active'});
 const changed=await ok(a.page,'admin/users/'+b.id);assert.equal(changed.position,'Director QA');assert.equal(changed.email,newEmail);assert.equal(changed.role,'ascla_executive');assert.ok(!changed.professional_requests.some(r=>r.id===contact.id));assert.ok((await fault('mail-count',b.id,'audit',oldUser.email)).count>mailBefore);
 pass('Cuentas y Contacto: rollback conserva rol, perfil, membresía, sesión y autorización, sin enviar correos');
 const newAccount={login:'atomic_created_'+suffix,email:'atomic_created_'+suffix+'@example.invalid',first_name:'Alta',last_name:'Atómica',role:'ascla_member',send_invite:true};
 await fault('enable',a.id);assert.equal((await api(a.page,'admin/users',newAccount)).status,500);assert.equal((await ok(a.page,'admin/users?q='+newAccount.login)).total,0);
 await fault('disable');const created=await ok(a.page,'admin/users',newAccount);users.push(created);
 await fault('seed-overlap',a.id,'audit','',created.id);
 await fault('enable',a.id);assert.equal((await api(a.page,'admin/users/'+created.id,null,'DELETE')).status,500);assert.equal((await ok(a.page,'admin/users/'+created.id)).id,created.id);
 await fault('disable');await ok(a.page,'admin/users/'+created.id,null,'DELETE');
 assert.equal((await fault('check-overlap',a.id,'audit','',created.id)).preserved,1);
 pass('Alta y eliminación administrativas conservan la cuenta completa ante un fallo intermedio');
 pass('Eliminar una cuenta conserva reacciones de contenido que comparten su identificador numérico');
 await ok(admin,'native:users/'+b.id,{roles:['ascla_member']});
 const scheduleInput={title:'Cron atómico '+suffix,body:'Publicación con auditoría obligatoria',status:'scheduled',publish_at:new Date(Date.now()+12000).toISOString()};
 const scheduled=await ok(a.page,'content/hub',scheduleInput);posts.push(scheduled.id);
 await fault('enable',a.id,'schedule');let until=Date.now()+50000;
 while(Date.now()<until){await new Promise(resolve=>setTimeout(resolve,1200));if((await ok(a.page,'items/'+scheduled.id)).meta.schedule_error)break;}
 const failedSchedule=await ok(a.page,'items/'+scheduled.id);assert.equal(failedSchedule.status,'ascla_hidden');assert.ok(failedSchedule.meta.schedule_error);assert.equal(failedSchedule.meta.published_at,undefined);assert.equal((await api(b.page,'items/'+scheduled.id)).status,404);
 await fault('disable');await ok(a.page,'content/hub/'+scheduled.id,{...scheduleInput,publish_at:new Date(Date.now()+12000).toISOString()});until=Date.now()+50000;
 while(Date.now()<until){await new Promise(resolve=>setTimeout(resolve,1200));if((await api(b.page,'items/'+scheduled.id)).status===200)break;}
 assert.equal((await ok(b.page,'items/'+scheduled.id)).status,'publish');
 pass('WP-Cron revierte una publicación si falla la auditoría y permite reprogramarla después');
 const waitingEvent=await ok(a.page,'content/event',{...eventInput,title:'Caducidad concurrente '+suffix,meta:{...eventInput.meta,capacity:1}});posts.push(waitingEvent.id);
 await ok(a.page,'events/'+waitingEvent.id+'/register',{status:'accepted'});await ok(b.page,'events/'+waitingEvent.id+'/register',{status:'waitlisted'});await ok(admin,'events/'+waitingEvent.id+'/register',{status:'waitlisted'});
 await ok(a.page,'events/'+waitingEvent.id+'/register',{status:'cancelled'});assert.equal((await ok(b.page,'events/'+waitingEvent.id)).registered,'offered');
 await fault('expire-offer',a.id,'audit','',waitingEvent.id);
 await Promise.all(Array.from({length:5},()=>ok(a.page,'events/'+waitingEvent.id)));
 assert.equal((await ok(b.page,'events/'+waitingEvent.id)).registered,'expired');const next=await ok(admin,'events/'+waitingEvent.id);assert.equal(next.registered,'offered');assert.equal(next.reserved,1);assert.ok(next.offer_expires_at);
 assert.equal((await api(b.page,'events/'+waitingEvent.id+'/register',{status:'accepted'})).status,409);
 await Promise.all(Array.from({length:4},()=>ok(admin,'events/'+waitingEvent.id+'/register',{status:'accepted'})));
 const full=await ok(a.page,'events/'+waitingEvent.id);assert.equal(full.attending,1);assert.equal(full.reserved,1);assert.equal(full.participants.filter(p=>p.status==='accepted').length,1);
 pass('Vencimiento concurrente: oferta FIFO al siguiente, rechazo del cupo expirado y aforo sin duplicados');
 assert.deepEqual(errors,[]);fs.writeFileSync(root+'/test-results/atomic107.json',JSON.stringify({checks,errors},null,2));
}catch(error){console.error(error.stack);fs.writeFileSync(root+'/test-results/atomic107.json',JSON.stringify({checks,errors,failure:error.message},null,2));process.exitCode=1;}finally{
 try{await fault('disable');}catch(e){console.error('Fault cleanup failed');process.exitCode=1;}
 if(users[1])try{await fault('cleanup-contact',users[1].id);}catch{process.exitCode=1;}
 if(admin){for(const id of posts)await api(admin,'items/'+id,null,'DELETE').catch(()=>{});for(const user of users)await api(admin,'native:users/'+user.id+'?force=true&reassign=1',null,'DELETE').catch(()=>{});}
 if(browser)await browser.close();
}})();
