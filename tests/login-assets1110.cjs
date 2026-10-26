/* Exercise the standalone form without the native admin presentation dependency. */
const {chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Disposable environment required');
const fixture=JSON.parse(fs.readFileSync(process.env.ASCLA_LOGIN_FIXTURE,'utf8')),base=fixture.base;
if(!['localhost','127.0.0.1'].includes(new URL(base).hostname))throw Error('Local login fixture required');
const out=process.env.ASCLA_LOGIN_ASSETS_OUT||path.join(__dirname,'../test-results/login-assets1110');fs.mkdirSync(out,{recursive:true});
const checks=[],errors=[],views=[],secrets=Object.values(fixture.actors).map(actor=>actor.password);let browser;
const pass=label=>{checks.push(label);console.log('PASS '+label);};
(async()=>{try{
 browser=await chromium.launch({channel:process.env.ASCLA_BROWSER_CHANNEL||'chrome',headless:true});
 for(const width of [1440,390])for(const theme of ['light','dark'])for(const locale of ['es_ES','en_US']){
  const context=await browser.newContext({viewport:{width,height:1000}});await context.addInitScript(mode=>localStorage.setItem('ascla-theme',mode),theme);
  const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
  await page.goto(base+'/login/');
  if(locale==='en_US'){
   await page.locator('#ascla-login-language').selectOption(locale);
   await Promise.all([page.waitForNavigation(),page.locator('#language-switcher button').click()]);
  }
  assert.equal(await page.getAttribute('html','data-ascla-theme'),theme);
  assert.equal(await page.getAttribute('html','lang'),locale.replace('_','-'));
  const styles=await page.locator('link[rel=stylesheet]').evaluateAll(nodes=>nodes.map(node=>({id:node.id,path:new URL(node.href).pathname,integrity:node.integrity,nonce:node.nonce})));
  assert.ok(styles.some(style=>style.id==='ascla-login-layout-css'));
  assert.ok(styles.every(style=>!style.path.includes('admin-schemes')&&!style.path.endsWith('/login.min.css')));
  assert.ok(styles.filter(style=>style.path.includes('/ascla-core/')).every(style=>style.integrity.startsWith('sha384-')));
  const password=page.locator('#user_pass'),toggle=page.locator('.wp-hide-pw');
  await password.fill('Synthetic-visibility-only');await toggle.click();assert.equal(await password.getAttribute('type'),'text');await toggle.click();assert.equal(await password.getAttribute('type'),'password');await password.fill('');
  await page.locator('#rememberme').focus();await page.keyboard.press('Space');assert.ok(await page.locator('#rememberme').isChecked());await page.keyboard.press('Space');
  const field=await password.boundingBox(),button=await toggle.boundingBox();assert.ok(field.height>=44&&button.height>=44&&Math.abs(field.y-button.y)<=2&&button.x>=field.x&&button.x+button.width<=field.x+field.width+1);
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  await page.evaluate(fs.readFileSync(process.env.ASCLA_AXE_PATH,'utf8'));
  const accessibility=await page.evaluate(async()=>({violations:(await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa']}})).violations.map(item=>({id:item.id,nodes:item.nodes.map(node=>({target:node.target,summary:node.failureSummary}))}))}));
  assert.deepEqual(accessibility.violations,[],width+' '+theme+' '+locale+': '+JSON.stringify(accessibility.violations));
  await page.screenshot({path:path.join(out,'login-'+theme+'-'+locale+'-'+width+'.png'),fullPage:true});
  views.push({width,theme,locale,styles:styles.map(({id,path})=>({id,path})),axe_violations:0});await context.close();
 }
 pass('Ocho vistas conservan diseño, idioma, teclado, mostrar contraseña, Recordarme y accesibilidad A/AA');
 for(const [index,name] of ['Administrador','Ana','Bruno','Carla'].entries()){
  const context=await browser.newContext({viewport:{width:index%2?390:1440,height:1000}}),page=await context.newPage(),actor=fixture.actors[name];
  await page.goto(base+'/login/');await page.locator('#user_login').fill(actor.login);await page.locator('#user_pass').fill(actor.password);
  if(index===0)await page.locator('#rememberme').check();
  await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);await page.locator('#ascla-root').waitFor();
  assert.equal(new URL(page.url()).pathname,name==='Administrador'?'/administracion/':'/intranet/');
  const logged=(await context.cookies()).filter(cookie=>cookie.name.startsWith('wordpress_logged_in_'));assert.ok(logged.length);
  if(index===0)assert.ok(logged.every(cookie=>cookie.expires>0));
  await context.close();
 }
 pass('Los cuatro usuarios acceden mediante el formulario real y Recordarme conserva una cookie persistente');
 const context=await browser.newContext(),page=await context.newPage();await page.goto(base+'/login/');
 await page.locator('#user_login').fill('synthetic-missing-login');await page.locator('#user_pass').fill('Synthetic-invalid-password');
 await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);await page.locator('#login_error').waitFor();assert.ok(await page.locator('#login_error').isVisible());
 await page.locator('#nav a').click();await page.locator('#lostpasswordform').waitFor();assert.ok(await page.locator('#login-css').count());
 await page.goto(base+'/wp-login.php');assert.ok(await page.locator('#login-css').count());assert.ok(await page.locator('#wp-base-styles-css').count());assert.equal(await page.locator('#ascla-login-layout-css').count(),0);
 const csp=(await context.request.get(base+'/wp-login.php')).headers()['content-security-policy'];assert.ok(csp&&!/unsafe-inline|unsafe-eval/.test(csp));
 assert.deepEqual(errors,[]);await context.close();
 pass('Los errores del acceso son visibles; recuperación y acceso nativo conservan el CSS original y la CSP');
 fs.writeFileSync(path.join(out,'summary.json'),JSON.stringify({status:'PASS',checks,views,credentials_saved:false},null,2));
}finally{if(browser)await browser.close();}})().catch(error=>{let message=error.message;for(const secret of secrets)message=message.split(secret).join('[REDACTED]');console.error(message);process.exitCode=1;});
