/* Real local connection and conversation requests; no mocked account state. */
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
if(process.env.ASCLA_E2E_EPHEMERAL!=='1')throw Error('Disposable local WordPress required');
const root=path.resolve(__dirname,'..'),base=process.env.ASCLA_SITE_URL||'http://ascla_audit-wordpress';
const f=JSON.parse(fs.readFileSync(root+'/test-results/connections-panel108-fixture.json','utf8'));
const checks=[],errors=[],views=[];let browser,page;
const panel=()=>page.locator('#connections-panel'),tab=key=>panel().locator(`[data-action=connection-tab][data-tab=${key}]`),row=id=>panel().locator(`[data-connection-row="${id}"]`);
const pass=name=>{checks.push(name);console.log('PASS '+name);};
async function login(name=f.login){
 const p=await (await browser.newContext({viewport:{width:1440,height:1000}})).newPage();p.on('pageerror',error=>errors.push(error.message));
 await p.goto(base+'/wp-login.php');await p.locator('#user_login').fill(name);await p.locator('#user_pass').fill(f.password);
 await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}),p.locator('#wp-submit').click()]);return p;
}
async function count(key,n){await page.waitForFunction(({key,n})=>document.querySelector(`[data-action=connection-tab][data-tab=${key}]>span`)?.textContent===String(n),{key,n});}
async function capture(name,width,theme){
 await page.setViewportSize({width,height:1000});await page.evaluate(theme=>{localStorage.setItem('ascla-theme',theme);document.documentElement.dataset.asclaTheme=theme;document.documentElement.style.colorScheme=theme;},theme);
 await page.evaluate(async()=>{await new Promise(requestAnimationFrame);await new Promise(requestAnimationFrame);});await page.mouse.move(0,0);
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,name+' page overflow');
 const height=await panel().evaluate(el=>el.getBoundingClientRect().height);assert.ok(height<(width>1000?650:900),name+' compact height '+height);
 await panel().screenshot({path:root+'/test-results/'+name+'.png',animations:'disabled'});
 await page.addScriptTag({path:require.resolve('axe-core')});
 const violations=await page.evaluate(async()=> (await axe.run(document.querySelector('#connections-panel'),{runOnly:{type:'tag',values:['wcag2a','wcag2aa']}})).violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))})));
 assert.deepEqual(violations,[],name+' WCAG checks');views.push({name,width,theme,height:Math.round(height),overflow:false,violations});
}
async function request(action){
 const result=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes('/ascla/v1/'));await action();assert.equal((await result).status(),200);
}
(async()=>{try{
 browser=await chromium.launch({headless:true});page=await login();await page.goto(base+'/directorio/');await tab('received').waitFor();await page.keyboard.press('Escape');
 assert.equal(await tab('received').getAttribute('aria-selected'),'true');await count('contacts',2);await count('received',3);await count('sent',2);await count('blocked',1);
 assert.equal(await panel().locator('[role=tabpanel]').count(),1);assert.equal(await panel().locator('.connection-row').count(),3);
 assert.equal(await panel().locator('.connections-sections').count(),0);assert.equal(await row(f.users[1]).locator('.connection-introduction').getAttribute('open'),null);
 assert.ok(!(await panel().innerText()).includes('CARGO_RESERVADO_PANEL'));assert.ok(!(await panel().innerText()).includes('EMPRESA_RESERVADA_PANEL'));pass('Cuatro pestañas, una sola lista visible, introducciones plegadas y privacidad conservada');
 for(const [name,width,theme] of [['connections-panel108-received-light',1440,'light'],['connections-panel108-received-dark',1440,'dark'],['connections-panel108-mobile-light',390,'light'],['connections-panel108-mobile-dark',390,'dark']])await capture(name,width,theme);
 pass('Solicitudes adaptadas a escritorio/móvil y ambos temas sin desbordamiento ni infracciones automáticas WCAG A/AA');
 await row(f.users[1]).locator('.connection-introduction summary').click();await row(f.users[1]).getByText('Hola, me gustaría compartir experiencias en gobierno corporativo.',{exact:true}).waitFor();
 await row(f.users[1]).locator('.connection-name').click();await page.locator('.modal').waitFor();await page.keyboard.press('Escape');pass('Mensaje de presentación y perfil siguen accesibles desde la fila compacta');
 await tab('contacts').click();const filter=panel().locator('#connection-name-filter');await filter.fill('sin-coincidencia');assert.equal(await panel().locator('.connection-row:visible').count(),0);await panel().locator('[data-connections-empty]').waitFor();
 await filter.fill('cÁrLoS');assert.equal(await panel().locator('.connection-row:visible').count(),1);await tab('sent').click();await tab('contacts').click();assert.equal(await filter.inputValue(),'cÁrLoS');
 const beforeRefresh=await filter.elementHandle();await panel().locator('[data-action=connections-refresh]').click();await page.waitForFunction(el=>!el.isConnected,beforeRefresh);await filter.waitFor();assert.equal(await filter.inputValue(),'cÁrLoS');assert.equal(await panel().locator('.connection-row:visible').count(),1);pass('Buscador local conserva nombre y filtrado al cambiar de pestaña o actualizar');
 await tab('contacts').focus();await page.keyboard.press('ArrowRight');assert.equal(await tab('received').getAttribute('aria-selected'),'true');await page.keyboard.press('End');assert.equal(await tab('blocked').getAttribute('aria-selected'),'true');await page.keyboard.press('Home');assert.equal(await tab('contacts').getAttribute('aria-selected'),'true');pass('Pestañas utilizables con flechas, Inicio y Fin, con foco y selección sincronizados');
 await filter.fill('');await capture('connections-panel108-contacts-dark',1440,'dark');await tab('sent').click();await capture('connections-panel108-sent-light',1440,'light');await tab('received').click();
 await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('.header-language select[name="_ascla_locale"]').selectOption('en_US')]);await tab('received').waitFor();
 assert.equal(await row(f.users[2]).locator('[data-action=connection-respond][data-decision=accept]').innerText(),'Accept');assert.equal(await row(f.users[7]).locator('[data-action=conversation-request-respond][data-decision=accept]').innerText(),'Accept');
 assert.ok((await row(f.users[7]).locator('[data-action=conversation-request-respond][data-decision=accept]').getAttribute('aria-label')).startsWith('Accept conversation'));
 await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('.header-language select[name="_ascla_locale"]').selectOption('es_ES')]);await tab('received').waitFor();pass('Las acciones de ambas clases de solicitud conservan su traducción y nombre accesible');
 await request(()=>row(f.users[2]).locator('[data-action=connection-respond][data-decision=accept]').click());await count('contacts',3);await count('received',2);assert.equal(await tab('received').getAttribute('aria-selected'),'true');pass('Aceptar una conexión actualiza los totales y mantiene la pestaña de trabajo');
 await request(()=>row(f.users[1]).locator('[data-action=connection-respond][data-decision=reject]').click());await count('received',1);
 await request(()=>row(f.users[7]).locator('[data-action=conversation-request-respond][data-decision=accept]').click());await count('received',0);await panel().getByText('No tienes solicitudes pendientes.',{exact:true}).waitFor();await count('contacts',3);pass('Rechazar conexión y aceptar conversación conservan sus flujos independientes y muestran el estado vacío');
 await tab('sent').click();await row(f.users[5]).locator('.connection-row-menu summary').click();await row(f.users[5]).locator('[data-action=connection-remove-request]').click();
 await request(()=>page.locator('[data-action=connection-remove-confirm]').click());await count('sent',1);
 await row(f.users[1]).locator('.connection-row-menu summary').click();await request(()=>row(f.users[1]).locator('[data-action=conversation-request-cancel]').click());await count('sent',0);pass('Cancelar conexiones y conversaciones enviadas funciona desde Más opciones');
 await tab('blocked').click();await request(()=>row(f.users[6]).locator('[data-action=block]').click());await count('blocked',0);await panel().getByText('No hay asociados bloqueados.',{exact:true}).waitFor();pass('Desbloquear permanece accesible sin restaurar una conexión anterior');
 await tab('contacts').click();await row(f.users[3]).locator('.connection-row-menu summary').click();await row(f.users[3]).locator('[data-action=connection-remove-request]').click();await request(()=>page.locator('[data-action=connection-remove-confirm]').click());await count('contacts',2);pass('Eliminar una conexión conserva su confirmación y actualiza Contactos');
 await panel().locator('.connections-overview>summary').click();await tab('contacts').waitFor({state:'hidden'});await panel().locator('.connections-overview>summary').click();await tab('contacts').waitFor();pass('El panel completo se puede plegar para dar espacio al directorio');
 await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('.header-language select[name="_ascla_locale"]').selectOption('en_US')]);await tab('contacts').waitFor();assert.ok((await tab('contacts').innerText()).includes('Contacts'));await tab('sent').click();await panel().getByText('There are no pending sent requests.',{exact:true}).waitFor();pass('Pestañas y estados vacíos se traducen con el idioma de la cuenta');
 const empty=await login(f.empty_login);await empty.goto(base+'/directorio/');await empty.locator('.connections-overview>summary').waitFor();await empty.keyboard.press('Escape');await empty.locator('.connections-overview>summary').click();await empty.locator('.connections-empty').waitFor();assert.equal(await empty.locator('[data-action=connection-tab]').count(),4);pass('Una cuenta sin relaciones muestra un panel vacío claro y compacto');
 assert.deepEqual(errors,[]);
}catch(error){errors.push(error.message);console.error(error.stack);if(page)await panel().screenshot({path:root+'/test-results/connections-panel108-failure.png'}).catch(()=>{});process.exitCode=1;}finally{
 if(browser)await browser.close();fs.writeFileSync(root+'/test-results/connections-panel108.json',JSON.stringify({scope:'Disposable local WordPress, real owned relationships and actions; no public QA deployment',checks,views,errors},null,2)+'\n');
}})();
