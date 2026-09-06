const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const env=Object.fromEntries(fs.readFileSync(path.join(root,'.env'),'utf8').split(/\r?\n/).filter(x=>x.includes('=')).map(x=>[x.slice(0,x.indexOf('=')),x.slice(x.indexOf('=')+1)]));
(async()=>{
  const browser=await chromium.launch({headless:true,channel:'msedge'});
  try {
    const page=await browser.newPage({viewport:{width:1440,height:1050}});const coverage=[];
    await page.coverage.startJSCoverage({resetOnNavigation:false});
    const navigate=page.goto.bind(page);page.goto=async(...args)=>{coverage.push(...await page.coverage.stopJSCoverage());await page.coverage.startJSCoverage({resetOnNavigation:false});return navigate(...args);};const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await page.goto('http://localhost:8089/wp-login.php');await page.locator('#user_login').fill('demo.asociado');await page.locator('#user_pass').fill(env.ASCLA_DEMO_PASSWORD);
    await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);await page.locator('.hero').waitFor().catch(async e=>{console.error('Page',page.url(),await page.locator('body').innerText());throw e;});
    const pages=await page.evaluate(()=>ASCLA.pages);const checked=[];
    for(const [name,data] of Object.entries(pages)){await page.goto(data.url);await page.locator('#page-content h1').waitFor();assert.equal(await page.locator('#page-content .error').count(),0);checked.push(name);}
    assert.equal(checked.length,12);
    await page.locator('[data-action=notifications]').click(); await page.locator('.activity-inbox').waitFor();
    const notice=page.locator('[data-action=notification-open]').first();
    if(await notice.count()) {
      const destination=await notice.getAttribute('href');
      assert.equal(new URL(destination).origin,'http://localhost:8089');
      assert.ok(new URL(destination).searchParams.has('page_id'));
      await Promise.all([page.waitForNavigation(),notice.click()]); await page.locator('#page-content h1').waitFor();
    }
    assert.deepEqual(errors,[]);
    await page.goto(pages.intranet.url);await page.locator('.hero').waitFor();await page.screenshot({path:path.join(root,'test-results/zip-dashboard.png'),fullPage:true});
    fs.writeFileSync(path.join(root,'test-results/portability-browser.json'),JSON.stringify({date:new Date().toISOString(),version:JSON.parse(fs.readFileSync(path.join(root,'test-results/portability.json'),'utf8')).plugin,environment:'WordPress 6.8.3, ZIP only, default query-string permalinks',pages:checked,jsErrors:errors,passed:true},null,2));
    coverage.push(...await page.coverage.stopJSCoverage());fs.writeFileSync(path.join(root,'coverage/zip-v8.json'),JSON.stringify(coverage.filter(c=>c.url.includes('/ascla-core/assets/') && new URL(c.url).pathname.endsWith('.js'))));
    console.log('PASS: all 12 private pages work from the installed ZIP with default WordPress permalinks.');
  } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
