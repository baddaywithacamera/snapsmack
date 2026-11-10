#!/usr/bin/env python3
"""
sybu-copyright-cleanup.py  (SS-1266)  --  OWNER-RUN, one site per run.

Strip the SYBU default-copyright stamp that older SYBU builds appended to post
and image descriptions, from EVERY site EXCEPT foundtextures. AUTHORED by Claude
Code, RUN BY SEAN. No agent runs it. PM route (room AnIOPdp); Codex strict
exact-suffix plan (Fs3EapbC); Codex reviews (3192685, 8f234515) and Muse review
(jJdhO8Uk) folded in -- all 8 findings.

WHAT IT TOUCHES
  snap_posts.description and snap_images.img_description, in each site schema
  that has a snap_images table AND a non-blank, non-foundtextures site_url.

WHAT "A STAMPED ROW" MEANS
  STRIPPED only when the column, as raw bytes, ENDS WITH the exact full stamp
  exactly once. The stamp is removed, plus ONE immediately preceding blank-line
  separator (CRLF+CRLF or LF+LF). A description that is only the stamp becomes
  empty (''). Every other byte of the caption is preserved.

REFUSED (counted into a review list, never changed)
  non-suffix | repeated-marker | unexpected-separator.

VARIANT INVENTORY (read-only, broad review net, never changed)
  Rows mentioning "foundtextures" that do NOT carry the exact full stamp are
  counted + sampled for human review. SCOPE IS HONEST AND BROAD: it may include
  legitimate captions that merely say "foundtextures". The cleanup NEVER touches
  them; only exact-full-stamp suffix rows are ever stripped. (Finding 4.)

SAFETY / CORRECTNESS
  * --dry-run DEFAULT; reads only. --apply needs --site <schema>; ONE per run.
  * Blank/missing site_url => unknown identity => INELIGIBLE (fail closed, F6).
  * Schemas whose name fails the identifier guard are REPORTED, not silently
    skipped (F8).
  * Before any write: backup every target row's original bytes OUTSIDE the repo;
    RELOAD the persisted backup and rehearse the restore OFFLINE (recompute strip
    from stored old bytes == stored new bytes; stored old bytes still carry the
    stamp); then confirm live bytes still equal the backup (abort on drift). (F2.)
  * --apply refuses unless snap_posts AND snap_images are InnoDB; the atomic
    rollback only holds on a transactional engine (Codex ask). Engines are shown
    in the dry run.
  * Apply is ATOMIC and byte-verified INSIDE the transaction: after each UPDATE a
    SELECT confirms the stored bytes now equal the expected new bytes (CAST AS
    BINARY, so a trigger/charset change cannot pass); the transaction COMMITs only
    if every row verified, else ROLLBACK. Row count alone is not trusted. (F1.)
  * After commit, rows are re-read and verified again.
  * --restore binds to the backup's site_url and stamp_hex and the site's
    eligibility, rolls back only rows still at the cleaned value, then VERIFIES
    and REPORTS any rows it skipped because they changed since cleanup. (F3, F7.)
  * Output is counts/ids/lengths/separator-kind ONLY, never caption content.

TRANSPORT (handed over by Codex, no credentials here)
  ssh -o BatchMode=yes -o ConnectTimeout=8 <ssh-target>
      pct exec <container> -- mariadb --default-character-set=utf8mb4
      --batch --skip-column-names ...

REVIEWER NOTE: the atomic COMMIT/ROLLBACK uses a `BEGIN NOT ATOMIC ... END`
compound block (MariaDB 10.1+). If the fleet's MariaDB rejects it the apply fails
CLOSED (nothing committed); flag the version if so.

SNAPSMACK_EOF_HEADER
    # ===== SNAPSMACK EOF =====
Last non-empty line of this file MUST match the line above.
"""

from __future__ import annotations

import argparse
import json
import shlex
import subprocess
import sys
import time
from pathlib import Path

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

# Broad review marker (brand token). Deliberately wider than the stamp so older
# wording variants and partial remnants surface; may include legitimate mentions.
VARIANT_MARKER = "foundtextures"
VARIANT_MARKER_HEX = VARIANT_MARKER.encode("utf-8").hex()

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
    """Mentions the brand marker but is NOT a clean full-stamp row."""
    return (VARIANT_MARKER.encode("utf-8") in old) and (STAMP_B not in old)


