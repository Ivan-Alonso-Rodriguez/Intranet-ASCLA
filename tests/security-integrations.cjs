/* Optional external compatibility probe. Uses Cloudflare's PUBLIC dummy key only. */
const {chromium}=require('playwright'),fs=require('node:fs'),assert=require('node:assert/strict');
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Disposable local QA required');
const out=process.env.ASCLA_SECURITY_OUT||'test-results/security-latest',base=JSON.parse(fs.readFileSync(out+'/private-actors.json')).base;
if(!/^https:\/\/ascla-zap109-[a-z0-9-]+\.test$/.test(base))throw Error('Owned TLS test host required');
(async()=>{let browser;const result={public_dummy_key:true,turnstile_token_received:false,youtube_ready:false,youtube_duration:0,violations:[],errors:[]};try{
 browser=await chromium.launch({headless:true});const context=await browser.newContext({ignoreHTTPSErrors:true});const page=await context.newPage();
 page.on('pageerror',e=>result.errors.push(e.message));
 await page.exposeFunction('recordCspViolation',value=>result.violations.push(value));
 await page.addInitScript(()=>{if(window===window.top)document.addEventListener('securitypolicyviolation',e=>{if(e.disposition==='enforce')window.recordCspViolation(e.effectiveDirective+' '+e.blockedURI);});});
 const response=await context.request.get(base+'/login/'),headers=response.headers(),nonce=headers['content-security-policy'].match(/nonce-([^']+)/)[1];
 await page.route(base+'/compatibility-test',route=>route.fulfill({status:200,headers:{'Content-Type':'text/html','Content-Security-Policy':headers['content-security-policy'],'Cross-Origin-Embedder-Policy':headers['cross-origin-embedder-policy'],'Cross-Origin-Opener-Policy':headers['cross-origin-opener-policy']},body:`<!doctype html><html><head><title>Public integration compatibility</title><script nonce="${nonce}" src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script></head><body><div class="cf-turnstile" data-sitekey="1x00000000000000000000AA"></div><iframe credentialless id="player" src="https://www.youtube-nocookie.com/embed/M7lc1UVf-VE?enablejsapi=1&origin=${encodeURIComponent(base)}" title="Public API example"></iframe><script nonce="${nonce}">window.onYouTubeIframeAPIReady=()=>{window.player=new YT.Player(document.getElementById('player'),{events:{onReady:event=>{window.youtubeReady=true;event.target.mute();event.target.cueVideoById('M7lc1UVf-VE');}}});};</script><script nonce="${nonce}" src="https://www.youtube.com/iframe_api"></script></body></html>`}));
 await page.goto(base+'/compatibility-test',{waitUntil:'domcontentloaded'});
 await page.waitForFunction(()=>Boolean(document.querySelector('[name=cf-turnstile-response]')?.value),null,{timeout:45000});result.turnstile_token_received=true;
 await page.waitForFunction(()=>window.youtubeReady===true,null,{timeout:45000});result.youtube_ready=true;
 await page.waitForFunction(()=>Number(window.player?.getDuration())>0,null,{timeout:15000});result.youtube_duration=await page.evaluate(()=>Math.round(player.getDuration()));
 assert.deepEqual(result.violations,[]);assert.deepEqual(result.errors,[]);
}catch(error){result.errors.push(error.message);process.exitCode=1;}finally{if(browser)await browser.close();fs.writeFileSync(out+'/integration-compatibility.json',JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify(result));}})();
