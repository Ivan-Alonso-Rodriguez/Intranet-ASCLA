const {chromium}=require('playwright'),fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
const {execFileSync}=require('child_process');
const root=path.resolve(__dirname,'..'),base='http://localhost:8088';
const env=Object.fromEntries(fs.readFileSync(path.join(root,'.env'),'utf8').split(/\r?\n/).filter(x=>x.includes('=')).map(x=>[x.slice(0,x.indexOf('=')),x.slice(x.indexOf('=')+1)]));
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'msedge'}),page=await browser.newPage(),coverage=[],errors=[],checks=[];
 page.on('pageerror',e=>errors.push(e.message)); await page.coverage.startJSCoverage({resetOnNavigation:false});
 const navigate=async url=>{coverage.push(...await page.coverage.stopJSCoverage());await page.coverage.startJSCoverage({resetOnNavigation:false});await page.goto(url);};
 try{
  await page.setViewportSize({width:1366,height:768});await navigate(base+'/wp-login.php');await page.screenshot({path:path.join(root,'test-results/sprint-login-1366.png')});
  await page.locator('#user_login').fill('ascla.admin');await page.locator('#user_pass').fill(env.ASCLA_ADMIN_PASSWORD);await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);await navigate(base+'/intranet/');await page.locator('.hero').waitFor();const pages=await page.evaluate(()=>ASCLA.pages);
  for(const [width,height] of [[1366,768],[1920,1080],[768,1024],[390,844]]){
   await page.setViewportSize({width,height});
   for(const [name,data] of Object.entries(pages)){
    await navigate(data.url);await page.locator('#page-content h1').waitFor();
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,`Overflow ${name} ${width}`);
    assert.equal(await page.locator('#page-content .error').count(),0,`${name} ${width}`);
    if(['intranet','directorio','centro-conocimiento'].includes(name))await page.screenshot({path:path.join(root,`test-results/sprint-${name}-${width}.png`),fullPage:true});
   }
   await page.locator('[data-action=notifications]').click();await page.locator('.activity-inbox').waitFor();
   const box=await page.locator('.modal').boundingBox();assert.ok(box.x>=0&&box.x+box.width<=width+1&&box.y>=0&&box.y+box.height<=height+1,`Modal ${width}`);await page.screenshot({path:path.join(root,`test-results/sprint-notifications-${width}.png`)});await page.locator('.modal [data-action=close]').click();
   if(width<760){await page.locator('[data-action=menu]').click();await page.locator('.ascla-sidebar.open').waitFor();await page.waitForFunction(()=>document.querySelector('.ascla-sidebar.open').getBoundingClientRect().x>=-1);await page.screenshot({path:path.join(root,'test-results/sprint-sidebar-mobile.png')});}
   checks.push(`${width}x${height}: 12 pages and notification modal without overflow`);
  }
  await page.context().clearCookies();await navigate(base+'/wp-login.php');await page.locator('#user_login').fill('demo.asociado');await page.locator('#user_pass').fill(env.ASCLA_DEMO_PASSWORD);await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);
  await page.setViewportSize({width:1366,height:768});await navigate(base+'/directorio/');await page.locator('#page-content h1').waitFor();
  await page.locator('[data-action=member]').first().click();await page.locator('.modal').waitFor();await page.screenshot({path:path.join(root,'test-results/sprint-matching.png')});
  await page.locator('.modal [data-action=intro]').click();await page.locator('[data-form=intro] textarea').fill('Propuesta editable de demostración.');await page.screenshot({path:path.join(root,'test-results/sprint-intro.png')});await page.locator('.modal [data-action=close]').first().click();checks.push('AI matching modal and editable introduction; cancellation without send');
  await page.context().clearCookies();await navigate(base+'/wp-login.php');await page.locator('#user_login').fill('ascla.admin');await page.locator('#user_pass').fill(env.ASCLA_ADMIN_PASSWORD);await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);
  const demo=JSON.parse(execFileSync('docker',['compose','exec','-T','wordpress','php'],{cwd:root,input:'<?php require "/var/www/html/wp-load.php";echo wp_json_encode(get_option("ascla_demo_showcase"));',encoding:'utf8'}));
  await navigate(base+'/centro-conocimiento/?item='+demo.resource_id);await page.locator('.generated-results').waitFor();
  for(const d of await page.locator('.generated-section').all())if(await d.getAttribute('open')===null)await d.locator('summary').click();
  assert.match(await page.locator('.modal').innerText(),/Cápsula sugerida/);
  const download=page.waitForEvent('download');await page.locator('[data-action=infographic]').click();const file=path.join(root,'test-results/sprint-infographic.svg');await(await download).saveAs(file);const svg=fs.readFileSync(file,'utf8');for(const phrase of ['35 %','Cronología','2024','ASCLA','Fuente:'])assert.ok(svg.includes(phrase),phrase);
  await page.screenshot({path:path.join(root,'test-results/sprint-multimedia.png')});checks.push('Synthetic multimedia draft with grounded statistics, timeline, SVG download and suggested capsules');
  await navigate(base+'/wp-admin/admin.php?page=ascla');await page.screenshot({path:path.join(root,'test-results/sprint-admin.png'),fullPage:true});
  assert.deepEqual(errors,[]);fs.writeFileSync(path.join(root,'test-results/sprint-ui.json'),JSON.stringify({date:new Date().toISOString(),passed:true,checks,jsErrors:errors},null,2));console.log(JSON.stringify({passed:true,checks},null,2));
 }catch(e){await page.screenshot({path:path.join(root,'test-results/sprint-ui-failure.png'),fullPage:true});console.error(page.url());throw e;}
 finally{coverage.push(...await page.coverage.stopJSCoverage());fs.writeFileSync(path.join(root,'coverage/sprint-v8.json'),JSON.stringify(coverage.filter(c=>c.url.includes('/ascla-core/assets/'))));await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
