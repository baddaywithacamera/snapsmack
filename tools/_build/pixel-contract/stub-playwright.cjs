// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====
//
// SS-1436. Lets the pixel comparator's ARGUMENT CONTRACT be executed on a box
// with no @playwright/test installed, which every git worktree is (SS-1206).
//
// WHY THIS EXISTS AT ALL. My first wiring called the comparator with no
// arguments and my regression passed, because it asserted that the
// argument-free call existed rather than running it. Codex measured the real
// thing on a box with Playwright and found the usage error. This preload is
// how the same contract gets executed here: the browser is stubbed, the
// comparator's own parseArgs runs for real, and a wrong argument list fails
// with the usage error exactly as it would in a release.
//
// It stubs ONLY the module this box lacks. It is a test harness and is never
// loaded by a release: rf_browser_tests() refuses outright when Playwright is
// missing, which is SS-1206's repair and is deliberately not bypassed here.
const Module = require('node:module');
const realLoad = Module._load;
Module._load = function (request) {
  if (request === '@playwright/test') {
    return {
      chromium: {
        launch: async () => { throw new Error('SNAPSMACK_STUB_NO_BROWSER'); },
      },
    };
  }
  return realLoad.apply(this, arguments);
};
// ===== SNAPSMACK EOF =====
