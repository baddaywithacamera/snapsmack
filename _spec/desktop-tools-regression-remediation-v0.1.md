# Desktop Tools Regression Remediation — v0.1

**Date:** 2026-09-02
**Status:** Ready for implementation
**Scope:** COLD SNAP, SNAP HQ, GYSS, and CRONOMETER
**Excluded:** SNAP SLAPPER, which is repaired under its own implementation specs.
## 1. Delivery rule

A control existing in source is not evidence that a workflow works. Each repair must
pass a behavioural test, a packaged-build test, and a visible Windows smoke test. A
release may not claim a feature that is merely designed, partially wired, hidden,
untracked, or present only in an uninstalled build.

## 2. COLD SNAP — SMACKTALK/BIGGIE

SMACKTALK longform mode must switch losslessly between:

1. the existing sparse shortcode/source editor; and
2. BIGGIE, a WYSIWYG block editor.

BIGGIE minimum blocks: paragraph, headings H2–H4, image, gallery, quote, list, divider,
buttons/link, HTML/shortcode escape block, and reusable callout. Blocks support insert,
delete, duplicate, drag reorder, keyboard reorder, undo/redo, and accessible selection.
Switching editors must round-trip supported content without loss and visibly isolate
unsupported markup. SMACKONEOUT and GRAMOFSMACK retain the simple editor only.

Release gates:

- draft/save/reopen and sparse→BIGGIE→sparse round trips;
- media references and captions survive reordering;
- preview matches published structure;
- packaged COLD SNAP contains COLD TAKE and BIGGIE;
- no live publication is performed during tests.

## 3. SNAP HQ

### 3.1 Reachable dashboard

Restore a vertically scrollable body, mouse-wheel/trackpad support, keyboard scrolling,
and maximize-on-open. Every control must remain reachable at 1024×768 and 125–200%
Windows scaling. Add a layout test that discovers the last interactive widget and
proves it can be scrolled into view.

### 3.2 Reproducible source

Track every imported shared-settings module and its tests. A clean checkout must build
and start without relying on untracked files from a developer machine. Packaging must
enumerate required modules deliberately rather than succeeding accidentally through a
directory glob.

### 3.3 Credentials and logging

Provide explicit Remove/Clear actions for stored credentials; blank text followed by
Save must not report that a secret was removed. Log HQ under the `snap_hq` namespace,
never `snap_slapper`.

### 3.4 Shared-library management

Expose cache location, per-site size, last sync, staleness, refresh, safe cleanup, and
the distinction between shared content and per-install configuration.

## 4. GYSS

### 4.1 Session/site binding

Resuming a session must resolve its exact saved `site_url` and profile before SORT or
PUSH becomes active. If the profile is missing or ambiguous, stop and request an
explicit choice. PUSH must use the bound session client and show the destination host
in the final confirmation. It may never silently return because no client exists.

Tests cover resume before profile selection, resume after selecting another site,
missing credentials, renamed profiles, and two concurrent saved-site sessions.

### 4.2 Stable drag/drop

SORT drag/drop handlers are bound once or cleanly replaced. Re-rendering the grid 100
times must still execute exactly one reorder per drop. Event delegation is preferred.

## 5. CRONOMETER

Controls must say when they **run due server jobs**, not merely “check health.” For each
site, show pre-run heartbeat, trigger result, jobs reported/run, post-run heartbeat,
duration, and error state. Fetch the displayed heartbeat after `run-crons`, so the
verdict describes the state the user is actually looking at.

Add deterministic tests for success, no due jobs, authentication failure, timeout,
malformed response, partial fleet failure, repeated refresh, mute/hide, and safe UI
updates after a window closes.

## 6. Packaging and acceptance

For every affected tool:

- run source tests and syntax/static checks;
- build from a clean, isolated dependency environment;
- inspect packaging warnings and required modules/assets;
- start the exact installed EXE and exercise the repaired workflow;
- hash the tested artifact and installed artifact and require equality;
- retain the prior executable as a recoverable `.previous` file;
- update the audit with **fixed**, **verified**, or **still open**—never “done” based
  solely on code presence.
