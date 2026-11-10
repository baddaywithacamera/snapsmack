#!/usr/bin/env python3
"""
sybu-copyright-cleanup.py  (SS-1266)  --  OWNER-RUN, one site per run.

Strip the SYBU default-copyright stamp that older SYBU builds appended to post
and image descriptions, from EVERY site EXCEPT foundtextures. AUTHORED by Claude
Code, RUN BY SEAN. No agent runs it. PM route (room AnIOPdp); Codex strict
exact-suffix plan (Fs3EapbC); Codex review (3192685) folded in; Muse review.

WHAT IT TOUCHES
  snap_posts.description and snap_images.img_description, in each site schema
  that has a snap_images table AND a non-blank, non-foundtextures site_url.

WHAT "A STAMPED ROW" MEANS
  STRIPPED only when the column, as raw bytes, ENDS WITH the exact full stamp
  exactly once. The stamp is removed, plus ONE immediately preceding blank-line
  separator (CRLF+CRLF or LF+LF). A description that is only the stamp becomes
  empty (''). Every other byte of the caption is preserved.

REFUSED (rejected into a counted review list, never changed)
  non-suffix | repeated-marker | unexpected-separator. A custom owner copyright
  never equals this exact stamp, so it is never matched.

VARIANT INVENTORY (read-only, never changed)
  Rows that carry the licence marker but NOT the exact full stamp (older wording
  variants) are counted and sampled for review. The cleanup does not touch them;
  surfacing them answers Codex finding (4).

SAFETY
  * --dry-run is DEFAULT; reads only.
  * --apply requires --site <schema>; ONE site per run.
  * A site with a blank/missing site_url is INELIGIBLE (fail closed).
  * Before any write: a local backup of every target row's original bytes is
    written OUTSIDE the repo; the persisted backup is then RELOADED and a restore
    rehearsal is run OFFLINE (recompute strip from stored old bytes, confirm the
    stored restore returns the original); then the live bytes are confirmed still
    equal the backup (abort on any drift).
  * The site's writes run in ONE transaction; every UPDATE carries an old-byte
    predicate; the transaction COMMITs only if the number of rows actually
    updated equals the plan, else it ROLLs BACK (atomic -- Codex finding 1).
  * After commit, rows are re-read and verified byte-for-byte.
  * --restore <backup.json> rolls a site back; it re-checks site identity and
    refuses foundtextures / unknown sites (Codex finding 3).
  * Output is counts/ids/lengths/separator-kind ONLY, never caption content.

TRANSPORT (handed over by Codex, no credentials here)
  ssh -o BatchMode=yes -o ConnectTimeout=8 <ssh-target>
      pct exec <container> -- mariadb --default-character-set=utf8mb4
      --batch --skip-column-names ...

NOTE for the reviewer: the atomic COMMIT/ROLLBACK uses a `BEGIN NOT ATOMIC ...
END` compound block (MariaDB 10.1+). If the fleet's MariaDB rejects it the apply
fails CLOSED (nothing committed); flag the version if so.

SNAPSMACK_EOF_HEADER
    # ===== SNAPSMACK EOF =====
Last non-empty line of this file MUST match the line above.
"""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
import time
from pathlib import Path

try:
    import shlex
except ImportError:  # pragma: no cover
    shlex = None

# ---- the exact stamp (byte-identical to tools/sybu history / Codex dry run) ----
STAMP = (
    "\u00a9 Sean McCormick / foundtextures.ca. "
    "Free for personal and commercial use. "
    "Cannot be resold as a standalone texture file. "
    "No attribution required. "
    "Permitted for use in AI training datasets."
)
STAMP_B = STAMP.encode("utf-8")
STAMP_HEX = STAMP_B.hex()

# A distinctive licence phrase older variants are likely to share. Used ONLY for
# the read-only variant inventory; never for stripping.
VARIANT_MARKER = "foundtextures.ca. Free for personal"
VARIANT_MARKER_B = VARIANT_MARKER.encode("utf-8")
VARIANT_MARKER_HEX = VARIANT_MARKER_B.hex()

TARGETS = (("snap_posts", "description"), ("snap_images", "img_description"))

