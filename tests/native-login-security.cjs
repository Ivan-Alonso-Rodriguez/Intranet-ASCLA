/* Native WordPress dependencies must work under the enforced nonce policy. */
const {chromium}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Disposable environment required');
const base=process.env.ASCLA_SITE_URL,checks=[],errors=[];let initialViolations=[];
if(!base)throw Error('ASCLA_SITE_URL is required');
(async()=>{
 const browser=await chromium.launch({headless:true});let page,finalCsp='',diagnostic=null;const consoleErrors=[];
 try{
  const context=await browser.newContext();page=await context.newPage();page.on('pageerror',error=>errors.push(error.stack||error.message));page.on('console',message=>{if(message.type()==='error')consoleErrors.push(message.text());});
  await context.addInitScript(()=>{window.__nativeViolations=[];document.addEventListener('securitypolicyviolation',event=>{if(event.disposition==='enforce')window.__nativeViolations.push(event.effectiveDirective);});});
  const response=await page.goto(base+'/wp-login.php');assert.equal(response.status(),200);
  await page.waitForFunction(()=>typeof jQuery==='function'&&typeof globalThis.wp?.hooks?.addAction==='function'&&typeof globalThis.wp?.i18n?.__==='function');
  const csp=response.headers()['content-security-policy'];assert.ok(csp.includes("'strict-dynamic'"));assert.ok(!/unsafe-inline|unsafe-eval/.test(csp));
  assert.equal(await page.locator('script[src*="load-scripts.php"]').count(),0);
  // strict-dynamic also allows dependencies created by an already trusted loader.
  // Check parser-inserted scripts in the original HTML, before those dynamic additions.
  assert.ok(await page.evaluate(markup=>{
    const nodes=[...new DOMParser().parseFromString(markup,'text/html').querySelectorAll('script[src]')];
    return nodes.length>0&&nodes.every(node=>Boolean(node.nonce));
  },await response.text()));
  checks.push('Las dependencias nativas cargan individualmente con nonce bajo CSP estricta');
  const second=await context.request.get(base+'/wp-login.php');assert.notEqual(csp.match(/nonce-([^']+)/)[1],second.headers()['content-security-policy'].match(/nonce-([^']+)/)[1]);
  checks.push('El nonce de CSP cambia entre respuestas del acceso nativo');
  assert.ok((await page.locator('[name="_ascla_native_login_nonce"]').inputValue()).length>8);
  initialViolations=await page.evaluate(()=>window.__nativeViolations);
  // jQuery feature probes create temporary style attributes. The strict style policy blocks them.
  // Preserve that observation while requiring every script dependency to load without a CSP block.
  assert.deepEqual(errors,[]);assert.ok(initialViolations.every(directive=>directive==='style-src-attr'));
  checks.push('El formulario conserva CSRF y carga sin errores JavaScript ni bloqueos CSP de scripts');
  // Keep the real document/network origin. Fulfilling its HTML creates a
  // synthetic public address space in Chromium and can block local assets.
  finalCsp=(await page.goto(base+'/wp-login.php')).headers()['content-security-policy'];
  await page.waitForFunction(()=>typeof globalThis.wp?.i18n?.__==='function');
  await page.evaluate(()=>{
    // document.write creates a parser-inserted script, unlike a trusted
    // DevTools-created dynamic script to which strict-dynamic may delegate.
    document.open();window.__nativeViolations=[];
    document.addEventListener('securitypolicyviolation',event=>{if(event.disposition==='enforce')window.__nativeViolations.push(event.effectiveDirective);});
    document.write('<!doctype html><title>CSP parser probe</title><script>globalThis.__untrustedNativeScriptExecuted=true<\/script>');
    document.close();
  });
  await page.waitForFunction(()=>window.__nativeViolations.some(directive=>directive.startsWith('script-src')));
  assert.equal(await page.evaluate(()=>globalThis.__untrustedNativeScriptExecuted),undefined);
  checks.push('CSP sigue bloqueando un script insertado por el parser sin nonce');
  assert.deepEqual(errors,[]);
  for(const check of checks)console.log('PASS '+check);
 }catch(error){errors.push(error.message);console.error(error.stack);process.exitCode=1;}
 finally{
  if(page)diagnostic=await page.evaluate(policy=>({scripts:[...document.scripts].map(n=>({id:n.id,src:n.src,nonce_matches:n.nonce===policy.match(/nonce-([^']+)/)?.[1],integrity:n.integrity})),violations:window.__nativeViolations}),finalCsp).catch(()=>null);
  if(errors.length)process.exitCode=1;
  await browser.close();fs.writeFileSync(path.join(__dirname,'../test-results/native-login-security.json'),JSON.stringify({date:new Date().toISOString(),target:base,checks,errors,status:errors.length?'FAIL':'PASS',initial_csp_violations:initialViolations,intentional_untrusted_script_probe:true,console_errors:consoleErrors,diagnostic},null,2)+'\n');
 }
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
