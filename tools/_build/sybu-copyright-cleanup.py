#!/usr/bin/env python3
"""
sybu-copyright-cleanup.py  (SS-1266)  --  OWNER-RUN, one site per run.

Strip the SYBU default-copyright stamp that older SYBU builds appended to post
and image descriptions, from EVERY site EXCEPT foundtextures. This file is
AUTHORED by Claude Code and RUN BY SEAN. No agent runs it. Written per the PM's
route (room AnIOPdp) and Codex's strict exact-suffix plan (Fs3EapbC), reviewed
by Muse (5-Iz3g / IsCLvlaL).

WHAT IT TOUCHES
  snap_posts.description  and  snap_images.img_description, in each site schema
  that has a snap_images table, resolved to its site_url and SKIPPED when the
  URL is foundtextures. Nothing else is read or written in the database.

WHAT "A STAMPED ROW" MEANS
  A row is only STRIPPED when the column, as raw bytes, ENDS WITH the exact full
  stamp (below) exactly once. The stamp is removed, and ONE immediately
  preceding blank-line separator -- CRLF+CRLF or LF+LF -- is removed with it. A
  description that is nothing but the stamp becomes empty (''). Every other byte
  of the caption is preserved unchanged.

WHAT IT REFUSES TO TOUCH (rejected into a review list, counted, never changed)
  * the stamp text present but NOT at the end        (reason: non-suffix)
  * the stamp text present more than once            (reason: repeated-marker)
  * a preceding separator that is neither CRLF+CRLF
    nor LF+LF and the caption is non-empty           (reason: unexpected-separator)
  A custom owner copyright never equals this exact stamp, so it is never matched.

SAFETY
  * --dry-run is the DEFAULT; it reads only and changes nothing.
  * --apply requires --site <schema> (ONE site per run) and is explicit.
  * Before any write: a local backup of every target row's original bytes is
    written OUTSIDE the repo, and a restore round-trip check confirms the live
    bytes still equal the backup (abort on any drift).
  * Each site's writes run inside a single transaction; every UPDATE carries an
    old-byte predicate, so a row edited since the backup is left untouched.
  * After COMMIT the rows are re-read and verified byte-for-byte.
  * --restore <backup.json> rolls a site back to the backed-up originals.
  * Output is counts, ids, lengths and separator kinds ONLY -- never caption
    content. Original bytes live only in the local backup file.

TRANSPORT (handed over by Codex, no credentials in this file)
  ssh -o BatchMode=yes -o ConnectTimeout=8 <ssh-target>
      pct exec <container> -- mariadb --default-character-set=utf8mb4
      --batch --skip-column-names ...
  Authentication is the existing SSH key / Unix socket already on Sean's box.

SNAPSMACK_EOF_HEADER
    # ===== SNAPSMACK EOF =====
Last non-empty line of this file MUST match the line above.
"""

from __future__ import annotations

import argparse
import json
import os
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

TARGETS = (("snap_posts", "description"), ("snap_images", "img_description"))

DEFAULT_SSH_TARGET = "root@192.168.1.10"
DEFAULT_PCT = "110"
DEFAULT_BACKUP_DIR = r"C:\snapsmack\backups\sybu-copyright-cleanup"


# ======================================================================
# PURE LOGIC -- no I/O, unit-tested offline (tests/sybu-copyright-cleanup-regression.py)
# ======================================================================
def classify(old: bytes):
    """Decide what to do with one column value.

    Returns (action, new_bytes_or_None, reason):
      ('STRIP',  new,  sep_kind)  -- remove stamp (+ one separator); new is the result
      ('REJECT', None, reason)    -- stamp present but not a clean single suffix
      ('SKIP',   None, 'no-stamp')-- stamp text not present at all
    """
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


# ======================================================================
# DB transport (only called from main(); import stays side-effect free)
# ======================================================================
class DB:
    def __init__(self, ssh_target: str, pct: str, timeout: int = 120):
        self.ssh_target = ssh_target
        self.pct = pct
        self.timeout = timeout

    def _base_remote(self) -> str:
        return (
            f"pct exec {self.pct} -- mariadb --default-character-set=utf8mb4 "
            f"--batch --skip-column-names"
        )

    def _argv(self, remote: str):
        return ["ssh", "-o", "BatchMode=yes", "-o", "ConnectTimeout=8",
                self.ssh_target, remote]

    def query(self, sql: str):
        """Read-only: SQL passed via -e (quoted). Returns list of split rows."""
        remote = self._base_remote() + " -e " + shlex.quote(
            "SET SESSION max_statement_time=5; " + sql)
        r = subprocess.run(self._argv(remote), capture_output=True, text=True,
                           encoding="utf-8", check=True, timeout=self.timeout)
        return [ln.split("\t") for ln in r.stdout.splitlines() if ln != ""]

    def script(self, sql: str):
        """Writes: multi-statement SQL fed via STDIN (no arg-length limit)."""
        remote = self._base_remote()
        r = subprocess.run(self._argv(remote), input=sql, capture_output=True,
                           text=True, encoding="utf-8", check=True,
                           timeout=self.timeout)
        return r.stdout


