/* Real SMTP/recovery against an explicitly disposable local runtime. No reset links in output. */
const {chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const {execFileSync}=require('node:child_process');
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Disposable environment required');
const root=path.resolve(__dirname,'..'),base=process.env.ASCLA_SITE_URL,mailbox=process.env.ASCLA_MAILBOX_URL;
assert.ok(base&&mailbox&&process.env.ASCLA_ENV_FILE&&process.env.COMPOSE_PROJECT_NAME);
const out=process.env.ASCLA_RUNTIME_MAIL_OUT||path.join(root,'test-results/runtime-mail');fs.mkdirSync(out,{recursive:true});
const fixture=data=>JSON.parse(execFileSync('docker',['compose','--env-file',process.env.ASCLA_ENV_FILE,'-p',process.env.COMPOSE_PROJECT_NAME,'exec','-T','-e','ASCLA_E2E_EPHEMERAL=1','wordpress','php','/opt/ascla-tests/runtime-mail-fixture.php'],{cwd:root,input:JSON.stringify(data),encoding:'utf8'}));
const checks=[],sensitive=[];let browser,user,mailId,page,phase='setup';
const pass=message=>{checks.push(message);console.log('PASS '+message);};
(async()=>{try{
 user=fixture({action:'setup'});browser=await chromium.launch({channel:process.env.ASCLA_BROWSER_CHANNEL||'chrome',headless:true});
 page=await browser.newPage();const errors=[];page.on('pageerror',error=>errors.push(error.message));
 phase='request-recovery';
 await page.goto(base+'/wp-login.php?action=lostpassword');await page.locator('#user_login').fill(user.email);
 await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);
 await page.locator('#user_login').waitFor();assert.equal(await page.locator('#login_error').count(),0);
 pass('La recuperación conserva el formulario y no revela errores de cuenta');
 let mail;
 for(let i=0;i<30&&!mail;i++){
  const response=await fetch(mailbox+'/api/email?sort=desc');assert.equal(response.status,200);
  mail=(await response.json()).find(item=>(item.to||[]).some(to=>to.address===user.email));
  if(!mail)await new Promise(resolve=>setTimeout(resolve,300));
 }
 assert.ok(mail,'No llegó el correo de recuperación al SMTP local');mailId=mail.id;
 const detail=await (await fetch(mailbox+'/api/email/'+mail.id)).json();
 const reset=(detail.text||'').match(/https?:\/\/[^\s<>]+/g)?.find(url=>url.startsWith(base+'/')&&url.includes('action=rp'));
 assert.ok(reset,'El correo local no contiene un enlace válido de recuperación');
 sensitive.push(reset);
 pass('WordPress entrega el correo real por SMTP y el buzón permite consultarlo');
 phase='open-reset';await page.goto(reset.replace(/&amp;/g,'&'));
 await page.waitForFunction(()=>typeof globalThis.zxcvbn==='function'&&document.getElementById('pass1')?.value.length>0);
 phase='submit-reset';const password='R7!'+crypto.randomBytes(24).toString('hex');sensitive.push(password);await page.locator('#pass1').fill(password);
 if(await page.locator('#pass2').isVisible())await page.locator('#pass2').fill(password);
 await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);await page.locator('.reset-pass').waitFor();
 phase='login-new-password';await page.goto(base+'/wp-login.php');await require('./helpers/browser.cjs').waitForNativeLogin(page);
 await page.locator('#user_login').fill(user.login);await page.locator('#user_pass').fill(password);
 await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);await page.locator('#ascla-root').waitFor();
 pass('El enlace permite restablecer la contraseña y acceder con la nueva credencial');
 phase='reject-consumed-reset';const other=await browser.newContext(),reused=await other.newPage();await reused.goto(reset.replace(/&amp;/g,'&'));
 assert.equal(await reused.locator('#pass1').count(),0);await other.close();assert.deepEqual(errors,[]);
 pass('El enlace consumido no se puede reutilizar y no hay errores JavaScript');
 fs.writeFileSync(path.join(out,'summary.json'),JSON.stringify({status:'PASS',checks,reset_links_or_passwords_saved:false},null,2));
}finally{
 if(page&&checks.length<4){
  const diagnostic=await page.evaluate(()=>({pathname:location.pathname,zxcvbn:typeof globalThis.zxcvbn,passwordStrength:typeof globalThis.wp?.passwordStrength,feedback:[...document.querySelectorAll('#login_error,.message')].map(n=>n.textContent),fields:[...document.querySelectorAll('#login input')].map(n=>({id:n.id,type:n.type,filled:Boolean(n.value),disabled:n.disabled})),scripts:[...document.scripts].map(n=>({id:n.id,path:n.src?new URL(n.src).pathname:''}))})).catch(()=>null);
  fs.writeFileSync(path.join(out,'failure.json'),JSON.stringify({phase,diagnostic},null,2));
 }
 if(browser)await browser.close();if(mailId)await fetch(mailbox+'/api/email/'+mailId,{method:'DELETE'});
 if(user)fixture({action:'cleanup',id:user.id});
}})().catch(error=>{let message=error.message;for(const value of sensitive)message=message.split(value).join('[REDACTED]');message=message.replace(/https?:\/\/\S+/g,'[URL]');console.error(phase+': '+message.slice(0,500));process.exitCode=1;});
