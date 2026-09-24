/* Convert only this run's exact production sources; never import stale coverage. */
const fs = require("node:fs");
const path = require("node:path");
const v8toIstanbul = require("v8-to-istanbul");
const libCoverage = require("istanbul-lib-coverage");
const libReport = require("istanbul-lib-report");
const reports = require("istanbul-reports");

async function writeCoverage(root, entries) {
  const assetRoot = path.join(root, "wp-content/plugins/ascla-core/assets");
  const sources = new Map(fs.readdirSync(assetRoot).filter(name => name.endsWith(".js"))
    .map(name => [name, fs.readFileSync(path.join(assetRoot, name), "utf8")]));
  const collected = new Map();
  for (const entry of entries) {
    let url;
    try { url = new URL(entry.url); } catch { continue; }
    if (!url.pathname.includes("/ascla-core/assets/")) continue;
    const name = path.basename(url.pathname);
    if (!sources.has(name)) continue;
    if (entry.source !== sources.get(name)) throw new Error("Coverage source differs from repository: " + name);
    if (!collected.has(name)) collected.set(name, []);
    collected.get(name).push(entry);
  }
  const map = libCoverage.createCoverageMap({});
  for (const [name, source] of sources) {
    const file = path.join(assetRoot, name);
    // An unloaded asset must remain measurable at zero, not disappear from the report.
    const entriesForFile = collected.get(name) || [{
      functions: [{functionName: "(unloaded)", isBlockCoverage: true,
        ranges: [{startOffset: 0, endOffset: source.length, count: 0}]}],
    }];
    for (const entry of entriesForFile) {
      const converter = v8toIstanbul(file, 0, {source});
      await converter.load();
      converter.applyCoverage(entry.functions);
      const converted = converter.toIstanbul()[file];
      converted.path = path.relative(root, file).split(path.sep).join("/");
      map.addFileCoverage(converted);
    }
  }
  const dir = path.join(root, "coverage");
  fs.mkdirSync(dir, {recursive: true});
  const context = libReport.createContext({dir, coverageMap: map});
  for (const kind of ["lcovonly", "json", "json-summary", "text-summary"]) reports.create(kind).execute(context);
  return map.getCoverageSummary().toJSON();
}
module.exports = {writeCoverage};
