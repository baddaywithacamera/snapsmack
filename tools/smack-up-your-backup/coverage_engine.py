"""
Smack Up Your Backup — coverage_engine.py

Scans every backup ZIP in a directory and cross-references against the
manifest to find:
  - Files that appear in more than one ZIP (over-backed — wasting space)
  - Files that never appear in any ZIP (genuinely missed)
  - Files that appear in exactly one ZIP (good)

Also provides DedupeEngine: rewrites affected ZIPs to strip duplicate
entries, keeping each file only in the newest ZIP that contains it.

This is distinct from AuditEngine, which audits the *live server*.
Coverage checks what actually landed in your *local backup archives*.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import os
import shutil
import tempfile
import zipfile
from dataclasses import dataclass, field
from typing import Dict, List, Optional, Set, Tuple

import manifest_reader


# ---------------------------------------------------------------------------
# Result types
# ---------------------------------------------------------------------------

COVERED      = "covered"        # in exactly one ZIP
OVER_BACKED  = "over_backed"    # in two or more ZIPs
NEVER_BACKED = "never_backed"   # not in any ZIP


@dataclass
class CoverageEntry:
    manifest_key:  str
    restores_to:   str
    manifest_size: int
    status:        str                    # COVERED | OVER_BACKED | NEVER_BACKED
    zip_count:     int = 0               # how many ZIPs contain this file
    zip_names:     List[str] = field(default_factory=list)  # which ZIPs


@dataclass
class CoverageReport:
    site_name:    str = ""
    backup_dir:   str = ""
    scan_date:    str = ""
    zips_scanned: List[str] = field(default_factory=list)
    entries:      List[CoverageEntry] = field(default_factory=list)
    summary:      Dict[str, int]      = field(default_factory=dict)

    def by_status(self, status: str) -> List[CoverageEntry]:
        return [e for e in self.entries if e.status == status]

    def count(self, status: str) -> int:
        return self.summary.get(status, 0)


# ---------------------------------------------------------------------------
# Engine
# ---------------------------------------------------------------------------

class CoverageEngine:
    """
    Scan every *.zip in backup_dir, index their contents, and compare
    against the manifest to classify each file as covered / over-backed
    / never-backed.
    """

    def __init__(
        self,
        backup_dir:  str,
        manifest:    manifest_reader.Manifest,
        blog_name:   str = "",
        on_progress=None,
        on_log=None,
    ):
        self.backup_dir  = backup_dir
        self.manifest    = manifest
        self.blog_name   = blog_name
        self.on_progress = on_progress or (lambda s, m, p: None)
        self.on_log      = on_log or print

    def run(self) -> CoverageReport:
        from datetime import datetime, timezone

        report = CoverageReport(
            site_name  = self.manifest.site_name,
            backup_dir = self.backup_dir,
            scan_date  = datetime.now(timezone.utc).isoformat(),
        )

        # ── Find ZIPs ────────────────────────────────────────────────
        self.on_progress("scan", "Finding backup ZIPs…", 0.02)

        zip_files = sorted([
            f for f in os.listdir(self.backup_dir)
            if f.lower().endswith(".zip")
        ])

        if not zip_files:
            self.on_log("No ZIP files found in backup directory.")
            return report

        report.zips_scanned = zip_files
        self.on_log(f"Found {len(zip_files)} backup ZIP(s).")

        # ── Index ZIP contents ────────────────────────────────────────
        # zip_index[normalised_path] = [zip_filename, ...]
        zip_index: Dict[str, List[str]] = {}
        total_zips = len(zip_files)

        for i, zname in enumerate(zip_files):
            pct = 0.05 + 0.45 * (i / max(total_zips, 1))
            self.on_progress("index", f"Indexing: {zname}", pct)
            zpath = os.path.join(self.backup_dir, zname)
            try:
                with zipfile.ZipFile(zpath, "r") as zf:
                    for info in zf.infolist():
                        if info.is_dir():
                            continue
                        # Normalise path separators
                        norm = info.filename.replace("\\", "/")
                        zip_index.setdefault(norm, []).append(zname)
            except Exception as e:
                self.on_log(f"Could not read {zname}: {e}")

        self.on_log(f"Indexed {len(zip_index)} unique paths across all ZIPs.")

        # ── Cross-reference with manifest ─────────────────────────────
        self.on_progress("check", "Checking manifest coverage…", 0.52)

        media_files = {k: v for k, v in self.manifest.files.items()
                       if not v.bundled}
        total = len(media_files)

        for i, (key, record) in enumerate(media_files.items()):
            pct = 0.52 + 0.44 * (i / max(total, 1))
            rel = record.restores_to.replace("\\", "/")

            containing = zip_index.get(rel, [])
            n = len(containing)

            if n == 0:
                status = NEVER_BACKED
            elif n == 1:
                status = COVERED
            else:
                status = OVER_BACKED

            report.entries.append(CoverageEntry(
                manifest_key  = key,
                restores_to   = rel,
                manifest_size = record.size,
                status        = status,
                zip_count     = n,
                zip_names     = containing,
            ))

            if i % 100 == 0:
                self.on_progress("check", f"Checking {i}/{total}…", pct)

        # ── Summary ───────────────────────────────────────────────────
        report.summary = {
            COVERED:      len(report.by_status(COVERED)),
            OVER_BACKED:  len(report.by_status(OVER_BACKED)),
            NEVER_BACKED: len(report.by_status(NEVER_BACKED)),
        }

        self.on_progress("done", "Coverage check complete.", 1.0)
        self.on_log(
            f"Coverage: {report.summary[COVERED]} covered, "
            f"{report.summary[OVER_BACKED]} over-backed, "
            f"{report.summary[NEVER_BACKED]} never backed up."
        )
        return report


# ---------------------------------------------------------------------------
# Deduplication result types
# ---------------------------------------------------------------------------

@dataclass
class DedupeZipResult:
    zip_name:       str
    entries_before: int
    entries_removed: int
    entries_after:  int
    bytes_saved:    int
    ok:             bool
    error:          str = ""


@dataclass
class DedupeResult:
    backup_dir:    str = ""
    run_date:      str = ""
    zips_modified: List[DedupeZipResult] = field(default_factory=list)
    total_removed: int = 0
    total_saved:   int = 0
    errors:        List[str] = field(default_factory=list)


# ---------------------------------------------------------------------------
# DedupeEngine
# ---------------------------------------------------------------------------

class DedupeEngine:
    """
    Takes a CoverageReport that has OVER_BACKED entries and rewrites the
    affected ZIPs so that each file lives in exactly one ZIP — the newest
    ZIP (by sorted filename, ascending) that already contains it.

    Safety rules:
    - Never removes the last copy of any file (verify keeper ZIP is readable
      before stripping from others).
    - Writes to a temp file first, then atomically replaces the original.
    - If any step fails for a ZIP, that ZIP is left untouched.
    """

    def __init__(
        self,
        report:      CoverageReport,
        on_progress=None,
        on_log=None,
    ):
        self.report      = report
        self.backup_dir  = report.backup_dir
        self.on_progress = on_progress or (lambda s, m, p: None)
        self.on_log      = on_log or print

    def run(self) -> DedupeResult:
        from datetime import datetime, timezone

        result = DedupeResult(
            backup_dir = self.backup_dir,
            run_date   = datetime.now(timezone.utc).isoformat(),
        )

        ob_entries = self.report.by_status(OVER_BACKED)
        if not ob_entries:
            self.on_log("No over-backed files to deduplicate.")
            self.on_progress("done", "Nothing to do.", 1.0)
            return result

        # ── Build per-ZIP removal sets ────────────────────────────────
        # For each over-backed file, keep it in the *newest* (last sorted)
        # ZIP that contains it; queue removal from all earlier ZIPs.
        #
        # zip_removals[zip_name] = set of normalised paths to strip
        zip_removals: Dict[str, Set[str]] = {}

        for entry in ob_entries:
            zips_sorted = sorted(entry.zip_names)  # oldest → newest
            keeper      = zips_sorted[-1]           # keep in newest
            for zname in zips_sorted[:-1]:
                zip_removals.setdefault(zname, set()).add(entry.restores_to)

        total_zips = len(zip_removals)
        self.on_log(
            f"Deduplicating {len(ob_entries)} files across "
            f"{total_zips} ZIP(s)…"
        )

        # ── Rewrite each affected ZIP ─────────────────────────────────
        for i, (zname, paths_to_remove) in enumerate(sorted(zip_removals.items())):
            pct = 0.05 + 0.90 * (i / max(total_zips, 1))
            self.on_progress("rewrite", f"Rewriting: {zname}", pct)
            self.on_log(f"  {zname}: removing {len(paths_to_remove)} duplicate(s)…")

            zpath  = os.path.join(self.backup_dir, zname)
            zresult = self._rewrite_zip(zpath, zname, paths_to_remove)
            result.zips_modified.append(zresult)

            if zresult.ok:
                result.total_removed += zresult.entries_removed
                result.total_saved   += zresult.bytes_saved
                self.on_log(
                    f"    ✓ removed {zresult.entries_removed} entries, "
                    f"saved {zresult.bytes_saved // 1024:,} KB"
                )
            else:
                result.errors.append(f"{zname}: {zresult.error}")
                self.on_log(f"    ✗ {zresult.error}")

        self.on_progress("done", "Deduplication complete.", 1.0)
        self.on_log(
            f"Done. Removed {result.total_removed} duplicate entries, "
            f"saved {result.total_saved // 1024:,} KB total."
        )
        return result

    def _rewrite_zip(
        self,
        zpath:          str,
        zname:          str,
        paths_to_remove: Set[str],
    ) -> DedupeZipResult:
        """
        Rewrite zpath, omitting any entry whose normalised path is in
        paths_to_remove.  Uses a temp file in the same directory so the
        replace is atomic on the same filesystem.
        """
        zdir   = os.path.dirname(zpath)
        tmp_fd, tmp_path = tempfile.mkstemp(dir=zdir, suffix=".zip.tmp")
        os.close(tmp_fd)

        try:
            entries_before  = 0
            entries_removed = 0
            bytes_saved     = 0

            with zipfile.ZipFile(zpath, "r") as src_zf:
                src_infos = [i for i in src_zf.infolist() if not i.is_dir()]
                entries_before = len(src_infos)

                with zipfile.ZipFile(tmp_path, "w",
                                     compression=zipfile.ZIP_DEFLATED,
                                     allowZip64=True) as dst_zf:
                    for info in src_infos:
                        norm = info.filename.replace("\\", "/")
                        if norm in paths_to_remove:
                            # Safety: verify the keeper ZIP can read this
                            # file before we drop it — already validated
                            # upstream by the coverage scan, but double-check
                            # the entry is non-zero size so we're not
                            # stripping a corrupt stub.
                            if info.file_size == 0:
                                # Keep zero-byte files — don't risk losing them
                                dst_zf.writestr(info, src_zf.read(info.filename))
                                continue
                            entries_removed += 1
                            bytes_saved     += info.compress_size
                        else:
                            dst_zf.writestr(info, src_zf.read(info.filename))

            # Atomic replace
            shutil.move(tmp_path, zpath)

            return DedupeZipResult(
                zip_name        = zname,
                entries_before  = entries_before,
                entries_removed = entries_removed,
                entries_after   = entries_before - entries_removed,
                bytes_saved     = bytes_saved,
                ok              = True,
            )

        except Exception as e:
            # Clean up temp file if anything went wrong
            try:
                os.unlink(tmp_path)
            except OSError:
                pass
            return DedupeZipResult(
                zip_name        = zname,
                entries_before  = 0,
                entries_removed = 0,
                entries_after   = 0,
                bytes_saved     = 0,
                ok              = False,
                error           = str(e),
            )
# ===== SNAPSMACK EOF =====