DEFAULT_SSH_TARGET = "root@192.168.1.10"
DEFAULT_PCT = "110"
DEFAULT_BACKUP_DIR = r"C:\snapsmack\backups\sybu-copyright-cleanup"


# ======================================================================
# PURE LOGIC -- no I/O, unit-tested offline
# ======================================================================
def classify(old: bytes):
    """(action, new_bytes_or_None, reason) for one column value."""
    occ = old.count(STAMP_B)
    if occ == 0:
        return ("SKIP", None, "no-stamp")
    if occ > 1:
        return ("REJECT", None, "repeated-marker")
    if not old.endswith(STAMP_B):
        return ("REJECT", None, "non-suffix")
    body = old[: -len(STAMP_B)]
    if body == b"":
        return ("STRIP", b"", "stamp-only")
    if body.endswith(b"\r\n\r\n"):
        return ("STRIP", body[:-4], "crlfcrlf")
    if body.endswith(b"\n\n"):
        return ("STRIP", body[:-2], "lflf")
    return ("REJECT", None, "unexpected-separator")


def is_variant(old: bytes) -> bool:
    """Carries the licence marker but NOT the exact full stamp (a wording variant)."""
    return VARIANT_MARKER_B in old and STAMP_B not in old


def site_status(url: str) -> str:
    """eligible | foundtextures | unknown (blank/missing url -> fail closed)."""
    u = (url or "").strip().lower()
    if not u:
        return "unknown"
    if "foundtextures" in u:
        return "foundtextures"
    return "eligible"


def rehearse_backup_rows(rows: list):
    """OFFLINE restore rehearsal over backup rows. Raises on any inconsistency.

    For each row: recompute the strip from the PERSISTED old bytes and confirm it
    equals the stored new bytes; confirm the stored old bytes are what a restore
    would write back. Pure -- no DB. Returns the row count.
    """
    for r in rows:
        old = bytes.fromhex(r["old_hex"])
        action, new, _ = classify(old)
        if action != "STRIP":
            raise ValueError(f"backup row {r.get('table')}#{r.get('id')} is not a clean strip")
        if new.hex() != r["new_hex"]:
            raise ValueError(f"backup row {r.get('table')}#{r.get('id')} new-bytes mismatch")
        # restore writes old back; the stamp must reappear exactly.
        if not old.endswith(STAMP_B) and old != STAMP_B:
            raise ValueError(f"backup row {r.get('table')}#{r.get('id')} old bytes lack the stamp")
    return len(rows)


# ======================================================================
# DB transport (only from main(); import stays side-effect free)
# ======================================================================
class DB:
    def __init__(self, ssh_target: str, pct: str, timeout: int = 120):
        self.ssh_target = ssh_target
        self.pct = pct
        self.timeout = timeout

    def _base(self) -> str:
        return (f"pct exec {self.pct} -- mariadb --default-character-set=utf8mb4 "
                f"--batch --skip-column-names")

    def _argv(self, remote: str):
        return ["ssh", "-o", "BatchMode=yes", "-o", "ConnectTimeout=8",
                self.ssh_target, remote]

    def query(self, sql: str):
        remote = self._base() + " -e " + shlex.quote("SET SESSION max_statement_time=5; " + sql)
        r = subprocess.run(self._argv(remote), capture_output=True, text=True,
                           encoding="utf-8", check=True, timeout=self.timeout)
        return [ln.split("\t") for ln in r.stdout.splitlines() if ln != ""]

    def script(self, sql: str) -> str:
        r = subprocess.run(self._argv(self._base()), input=sql, capture_output=True,
                           text=True, encoding="utf-8", check=True, timeout=self.timeout)
        return r.stdout


def ident_ok(name: str) -> bool:
    return bool(name) and name.replace("_", "").isalnum()


def discover_sites(db: DB):
    """[(schema, url, status)] for schemas that have snap_images."""
    schemas = [row[0] for row in db.query(
        "SELECT TABLE_SCHEMA FROM information_schema.tables "
        "WHERE TABLE_NAME='snap_images' ORDER BY TABLE_SCHEMA")]
    out = []
    for s in schemas:
        if not ident_ok(s):
            continue
        rows = db.query(f"SELECT setting_val FROM `{s}`.snap_settings "
                        f"WHERE setting_key='site_url' LIMIT 1")
        url = rows[0][0] if rows and rows[0] else ""
        out.append((s, url, site_status(url)))
    return out


