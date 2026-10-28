# Browser tests

Playwright is installed here as a development-only dependency. Run commands
from `C:\dev\snapsmack\tools\browser-tests`.

- `npm test`: run tests in Chromium, Firefox and WebKit.
- `npm run regressions`: required Chromium checks for CHAPLIN archive geometry,
  archive paging and generated mosaic preview, plus the Node scroll-time check.
- `npm run test:ui`: open the interactive test runner.
- `npm run report`: open the last HTML report.
- `npm ci` then `npm run browsers`: reproduce the installation on another machine.

The initial `installation.spec.js` verifies browser launch, page navigation,
and operation of a checkbox. **It does not verify any SnapSmack skin.**
Skin checks must exercise real rendering and settings workflows and identify
their historical visual reference. A passing installation check is not a release gate.

Tests run in separate browser profiles. They do not inherit the user's logged-in
Chrome session. Any future saved test login state belongs in ignored `.auth/`;
never commit cookies, passwords, API keys, traces containing secrets, or reports.

Browser downloads live in the Windows user's Playwright cache. Dependencies,
reports and test results are ignored. No hosted testing subscription is needed.

## Installation verification — 27 October 2026

Playwright 1.64.1: Chromium and WebKit pass the installation check.
Firefox 1555 downloads but Windows refuses to launch it: SideBySide event log
reports missing assembly `mozglue`, version `1.0.0.0`. Re-downloading did not
resolve it. Firefox remains configured so a full run reports the failure rather
than silently omitting that browser. Run the working browsers explicitly with
`npm test -- --project=chromium --project=webkit`.

The CMS release workflow runs the required regressions during `gate-note`,
`tag-dev` and `promote-stable`. Missing dependencies, fixture-generation errors,
timeouts and nonzero test exits block the workflow. The pre-push gate requires
`browser_regressions: pass` in the commit's release note. Existing notes without
that evidence must be regenerated; no release tag is moved by this change.
The browser runner creates a fresh temporary fixture directory per run and
retains it on failure. It never relies on an earlier agent's temporary files.

These checks cover named regressions, not all skin controls, all browsers or
live deployment. The all-skin geometry matrix is separate work.
