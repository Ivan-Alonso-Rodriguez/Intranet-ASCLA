/* RNF-015/016: real screens, visible forms, keyboard and WCAG 2.1 A/AA. */
const {chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..'),base=process.env.ASCLA_SITE_URL||'http://ascla_audit-wordpress';
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Disposable environment required');
const env=Object.fromEntries(fs.readFileSync(process.env.ASCLA_ENV_FILE||root+'/.env.audit','utf8').split(/\r?\n/).filter(v=>v.includes('=')).map(v=>[v.slice(0,v.indexOf('=')),v.slice(v.indexOf('=')+1)]));
const checks=[],errors=[],scans=[];let browser,page,nativeUrl,originalLocale;
const routes=['intranet','perfil','eventos','hub','galeria','foros','directorio','centro-conocimiento','asistente','aliados','mensajeria','contacto','administracion'];
async function api(route,body){return page.evaluate(async({route,body})=>{const r=await fetch(ASCLA.api+route,{method:'POST',headers:{'X-WP-Nonce':ASCLA.nonce,'Content-Type':'application/json'},body:JSON.stringify(body)});return {status:r.status,body:await r.json()};},{route,body});}
async function changeLocale(locale){
 const actual=await page.evaluate(async locale=>{
  const body=new URLSearchParams({_ascla_change_language:'1',_ascla_locale:locale,_ascla_language_nonce:ASCLA.languageNonce});
  const r=await fetch(location.href,{method:'POST',body});if(!r.ok)throw Error('Language change failed');
  const state=await fetch(ASCLA.api.replace(/ascla\/v1\/?$/,'wp/v2/')+'users/me?context=edit',{headers:{'X-WP-Nonce':ASCLA.nonce}});return (await state.json()).locale;
 },locale);assert.equal(actual,locale);
}
async function goto(route){await page.goto(base+'/'+route+'/');await page.locator('#page-content h1').waitFor();await page.locator('#page-content[aria-busy="true"]').waitFor({state:'hidden'});await page.keyboard.press('Escape');await page.evaluate(()=>document.fonts.ready);}
async function scan(name){
 await page.addScriptTag({path:require.resolve('axe-core/axe.min.js')});
 const result=await page.evaluate(async()=>{
  const r=await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa']}});
  const simplify=v=>({id:v.id,impact:v.impact,help:v.help,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))});
  return {violations:r.violations.map(simplify),incomplete:r.incomplete.map(simplify),passes:r.passes.map(v=>v.id),overflow:document.documentElement.scrollWidth>innerWidth+1,
   documentWidth:document.documentElement.scrollWidth,viewport:innerWidth,
   overflowing:[...document.querySelectorAll('body *')].filter(n=>n.getClientRects().length&&n.getBoundingClientRect().right>innerWidth+1&&!n.closest('.table-wrap,script,style')).slice(0,8).map(n=>({tag:n.tagName,class:typeof n.className==='string'?n.className:'',width:n.getBoundingClientRect().width,right:n.getBoundingClientRect().right,inert:!!n.closest('[inert]')}))};
 });
 scans.push({name,...result});console.log(name+': '+result.violations.length+' violations'+(result.overflow?' OVERFLOW '+result.overflowing.join(', '):''));
 if(result.overflow){console.log(JSON.stringify({name,width:result.documentWidth,viewport:result.viewport,targets:result.overflowing}));await page.screenshot({path:root+'/test-results/accessibility-overflow.png'});}
 fs.writeFileSync(root+'/test-results/accessibility107-progress.json',JSON.stringify(scans,null,2));
}
function pass(name){checks.push(name);console.log('PASS '+name);}
async function trap(selector){
 const container=page.locator(selector);
 const boundaries=await container.evaluate(n=>{const controls=[...n.querySelectorAll('a[href],button,input,select,textarea,[tabindex],summary')].filter(x=>!x.disabled&&x.tabIndex>=0&&x.getClientRects().length&&!x.closest('[inert]'));controls.at(-1).focus();return controls.length;});
 assert.ok(boundaries>1);await page.keyboard.press('Tab');assert.ok(await container.evaluate(n=>n.contains(document.activeElement)));
 await page.keyboard.press('Shift+Tab');assert.ok(await container.evaluate(n=>n.contains(document.activeElement)));
 assert.ok(await page.locator('.ascla-main').evaluate(n=>n.inert));
}
(async()=>{try{
 browser=await chromium.launch({headless:true});page=await browser.newPage();page.on('pageerror',e=>errors.push(e.message));
 await page.goto(base+'/wp-login.php');await page.locator('#user_login').fill(env.ASCLA_ADMIN_USER||'ascla.admin');await page.locator('#user_pass').fill(env.ASCLA_ADMIN_PASSWORD);
 await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('#wp-submit').click()]);
 await goto('intranet');nativeUrl=await page.evaluate(()=>ASCLA.api.replace(/ascla\/v1\/?$/,'wp/v2/')+'users/me');
 originalLocale=await page.evaluate(async url=>{const r=await fetch(url+'?context=edit',{headers:{'X-WP-Nonce':ASCLA.nonce}});return (await r.json()).locale;},nativeUrl);assert.ok(typeof originalLocale==='string');
 await changeLocale('es_ES');
 for(const theme of ['light','dark']){
  await page.addInitScript(mode=>localStorage.setItem('ascla-theme',mode),theme);
  for(const width of [360,768,1024]){
   await page.setViewportSize({width,height:900});
   for(const route of routes){await goto(route);await scan(route+' '+theme+' '+width);if(route==='directorio')await page.screenshot({path:root+'/test-results/accessibility-directory-'+theme+'-'+width+'.png',fullPage:true});}
  }
  for(const width of [360,1024]){
   await page.setViewportSize({width,height:1000});await goto('administracion');
   const tabs=await page.locator('.admin-tab[data-tab]').evaluateAll(nodes=>nodes.map(n=>n.dataset.tab));
   for(const tab of tabs){await page.locator('.admin-tab[data-tab="'+tab+'"]').click();await page.waitForFunction(key=>document.querySelector('.admin-tab.active')?.dataset.tab===key&&document.querySelector('#admin-panel')?.textContent.trim().length>0,tab);await scan('admin '+tab+' '+theme+' '+width);}
  }
  await page.setViewportSize({width:360,height:900});
  let uploadChecked = false;
  for(const [route,type] of [['eventos','event'],['hub','hub'],['galeria','gallery'],['foros','topic'],['centro-conocimiento','resource']]){
   await goto(route);const button=page.locator('[data-action="editor"][data-type="'+type+'"]');if(await button.count()){
    await button.first().click();await page.locator('.modal form[data-form="editor"]').waitFor();await scan('editor '+type+' '+theme);await trap('.modal');
    assert.deepEqual(await page.evaluate(()=>{const ids=[...document.querySelectorAll('[id]')].map(n=>n.id);return ids.filter((id,i)=>ids.indexOf(id)!==i);}),[],'duplicate IDs in editor');
    const upload=page.locator('.modal label[role="button"]:has(input[type="file"][hidden])');
    if(await upload.count()&&!uploadChecked){
     uploadChecked=true;await upload.first().focus();assert.ok(await upload.first().evaluate(n=>n===document.activeElement),'upload focus '+type+' '+theme);
     const picker=page.waitForEvent('filechooser');await page.keyboard.press('Enter');await (await picker).setFiles(root+'/wp-content/plugins/ascla-core/assets/ascla-logo.png');
     await page.locator('.ascla-image-editor').waitFor();await scan('editor de imagen '+theme);await trap('.ascla-image-editor');
     const zoom=page.locator('.ascla-image-editor input[type="range"]');await zoom.focus();const initial=Number(await zoom.inputValue());await page.keyboard.press('ArrowRight');assert.equal(Number(await zoom.inputValue()),initial+1);
     assert.ok(await page.locator('.modal').evaluate(n=>n.closest('.modal-backdrop').inert));await page.keyboard.press('Escape');await page.locator('.ascla-image-editor').waitFor({state:'hidden'});
     await page.waitForFunction(()=>document.querySelector('.modal input[type="file"]').value==='');assert.ok(await upload.first().evaluate(n=>n===document.activeElement));
    }
    await page.keyboard.press('Escape');await page.locator('.modal').waitFor({state:'hidden'});assert.ok(await button.first().evaluate(n=>n===document.activeElement));
   }
  }
 }
 pass('13 pantallas en 360, 768 y 1024 px, ambos temas; administración y editores con análisis WCAG 2.1');
 pass('Adjuntos accesibles con teclado y campos de editores sin identificadores duplicados');
 await goto('hub');const trigger=page.locator('[data-action="editor"][data-type="hub"]').first();await trigger.click();await page.locator('.modal [name="title"]').fill('Cambio pendiente de accesibilidad');
 await page.keyboard.press('Escape');await page.locator('.modal-decision').waitFor();await trap('.modal-decision');assert.ok(await page.locator('.modal:not(.modal-decision)').evaluate(n=>n.closest('.modal-backdrop').inert));await scan('confirmación de cambios sin guardar');
 await page.keyboard.press('Escape');await page.locator('.modal-decision').waitFor({state:'hidden'});await trap('.modal');
 await page.keyboard.press('Escape');await page.locator('[data-action="modal-unsaved-discard"]').click();await page.locator('.modal').waitFor({state:'hidden'});assert.ok(await trigger.evaluate(n=>n===document.activeElement));
 pass('Diálogos anidados: Tab, Shift+Tab, Escape, fondo inerte y retorno al control de origen');
 await trigger.click();const editor=page.locator('.modal form[data-form="editor"]');await editor.locator('[name="title"]').fill('Prueba accesible de error');await editor.locator('[name="body"]').fill('Contenido de prueba sin publicación.');
 const saveRoute=url=>url.pathname.endsWith('/ascla/v1/content/hub');await page.route(saveRoute,route=>route.request().method()==='POST'?route.fulfill({status:400,contentType:'application/json',body:JSON.stringify({code:'a11y_test',message:'No se pudo guardar el contenido.',data:{status:400}})}):route.continue());
 await editor.locator('.form-actions button:not([type])').click();await page.locator('.modal .feedback-toast[role="status"]').waitFor();assert.ok(await page.locator('.modal .feedback-toast').evaluate(n=>!n.closest('[inert]')));await scan('error de guardado accesible en diálogo');
 await page.unroute(saveRoute);await page.keyboard.press('Escape');await page.locator('[data-action="modal-unsaved-discard"]').click();await page.locator('.modal').waitFor({state:'hidden'});
 pass('Error de guardado anunciado dentro del diálogo activo; respuesta HTTP simulada sin publicar');
 await page.setViewportSize({width:360,height:900});await goto('directorio');const menu=page.locator('[data-action="menu"]');
 assert.equal(await menu.getAttribute('aria-expanded'),'false');assert.ok(await page.locator('.ascla-sidebar').evaluate(n=>n.inert));await menu.click();assert.equal(await menu.getAttribute('aria-expanded'),'true');assert.ok(await page.locator('.ascla-sidebar').evaluate(n=>n.contains(document.activeElement)&&!n.inert));await scan('menú móvil abierto');
 await page.keyboard.press('Escape');assert.equal(await menu.getAttribute('aria-expanded'),'false');assert.ok(await menu.evaluate(n=>n===document.activeElement));
 await page.locator('.skip-link').focus();await page.keyboard.press('Enter');assert.equal(await page.evaluate(()=>document.activeElement.id),'main');
 pass('Menú móvil cerrado fuera del teclado, apertura/cierre accesibles y salto al contenido');
 await page.locator('.header-search input[name="q"]').fill('gobierno');await page.locator('.header-search input[name="q"]').press('Enter');await page.waitForURL(/centro-conocimiento.*q=gobierno/);await page.locator('#page-content h1').waitFor();
 pass('Búsqueda global disponible y operativa también a 360 px');
 await goto('contacto');const form=page.locator('[data-form="contact"]');let requests=0;const observe=r=>{if(r.request().method()==='POST'&&r.url().endsWith('/content/contact'))requests++;};page.on('response',observe);
 await form.locator('button').click();assert.equal(requests,0);assert.equal(await form.locator('[name="title"]').evaluate(n=>n.validity.valueMissing),true);
 const forced=await api('content/contact',{title:'',body:'',category:'Consulta'});assert.equal(forced.status,400);page.off('response',observe);
 pass('Contacto vacío: bloqueo nativo con etiqueta y rechazo independiente del backend');
 try {
  await changeLocale('en_US');
  await goto('directorio');assert.equal(await page.locator('.skip-link').innerText(),'Skip to content');assert.ok(await page.getByRole('heading',{name:'Your professional network'}).isVisible());await menu.click();assert.equal(await menu.getAttribute('aria-label'),'Close navigation');await scan('directorio inglés con menú');
 }finally{await changeLocale('es_ES');}
 pass('Gettext inglés: navegación, salto al contenido y cierre del menú; idioma original restaurado');
 assert.deepEqual(errors,[]);const failures=scans.filter(r=>r.violations.length||r.overflow);assert.equal(failures.length,0,JSON.stringify(failures.map(r=>({name:r.name,ids:r.violations.map(v=>v.id),overflow:r.overflow})),null,2));
 pass('Sin infracciones automáticas A/AA ni desbordamiento en los estados examinados');
}catch(error){console.error(error.stack);process.exitCode=1;errors.push(error.message);}finally{
 if(page&&originalLocale!==undefined)await changeLocale(originalLocale);
 fs.writeFileSync(root+'/test-results/accessibility107.json',JSON.stringify({axe:require('axe-core/package.json').version,checks,errors,scans},null,2));if(browser)await browser.close();
}})();