def ident_ok(name: str) -> bool:
    return bool(name) and name.replace("_", "").isalnum()


def discover_sites(db: DB):
    """Return [(schema, site_url)] for schemas that have snap_images, with url."""
    schemas = [row[0] for row in db.query(
        "SELECT TABLE_SCHEMA FROM information_schema.tables "
        "WHERE TABLE_NAME='snap_images' ORDER BY TABLE_SCHEMA")]
    out = []
    for s in schemas:
        if not ident_ok(s):
            continue
        rows = db.query(
            f"SELECT setting_val FROM `{s}`.snap_settings "
            f"WHERE setting_key='site_url' LIMIT 1")
        url = rows[0][0] if rows else ""
        out.append((s, url))
    return out


def is_foundtextures(url: str) -> bool:
    return "foundtextures" in (url or "").lower()


def read_stamped(db: DB, schema: str, table: str, col: str):
    """Rows whose column contains the stamp bytes. Returns [(id, old_bytes)]."""
    rows = db.query(
        f"SELECT id, HEX(`{col}`) FROM `{schema}`.`{table}` "
        f"WHERE LOCATE(0x{STAMP_HEX}, CAST(`{col}` AS BINARY)) > 0 ORDER BY id")
    out = []
    for r in rows:
        if len(r) < 2:
            continue
        out.append((r[0], bytes.fromhex(r[1])))
    return out


def plan_site(db: DB, schema: str):
    """Classify every stamped row across both stores. Returns strip/reject lists."""
    strip, reject = [], []
    for table, col in TARGETS:
        for rid, old in read_stamped(db, schema, table, col):
            action, new, reason = classify(old)
            if action == "STRIP":
                strip.append({"id": rid, "table": table, "col": col,
                              "old_hex": old.hex(), "new_hex": new.hex(),
                              "old_len": len(old), "new_len": len(new),
                              "sep": reason})
            elif action == "REJECT":
                reject.append({"id": rid, "table": table, "col": col,
                               "reason": reason, "old_len": len(old)})
    return strip, reject


# ======================================================================
# Backup / apply / restore
# ======================================================================
def backup_path(backup_dir: str, schema: str) -> Path:
    stamp = time.strftime("%Y%m%d-%H%M%S")
    d = Path(backup_dir)
    d.mkdir(parents=True, exist_ok=True)
    return d / f"{schema}-{stamp}.json"


def write_backup(path: Path, schema: str, url: str, strip: list):
    payload = {"schema": schema, "site_url": url, "stamp_hex": STAMP_HEX,
               "created": time.strftime("%Y-%m-%d %H:%M:%S"), "rows": strip}
    path.write_text(json.dumps(payload, indent=2), encoding="utf-8")


def restore_roundtrip_check(db: DB, schema: str, strip: list) -> list:
    """Confirm the live bytes still equal what we are about to back up.

    Returns a list of ids that DRIFTED (live != planned old) -- must be empty
    before any write.
    """
    drift = []
    by_table = {}
    for row in strip:
        by_table.setdefault((row["table"], row["col"]), {})[str(row["id"])] = row["old_hex"]
    for (table, col), idmap in by_table.items():
        ids = ",".join(idmap.keys())
        live = db.query(
            f"SELECT id, HEX(`{col}`) FROM `{schema}`.`{table}` "
            f"WHERE id IN ({ids})")
        live_map = {r[0]: (r[1] if len(r) > 1 else "") for r in live}
        for rid, old_hex in idmap.items():
            if live_map.get(rid, "").upper() != old_hex.upper():
                drift.append({"id": rid, "table": table})
    return drift


def apply_site(db: DB, schema: str, strip: list):
    """One transaction, old-byte predicates. Returns SQL sent (for the record)."""
    lines = ["SET SESSION max_statement_time=10;", "START TRANSACTION;"]
    for row in strip:
        new_sql = f"UNHEX('{row['new_hex']}')" if row["new_hex"] else "''"
        lines.append(
            f"UPDATE `{schema}`.`{row['table']}` SET `{row['col']}` = {new_sql} "
            f"WHERE id = {int(row['id'])} "
            f"AND `{row['col']}` = UNHEX('{row['old_hex']}');")
    lines.append("COMMIT;")
    sql = "\n".join(lines)
    db.script(sql)
    return sql


