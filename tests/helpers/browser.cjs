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
module.exports = {startCoverage, stopCoverage};
