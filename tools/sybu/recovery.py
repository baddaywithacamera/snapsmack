"""
Smack Your Batch Up — recovery.py

Crash-safe, incremental persistence of Gemini enrichment + post status.

WHY THIS EXISTS
    Before 0.7.9k, enrichment (title / tags / category / album / orientation /
    colors per image) lived ONLY in the in-memory Tkinter rows. A crash, hang,
    or accidental close before the batch finished posting threw away every
    paid Gemini call. (0.7.9j hung on "Checking session…" after ~$1 of spend
    and lost all 154 items — see _continuity/sybu-0.7.9j-crash-and-recovery.)

WHAT IT DOES
    As each image finishes enriching, its full enriched fields are upserted into
    a per-folder JSON file under  <exe dir>/recovery/sybu_recovery_<jobid>.json
    via an atomic temp-write + os.replace, so a crash mid-write cannot corrupt
    it. On reloading the same folder, the app offers to restore and skip
    re-enriching those items (the dollar-saver). Items are marked 'ok' as they
    post; the file is pruned once the whole batch is posted.

KEYING
    jobid  = short hash of the absolute, case-normalised image-folder path
             (one recovery file per source folder).
    item   = "<filename>|<size>|<mtime>" so a changed/replaced file is NOT
             falsely matched to stale enrichment.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import hashlib
import json
import os
import sys
import time


# Fields persisted per item. Mirrors manifest_parser.ManifestEntry (minus file,
# which is stored separately, and line_num, which is source-only).
FIELDS = ('title', 'caption', 'tags', 'category', 'album', 'orientation', 'colors')


def _base_dir() -> str:
    """Directory next to the exe (frozen) or this source file (dev)."""
    if getattr(sys, 'frozen', False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


def _recovery_dir() -> str:
    d = os.path.join(_base_dir(), 'recovery')
    try:
        os.makedirs(d, exist_ok=True)
    except OSError:
        pass
    return d


def folder_jobid(image_folder: str) -> str:
    """Stable short id for a folder path (case-insensitive, absolute)."""
    norm = os.path.normcase(os.path.abspath(image_folder))
    return hashlib.sha1(norm.encode('utf-8')).hexdigest()[:16]


def item_key(image_folder: str, filename: str) -> str:
    """Identity for one image: name + size + mtime. Changed file => new key."""
    path = os.path.join(image_folder, filename)
    try:
        st = os.stat(path)
        return f"{filename}|{st.st_size}|{int(st.st_mtime)}"
    except OSError:
        # File missing/unreadable — fall back to name only so we still match by
        # name when stat is unavailable (e.g. network blip).
        return f"{filename}|0|0"


class RecoveryStore:
    """One recovery file for one image folder."""

    def __init__(self, image_folder: str):
        self.image_folder = image_folder
        self.jobid = folder_jobid(image_folder)
        self.path = os.path.join(_recovery_dir(), f"sybu_recovery_{self.jobid}.json")
        self.data = {'image_folder': image_folder, 'updated': None, 'items': {}}

    # ── persistence ────────────────────────────────────────────────────────
    def exists(self) -> bool:
        return os.path.isfile(self.path)

    def load(self) -> 'RecoveryStore':
        try:
            with open(self.path, 'r', encoding='utf-8') as f:
                loaded = json.load(f)
            if isinstance(loaded, dict):
                self.data = loaded
        except (OSError, ValueError):
            pass  # corrupt/missing — keep the empty default
        self.data.setdefault('items', {})
        self.data.setdefault('image_folder', self.image_folder)
        return self

    def _save(self) -> None:
        """Atomic write: temp file + fsync + os.replace (crash-safe)."""
        self.data['updated'] = time.strftime('%Y-%m-%d %H:%M:%S')
        tmp = self.path + '.tmp'
        try:
            with open(tmp, 'w', encoding='utf-8') as f:
                json.dump(self.data, f, ensure_ascii=False, separators=(',', ':'))
                f.flush()
                os.fsync(f.fileno())
            os.replace(tmp, self.path)
        except OSError:
            # Never let a recovery-write failure crash the app or the batch.
            try:
                if os.path.isfile(tmp):
                    os.remove(tmp)
            except OSError:
                pass

    # ── mutation ───────────────────────────────────────────────────────────
    def upsert(self, entry, status: str = 'enriched') -> None:
        """Store/refresh one item's enriched fields, then flush to disk."""
        key = item_key(self.image_folder, entry.file)
        rec = self.data['items'].get(key, {})
        rec['file'] = entry.file
        for fld in FIELDS:
            rec[fld] = getattr(entry, fld, '') or ''
        rec['status'] = status
        self.data['items'][key] = rec
        self._save()

    def mark_status(self, entry, status: str) -> None:
        """Update only the status of an existing item (e.g. 'ok' after posting)."""
        key = item_key(self.image_folder, entry.file)
        rec = self.data['items'].get(key)
        if rec is None:
            # Item was never enriched (posted raw) — record it anyway so prune works.
            rec = {'file': entry.file}
            for fld in FIELDS:
                rec[fld] = getattr(entry, fld, '') or ''
            self.data['items'][key] = rec
        rec['status'] = status
        self._save()

    # ── query ──────────────────────────────────────────────────────────────
    def lookup(self, entry) -> dict:
        return self.data['items'].get(item_key(self.image_folder, entry.file))

    def enriched_count(self) -> int:
        return sum(1 for r in self.data['items'].values()
                   if r.get('status') in ('enriched', 'ok') and (r.get('title') or r.get('tags')))

    def enriched_count_for(self, entries) -> int:
        """How many of the CURRENT entries have a matching saved enrichment.

        Counts against the live folder via the same key lookup that restore_into
        uses — NOT the raw store. Stale records for files that were deleted,
        renamed, or re-exported since the last run no longer inflate the number
        (the "8 of 7" bug) and we never promise more is restorable than the
        entries that will actually match and restore."""
        n = 0
        for entry in entries:
            rec = self.lookup(entry)
            if rec and rec.get('status') in ('enriched', 'ok') and (rec.get('title') or rec.get('tags')):
                n += 1
        return n

    def restore_into(self, entries) -> int:
        """Copy stored enrichment into matching entries in place. Returns count."""
        n = 0
        for entry in entries:
            rec = self.lookup(entry)
            if not rec:
                continue
            touched = False
            for fld in FIELDS:
                val = rec.get(fld)
                if val:
                    setattr(entry, fld, val)
                    touched = True
            if touched:
                n += 1
        return n

    def all_posted(self) -> bool:
        items = list(self.data['items'].values())
        return bool(items) and all(r.get('status') == 'ok' for r in items)

    def delete(self) -> None:
        try:
            os.remove(self.path)
        except OSError:
            pass

# ===== SNAPSMACK EOF =====
