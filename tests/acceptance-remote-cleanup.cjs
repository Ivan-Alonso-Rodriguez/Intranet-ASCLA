/* The QA helper accepts cleanup only through a logged-in administrator and WordPress nonce. */
const {chromium}=require('playwright');
module.exports=async function(fixture){
 if(fixture.origin!=='ascla-qa-tools'||!/^web_[A-Za-z0-9_]+$/.test(fixture.run||''))throw Error('QA helper fixture required');
 const browser=await chromium.launch({headless:true});
 try {
  const page=await browser.newPage();await page.goto(fixture.base+'/wp-login.php');
  await page.locator('#user_login').fill(fixture.actors.admin.login);await page.locator('#user_pass').fill(fixture.actors.admin.password);
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#wp-submit').click()]);
  await page.goto(fixture.base+'/wp-admin/tools.php?page=ascla-qa-tools');const form=page.locator('[data-ascla-qa-cleanup]');await form.waitFor();
  if(await form.locator('[name=run]').inputValue()!==fixture.run)throw Error('The active QA batch does not match this run');
  const nonce=await form.locator('[name=_wpnonce]').inputValue();
  const r=await page.request.post(fixture.base+'/wp-admin/admin-post.php',{form:{action:'ascla_qa_tools_cleanup',_wpnonce:nonce,run:fixture.run,format:'json'}});
  if(!r.ok())throw Error('QA cleanup returned HTTP '+r.status());const result=await r.json();if(result.cleaned!==true||result.run!==fixture.run)throw Error('QA cleanup was not confirmed');
  return {status:'passed',method:'WordPress admin + nonce',run:fixture.run};
 } finally {await browser.close();}
};
