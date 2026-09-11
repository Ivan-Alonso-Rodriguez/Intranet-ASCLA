/* Real browser journeys against local WordPress only. Credentials stay in .env. */
const { chromium } = require("playwright");
const fs = require("node:fs"),
  path = require("node:path"),
  assert = require("node:assert/strict");
const { execFileSync } = require("node:child_process");
const v8toIstanbul = require("v8-to-istanbul");
const libCoverage = require("istanbul-lib-coverage"),
  libReport = require("istanbul-lib-report"),
  reports = require("istanbul-reports");
const root = path.resolve(__dirname, ".."),
  base = "http://localhost:8088";
const env = Object.fromEntries(
  fs
    .readFileSync(path.join(root, ".env"), "utf8")
    .split(/\r?\n/)
    .filter((x) => x.includes("="))
    .map((x) => [x.slice(0, x.indexOf("=")), x.slice(x.indexOf("=") + 1)]),
);
const results = [],
  fixtures = [],
  messageIds = [],
  mediaIds = [],
  coverage = [];
async function login(browser, name, password) {
  const context = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
  });
  const page = await context.newPage();
  await page.coverage.startJSCoverage({ resetOnNavigation: false });
  // Chromium discards profiler counters with destroyed page contexts. Capture before each navigation.
  for (const method of ['goto','reload']) {
    const original=page[method].bind(page);
    page[method]=async (...args) => {
      coverage.push(...await page.coverage.stopJSCoverage());
      await page.coverage.startJSCoverage({resetOnNavigation:false});
      return original(...args);
    };
  }

  await page.goto(base + "/wp-login.php");
  await page.locator("#user_login").fill(name);
  await page.locator("#user_pass").fill(password);
  await Promise.all([
    page.waitForNavigation(),
    page.locator("#wp-submit").click(),
  ]);
  await page.goto(base + "/intranet/");
  await page.locator(".hero").waitFor();
  return { context, page };
}
async function request(page, route, body, method) {
  return page.evaluate(
    async ({ route, body, method }) => {
      const response = await fetch(ASCLA.api + route, {
        method: method || (body ? "POST" : "GET"),
        credentials: "same-origin",
        headers: {
          "X-WP-Nonce": ASCLA.nonce,
          "Content-Type": "application/json",
        },
        ...(body ? { body: JSON.stringify(body) } : {}),
      });
      return { status: response.status, body: await response.json() };
    },
    { route, body, method },
  );
}
async function test(name, fn) {
  const start = Date.now();
  await fn();
  results.push({ name, status: "passed", ms: Date.now() - start });
  console.log("PASS " + name);
}
async function goto(page, route) {
  const url = base + "/" + route + "/";
  const anchor = page.locator('.ascla-sidebar .nav-link[href="' + url + '"]');
  if (await anchor.count()) {
    const origin = await page.evaluate(() => performance.timeOrigin);
    await anchor.evaluate(link => link.click());
    await page.locator("#page-content h1").waitFor();
    assert.equal(await page.evaluate(() => performance.timeOrigin), origin);
    assert.equal(page.url(), url);
  } else {
    await page.goto(url);
    await page.locator("#page-content h1").waitFor();
  }
}
(async () => {
  fs.mkdirSync(path.join(root, "test-results"), { recursive: true });
  fs.mkdirSync(path.join(root, "coverage"), { recursive: true });
  const browser = await chromium.launch({ headless: true, channel: "msedge" });
  let member, admin, originalProfile, confirmedPair;
  const errors = [];
  let failed = null;
  try {
    member = await login(browser, "demo.asociado", env.ASCLA_DEMO_PASSWORD);
    admin = await login(browser, "ascla.admin", env.ASCLA_ADMIN_PASSWORD);
    const p = member.page,
      a = admin.page;
    p.on("pageerror", (e) => errors.push(e.message));
    a.on("pageerror", (e) => errors.push(e.message));
    await test("Anonymous routes, REST nonce and cache isolation", async () => {
      const guest = await browser.newContext();
      const g = await guest.newPage();
      await g.goto(base + "/intranet/");
      assert.match(g.url(), /wp-login/);
      const response = await g.request.get(base + "/wp-json/ascla/v1/profiles");
      assert.equal(response.status(), 401);
      const unauth = await p.evaluate(async () => {
        const r = await fetch(ASCLA.api + "profiles", {
          credentials: "same-origin",
        });
        return r.status;
      });
      assert.equal(unauth, 401);
      const headers = await p.request.get(base + "/intranet/");
      assert.match(headers.headers()["cache-control"], /no-cache|no-store/);
      await guest.close();
    });
    await test("All member routes load without JavaScript errors", async () => {
      for (const route of [
        "perfil",
        "directorio",
        "hub",
        "foros",
        "eventos",
        "centro-conocimiento",
        "asistente",
        "galeria",
        "aliados",
        "mensajeria",
        "contacto",
      ]) {
        await goto(p, route);
        assert.equal(await p.locator("#page-content .error").count(), 0);
      }
    });
    await test("Member profile edits persist and hidden fields are not searchable", async () => {
      originalProfile = (
        await request(
          p,
          "profiles/" + (await request(p, "bootstrap")).body.me.id,
        )
      ).body;
      await goto(p, "perfil");
      await p
        .locator("[name=bio]")
        .fill("E2E Perfil de prueba para verificar persistencia.");
      await p.locator("[name=company]").fill("E2E_EMPRESA_PRIVADA_457");
      await p.locator("[name=hidden][value=company]").check();
      await p.getByRole("button", { name: "Guardar perfil" }).click();
      await p
        .getByRole("status")
        .filter({ hasText: "Perfil actualizado" })
        .waitFor();
      await p.reload();
      await p.locator("[name=bio]").waitFor();
      assert.match(await p.locator("[name=bio]").inputValue(), /E2E Perfil/);
      const other = await login(
        browser,
        "demo.miembro.3",
        env.ASCLA_DEMO_PASSWORD,
      );
      const found = await request(
        other.page,
        "profiles?q=E2E_EMPRESA_PRIVADA_457",
      );
      assert.equal(found.body.total, 0);
      coverage.push(...(await other.page.coverage.stopJSCoverage()));
      await other.context.close();
      await request(p, "profiles/me", originalProfile);
    });
    let post;
    await test("Hub submission requires review and moderator publishes with reason", async () => {
      await goto(p, "hub");
      await p.getByRole("button", { name: "Nueva publicación" }).click();
      await p
        .locator(".modal [name=title]")
        .fill("E2E Publicación de gobernanza");
      await p
        .locator(".modal [name=body]")
        .fill(
          "E2E Conversación constructiva sobre responsabilidades del directorio.",
        );
      const saved = p.waitForResponse(
        (r) =>
          r.url().includes("/content/hub") && r.request().method() === "POST",
      );
      await p
        .locator(".modal button")
        .filter({ hasText: "Guardar publicación" })
        .click();
      post = await (await saved).json();
      fixtures.push(post.id);
      assert.equal(post.status, "pending");
      const noPerm = await request(p, "items/" + post.id + "/moderate", {
        decision: "approve",
        reason: "No autorizado",
      });
      assert.equal(noPerm.status, 403);
      await a.goto(base + "/wp-admin/admin.php?page=ascla");
      await a.locator("#admin-panel").waitFor();
      await a
        .getByRole("row")
        .filter({ hasText: "E2E Publicación de gobernanza" })
        .getByRole("button", { name: "Revisar" })
        .click();
      await a.getByRole("button", { name: "Moderar", exact: true }).click();
      await a
        .locator(".modal [name=reason]")
        .fill("Contenido revisado y pertinente.");
      await a.getByRole("button", { name: "Guardar decisión" }).click();
      await a
        .getByRole("status")
        .filter({ hasText: "Decisión registrada" })
        .waitFor();
      assert.equal(
        (await request(a, "items/" + post.id)).body.status,
        "publish",
      );
    });
    await test("Comments, reactions, following and reporting work", async () => {
      await p.goto(base + "/hub/?item=" + post.id);
      await p
        .locator(".modal [name=body]")
        .fill("E2E Comentario constructivo.");
      await p.getByRole("button", { name: "Publicar comentario" }).click();
      await p
        .locator("#comments-list")
        .getByText("E2E Comentario constructivo.")
        .waitFor();
      await Promise.all([p.waitForResponse(r=>r.url().includes('/reaction')&&r.request().method()==='POST'),p.locator(".modal [data-action=like]").click()]);
      await Promise.all([p.waitForResponse(r=>r.url().includes('/reaction')&&r.request().method()==='POST'),p.locator(".modal [data-action=follow]").click()]);
      const data = (await request(p, "items/" + post.id)).body;
      assert.equal(data.liked, true);
      assert.equal(data.following, true);
      await p.locator(".modal [data-action=report]").click();
      await p
        .getByRole("status")
        .filter({ hasText: "Reporte enviado" })
        .waitFor();
      await p.keyboard.press("Escape");
    });
    await test("Native WordPress endpoints do not expose or bypass private comments", async () => {
      const guest=await browser.newContext();const comments=(await request(p,'items/'+post.id+'/comments')).body;assert.ok(comments.length);
      const direct=await guest.request.get(base+'/wp-json/wp/v2/comments/'+comments[0].id);assert.notEqual(direct.status(),200);
      const feed=await guest.request.get(base+'/?feed=comments-rss2');assert.ok(!(await feed.text()).includes('E2E Comentario constructivo.'));
      const submitted=await guest.request.post(base+'/wp-comments-post.php',{form:{comment_post_ID:String(post.id),author:'Guest fixture',email:'guest@example.invalid',comment:'E2E NATIVE BYPASS MUST FAIL'}});assert.equal(submitted.status(),403);
      await guest.close();
    });
    await test("Suggested introduction is editable and never auto-sends", async () => {
      await goto(p, "directorio");
      await p
        .locator("[data-action=member]")
        .filter({ hasText: "Ver perfil" })
        .first()
        .click();
      const introButton = p.locator(".modal [data-action=intro]");
      if (await introButton.count()) {
        await introButton.click();
        await p.locator(".modal [name=body]").waitFor();
        assert.ok(
          (await p.locator(".modal [name=body]").inputValue()).length > 30,
        );
        await p.getByRole("button", { name: "Cancelar", exact: true }).click();
      }
      assert.equal(await p.locator(".modal").count(), 0);
    });
    await test("Forum topic and reply publish immediately", async () => {
      await goto(p,"foros");await p.getByRole("button",{name:"Nuevo tema",exact:true}).click();
      await p.locator('.modal [name=parent]').selectOption({index:1});await p.locator('.modal [name=title]').fill('E2E Tema de foro');await p.locator('.modal [name=body]').fill('E2E Consulta sobre prácticas de supervisión.');
      const saving=p.waitForResponse(r=>r.url().includes('/content/topic')&&r.request().method()==='POST');await p.getByRole('button',{name:'Guardar tema',exact:true}).click();
      const topic=await(await saving).json();fixtures.push(topic.id);assert.equal(topic.status,'publish');assert.ok(topic.parent>0);
      await p.goto(base+'/foros/?item='+topic.id);await p.locator('.modal [name=body]').fill('E2E Respuesta de la comunidad.');await p.getByRole('button',{name:'Publicar comentario'}).click();await p.locator('#comments-list').getByText('E2E Respuesta de la comunidad.').waitFor();
    });
    let conversation;
    await test("Private message delivery, read state and blocking", async () => {
      const target = (await request(p, "profiles?q=Tomás")).body.items[0];
      const me = (await request(p, "bootstrap")).body.me.id;
      confirmedPair = JSON.parse(execFileSync('docker', ['compose','exec','-T','wordpress','php','/opt/ascla-tests/confirmed-pair-fixture.php'], {input:JSON.stringify({action:'setup',a:me,b:target.id}),encoding:'utf8'}));
      conversation = (await request(p, "conversations", { target: target.id }))
        .body;
      await p.goto(base + "/mensajeria/?conversation=" + conversation.id);
      await p
        .locator(".chat-compose textarea")
        .fill("E2E Mensaje privado 457.");
      const response = p.waitForResponse(
        (r) => r.url().includes("/messages") && r.request().method() === "POST",
      );
      await p.getByRole("button", { name: "Enviar", exact: true }).click();
      messageIds.push((await (await response).json()).id);
      await p
        .locator(".bubble")
        .filter({ hasText: "E2E Mensaje privado 457." })
        .waitFor();
      const forbidden = await request(
        a,
        "conversations/" + conversation.id + "/messages",
      );
      assert.equal(forbidden.status, 404);
      await p.getByRole("button", { name: "Bloquear", exact: true }).click();
      await p
        .getByRole("button", { name: "Desbloquear", exact: true })
        .waitFor();
      assert.equal(
        (
          await request(p, "conversations/" + conversation.id + "/messages", {
            body: "Blocked",
          })
        ).status,
        403,
      );
      await p.getByRole("button", { name: "Desbloquear", exact: true }).click();
    });
    let event;
    await test("Event registration, cancellation and ICS download", async () => {
      const r = await request(a, "content/event", {
        title: "E2E Evento con calendario",
        body: "Encuentro ficticio E2E",
        status: "publish",
        meta: {
          start: new Date(Date.now() + 86400000).toISOString(),
          end: new Date(Date.now() + 90000000).toISOString(),
          capacity: 2,
          location: "Virtual",
        },
      });
      assert.equal(r.status, 200, JSON.stringify(r.body));
      event = r.body;
      fixtures.push(event.id);
      await p.goto(base + "/eventos/?item=" + event.id);
      await p.getByRole("button", { name: "Registrarme", exact: true }).click();
      await p
        .getByRole("button", { name: "Cancelar inscripción", exact: true })
        .waitFor();
      const downloadEvent = p.waitForEvent("download");
      await p.getByRole("button", { name: "ICS", exact: true }).click();
      const file = await downloadEvent;
      await file.saveAs(path.join(root, "test-results/event.ics"));
      assert.match(
        fs.readFileSync(path.join(root, "test-results/event.ics"), "utf8"),
        /BEGIN:VCALENDAR/,
      );
      await p
        .getByRole("button", { name: "Cancelar inscripción", exact: true })
        .click();
      await p
        .getByRole("button", { name: "Registrarme", exact: true })
        .waitFor();
    });
    await test("Authorized upload stays private before publication", async () => {
      await a.goto(base + "/galeria/"); await a.locator("#page-content h1").waitFor();
      await a.getByRole("button", { name: "Nueva galería" }).click();
      await a.locator(".modal [name=title]").fill("E2E Galería privada");
      await a
        .locator(".modal [name=body]")
        .fill("E2E Imágenes ficticias para comprobar privacidad.");
      const uploaded = a.waitForResponse(
        (r) => r.url().endsWith("/media") && r.request().method() === "POST",
      );
      await a
        .locator(".modal [data-upload=content]")
        .setInputFiles(
          path.join(
            root,
            "wp-content/plugins/ascla-core/assets/ascla-logo.png",
          ),
        );
      const media = await (await uploaded).json();
      assert.ok(media.id);
      mediaIds.push(media.id);
      const guest = await browser.newContext();
      const response = await guest.request.get(media.url);
      assert.notEqual(response.headers()["content-type"], "image/png");
      await guest.close();
      const save = a.waitForResponse(
        (r) =>
          r.url().includes("/content/gallery") &&
          r.request().method() === "POST",
      );
      await a.getByRole("button", { name: "Guardar galería" }).click();
      const gal = await (await save).json();
      fixtures.push(gal.id);
      assert.equal(gal.status, "pending");
    });
    let resource;
    await test("Multimedia job creates drafts, capsules and review gate", async () => {
      resource = (
        await request(a, "content/resource", {
          title: "E2E Conferencia de gobernanza",
          body: "Conferencia de prueba.",
          status: "publish",
          meta: {
            resource_type: "Video",
            chatham: true,
            identities: "Ana Identificable\nEmpresa Reservada",
            transcript:
              "[00:00] Ana Identificable explica que la junta debe supervisar riesgos en Empresa Reservada.\n[01:10] La gobernanza requiere responsabilidades claras y seguimiento.\n[02:30] El directorio revisa los acuerdos y comparte aprendizajes.\n[04:00] Cierre de la sesión.",
          },
        })
      ).body;
      fixtures.push(resource.id);
      const j = (
        await request(a, "jobs", {
          kind: "multimedia",
          resource_id: resource.id,
        })
      ).body;
      execFileSync(
        "docker",
        ["compose", "run", "--rm", "cli", "wp", "ascla", "jobs"],
        { cwd: root, stdio: "pipe" },
      );
      let job;
      for (let i = 0; i < 20; i++) {
        job = (await request(a, "jobs/" + j.id)).body;
        if (job.status === "completed" || job.status === "error") break;
        await new Promise((r) => setTimeout(r, 1500));
      }
      assert.equal(job.status, "completed", job.error);
      fixtures.push(
        job.result.resource_id,
        job.result.hub_id,
        ...job.result.capsule_ids,
      );
      const draft = (await request(a, "items/" + job.result.resource_id)).body;
      assert.equal(draft.status, "draft");
      assert.ok(!draft.body.includes("Ana Identificable"));
      assert.ok(job.result.capsule_ids.length > 0);
      const blocked = await request(a, "items/" + draft.id + "/moderate", {
        decision: "approve",
        reason: "E2E",
      });
      assert.equal(blocked.status, 400);
      await a.goto(base + "/centro-conocimiento/?item=" + draft.id);
      await a.locator(".modal [data-action=infographic]").waitFor();
      const down = a.waitForEvent("download");
      await a.locator(".modal [data-action=infographic]").click();
      await (
        await down
      ).saveAs(path.join(root, "test-results/infographic.svg"));
      assert.match(
        fs.readFileSync(
          path.join(root, "test-results/infographic.svg"),
          "utf8",
        ),
        /© ASCLA/,
      );
      await request(a, "items/" + draft.id + "/moderate", {
        decision: "approve",
        reason: "Fuentes y anonimización revisadas.",
        reviewed: true,
      });
    });
    await test("Knowledge assistant shows verified sources and abstains", async () => {
      await goto(p, "asistente");
      await p
        .locator("[name=question]")
        .fill("¿Cómo supervisar los riesgos de inteligencia artificial?");
      await p.getByRole("button", { name: "Consultar al asistente" }).click();
      await p
        .locator(".answer-card .sources a")
        .first()
        .waitFor({ timeout: 90000 });
      await p.locator("[name=question]").fill("ZXCV987654321INEXISTENTE");
      await p.getByRole("button", { name: "Consultar al asistente" }).click();
      await p
        .locator(".answer-card")
        .first()
        .getByText(/No existe suficiente información/)
        .waitFor({ timeout: 90000 });
    });
    await test("Contact request is private and admin can resolve it", async () => {
      await goto(p, "contacto");
      await p.locator("[name=title]").fill("E2E Solicitud de soporte");
      await p
        .locator("[name=body]")
        .fill("E2E Consulta ficticia para verificar atención.");
      const response = p.waitForResponse(
        (r) =>
          r.url().includes("/content/contact") &&
          r.request().method() === "POST",
      );
      await p.getByRole("button", { name: "Enviar solicitud" }).click();
      const contact = await (await response).json();
      fixtures.push(contact.id);
      assert.equal(contact.status, "private");
      await request(a, "admin/contact/" + contact.id, { status: "closed" });
      await p.reload();
      await p.getByText("Resuelta", { exact: true }).waitFor();
    });
    await test("Admin configuration saves without returning secrets", async () => {
      await a.goto(base + "/wp-admin/admin.php?page=ascla-configuracion");
      await a.locator("[data-form=settings]").waitFor();
      await a.getByRole("button", { name: "Guardar configuración" }).click();
      await a
        .getByRole("status")
        .filter({ hasText: "Configuración guardada" })
        .waitFor();
      const response = await request(a, "settings");
      assert.equal(response.body.ai_key, undefined);
      assert.equal(response.body.google_client_secret, undefined);
    });
    await test("Desktop and mobile layouts fit viewport", async () => {
      await goto(p, "intranet");
      await p.screenshot({
        path: path.join(root, "test-results/dashboard-desktop.png"),
        fullPage: true,
      });
      await p.setViewportSize({ width: 390, height: 844 });
      await p.reload();
      await p.locator(".hero").waitFor();
      assert.equal(
        await p.evaluate(
          () => document.documentElement.scrollWidth > innerWidth,
        ),
        false,
      );
      await p.getByRole("button", { name: "Abrir navegación" }).click();
      await p.locator(".ascla-sidebar.open").waitFor();
      await p.keyboard.press("Escape");
      await p.screenshot({
        path: path.join(root, "test-results/dashboard-mobile.png"),
        fullPage: true,
      });
    });
    assert.deepEqual(errors, []);
    results.push({
      name: "JavaScript runtime errors",
      status: "passed",
      errors,
    });
  } catch (e) {
    failed = e;
    console.error(e);
    if (member)
      await member.page.screenshot({
        path: path.join(root, "test-results/failure-member.png"),
        fullPage: true,
      });
    if (admin)
      await admin.page.screenshot({
        path: path.join(root, "test-results/failure-admin.png"),
        fullPage: true,
      });
  } finally {
    if (member && originalProfile) {
      try {
        await request(member.page, "profiles/me", originalProfile);
      } catch {}
    }
    for (const item of [member, admin])
      if (item) {
        try {
          coverage.push(...(await item.page.coverage.stopJSCoverage()));
        } catch {}
      }
    await browser.close();
    if (confirmedPair) execFileSync('docker', ['compose','exec','-T','wordpress','php','/opt/ascla-tests/confirmed-pair-fixture.php'], {input:JSON.stringify({...confirmedPair,action:'cleanup'}),encoding:'utf8'});
    const assetRoot = path.join(root, "wp-content/plugins/ascla-core/assets");
    const productionScript = entry => {
      if (!entry.url.includes("/ascla-core/assets/")) return null;
      const name = new URL(entry.url).pathname.split("/").pop();
      const file = path.join(assetRoot, name);
      return name.endsWith(".js") && fs.existsSync(file) && entry.source === fs.readFileSync(file, "utf8") ? file : null;
    };
    for (const report of ["zip-v8.json", "completion-v8.json", "sprint-v8.json", "navigation-v8.json"]) {
      const file = path.join(root, "coverage", report);
      if (fs.existsSync(file)) coverage.push(...JSON.parse(fs.readFileSync(file, "utf8")).filter(productionScript));
    }
    fs.writeFileSync(path.join(root,"coverage/raw-v8.json"), JSON.stringify(coverage.filter(productionScript)));
    const map = libCoverage.createCoverageMap({});
    for (const entry of coverage.filter(productionScript)) {
      const converter = v8toIstanbul(productionScript(entry), 0, { source: entry.source });
      await converter.load();
      converter.applyCoverage(entry.functions);
      map.merge(converter.toIstanbul());
    }
    const context = libReport.createContext({
      dir: path.join(root, "coverage"),
      coverageMap: map,
    });
    reports.create("lcovonly").execute(context);
    reports.create("text-summary").execute(context);
    fs.writeFileSync(
      path.join(root, "test-results/e2e.json"),
      JSON.stringify(
        {
          date: new Date().toISOString(),
          results,
          errors,
          failure: failed?.message || null,
        },
        null,
        2,
      ),
    );
    // Remove only IDs created by this suite, never existing demo/community content.
    const ids = fixtures.filter(Number.isInteger);
    const code = `foreach (${JSON.stringify(ids)} as $id) { if (get_post($id)) wp_delete_post($id,true); foreach (['like','follow','report'] as $kind) ASCLA\\Core\\Repositories\\Store::delete('relations',['target_id'=>$id,'kind'=>$kind]); ASCLA\\Core\\Repositories\\Store::delete('registrations',['event_id'=>$id]); } foreach (${JSON.stringify(messageIds)} as $id) { ASCLA\\Core\\Repositories\\Store::delete('messages',['id'=>$id]); } foreach (${JSON.stringify(mediaIds)} as $id) { ASCLA\\Core\\Repositories\\Store::delete('media',['id'=>$id]); }`;
    try {
      execFileSync(
        "docker",
        ["compose", "run", "--rm", "cli", "wp", "eval", code],
        { cwd: root, stdio: "pipe" },
      );
    } catch (e) {
      console.error("Fixture cleanup needs attention:", e.message);
    }
  }
  if (failed) process.exit(1);
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
