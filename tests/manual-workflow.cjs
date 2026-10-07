/* Scanner guards are exercised without a network connection or real credentials. */
const fs = require("node:fs"), os = require("node:os"), path = require("node:path");
const {spawnSync} = require("node:child_process"), assert = require("node:assert/strict");
const temp = fs.mkdtempSync(path.join(os.tmpdir(), "ascla-sonar-"));
try {
  for (const name of ["scripts", "coverage", "bin"]) fs.mkdirSync(path.join(temp, name));
  fs.copyFileSync(path.join(__dirname, "../scripts/sonar-manual.sh"), path.join(temp, "scripts/sonar-manual.sh"));
  const executable = (name, body) => fs.writeFileSync(path.join(temp, "bin", name), "#!/bin/sh\n" + body, {mode:0o700});
  executable("git", 'case "$1" in branch) echo "$TEST_BRANCH";; status) printf "%s" "$TEST_DIRTY";; rev-parse) echo revision;; esac\n');
  executable("docker", 'printf "%s\\n" "$@" > "$TEST_DOCKER_ARGS"\n');
  for (const report of ["clover.xml", "junit.xml", "lcov.info"]) fs.writeFileSync(path.join(temp, "coverage", report), "local-test-report\n");
  const marker = path.join(temp, "docker-args");
  const cases = [
    ["unsupported branch", "development", {}, false],
    ["different branch", "uat", {}, false],
    ["uncommitted files", "qa", {TEST_DIRTY:" M file"}, false],
    ["stale reports", "qa", {TEST_REVISION:"older"}, false],
    ["Markdown URL", "qa", {SONAR_HOST_URL:"[https://example.invalid](https://example.invalid)"}, false],
    ["empty token", "qa", {SONAR_TOKEN:""}, false],
    ["QA", "qa", {}, true],
    ["UAT", "uat", {TEST_BRANCH:"uat"}, true],
  ];
  for (const [name, branch, overrides, ok] of cases) {
    fs.rmSync(marker, {force:true});
    fs.writeFileSync(path.join(temp, "coverage/source-revision"), overrides.TEST_REVISION || "revision");
    const env = {...process.env, PATH:path.join(temp, "bin")+":"+process.env.PATH, TEST_BRANCH:"qa", TEST_DIRTY:"", TEST_DOCKER_ARGS:marker, SONAR_HOST_URL:"https://example.invalid", SONAR_TOKEN:"local-dummy-credential", ...overrides};
    const run = spawnSync("bash", [path.join(temp, "scripts/sonar-manual.sh"), branch], {env, encoding:"utf8"});
    assert.equal(run.status === 0, ok, name);
    assert.equal(fs.existsSync(marker), ok, name+" must stop before scanner");
    if (ok) {
      const args = fs.readFileSync(marker, "utf8");
      assert.ok(args.includes("-Dsonar.qualitygate.wait=true"));
      assert.ok(args.includes("-Dsonar.projectKey=ASCLA-"+branch.toUpperCase()));
      assert.ok(!args.includes(env.SONAR_TOKEN), "Token must be passed through environment, not command arguments");
    }
  }
  console.log("PASS 8 manual scanner guard cases; no network requests.");
} finally { fs.rmSync(temp, {recursive:true, force:true}); }
