"""
Smack Up Your Backup — audit_engine.py
Three-way audit: manifest vs FTP filesystem vs database image records.
Produces a categorised AuditReport with wrong-location detection.
"""

import os
from dataclasses import dataclass, field
from typing import Dict, List, Optional

import ftp_client as ftp_module
import manifest_reader


# ---------------------------------------------------------------------------
# Result types
# ---------------------------------------------------------------------------

HEALTHY            = "healthy"
MISSING_FROM_SERVER= "missing_from_server"
ORPHANED_ON_SERVER = "orphaned_on_server"
ORPHANED_IN_DB     = "orphaned_in_db"
NOT_IN_DB          = "not_in_db"
SIZE_MISMATCH      = "size_mismatch"
WRONG_LOCATION     = "wrong_location"


@dataclass
class AuditEntry:
    manifest_key:    str
    restores_to:     str
    category:        str
    manifest_size:   int   = 0
    remote_size:     int   = -1
    db_id:           Optional[int] = None
    db_title:        Optional[str] = None
    wrong_location:  str   = ""     # actual remote path if wrong-located
    note:            str   = ""


@dataclass
class AuditReport:
    site_name:    str = ""
    site_url:     str = ""
    audit_date:   str = ""
    entries:      List[AuditEntry]  = field(default_factory=list)
    orphan_server:List[str]         = field(default_factory=list)   # paths on server not in manifest
    summary:      Dict[str, int]    = field(default_factory=dict)

    def by_category(self, cat: str) -> List[AuditEntry]:
        return [e for e in self.entries if e.category == cat]

    def count(self, cat: str) -> int:
        return self.summary.get(cat, 0)


# ---------------------------------------------------------------------------
# Engine
# ---------------------------------------------------------------------------

class AuditEngine:
    def __init__(
        self,
        profile:     dict,
        manifest:    manifest_reader.Manifest,
        on_progress=None,
        on_log=None,
    ):
        self.profile     = profile
        self.manifest    = manifest
        self.on_progress = on_progress or (lambda s, m, p: None)
        self.on_log      = on_log or print

    def run(self) -> AuditReport:
        from datetime import datetime, timezone
        report = AuditReport(
            site_name  = self.manifest.site_name,
            site_url   = self.manifest.site_url,
            audit_date = datetime.now(timezone.utc).isoformat(),
        )

        # ── Connect FTP ──────────────────────────────────────────────
        self.on_progress("ftp", "Connecting via FTP…", 0.02)
        ftp = ftp_module.FTPClient(
            host        = self.profile.get("ftp_host", ""),
            user        = self.profile.get("ftp_user", ""),
            password    = self.profile.get("ftp_pass", ""),
            remote_dir  = self.profile.get("ftp_remote_dir", "/"),
            port        = int(self.profile.get("ftp_port", 21)),
            use_tls     = bool(self.profile.get("ftp_ssl", True)),
            verify_cert = bool(self.profile.get("ftp_verify_cert", False)),
            transfer_delay = 0,     # No pacing during audit reads
        )
        try:
            ftp.connect()
        except Exception as e:
            self.on_log(f"FTP connection failed: {e}")
            return report

        # ── Build remote index ────────────────────────────────────────
        self.on_progress("index", "Building remote file index…", 0.05)
        try:
            remote_index = ftp.build_remote_index()
        except Exception as e:
            self.on_log(f"Could not build remote index: {e}")
            ftp.disconnect()
            return report

        ftp.disconnect()
        self.on_progress("index", f"Remote index: {len(remote_index)} files.", 0.30)

        # Build basename index for wrong-location detection
        # {basename: [rel_path, ...]}
        basename_index: Dict[str, List[str]] = {}
        for rpath in remote_index:
            bn = os.path.basename(rpath).lower()
            basename_index.setdefault(bn, []).append(rpath)

        # Build DB lookup: {img_file_basename: {id, title, slug}}
        db_lookup: Dict[str, dict] = {}
        for row in self.manifest.database_images:
            bn = os.path.basename(row.get("file", "")).lower()
            if bn:
                db_lookup[bn] = row

        # ── Audit manifest files ──────────────────────────────────────
        media_files  = {k: v for k, v in self.manifest.files.items() if not v.bundled}
        total        = len(media_files)
        accounted    = set()   # remote paths covered by manifest entries

        self.on_progress("audit", "Auditing manifest entries…", 0.32)

        for i, (key, record) in enumerate(media_files.items()):
            pct = 0.32 + 0.55 * (i / max(total, 1))
            rel = record.restores_to
            bn  = os.path.basename(rel).lower()

            remote_size  = remote_index.get(rel, -1)
            db_row       = db_lookup.get(bn)

            entry = AuditEntry(
                manifest_key  = key,
                restores_to   = rel,
                manifest_size = record.size,
                remote_size   = remote_size,
                db_id         = db_row.get("id")    if db_row else None,
                db_title      = db_row.get("title") if db_row else None,
            )

            if remote_size == -1:
                # Not at expected path — check wrong location
                candidates = [p for p in basename_index.get(bn, []) if p != rel]
                if candidates:
                    entry.category       = WRONG_LOCATION
                    entry.wrong_location = candidates[0]
                    entry.note = f"Found at: {candidates[0]}"
                else:
                    entry.category = MISSING_FROM_SERVER
            elif remote_size != record.size:
                entry.category = SIZE_MISMATCH
                entry.note = f"Expected {record.size}B, got {remote_size}B"
                accounted.add(rel)
            elif db_row is None and rel.startswith("img_uploads/"):
                # Image file with no DB row — broken link
                entry.category = NOT_IN_DB
                accounted.add(rel)
            else:
                entry.category = HEALTHY
                accounted.add(rel)

            report.entries.append(entry)
            self.on_progress("audit", f"Checking: {rel}", pct)

        # ── Orphaned DB entries ───────────────────────────────────────
        self.on_progress("db", "Checking database orphans…", 0.88)
        manifest_basenames = {
            os.path.basename(r.restores_to).lower()
            for r in media_files.values()
        }
        for row in self.manifest.database_images:
            bn = os.path.basename(row.get("file", "")).lower()
            if bn and bn not in manifest_basenames:
                entry = AuditEntry(
                    manifest_key = "",
                    restores_to  = row.get("file", ""),
                    category     = ORPHANED_IN_DB,
                    db_id        = row.get("id"),
                    db_title     = row.get("title"),
                    note         = "DB row points to file not in manifest",
                )
                report.entries.append(entry)

        # ── Orphaned server files ─────────────────────────────────────
        self.on_progress("orphans", "Finding orphaned server files…", 0.92)
        img_remote = {
            p for p in remote_index
            if p.startswith("img_uploads/")
        }
        manifest_paths = {r.restores_to for r in media_files.values()}
        orphaned = img_remote - manifest_paths - accounted
        report.orphan_server = sorted(orphaned)

        # ── Summary ───────────────────────────────────────────────────
        cats = [HEALTHY, MISSING_FROM_SERVER, ORPHANED_ON_SERVER,
                ORPHANED_IN_DB, NOT_IN_DB, SIZE_MISMATCH, WRONG_LOCATION]
        report.summary = {c: len(report.by_category(c)) for c in cats}
        report.summary[ORPHANED_ON_SERVER] = len(orphaned)

        self.on_progress("done", "Audit complete.", 1.0)
        return report
