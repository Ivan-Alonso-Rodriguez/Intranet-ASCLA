const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
module.exports = async function imageScenarios({page, test, root}) {
  const bytes = [...fs.readFileSync(path.join(root,"wp-content/plugins/ascla-core/assets/ascla-logo.png"))];
  const open = async (context, name) => {
    await page.evaluate(({bytes,context,name}) => {
      window.__imageResult = undefined;
      window.__imageError = undefined;
      window.ASCLAImageEditor.edit(new File([new Uint8Array(bytes)],name,{type:"image/png"}),{context})
        .then(result => {window.__imageResult=result;}, error => {window.__imageError=error.message;});
    }, {bytes,context,name});
    await page.locator(".ascla-image-editor").waitFor();
  };
  await test("Image editor rejects non-images, oversized and corrupt files without hanging", async () => {
    const results = await page.evaluate(async () => {
      const cases = [null, new File(["text"],"test.txt",{type:"text/plain"}),
        new File([new Uint8Array(15*1024*1024+1)],"large.png",{type:"image/png"}),
        new File(["corrupt"],"broken.png",{type:"image/png"})];
      const messages=[];
      for (const file of cases) {
        try {await window.ASCLAImageEditor.edit(file);messages.push("unexpected success");}
        catch(error) {messages.push(error.message);}
      }
      return messages;
    });
    assert.match(results[0],/JPG, PNG o WebP/);
    assert.match(results[1],/JPG, PNG o WebP/);
    assert.match(results[2],/15 MB/);
    assert.match(results[3],/No se pudo leer/);
    assert.equal(await page.locator(".ascla-image-editor").count(),0);
  });
  await test("Image crop supports zoom, reset, keyboard movement and sanitized filenames", async () => {
    await open("gallery","---badge---.png");
    await page.locator('[data-image-zoom="in"]').click();
    await page.locator('[data-image-zoom="out"]').click();
    const slider=page.locator('.ascla-image-editor input[type="range"]');
    await slider.fill("170");
    await slider.dispatchEvent("input");
    assert.equal(await slider.inputValue(),"170");
    await page.locator("[data-image-reset]").click();
    assert.equal(await slider.inputValue(),"100");
    await page.locator(".image-editor-stage").focus();
    await page.keyboard.press("Shift+ArrowRight");
    await page.keyboard.press("ArrowDown");
    await page.locator("[data-image-apply]").click();
    await page.locator(".ascla-image-editor").waitFor({state:"detached"});
    const result=await page.evaluate(async () => {
      const r=window.__imageResult;
      const image=await createImageBitmap(r.outputFile);
      return {full:r.full,context:r.context,output:r.outputFile.name,master:r.masterFile.name,
        width:image.width,height:image.height,sourceWidth:r.sourceWidth,sourceHeight:r.sourceHeight,bytes:r.storedBytes};
    });
    assert.equal(result.full,false);
    assert.equal(result.context,"gallery");
    assert.match(result.output,/^badge-recorte\.(webp|png|jpg)$/);
    assert.match(result.master,/^badge-master\.(webp|png|jpg)$/);
    assert.ok(result.width<=result.sourceWidth && result.height<=result.sourceHeight);
    assert.ok(Math.abs(result.width/result.height - 4/3)<0.03);
    assert.ok(result.bytes>0);
  });
  await test("Full image mode produces one file and cancellation resolves to null", async () => {
    await open("ally","---.png");
    await page.locator("[data-image-apply]").click();
    await page.locator(".ascla-image-editor").waitFor({state:"detached"});
    const result=await page.evaluate(() => ({
      full:window.__imageResult.full,master:window.__imageResult.masterFile,
      name:window.__imageResult.outputFile.name,
    }));
    assert.equal(result.full,true);
    assert.equal(result.master,null);
    assert.match(result.name,/^imagen-master\.(webp|png|jpg)$/);
    await open("profile","profile.png");
    assert.equal(await page.locator('[data-image-mode="full"]').count(),0);
    await page.keyboard.press("Escape");
    await page.locator(".ascla-image-editor").waitFor({state:"detached"});
    assert.equal(await page.evaluate(() => window.__imageResult),null);
  });
};