def verify_site(db: DB, schema: str, strip: list) -> list:
    """Re-read and confirm each row now equals its expected new bytes."""
    bad = []
    by_table = {}
    for row in strip:
        by_table.setdefault((row["table"], row["col"]), {})[str(row["id"])] = row["new_hex"]
    for (table, col), idmap in by_table.items():
        ids = ",".join(idmap.keys())
        live = db.query(
            f"SELECT id, HEX(`{col}`) FROM `{schema}`.`{table}` "
            f"WHERE id IN ({ids})")
        live_map = {r[0]: (r[1] if len(r) > 1 else "") for r in live}
        for rid, new_hex in idmap.items():
            if live_map.get(rid, "").upper() != new_hex.upper():
                bad.append({"id": rid, "table": table})
    return bad


def do_restore(db: DB, backup_file: str):
    data = json.loads(Path(backup_file).read_text(encoding="utf-8"))
    schema = data["schema"]
    if not ident_ok(schema):
        raise SystemExit(f"bad schema in backup: {schema}")
    lines = ["SET SESSION max_statement_time=10;", "START TRANSACTION;"]
    for row in data["rows"]:
        old_sql = f"UNHEX('{row['old_hex']}')" if row["old_hex"] else "''"
        new_sql = f"UNHEX('{row['new_hex']}')" if row["new_hex"] else "''"
        # only restore rows still at the cleaned value (don't clobber a later edit)
        lines.append(
            f"UPDATE `{schema}`.`{row['table']}` SET `{row['col']}` = {old_sql} "
            f"WHERE id = {int(row['id'])} AND `{row['col']}` = {new_sql};")
    lines.append("COMMIT;")
    db.script("\n".join(lines))
    print(f"restore: submitted {len(data['rows'])} rows for {schema} from {backup_file}")


# ======================================================================
# Reporting (counts / ids / lengths ONLY -- never caption content)
# ======================================================================
def summarize(strip: list, reject: list):
    def by_table(rows):
        d = {}
        for r in rows:
            d[r["table"]] = d.get(r["table"], 0) + 1
        return d
    sep = {}
    for r in strip:
        sep[r["sep"]] = sep.get(r["sep"], 0) + 1
    rej = {}
    for r in reject:
        rej[r["reason"]] = rej.get(r["reason"], 0) + 1
    return {"strip_total": len(strip), "strip_by_table": by_table(strip),
            "strip_by_separator": sep, "reject_total": len(reject),
            "reject_by_reason": rej,
            "strip_sample_ids": [f"{r['table']}#{r['id']}" for r in strip[:25]],
            "reject_sample_ids": [f"{r['table']}#{r['id']}({r['reason']})"
                                  for r in reject[:25]]}


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
    live = [(s, u) for (s, u) in sites if not is_foundtextures(u)]
    skipped = [(s, u) for (s, u) in sites if is_foundtextures(u)]

    # ---- APPLY: one site only ----
    if args.apply:
        if not args.site:
            raise SystemExit("--apply requires --site <schema> (one site per run)")
        match = [(s, u) for (s, u) in live if s == args.site]
        if not match:
            if any(s == args.site for s, _ in skipped):
                raise SystemExit(f"refusing: {args.site} is a foundtextures site")
            raise SystemExit(f"unknown/non-eligible site schema: {args.site}")
        schema, url = match[0]
        strip, reject = plan_site(db, schema)
        print(f"[apply] {schema}  ({url})")
        print(json.dumps(summarize(strip, reject), indent=2))
        if not strip:
            print("nothing to strip; no write.")
            return 0
        bpath = backup_path(args.backup_dir, schema)
        write_backup(bpath, schema, url, strip)
        print(f"backup written: {bpath}")
        drift = restore_roundtrip_check(db, schema, strip)
        if drift:
            raise SystemExit(f"ABORT: {len(drift)} row(s) drifted since read; "
                             f"nothing written. ids={drift}")
        print("restore round-trip check: OK (live bytes match backup)")
        apply_site(db, schema, strip)
        bad = verify_site(db, schema, strip)
        if bad:
            raise SystemExit(f"POST-VERIFY MISMATCH on {len(bad)} row(s): {bad}. "
                             f"Backup at {bpath}; use --restore to roll back.")
        print(f"APPLIED + VERIFIED {len(strip)} row(s) on {schema}. "
              f"Rejected {len(reject)} for review. Backup: {bpath}")
        return 0

    # ---- DRY RUN (default): read-only, all eligible sites, smallest first ----
    report = []
    for schema, url in live:
        strip, reject = plan_site(db, schema)
        report.append({"schema": schema, "site_url": url,
                       **summarize(strip, reject)})
    report.sort(key=lambda r: r["strip_total"])
    print(f"SKIPPED (foundtextures): {[s for s, _ in skipped]}")
    print(json.dumps(report, indent=2))
    if report:
        first = report[0]
        print(f"\nSMALLEST FIRST: --site {first['schema']} --apply   "
              f"({first['strip_total']} rows)")
    print("\nDRY RUN ONLY. Re-run with --site <schema> --apply to write one site.")
    return 0


if __name__ == "__main__":
    sys.exit(main())

# ===== SNAPSMACK EOF =====
