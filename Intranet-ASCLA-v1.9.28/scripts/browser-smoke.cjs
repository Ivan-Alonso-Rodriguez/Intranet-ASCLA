const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const env = Object.fromEntries(fs.readFileSync(path.join(root,'.env'),'utf8').split(/\r?\n/).filter(x=>x.includes('=')).map(x=>[x.slice(0,x.indexOf('=')),x.slice(x.indexOf('=')+1)]));
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'msedge'});
 const page=await browser.newPage({viewport:{width:1440,height:1050}});
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto('http://localhost:8088/wp-login.php');
 await page.locator('#user_login').fill('demo.asociado');await page.locator('#user_pass').fill(env.ASCLA_DEMO_PASSWORD);
 await Promise.all([page.waitForURL('**/intranet/'),page.locator('#wp-submit').click()]);
 await page.locator('.hero').waitFor();
 fs.mkdirSync(path.join(root,'tmp/screens'),{recursive:true});
 await page.screenshot({path:path.join(root,'tmp/screens/dashboard-desktop.png'),fullPage:true});
 const routes=['perfil','directorio','hub','foros','eventos','centro-conocimiento','asistente','galeria','aliados','mensajeria','contacto'];
 const results=[];
 for(const route of routes){await page.goto('http://localhost:8088/'+route+'/');await page.locator('#page-content h1').waitFor();const failures=await page.locator('#page-content .error').allTextContents();results.push({route,heading:await page.locator('#page-content h1').innerText(),failures});}
 await page.setViewportSize({width:390,height:844});await page.goto('http://localhost:8088/intranet/');await page.locator('.hero').waitFor();
 await page.screenshot({path:path.join(root,'tmp/screens/dashboard-mobile.png'),fullPage:true});
 const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth);
 console.log(JSON.stringify({routes:results,jsErrors:errors,mobileOverflow:overflow},null,2));
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1);});
