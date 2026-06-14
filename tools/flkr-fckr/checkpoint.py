"""
FLKR FCKR — checkpoint.py
Atomic crash-recovery checkpoint for the import pipeline.

Adapted from tools/smack-up-your-backup/checkpoint.py — simplified
for FLKR FCKR's needs: tracks which Flickr IDs have been imported and
their corresponding SnapSmack image IDs.

Written after every successful photo import. If the process is killed
mid-import, the checkpoint survives on disk. On next launch FLKR FCKR
detects it and offers to resume rather than restart from scratch.

Atomic write: temp file + rename — safe against power cuts mid-write.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import json
import os
import sys
from datetime import datetime, timezone
from typing import Dict, Optional


class ImportCheckpoint:

    VERSION = 1

    def __init__(self, path: str):
        self.path = path
        self.data: dict = {}

    # ------------------------------------------------------------------
    # Factory helpers
    # ------------------------------------------------------------------

    @classmethod
    def path_for(cls) -> str:
        """Return checkpoint path alongside the exe / script."""
        if getattr(sys, 'frozen', False):
            base = os.path.dirname(sys.executable)
        else:
            base = os.path.dirname(os.path.abspath(__file__))
        return os.path.join(base, 'flkrfckr_checkpoint.json')

    @classmethod
    def load(cls) -> Optional['ImportCheckpoint']:
        """Return an existing checkpoint, or None if none exists / is corrupt."""
        path = cls.path_for()
        if not os.path.exists(path):
            return None
        try:
            with open(path, 'r', encoding='utf-8') as f:
                data = json.load(f)
            if data.get('version') != cls.VERSION:
                return None
            cp = cls(path)
            cp.data = data
            return cp
        except Exception:
            return None

    # ------------------------------------------------------------------
    # Lifecycle
    # ------------------------------------------------------------------

    def start(self, export_folder: str, site_url: str, total_photos: int) -> None:
        """Initialise a fresh checkpoint at the beginning of an import run."""
        self.data = {
            'version':       self.VERSION,
            'export_folder': export_folder,
            'site_url':      site_url,
            'total_photos':  total_photos,
            'imported':      {},   # flickr_id → snapsmack_image_id
            'failed':        [],   # flickr_ids that errored
            'skipped':       [],   # flickr_ids explicitly skipped
            'created_at':    datetime.now(timezone.utc).isoformat(),
            'updated_at':    datetime.now(timezone.utc).isoformat(),
        }
        self._write()

    def update_total(self, total_photos: int) -> None:
        """Update the expected photo count (e.g. when resuming a run)."""
        self.data['total_photos'] = total_photos
        self.data['updated_at'] = datetime.now(timezone.utc).isoformat()
        self._write()

    def record_imported(self, flickr_id: str, snapsmack_image_id: int) -> None:
        """Record a successfully imported photo. Flushes to disk immediately."""
        self.data.setdefault('imported', {})[flickr_id] = snapsmack_image_id
        # Clear any prior failure for this id so a successful retry lets the
        # checkpoint auto-delete on completion instead of lingering forever.
        failed = self.data.get('failed')
        if failed and flickr_id in failed:
            failed.remove(flickr_id)
        self.data['updated_at'] = datetime.now(timezone.utc).isoformat()
        self._write()

    def record_failed(self, flickr_id: str) -> None:
        """Record a failed photo. Flushes to disk."""
        self.data.setdefault('failed', [])
        if flickr_id not in self.data['failed']:
            self.data['failed'].append(flickr_id)
        self.data['updated_at'] = datetime.now(timezone.utc).isoformat()
        self._write()

    def record_skipped(self, flickr_id: str) -> None:
        """Record a skipped (excluded) photo. Flushes to disk."""
        self.data.setdefault('skipped', [])
        if flickr_id not in self.data['skipped']:
            self.data['skipped'].append(flickr_id)
        self.data['updated_at'] = datetime.now(timezone.utc).isoformat()
        self._write()

    def already_imported(self) -> Dict[str, int]:
        """Dict of flickr_id → snapsmack_image_id for already-imported photos."""
        return dict(self.data.get('imported', {}))

    def progress(self) -> dict:
        """Summary stats for display."""
        return {
            'total':    self.data.get('total_photos', 0),
            'imported': len(self.data.get('imported', {})),
            'failed':   len(self.data.get('failed', [])),
            'skipped':  len(self.data.get('skipped', [])),
        }

    def delete(self) -> None:
        """Remove checkpoint on successful import completion."""
        try:
            os.unlink(self.path)
        except Exception:
            pass

    # ------------------------------------------------------------------
    # Internals
    # ------------------------------------------------------------------

    def _write(self) -> None:
        """Atomic write: temp file → rename. Safe against power cuts."""
        tmp = self.path + '.tmp'
        try:
            with open(tmp, 'w', encoding='utf-8') as f:
                json.dump(self.data, f, indent=2)
            os.replace(tmp, self.path)
        except Exception:
            try:
                os.unlink(tmp)
            except Exception:
                pass
# ===== SNAPSMACK EOF =====
