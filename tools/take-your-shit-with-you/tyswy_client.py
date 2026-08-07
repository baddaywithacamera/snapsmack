"""
TAKE YOUR SHIT WITH YOU — the client half of the export API.

Spec: _spec/take-your-shit-with-you-spec-v0_1.md sections 5, 6, 12.

This module is the ONLY thing here that touches the network. It speaks to
`core/tyswy-api.php` and nothing else, it never writes to the site, and it hands
back verified records and verified bytes. The archive format (portable_archive)
and the orchestration (export_engine) both sit above it and stay testable
without a server.

THE VERIFICATION RULE. The server is not trusted to tell us it succeeded. Every
chunk is checked three ways before a single row of it is allowed to advance a
checkpoint:

  1. per-record — the record is re-serialised canonically here and its SHA-256
     compared with the one the server sent;
  2. per-stream — a rolling hash over those digests must match the footer;
  3. structural — a chunk with no footer is a TRUNCATED chunk, and a truncated
     chunk is discarded whole. That is the property that makes a dropped
     connection safe: an interrupted stream can never be mistaken for a short
     but complete one.

CANONICAL SERIALISATION must match PHP's `tyswy_canonical()` byte for byte or
every record fails verification. PHP uses `ksort` + `json_encode` with
JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE. The Python equivalent is
sort_keys + compact separators + ensure_ascii=False, with ONE divergence to
correct: PHP still escapes U+2028/U+2029 (it wants output that is valid
JavaScript), Python does not. A caption pasted out of a word processor really
can contain U+2028, so that is fixed up rather than left as a mystery hash
mismatch. See `canonical()`.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import hashlib
import json
import os
import time
from urllib.parse import urlsplit

import requests

CLIENT_NAME    = 'take-your-shit-with-you'
API_VERSION    = '0.1'
STREAM_FORMAT  = 1

CHUNK_DEFAULT  = 500
CHUNK_MAX      = 2000

# Loopback is exempt from the HTTPS requirement: there is no network path to
# intercept. Everything else must be https:// — the key and the whole archive
# ride on this connection.
_LOOPBACK = {'localhost', '127.0.0.1', '::1', '[::1]'}


class TyswyError(Exception):
    """An error from the API, or from our own verification of its answer."""

    def __init__(self, message, *, code='client_error', retryable=False,
                 http_status=None, request_id=None):
        super().__init__(message)
        self.code        = code
        self.retryable   = retryable
        self.http_status = http_status
        self.request_id  = request_id


class VerificationError(TyswyError):
    """The bytes arrived but they are not what the server said they were. Never
    retryable in the transport sense — retrying identical bad data is pointless
    and pretending it verified is worse."""

    def __init__(self, message, **kw):
        kw.setdefault('code', 'verification_failed')
        kw['retryable'] = False
        super().__init__(message, **kw)


# ---------------------------------------------------------------------------
# Canonical serialisation — must equal PHP tyswy_canonical()
# ---------------------------------------------------------------------------

def canonical(record):
    """Key-sorted, compact, unescaped-unicode JSON, exactly as the server built
    it before hashing. See the module docstring for why U+2028/U+2029 are
    escaped by hand."""
    text = json.dumps(record, sort_keys=True, ensure_ascii=False,
                      separators=(',', ':'))
    return text.replace('\u2028', '\\u2028').replace('\u2029', '\\u2029')


def record_digest(record):
    return hashlib.sha256(canonical(record).encode('utf-8')).hexdigest()


# ---------------------------------------------------------------------------
# Chunk result
# ---------------------------------------------------------------------------

class StreamChunk:
    """One verified chunk. `records` is empty when the caller supplied an
    `on_record` sink — a 2,000-row chunk of longform posts is worth streaming
    past rather than holding, for the same reason the server does not hold it."""

    __slots__ = ('record_type', 'supported', 'header', 'footer', 'records',
                 'rows', 'last_id', 'has_more', 'source_count', 'source_max_id',
                 'snapshot')

    def __init__(self, record_type, supported, header, footer, records,
                 rows, last_id, has_more, source_count, source_max_id, snapshot):
        self.record_type   = record_type
        self.supported     = supported
        self.header        = header
        self.footer        = footer
        self.records       = records
        self.rows          = rows
        self.last_id       = last_id
        self.has_more      = has_more
        self.source_count  = source_count
        self.source_max_id = source_max_id
        self.snapshot      = snapshot


class MediaResult:
    __slots__ = ('path', 'bytes_written', 'sha256', 'resumed', 'variant',
                 'not_modified')

    def __init__(self, path, bytes_written, sha256, resumed, variant,
                 not_modified=False):
        self.path          = path
        self.bytes_written = bytes_written
        self.sha256        = sha256
        self.resumed       = resumed
        self.variant       = variant
        self.not_modified  = not_modified


# ---------------------------------------------------------------------------
# Client
# ---------------------------------------------------------------------------

class TyswyClient:
    """
    Read-only client for the TYSWY export API.

    There is deliberately no method here that writes to the site. The key type
    cannot write either (spec section 5) — this is the second lock on the same
    door, so a future edit here cannot quietly grow a write path.
    """

    def __init__(self, site_url, api_key, *, app_version='0.1.0', timeout=(15, 120),
                 allow_http=False, max_retries=4, session=None):
        self.site_url    = self._normalise_url(site_url)
        self.api_key     = (api_key or '').strip()
        self.app_version = app_version
        self.timeout     = timeout
        self.max_retries = max(0, int(max_retries))
        self._require_https(self.site_url, allow_http)

        self.session = session or requests.Session()
        self.session.headers.update({
            'User-Agent':    f'{CLIENT_NAME}/{app_version}',
            'Accept':        'application/json, application/x-ndjson',
            'Authorization': f'Bearer {self.api_key}',
        })
        self.site_uuid = None          # learned at preflight, pinned thereafter

    # -- url handling -------------------------------------------------------
    @staticmethod
    def _normalise_url(url):
        u = (url or '').strip().rstrip('/')
        if not u:
            raise TyswyError('A site URL is required.', code='no_site_url')
        if '://' not in u:
            u = 'https://' + u
        return u

    @staticmethod
    def _require_https(url, allow_http):
        parts = urlsplit(url)
        if parts.scheme == 'https':
            return
        host = (parts.hostname or '').lower()
        if host in _LOOPBACK or host.startswith('127.'):
            return
        if allow_http:
            return
        raise TyswyError(
            'This tool refuses plain http://. Your export key and every one of '
            'your photographs would cross the network unencrypted. Use https://.',
            code='https_required')

    def _url(self, action, **params):
        q = {'route': 'tyswy/api', 'action': action}
        q.update({k: v for k, v in params.items() if v is not None})
        from urllib.parse import urlencode
        return f'{self.site_url}/api.php?{urlencode(q)}'

    # -- request plumbing ---------------------------------------------------
    def _sleep(self, attempt):
        time.sleep(min(30.0, 1.5 * (2 ** attempt)))

    def _raise_for_error_body(self, resp):
        """Turn the documented error contract (spec 6.7) into a typed exception.
        Falls back to the status code when the body is not our JSON — a proxy
        error page is still an error, it just cannot say which."""
        rid = None
        try:
            body = resp.json()
        except ValueError:
            body = None
        if isinstance(body, dict):
            rid = body.get('request_id')
            err = body.get('error')
            if isinstance(err, dict):
                raise TyswyError(err.get('message') or 'The site refused the request.',
                                 code=err.get('code') or 'api_error',
                                 retryable=bool(err.get('retryable')) or resp.status_code in (429, 500, 502, 503, 504),
                                 http_status=resp.status_code, request_id=rid)
        raise TyswyError(
            f'The site answered {resp.status_code} without a usable error message. '
            'That usually means something in front of the site (a proxy, a firewall, '
            'a security plugin) answered instead of SnapSmack.',
            code='http_' + str(resp.status_code),
            retryable=resp.status_code in (429, 500, 502, 503, 504),
            http_status=resp.status_code, request_id=rid)

    def _get(self, action, *, stream=False, headers=None, **params):
        url  = self._url(action, **params)
        last = None
        for attempt in range(self.max_retries + 1):
            try:
                resp = self.session.get(url, timeout=self.timeout, stream=stream,
                                        headers=headers or None)
            except requests.RequestException as e:
                last = TyswyError(f'Could not reach the site: {e}',
                                  code='network', retryable=True)
                if attempt < self.max_retries:
                    self._sleep(attempt)
                    continue
                raise last
            if resp.status_code >= 400:
                try:
                    self._raise_for_error_body(resp)
                except TyswyError as e:
                    last = e
                    if e.retryable and attempt < self.max_retries:
                        resp.close()
                        self._sleep(attempt)
                        continue
                    raise
            return resp
        raise last or TyswyError('Request failed.', code='network', retryable=True)

    def _get_json(self, action, **params):
        resp = self._get(action, **params)
        try:
            body = resp.json()
        except ValueError:
            raise TyswyError(
                'The site did not answer with JSON. Check that the URL is the '
                'SnapSmack install itself and not a redirect, a parked page, or '
                'a "coming soon" plugin.', code='not_json')
        if not body.get('ok'):
            err = body.get('error') or {}
            raise TyswyError(err.get('message') or 'The site refused the request.',
                             code=err.get('code') or 'api_error',
                             retryable=bool(err.get('retryable')),
                             request_id=body.get('request_id'))
        self._check_identity(body)
        return body

    def _check_identity(self, body):
        """Pin the site UUID after the first answer.

        An export must never quietly continue against a different site — that is
        how two archives get merged into one folder and neither is trustworthy
        afterwards (spec 12, Resume)."""
        uuid = body.get('site_uuid')
        if not uuid:
            return
        if self.site_uuid is None:
            self.site_uuid = uuid
        elif uuid != self.site_uuid:
            raise VerificationError(
                'The site identified itself differently mid-export. Stopping '
                'rather than mixing two sites into one archive.',
                code='site_identity_changed')

    # -- 6.1 preflight ------------------------------------------------------
    def preflight(self):
        body = self._get_json('preflight')
        fmt = body.get('stream_format')
        if fmt is not None and int(fmt) != STREAM_FORMAT:
            raise TyswyError(
                f'This site speaks export stream format {fmt}; this version of '
                f'TAKE YOUR SHIT WITH YOU speaks {STREAM_FORMAT}. Update the tool '
                '(or the site) so the archive is written the way it is read.',
                code='stream_format_mismatch')
        return body

    # -- 6.2 stream ---------------------------------------------------------
    def stream_chunk(self, record_type, *, after_id=0, limit=CHUNK_DEFAULT,
                     snapshot=None, on_record=None):
        """
        Fetch, verify and return ONE bounded chunk.

        Nothing is returned until the footer has been read and the rolling hash
        matches — a partially consumed body is a failure, not a partial success.
        `on_record(record, source_id, digest)` receives rows as they arrive so
        the caller can write them straight out.
        """
        limit = max(1, min(int(limit), CHUNK_MAX))
        resp  = self._get('stream', stream=True, type=record_type,
                          after_id=int(after_id), limit=limit,
                          snapshot=(int(snapshot) if snapshot else None))
        header = footer = None
        records = [] if on_record is None else None
        rolling = hashlib.sha256()
        rows = 0
        last_id = int(after_id)

        try:
            for raw in resp.iter_lines(decode_unicode=False):
                if not raw:
                    continue
                try:
                    line = json.loads(raw.decode('utf-8'))
                except (ValueError, UnicodeDecodeError):
                    raise VerificationError(
                        'The site sent a line in the record stream that is not '
                        'valid JSON. The chunk has been discarded.',
                        code='bad_ndjson_line')

                if header is None:
                    header = line
                    if not isinstance(header, dict) or header.get('record_type') != record_type:
                        raise VerificationError(
                            'The record stream started with the wrong header.',
                            code='bad_stream_header')
                    if header.get('supported') is False:
                        continue
                    continue

                if line.get('footer'):
                    footer = line
                    continue

                rec    = line.get('record')
                digest = line.get('sha256')
                if not isinstance(rec, dict) or not isinstance(digest, str):
                    raise VerificationError(
                        'A line in the record stream is not a record.',
                        code='bad_record_line')
                mine = record_digest(rec)
                if mine != digest:
                    raise VerificationError(
                        f'A {record_type} record did not survive the trip intact '
                        f'(id {line.get("id")}). The chunk has been discarded and '
                        'will be fetched again.',
                        code='record_digest_mismatch')
                rolling.update(digest.encode('ascii'))
                rows += 1
                sid = int(line.get('id') or 0)
                if sid:
                    last_id = sid
                if on_record is not None:
                    on_record(rec, sid, digest)
                else:
                    records.append({'id': sid, 'sha256': digest, 'record': rec})
        finally:
            resp.close()

        if header is None:
            raise VerificationError('The record stream was empty — no header.',
                                    code='no_stream_header')

        if header.get('supported') is False:
            # An absent optional feature is not an error (spec 6.2).
            return StreamChunk(record_type, False, header, footer or {},
                               records or [], 0, int(after_id), False, 0, 0,
                               int(snapshot or 0))

        if footer is None:
            raise VerificationError(
                'The record stream stopped without a footer, which means it was '
                'cut off. Nothing from this chunk has been kept — it will be '
                'fetched again from the last verified row.',
                code='truncated_stream')
        if not footer.get('complete'):
            raise VerificationError('The site reported the chunk did not complete.',
                                    code='incomplete_stream')
        if int(footer.get('rows') or 0) != rows:
            raise VerificationError(
                f'The site said it sent {footer.get("rows")} {record_type} rows; '
                f'{rows} arrived.', code='row_count_mismatch')
        declared = footer.get('stream_sha256')
        if declared and declared != rolling.hexdigest():
            raise VerificationError(
                f'The {record_type} chunk hashes differently here than at the '
                'source. Discarded.', code='stream_hash_mismatch')

        return StreamChunk(
            record_type   = record_type,
            supported     = True,
            header        = header,
            footer        = footer,
            records       = records or [],
            rows          = rows,
            last_id       = int(footer.get('last_id') or last_id),
            has_more      = bool(footer.get('has_more')),
            source_count  = int(header.get('source_count') or 0),
            source_max_id = int(header.get('source_max_id') or 0),
            snapshot      = int(header.get('snapshot') or snapshot or 0),
        )

    def stream_all(self, record_type, *, snapshot=None, after_id=0,
                   limit=CHUNK_DEFAULT, on_record=None, on_chunk=None,
                   should_stop=None):
        """
        Walk every chunk of one record type. Yields nothing; the caller writes
        through `on_record`. `on_chunk(chunk)` fires after each VERIFIED chunk,
        which is the only safe moment to advance a checkpoint.

        Returns (rows, last_id, snapshot, source_count).
        """
        total = 0
        cursor = int(after_id)
        snap = snapshot
        source_count = 0
        while True:
            chunk = self.stream_chunk(record_type, after_id=cursor, limit=limit,
                                      snapshot=snap, on_record=on_record)
            if not chunk.supported:
                return 0, cursor, int(snap or 0), 0
            snap = snap or chunk.snapshot
            source_count = chunk.source_count or source_count
            total += chunk.rows
            if on_chunk:
                on_chunk(chunk)
            if chunk.rows == 0 or chunk.last_id <= cursor:
                # No forward progress: stop rather than loop on the same id.
                return total, chunk.last_id, int(snap or 0), source_count
            cursor = chunk.last_id
            if not chunk.has_more:
                return total, cursor, int(snap or 0), source_count
            if should_stop and should_stop():
                return total, cursor, int(snap or 0), source_count

    # -- 6.4 record ---------------------------------------------------------
    def record(self, record_type, source_id):
        body = self._get_json('record', type=record_type, id=int(source_id))
        rec = body.get('record')
        if not isinstance(rec, dict):
            raise VerificationError('The site did not return a record.',
                                    code='bad_record')
        if record_digest(rec) != body.get('sha256'):
            raise VerificationError(
                f'{record_type} {source_id} did not survive the trip intact.',
                code='record_digest_mismatch')
        return rec

    # -- 6.5 verify ---------------------------------------------------------
    def verify(self, snapshot=None):
        return self._get_json('verify', snapshot=(int(snapshot) if snapshot else None))

    # -- 6.6 changes --------------------------------------------------------
    def changes(self, since):
        return self._get_json('changes', since=since)

    # -- 6.3 media ----------------------------------------------------------
    def download_media(self, image_id, dest_path, *, variant='original',
                       source='image', on_progress=None, expect_bytes=None):
        """
        Download one media file to `dest_path` via a `.part` file, resuming with
        a Range request when a partial is already there.

        `source` is 'image' for a gallery photograph or 'asset' for a longform
        inline file ([img:ID] in a SMACKTALK body) — different table, same
        containment on the server side.

        The `.part` is renamed into place only after the whole length arrives.
        A half-file therefore never looks like a finished one, and an interrupted
        transfer costs only the bytes not yet received (spec 12/13).
        """
        part = dest_path + '.part'
        os.makedirs(os.path.dirname(dest_path) or '.', exist_ok=True)

        have = os.path.getsize(part) if os.path.exists(part) else 0
        headers = {'Range': f'bytes={have}-'} if have else None

        resp = self._get('media', stream=True, headers=headers,
                         id=int(image_id), variant=variant, source=source)
        try:
            if have and resp.status_code == 200:
                # The server ignored the Range (or the file changed) — start over
                # rather than splice two different files together.
                have = 0
            resumed = bool(have) and resp.status_code == 206

            expected_total = None
            clen = resp.headers.get('Content-Length')
            if clen and str(clen).isdigit():
                expected_total = int(clen) + (have if resumed else 0)
            crange = resp.headers.get('Content-Range', '')
            if '/' in crange:
                tail = crange.rsplit('/', 1)[-1].strip()
                if tail.isdigit():
                    expected_total = int(tail)

            mode = 'ab' if resumed else 'wb'
            written = have if resumed else 0
            with open(part, mode) as fh:
                for block in resp.iter_content(chunk_size=1 << 18):
                    if not block:
                        continue
                    fh.write(block)
                    written += len(block)
                    if on_progress:
                        on_progress(written, expected_total)
        finally:
            resp.close()

        if expected_total is not None and written != expected_total:
            raise VerificationError(
                f'Image {image_id} arrived {written} bytes of an expected '
                f'{expected_total}. The partial file has been kept so the rest '
                'can be fetched — nothing has been lost.',
                code='short_media')
        if expect_bytes is not None and written != int(expect_bytes):
            raise VerificationError(
                f'Image {image_id} is {written} bytes; the archive expected '
                f'{expect_bytes}.', code='media_size_mismatch')

        digest = hashlib.sha256()
        with open(part, 'rb') as fh:
            for block in iter(lambda: fh.read(1 << 20), b''):
                digest.update(block)

        os.replace(part, dest_path)      # atomic completion
        return MediaResult(dest_path, written, digest.hexdigest(), resumed, variant)

    def close(self):
        try:
            self.session.close()
        except Exception:
            pass
# ===== SNAPSMACK EOF =====
