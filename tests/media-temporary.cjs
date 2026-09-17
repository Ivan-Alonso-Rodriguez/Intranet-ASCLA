/* Real multipart uploads against disposable local WordPress, with isolated users. */
const {chromium}=require('playwright'),{execFileSync}=require('node:child_process');
const fs=require('node:fs'),assert=require('node:assert/strict');
const base='http://localhost:8088',results=[],errors=[];
const fixture=input=>JSON.parse(execFileSync('docker',['compose','exec','-T','wordpress','php','/opt/ascla-tests/admin-gemini-fixture.php'],{input:JSON.stringify(input),encoding:'utf8'}));
async function api(p,path,body,method){return p.evaluate(async({path,body,method})=>{const r=await fetch(ASCLA.api+path,{method:method||(body?'POST':'GET'),headers:{'X-WP-Nonce':ASCLA.nonce,'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})});const data=await r.json();if(!r.ok)throw Error(r.status+' '+data.message);return data;},{path,body,method});}
async function upload(p,name,original=0){return p.evaluate(async({name,original})=>{
 const bytes=Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j5tUAAAAASUVORK5CYII='),c=>c.charCodeAt(0));
 const f=new FormData();f.append('file',new Blob([bytes],{type:'image/png'}),name);if(original)f.append('original_id',String(original));
 const r=await fetch(ASCLA.api+'media',{method:'POST',headers:{'X-WP-Nonce':ASCLA.nonce},body:f});if(!r.ok)throw Error('upload '+r.status);return r.json();
},{name,original});}
async function login(browser,user){const context=await browser.newContext(),p=await context.newPage();p.on('pageerror',e=>errors.push(e.message));await p.goto(base+'/wp-login.php');await p.locator('#user_login').fill(user.login);await p.locator('#user_pass').fill(user.password);await Promise.all([p.waitForNavigation(),p.locator('#wp-submit').click()]);await p.goto(base+'/perfil/');await p.locator('[data-form="profile"]').waitFor();return p;}
async function test(name,fn){await fn();results.push({name,status:'passed'});console.log('PASS '+name);}
(async()=>{let f,browser,p;
 try{
  fs.mkdirSync('test-results',{recursive:true});f=fixture({action:'setup'});browser=await chromium.launch({headless:true,channel:'msedge'});
  const a=await login(browser,f.users[0]),m=await login(browser,f.users[1]),u=await login(browser,f.users[2]);p=u;
  await test('Carga temporal privada: oculta en ambas bibliotecas y cancelable solo por su propietario',async()=>{
   const image=await upload(u,'temporal-'+f.token+'.png');
   assert.ok(!(await api(u,'media')).items.some(x=>x.id===image.id));assert.ok(!(await api(a,'media?scope=all')).items.some(x=>x.id===image.id));
   assert.equal((await u.request.get(image.url)).status(),200);assert.equal((await m.request.get(image.url)).status(),404);
   assert.equal((await api(m,'media/'+image.id+'/discard',{})).discarded,false);
   assert.equal((await api(u,'media/'+image.id+'/discard',{})).discarded,true);assert.equal((await u.request.get(image.url)).status(),404);
  });
  await test('Guardar confirma el archivo; eliminarlo desde la biblioteca limpia la foto de perfil',async()=>{
   const image=await upload(u,'confirmado-'+f.token+'.png');await api(u,'profiles/me',{photo_id:image.id});
   assert.ok((await api(u,'media')).items.some(x=>x.id===image.id));assert.ok((await api(a,'media?scope=all')).items.some(x=>x.id===image.id));
   assert.equal((await api(u,'media/'+image.id+'/discard',{})).discarded,false);
   await u.goto(base+'/perfil/');await u.getByRole('button',{name:'Administrar mis archivos',exact:true}).click();
   await u.locator('[data-file="'+image.id+'"] [data-action="delete-media"]').click();
   const removed=u.waitForResponse(r=>r.url().includes('/media/'+image.id)&&r.request().method()==='DELETE');await u.locator('[data-action="delete-confirm"]').click();assert.equal((await removed).status(),200);
   assert.equal((await api(u,'bootstrap')).me.photo_id,0);assert.equal((await u.request.get(image.url)).status(),404);
   await u.screenshot({path:'test-results/media-temporary-library.png',fullPage:true});
  });
  await test('Cancelar un recorte temporal elimina también su copia maestra sin uso',async()=>{
   const master=await upload(u,'maestra-'+f.token+'.png'),crop=await upload(u,'recorte-'+f.token+'.png',master.id);
   assert.equal((await api(u,'media/'+crop.id+'/discard',{})).discarded,true);
   assert.equal((await u.request.get(crop.url)).status(),404);assert.equal((await u.request.get(master.url)).status(),404);
  });
  assert.deepEqual(errors,[]);
 }catch(e){console.error(e);process.exitCode=1;if(p)await p.screenshot({path:'test-results/media-temporary-failure.png',fullPage:true}).catch(()=>{});}
 finally{if(browser)await browser.close();if(f)fixture({action:'cleanup',token:f.token,users:f.users.map(u=>u.id)});fs.writeFileSync('test-results/media-temporary.json',JSON.stringify({results,errors},null,2));}
})();
