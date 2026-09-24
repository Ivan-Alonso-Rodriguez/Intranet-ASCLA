/* Fail CI on missing, empty, stale-path or incomplete coverage imports. */
const fs = require("node:fs");
const path = require("node:path");
const root = path.resolve(__dirname, "..");
function read(name) {
  const text = fs.readFileSync(path.join(root, "coverage", name), "utf8");
  if (!text.trim()) throw new Error("Empty coverage report: " + name);
  return text;
}
function checkPath(file) {
  if (path.isAbsolute(file) || /^[A-Za-z]:|\\/.test(file) || file.split("/").includes("..")) {
    throw new Error("Report path must be repository-relative: " + file);
  }
  if (!fs.existsSync(path.join(root, file))) throw new Error("Unknown report path: " + file);
}
const clover = read("clover.xml");
const junit = read("junit.xml");
const lcov = read("lcov.info");
const phpFiles = [...clover.matchAll(/<file name="([^"]+)"/g)].map(match => match[1]);
if (!phpFiles.length || !/<line\b[^>]*type="stmt"/.test(clover)) throw new Error("Clover contains no executable PHP lines");
phpFiles.forEach(checkPath);
if (!/<testcase\b/.test(junit) || /<(?:failure|error)\b/.test(junit)) throw new Error("JUnit has no tests or has failures");
[...junit.matchAll(/\bfile="([^"]+)"/g)].forEach(match => checkPath(match[1]));
const jsFiles = [...lcov.matchAll(/^SF:(.+)$/gm)].map(match => match[1].trim());
if (!jsFiles.length || !/^DA:\d+,\d+/m.test(lcov)) throw new Error("LCOV contains no lines");
jsFiles.forEach(checkPath);
for (const name of fs.readdirSync(path.join(root, "wp-content/plugins/ascla-core/assets")).filter(name => name.endsWith(".js"))) {
  if (!jsFiles.includes("wp-content/plugins/ascla-core/assets/" + name)) throw new Error("Missing JavaScript asset in LCOV: " + name);
}
console.log("Coverage validated: " + phpFiles.length + " PHP files, " + jsFiles.length + " JavaScript files and nonempty JUnit.");
