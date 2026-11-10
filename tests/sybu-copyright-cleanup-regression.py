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
import json
import sys
import tempfile
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

# 14. variant inventory: marker present, full stamp absent -> variant
variant_row = "An old caption.\n\n© Sean McCormick / foundtextures.ca. Free for personal use only.".encode("utf-8")
check("wording variant detected", m.is_variant(variant_row), True)
# 15. full-stamp row is NOT a variant (it is a clean strip, handled elsewhere)
check("full-stamp row is not a variant", m.is_variant(b"cap\n\n" + STAMP_B), False)
# 16. an unrelated caption is not a variant
check("plain caption not a variant", m.is_variant(b"nothing to see"), False)

# 17-20. site_status fail-closed on blank/missing url
check("blank url -> unknown (fail closed)", m.site_status(""), "unknown")
check("None url -> unknown", m.site_status(None), "unknown")
check("foundtextures url -> foundtextures", m.site_status("https://foundtextures.ca"), "foundtextures")
check("normal url -> eligible", m.site_status("https://acolourlesslife.ca"), "eligible")

# 21. backup reload rehearsal accepts a consistent backup row set
good_rows = []
for cap in (b"", b"Field.\n\n", b"Dusk.\r\n\r\n"):
    old = cap + STAMP_B
    _, new, _ = m.classify(old)
    good_rows.append({"id": 1, "table": "snap_posts", "old_hex": old.hex(), "new_hex": new.hex()})
check("backup rehearsal accepts consistent rows", m.rehearse_backup_rows(good_rows), 3)

# 22. backup rehearsal REJECTS a tampered new_hex (would mis-restore)
bad = [{"id": 2, "table": "snap_posts",
        "old_hex": (b"x\n\n" + STAMP_B).hex(), "new_hex": b"WRONG".hex()}]
try:
    m.rehearse_backup_rows(bad)
    check("backup rehearsal rejects tampered row", "no-raise", "raise")
except ValueError:
    check("backup rehearsal rejects tampered row", "raise", "raise")

# ---- apply SQL generation: atomic + in-transaction byte verification (F1) ----
_strip = [{"id": 7, "table": "snap_posts", "col": "description",
           "old_hex": (b"cap\n\n" + STAMP_B).hex(), "new_hex": b"cap".hex()}]
sql = m.build_apply_sql("acme_blog", _strip)
check("apply: begins a transaction", "START TRANSACTION;" in sql, True)
check("apply: byte-exact old-byte predicate (CAST AS BINARY)",
      "CAST(`description` AS BINARY) = UNHEX('" + (b"cap\n\n" + STAMP_B).hex() + "')" in sql, True)
check("apply: in-transaction verify counts the NEW bytes before commit",
      "SET @ok := @ok + (SELECT COUNT(*)" in sql
      and ("CAST(`description` AS BINARY) = UNHEX('" + b"cap".hex() + "')") in sql, True)
check("apply: sets value via CONVERT ... USING utf8mb4",
      "CONVERT(UNHEX('" + b"cap".hex() + "') USING utf8mb4)" in sql, True)
check("apply: atomic gate commits only when every row verified",
      ("IF @ok = 1 THEN COMMIT;" in sql) and ("ELSE ROLLBACK;" in sql), True)
_updates = [ln for ln in sql.splitlines() if ln.strip().startswith("UPDATE ")]
check("apply: every UPDATE carries an old-byte predicate",
      len(_updates) == 1 and all("AND CAST(" in ln for ln in _updates), True)

# 23+. restore SQL only touches rows still at the cleaned value
rsql = m.build_restore_sql("acme_blog", _strip)
check("restore: predicate is the cleaned (new) bytes",
      "CAST(`description` AS BINARY) = UNHEX('" + b"cap".hex() + "')" in rsql, True)
check("restore: writes back the original (old) bytes",
      "CONVERT(UNHEX('" + (b"cap\n\n" + STAMP_B).hex() + "') USING utf8mb4)" in rsql, True)


# ---- FakeDB exercises do_restore guards (F3) offline, no real DB ----
class FakeDB:
    def __init__(self, schema, url):
        self.schema, self.url, self.scripts = schema, url, []

    def query(self, sql):
        if "information_schema.tables" in sql:
            return [[self.schema]]
        if "snap_settings" in sql:
            return [[self.url]]
        return []  # post-restore verify: pretend rows gone/absent

    def script(self, sql):
        self.scripts.append(sql)
        return "ok"


def _write_backup(tmp, schema, url, stamp_hex):
    p = Path(tmp)
    p.write_text(json.dumps({"schema": schema, "site_url": url, "stamp_hex": stamp_hex,
                             "rows": []}), encoding="utf-8")
    return str(p)


import tempfile
_tmp = tempfile.mkdtemp()
bp_ok = _write_backup(Path(_tmp) / "ok.json", "acme_blog", "https://acme.example", m.STAMP_HEX)
bp_badstamp = _write_backup(Path(_tmp) / "bs.json", "acme_blog", "https://acme.example", "deadbeef")
bp_badurl = _write_backup(Path(_tmp) / "bu.json", "acme_blog", "https://OTHER.example", m.STAMP_HEX)
bp_ftx = _write_backup(Path(_tmp) / "ft.json", "acme_blog", "https://acme.example", m.STAMP_HEX)


def expect_exit(label, fn):
    try:
        fn()
        check(label, "no-exit", "SystemExit")
    except SystemExit:
        check(label, "SystemExit", "SystemExit")


expect_exit("restore refuses stamp_hex mismatch",
            lambda: m.do_restore(FakeDB("acme_blog", "https://acme.example"), bp_badstamp))
expect_exit("restore refuses site_url mismatch",
            lambda: m.do_restore(FakeDB("acme_blog", "https://acme.example"), bp_badurl))
expect_exit("restore refuses foundtextures site",
            lambda: m.do_restore(FakeDB("acme_blog", "https://foundtextures.ca"), bp_ftx))
expect_exit("restore refuses unknown-identity (blank url)",
            lambda: m.do_restore(FakeDB("acme_blog", ""), bp_ok))
# happy path: matching stamp + url + eligible -> runs, no raise
_fdb = FakeDB("acme_blog", "https://acme.example")
try:
    m.do_restore(_fdb, bp_ok)
    check("restore runs on a matching eligible backup", len(_fdb.scripts) >= 1, True)
except SystemExit as e:
    check("restore runs on a matching eligible backup", f"exit:{e}", True)

# ---- InnoDB transactional-engine guard (Codex ask) ----
check("engine guard passes when both tables are InnoDB",
      m.assert_transactional({"snap_posts": "InnoDB", "snap_images": "INNODB"}), True)
for bad_engines, label in (
    ({"snap_posts": "MyISAM", "snap_images": "InnoDB"}, "refuses MyISAM snap_posts"),
    ({"snap_posts": "InnoDB", "snap_images": "Aria"}, "refuses Aria snap_images"),
    ({"snap_posts": "InnoDB"}, "refuses missing snap_images"),
):
    try:
        m.assert_transactional(bad_engines)
        check(f"engine guard {label}", "no-exit", "SystemExit")
    except SystemExit:
        check(f"engine guard {label}", "SystemExit", "SystemExit")

if fails:
    sys.stderr.write(f"\n{fails} assertion(s) FAILED\n")
    sys.exit(1)
print("\nsybu copyright cleanup logic: all checks passed")

# ===== SNAPSMACK EOF =====