def site_status(url: str) -> str:
    """eligible | foundtextures | unknown (blank/missing url -> fail closed)."""
    u = (url or "").strip().lower()
    if not u:
        return "unknown"
    if "foundtextures" in u:
        return "foundtextures"
    return "eligible"


def rehearse_backup_rows(rows: list) -> int:
    """OFFLINE restore rehearsal over backup rows. Raises on any inconsistency."""
    for r in rows:
        old = bytes.fromhex(r["old_hex"])
        action, new, _ = classify(old)
        if action != "STRIP":
            raise ValueError(f"backup row {r.get('table')}#{r.get('id')} is not a clean strip")
        if new.hex() != r["new_hex"]:
            raise ValueError(f"backup row {r.get('table')}#{r.get('id')} new-bytes mismatch")
        if not old.endswith(STAMP_B):
            raise ValueError(f"backup row {r.get('table')}#{r.get('id')} old bytes lack the stamp")
    return len(rows)


# ---- SQL fragment builders (pure, structure-tested offline) ----
def _val_expr(hexv: str) -> str:
    # UNHEX('')='' and CONVERT('' USING utf8mb4)='' so the empty case is covered.
    return f"CONVERT(UNHEX('{hexv}') USING utf8mb4)"


def _bin_eq(col: str, hexv: str) -> str:
    # byte-exact comparison, immune to collation/charset coercion
    return f"CAST(`{col}` AS BINARY) = UNHEX('{hexv}')"


def build_apply_sql(schema: str, strip: list) -> str:
    n = len(strip)
    L = ["SET SESSION max_statement_time=10;", "SET @ok := 0;", "START TRANSACTION;"]
    for row in strip:
        t, c, rid = row["table"], row["col"], int(row["id"])
        L.append(f"UPDATE `{schema}`.`{t}` SET `{c}` = {_val_expr(row['new_hex'])} "
                 f"WHERE id = {rid} AND {_bin_eq(c, row['old_hex'])};")
        # IN-TRANSACTION byte verify: stored bytes must now equal expected new bytes
        L.append(f"SET @ok := @ok + (SELECT COUNT(*) FROM `{schema}`.`{t}` "
                 f"WHERE id = {rid} AND {_bin_eq(c, row['new_hex'])});")
    L += ["DELIMITER $$", "BEGIN NOT ATOMIC",
          f"  IF @ok = {n} THEN COMMIT; SELECT CONCAT('COMMIT verified=', @ok) AS result;",
          f"  ELSE ROLLBACK; SELECT CONCAT('ROLLBACK verified=', @ok, ' expected={n}') AS result; END IF;",
          "END$$", "DELIMITER ;"]
    return "\n".join(L)


def build_restore_sql(schema: str, rows: list) -> str:
    L = ["SET SESSION max_statement_time=10;", "START TRANSACTION;"]
    for row in rows:
        t, c, rid = row["table"], row["col"], int(row["id"])
        # restore only rows STILL at the cleaned value (don't clobber a later edit)
        L.append(f"UPDATE `{schema}`.`{t}` SET `{c}` = {_val_expr(row['old_hex'])} "
                 f"WHERE id = {rid} AND {_bin_eq(c, row['new_hex'])};")
    L.append("COMMIT;")
    return "\n".join(L)


# ======================================================================
# DB transport (only from main(); import stays side-effect free)
# ======================================================================
class DB:
    def __init__(self, ssh_target: str, pct: str, timeout: int = 120):
        self.ssh_target, self.pct, self.timeout = ssh_target, pct, timeout

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


def discover_sites(db):
    """Returns (sites, dropped). sites=[(schema,url,status)]; dropped=bad idents."""
    raw = [row[0] for row in db.query(
        "SELECT TABLE_SCHEMA FROM information_schema.tables "
        "WHERE TABLE_NAME='snap_images' ORDER BY TABLE_SCHEMA")]
    sites, dropped = [], []
    for s in raw:
        if not ident_ok(s):
            dropped.append(s)
            continue
        rows = db.query(f"SELECT setting_val FROM `{s}`.snap_settings "
                        f"WHERE setting_key='site_url' LIMIT 1")
        url = rows[0][0] if rows and rows[0] else ""
        sites.append((s, url, site_status(url)))
    return sites, dropped


