/* Local browser acceptance. Fixtures are isolated from demo and member data. */
const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..'), base = 'http://localhost:8088';
const checks = [], errors = [];
function fixture(...args) {
  return execFileSync('docker', ['compose', 'exec', '-T', 'wordpress', 'php', '/opt/ascla-tests/notifications-fixture.php', ...args], { cwd: root, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] });
}
async function api(page, route, body) {
  return page.evaluate(async ({ route, body }) => {
    const r = await fetch(ASCLA.api + route, { method: body ? 'POST' : 'GET', headers: { 'X-WP-Nonce': ASCLA.nonce, 'Content-Type': 'application/json' }, ...(body ? { body: JSON.stringify(body) } : {}) });
    const result = await r.json(); if (!r.ok) throw new Error(result.message); return result;
  }, { route, body });
}
(async () => {
  const data = JSON.parse(fixture()), browser = await chromium.launch({ headless: true, channel: 'msedge' });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1080 } });
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(base + '/wp-login.php');
    await page.locator('#user_login').fill(data.login); await page.locator('#user_pass').fill(data.password);
    await Promise.all([page.waitForNavigation(), page.locator('#wp-submit').click()]);
    async function inbox() {
      await page.goto(base + '/perfil/'); await page.locator('[data-action=notifications]').click(); await page.locator('.activity-card').first().waitFor();
    }
    await inbox();
    await page.getByText('Diego Salazar reaccionó a tu publicación', { exact: true }).waitFor();
    assert.equal(await page.locator('.activity-link').count(), data.notices.length);
    await page.screenshot({ path: path.join(root, 'test-results/notifications-desktop.png'), fullPage: false });
    checks.push('Contextual titles, subjects, categories, time and actions displayed');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: path.join(root, 'test-results/notifications-mobile.png'), fullPage: false });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    assert.equal(await page.locator('.notification-modal').evaluate(e => e.scrollWidth > e.clientWidth), false);
    checks.push('Mobile inbox fits a 390px viewport without horizontal overflow');
    await page.setViewportSize({ width: 1440, height: 1080 });
    const reaction = data.notices.find(n => n.kind === 'reaction');
    await Promise.all([page.waitForURL(u => u.searchParams.get('item') === String(data.posts[0])), page.locator(`[data-action=notification-open][data-id="${reaction.id}"]`).click()]);
    await page.locator('.modal').getByText('Cómo preparar una junta con mejores decisiones', { exact: true }).waitFor();
    assert.equal((await api(page, 'notifications/feed')).unread_total, data.notices.length - 1);
    checks.push('Click reaction opens its publication and marks it read');
    console.log('Verified:', checks.at(-1));
    await inbox();
    const message = data.notices.find(n => n.kind === 'message');
    await Promise.all([page.waitForURL(u => u.searchParams.has('conversation')), page.locator(`[data-action=notification-open][data-id="${message.id}"]`).click()]);
    await page.locator('#chat-messages .bubble').filter({ hasText: 'Hola Elena, conversemos sobre el seguimiento de acuerdos de la junta.' }).waitFor();
    checks.push('Click message opens the correct private conversation');
    console.log('Verified:', checks.at(-1));
    await inbox();
    const answer = data.notices.find(n => n.kind === 'job' && n.url.includes('job='));
    await Promise.all([page.waitForURL(u => u.searchParams.has('job')), page.locator(`[data-action=notification-open][data-id="${answer.id}"]`).click()]);
    await page.locator('.answer-card').getByText('¿Cómo mejorar el seguimiento de acuerdos?', { exact: true }).waitFor();
    await page.locator('.answer-card .sources a').waitFor();
    checks.push('Click assistant notice restores the saved question, response and source');
    console.log('Verified:', checks.at(-1));
    for (const kind of ['event', 'resource']) {
      await inbox(); const notice = data.notices.find(n => n.kind === kind);
      await Promise.all([page.waitForURL(u => u.searchParams.has('item')), page.locator(`[data-action=notification-open][data-id="${notice.id}"]`).click()]);
      await page.locator('.modal').getByText(notice.description, { exact: true }).waitFor();
    }
    checks.push('Event invitations and resource notices open their specific details');
    await inbox();
    const profile = data.notices.find(n => n.kind === 'connection');
    await Promise.all([page.waitForURL(u => u.searchParams.has('member')), page.locator(`[data-action=notification-open][data-id="${profile.id}"]`).click()]);
    await page.locator('.modal').getByText('Diego Salazar', { exact: true }).waitFor();
    checks.push('Click connection opens the member profile');
    console.log('Verified:', checks.at(-1));
    await inbox();
    const legacy = data.notices.find(n => n.kind === 'job' && n.url.includes('history='));
    await Promise.all([page.waitForURL(u => u.searchParams.has('history')), page.locator(`[data-action=notification-open][data-id="${legacy.id}"]`).click()]);
    await page.getByRole('dialog', { name: 'Mis consultas anteriores' }).waitFor();
    await page.locator('.answer-history-entry').getByText('¿Cómo mejorar el seguimiento de acuerdos?', { exact: true }).waitFor();
    checks.push('Legacy job notice offers an actionable personal history');
    await inbox();
    await page.locator('[data-action=notification-filter][data-filter=unread]').click();
    await page.locator('[data-filter=unread][aria-pressed=true]').waitFor();
    const before = (await api(page, 'notifications/summary')).unread_total;
    await page.locator('[data-action=notification-read]').first().click();
    await page.waitForFunction(expected => document.querySelector('.activity-filters [data-filter=unread]').textContent === `Sin leer (${expected})`, before - 1);
    await page.locator('[data-action=notifications-read-all]').click();
    await page.getByRole('heading', { name: 'Estás al día', exact: true }).waitFor();
    assert.equal((await api(page, 'notifications/summary')).unread_total, 0);
    assert.equal(await page.locator('.notification-count').isVisible(), false);
    await page.locator('[data-filter=all]').click(); await page.locator('.activity-card.is-read').first().waitFor();
    assert.equal(await page.locator('.activity-card.is-read').count(), data.notices.length);
    checks.push('Unread filter, individual read, read all and badge remain synchronized');
    await page.keyboard.press('Escape'); assert.equal(await page.locator('.notification-modal').count(), 0);
    assert.equal(await page.locator('[data-action=notifications]').evaluate(e => e === document.activeElement), true);
    checks.push('Escape closes inbox and restores keyboard focus to the bell');
    assert.deepEqual(errors, []);
    fs.writeFileSync(path.join(root, 'test-results/notifications.json'), JSON.stringify({ version: '1.2.0', date: new Date().toISOString(), checks, browserErrors: errors }, null, 2));
    console.log(JSON.stringify({ checks, browserErrors: errors }, null, 2));
  } finally {
    await browser.close(); fixture('cleanup', JSON.stringify({ posts: data.posts, users: data.users, conversation: data.conversation }));
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