def read_hex(db: DB, schema: str, table: str, col: str, where: str):
    rows = db.query(f"SELECT id, HEX(`{col}`) FROM `{schema}`.`{table}` "
                    f"WHERE {where} ORDER BY id")
    return [(r[0], bytes.fromhex(r[1])) for r in rows if len(r) >= 2]


def plan_site(db: DB, schema: str):
    """Returns (strip, reject, variants) across both stores."""
    strip, reject, variants = [], [], []
    for table, col in TARGETS:
        for rid, old in read_hex(db, schema, table, col,
                                 f"LOCATE(0x{STAMP_HEX}, CAST(`{col}` AS BINARY)) > 0"):
            action, new, reason = classify(old)
            if action == "STRIP":
                strip.append({"id": rid, "table": table, "col": col,
                              "old_hex": old.hex(), "new_hex": new.hex(),
                              "old_len": len(old), "new_len": len(new), "sep": reason})
            elif action == "REJECT":
                reject.append({"id": rid, "table": table, "col": col,
                               "reason": reason, "old_len": len(old)})
        # read-only variant inventory: marker present, full stamp absent
        var = db.query(
            f"SELECT id, CHAR_LENGTH(`{col}`) FROM `{schema}`.`{table}` "
            f"WHERE LOCATE(0x{VARIANT_MARKER_HEX}, CAST(`{col}` AS BINARY)) > 0 "
            f"AND LOCATE(0x{STAMP_HEX}, CAST(`{col}` AS BINARY)) = 0 ORDER BY id")
        for r in var:
            variants.append({"id": r[0], "table": table,
                             "len": int(r[1]) if len(r) > 1 and r[1].isdigit() else -1})
    return strip, reject, variants


# ======================================================================
# Backup / apply / restore
# ======================================================================
def backup_path(backup_dir: str, schema: str) -> Path:
    d = Path(backup_dir)
    d.mkdir(parents=True, exist_ok=True)
    return d / f"{schema}-{time.strftime('%Y%m%d-%H%M%S')}.json"


def write_backup(path: Path, schema: str, url: str, strip: list):
    path.write_text(json.dumps(
        {"schema": schema, "site_url": url, "stamp_hex": STAMP_HEX,
         "created": time.strftime("%Y-%m-%d %H:%M:%S"), "rows": strip},
        indent=2), encoding="utf-8")


def reload_and_rehearse(path: Path, strip: list) -> int:
    """Reload the persisted backup from disk and rehearse restore OFFLINE."""
    data = json.loads(Path(path).read_text(encoding="utf-8"))
    rows = data.get("rows", [])
    if len(rows) != len(strip):
        raise SystemExit("backup roundtrip: reloaded row count != plan")
    plan = {(r["table"], str(r["id"])): r for r in strip}
    for r in rows:
        p = plan.get((r["table"], str(r["id"])))
        if not p or r["old_hex"] != p["old_hex"] or r["new_hex"] != p["new_hex"]:
            raise SystemExit(f"backup roundtrip: reloaded row differs from plan: "
                             f"{r.get('table')}#{r.get('id')}")
    rehearse_backup_rows(rows)   # recompute + restore rehearsal, pure
    return len(rows)


def live_drift_check(db: DB, schema: str, strip: list) -> list:
    """Live bytes must still equal the backed-up old bytes. Returns drifted ids."""
    drift = []
    by = {}
    for row in strip:
        by.setdefault((row["table"], row["col"]), {})[str(row["id"])] = row["old_hex"]
    for (table, col), idmap in by.items():
        live = db.query(f"SELECT id, HEX(`{col}`) FROM `{schema}`.`{table}` "
                        f"WHERE id IN ({','.join(idmap.keys())})")
        live_map = {r[0]: (r[1] if len(r) > 1 else "") for r in live}
        for rid, old_hex in idmap.items():
            if live_map.get(rid, "").upper() != old_hex.upper():
                drift.append({"id": rid, "table": table})
    return drift