def assert_transactional(engines: dict):
    """Refuse unless both target tables are InnoDB. The atomic COMMIT/ROLLBACK
    gate only guarantees rollback on a transactional engine (Codex ask). Pure."""
    for t, _ in TARGETS:
        e = engines.get(t)
        if e is None:
            raise SystemExit(f"refusing: target table {t} not found in schema")
        if (e or "").upper() != "INNODB":
            raise SystemExit(f"refusing: {t} engine is '{e}', not InnoDB -- the atomic "
                             f"rollback guarantee requires a transactional engine")
    return True


def table_engines(db, schema):
    rows = db.query(
        f"SELECT TABLE_NAME, ENGINE FROM information_schema.tables "
        f"WHERE TABLE_SCHEMA='{schema}' "
        f"AND TABLE_NAME IN ('snap_posts','snap_images')")
    return {r[0]: (r[1] if len(r) > 1 else "") for r in rows}


def read_hex(db, schema, table, col, where):
    rows = db.query(f"SELECT id, HEX(`{col}`) FROM `{schema}`.`{table}` "
                    f"WHERE {where} ORDER BY id")
    return [(r[0], bytes.fromhex(r[1])) for r in rows if len(r) >= 2]


def plan_site(db, schema):
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
        var = db.query(
            f"SELECT id, CHAR_LENGTH(`{col}`) FROM `{schema}`.`{table}` "
            f"WHERE LOCATE(0x{VARIANT_MARKER_HEX}, CAST(`{col}` AS BINARY)) > 0 "
            f"AND LOCATE(0x{STAMP_HEX}, CAST(`{col}` AS BINARY)) = 0 ORDER BY id")
        for r in var:
            variants.append({"id": r[0], "table": table,
                             "len": int(r[1]) if len(r) > 1 and r[1].lstrip("-").isdigit() else -1})
    return strip, reject, variants


# ======================================================================
# Backup / apply / restore
# ======================================================================
def backup_path(backup_dir, schema):
    d = Path(backup_dir)
    d.mkdir(parents=True, exist_ok=True)
    return d / f"{schema}-{time.strftime('%Y%m%d-%H%M%S')}.json"


def write_backup(path, schema, url, strip):
    path.write_text(json.dumps(
        {"schema": schema, "site_url": url, "stamp_hex": STAMP_HEX,
         "created": time.strftime("%Y-%m-%d %H:%M:%S"), "rows": strip},
        indent=2), encoding="utf-8")


def reload_and_rehearse(path, strip):
    """Reload persisted backup from disk and rehearse restore OFFLINE (F2)."""
    data = json.loads(Path(path).read_text(encoding="utf-8"))
    rows = data.get("rows", [])
    if len(rows) != len(strip):
        raise SystemExit("backup roundtrip: reloaded row count != plan")
    plan = {(r["table"], str(r["id"])): r for r in strip}
    for r in rows:
        p = plan.get((r["table"], str(r["id"])))
        if not p or r["old_hex"] != p["old_hex"] or r["new_hex"] != p["new_hex"]:
            raise SystemExit(f"backup roundtrip: reloaded row differs: {r.get('table')}#{r.get('id')}")
    rehearse_backup_rows(rows)
    return len(rows)


def live_drift_check(db, schema, strip):
    drift, by = [], {}
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


def apply_site(db, schema, strip):
    out = db.script(build_apply_sql(schema, strip))
    if "COMMIT verified=" not in out:
        raise SystemExit(f"APPLY DID NOT COMMIT (rolled back / error): {out.strip()}")
    return out.strip()


def verify_site(db, schema, strip):
    bad, by = [], {}
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


