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

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import json
import os
from datetime import datetime, timezone
from typing import Optional, Set


class BackupCheckpoint:

    VERSION = 1
    JOURNAL_SUFFIX = ".journal"

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
            # The recovery kit is assembled only after every media file has
            # downloaded.  Requiring it here made every mid-download checkpoint
            # impossible to resume.  Stage 3 can resume when its saved inventory,
            # SQL dump and media staging directory still exist.
            timestamp = str(data.get("timestamp", ""))
            blog_name = str(data.get("blog_name", ""))
            inventory = os.path.join(
                backup_dir,
                f"{_filename_token_from_checkpoint(data)}_inventory_{timestamp}.json",
            )
            if (not timestamp or not blog_name or not os.path.isfile(inventory)
                    or not os.path.isfile(data.get("sql_full_path", ""))
                    or not os.path.isdir(data.get("local_media_dir", ""))):
                return None
            cp = cls(path)
            cp.data = data
            cp._replay_journal()
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
            "skipped":          [],
            "files_downloaded": 0,
            "files_skipped":    0,
            "files_failed":     0,
            "created_at":       datetime.now(timezone.utc).isoformat(),
            "updated_at":       datetime.now(timezone.utc).isoformat(),
        }
        self._write()
        try:
            os.unlink(self.path + self.JOURNAL_SUFFIX)
        except FileNotFoundError:
            pass

    def record(self, key: str, downloaded: bool = False,
               skipped: bool = False, failed: bool = False) -> None:
        """Record the outcome for one file.  Flushes to disk immediately."""
        if downloaded:
            if key not in self.data.setdefault("downloaded", []):
                self.data["downloaded"].append(key)
                self.data["files_downloaded"] = self.data.get("files_downloaded", 0) + 1
                self._append_journal(key, "downloaded")
        elif skipped:
            if key not in self.data.setdefault("skipped", []):
                self.data["skipped"].append(key)
                self.data["files_skipped"] = self.data.get("files_skipped", 0) + 1
                self._append_journal(key, "skipped")
        elif failed:
            self.data["files_failed"] = self.data.get("files_failed", 0) + 1
            self._append_journal(key, "failed")
        self.data["updated_at"] = datetime.now(timezone.utc).isoformat()

    def already_downloaded(self) -> Set[str]:
        """Set of file keys already confirmed downloaded."""
        return set(self.data.get("downloaded", []))

    def already_processed(self) -> Set[str]:
        """Files safely completed as either downloaded or unchanged."""
        return self.already_downloaded() | set(self.data.get("skipped", []))

    def delete(self) -> None:
        """Remove checkpoint on successful backup completion."""
        try:
            os.unlink(self.path)
        except Exception:
            pass
        try:
            os.unlink(self.path + self.JOURNAL_SUFFIX)
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

    def _append_journal(self, key: str, outcome: str) -> None:
        """Durably append one tiny completed-file record instead of rewriting
        the entire, ever-growing checkpoint after every download.
        """
        row = json.dumps({
            "key": key,
            "outcome": outcome,
            "at": datetime.now(timezone.utc).isoformat(),
        }, separators=(",", ":"))
        with open(self.path + self.JOURNAL_SUFFIX, "a", encoding="utf-8") as journal:
            journal.write(row + "\n")
            journal.flush()
            os.fsync(journal.fileno())

    def _replay_journal(self) -> None:
        path = self.path + self.JOURNAL_SUFFIX
        if not os.path.isfile(path):
            return
        downloaded = self.data.setdefault("downloaded", [])
        skipped = self.data.setdefault("skipped", [])
        downloaded_set = set(downloaded)
        skipped_set = set(skipped)
        try:
            with open(path, "r", encoding="utf-8") as journal:
                for line in journal:
                    try:
                        row = json.loads(line)
                    except json.JSONDecodeError:
                        continue  # an incomplete final line is safe to ignore
                    key = str(row.get("key", ""))
                    outcome = row.get("outcome")
                    if outcome == "downloaded" and key and key not in downloaded_set:
                        downloaded.append(key); downloaded_set.add(key)
                    elif outcome == "skipped" and key and key not in skipped_set:
                        skipped.append(key); skipped_set.add(key)
                    elif outcome == "failed":
                        self.data["files_failed"] = self.data.get("files_failed", 0) + 1
                    if row.get("at"):
                        self.data["updated_at"] = row["at"]
        except OSError:
            return
        self.data["files_downloaded"] = len(downloaded)
        self.data["files_skipped"] = len(skipped)


def _filename_token_from_checkpoint(data: dict) -> str:
    """Recover the deterministic inventory prefix from a checkpoint path."""
    kit_name = os.path.basename(str(data.get("kit_path", "")))
    marker = "_recovery_kit_"
    if marker in kit_name:
        return kit_name.split(marker, 1)[0]
    return str(data.get("blog_name", "blog"))
# ===== SNAPSMACK EOF =====
