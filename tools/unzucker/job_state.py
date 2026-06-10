"""
Unzucker — job_state.py
Per-import job persistence.

Each import job gets its own .ini file at:
  %APPDATA%\\Unzucker\\jobs\\{job_name}.ini

Sections:
  [job]      — identity: job_name, export_folder, site_url
  [progress] — uploaded = "idx:post_id ..."  |  excluded = "0,3,7,..."
  [trigrams] — t1 = indices:0,1,2 slots:1,2,3 orientation:h
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import configparser
import os
import re
import sys
from typing import Dict, List, Optional, Set


def _jobs_dir() -> str:
    """Jobs folder sits next to the exe (frozen) or the script (dev)."""
    if getattr(sys, 'frozen', False):
        base = os.path.dirname(sys.executable)
    else:
        base = os.path.dirname(os.path.abspath(__file__))
    return os.path.join(base, 'jobs')


_JOBS_DIR = _jobs_dir()
os.makedirs(_JOBS_DIR, exist_ok=True)


# ---------------------------------------------------------------------------
# Job name parser
# ---------------------------------------------------------------------------

def parse_job_name(export_folder: str) -> Optional[str]:
    """
    Try to extract an IG username from the export folder name.

    Handles the standard IG export patterns:
      instagram-username-YYYY-MM-DD-HHMMSS-hash  (most common)
      instagram-username-YYYY-MM-DD
      instagram-username
    Returns None if the folder doesn't match any pattern.
    """
    base = os.path.basename(export_folder.rstrip('/\\'))
    # Strip leading 'instagram[-_]' then take everything before the first date
    m = re.match(
        r'^instagram[-_](.+?)[-_]\d{4}[-_]\d{2}[-_]\d{2}',
        base, re.IGNORECASE,
    )
    if m:
        return m.group(1).strip('-_ ') or None
    # No date — just 'instagram-username'
    m = re.match(r'^instagram[-_](.+)$', base, re.IGNORECASE)
    if m:
        return m.group(1).strip('-_ ') or None
    return None


# ---------------------------------------------------------------------------
# JobState
# ---------------------------------------------------------------------------

class JobState:
    """
    Persists the state of one Unzucker import job.

    Mutation helpers (record_uploaded, save_trigrams, set_excluded) each
    call save() automatically.  Call save() directly if you mutate the
    .uploaded / .excluded / .trigrams attributes by hand.
    """

    def __init__(self, job_name: str, export_folder: str, site_url: str):
        self.job_name      = job_name
        self.export_folder = export_folder
        self.site_url      = site_url
        self._path         = os.path.join(_JOBS_DIR, f"{job_name}.ini")

        self.uploaded: Dict[int, int] = {}   # post_index → post_id
        self.excluded: Set[int]       = set()
        self.trigrams: List[dict]     = []   # [{num, indices, slots, orientation}]

    # ------------------------------------------------------------------
    # Factory — find existing job
    # ------------------------------------------------------------------

    @classmethod
    def find_for_folder(cls, export_folder: str) -> Optional['JobState']:
        """Return the saved JobState for this export folder, or None."""
        try:
            entries = os.listdir(_JOBS_DIR)
        except OSError:
            return None
        for fname in entries:
            if not fname.endswith('.ini'):
                continue
            path = os.path.join(_JOBS_DIR, fname)
            cfg  = configparser.ConfigParser()
            try:
                cfg.read(path, encoding='utf-8')
            except Exception:
                continue
            if cfg.get('job', 'export_folder', fallback='') != export_folder:
                continue
            job_name = cfg.get('job', 'job_name',
                                fallback=os.path.splitext(fname)[0])
            site_url = cfg.get('job', 'site_url', fallback='')
            js       = cls(job_name, export_folder, site_url)
            js._path = path
            js._load_from(cfg)
            return js
        return None

    # ------------------------------------------------------------------
    # Persistence
    # ------------------------------------------------------------------

    def _load_from(self, cfg: configparser.ConfigParser):
        # uploaded: "0:123 1:456 ..."
        for pair in cfg.get('progress', 'uploaded', fallback='').split():
            try:
                raw_idx, raw_pid = pair.split(':')
                self.uploaded[int(raw_idx)] = int(raw_pid)
            except (ValueError, TypeError):
                pass

        # excluded: "0,3,7,..."
        for token in cfg.get('progress', 'excluded', fallback='').split(','):
            token = token.strip()
            if token.isdigit():
                self.excluded.add(int(token))

        # trigrams: t1 = indices:0,1,2 slots:1,2,3 orientation:h
        if cfg.has_section('trigrams'):
            for key, val in cfg['trigrams'].items():
                try:
                    parts: Dict[str, str] = {}
                    for token in val.split():
                        k, v = token.split(':')
                        parts[k] = v
                    num = int(key[1:]) if (key.startswith('t')
                                           and key[1:].isdigit()) else 0
                    if not num:
                        continue
                    self.trigrams.append({
                        'num':         num,
                        'indices':     [int(x) for x in parts['indices'].split(',')],
                        'slots':       [int(x) for x in parts['slots'].split(',')],
                        'orientation': parts.get('orientation', 'h'),
                    })
                except Exception:
                    pass

    def save(self):
        cfg = configparser.ConfigParser()
        cfg['job'] = {
            'job_name':      self.job_name,
            'export_folder': self.export_folder,
            'site_url':      self.site_url,
        }
        cfg['progress'] = {
            'uploaded': ' '.join(
                f"{i}:{p}" for i, p in sorted(self.uploaded.items())),
            'excluded': ','.join(str(i) for i in sorted(self.excluded)),
        }
        cfg['trigrams'] = {
            f"t{grp['num']}": (
                f"indices:{','.join(str(i) for i in grp['indices'])} "
                f"slots:{','.join(str(s) for s in grp['slots'])} "
                f"orientation:{grp.get('orientation', 'h')}"
            )
            for grp in self.trigrams
        }
        with open(self._path, 'w', encoding='utf-8') as fh:
            cfg.write(fh)

    def delete(self):
        try:
            os.unlink(self._path)
        except OSError:
            pass

    # ------------------------------------------------------------------
    # Mutation helpers
    # ------------------------------------------------------------------

    def record_uploaded(self, index: int, post_id: int):
        """Record a successfully uploaded post and save immediately."""
        self.uploaded[index] = post_id
        self.save()

    def save_trigrams(self, groups: list):
        """Replace the full trigram list (after any index remap) and save."""
        self.trigrams = list(groups)
        self.save()

    def set_excluded(self, indices: Set[int]):
        """Persist the current excluded index set."""
        self.excluded = set(indices)
        self.save()

    # ------------------------------------------------------------------
    # Properties
    # ------------------------------------------------------------------

    @property
    def has_progress(self) -> bool:
        return bool(self.uploaded)

    @property
    def upload_count(self) -> int:
        return len(self.uploaded)
# ===== SNAPSMACK EOF =====
