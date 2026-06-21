"""
Smack Up Your Backup — sync_manifest.py
Persistent record of every successfully verified file transfer.

Manifest JSON format:
{
  "version": 1,
  "job_name": "...",
  "updated_at": "2024-...",
  "files": {
    "photo.jpg": {
      "size":        1234567,
      "drive_md5":   "abc...",   # MD5 from Drive API (null for native GDocs)
      "b2_sha1":     "def...",   # SHA1 confirmed by B2 on upload
      "verified_at": "2024-..."
    }
  }
}

Stored locally at: C:\\SmackUpYourBackup\\manifests\\{job-name}-manifest.json
Stored remotely at: _suyb_manifest.json in the B2 bucket root

On each run: whichever copy is newer wins.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import json
import os
from datetime import datetime, timezone
from typing import Optional


MANIFEST_B2_FILENAME = "_suyb_manifest.json"


def _safe_name(name: str) -> str:
    return "".join(c if c.isalnum() or c in " .-_" else "_" for c in name).strip()


class SyncManifest:
    """Persistent record of successfully verified file transfers."""

    VERSION = 1

    def __init__(self, job_name: str, local_dir: str):
        self._job_name  = job_name
        self._local_dir = local_dir
        self._path      = os.path.join(
            local_dir, f"{_safe_name(job_name)}-manifest.json"
        )
        self._data: dict = {
            "version":    self.VERSION,
            "job_name":   job_name,
            "updated_at": "",
            "files":      {},
        }

    # ------------------------------------------------------------------
    # Load / save
    # ------------------------------------------------------------------

    @property
    def path(self) -> str:
        return self._path

    def load(self) -> bool:
        """Load manifest from disk. Returns True if loaded successfully."""
        if not os.path.exists(self._path):
            return False
        try:
            with open(self._path, encoding="utf-8") as f:
                data = json.load(f)
            if isinstance(data, dict) and "files" in data:
                self._data = data
                return True
        except Exception:
            pass
        return False

    def save(self) -> None:
        """Write manifest to disk."""
        os.makedirs(self._local_dir, exist_ok=True)
        self._data["updated_at"] = datetime.now(timezone.utc).isoformat()
        with open(self._path, "w", encoding="utf-8") as f:
            json.dump(self._data, f, indent=2)

    def upload_to_b2(self, b2_client) -> None:
        """Save locally then upload a copy to the B2 bucket root."""
        self.save()
        try:
            b2_client.upload_manifest(self._path, MANIFEST_B2_FILENAME)
        except Exception as e:
            print(f"[manifest] Warning: could not upload manifest to B2: {e}")

    def try_load_from_b2(self, b2_client) -> bool:
        """Download manifest from B2 if it exists and is newer than local copy.
        Returns True if B2 copy was loaded."""
        tmp = self._path + ".b2_download"
        try:
            b2_client.download_manifest(MANIFEST_B2_FILENAME, tmp)
        except Exception:
            return False  # doesn't exist on B2 yet — that's fine

        try:
            # Compare timestamps — use whichever is newer
            local_mtime = (
                os.path.getmtime(self._path) if os.path.exists(self._path) else 0
            )
            b2_mtime = os.path.getmtime(tmp)
            if b2_mtime > local_mtime:
                if os.path.exists(self._path):
                    os.replace(tmp, self._path)
                else:
                    os.rename(tmp, self._path)
                return self.load()
            else:
                os.remove(tmp)
                return False
        except Exception as e:
            print(f"[manifest] Warning during B2 manifest merge: {e}")
            try:
                os.remove(tmp)
            except Exception:
                pass
            return False

    # ------------------------------------------------------------------
    # Query / update
    # ------------------------------------------------------------------

    def get(self, filename: str) -> Optional[dict]:
        """Return manifest entry for a filename, or None."""
        return self._data.get("files", {}).get(filename)

    def is_current(self, filename: str, size: int,
                   drive_md5: Optional[str]) -> bool:
        """
        Return True only if this file is PROVABLY unchanged and can be skipped:
          - present in the manifest with matching size, AND
          - a stored MD5 and the current source MD5 are BOTH present and equal.
        A size match alone is NOT enough — a changed file can keep the same byte
        size — so any file lacking an MD5 on either side is re-verified rather
        than silently skipped.
        """
        entry = self.get(filename)
        if not entry:
            return False
        if entry.get("size") != size:
            return False
        # Require a real content hash on both sides to skip. If either MD5 is
        # missing (e.g. Google-native files expose none), we cannot prove the
        # bytes are identical from size alone — force a re-verify.
        em = entry.get("drive_md5")
        if not drive_md5 or not em:
            return False
        if drive_md5 != em:
            return False
        return True

    def update(self, filename: str, size: int,
               drive_md5: Optional[str], b2_sha1: Optional[str]) -> None:
        """Record a successfully verified transfer."""
        if "files" not in self._data:
            self._data["files"] = {}
        self._data["files"][filename] = {
            "size":        size,
            "drive_md5":   drive_md5,
            "b2_sha1":     b2_sha1,
            "verified_at": datetime.now(timezone.utc).isoformat(),
        }

    def remove(self, filename: str) -> None:
        """Remove a file from the manifest (force re-transfer on next run)."""
        self._data.get("files", {}).pop(filename, None)

    def file_count(self) -> int:
        return len(self._data.get("files", {}))
# ===== SNAPSMACK EOF =====
