/* Focused authentication regression. Local WordPress only; credentials never enter reports. */
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const base = 'http://localhost:8088';
const env = Object.fromEntries(fs.readFileSync(path.join(root, '.env'), 'utf8').split(/\r?\n/).filter(x => x.includes('=')).map(x => [x.slice(0, x.indexOf('=')), x.slice(x.indexOf('=') + 1)]));

(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  const errors = [], checks = [];
  const evidence = path.join(root, 'docs/evidence');
  page.on('pageerror', error => errors.push(error.message));
  try {
    await page.goto(base + '/aliados/');
    assert.match(page.url(), /wp-login\.php/);
    assert.equal(await page.title(), 'Entrar a la comunidad · ASCLA');
    assert.equal(await page.locator('#wp-submit').inputValue(), 'Entrar a la comunidad');
    assert.equal(await page.locator('label[for=user_pass]').innerText(), 'Contraseña');
    await page.screenshot({ path: path.join(evidence, 'login-desktop.png'), fullPage: true });
    checks.push('Branded Spanish login protects a private page');

    for (const width of [390, 320, 768]) {
      await page.setViewportSize({ width, height: 844 });
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
      assert.equal(await page.locator('#wp-submit').isVisible(), true);
      if (width === 390) await page.screenshot({ path: path.join(evidence, 'login-mobile.png'), fullPage: true });
    }
    checks.push('Mobile and tablet layout at 320, 390 and 768 px');
    await page.setViewportSize({ width: 1440, height: 1000 });

    await page.locator('#user_login').fill('demo.asociado');
    await page.locator('#user_pass').fill('deliberately-incorrect-ascla-test');
    await page.locator('.wp-hide-pw').click();
    assert.equal(await page.locator('#user_pass').getAttribute('type'), 'text');
    await page.locator('.wp-hide-pw').click();
    assert.equal(await page.locator('#user_pass').getAttribute('type'), 'password');
    checks.push('Native password visibility control');
    await Promise.all([page.waitForNavigation(), page.locator('#wp-submit').click()]);
    assert.match(await page.locator('#login_error').innerText(), /incorrecta/);
    checks.push('Incorrect password rejected with visible Spanish error');

    // WordPress clears and focuses the failed password field after its 200 ms autofocus timer.
    await page.waitForFunction(() => document.activeElement?.id === 'user_pass');
    await page.locator('#user_pass').fill(env.ASCLA_DEMO_PASSWORD);
    await page.locator('#rememberme').check();
    await Promise.all([page.waitForURL(base + '/aliados/'), page.locator('#wp-submit').click()]);
    await page.locator('#page-content h1').waitFor();
    checks.push('Successful member login preserves requested section');
    await page.goto(await page.evaluate(() => ASCLA.logout));
    assert.match(page.url(), /wp-login\.php/);
    await page.goto(base + '/aliados/');
    assert.match(page.url(), /wp-login\.php/);
    checks.push('Logout returns to branded access and removes private access');

    await page.locator('#nav a').filter({ hasText: '¿Olvidaste tu contraseña?' }).click();
    assert.equal(await page.locator('.ascla-login-intro h2').innerText(), 'Recupera tu acceso');
    assert.equal(await page.locator('#lostpasswordform').getAttribute('method'), 'post');
    assert.equal(await page.locator('#wp-submit').inputValue(), 'Enviar enlace de recuperación');
    await page.screenshot({ path: path.join(evidence, 'login-recovery.png'), fullPage: true });
    checks.push('Native password recovery form retains branding (no email sent)');

    await page.goto(base + '/wp-login.php?interim-login=1');
    assert.equal(await page.locator('.ascla-welcome').isVisible(), false);
    assert.equal(await page.locator('#loginform').isVisible(), true);
    checks.push('Native session-expiry dialog remains usable');

    await page.goto(base + '/wp-login.php?redirect_to=' + encodeURIComponent('https://example.invalid/aliados/'));
    await page.locator('#user_login').fill('demo.asociado');
    await page.locator('#user_pass').fill(env.ASCLA_DEMO_PASSWORD);
    await Promise.all([page.waitForURL(base + '/intranet/'), page.locator('#wp-submit').click()]);
    checks.push('External redirect rejected in real login');
    assert.deepEqual(errors, []);
    checks.push('No browser JavaScript errors');
    const report = { version: fs.readFileSync(path.join(root, 'wp-content/plugins/ascla-core/ascla-core.php'), 'utf8').match(/Version: ([\d.]+)/)[1], checkedAt: new Date().toISOString(), status: 'passed', checks, jsErrors: errors };
    fs.writeFileSync(path.join(evidence, 'login.json'), JSON.stringify(report, null, 2) + '\n');
    console.log(JSON.stringify(report, null, 2));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
