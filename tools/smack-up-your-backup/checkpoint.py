"""
Smack Up Your Backup — checkpoint.py
Atomic crash-recovery checkpoint for the backup pipeline.

Written after every successful file download during Stage 3.
If the process is killed mid-backup (Windows Update, power cut, Ctrl-C),
the checkpoint survives on disk.  On next launch SUYB detects it and
offers to resume rather than restart from scratch.

The checkpoint file is written via a temp-file + atomic rename so a
power cut during the write itself cannot produce a corrupt checkpoint.
"""

import json
import os
from datetime import datetime, timezone
from typing import Optional, Set


class BackupCheckpoint:

    VERSION = 1

    def __init__(self, path: str):
        self.path = path
        self.data: dict = {}

    # ------------------------------------------------------------------
    # Factory helpers
    # ------------------------------------------------------------------

    @classmethod
    def path_for(cls, backup_dir: str, blog_name: str) -> str:
        safe = blog_name.replace("/", "_").replace("\\", "_").strip()
        return os.path.join(backup_dir, f"{safe}_checkpoint.json")

    @classmethod
    def load(cls, backup_dir: str, blog_name: str) -> Optional["BackupCheckpoint"]:
        """Return an existing checkpoint, or None if none exists / is corrupt."""
        path = cls.path_for(backup_dir, blog_name)
        if not os.path.exists(path):
            return None
        try:
            with open(path) as f:
                data = json.load(f)
            if data.get("version") != cls.VERSION:
                return None
            # Must have at least the kit on disk to be resumable
            if not os.path.exists(data.get("kit_path", "")):
                return None
            cp = cls(path)
            cp.data = data
            return cp
        except Exception:
            return None

    # ------------------------------------------------------------------
    # Lifecycle
    # ------------------------------------------------------------------

    def start(
        self,
        blog_name:        str,
        timestamp:        str,
        kit_path:         str,
        sql_full_path:    str,
        sql_schema_path:  str,
        local_media_dir:  str,
        zip_name:         str,
        prev_state:       dict,
        force_full:       bool,
    ) -> None:
        """Initialise a fresh checkpoint at the beginning of Stage 3."""
        self.data = {
            "version":          self.VERSION,
            "blog_name":        blog_name,
            "timestamp":        timestamp,
            "kit_path":         kit_path,
            "sql_full_path":    sql_full_path,
            "sql_schema_path":  sql_schema_path,
            "local_media_dir":  local_media_dir,
            "zip_name":         zip_name,
            "prev_state":       prev_state,
            "force_full":       force_full,
            "downloaded":       [],
            "files_downloaded": 0,
            "files_skipped":    0,
            "files_failed":     0,
            "created_at":       datetime.now(timezone.utc).isoformat(),
            "updated_at":       datetime.now(timezone.utc).isoformat(),
        }
        self._write()

    def record(self, key: str, downloaded: bool = False,
               skipped: bool = False, failed: bool = False) -> None:
        """Record the outcome for one file.  Flushes to disk immediately."""
        if downloaded:
            self.data.setdefault("downloaded", []).append(key)
            self.data["files_downloaded"] = self.data.get("files_downloaded", 0) + 1
        elif skipped:
            self.data["files_skipped"] = self.data.get("files_skipped", 0) + 1
        elif failed:
            self.data["files_failed"] = self.data.get("files_failed", 0) + 1
        self.data["updated_at"] = datetime.now(timezone.utc).isoformat()
        self._write()

    def already_downloaded(self) -> Set[str]:
        """Set of file keys already confirmed downloaded."""
        return set(self.data.get("downloaded", []))

    def delete(self) -> None:
        """Remove checkpoint on successful backup completion."""
        try:
            os.unlink(self.path)
        except Exception:
            pass

    # ------------------------------------------------------------------
    # Internals
    # ------------------------------------------------------------------

    def _write(self) -> None:
        """Atomic write: temp file → rename.  Safe against power cuts."""
        tmp = self.path + ".tmp"
        try:
            with open(tmp, "w") as f:
                json.dump(self.data, f, indent=2)
            os.replace(tmp, self.path)
        except Exception:
            try:
                os.unlink(tmp)
            except Exception:
                pass
