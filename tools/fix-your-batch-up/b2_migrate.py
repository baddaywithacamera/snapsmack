# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

"""
Fix Your Batch Up — b2_migrate.py
Backblaze B2 client, migration manifest, and Google Drive download helper.

Used by MigrateTab (main.py) to migrate Google Drive image backups to B2.
No new pip dependencies — uses requests (already in requirements.txt) and
the google-api-python-client that is already installed for Drive auth.
"""

import hashlib
import io
import json
import mimetypes
import os
import re
import urllib.parse
from datetime import date, datetime
from typing import Optional, Tuple


# ---------------------------------------------------------------------------
# Exceptions
# ---------------------------------------------------------------------------

class B2AuthError(Exception):
    """Raised when B2 authorization or bucket resolution fails."""

class B2UploadError(Exception):
    """Raised when a B2 upload returns a non-200 status."""


# ---------------------------------------------------------------------------
# B2 client
# ---------------------------------------------------------------------------

class B2Client:
    """
    Minimal Backblaze B2 client using the native HTTP API v2.
    Thread-safe for reads; call from a single thread for uploads.

    Usage:
        client = B2Client(account_id, app_key, bucket_name)
        client.authorize()        # raises B2AuthError on failure
        url = client.upload(data, 'photo.jpg')
    """

    API_BASE = 'https://api.backblazeb2.com'

    def __init__(self, account_id: str, app_key: str,
                 bucket_name: str, bucket_id: str = ''):
        self.account_id   = account_id.strip()
        self.app_key      = app_key.strip()
        self.bucket_name  = bucket_name.strip()
        self.bucket_id    = bucket_id.strip()
        self._auth_token  = ''
        self._api_url     = ''
        self._download_url = ''

    # ── Auth ─────────────────────────────────────────────────────────────────

    def authorize(self) -> None:
        """
        Authorize with B2 and resolve the bucket ID if not already set.
        Raises B2AuthError on failure.
        """
        import requests as _req
        r = _req.get(
            f'{self.API_BASE}/b2api/v2/b2_authorize_account',
            auth=(self.account_id, self.app_key),
            timeout=15,
        )
        if r.status_code != 200:
            raise B2AuthError(
                f'B2 authorization failed (HTTP {r.status_code}): {r.text[:300]}'
            )
        d = r.json()
        self._auth_token   = d['authorizationToken']
        self._api_url      = d['apiUrl']
        self._download_url = d['downloadUrl']

        if not self.bucket_id and self.bucket_name:
            self._resolve_bucket_id()

    def _resolve_bucket_id(self) -> None:
        """Look up bucket ID by name. Raises B2AuthError if not found."""
        import requests as _req
        r = _req.post(
            f'{self._api_url}/b2api/v2/b2_list_buckets',
            headers={'Authorization': self._auth_token},
            json={'accountId': self.account_id, 'bucketName': self.bucket_name},
            timeout=15,
        )
        r.raise_for_status()
        buckets = r.json().get('buckets', [])
        if not buckets:
            raise B2AuthError(
                f"Bucket '{self.bucket_name}' not found. "
                f"Check the bucket name and that your App Key has access to it."
            )
        self.bucket_id = buckets[0]['bucketId']

    # ── Upload ───────────────────────────────────────────────────────────────

    def _get_upload_url(self) -> Tuple[str, str]:
        """
        Get a one-time upload URL for this bucket.
        Returns (upload_url, upload_auth_token).
        Upload tokens are short-lived — fetch one per file.
        """
        import requests as _req
        r = _req.post(
            f'{self._api_url}/b2api/v2/b2_get_upload_url',
            headers={'Authorization': self._auth_token},
            json={'bucketId': self.bucket_id},
            timeout=15,
        )
        r.raise_for_status()
        d = r.json()
        return d['uploadUrl'], d['authorizationToken']

    def upload(self, data: bytes, filename: str, content_type: str = '') -> str:
        """
        Upload bytes to B2.
        Returns the public download URL for the file.
        Raises B2AuthError if not authorized, B2UploadError on upload failure.
        """
        if not self._auth_token:
            raise B2AuthError("Not authorized. Call authorize() first.")

        if not content_type:
            ct, _ = mimetypes.guess_type(filename)
            content_type = ct or 'application/octet-stream'

        sha1         = hashlib.sha1(data).hexdigest()
        encoded_name = urllib.parse.quote(filename, safe='/')

        upload_url, upload_token = self._get_upload_url()

        import requests as _req
        r = _req.post(
            upload_url,
            headers={
                'Authorization':     upload_token,
                'X-Bz-File-Name':    encoded_name,
                'Content-Type':      content_type,
                'Content-Length':    str(len(data)),
                'X-Bz-Content-Sha1': sha1,
            },
            data=data,
            timeout=300,   # large image files may take time
        )
        if r.status_code != 200:
            raise B2UploadError(
                f'B2 upload failed (HTTP {r.status_code}): {r.text[:300]}'
            )
        return self.public_url(filename)

    def upload_json(self, obj: dict, filename: str) -> str:
        """Serialize obj as JSON and upload to B2. Returns public URL."""
        data = json.dumps(obj, indent=2, ensure_ascii=False).encode('utf-8')
        return self.upload(data, filename, 'application/json')

    # ── URL helpers ───────────────────────────────────────────────────────────

    def public_url(self, filename: str) -> str:
        """
        Construct the public download URL for a file already in the bucket.
        Only works if the bucket is set to Public in the B2 console.
        """
        encoded = urllib.parse.quote(filename, safe='/')
        return f'{self._download_url}/file/{self.bucket_name}/{encoded}'

    @property
    def authorized(self) -> bool:
        return bool(self._auth_token)