def do_restore(db, backup_file):
    data = json.loads(Path(backup_file).read_text(encoding="utf-8"))
    schema = data.get("schema", "")
    if not ident_ok(schema):
        raise SystemExit(f"restore: bad schema in backup: {schema}")
    if data.get("stamp_hex") != STAMP_HEX:
        raise SystemExit("restore: backup stamp_hex does not match this tool's stamp; refusing")
    sites, _ = discover_sites(db)
    live = {s: (u, st) for s, u, st in sites}
    if schema not in live:
        raise SystemExit(f"restore: schema {schema} not present / no snap_images")
    live_url, st = live[schema]
    if st != "eligible":
        raise SystemExit(f"restore: refusing schema {schema} (status={st})")
    if (live_url or "").strip() != (data.get("site_url") or "").strip():
        raise SystemExit(f"restore: live site_url for {schema} does not match the backup; refusing")
    rows = data.get("rows", [])
    db.script(build_restore_sql(schema, rows))
    # post-restore verify + report rows skipped (edited since cleanup)
    not_restored = []
    by = {}
    for row in rows:
        by.setdefault((row["table"], row["col"]), {})[str(row["id"])] = row["old_hex"]
    for (table, col), idmap in by.items():
        live_rows = db.query(f"SELECT id, HEX(`{col}`) FROM `{schema}`.`{table}` "
                             f"WHERE id IN ({','.join(idmap.keys())})")
        lm = {r[0]: (r[1] if len(r) > 1 else "") for r in live_rows}
        for rid, old_hex in idmap.items():
            if lm.get(rid, "").upper() != old_hex.upper():
                not_restored.append(f"{table}#{rid}")
    print(f"restore {schema}: {len(rows) - len(not_restored)}/{len(rows)} rows restored.")
    if not_restored:
        print(f"NOT restored (changed since cleanup, left as-is): {len(not_restored)} -> "
              f"{not_restored[:25]}")


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
            "reject_by_reason": rej,
            "variant_inventory_total": len(variants), "variant_by_table": by_table(variants),
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

    sites, dropped = discover_sites(db)
    status_of = {s: st for s, u, st in sites}
    url_of = {s: u for s, u, st in sites}
    eligible = [(s, u) for s, u, st in sites if st == "eligible"]
    foundtx = [s for s, u, st in sites if st == "foundtextures"]
    unknown = [s for s, u, st in sites if st == "unknown"]

    if args.apply:
        if not args.site:
            raise SystemExit("--apply requires --site <schema> (one site per run)")
        st = status_of.get(args.site)
        if st != "eligible":
            raise SystemExit(f"refusing --apply on {args.site}: status={st or 'not-found'} "
                             f"(fail closed; dropped-bad-ident={args.site in dropped})")
        url = url_of[args.site]
        assert_transactional(table_engines(db, args.site))
        print("engine check: snap_posts + snap_images are InnoDB (transactional)")
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
            raise SystemExit(f"ABORT: {len(drift)} row(s) drifted since read; nothing written. {drift}")
        print("live drift check: OK")
        result = apply_site(db, args.site, strip)
        bad = verify_site(db, args.site, strip)
        if bad:
            raise SystemExit(f"POST-VERIFY MISMATCH {len(bad)}: {bad}. Backup {bpath}; --restore to roll back.")
        print(f"{result}; VERIFIED {len(strip)} row(s) on {args.site}. "
              f"Rejected {len(reject)}, variants-for-review {len(variants)}. Backup: {bpath}")
        return 0

    # ---- DRY RUN (default) ----
    report = []
    for schema, url in eligible:
        strip, reject, variants = plan_site(db, schema)
        report.append({"schema": schema, "site_url": url,
                       "engines": table_engines(db, schema),
                       **summarize(strip, reject, variants)})
    report.sort(key=lambda r: r["strip_total"])
    print(f"SKIPPED foundtextures: {foundtx}")
    print(f"SKIPPED unknown-identity (blank site_url, fail-closed): {unknown}")
    print(f"DROPPED bad-identifier schemas (NOT processed, reported per F8): {dropped}")
    print(json.dumps(report, indent=2))
    positive = [r for r in report if r["strip_total"] > 0]
    if positive:
        first = positive[0]
        print(f"\nSMALLEST FIRST (positive strip count): --site {first['schema']} --apply "
              f"({first['strip_total']} rows)")
    else:
        print("\nNo eligible site has rows to strip.")
    print("\nDRY RUN ONLY. Re-run with --site <schema> --apply to write one site.")
    return 0


if __name__ == "__main__":
    sys.exit(main())

# ===== SNAPSMACK EOF =====
