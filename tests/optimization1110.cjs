/* Browser regression for selective assets and all ordinary community pages. */
const {chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const base=process.env.ASCLA_SITE_URL,out=path.resolve('test-results/optimization-20261028');
if(process.env.ASCLA_E2E_EPHEMERAL!=='1'||!['127.0.0.1','localhost'].includes(new URL(base).hostname))throw Error('Disposable loopback required');
const settings=Object.fromEntries(fs.readFileSync(process.env.ASCLA_ENV_FILE,'utf8').split(/\r?\n/).filter(s=>s.includes('=')).map(s=>[s.slice(0,s.indexOf('=')),s.slice(s.indexOf('=')+1)]));
const checks=[],errors=[],serverErrors=[],views=[];let browser,page;
const pass=name=>{checks.push(name);console.log('PASS '+name);};
async function login(user,password){const context=await browser.newContext({viewport:{width:1440,height:1050},reducedMotion:'reduce',timezoneId:'America/Lima'});const p=await context.newPage();p.on('pageerror',error=>errors.push(error.message));p.on('response',r=>{if(r.status()>=500)serverErrors.push({status:r.status(),path:new URL(r.url()).pathname});});await p.goto(base+'/wp-login.php');await p.locator('#user_login').fill(user);await p.locator('#user_pass').fill(password);await Promise.all([p.waitForNavigation(),p.locator('#wp-submit').click()]);return p;}
(async()=>{try{
 browser=await chromium.launch({headless:true,channel:process.env.ASCLA_BROWSER_CHANNEL||'chrome'});page=await login('demo.asociado',settings.ASCLA_DEMO_PASSWORD);
 await page.goto(base+'/intranet/');await page.locator('.hero').waitFor();
 const removed=await page.locator('script[src]').evaluateAll(nodes=>nodes.map(n=>n.src).filter(s=>/(?:admin-statistics|admin-members|interest-imports)\.js/.test(s)));assert.deepEqual(removed,[]);pass('El asociado carga la aplicación sin descargar los tres módulos administrativos');
 const pages=await page.evaluate(()=>ASCLA.pages);
 for(const [key,p] of Object.entries(pages)){
  await page.goto(p.url);await page.locator('.nav-link').first().waitFor();await page.locator('#page-content h1').waitFor();await page.waitForLoadState('networkidle');
  assert.ok(await page.locator('#page-content').innerText());views.push(key);
 }
 pass('Los '+views.length+' módulos de la comunidad renderizan con sus datos y navegación');
 await page.goto(pages.directorio.url);await page.locator('.member-card').first().waitFor();await page.screenshot({path:path.join(out,'directory-desktop.png'),fullPage:true});
 await page.setViewportSize({width:390,height:844});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await page.screenshot({path:path.join(out,'directory-mobile.png'),fullPage:true});pass('El Directorio mantiene tarjetas, navegación y adaptación móvil');
 const admin=await login(settings.ASCLA_ADMIN_USER,settings.ASCLA_ADMIN_PASSWORD);await admin.goto(base+'/administracion/?section=estadisticas');await admin.locator('[data-stat=active_connections]').waitFor();
 const included=await admin.locator('script[src]').evaluateAll(nodes=>nodes.map(n=>n.src).filter(s=>/(?:admin-statistics|admin-members|interest-imports)\.js/.test(s)));assert.equal(included.length,3);await admin.screenshot({path:path.join(out,'statistics-admin.png'),fullPage:true});pass('Administración conserva los tres módulos y renderiza las estadísticas');
 assert.deepEqual(errors,[]);assert.deepEqual(serverErrors,[]);pass('Sin errores JavaScript ni respuestas 500 durante el recorrido');
 }catch(error){if(page)await page.screenshot({path:path.join(out,'browser-failure.png'),fullPage:true}).catch(()=>{});console.error(error);process.exitCode=1;
 }finally{if(browser)await browser.close();fs.writeFileSync(path.join(out,'browser.json'),JSON.stringify({checks,views,errors,serverErrors,status:process.exitCode?'FAIL':'PASS'},null,2));}
})();