# ---------------------------------------------------------------------------
# Migration manifest
# ---------------------------------------------------------------------------

MANIFEST_B2_PATH = '_fybu/migration-manifest.json'

class MigrationManifest:
    """
    Persistent record of every image migrated from Drive to B2.

    Saved locally as fybu-b2-manifest.json (in the same dir as the exe/script)
    and also uploaded to the B2 bucket as _fybu/migration-manifest.json after
    each successful migration so a future cleanup pass can find it.

    JSON structure:
    {
      "version": 1,
      "site_url": "https://example.com",
      "bucket": "my-b2-bucket",
      "migrated": {
        "<snap_id>": {
          "img_title":   str,
          "drive_url":   str,
          "b2_url":      str,
          "b2_filename": str,
          "migrated_at": "2026-05-25T14:30:00"
        }, ...
      }
    }
    """

    def __init__(self, local_path: str):
        self.local_path = local_path
        self.data: dict = {
            'version':  1,
            'site_url': '',
            'bucket':   '',
            'migrated': {},
        }

    def load(self) -> None:
        """Load manifest from disk. Silently no-ops if file is missing/corrupt."""
        if not os.path.isfile(self.local_path):
            return
        try:
            with open(self.local_path, 'r', encoding='utf-8') as f:
                loaded = json.load(f)
            if isinstance(loaded, dict):
                self.data.update(loaded)
                if not isinstance(self.data.get('migrated'), dict):
                    self.data['migrated'] = {}
        except Exception:
            pass   # corrupt file — start fresh

    def save_local(self) -> None:
        """Write manifest to disk. Non-fatal."""
        try:
            with open(self.local_path, 'w', encoding='utf-8') as f:
                json.dump(self.data, f, indent=2, ensure_ascii=False)
        except Exception:
            pass

    def save_to_b2(self, b2: B2Client) -> None:
        """Upload manifest to B2. Non-fatal on failure."""
        try:
            b2.upload_json(self.data, MANIFEST_B2_PATH)
        except Exception:
            pass

    def save(self, b2: Optional[B2Client] = None) -> None:
        """Save locally and (if b2 client provided) upload to B2."""
        self.save_local()
        if b2 and b2.authorized:
            self.save_to_b2(b2)

    def record(self, snap_id: int, img_title: str,
               drive_url: str, b2_url: str, b2_filename: str) -> None:
        """Record a completed migration."""
        self.data['migrated'][str(snap_id)] = {
            'img_title':   img_title,
            'drive_url':   drive_url,
            'b2_url':      b2_url,
            'b2_filename': b2_filename,
            'migrated_at': datetime.now().isoformat(timespec='seconds'),
        }

    def is_migrated(self, snap_id) -> bool:
        return str(snap_id) in self.data['migrated']

    def daily_count(self) -> int:
        """Number of files migrated today (local date)."""
        today = date.today().isoformat()
        return sum(
            1 for v in self.data['migrated'].values()
            if v.get('migrated_at', '').startswith(today)
        )

    def total_migrated(self) -> int:
        return len(self.data['migrated'])


# ---------------------------------------------------------------------------
# Google Drive helpers
# ---------------------------------------------------------------------------

def extract_drive_file_id(url: str) -> Optional[str]:
    """
    Extract the Google Drive file ID from common URL formats.

    Handles:
    - https://drive.google.com/file/d/FILE_ID/view
    - https://drive.google.com/uc?id=FILE_ID&export=download
    - https://drive.google.com/open?id=FILE_ID

    Returns None if no file ID can be found.
    """
    if not url:
        return None
    # /d/FILE_ID pattern (most common for shared files)
    m = re.search(r'/d/([a-zA-Z0-9_-]{10,})', url)
    if m:
        return m.group(1)
    # ?id=FILE_ID or &id=FILE_ID query param
    m = re.search(r'[?&]id=([a-zA-Z0-9_-]{10,})', url)
    if m:
        return m.group(1)
    return None


def is_drive_url(url: str) -> bool:
    """True if the URL looks like a Google Drive link."""
    return bool(url and (
        'drive.google.com' in url or 'docs.google.com' in url
    ))


def download_from_drive(service, file_id: str) -> Tuple[bytes, str]:
    """
    Download a file from Google Drive using the google-api-python-client service.

    Args:
        service: Authenticated googleapiclient Resource (from local_drive.authenticate)
        file_id: Google Drive file ID string

    Returns:
        (file_bytes, content_type)

    Raises:
        ValueError  if the file is a Google Workspace type (Docs/Sheets/etc.)
                    that cannot be downloaded via get_media.
        Exception   on any API or network error.
    """
    from googleapiclient.http import MediaIoBaseDownload

    # Fetch metadata first to get the MIME type
    meta = service.files().get(
        fileId=file_id,
        fields='name,mimeType',
        supportsAllDrives=True,
    ).execute()

    content_type = meta.get('mimeType', 'application/octet-stream')

    # Google Workspace files (Docs, Sheets, Slides) can't be downloaded
    # with get_media — they need export. These won't be image files in
    # SnapSmack, so treat as an error.
    if content_type.startswith('application/vnd.google-apps.'):
        raise ValueError(
            f"File is a Google Workspace type ({content_type}) and cannot "
            f"be downloaded directly. Export the file first."
        )

    request = service.files().get_media(
        fileId=file_id,
        supportsAllDrives=True,
    )
    buf = io.BytesIO()
    downloader = MediaIoBaseDownload(buf, request)
    done = False
    while not done:
        _, done = downloader.next_chunk()
    buf.seek(0)
    return buf.read(), content_type
# ===== SNAPSMACK EOF =====
