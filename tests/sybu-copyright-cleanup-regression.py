#!/usr/bin/env python3
"""
Offline regression for tools/_build/sybu-copyright-cleanup.py (SS-1266).

Exercises the PURE byte logic only -- no database, no network. It proves the
classify() decision table matches the agreed strict plan: exact full stamp at
END only is stripped (with one preceding CRLF+CRLF or LF+LF, stamp-only -> ''),
and every other shape is rejected into review, never silently changed.

SNAPSMACK_EOF_HEADER
    # ===== SNAPSMACK EOF =====
"""
from __future__ import annotations

import importlib.util
import sys
from pathlib import Path

_SCRIPT = Path(__file__).resolve().parents[1] / "tools" / "_build" / "sybu-copyright-cleanup.py"
_spec = importlib.util.spec_from_file_location("sybu_cleanup", _SCRIPT)
m = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(m)   # import must have NO side effects (no DB access)

STAMP_B = m.STAMP_B
fails = 0


def check(label, got, want):
    global fails
    if got == want:
        print(f"ok: {label}")
    else:
        fails += 1
        sys.stderr.write(f"FAIL: {label} -- got {got!r}, want {want!r}\n")


# 1. stamp-only -> empty
check("stamp-only strips to empty", m.classify(STAMP_B), ("STRIP", b"", "stamp-only"))

# 2. caption + LF LF + stamp -> caption
check("lflf separator stripped",
      m.classify(b"A field at dusk." + b"\n\n" + STAMP_B),
      ("STRIP", b"A field at dusk.", "lflf"))

# 3. caption + CRLF CRLF + stamp -> caption
check("crlfcrlf separator stripped",
      m.classify(b"Prairie light.\r\n\r\n" + STAMP_B),
      ("STRIP", b"Prairie light.", "crlfcrlf"))

# 4. caption with its OWN trailing blank line: only ONE separator removed
check("only one separator removed (caption blank line preserved)",
      m.classify(b"Line one.\n\n" + b"\n\n" + STAMP_B),
      ("STRIP", b"Line one.\n\n", "lflf"))

# 5. multibyte caption preserved byte-for-byte
cap = "café \U0001f304 ©me".encode("utf-8")
check("multibyte caption preserved",
      m.classify(cap + b"\n\n" + STAMP_B),
      ("STRIP", cap, "lflf"))

# 6. single LF separator -> REJECT (unexpected), never guessed
check("single-LF separator rejected",
      m.classify(b"caption\n" + STAMP_B),
      ("REJECT", None, "unexpected-separator"))

# 7. space separator -> REJECT
check("space separator rejected",
      m.classify(b"caption " + STAMP_B),
      ("REJECT", None, "unexpected-separator"))

# 8. stamp present but NOT at end -> REJECT non-suffix
check("non-suffix stamp rejected",
      m.classify(STAMP_B + b" and more text after"),
      ("REJECT", None, "non-suffix"))

# 9. stamp twice -> REJECT repeated-marker (even though it ends with the stamp)
check("repeated marker rejected",
      m.classify(b"x" + STAMP_B + b"\n\n" + STAMP_B),
      ("REJECT", None, "repeated-marker"))

# 10. no stamp -> SKIP
check("no stamp skipped", m.classify(b"just a normal caption"),
      ("SKIP", None, "no-stamp"))

# 11. a custom owner copyright (different text, real ©) is never matched
custom = "My photo.\n\n© 2026 Someone Else. All rights reserved.".encode("utf-8")
check("custom copyright untouched", m.classify(custom), ("SKIP", None, "no-stamp"))

# 12. new value for a stripped row never contains the stamp bytes
act, new, _ = m.classify(b"caption text\n\n" + STAMP_B)
check("stripped result has no stamp bytes", (act, STAMP_B in (new or b"")), ("STRIP", False))

# 13. import had no side effects (DB class exists but was not instantiated/used)
check("module exposes classify + DB without running them",
      (callable(m.classify), hasattr(m, "DB")), (True, True))

if fails:
    sys.stderr.write(f"\n{fails} assertion(s) FAILED\n")
    sys.exit(1)
print("\nsybu copyright cleanup logic: all checks passed")

# ===== SNAPSMACK EOF =====
