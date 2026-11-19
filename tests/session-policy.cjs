const fs = require("node:fs");
const path = require("node:path");
const assert = require("node:assert/strict");

module.exports = async ({browser, root, base, test, coverage}) => {
  await test("Session warning, explicit extension and expired session redirect", async () => {
    const context = await browser.newContext();
    const page = await context.newPage();
    await require("./helpers/browser.cjs").startCoverage(page, coverage);
    let remaining = 90, expired = false, renewals = 0, polls = 0;
    try {
      await page.route(base + "/wp-content/plugins/ascla-core/assets/session.js", route => route.fulfill({contentType:"text/javascript", body:fs.readFileSync(path.join(root, "wp-content/plugins/ascla-core/assets/session.js"), "utf8")}));
      await page.route(base + "/session-policy-test", route => route.fulfill({contentType:"text/html", body:"<!doctype html><meta charset=\"utf-8\"><title>Session policy test</title><body></body>"}));
      await page.route(base + "/session-login-test", route => route.fulfill({contentType:"text/html", body:"<!doctype html><meta charset=\"utf-8\"><title>Login required</title>"}));
      await page.route(base + "/session-api/account/session", route => {
        if (expired) return route.fulfill({status:401, json:{message:"Expired"}});
        if (route.request().method() === "POST") { renewals++; remaining = 1800; }
        else polls++;
        return route.fulfill({json:{remaining, idle_seconds:1800}});
      });
      await page.goto(base + "/session-policy-test");
      await page.clock.install();
      await page.evaluate(base => { globalThis.ASCLA = {api:base + "/session-api/", nonce:"local-test", login:base + "/session-login-test"}; }, base);
      await Promise.all([page.waitForResponse(base + "/session-api/account/session"), page.addScriptTag({url:base + "/wp-content/plugins/ascla-core/assets/session.js"})]);
      await page.clock.fastForward(10000);
      await page.getByRole("status").waitFor();
      assert.equal(renewals, 0, "Reading session state cannot extend inactivity");
      await page.getByRole("button", {name:"Continuar mi sesión"}).click();
      await page.getByRole("status").waitFor({state:"detached"});
      assert.equal(renewals, 1);
      await Promise.all([page.waitForResponse(base + "/session-api/account/session"), page.clock.fastForward(60000)]);
      assert.equal(renewals, 1, "Background polls cannot extend inactivity");
      assert.ok(polls >= 2);
      expired = true;
      await page.clock.fastForward(60000);
      await page.waitForURL(base + "/session-login-test");
      assert.equal(await page.title(), "Login required");
    } finally { await require("./helpers/browser.cjs").stopCoverage(page, coverage); await context.close(); }
  });
};
