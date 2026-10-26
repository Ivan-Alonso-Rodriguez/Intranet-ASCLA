/* Read-only authenticated security checks against the explicitly authorized shared QA. */
const {chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const out=path.resolve('test-results/qa-refresh-20261022');
const provided=JSON.parse(fs.readFileSync(path.join(out,'private-provided-access.json'))),access=JSON.parse(fs.readFileSync('test-results/playwright-usuarios.json'));
require('../scripts/qa-access.cjs')(access);const base=access.base;
if(base!=='https://wordpress.ingsoftware.lat'||provided.base!==base)throw Error('Only the authorized published QA is accepted.');
const checks=[],errors=[],violations=[],responses=[],screenshots=[],videos=[];
const pass=name=>{checks.push(name);console.log('PASS '+name);};
const sanitize=error=>Object.values({...provided.actors,...access.actors}).reduce((value,actor)=>value.split(actor.password).join('[retirado]'),String(error));
(async()=>{
 const browser=await chromium.launch({channel:'msedge',headless:true}),contexts=[];
 try{
  const deployment=await require('../scripts/qa-target-provenance.cjs')(base);
  fs.writeFileSync(path.join(out,'deployment.json'),JSON.stringify(deployment,null,2)+'\n');
  for(const [role,actor] of [['Administrador',provided.actors.Administrador],['Ejecutivo',provided.actors.Ejecutivo],['Moderador',provided.actors.Moderador],['Asociado',access.actors.Ana]]){
   const context=await browser.newContext({viewport:{width:1440,height:1000},timezoneId:'America/Lima',recordVideo:{dir:path.join(out,'security-videos',role),size:{width:1280,height:900}}});contexts.push(context);
   const page=await context.newPage();page.on('pageerror',error=>errors.push({role,message:sanitize(error.message)}));
   await context.addInitScript(()=>{window.__qaCsp=[];document.addEventListener('securitypolicyviolation',e=>{if(e.disposition==='enforce')window.__qaCsp.push({directive:e.effectiveDirective,blocked:e.blockedURI.split('?')[0]});});});
   await page.goto(base+'/login/');await page.locator('#user_login').fill(actor.login);await page.locator('#user_pass').fill(actor.password);
   await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);await page.locator('#ascla-root').waitFor();
   const cookies=(await context.cookies()).filter(cookie=>cookie.name.startsWith('wordpress_logged_in_'));
   assert.ok(cookies.length>0&&cookies.every(cookie=>cookie.httpOnly&&cookie.secure&&cookie.sameSite==='Lax'));
   const nonce=await page.evaluate(()=>ASCLA.nonce),headers={'X-WP-Nonce':nonce};
   const identity=await context.request.get(base+'/wp-json/ascla/v1/bootstrap',{headers});assert.equal(identity.status(),200);assert.equal((await identity.json()).me.id,actor.id||9);
   const slugs=['perfil','eventos','hub','galeria','foros','centro-conocimiento','asistente','aliados',...(['Administrador','Ejecutivo'].includes(role)?['administracion']:['directorio','mensajeria','contacto','intranet'])];
   for(const slug of slugs){
    const response=await page.goto(base+'/'+slug+'/');await page.locator('#page-content h1').first().waitFor();
    assert.equal(response.status(),200);const h=response.headers();assert.ok(h['content-security-policy']);assert.equal(h['x-content-type-options'],'nosniff');assert.match(h['x-frame-options'],/^(SAMEORIGIN|DENY)$/i);
    assert.ok(!/unsafe-inline|unsafe-eval/.test(h['content-security-policy']));violations.push(...(await page.evaluate(()=>window.__qaCsp||[])).map(item=>({role,path:'/'+slug+'/',...item})));
    responses.push({role,path:'/'+slug+'/',status:response.status()});
   }
   if(role==='Ejecutivo')assert.equal((await context.request.get(base+'/wp-json/ascla/v1/settings',{headers})).status(),403);
   if(role==='Asociado'){
    assert.equal((await context.request.get(base+'/wp-json/ascla/v1/admin/users',{headers})).status(),403);
    const cors=await context.request.get(base+'/wp-json/ascla/v1/bootstrap',{headers:{...headers,Origin:'https://untrusted.example'}});assert.equal(cors.status(),200);assert.equal(cors.headers()['access-control-allow-origin'],undefined);assert.equal(cors.headers()['access-control-allow-credentials'],undefined);
   }
   const shot='security-'+role.toLowerCase()+'.png';await page.screenshot({path:path.join(out,shot),fullPage:true,animations:'disabled'});screenshots.push(shot);
   const video=page.video();await context.close();if(video)videos.push(path.relative(out,await video.path()).replaceAll(path.sep,'/'));
   pass(role+': sesión segura, módulos con CSP impuesta y permisos REST vigentes');
  }
  const anon=await browser.newContext(),other=await browser.newContext();contexts.push(anon,other);
  assert.equal((await anon.request.get(base+'/wp-json/ascla/v1/profiles')).status(),401);
  const native=await anon.request.get(base+'/wp-login.php'),html=await native.text(),nonce=html.match(/name="_ascla_native_login_nonce"\s+value="([^"]+)"/)?.[1];assert.ok(nonce);
  await other.request.get(base+'/wp-login.php');const rejection=await other.request.post(base+'/wp-login.php',{form:{log:provided.actors.Administrador.login,pwd:provided.actors.Administrador.password,_ascla_native_login_nonce:nonce},maxRedirects:0});
  assert.equal(rejection.status(),200);assert.match(await rejection.text(),/formulario expiró|form session expired/);assert.equal((await other.cookies()).some(cookie=>cookie.name.startsWith('wordpress_logged_in_')),false);
  pass('Directorio anónimo rechazado y login protegido frente a un nonce de otro navegador');
  assert.deepEqual(errors,[]);assert.deepEqual(violations,[]);pass('Sin errores JavaScript ni infracciones CSP en las vistas autenticadas revisadas');
 }catch(error){errors.push({message:sanitize(error.message)});console.error(sanitize(error.stack));process.exitCode=1;}
 finally{
  for(const context of contexts)await context.close().catch(()=>{});await browser.close();
  fs.writeFileSync(path.join(out,'remote-browser-security.json'),JSON.stringify({date:new Date().toISOString(),target:base,status:errors.length?'FAIL':'PASS',mode:'remote-authenticated-read-only',checks,errors,violations,responses,screenshots,videos,active_zap:false},null,2)+'\n');
 }
})().catch(error=>{console.error(sanitize(error.message));process.exitCode=1;});
