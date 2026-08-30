# SNAP SLAPPER external-tool audit

Audited: 2026-08-30

This audit applies the arm's-length external-tool contract to the SNAP SLAPPER
source and its PyInstaller package. Line references are to the audited `dev`
working tree before release.

| Tool | License | Mechanism | Evidence | Category | Verdict and action |
|---|---|---|---|---|---|
| XPANO | GPL-3.0-or-later | PANOMERGE detects a separately installed executable and starts it with `QProcess`; inputs and output are file paths passed as arguments. | `tools/hub/slapper_qt/panomerge.py:46-87`, `:232-290`; `tools/hub/slapper_qt/library_window.py:437-445` | (A) arm's-length | Clean. The action is disabled when XPANO is absent. Added `/licenses/xpano-external-tool-notice.txt`. |
| RAWTHERAPEE | GPL-3.0-or-later | Detects a separately installed executable and opens the untouched original in a separate process using an argument list and `shell=False`. | `tools/hub/slapper_qt/raw_handoff.py:10-34`, `:37-62` | (A) arm's-length | Clean. Absence leaves no launch button and does not crash. Added `/licenses/rawtherapee-external-tool-notice.txt`. |
| DARKTABLE | GPL-3.0-or-later | Same external RAW handoff as RAWTHERAPEE. | `tools/hub/slapper_qt/raw_handoff.py:10-34`, `:37-62` | (A) arm's-length | Clean. Absence leaves no launch button and does not crash. Added `/licenses/darktable-external-tool-notice.txt`. |

## XPANO / OpenCV determination

PANOMERGE is XPANO CLI category (A), not an OpenCV-linked implementation and
not an XPANO port. `panomerge.py` contains only executable discovery, command
construction, UI, and separate-process supervision. The SNAP SLAPPER Qt build
recipe explicitly excludes `cv2` and the larger scientific stacks at
`tools/hub/slapper_qt.spec:49-54`. Its `binaries` list is empty at line 43.
Inspection of the built PyInstaller archive found no entry matching XPANO,
RAWTHERAPEE, DARKTABLE, OpenCV, or `cv2`.

## Codebase and package sweep

The SNAP SLAPPER process-launching code is confined to PANOMERGE's `QProcess`
bridge and RAW handoff's `subprocess.Popen`. The legacy library code can also
open a user-selected editor or Windows Explorer; both remain separate processes
and no third-party executable is shipped. Repository maintenance scripts and
other SNAPSMACK companion tools also launch programs, but are outside the SNAP
SLAPPER package audited here.

No external program's source tree, executable, presets, profiles, or licence-
controlled data files were found vendored into SNAP SLAPPER. The build manifest
contains no external-tool binaries. The notices added by this audit identify
the three named optional programs without redistributing any part of them.
