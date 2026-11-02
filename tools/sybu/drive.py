"""
ft-batch-poster — drive.py
Google Drive authentication and file upload.

First run: opens a browser for OAuth consent. After that, token.json
handles it silently — no browser, no fuss.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.




import hashlib
import os
import sys
from typing import Optional

from google.auth.transport.requests import Request
from google.oauth2.credentials import Credentials
from google_auth_oauthlib.flow import InstalledAppFlow
from googleapiclient.discovery import build
from googleapiclient.http import MediaFileUpload

SCOPES = ['https://www.googleapis.com/auth/drive']

# Bounded retry for the permission and delete calls. Not 0 (a transient hiccup
# must not lose a grant and leave an orphan) and not the client default (that
# loop can hang a desktop GUI for minutes). See upload().
_PERM_RETRIES = 2


def _token_path() -> str:
    """token.json lives next to the exe (or script in dev)."""
    if getattr(sys, 'frozen', False):
        base = os.path.dirname(sys.executable)
    else:
        base = os.path.dirname(os.path.abspath(__file__))
    return os.path.join(base, 'token.json')


def authenticate(credentials_path: str):
    """
    Authenticate with Google Drive using OAuth2.
    - First call: opens browser for consent, saves token.json.
    - Subsequent calls: silently refreshes from token.json.
    Returns a Drive API service object.
    """
    creds = None
    token_path = _token_path()

    if os.path.exists(token_path):
        creds = Credentials.from_authorized_user_file(token_path, SCOPES)

    if not creds or not creds.valid:
        if creds and creds.expired and creds.refresh_token:
            creds.refresh(Request())
        else:
            try:
                flow = InstalledAppFlow.from_client_secrets_file(credentials_path, SCOPES)
            except Exception as e:
                msg = str(e) if str(e) and str(e) != 'None' else None
                raise RuntimeError(
                    "Could not load credentials.json.\n\n"
                    "Make sure you are using a 'Desktop app' OAuth client, "
                    "not a 'Web application' client.\n\n"
                    "Go to Google Cloud Console → APIs & Services → Credentials, "
                    "create a new OAuth 2.0 Client ID with type 'Desktop app', "
                    "and download the new credentials.json."
                    + (f"\n\nDetails: {msg}" if msg else "")
                ) from e
            creds = flow.run_local_server(port=0)
        with open(token_path, 'w') as f:
            f.write(creds.to_json())

    return build('drive', 'v3', credentials=creds)


def is_authenticated() -> bool:
    """Return True if a valid token.json already exists."""
    token_path = _token_path()
    if not os.path.exists(token_path):
        return False
    try:
        creds = Credentials.from_authorized_user_file(token_path, SCOPES)
        return creds and (creds.valid or creds.refresh_token)
    except Exception:
        return False


def upload(service, file_path: str, filename: str,
           folder_id: Optional[str] = None) -> str:
    """
    Upload a file to Google Drive and return a publicly shareable link.
    If folder_id is provided, the file is placed in that folder.
    """
    file_metadata = {'name': filename}
    if folder_id:
        file_metadata['parents'] = [folder_id]

    media = MediaFileUpload(
        file_path,
        mimetype=_mime(file_path),
        resumable=True,
    )

    file = service.files().create(
        body=file_metadata,
        media_body=media,
        fields='id',
    ).execute(num_retries=0)

    file_id = file.get('id')
    if not file_id:
        raise RuntimeError('Drive did not return a file ID after upload.')

    # Make the file publicly readable (anyone with the link).
    # _PERM_RETRIES is 2, not 0 and not the client default. It was 0 to stop the
    # Google client's exponential-backoff loop hanging the GUI for minutes on a
    # transient hiccup; that reason still holds, so this is a bounded retry (a
    # few seconds at worst), not the default loop. The PM's 22:39 ruling
    # requires a transient permission failure to retry or fail loudly.
    try:
        service.permissions().create(
            fileId=file_id,
            body={'type': 'anyone', 'role': 'reader'},
        ).execute(num_retries=_PERM_RETRIES)
    except Exception as perm_exc:
        left = _discard_orphan(service, file_id)
        if left:
            raise RuntimeError(
                f'File uploaded to Drive (ID: {file_id}) but setting public '
                f'permissions failed: {perm_exc}\n\n'
                f'It could not be removed either ({left}), so an unshared copy '
                f'of "{filename}" is still in Drive. Either delete it or grant '
                f'it "Anyone with the link - Viewer" and paste the link in.'
            ) from perm_exc
        raise RuntimeError(
            f'Setting public permissions on "{filename}" failed: {perm_exc}\n\n'
            f'The uploaded copy was removed, so nothing was left unshared in '
            f'Drive and nothing was published. Retry the upload.'
        ) from perm_exc

    url = f"https://drive.google.com/uc?export=download&id={file_id}"

    # THE PUBLISH GATE. A URL we built is not a URL that works: a grant that did
    # not take, a quota page or a throttled file all leave this string looking
    # fine. Prove it serves the image to a stranger before any caller can
    # publish it. poster.py refuses to publish a post whose download URL is
    # missing (:439, :700), so raising here is what stops the post.
    receipt = public_permission_receipt(service, file_id)
    probe = probe_public_download(url, file_path)

    # Drive's grant is not always effective for an anonymous fetch the instant
    # it is created. One short retry covers propagation; it is not a waiting
    # period, and a second failure is a real failure.
    if not probe['ok'] and probe['reason'] in ('status', 'html', 'network'):
        import time
        time.sleep(2)
        probe = probe_public_download(url, file_path)

    if not (receipt['ok'] and probe['ok']):
        left = _discard_orphan(service, file_id)
        why = probe['detail'] if not probe['ok'] else receipt['detail']
        reason = probe['reason'] if not probe['ok'] else 'permission_not_set'
        tail = (f'The uploaded copy was removed, so nothing was published.'
                if not left else
                f'It could not be removed either ({left}), so an unusable copy '
                f'of "{filename}" is still in Drive.')
        raise RuntimeError(
            f'"{filename}" FAILED the anonymous download check '
            f'[{reason}]: {why}\n\n'
            f'Permission receipt: {receipt["detail"]}\n{tail}'
        )

    return url


def rename(service, file_id: str, new_name: str) -> str:
    """
    Rename a file in Google Drive.  Returns the new name as confirmed by the
    API.  The file's share URL is ID-based and is unaffected by the rename.
    """
    result = service.files().update(
        fileId=file_id,
        body={'name': new_name},
        fields='id,name',
    ).execute(num_retries=0)
    return result.get('name', new_name)


def download_to_temp(service, file_id: str) -> str:
    """
    Download a Drive file to a temp file on disk.
    Returns the temp file path — caller is responsible for deleting it.
    Raises RuntimeError on failure.
    """
    import io
    import tempfile
    from googleapiclient.http import MediaIoBaseDownload

    # Get file metadata first (we need the name to pick the right extension)
    meta = service.files().get(fileId=file_id, fields='name,mimeType').execute()
    original_name = meta.get('name', 'download.jpg')
    ext = os.path.splitext(original_name)[1] or '.jpg'

    request = service.files().get_media(fileId=file_id)
    buf = io.BytesIO()
    downloader = MediaIoBaseDownload(buf, request, chunksize=4 * 1024 * 1024)
    done = False
    while not done:
        _, done = downloader.next_chunk()

    buf.seek(0)
    tmp = tempfile.NamedTemporaryFile(delete=False, suffix=ext)
    tmp.write(buf.read())
    tmp.close()
    return tmp.name


def search(service, folder_id: str, title_query: str) -> list:
    """
    Search for files in a Drive folder whose name contains title_query.
    Returns a list of dicts: [{'id': '...', 'name': '...', 'url': '...'}, ...]
    Returns [] if nothing found.  Raises RuntimeError on API failure.
    """
    # Escape single quotes in the query string to avoid breaking the q param
    escaped = title_query.replace("\\", "\\\\").replace("'", "\\'")
    q = f"name contains '{escaped}' and '{folder_id}' in parents and trashed = false"
    try:
        result = service.files().list(
            q=q,
            fields="files(id,name)",
            pageSize=10,
        ).execute(num_retries=0)
    except Exception as exc:
        raise RuntimeError(f"Drive search failed: {exc}") from exc

    files = result.get('files', [])
    return [
        {
            'id':   f['id'],
            'name': f['name'],
            'url':  f"https://drive.google.com/uc?export=download&id={f['id']}",
        }
        for f in files
    ]


def probe_folder(service, folder_id: str) -> dict:
    """Read-only: can THIS token reach THIS folder? No upload, no write, no queue.

    SS-1171. SYBU's only Drive signal was `drive_service is not None`, which is
    true the moment authenticate() returns an object -- so a dead token or a
    folder id that no longer exists both read as "connected", and the owner
    found out at publish time. This asks Drive one question and reports which
    of the two failed, because "it does not work" is not a diagnosis.

    Returns {'ok': bool, 'reason': str, 'detail': str, 'name': str}:
      reason 'no_service'   authenticate() has not run in this session
      reason 'no_folder'    no folder id was given to check
      reason 'auth'         the token is refused (401/403) -- re-authenticate
      reason 'folder'       the folder is missing, trashed, or not shared (404)
      reason 'not_a_folder' the id resolves to a file, not a folder
      reason 'api'          Drive answered with something else; detail carries it
      reason ''             ok
    """
    if service is None:
        return {'ok': False, 'reason': 'no_service', 'detail':
                'Google Drive is not connected in this session.', 'name': ''}
    folder_id = (folder_id or '').strip()
    if not folder_id:
        return {'ok': False, 'reason': 'no_folder', 'detail':
                'No Drive folder id is set, so there is nothing to check.', 'name': ''}
    try:
        meta = service.files().get(
            fileId=folder_id,
            fields='id,name,mimeType,trashed',
        ).execute(num_retries=0)
    except Exception as exc:                      # googleapiclient.errors.HttpError and friends
        status = getattr(getattr(exc, 'resp', None), 'status', None)
        if status is None:
            status = getattr(exc, 'status_code', None)
        try:
            status = int(status) if status is not None else None
        except (TypeError, ValueError):
            status = None
        text = str(exc)
        if status in (401, 403) or 'invalid_grant' in text or 'insufficient' in text.lower():
            return {'ok': False, 'reason': 'auth', 'detail':
                    'Google refused the saved token. Re-authenticate Drive. (%s)' % text,
                    'name': ''}
        if status == 404 or 'notFound' in text or 'not found' in text.lower():
            return {'ok': False, 'reason': 'folder', 'detail':
                    'Drive has no folder with that id, or it is not shared with '
                    'this account. (%s)' % text, 'name': ''}
        return {'ok': False, 'reason': 'api', 'detail': text, 'name': ''}

    if meta.get('trashed'):
        return {'ok': False, 'reason': 'folder', 'detail':
                'That folder is in the Drive trash.', 'name': str(meta.get('name') or '')}
    if meta.get('mimeType') != 'application/vnd.google-apps.folder':
        return {'ok': False, 'reason': 'not_a_folder', 'detail':
                'That id is a file, not a folder.', 'name': str(meta.get('name') or '')}
    return {'ok': True, 'reason': '', 'detail': '', 'name': str(meta.get('name') or '')}


def revoke_token() -> None:
    """Delete the saved token, forcing re-authentication next time."""
    token_path = _token_path()
    if os.path.exists(token_path):
        os.unlink(token_path)


def _local_digest(path: str) -> tuple:
    """(size, md5) of a local file, or (0, '') if it cannot be read."""
    try:
        h = hashlib.md5()
        n = 0
        with open(path, 'rb') as fh:
            while True:
                chunk = fh.read(1024 * 1024)
                if not chunk:
                    break
                n += len(chunk)
                h.update(chunk)
        return n, h.hexdigest()
    except OSError:
        return 0, ''


def _imgsafe():
    """The shared image-safety module, or None.

    Same lookup poster.py uses for snap_site_scope: each tool puts tools/_shared
    on sys.path at a different spot, and frozen exes bundle it next to the entry
    script.
    """
    try:
        import snap_imgsafe
        return snap_imgsafe
    except Exception:  # noqa: BLE001
        d = os.path.dirname(os.path.abspath(__file__))
        for _ in range(4):
            cand = os.path.join(d, '_shared')
            if os.path.isdir(cand):
                if cand not in sys.path:
                    sys.path.insert(0, cand)
                break
            d = os.path.dirname(d)
        try:
            import snap_imgsafe
            return snap_imgsafe
        except Exception:  # noqa: BLE001
            return None


def probe_public_download(url: str, local_path: str = '', *,
                          expected_md5: str = '',
                          timeout: int = 30) -> dict:
    """Fetch a Drive link the way a stranger would and prove it is the image.

    Anonymous on purpose: a fresh request with no OAuth header, no cookies and
    no api_key. The owner's own browser is signed in to Drive, so a link that
    works for him proves nothing about what a visitor gets.

    Returns {'ok', 'reason', 'detail', 'bytes', 'md5'}. Reasons: no_url,
    no_requests, network, status, html, not_image, no_imgsafe, size_mismatch,
    md5_mismatch.

    The two HTML traps are why content-type alone is not enough: Drive answers
    the large-file confirmation page and the "too many users have viewed or
    downloaded this file" quota page with status 200 and a body of HTML.
    """
    out = {'ok': False, 'reason': '', 'detail': '', 'bytes': 0, 'md5': ''}

    if not (url or '').strip():
        out['reason'] = 'no_url'
        out['detail'] = 'No download URL to probe.'
        return out

    try:
        import requests
    except Exception as exc:  # noqa: BLE001
        out['reason'] = 'no_requests'
        out['detail'] = 'requests is unavailable: %s' % exc
        return out

    want_size, want_md5 = (0, '')
    if local_path:
        want_size, want_md5 = _local_digest(local_path)
    if expected_md5:
        want_md5 = expected_md5.strip().lower()

    safe = _imgsafe()
    if safe is None:
        out['reason'] = 'no_imgsafe'
        out['detail'] = ('snap_imgsafe (shared image safety module) is missing, so '
                         'downloaded bytes cannot be verified. Refusing to pass.')
        return out

    # Read at most what the original is, plus slack for container differences,
    # and never more than snap_imgsafe's own ceiling.
    budget = min(getattr(safe, 'MAX_BYTES', 128 * 1024 * 1024),
                 (want_size + 1024 * 1024) if want_size else 128 * 1024 * 1024)

    try:
        with requests.get(url, timeout=timeout, stream=True,
                          allow_redirects=True, cookies={}) as r:
            status = r.status_code
            ctype = (r.headers.get('Content-Type') or '').split(';')[0].strip().lower()
            body = bytearray()
            for chunk in r.iter_content(64 * 1024):
                if not chunk:
                    continue
                body.extend(chunk)
                if len(body) > budget:
                    break
            data = bytes(body)
    except Exception as exc:  # noqa: BLE001
        out['reason'] = 'network'
        out['detail'] = str(exc)
        return out

    out['bytes'] = len(data)

    if status != 200:
        out['reason'] = 'status'
        out['detail'] = 'HTTP %s from the download URL.' % status
        return out

    if ctype.startswith('text/') or ctype in ('application/xhtml+xml',):
        out['reason'] = 'html'
        out['detail'] = ('Drive returned %s, not an image. This is normally the '
                         'large-file confirmation page or the download-quota '
                         'page, both of which answer with status 200.' % ctype)
        return out

    try:
        safe.check_bytes(data)
    except Exception as exc:  # noqa: BLE001
        out['reason'] = 'not_image'
        out['detail'] = 'Downloaded bytes are not a usable image: %s' % exc
        return out

    out['md5'] = hashlib.md5(data).hexdigest()

    if want_size and len(data) != want_size:
        out['reason'] = 'size_mismatch'
        out['detail'] = ('downloaded %d bytes, the original is %d. Drive served '
                         'something other than the file we uploaded.'
                         % (len(data), want_size))
        return out

    if want_md5 and out['md5'] != want_md5:
        out['reason'] = 'md5_mismatch'
        out['detail'] = ('downloaded md5 %s, expected %s.' % (out['md5'], want_md5))
        return out

    out['ok'] = True
    return out


def public_permission_receipt(service, file_id: str) -> dict:
    """Read back whether anyone/reader is really set on the file.

    The create call returning without raising is not the same as the grant being
    in place. Returns {'ok', 'detail'}; ok is False when the read fails, so a
    missing receipt is never counted as a pass.
    """
    try:
        resp = service.permissions().list(
            fileId=file_id, fields='permissions(type,role)',
        ).execute(num_retries=_PERM_RETRIES)
    except Exception as exc:  # noqa: BLE001
        return {'ok': False, 'detail': 'could not read permissions back: %s' % exc}

    for perm in (resp.get('permissions') or []):
        if perm.get('type') == 'anyone' and perm.get('role') in ('reader', 'writer', 'owner'):
            return {'ok': True, 'detail': 'anyone/%s' % perm.get('role')}
    return {'ok': False,
            'detail': 'no anyone/reader grant is present on the file'}


def _discard_orphan(service, file_id: str) -> str:
    """Delete a file this upload just created and is refusing to use.

    Rolling back our own failed write, seconds old and never published. It is
    not a cleanup pass over the owner's Drive and never touches anything whose
    id this call did not just receive. Returns '' on success, else why not, so
    the caller can tell the owner what is left behind.
    """
    try:
        service.files().delete(fileId=file_id).execute(num_retries=_PERM_RETRIES)
        return ''
    except Exception as exc:  # noqa: BLE001
        return str(exc)


def _mime(path: str) -> str:
    ext = os.path.splitext(path)[1].lower()
    return {
        '.jpg':  'image/jpeg',
        '.jpeg': 'image/jpeg',
        '.png':  'image/png',
        '.gif':  'image/gif',
        '.webp': 'image/webp',
    }.get(ext, 'image/jpeg')
# ===== SNAPSMACK EOF =====
