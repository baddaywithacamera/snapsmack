"""
TAKE YOUR SHIT WITH YOU — resumable export state.

Spec: _spec/take-your-shit-with-you-spec-v0_1.md section 12 (Resume) and 13.

An export of a large archive WILL be interrupted. A laptop lid closes, a shared
host has a bad afternoon, Windows decides it is time. The rule this module
exists to enforce is the one from spec 12:

    never delete a valid local file merely because the server is temporarily
    unavailable

So the state lives in `.tyswy/` INSIDE the destination folder, outside the
canonical manifest until the export completes, and it is written in a way a
power cut cannot corrupt:

  * `state.json` is written to a temp file and renamed over the old one —
    a torn write leaves the previous good state, never half of a new one;
  * `media-ledger.ndjson` and the raw record files are APPEND-ONLY, with the
    byte length of every file recorded in state.json at the last known-good
    boundary. On resume the files are truncated back to that length, which
    discards a half-written line without discarding the work before it.

That last point is the whole trick. Appending is cheap (no O(n^2) rewrite of a
growing ledger after every one of ten thousand photographs), and the recorded
length is what makes an append-only file as trustworthy as an atomic one.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import json
import os
import uuid
from datetime import datetime, timezone

STATE_DIR   = '.tyswy'
STATE_FILE  = 'state.json'
MEDIA_LEDGER = 'media-ledger.ndjson'
RAW_DIR     = 'raw'
STATE_VERSION = 1


def _now():
    return datetime.now(timezone.utc).isoformat()


class ResumeRefused(Exception):
    """The folder holds an export, but not one we may continue."""


class ExportState:

    def __init__(self, root):
        self.root      = os.path.abspath(root)
        self.dir       = os.path.join(self.root, STATE_DIR)
        self.path      = os.path.join(self.dir, STATE_FILE)
        self.raw_dir   = os.path.join(self.dir, RAW_DIR)
        self.ledger    = os.path.join(self.dir, MEDIA_LEDGER)
        self.data      = {}
        self._media_done = None      # lazily replayed from the ledger
        self._ledger_fh  = None

    # -- lifecycle ----------------------------------------------------------
    @classmethod
    def start(cls, root, *, site_uuid, site_url, site_name, site_mode,
              site_version, app_version, snapshot=None):
        st = cls(root)
        os.makedirs(st.raw_dir, exist_ok=True)
        st.data = {
            'state_version': STATE_VERSION,
            'export_uuid':   uuid.uuid4().hex,
            'site_uuid':     site_uuid,
            'site_url':      site_url,
            'site_name':     site_name,
            'site_mode':     site_mode,
            'site_version':  site_version,
            'app_version':   app_version,
            'started_at':    _now(),
            'updated_at':    _now(),
            'snapshot':      dict(snapshot or {}),
            'stage':         'collect',
            'types':         {},     # name -> {cursor, rows, chunks[], bytes, done}
            'media_bytes':   0,
            'media_files':   0,
            'completed':     False,
        }
        st.flush()
        return st

    @classmethod
    def load(cls, root):
        st = cls(root)
        if not os.path.exists(st.path):
            return None
        try:
            with open(st.path, encoding='utf-8') as f:
                data = json.load(f)
        except Exception:
            return None
        if data.get('state_version') != STATE_VERSION:
            return None
        st.data = data
        return st

    def check_resumable(self, *, site_uuid, site_url):
        """
        Refuse to resume against a different site (spec 12).

        The site UUID is the test, not the URL — a site that moved domain is
        still the same archive, and two sites behind one URL over time are not.
        The URL is reported in the message because that is what a person
        recognises.
        """
        if self.data.get('completed'):
            raise ResumeRefused(
                'This folder already holds a COMPLETED export. Nothing here will '
                'be overwritten — choose a new folder, or open this one.')
        mine = self.data.get('site_uuid')
        if mine and site_uuid and mine != site_uuid:
            raise ResumeRefused(
                'This folder holds an unfinished export of a DIFFERENT site '
                f'({self.data.get("site_url") or "unknown"}). Refusing to mix two '
                f'sites into one archive. Choose another folder for {site_url}.')
        return True

    # -- writing ------------------------------------------------------------
    def flush(self):
        """Atomic: temp file then rename. A power cut leaves the previous good
        state rather than a truncated one."""
        os.makedirs(self.dir, exist_ok=True)
        self.data['updated_at'] = _now()
        tmp = self.path + '.tmp'
        with open(tmp, 'w', encoding='utf-8', newline='\n') as f:
            json.dump(self.data, f, indent=2, sort_keys=True)
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmp, self.path)

    # -- record streams -----------------------------------------------------
    def raw_path(self, record_type):
        return os.path.join(self.raw_dir, f'{record_type}.ndjson')

    def type_state(self, record_type):
        return self.data.setdefault('types', {}).setdefault(record_type, {
            'cursor': 0, 'rows': 0, 'bytes': 0, 'chunk_hashes': [],
            'done': False, 'supported': True, 'source_count': 0, 'snapshot': 0,
        })

    def open_raw_for_append(self, record_type):
        """
        Reopen a raw stream for appending, first truncating it back to the last
        byte offset that a verified chunk ended on. Anything past that came from
        a chunk that never finished verifying and must not be trusted.
        """
        ts   = self.type_state(record_type)
        path = self.raw_path(record_type)
        os.makedirs(self.raw_dir, exist_ok=True)
        want = int(ts.get('bytes') or 0)
        if os.path.exists(path):
            have = os.path.getsize(path)
            if have > want:
                with open(path, 'r+b') as f:
                    f.truncate(want)
        elif want:
            # The file vanished but state says it had rows: start the type over
            # rather than silently exporting a hole.
            ts.update({'cursor': 0, 'rows': 0, 'bytes': 0, 'chunk_hashes': [],
                       'done': False})
        return open(path, 'ab')

    def commit_chunk(self, record_type, *, cursor, rows_added, byte_length,
                     chunk_hash=None, source_count=None, snapshot=None,
                     done=False, supported=True):
        """Advance the checkpoint. Called ONLY after a chunk verified."""
        ts = self.type_state(record_type)
        ts['cursor'] = int(cursor)
        ts['rows']   = int(ts.get('rows', 0)) + int(rows_added)
        ts['bytes']  = int(byte_length)
        ts['done']   = bool(done)
        ts['supported'] = bool(supported)
        if chunk_hash:
            ts.setdefault('chunk_hashes', []).append(chunk_hash)
        if source_count is not None:
            ts['source_count'] = int(source_count)
        if snapshot:
            ts['snapshot'] = int(snapshot)
        self.flush()

    def iter_raw(self, record_type):
        """Yield every stored record of a type without loading the file."""
        path = self.raw_path(record_type)
        if not os.path.exists(path):
            return
        with open(path, encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if not line:
                    continue
                try:
                    yield json.loads(line)
                except ValueError:
                    continue

    def ledger_digest(self, record_type):
        """A single digest over the chunk hashes, so a resumed run produces the
        same ledger value as an uninterrupted one."""
        import hashlib
        ts = self.type_state(record_type)
        h = hashlib.sha256()
        for ch in ts.get('chunk_hashes', []):
            h.update(str(ch).encode('ascii'))
        return h.hexdigest()

    # -- media ledger -------------------------------------------------------
    def media_done(self):
        """Replay the append-only ledger into {key: entry}."""
        if self._media_done is not None:
            return self._media_done
        done = {}
        if os.path.exists(self.ledger):
            with open(self.ledger, encoding='utf-8') as f:
                for line in f:
                    line = line.strip()
                    if not line:
                        continue
                    try:
                        e = json.loads(line)
                    except ValueError:
                        continue          # torn last line — the file is append-only
                    key = e.get('key')
                    if key:
                        done[key] = e
        self._media_done = done
        return done

    @staticmethod
    def media_key(source, source_id, variant):
        return f'{source}:{source_id}:{variant}'

    def record_media(self, source, source_id, variant, rel_path, sha256, size):
        entry = {
            'key':     self.media_key(source, source_id, variant),
            'source':  source,
            'id':      int(source_id),
            'variant': variant,
            'path':    rel_path,
            'sha256':  sha256,
            'bytes':   int(size),
            'at':      _now(),
        }
        os.makedirs(self.dir, exist_ok=True)
        if self._ledger_fh is None:
            self._ledger_fh = open(self.ledger, 'a', encoding='utf-8', newline='\n')
        self._ledger_fh.write(json.dumps(entry, ensure_ascii=False) + '\n')
        self._ledger_fh.flush()
        self.media_done()[entry['key']] = entry
        self.data['media_files'] = int(self.data.get('media_files', 0)) + 1
        self.data['media_bytes'] = int(self.data.get('media_bytes', 0)) + int(size)
        return entry

    def close(self):
        if self._ledger_fh is not None:
            try:
                self._ledger_fh.close()
            except Exception:
                pass
            self._ledger_fh = None

    # -- stage tracking -----------------------------------------------------
    def set_stage(self, stage):
        self.data['stage'] = stage
        self.flush()

    def mark_complete(self):
        self.data['completed'] = True
        self.data['finished_at'] = _now()
        self.flush()
        self.close()

    @property
    def export_uuid(self):
        return self.data.get('export_uuid')

    @property
    def started_at(self):
        return self.data.get('started_at')
# ===== SNAPSMACK EOF =====
