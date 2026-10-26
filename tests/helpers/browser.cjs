async function startCoverage(page, entries) {
  await page.coverage.startJSCoverage({resetOnNavigation: false});
  for (const method of ["goto", "reload"]) {
    const original = page[method].bind(page);
    page[method] = async (...args) => {
      entries.push(...await page.coverage.stopJSCoverage());
      await page.coverage.startJSCoverage({resetOnNavigation: false});
      if (method === "goto") args[1] = {waitUntil: "domcontentloaded", ...args[1]};
      else args[0] = {waitUntil: "domcontentloaded", ...args[0]};
      return original(...args);
    };
  }
}
async function stopCoverage(page, entries) {
  if (!page || page.isClosed()) return;
  entries.push(...await page.coverage.stopJSCoverage());
}
async function waitForHome(page) {
  await page.locator('#page-content h1').waitFor();
  const state = await page.evaluate(async () => {
    const response = await fetch(ASCLA.api + 'bootstrap', {headers: {'X-WP-Nonce': ASCLA.nonce}});
    if (!response.ok) throw Error('Home bootstrap failed: ' + response.status);
    return {page: ASCLA.page, administrative: Boolean((await response.json()).admin)};
  });
  require('node:assert/strict').equal(state.page, state.administrative ? 'admin' : 'intranet');
  await page.locator(state.administrative ? '.admin-workspace #admin-panel' : '.hero').waitFor();
}
async function waitForNativeLogin(page) {
  await page.waitForLoadState('load');
  // WordPress schedules wp_attempt_focus() after 200 ms. HTML autofocus can
  // happen earlier, so focus alone does not prove that its callback has run.
  await page.waitForFunction(() => {
    const navigation=performance.getEntriesByType('navigation')[0];
    return navigation?.domContentLoadedEventEnd>0 && performance.now()-navigation.domContentLoadedEventEnd>=250;
  });
}
module.exports = {startCoverage, stopCoverage, waitForHome, waitForNativeLogin};
