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
const fixture = path.join(temp, 'tests/admin-content-preview-regression.php');
const first = path.join(temp, 'tests/chaplin-archive-geometry-browser.cjs');
const scripts = ['chaplin-archive-geometry-browser.cjs', 'columns-paging-browser.cjs', 'mosaic-preview-browser.cjs', 'jive-border-geometry-browser.cjs', 'scrolltime-beacon-regression.cjs'];
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
  for (const file of scripts) fs.writeFileSync(path.join(temp, 'tests', file),
    'require("node:assert/strict").equal(require("node:fs").readFileSync(require("node:path").join(process.env.SNAPSMACK_BROWSER_OUTPUT,"fresh"),"utf8"),"yes");');
  fs.writeFileSync(first, 'process.exit(9)');
  result = run();
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /chaplin-archive-geometry-browser.cjs: exit 9/);
  assert.doesNotMatch(result.stdout, /CHECK tests\/columns-paging/);
  fs.writeFileSync(first, 'process.exit(0)');
  result = run();
  assert.equal(result.status, 0, result.stderr);
  assert.match(result.stdout, /all required browser\/telemetry regressions/);
  console.log('PASS runner refuses missing dependencies, failed fixture and failed browser; fresh fixture reaches each succeeding check.');
} finally {
  fs.rmSync(temp, { recursive: true, force: true });
}
