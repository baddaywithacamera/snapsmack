"""
A stand-in for core/tyswy-api.php, good enough to test the client and the engine
against without a database or a web server.

It is deliberately a REAL implementation of the wire format rather than a mock
that returns canned objects: the properties worth testing here are things like
"a chunk with no footer is discarded" and "a resumed stream picks up at the last
verified id", and a mock that just hands back a list proves neither. So this
paginates by after_id, hashes each record the way PHP does, and emits a header
and a footer — and the tests then break it on purpose.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import hashlib
import json
import os
import sys
from urllib.parse import parse_qs, urlsplit

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from tyswy_client import canonical  # noqa: E402


class FakeResponse:

    def __init__(self, *, status=200, lines=None, body=b'', headers=None,
                 json_body=None):
        self.status_code = status
        self._lines = list(lines or [])
        self._body  = body
        self.headers = dict(headers or {})
        self._json  = json_body
        self.closed = False

    def iter_lines(self, decode_unicode=False):
        for line in self._lines:
            yield line if isinstance(line, bytes) else line.encode('utf-8')

    def iter_content(self, chunk_size=8192):
        for i in range(0, len(self._body), chunk_size):
            yield self._body[i:i + chunk_size]

    def json(self):
        if self._json is None:
            raise ValueError('no json')
        return self._json

    def close(self):
        self.closed = True


class FakeSite:
    """
    A tiny SnapSmack. `tables` is {record_type: [row dicts]} — rows must carry
    the id column the real registry uses.
    """

    ID_COLUMN = {
        'image_category_memberships': 'image_id',
        'image_album_memberships':    'image_id',
        'post_category_memberships':  'post_id',
        'post_album_memberships':     'post_id',
    }

    def __init__(self, tables=None, media=None, *, site_uuid='site-abc',
                 site_name='it is a fauxlaroid, fyi',
                 site_url='https://fauxlaroid.fyi', site_mode='carousel',
                 settings_public=None):
        # The client treats this object as a requests.Session, so it needs the
        # bits of that interface it actually touches.
        self.headers = {}
        self.tables = {k: list(v) for k, v in (tables or {}).items()}
        self.media  = dict(media or {})          # (source, id, variant) -> bytes
        self.site_uuid = site_uuid
        self.site_name = site_name
        self.site_url  = site_url
        self.site_mode = site_mode
        self.settings_public = settings_public or {'site_name': site_name}
        self.requests = []
        # Test hooks: break the wire on purpose.
        self.drop_footer_for   = set()   # record types whose footer goes missing
        self.corrupt_digest_for = set()  # record types with a lying per-record hash
        self.fail_media_once   = set()   # (source, id) that 404s the first time
        self._failed_once      = set()
        self.max_chunk         = 500

    # -- helpers ------------------------------------------------------------
    def _id_col(self, rtype):
        return self.ID_COLUMN.get(rtype, 'id')

    def _envelope(self):
        return {
            'ok': True, 'api_version': '0.1', 'site_uuid': self.site_uuid,
            'site_mode': self.site_mode, 'site_version': '0.7.505',
            'generated_at': '2026-08-07T00:00:00+00:00', 'request_id': 'test',
        }

    # -- the surface --------------------------------------------------------
    def get(self, url, timeout=None, stream=False, headers=None):
        q = parse_qs(urlsplit(url).query)
        action = (q.get('action') or ['preflight'])[0]
        self.requests.append((action, {k: v[0] for k, v in q.items()}))
        fn = getattr(self, '_a_' + action, None)
        if fn is None:
            return FakeResponse(status=400, json_body={
                'ok': False, 'error': {'code': 'unknown_action',
                                       'message': 'Unknown action.',
                                       'retryable': False},
                'request_id': 'test'})
        return fn(q, headers or {})

    def _a_preflight(self, q, headers):
        types = {}
        for rtype, rows in self.tables.items():
            idc = self._id_col(rtype)
            types[rtype] = {
                'supported': True, 'count': len(rows),
                'max_id': max([int(r[idc]) for r in rows], default=0),
            }
        body = dict(self._envelope())
        body.update({
            'site_name': self.site_name, 'site_url': self.site_url,
            'stream_format': 1, 'chunk_default': 500, 'chunk_max': 2000,
            'types': types,
            'media_variants': ['original', 'web', 'thumb'],
            'media_sources': ['image', 'asset'],
            'media_variant_notes': {'original': 'one stored file per image'},
            'media_bytes_estimate': None,
            'settings_public': self.settings_public,
            'excluded_classes': ['password hashes', 'API keys and OAuth tokens'],
            'included_sensitive_classes': ['image EXIF including GPS coordinates'],
            'key_scope': 'tyswy:read-only',
        })
        return FakeResponse(json_body=body)

    def _a_stream(self, q, headers):
        rtype = (q.get('type') or [''])[0]
        if rtype not in self.tables:
            return FakeResponse(lines=[
                json.dumps({'record_type': rtype, 'supported': False,
                            'stream_format': 1}),
                json.dumps({'footer': True, 'rows': 0, 'last_id': 0,
                            'has_more': False, 'complete': True}),
            ])
        idc      = self._id_col(rtype)
        rows     = sorted(self.tables[rtype], key=lambda r: int(r[idc]))
        after_id = int((q.get('after_id') or ['0'])[0])
        limit    = min(int((q.get('limit') or ['500'])[0]), self.max_chunk)
        max_id   = max([int(r[idc]) for r in rows], default=0)
        snapshot = int((q.get('snapshot') or ['0'])[0]) or max_id

        window = [r for r in rows if after_id < int(r[idc]) <= snapshot][:limit]
        lines = [json.dumps({
            'record_type': rtype, 'supported': True, 'snapshot': snapshot,
            'after_id': after_id, 'source_count': len(rows),
            'source_max_id': max_id, 'stream_format': 1,
            'columns': sorted(rows[0].keys()) if rows else [],
        })]
        rolling = hashlib.sha256()
        last_id = after_id
        for r in window:
            digest = hashlib.sha256(canonical(r).encode('utf-8')).hexdigest()
            sent   = digest
            if rtype in self.corrupt_digest_for:
                sent = 'f' * 64
            rolling.update(digest.encode('ascii'))
            lines.append(json.dumps({'id': int(r[idc]), 'sha256': sent, 'record': r}))
            last_id = int(r[idc])
        if rtype not in self.drop_footer_for:
            lines.append(json.dumps({
                'footer': True, 'rows': len(window), 'last_id': last_id,
                'has_more': last_id < snapshot and len(window) == limit,
                'stream_sha256': rolling.hexdigest(),
                'source_count': len(rows), 'source_max_id': max_id,
                'complete': True}))
        return FakeResponse(lines=lines)

    def _a_verify(self, q, headers):
        snapshot = int((q.get('snapshot') or ['0'])[0])
        types = {}
        for rtype, rows in self.tables.items():
            idc = self._id_col(rtype)
            entry = {'supported': True, 'count': len(rows),
                     'max_id': max([int(r[idc]) for r in rows], default=0)}
            if snapshot:
                entry['count_at_snapshot'] = len(
                    [r for r in rows if int(r[idc]) <= snapshot])
            types[rtype] = entry
        body = dict(self._envelope())
        body.update({'snapshot': snapshot, 'types': types})
        return FakeResponse(json_body=body)

    def _a_changes(self, q, headers):
        body = dict(self._envelope())
        body.update({'since': (q.get('since') or [''])[0],
                     'types': {t: {'method': 'restream', 'changed_ids': None}
                               for t in self.tables}})
        return FakeResponse(json_body=body)

    def _a_record(self, q, headers):
        rtype = (q.get('type') or [''])[0]
        rid   = int((q.get('id') or ['0'])[0])
        idc   = self._id_col(rtype)
        for r in self.tables.get(rtype, []):
            if int(r[idc]) == rid:
                body = dict(self._envelope())
                body.update({'record_type': rtype, 'id': rid, 'record': r,
                             'sha256': hashlib.sha256(
                                 canonical(r).encode('utf-8')).hexdigest()})
                return FakeResponse(json_body=body)
        return FakeResponse(status=404, json_body={
            'ok': False, 'error': {'code': 'not_found', 'message': 'No such record.',
                                   'retryable': False}, 'request_id': 'test'})

    def close(self):
        pass

    def _a_media(self, q, headers):
        source  = (q.get('source') or ['image'])[0]
        mid     = int((q.get('id') or ['0'])[0])
        variant = (q.get('variant') or ['original'])[0]
        key = (source, mid, variant)
        if (source, mid) in self.fail_media_once and (source, mid) not in self._failed_once:
            self._failed_once.add((source, mid))
            return FakeResponse(status=404, json_body={
                'ok': False, 'error': {'code': 'file_missing',
                                       'message': 'The file for that image is not on disk.',
                                       'retryable': False}, 'request_id': 'test'})
        blob = self.media.get(key)
        if blob is None:
            return FakeResponse(status=404, json_body={
                'ok': False, 'error': {'code': 'not_found', 'message': 'No such image.',
                                       'retryable': False}, 'request_id': 'test'})
        rng = (headers or {}).get('Range')
        if rng and rng.startswith('bytes='):
            start = int(rng.split('=', 1)[1].split('-')[0] or 0)
            part  = blob[start:]
            return FakeResponse(
                status=206, body=part,
                headers={'Content-Length': str(len(part)),
                         'Content-Range': f'bytes {start}-{len(blob) - 1}/{len(blob)}'})
        return FakeResponse(status=200, body=blob,
                            headers={'Content-Length': str(len(blob))})
# ===== SNAPSMACK EOF =====