def build_apply_sql(schema: str, strip: list) -> str:
    n = len(strip)
    L = ["SET SESSION max_statement_time=10;", "SET @applied := 0;", "START TRANSACTION;"]
    for row in strip:
        new_sql = f"UNHEX('{row['new_hex']}')" if row["new_hex"] else "''"
        L.append(f"UPDATE `{schema}`.`{row['table']}` SET `{row['col']}` = {new_sql} "
                 f"WHERE id = {int(row['id'])} AND `{row['col']}` = UNHEX('{row['old_hex']}');")
        L.append("SET @applied := @applied + ROW_COUNT();")
    # atomic gate: COMMIT only if every planned row was updated, else ROLLBACK
    L.append("DELIMITER $$")
    L.append("BEGIN NOT ATOMIC")
    L.append(f"  IF @applied = {n} THEN COMMIT; SELECT CONCAT('COMMIT applied=', @applied) AS result;")
    L.append(f"  ELSE ROLLBACK; SELECT CONCAT('ROLLBACK applied=', @applied, ' expected={n}') AS result; END IF;")
    L.append("END$$")
    L.append("DELIMITER ;")
    return "\n".join(L)


def apply_site(db: DB, schema: str, strip: list) -> str:
    out = db.script(build_apply_sql(schema, strip))
    if "COMMIT applied=" not in out:
        raise SystemExit(f"APPLY DID NOT COMMIT (rolled back): {out.strip()}")
    return out.strip()


def verify_site(db: DB, schema: str, strip: list) -> list:
    bad = []
    by = {}
    for row in strip:
        by.setdefault((row["table"], row["col"]), {})[str(row["id"])] = row["new_hex"]
    for (table, col), idmap in by.items():
        live = db.query(f"SELECT id, HEX(`{col}`) FROM `{schema}`.`{table}` "
                        f"WHERE id IN ({','.join(idmap.keys())})")
        live_map = {r[0]: (r[1] if len(r) > 1 else "") for r in live}
        for rid, new_hex in idmap.items():
            if live_map.get(rid, "").upper() != new_hex.upper():
                bad.append({"id": rid, "table": table})
    return bad


def do_restore(db: DB, backup_file: str):
    data = json.loads(Path(backup_file).read_text(encoding="utf-8"))
    schema = data["schema"]
    if not ident_ok(schema):
        raise SystemExit(f"restore: bad schema in backup: {schema}")
    # re-check site identity: must still exist, have snap_images, and be eligible
    sites = {s: st for s, u, st in discover_sites(db)}
    st = sites.get(schema)
    if st is None:
        raise SystemExit(f"restore: schema {schema} not present / no snap_images")
    if st != "eligible":
        raise SystemExit(f"restore: refusing schema {schema} (status={st})")
    L = ["SET SESSION max_statement_time=10;", "START TRANSACTION;"]
    for row in data["rows"]:
        old_sql = f"UNHEX('{row['old_hex']}')" if row["old_hex"] else "''"
        new_sql = f"UNHEX('{row['new_hex']}')" if row["new_hex"] else "''"
        # only restore rows still at the cleaned value (don't clobber a later edit)
        L.append(f"UPDATE `{schema}`.`{row['table']}` SET `{row['col']}` = {old_sql} "
                 f"WHERE id = {int(row['id'])} AND `{row['col']}` = {new_sql};")
    L.append("COMMIT;")
    db.script("\n".join(L))
    print(f"restore: submitted {len(data['rows'])} rows for {schema} from {backup_file}")


# ======================================================================
# Reporting (counts / ids / lengths ONLY -- never caption content)
# ======================================================================
def summarize(strip, reject, variants):
    def by_table(rows):
        d = {}
        for r in rows:
            d[r["table"]] = d.get(r["table"], 0) + 1
        return d
    sep, rej = {}, {}
    for r in strip:
        sep[r["sep"]] = sep.get(r["sep"], 0) + 1
    for r in reject:
        rej[r["reason"]] = rej.get(r["reason"], 0) + 1
    return {"strip_total": len(strip), "strip_by_table": by_table(strip),
            "strip_by_separator": sep, "reject_total": len(reject),
            "reject_by_reason": rej, "variant_inventory_total": len(variants),
            "variant_by_table": by_table(variants),
            "strip_sample_ids": [f"{r['table']}#{r['id']}" for r in strip[:25]],
            "reject_sample_ids": [f"{r['table']}#{r['id']}({r['reason']})" for r in reject[:25]],
            "variant_sample_ids": [f"{r['table']}#{r['id']}" for r in variants[:25]]}


