/* Focused nonce policy and automatic YouTube fallback checks; no coverage instrumentation. */
const {chromium} = require("playwright");
const fs = require("node:fs");
const assert = require("node:assert/strict");
const {CspParser} = require("csp_evaluator/dist/parser.js");
const {CspEvaluator} = require("csp_evaluator/dist/evaluator.js");
const {Severity, Type} = require("csp_evaluator/dist/finding.js");
if (process.env.ASCLA_E2E_EPHEMERAL !== "1") throw Error("Owned disposable local QA required");
const out = process.env.ASCLA_SECURITY_OUT;
const fixture = JSON.parse(fs.readFileSync(out + "/private-actors.json"));
const base = fixture.base, fixtures = [];
if (!/^https:\/\/ascla-zap109-[a-z0-9-]+\.test$/.test(base)) throw Error("Owned local TLS host required");
const result = {checks: [], findings: [], unexpected_csp: [], errors: []};
const test = async (name, run) => { await run(); result.checks.push(name); console.log("PASS " + name); };
const request = (page, path, body) => page.evaluate(async ({path, body}) => {
  const response = await fetch(ASCLA.api + path, {method: body ? "POST" : "GET", credentials: "same-origin",
    headers: {"Content-Type": "application/json", "X-WP-Nonce": ASCLA.nonce},
    body: body ? JSON.stringify(body) : undefined});
  return {status: response.status, body: await response.json()};
}, {path, body});
(async () => {
  let browser;
  try {
    browser = await chromium.launch({headless: true});
    const context = await browser.newContext({ignoreHTTPSErrors: true});
    const response = await context.request.get(base + "/login/");
    const policy = response.headers()["content-security-policy"];
    const nonce = policy.match(/nonce-([^']+)/)[1];
    await test("CSP Evaluator finds no script allowlist or dangerous policy findings", async () => {
      result.findings = new CspEvaluator(new CspParser(policy).csp).evaluate();
      const dangerous = new Set([Severity.HIGH, Severity.SYNTAX, Severity.MEDIUM, Severity.HIGH_MAYBE, Severity.MEDIUM_MAYBE]);
      assert.deepEqual(result.findings.filter(f => dangerous.has(f.severity) || f.type === Type.SCRIPT_ALLOWLIST_BYPASS), []);
      assert.equal(policy.match(/(?:^|;)\s*script-src\s+([^;]+)/)[1], "'nonce-" + nonce + "' 'strict-dynamic'");
    });
    await test("Parser-inserted YouTube, same-origin and inline scripts without nonce are blocked", async () => {
      const page = await context.newPage(), blocked = [], fetched = [];
      await page.exposeFunction("recordBlockedScript", uri => blocked.push(uri));
      await page.addInitScript(() => document.addEventListener("securitypolicyviolation", e => {
        if (e.disposition === "enforce") window.recordBlockedScript(e.blockedURI);
      }));
      const injectedURL = "https://www.youtube.com/oembed?callback=cspProbe";
      const localURL = base + "/uploads/csp-probe.js";
      for (const url of [injectedURL, localURL]) await page.route(url, route => {
        fetched.push(url); return route.fulfill({contentType: "application/javascript", body: "window.untrustedRan=true;"});
      });
      await page.route(base + "/csp-probe", route => route.fulfill({
        contentType: "text/html", headers: {"Content-Security-Policy": policy},
        body: '<!doctype html><title>CSP probe</title><script nonce="' + nonce + '">window.trustedRan=true;</script>' +
          '<script src="' + injectedURL + '"></script><script src="' + localURL + '"></script><script>window.untrustedRan=true;</script>',
      }));
      await page.goto(base + "/csp-probe");
      await page.waitForFunction(() => window.trustedRan === true);
      await page.waitForTimeout(150);
      assert.equal(await page.evaluate(() => Boolean(window.untrustedRan)), false);
      assert.deepEqual(fetched, []);
      for (const uri of [injectedURL, localURL, "inline"]) assert.ok(blocked.some(value => value === uri || value.split("?")[0] === uri.split("?")[0]), uri);
      await page.close();
    });
    const admin = await context.newPage();
    await admin.exposeFunction("unexpectedCsp", uri => result.unexpected_csp.push(uri));
    await admin.addInitScript(() => document.addEventListener("securitypolicyviolation", e => {
      if (e.disposition === "enforce") window.unexpectedCsp(e.effectiveDirective + " " + e.blockedURI);
    }));
    admin.on("pageerror", error => result.errors.push(error.message));
    const actor = fixture.actors.Administrador;
    await admin.goto(base + "/login/");
    await admin.locator("#user_login").fill(actor.login);
    await admin.locator("#user_pass").fill(actor.password);
    await Promise.all([admin.waitForNavigation({waitUntil: "domcontentloaded"}), admin.locator("#wp-submit").click()]);
    await admin.locator("#ascla-root").waitFor();
    await require("./video-metadata.cjs")({test, admin, request, base, fixtures});
    assert.deepEqual(result.errors, []);
    assert.deepEqual(result.unexpected_csp, []);
    result.status = "PASS";
  } catch (error) {
    result.status = "FAIL"; result.errors.push(error.message); console.error(error.stack); process.exitCode = 1;
  } finally {
    if (browser) await browser.close();
    fs.writeFileSync(out + "/youtube-csp.json", JSON.stringify(result, null, 2) + "\n");
  }
})();
