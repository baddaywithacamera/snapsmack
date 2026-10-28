const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'snapsmack-browser-'));
const checks = [
  ['php', 'tests/admin-content-preview-regression.php'],
  [process.execPath, 'tests/chaplin-archive-geometry-browser.cjs'],
  [process.execPath, 'tests/columns-paging-browser.cjs'],
  [process.execPath, 'tests/mosaic-preview-browser.cjs'],
  [process.execPath, 'tests/jive-border-geometry-browser.cjs'],
  [process.execPath, 'tests/engine-config-csp-browser.cjs'],
  [process.execPath, 'tests/tag-infinite-paging-browser.cjs'],
  [process.execPath, 'tests/impact-archive-controls-browser.cjs'],
  [process.execPath, 'tests/scrolltime-beacon-regression.cjs'],
];
let failed = false;
try {
  require.resolve('@playwright/test');
  for (const [command, file] of checks) {
    console.log(`\nCHECK ${file}`);
    const result = spawnSync(command, [path.join(root, file)], {
      cwd: root, stdio: 'inherit', timeout: 180000,
      env: { ...process.env, SNAPSMACK_BROWSER_OUTPUT: output },
    });
    if (result.error || result.status !== 0) {
      console.error(`FAILED ${file}: ${result.error?.message || `exit ${result.status}`}`);
      failed = true;
      break;
    }
  }
} catch (error) {
  console.error(error.message, '\nRun npm ci and npx playwright install chromium in tools/browser-tests.');
  failed = true;
}
if (failed) {
  console.error(`Browser gate FAILED. Diagnostic output: ${output}`);
  process.exitCode = 1;
} else {
  fs.rmSync(output, { recursive: true, force: true });
  console.log('PASS: all required browser/telemetry regressions. This is not all-skin or live-site certification.');
}