# ======================================================================
def main(argv=None):
    ap = argparse.ArgumentParser(description="SS-1266 SYBU copyright stamp cleanup (owner-run).")
    ap.add_argument("--ssh-target", default=DEFAULT_SSH_TARGET)
    ap.add_argument("--pct", default=DEFAULT_PCT)
    ap.add_argument("--backup-dir", default=DEFAULT_BACKUP_DIR)
    ap.add_argument("--site", help="one schema name to --apply to")
    ap.add_argument("--apply", action="store_true", help="perform the write (needs --site)")
    ap.add_argument("--restore", metavar="BACKUP.json", help="roll a site back from its backup")
    args = ap.parse_args(argv)

    db = DB(args.ssh_target, args.pct)

    if args.restore:
        do_restore(db, args.restore)
        return 0

    sites = discover_sites(db)
    eligible = [(s, u) for s, u, st in sites if st == "eligible"]
    foundtx = [s for s, u, st in sites if st == "foundtextures"]
    unknown = [s for s, u, st in sites if st == "unknown"]

    # ---- APPLY: one eligible site only ----
    if args.apply:
        if not args.site:
            raise SystemExit("--apply requires --site <schema> (one site per run)")
        st = {s: stt for s, u, stt in sites}.get(args.site)
        if st != "eligible":
            raise SystemExit(f"refusing --apply on {args.site}: status={st or 'not-found'} "
                             f"(fail closed; only eligible non-foundtextures sites with a site_url)")
        url = dict((s, u) for s, u, _ in sites)[args.site]
        strip, reject, variants = plan_site(db, args.site)
        print(f"[apply] {args.site}  ({url})")
        print(json.dumps(summarize(strip, reject, variants), indent=2))
        if not strip:
            print("nothing to strip; no write.")
            return 0
        bpath = backup_path(args.backup_dir, args.site)
        write_backup(bpath, args.site, url, strip)
        print(f"backup written: {bpath}")
        reload_and_rehearse(bpath, strip)
        print("backup roundtrip (reload + offline restore rehearsal): OK")
        drift = live_drift_check(db, args.site, strip)
        if drift:
            raise SystemExit(f"ABORT: {len(drift)} row(s) drifted since read; "
                             f"nothing written. ids={drift}")
        print("live drift check: OK (live bytes match backup)")
        result = apply_site(db, args.site, strip)
        bad = verify_site(db, args.site, strip)
        if bad:
            raise SystemExit(f"POST-VERIFY MISMATCH on {len(bad)} row(s): {bad}. "
                             f"Backup {bpath}; use --restore to roll back.")
        print(f"{result}; VERIFIED {len(strip)} row(s) on {args.site}. "
              f"Rejected {len(reject)}, variants-for-review {len(variants)}. Backup: {bpath}")
        return 0

    # ---- DRY RUN (default): read-only, all eligible sites ----
    report = []
    for schema, url in eligible:
        strip, reject, variants = plan_site(db, schema)
        report.append({"schema": schema, "site_url": url, **summarize(strip, reject, variants)})
    report.sort(key=lambda r: r["strip_total"])
    print(f"SKIPPED foundtextures: {foundtx}")
    print(f"SKIPPED unknown-identity (blank site_url, fail-closed): {unknown}")
    print(json.dumps(report, indent=2))
    positive = [r for r in report if r["strip_total"] > 0]
    if positive:
        first = positive[0]
        print(f"\nSMALLEST FIRST (positive strip count): --site {first['schema']} --apply   "
              f"({first['strip_total']} rows)")
    else:
        print("\nNo eligible site has rows to strip.")
    print("\nDRY RUN ONLY. Re-run with --site <schema> --apply to write one site.")
    return 0


if __name__ == "__main__":
    sys.exit(main())

# ===== SNAPSMACK EOF =====
