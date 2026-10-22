const assert = require("node:assert/strict");
module.exports = async function videoScenarios({test, admin, request, base, fixtures}) {
  let mode = "success", durationWrites = 0, multimediaJobs = 0;
  const jobs = async route => {
    if (route.request().method() === "POST" && route.request().postDataJSON()?.kind === "multimedia") {
      multimediaJobs++;
      return route.fulfill({json: {id: 990000001}});
    }
    return route.continue();
  };
  const jobResult = route => route.fulfill({json: {status: "error", error: "Metadata <b>unavailable</b>"}});
  const loader = async route => {
    if (mode === "network-error") return route.abort();
    return route.fulfill({contentType: "application/javascript", body: [
      "window.YT={Player:function(host,config){",
      "this.getDuration=()=>125.6;this.mute=()=>{};this.cueVideoById=()=>{};",
      "this.destroy=()=>{window.__ytDestroyed=(window.__ytDestroyed||0)+1;};",
      "setTimeout(()=>window.__ytPlayerError?config.events.onError():config.events.onReady({target:this}),10);",
      "}};window.onYouTubeIframeAPIReady();",
    ].join("")});
  };
  const onRequest = req => { if (req.method() === "POST" && req.url().includes("/video-duration")) durationWrites++; };
  const resource = async () => {
    const response = await request(admin, "content/resource", {
      title: "E2E automatic YouTube metadata", body: "Prueba local del reproductor", status: "publish",
      meta: {resource_type: "Video", youtube_url: "https://www.youtube.com/watch?v=abcdefghijk"},
    });
    assert.equal(response.status, 200, JSON.stringify(response.body));
    fixtures.push(response.body.id);
    await admin.goto(base + "/centro-conocimiento/?item=" + response.body.id);
    await admin.locator('.modal [data-action="generate"]').waitFor();
    assert.equal(await admin.locator('[data-action="video-metadata"]').count(), 0);
    assert.equal(await admin.getByText("Actualizar datos de YouTube", {exact: true}).count(), 0);
    return response.body.id;
  };
  const generate = async () => {
    await admin.locator('.modal [data-action="generate"]').click();
    await admin.locator("#job-result .error").waitFor();
  };
  admin.on("request", onRequest);
  await admin.route("https://www.youtube-nocookie.com/**", route => route.abort());
  await admin.route("https://www.youtube.com/iframe_api", loader);
  await admin.route("**/ascla/v1/jobs", jobs);
  await admin.route("**/ascla/v1/jobs/990000001", jobResult);
  try {
    await test("YouTube refresh button is absent and processing automatically stores measured duration", async () => {
      const id = await resource(); await generate();
      const saved = await request(admin, "items/" + id);
      assert.equal(saved.body.meta.duration_seconds, 126);
      assert.equal(durationWrites, 1); assert.equal(multimediaJobs, 1);
      assert.equal(await admin.evaluate(() => window.__ytDestroyed), 1);
      assert.equal(await admin.locator('script[data-ascla-youtube-api]').getAttribute("src"), "https://www.youtube.com/iframe_api");
      assert.ok(await admin.locator('script[data-ascla-youtube-api]').evaluate(script => Boolean(script.nonce)));
    });
    await test("Failed automatic player preserves duration and still submits processing without unsafe error HTML", async () => {
      await resource(); await admin.evaluate(() => { window.__ytPlayerError = true; }); await generate();
      const error = admin.locator("#job-result .error");
      assert.match(await error.innerText(), /Metadata <b>unavailable<\/b>/);
      assert.equal(await error.locator("b").count(), 0);
      assert.equal(durationWrites, 1); assert.equal(multimediaJobs, 2);
      assert.equal(await admin.evaluate(() => window.__ytDestroyed), 1);
    });
    await test("A failed automatic YouTube loader can retry on the same page", async () => {
      const id = await resource(); mode = "network-error"; await generate();
      assert.equal(await admin.locator('script[data-ascla-youtube-api]').count(), 0);
      mode = "success"; await admin.locator('.modal-top [data-action="close"]').click();
      await admin.locator('[data-action="item"][data-id="' + id + '"]').first().click(); await generate();
      assert.equal(durationWrites, 2); assert.equal(multimediaJobs, 4);
    });
  } finally {
    admin.off("request", onRequest);
    await admin.unroute("**/ascla/v1/jobs", jobs);
    await admin.unroute("**/ascla/v1/jobs/990000001", jobResult);
    await admin.unroute("https://www.youtube.com/iframe_api", loader);
    await admin.unroute("https://www.youtube-nocookie.com/**");
  }
};
