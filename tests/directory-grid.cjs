/* Verify the real Directory layout, pagination and responsive breakpoints. */
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const out=path.resolve(process.env.ASCLA_DIRECTORY_OUT||'test-results/directory-grid-20261022');
const fixture=JSON.parse(fs.readFileSync(path.join(out,'private-access.json'),'utf8'));
if(new URL(fixture.base).hostname!=='ascla-directory.test')throw Error('Only the isolated directory host is accepted.');
const checks=[],errors=[];
const pass=name=>{checks.push(name);console.log('PASS '+name);};
(async()=>{
 const browser=await chromium.launch({headless:true});
 const context=await browser.newContext({viewport:{width:1440,height:1000},timezoneId:'America/Lima'});
 const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
 const cards=page.locator('.cards.directory .member-card');
 const ids=()=>cards.locator('[data-action="member"]').evaluateAll(nodes=>nodes.map(node=>node.dataset.id));
 const layout=()=>cards.evaluateAll(nodes=>{
  const boxes=nodes.map(node=>node.getBoundingClientRect());
  return {columns:new Set(boxes.map(box=>Math.round(box.x))).size,rows:new Set(boxes.map(box=>Math.round(box.y))).size};
 });
 try{
  await page.goto(fixture.base+'/login/');await page.locator('#user_login').fill(fixture.viewer.login);await page.locator('#user_pass').fill(fixture.viewer.password);
  await Promise.all([page.waitForNavigation(),page.locator('#wp-submit').click()]);
  await page.goto(fixture.base+'/directorio/?'+new URLSearchParams({q:fixture.prefix}));
  await page.waitForFunction(()=>document.querySelectorAll('.cards.directory .member-card').length===12);
  const search=page.locator('.directory-search'),oldSearch=await search.elementHandle();
  await search.locator('#directory-country').fill('Perú');await search.getByRole('button',{name:'Buscar',exact:true}).click();
  await page.waitForFunction(element=>!element.isConnected,oldSearch);await page.waitForFunction(()=>document.querySelectorAll('.cards.directory .member-card').length===12);
  assert.deepEqual(await layout(),{columns:4,rows:3});
  assert.match(await page.locator('.directory-pagination-summary').innerText(),/12 por página.*1–12 de 13/);
  const first=await ids();assert.equal(new Set(first).size,12);
  const pager=page.locator('.directory-pagination');const pagerBox=await pager.boundingBox();
  const lastRow=await cards.last().boundingBox();assert.ok(pagerBox.y>=lastRow.y+lastRow.height);
  await page.screenshot({path:path.join(out,'directorio-4-columnas-3-filas.png'),fullPage:true,animations:'disabled'});
  pass('Escritorio: doce perfiles distribuidos en cuatro columnas y tres filas, con paginación debajo');
  await pager.getByRole('button',{name:'Siguiente',exact:true}).click();
  await page.waitForFunction(()=>document.querySelectorAll('.cards.directory .member-card').length===1);
  const second=await ids();assert.equal(first.includes(second[0]),false);
  assert.match(await page.locator('.directory-pagination-summary').innerText(),/13–13 de 13/);
  assert.equal(await pager.getByRole('button',{name:'Siguiente',exact:true}).isDisabled(),true);
  assert.equal(await page.locator('#directory-search').inputValue(),fixture.prefix);assert.equal(await page.locator('#directory-country').inputValue(),'Perú');
  await pager.getByRole('button',{name:'Anterior',exact:true}).click();
  await page.waitForFunction(()=>document.querySelectorAll('.cards.directory .member-card').length===12);assert.deepEqual(await ids(),first);
  pass('Siguiente muestra el perfil trece sin duplicados; Anterior conserva búsqueda, país y orden');
  for(const [width,columns,rows] of [[1280,4,3],[1024,2,6],[390,1,12]]){
   await page.setViewportSize({width,height:1000});assert.deepEqual(await layout(),{columns,rows});
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
   assert.equal(await cards.count(),12);
  }
  await page.screenshot({path:path.join(out,'directorio-movil.png'),fullPage:true,animations:'disabled'});
  pass('Doce perfiles por página también en tableta y móvil, sin desbordamiento horizontal');
  assert.deepEqual(errors,[]);
 }finally{
  await context.close();await browser.close();
  fs.writeFileSync(path.join(out,'browser-result.json'),JSON.stringify({date:new Date().toISOString(),checks,errors},null,2)+'\n');
 }
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
