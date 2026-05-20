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




import os
import sys
from typing import Optional

from google.auth.transport.requests import Request
from google.oauth2.credentials import Credentials
from google_auth_oauthlib.flow import InstalledAppFlow
from googleapiclient.discovery import build
from googleapiclient.http import MediaFileUpload

SCOPES = ['https://www.googleapis.com/auth/drive']


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
    # num_retries=0 prevents the Google client's exponential-backoff loop
    # from hanging for minutes on a transient API hiccup.
    try:
        service.permissions().create(
            fileId=file_id,
            body={'type': 'anyone', 'role': 'reader'},
        ).execute(num_retries=0)
    except Exception as perm_exc:
        raise RuntimeError(
            f'File uploaded to Drive (ID: {file_id}) but setting public '
            f'permissions failed: {perm_exc}\n\n'
            f'Grant "Anyone with the link – Viewer" access manually in Drive, '
            f'then retry the upload or paste the link directly.'
        ) from perm_exc

    return f"https://drive.google.com/uc?export=download&id={file_id}"


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


def revoke_token() -> None:
    """Delete the saved token, forcing re-authentication next time."""
    token_path = _token_path()
    if os.path.exists(token_path):
        os.unlink(token_path)


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
