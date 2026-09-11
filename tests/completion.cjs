/* End-to-end acceptance of the completed prompt items, using disposable local fixtures. */
const { chromium } = require('playwright');
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '..'), base = 'http://localhost:8088';
const env = Object.fromEntries(fs.readFileSync(path.join(root, '.env'), 'utf8').split(/\r?\n/).filter(x => x.includes('=')).map(x => [x.slice(0, x.indexOf('=')), x.slice(x.indexOf('=') + 1)]));
const ids = [], media = [], coverage = [], checks = [], errors = [];
async function request(page, route, body) {
  return page.evaluate(async ({ route, body }) => {
    const response = await fetch(ASCLA.api + route, { method: body ? 'POST' : 'GET', headers: { 'X-WP-Nonce': ASCLA.nonce, 'Content-Type': 'application/json' }, ...(body ? { body: JSON.stringify(body) } : {}) });
    const result = await response.json(); if (!response.ok) throw new Error(result.message); return result;
  }, { route, body });
}
async function session(browser, user, password) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1050 } });
  const page = await context.newPage(); page.on('pageerror', e => errors.push(e.message));
  await page.coverage.startJSCoverage({ resetOnNavigation: false });
  const go = page.goto.bind(page);
  page.goto = async (...args) => { coverage.push(...await page.coverage.stopJSCoverage()); await page.coverage.startJSCoverage({ resetOnNavigation: false }); return go(...args); };
  await page.goto(base + '/wp-login.php'); await page.locator('#user_login').fill(user); await page.locator('#user_pass').fill(password);
  await Promise.all([page.waitForNavigation(), page.locator('#wp-submit').click()]); await page.goto(base + '/intranet/'); await page.locator('.hero').waitFor();
  return page;
}
async function go(page, route) { await page.goto(base + '/' + route + '/'); await page.locator('#page-content h1').waitFor(); }
async function create(page, type, extra = {}) { const p = await request(page, 'content/' + type, { title: 'QZ Aceptación ' + type, body: 'Gobernanza corporativa y responsabilidades del directorio.', status: 'publish', ...extra }); ids.push(p.id); return p; }
async function runJobs() {
  execFileSync('docker', ['compose', 'exec', '-T', 'wordpress', 'php'], { cwd: root, input: '<?php require "/var/www/html/wp-load.php"; if(wp_get_environment_type()!=="local")exit(2); for($i=0;$i<30;$i++){if(!ASCLA\\Core\\Repositories\\Store::count("jobs","status=\'pending\'",[]))break;ASCLA\\Core\\Jobs\\Queue::run();}', stdio: ['pipe', 'pipe', 'pipe'] });
}
(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  let admin, member, settings;
  try {
    admin = await session(browser, 'ascla.admin', env.ASCLA_ADMIN_PASSWORD); member = await session(browser, 'demo.asociado', env.ASCLA_DEMO_PASSWORD);
    settings = await request(admin, 'settings'); await request(admin, 'settings', { ai_mode: 'mock', youtube_mode: 'mock' });
    const boot = await request(member, 'bootstrap'), catalogs = boot.catalogs;
    await go(member, 'directorio');
    await member.locator('select[name=interests]').selectOption(String(catalogs.interest[0].id));
    await member.locator('select[name=areas]').selectOption(String(catalogs.area[0].id));
    await Promise.all([member.waitForResponse(r => r.url().includes('/profiles?')), member.locator('form[data-form=filters] button').click()]);
    checks.push('Directory interest and expertise selectors work together');

    const imageBuffer = fs.readFileSync(path.join(root, 'wp-content/plugins/ascla-core/assets/ascla-logo.png'));
    const upload = await admin.request.post(await admin.evaluate(() => ASCLA.api + 'media'), { headers: { 'X-WP-Nonce': await admin.evaluate(() => ASCLA.nonce) }, multipart: { file: { name: 'ascla-test.png', mimeType: 'image/png', buffer: imageBuffer } } });
    assert.equal(upload.ok(), true); const file = await upload.json(); media.push(file.id);
    const resource = await create(admin, 'resource', { meta: { resource_type: 'Video', youtube_url: 'https://youtu.be/abcdefghijk', source: 'Fuente QZCompleta' }, interest: [catalogs.interest[0].id], category: [catalogs.category[0].id], tag_names: ['qz-completa'] });
    const forum = await create(admin, 'forum'), topic = await create(admin, 'topic', { parent: forum.id, title: 'Tema QZ del foro', category: [catalogs.category[0].id] });
    await go(member, 'foros'); await member.locator('.advanced-filters summary').click(); await member.locator('select[name=parent]').selectOption(String(forum.id));
    await Promise.all([member.waitForResponse(r => r.url().includes('/content/topic?')), member.locator('form[data-form=filters] button').click()]);
    await member.getByRole('link', { name: topic.title, exact: true }).waitFor(); checks.push('Forum navigation and category selectors');

    await go(member, 'centro-conocimiento'); await member.locator('.advanced-filters summary').click();
    await member.locator('select[name=author]').selectOption(String(resource.author.id)); await member.locator('select[name=category]').selectOption(String(catalogs.category[0].id));
    await member.locator('select[name=tag]').selectOption(String(catalogs.interest[0].id));
    const keyword = resource.tags.find(t => t.taxonomy === 'ascla_tag'); await member.locator('select[name=keyword]').selectOption(String(keyword.id));
    await member.locator('[name=q]').last().fill('Fuente QZCompleta');
    await Promise.all([member.waitForResponse(r => r.url().includes('/content/resource?')), member.locator('form[data-form=filters] button').click()]);
    await member.getByRole('heading', { name: resource.title, exact: true }).waitFor();
    await member.screenshot({ path: path.join(root, 'test-results/completion-knowledge.png'), fullPage: true }); checks.push('Resource author, source, topic, category and keyword filters combined');

    const hub = await create(admin, 'hub', { meta: { media_ids: [file.id] } });
    await go(member, 'hub'); await member.locator('.feed-images img').first().waitFor(); assert.equal(await member.locator('.feed-images img').first().evaluate(async el => { await el.decode(); return el.naturalWidth > 0; }), true);
    checks.push('Private images appear directly in Hub feed');
    const upload2 = await admin.request.post(await admin.evaluate(() => ASCLA.api + 'media'), { headers: { 'X-WP-Nonce': await admin.evaluate(() => ASCLA.nonce) }, multipart: { file: { name: 'ally-test.png', mimeType: 'image/png', buffer: imageBuffer } } });
    const logo = await upload2.json(); media.push(logo.id); await create(admin, 'ally', { meta: { media_ids: [logo.id] } });
    await go(member, 'aliados'); await member.locator('.ally-logo').first().waitFor(); checks.push('Ally card displays its uploaded logo');

    const start = new Date(Date.now() + 86400000), end = new Date(start.getTime() + 3600000);
    const event = await create(admin, 'event', { meta: { start: start.toISOString(), end: end.toISOString(), capacity: 5 } });
    await go(admin, 'eventos'); await admin.goto(event.url); await admin.locator('[data-action=event-invite]').click();
    await admin.locator('input[aria-label="Buscar invitados"]').fill(boot.me.name);
    await Promise.all([admin.waitForResponse(r => r.url().includes('/profiles?')), admin.locator('[data-form=invite-search] button').click()]);
    await admin.locator(`[data-invite-member="${boot.me.id}"]`).check();
    await Promise.all([admin.waitForResponse(r => r.url().includes('/invite') && r.request().method() === 'POST'), admin.locator('[data-form=event-invite] button:not([type])').click()]);
    await member.goto(event.url); await member.locator('.modal .status.invited').waitFor();
    assert.equal((await request(member, 'events/' + event.id)).registered, 'invited'); checks.push('Moderator sends internal invitation and member receives invited state');
    await go(member, 'intranet'); await member.locator('.calendar-day').first().click(); await member.locator('.modal .event-mini').first().waitFor();
    await member.locator('.modal [data-action=close]').click(); await member.locator('[data-action=calendar-next]').click();
    await member.locator('[data-action=calendar-prev]').click(); checks.push('Monthly agenda and day details remain navigable');

    await admin.goto(resource.url); await admin.locator('[data-action=video-metadata]').click(); await admin.locator('#video-job-result').waitFor(); await runJobs();
    await admin.locator('.video-metadata').filter({ hasText: '3 min 30 s' }).waitFor({ timeout: 60000 });
    await admin.locator('.modal [data-action=editor]').click(); await admin.locator('[name=duration_seconds]').fill('215'); await admin.locator('[name=tag_names]').fill('qz-completa, qz-revisada');
    await Promise.all([admin.waitForResponse(r => r.url().includes('/content/resource/') && r.request().method() === 'POST'), admin.locator('[data-form=editor] button:not([type])').click()]);
    await admin.goto(resource.url); await Promise.all([admin.waitForResponse(r => r.url().endsWith('/jobs') && r.request().method() === 'POST'), admin.locator('[data-action=generate]').click()]); await runJobs();
    const generated = await request(admin, 'content/resource?mine=1'); const note = generated.items.find(p => p.meta.source_id === resource.id && p.meta.resource_type === 'Nota técnica');
    assert.ok(note); ids.push(...generated.items.filter(p => p.meta.source_id === resource.id).map(p => p.id));
    const hubItems = await request(admin, 'content/hub?mine=1'); ids.push(...hubItems.items.filter(p => p.meta.source_id === note.id).map(p => p.id));
    await admin.goto(note.url); await admin.locator('.generated-results').waitFor();
    for (const detail of await admin.locator('.generated-section').all()) { await detail.locator('summary').click(); }
    assert.equal(await admin.locator('.modal').innerText().then(text => text.includes('Invalid Date')), false);
    for (const detail of await admin.locator('.generated-section').all()) { if (!(await detail.locator('summary').innerText()).includes('Fragmentos')) await detail.locator('summary').click(); }
    await admin.locator('.generated-results').scrollIntoViewIfNeeded();
    await admin.screenshot({ path: path.join(root, 'test-results/completion-generated.png') }); checks.push('Video metadata, editable keywords and structured generated results are visible');

    for (const route of ['directorio', 'centro-conocimiento', 'eventos', 'hub']) {
      await member.setViewportSize({ width: 390, height: 844 }); await go(member, route);
      assert.equal(await member.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    }
    checks.push('New controls fit mobile viewport'); assert.deepEqual(errors, []);
    fs.writeFileSync(path.join(root, 'test-results/completion.json'), JSON.stringify({ version: fs.readFileSync(path.join(root, 'wp-content/plugins/ascla-core/ascla-core.php'), 'utf8').match(/Version: ([\d.]+)/)[1], date: new Date().toISOString(), passed: true, checks, jsErrors: errors }, null, 2));
    console.log(JSON.stringify({ passed: true, checks, jsErrors: errors }, null, 2));
  } catch (error) {
    console.error(JSON.stringify({errors, checks, admin: admin?.url(), member: member?.url()}));
    for (const [name, page] of [['admin', admin], ['member', member]]) if(page) {
      console.error(name, await page.locator('body').innerText());
      await page.screenshot({path:path.join(root, `test-results/completion-failure-${name}.png`), fullPage:true});
    }
    throw error;
  } finally {
    if (admin && settings) await request(admin, 'settings', settings).catch(() => {});
    for (const p of [admin, member]) if (p) coverage.push(...await p.coverage.stopJSCoverage());
    fs.writeFileSync(path.join(root, 'coverage/completion-v8.json'), JSON.stringify(coverage.filter(c => c.url.includes('/ascla-core/assets/'))));
    await browser.close();
    const code = `<?php require '/var/www/html/wp-load.php'; if(wp_get_environment_type()!=='local')exit(2); foreach(${JSON.stringify([...new Set(ids)])} as $id){wp_delete_post($id,true);ASCLA\\Core\\Repositories\\Store::delete('registrations',['event_id'=>$id]);foreach(['event','resource'] as $kind)ASCLA\\Core\\Repositories\\Store::delete('notifications',['event_key'=>$kind.':'.$id]);} foreach(${JSON.stringify(media)} as $id)ASCLA\\Core\\Repositories\\Store::delete('media',['id'=>$id]);`;
    execFileSync('docker', ['compose', 'exec', '-T', 'wordpress', 'php'], { cwd: root, input: code, stdio: ['pipe', 'pipe', 'pipe'] });
  }
})().catch(error => { console.error(error); process.exit(1); });
