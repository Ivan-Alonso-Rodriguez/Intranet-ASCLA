const assert = require("node:assert/strict");
/* The player/manual-generation flow was replaced by transcript timing and queued preparation. */
module.exports = async function videoScenarios({test, admin, request, base, fixtures}) {
  const transcript = "00:00:06,160 --> 00:00:10,040\nLa junta supervisa los riesgos.\n\n00:01:58,000 --> 00:02:05,600\nCierre con acuerdos de gobernanza.";
  const resource = async () => {
    const response = await request(admin, "content/resource", {
      title: "E2E transcript duration and preparation", body: "Prueba local del recurso", status: "publish",
      meta: {resource_type: "Video", youtube_url: "https://www.youtube.com/watch?v=abcdefghijk", transcript, summary: "Resumen previo conservado.", chatham: false},
    });
    assert.equal(response.status, 200, JSON.stringify(response.body));
    fixtures.push(response.body.id);
    return response.body;
  };
  const visit = async id => {
    await admin.goto(base + "/centro-conocimiento/?item=" + id);
    await admin.locator('.modal [data-action="resource-regenerate"]').waitFor();
  };
  await admin.route("https://www.youtube-nocookie.com/**", route => route.abort());
  try {
    await test("Transcript timing supplies duration without player metadata or manual preparation buttons", async () => {
      const data = await resource(); await visit(data.id);
      const saved = await request(admin, "items/" + data.id);
      assert.equal(saved.body.meta.duration_seconds, 126);
      assert.equal(await admin.locator('[data-action="generate"],[data-action="transcript"],[data-action="video-metadata"]').count(), 0);
      assert.equal(await admin.locator('script[data-ascla-youtube-api]').count(), 0);
      assert.equal(await admin.getByText("Actualizar datos de YouTube", {exact: true}).count(), 0);
    });
    await test("A simulated preparation error is escaped and does not change the stored duration", async () => {
      const data = await resource();
      const pattern = "**/ascla/v1/items/" + data.id;
      const failed = async route => {
        const response = await route.fetch(), item = await response.json();
        item.meta.video_preparation = {status: "error", job_id: 990000001, error: "Metadata <b>unavailable</b>"};
        return route.fulfill({response, json: item});
      };
      await admin.route(pattern, failed);
      try {
        await visit(data.id);
        const error = admin.locator('[data-video-preparation].error');
        assert.match(await error.innerText(), /Metadata <b>unavailable<\/b>/);
        assert.equal(await error.locator("b").count(), 0);
        assert.equal(await admin.locator('[data-action="resource-regenerate"]').isEnabled(), true);
      } finally { await admin.unroute(pattern, failed); }
      assert.equal((await request(admin, "items/" + data.id)).body.meta.duration_seconds, 126);
    });
    await test("Editorial refresh runs a real queued job and preserves transcript-derived duration", async () => {
      const data = await resource(); await visit(data.id);
      const response = admin.waitForResponse(r => r.request().method() === "POST" && r.url().endsWith("/resources/" + data.id + "/regenerate"));
      await admin.locator('[data-action="resource-regenerate"]').click();
      const queued = await response; assert.equal(queued.status(), 200);
      const job = await queued.json(); assert.ok(job.id > 0);
      let state;
      for (let i=0;i<120;i++) {
        state=(await request(admin,'jobs/'+job.id)).body;
        if (['completed','error'].includes(state.status)) break;
        await new Promise(resolve=>setTimeout(resolve,1000));
      }
      assert.equal(state.status,'completed','Queued preparation: '+JSON.stringify(state));
      await admin.locator('.knowledge-reading-summary').waitFor({timeout: 15000});
      const saved = (await request(admin, "items/" + data.id)).body;
      assert.equal(saved.meta.video_preparation.status, "completed");
      assert.equal(saved.meta.duration_seconds, 126);
      assert.equal(saved.meta.transcript, transcript);
      assert.ok(saved.meta.summary && saved.meta.technical_note && saved.meta.ai_enriched);
      assert.equal((await request(admin, "jobs/" + job.id)).body.status, "completed");
    });
  } finally { await admin.unroute("https://www.youtube-nocookie.com/**"); }
};
