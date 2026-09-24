const assert = require("node:assert/strict");
module.exports = async function videoScenarios({test, admin, request, base, fixtures}) {
  const response = await request(admin, "content/resource", {
    title: "E2E metadata fallback", body: "Prueba local del reproductor",
    status: "publish", meta: {resource_type: "Video", youtube_url: "https://www.youtube.com/watch?v=abcdefghijk"},
  });
  assert.equal(response.status, 200, JSON.stringify(response.body));
  const id = response.body.id;
  fixtures.push(id);
  const itemURL = base + "/centro-conocimiento/?item=" + id;
  let mode = "success", scriptRequests = 0, durationWrites = 0;
  const jobs = async route => {
    const req = route.request();
    if (req.method() === "POST" && req.postDataJSON()?.kind === "video_metadata") {
      return route.fulfill({json: {id: 990000001}});
    }
    return route.continue();
  };
  const errorJob = route => route.fulfill({json: {status: "error", error: "Metadata <b>unavailable</b>"}});
  const loader = async route => {
    scriptRequests++;
    if (mode === "network-error") return route.abort();
    return route.fulfill({contentType: "application/javascript", body: [
      "window.YT={Player:function(host,config){",
      "this.getDuration=()=>125.6;",
      "this.mute=()=>{window.__ytMuted=true;};",
      "this.cueVideoById=id=>{window.__ytVideo=id;};",
      "this.destroy=()=>{window.__ytDestroyed=(window.__ytDestroyed||0)+1;};",
      "setTimeout(()=>window.__ytPlayerError?config.events.onError():config.events.onReady({target:this}),10);",
      "}};window.onYouTubeIframeAPIReady();",
    ].join("")});
  };
  const onRequest = req => { if (req.method() === "POST" && req.url().includes("/video-duration")) durationWrites++; };
  admin.on("request", onRequest);
  await admin.route("https://www.youtube-nocookie.com/**", route => route.abort());
  await admin.route("https://www.youtube.com/iframe_api", loader);
  await admin.route("**/ascla/v1/jobs", jobs);
  await admin.route("**/ascla/v1/jobs/990000001", errorJob);
  const open = async () => {
    await admin.goto(itemURL);
    await admin.locator('.modal [data-action="video-metadata"]').waitFor();
    await admin.locator('.modal [data-action="video-metadata"]').click();
  };
  try {
    await test("YouTube server failure falls back to official loader and saves measured duration", async () => {
      await open();
      await admin.getByText(/Duración verificada directamente desde el video/).waitFor();
      const saved = await request(admin, "items/" + id);
      assert.equal(saved.body.meta.duration_seconds, 126);
      assert.equal(durationWrites, 1);
      assert.equal(scriptRequests, 1);
      assert.deepEqual(await admin.evaluate(() => [window.__ytMuted, window.__ytVideo, window.__ytDestroyed]), [true, "abcdefghijk", 1]);
      assert.equal(await admin.locator('script[data-ascla-youtube-api]').getAttribute("src"), "https://www.youtube.com/iframe_api");
    });
    await test("YouTube player failure preserves the escaped error and removes the hidden player", async () => {
      await admin.goto(itemURL);
      await admin.evaluate(() => { window.__ytPlayerError = true; });
      await admin.locator('.modal [data-action="video-metadata"]').click();
      const error = admin.locator("#video-job-result .error");
      await error.waitFor();
      assert.match(await error.innerText(), /Metadata <b>unavailable<\/b>/);
      assert.match(await error.innerText(), /Tampoco fue posible/);
      assert.equal(await error.locator("b").count(), 0);
      assert.equal(durationWrites, 1, "A failed player must never persist a duration");
      assert.equal(await admin.evaluate(() => window.__ytDestroyed), 1);
    });
    await test("Failed YouTube script can be retried in the same page", async () => {
      mode = "network-error";
      await open();
      await admin.locator("#video-job-result .error").waitFor();
      assert.equal(await admin.locator('script[data-ascla-youtube-api]').count(), 0);
      mode = "success";
      await admin.locator('.modal-top [data-action="close"]').click();
      // Reopen the item without navigating: this exercises the rejected-promise cache.
      await admin.locator('[data-action="item"][data-id="' + id + '"]').first().click();
      await admin.locator('.modal [data-action="video-metadata"]').click();
      await admin.getByText(/Duración verificada directamente desde el video/).waitFor();
      assert.equal(durationWrites, 2);
    });
  } finally {
    admin.off("request", onRequest);
    await admin.unroute("**/ascla/v1/jobs", jobs);
    await admin.unroute("**/ascla/v1/jobs/990000001", errorJob);
    await admin.unroute("https://www.youtube.com/iframe_api", loader);
    await admin.unroute("https://www.youtube-nocookie.com/**");
  }
};
