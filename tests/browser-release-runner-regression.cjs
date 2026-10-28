// Verify orchestration refuses missing dependencies and failed checks, even with stale output.
const fs = require('node:fs'), os = require('node:os'), path = require('node:path');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'snap-gate-proof-'));
const runnerDir = path.join(temp, 'tools/browser-tests');
fs.mkdirSync(runnerDir, { recursive: true });
fs.mkdirSync(path.join(temp, 'tests'));
fs.copyFileSync(path.join(root, 'tools/browser-tests/run-regressions.cjs'), path.join(runnerDir, 'run-regressions.cjs'));
// READ THE REQUIRED LIST FROM THE RUNNER, never a second copy of it.
// This used to be a hand-written array of the runner's checks. A check added
// to the runner -- tools/browser-tests/rationalgeo-static-frame.cjs -- was not
// added here, so the stub was never created, the runner inside this fixture
// could not resolve the real file, and the release gate died with a module
// resolution error instead of a test result. A list duplicated in two files
// is a list that will disagree with itself.
//
// Paths are taken as written, because they are not all under tests/: the
// runner also requires checks that live in tools/browser-tests/.
const runnerSource = fs.readFileSync(path.join(root, 'tools/browser-tests/run-regressions.cjs'), 'utf8');
const checksBlock = runnerSource.slice(runnerSource.indexOf('const checks = ['), runnerSource.indexOf('];', runnerSource.indexOf('const checks = [')));
const required = [...checksBlock.matchAll(/'([^']+\.(?:cjs|php))'/g)].map((m) => m[1]);
assert.ok(required.length >= 2, 'could not read the required checks from the runner');
const phpFixture = required.find((f) => f.endsWith('.php'));
assert.ok(phpFixture, 'the runner no longer has a PHP fixture check; update this test deliberately');
const scripts = required.filter((f) => f.endsWith('.cjs'));
const fixture = path.join(temp, phpFixture);
const first = path.join(temp, scripts[0]);
const firstName = path.basename(scripts[0]);
for (const file of required) fs.mkdirSync(path.dirname(path.join(temp, file)), { recursive: true });
function run(deps = true) {
  return spawnSync(process.execPath, [path.join(runnerDir, 'run-regressions.cjs')], {
    encoding: 'utf8', timeout: 30000,
    env: { ...process.env, NODE_PATH: deps ? path.join(root, 'tools/browser-tests/node_modules') : '' },
  });
}
try {
  fs.writeFileSync(fixture, '<?php exit(7);');
  let result = run(false);
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /npm ci/);
  result = run();
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /admin-content-preview-regression.php: exit 7/);
  fs.writeFileSync(fixture, '<?php file_put_contents(getenv("SNAPSMACK_BROWSER_OUTPUT") . "/fresh", "yes");');
  for (const file of scripts) fs.writeFileSync(path.join(temp, file),
    'require("node:assert/strict").equal(require("node:fs").readFileSync(require("node:path").join(process.env.SNAPSMACK_BROWSER_OUTPUT,"fresh"),"utf8"),"yes");');
  fs.writeFileSync(first, 'process.exit(9)');
  result = run();
  assert.notEqual(result.status, 0);
  assert.ok(result.stderr.includes(firstName + ': exit 9'), result.stderr);
  // Nothing after the first failure runs: the runner stops at the first break.
  assert.doesNotMatch(result.stdout, new RegExp('CHECK ' + scripts[1].replace(/[.\/]/g, '\$&')));
  fs.writeFileSync(first, 'process.exit(0)');
  result = run();
  assert.equal(result.status, 0, result.stderr);
  assert.match(result.stdout, /all required browser\/telemetry regressions/);
  console.log('PASS runner refuses missing dependencies, failed fixture and failed browser; fresh fixture reaches each succeeding check.');
} finally {
  fs.rmSync(temp, { recursive: true, force: true });
}
