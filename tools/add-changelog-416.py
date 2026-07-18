#!/usr/bin/env python3
# SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
# add-changelog-416.py — splice the 0.7.416 entry into CHANGELOG.md, natively.
# Idempotent, preserves the entire file (including the tail EOF marker), matches
# the file's existing newline style. Run from repo root:
#   python tools\add-changelog-416.py            # dry run — shows where it inserts
#   python tools\add-changelog-416.py --write     # applies + verifies
import os
import sys

ENTRY = '''## 0.7.416 — "The Best Night Ever" (2026-07-17)

JIVE TURKEY's SCOPE kaleidoscope gets denser and grows a flower at its heart, and the font, weight, and colour controls the family declutter had cut come home.

- **JIVE TURKEY — SCOPE kaleidoscope: 20 rays + a flower mandala** — the reflection kaleidoscope steps up from 14 to 20 mirrored rays for a tighter tumble, and a flower mandala now floats at its centre: eight petals alternating the colourway's first two colours around a contrasting hub, turning on their own slow clock. All colour still comes from the active colourway, so it shifts with BARF / BLECH / GROOVY / HARVEST. (`assets/js/ss-engine-jive-turkey.js`.)
- **JIVE TURKEY — TITLE & TAGLINE, TYPOGRAPHY and COLOURS controls restored (17)** — the 0.7.414 declutter cut these working controls along with the genuinely dead ones, so the settings page had lost its font, weight, and colour pickers. All 17 are back and wired, emitting their CSS vars from `skin-profile.php`. Skin v0.1.7. (`skins/jive-turkey/manifest.php`, `skins/jive-turkey/skin-profile.php`.)

'''

ANCHOR = '## 0.7.415'
REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
path = os.path.join(REPO, 'CHANGELOG.md')

with open(path, 'r', encoding='utf-8', newline='') as f:
    data = f.read()

if '## 0.7.416' in data:
    print("0.7.416 entry already present — nothing to do.")
    sys.exit(0)

idx = data.find(ANCHOR)
if idx == -1:
    print("!! anchor '## 0.7.415' not found — refusing to write. Check the file head by hand.")
    sys.exit(1)

nl = '\r\n' if '\r\n' in data[:4096] else '\n'
entry = ENTRY.replace('\n', nl)
new = data[:idx] + entry + data[idx:]

if '--write' not in sys.argv[1:]:
    print(f"DRY RUN: would insert the 0.7.416 entry just above the '{ANCHOR}' heading.")
    print(f"File is {len(data)} bytes; newline style = {'CRLF' if nl == chr(13)+chr(10) else 'LF'}.")
    print("Re-run with --write to apply.")
    sys.exit(0)

with open(path, 'w', encoding='utf-8', newline='') as f:
    f.write(new)

with open(path, 'r', encoding='utf-8', newline='') as f:
    check = f.read()
last = [ln for ln in check.splitlines() if ln.strip()][-1]
has_416 = '## 0.7.416' in check
eof_ok = 'SNAPSMACK EOF' in last
print(f"Inserted 0.7.416 entry: {has_416}")
print(f"EOF marker intact:      {eof_ok}  ->  {last}")
print("Now run: python tools\\check-eof.py   (expect All clear), then commit.")
if not (has_416 and eof_ok):
    sys.exit(1)
# ===== SNAPSMACK EOF =====
