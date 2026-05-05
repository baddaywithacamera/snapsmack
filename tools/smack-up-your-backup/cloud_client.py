"""
Smack Up Your Backup — cloud_client.py
Cloud upload/download: Google Drive, Box, Backblaze B2.

Google Drive — OAuth (InstalledAppFlow). OAuth client secret JSON from
Google Cloud Console → APIs & Services → Credentials → Desktop app.

Box — OAuth2. Create a Box app at developer.box.com (Custom App, OAuth 2.0).
Credentials JSON: {"client_id": "...", "client_secret": "..."}

Backblaze B2 — API key auth. Get keys from backblaze.com → App Keys.
Credentials JSON: {"account_id": "...", "application_key": "...", "bucket_name": "..."}
No OAuth needed for B2 — just paste the keys.

Microsoft OneDrive is NOT supported. Use Box or B2.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import json
import os
from typing import Callable, List, Optional

ProgressCallback = Callable[[int, int], None]   # (bytes_done, bytes_total)

CHUNK_SIZE = 5 * 1024 * 1024   # 5 MB


# ---------------------------------------------------------------------------
# Google Drive — OAuth (InstalledAppFlow)
# ---------------------------------------------------------------------------

def _get_drive_service(credentials_file: str, readonly: bool = False):
    """Build an authenticated Drive service from an OAuth client secret JSON.

    readonly=True uses drive.readonly scope (for reading user's own files as
    a sync source). A separate token cache file is used so the upload token
    (drive.file) is unaffected.
    """
    try:
        from google.oauth2.credentials import Credentials
        from google_auth_oauthlib.flow import InstalledAppFlow
        from google.auth.transport.requests import Request
        from googleapiclient.discovery import build
    except ImportError:
        raise RuntimeError(
            "google-api-python-client and google-auth-oauthlib are required "
            "for Google Drive support."
        )

    if readonly:
        SCOPES     = ["https://www.googleapis.com/auth/drive.readonly"]
        token_file = credentials_file.replace(".json", "_readonly_token.json")
    else:
        SCOPES     = ["https://www.googleapis.com/auth/drive.file"]
        token_file = credentials_file.replace(".json", "_token.json")

    creds = None

    if os.path.exists(token_file):
        creds = Credentials.from_authorized_user_file(token_file, SCOPES)
    else:
        raise RuntimeError(
            f"No token file found at: {token_file}\n"
            f"Click 'Authenticate with Google' in the Edit dialog, then Save."
        )

    if not creds or not creds.valid:
        if creds and creds.expired and creds.refresh_token:
            try:
                creds.refresh(Request())
            except Exception as e:
                raise RuntimeError(
                    f"Token refresh failed (invalid_grant): {e}\n"
                    f"Token file: {token_file}\n"
                    f"Re-authenticate: open Edit dialog, click 'Authenticate with Google', then Save."
                ) from e
        else:
            flow = InstalledAppFlow.from_client_secrets_file(credentials_file, SCOPES)
            creds = flow.run_local_server(port=0)
        with open(token_file, "w") as f:
            f.write(creds.to_json())

    return build("drive", "v3", credentials=creds)


def _is_service_account_key(path: str) -> bool:
    """Return True if the file looks like a Google service account key JSON."""
    if not path or not os.path.isfile(path):
        return False
    try:
        with open(path) as f:
            data = json.load(f)
        return data.get("type") == "service_account"
    except Exception:
        return False


def _is_oauth_client_secret(path: str) -> bool:
    """Return True if the file looks like an OAuth client secret JSON."""
    if not path or not os.path.isfile(path):
        return False
    try:
        with open(path) as f:
            data = json.load(f)
        return "installed" in data or "web" in data
    except Exception:
        return False


def get_oauth_token_status(credentials_file: str, readonly: bool = False) -> str:
    """Return a human-readable status string for the OAuth token.

    readonly=True checks the _readonly_token.json used by Cloud Sync sources.
    readonly=False (default) checks _token.json used by backup destinations.
    """
    if not credentials_file or not os.path.isfile(credentials_file):
        return ""
    if not _is_oauth_client_secret(credentials_file):
        return ""
    if readonly:
        token_file = credentials_file.replace(".json", "_readonly_token.json")
        scopes     = ["https://www.googleapis.com/auth/drive.readonly"]
    else:
        token_file = credentials_file.replace(".json", "_token.json")
        scopes     = ["https://www.googleapis.com/auth/drive.file"]
    if not os.path.exists(token_file):
        return "Not authenticated — click Authenticate"
    try:
        from google.oauth2.credentials import Credentials
        from google.auth.transport.requests import Request
        creds = Credentials.from_authorized_user_file(token_file, scopes)
        if creds.valid:
            return "✓ Authenticated"
        if creds.expired and creds.refresh_token:
            try:
                creds.refresh(Request())
                with open(token_file, "w") as f:
                    f.write(creds.to_json())
                return "✓ Token refreshed"
            except Exception as e:
                return f"Token expired — re-authenticate ({e})"
        return "Token invalid — re-authenticate"
    except Exception as e:
        return f"Token error: {e}"


def authenticate_oauth(credentials_file: str, readonly: bool = False) -> tuple:
    """
    Run the OAuth consent flow explicitly. Opens a browser window.
    Returns (success: bool, message: str).
    readonly=True uses drive.readonly scope (for Cloud Sync source).
    """
    if not credentials_file or not os.path.isfile(credentials_file):
        return False, "Credentials file not found."
    if not _is_oauth_client_secret(credentials_file):
        return False, "Not an OAuth client secret file. Service account keys don't need authentication."
    try:
        from google_auth_oauthlib.flow import InstalledAppFlow
        if readonly:
            SCOPES     = ["https://www.googleapis.com/auth/drive.readonly"]
            token_file = credentials_file.replace(".json", "_readonly_token.json")
        else:
            SCOPES     = ["https://www.googleapis.com/auth/drive.file"]
            token_file = credentials_file.replace(".json", "_token.json")
        flow = InstalledAppFlow.from_client_secrets_file(credentials_file, SCOPES)
        creds = flow.run_local_server(port=0)
        with open(token_file, "w") as f:
            f.write(creds.to_json())
        return True, "✓ Authenticated successfully. Token saved."
    except Exception as e:
        return False, f"Authentication failed: {e}"


class DriveClient:
    def __init__(self, credentials_file: str, folder_id: str, readonly: bool = False):
        self.credentials_file = credentials_file
        self.folder_id        = folder_id
        self._readonly        = readonly
        self._service         = None

    def _svc(self):
        if not self._service:
            self._service = _get_drive_service(self.credentials_file, readonly=self._readonly)
        return self._service

    def upload_file(
        self,
        local_path:  str,
        remote_name: str,
        on_progress: Optional[ProgressCallback] = None,
    ) -> str:
        """Upload a file to the configured Drive folder. Returns file ID."""
        from googleapiclient.http import MediaFileUpload

        svc       = self._svc()
        file_size = os.path.getsize(local_path)
        media     = MediaFileUpload(
            local_path,
            mimetype="application/octet-stream",
            resumable=True,
            chunksize=CHUNK_SIZE,
        )
        meta = {"name": remote_name, "parents": [self.folder_id]}
        req  = svc.files().create(body=meta, media_body=media, fields="id")

        response = None
        while response is None:
            status, response = req.next_chunk()
            if status and on_progress:
                on_progress(int(status.resumable_progress), file_size)

        if on_progress:
            on_progress(file_size, file_size)
        return response.get("id", "")

    def upload_json(self, data: dict, remote_name: str) -> str:
        """Upload a dict as JSON to the configured Drive folder."""
        import tempfile
        with tempfile.NamedTemporaryFile(
            mode="w", suffix=".json", delete=False
        ) as tmp:
            json.dump(data, tmp, indent=2)
            tmp_path = tmp.name
        try:
            return self.upload_file(tmp_path, remote_name)
        finally:
            os.unlink(tmp_path)

    def list_files(self, name_filter: str = "") -> List[dict]:
        """
        List files in the configured folder.
        Returns list of {id, name, size, modifiedTime, md5Checksum}.
        md5Checksum is None for Google Workspace native files (Docs, Sheets, etc).
        Paginates automatically — Google Drive caps each page at 1000 items.
        """
        svc   = self._svc()
        query = f"'{self.folder_id}' in parents and trashed=false"
        if name_filter:
            safe_filter = name_filter.replace("'", "\\'")
            query += f" and name contains '{safe_filter}'"
        all_files  = []
        page_token = None
        while True:
            kwargs = dict(
                q=query,
                fields="nextPageToken,files(id,name,size,modifiedTime,md5Checksum)",
                pageSize=1000,
                # orderBy omitted intentionally — Drive API re-sorts between paginated
                # requests when orderBy is specified, causing files to be skipped across
                # page boundaries. Stable enumeration requires no ordering constraint.
            )
            if page_token:
                kwargs["pageToken"] = page_token
            results    = svc.files().list(**kwargs).execute()
            page_files = results.get("files", [])
            page_token = results.get("nextPageToken")
            all_files += page_files
            if not page_token:
                break
        return all_files

    def download_file(
        self,
        file_id:     str,
        local_path:  str,
        on_progress: Optional[ProgressCallback] = None,
    ) -> None:
        """Download a Drive file by ID to local_path."""
        from googleapiclient.http import MediaIoBaseDownload
        import io

        svc = self._svc()
        req = svc.files().get_media(fileId=file_id)

        os.makedirs(os.path.dirname(local_path) or ".", exist_ok=True)
        with open(local_path, "wb") as f:
            downloader = MediaIoBaseDownload(f, req, chunksize=CHUNK_SIZE)
            done = False
            while not done:
                status, done = downloader.next_chunk()
                if status and on_progress:
                    on_progress(
                        int(status.resumable_progress),
                        int(status.total_size or 0),
                    )

    def read_json(self, file_id: str) -> dict:
        """Download and parse a JSON file from Drive."""
        import io
        from googleapiclient.http import MediaIoBaseDownload

        svc = self._svc()
        req = svc.files().get_media(fileId=file_id)
        buf = io.BytesIO()
        dl  = MediaIoBaseDownload(buf, req)
        done = False
        while not done:
            _, done = dl.next_chunk()
        return json.loads(buf.getvalue())

    def find_file_id(self, name: str) -> Optional[str]:
        """Return the Drive file ID for a file by name, or None."""
        files = self.list_files(name_filter=name)
        for f in files:
            if f["name"] == name:
                return f["id"]
        return None

    def verify_upload(self, file_id: str, expected_size: int) -> bool:
        """Confirm file exists on Drive and size matches."""
        try:
            svc  = self._svc()
            meta = svc.files().get(fileId=file_id, fields="size").execute()
            return int(meta.get("size", -1)) == expected_size
        except Exception:
            return False


# ---------------------------------------------------------------------------
# Box — OAuth 2.0 (no SDK, raw requests + local redirect server)
# ---------------------------------------------------------------------------

def _box_token_file(credentials_file: str) -> str:
    return credentials_file.replace(".json", "_box_token.json")


def get_box_token_status(credentials_file: str) -> str:
    """Return human-readable Box auth status."""
    if not credentials_file or not os.path.isfile(credentials_file):
        return ""
    token_file = _box_token_file(credentials_file)
    if not os.path.exists(token_file):
        return "Not authenticated — click Authenticate"
    try:
        import requests
        with open(credentials_file) as f:
            creds = json.load(f)
        with open(token_file) as f:
            tok = json.load(f)
        # Try a lightweight API call to validate the token
        resp = requests.get(
            "https://api.box.com/2.0/users/me",
            headers={"Authorization": f"Bearer {tok['access_token']}"},
            timeout=8,
        )
        if resp.status_code == 200:
            name = resp.json().get("name", "")
            return f"✓ Authenticated{(' — ' + name) if name else ''}"
        if resp.status_code == 401:
            # Try refresh
            refreshed, msg = _box_refresh_token(credentials_file, tok)
            return "✓ Token refreshed" if refreshed else f"Token expired — re-authenticate"
        return f"Token status unknown ({resp.status_code})"
    except Exception as e:
        return f"Token error: {e}"


def _box_refresh_token(credentials_file: str, tok: dict) -> tuple:
    """Attempt to refresh a Box access token. Returns (ok, message)."""
    try:
        import requests
        with open(credentials_file) as f:
            creds = json.load(f)
        resp = requests.post("https://api.box.com/oauth2/token", data={
            "grant_type":    "refresh_token",
            "refresh_token": tok["refresh_token"],
            "client_id":     creds["client_id"],
            "client_secret": creds["client_secret"],
        }, timeout=15)
        resp.raise_for_status()
        new_tok = resp.json()
        with open(_box_token_file(credentials_file), "w") as f:
            json.dump(new_tok, f)
        return True, "✓ Token refreshed"
    except Exception as e:
        return False, str(e)


def authenticate_box(credentials_file: str) -> tuple:
    """
    Run Box OAuth2 authorization code flow. Opens system browser with
    a local redirect server to capture the code.
    Returns (success: bool, message: str).
    """
    if not credentials_file or not os.path.isfile(credentials_file):
        return False, "Credentials file not found."
    try:
        import requests
        import socket
        import threading
        import webbrowser
        from http.server import BaseHTTPRequestHandler, HTTPServer
        from urllib.parse import parse_qs, urlparse

        with open(credentials_file) as f:
            creds = json.load(f)
        client_id     = creds.get("client_id", "")
        client_secret = creds.get("client_secret", "")
        if not client_id or not client_secret:
            return False, "credentials JSON must contain client_id and client_secret."

        # Find a free port
        s = socket.socket()
        s.bind(("", 0))
        port = s.getsockname()[1]
        s.close()

        redirect_uri = f"http://localhost:{port}"
        code_holder  = [None]
        error_holder = [None]

        class _Handler(BaseHTTPRequestHandler):
            def do_GET(self):
                params = parse_qs(urlparse(self.path).query)
                code_holder[0]  = params.get("code",  [None])[0]
                error_holder[0] = params.get("error", [None])[0]
                self.send_response(200)
                self.send_header("Content-Type", "text/html")
                self.end_headers()
                self.wfile.write(
                    b"<html><body style='font-family:sans-serif;padding:40px'>"
                    b"<h2>Authenticated! You can close this window.</h2>"
                    b"</body></html>"
                )
            def log_message(self, *args):
                pass

        server = HTTPServer(("localhost", port), _Handler)
        threading.Thread(target=server.handle_request, daemon=True).start()

        auth_url = (
            f"https://account.box.com/api/oauth2/authorize"
            f"?client_id={client_id}&redirect_uri={redirect_uri}&response_type=code"
        )
        webbrowser.open(auth_url)

        import time
        deadline = time.time() + 120
        while code_holder[0] is None and error_holder[0] is None and time.time() < deadline:
            time.sleep(0.2)

        if error_holder[0]:
            return False, f"Box auth error: {error_holder[0]}"
        if not code_holder[0]:
            return False, "Authentication timed out (2 min). Try again."

        # Exchange code for tokens
        resp = requests.post("https://api.box.com/oauth2/token", data={
            "grant_type":   "authorization_code",
            "code":         code_holder[0],
            "client_id":    client_id,
            "client_secret": client_secret,
            "redirect_uri": redirect_uri,
        }, timeout=15)
        resp.raise_for_status()
        tok = resp.json()
        with open(_box_token_file(credentials_file), "w") as f:
            json.dump(tok, f)
        return True, "✓ Authenticated with Box."
    except Exception as e:
        return False, f"Authentication failed: {e}"


class BoxClient:
    API_BASE    = "https://api.box.com/2.0"
    UPLOAD_BASE = "https://upload.box.com/api/2.0"

    def __init__(self, credentials_file: str, folder_id: str = "0"):
        """folder_id: Box folder ID. '0' = root."""
        self.credentials_file = credentials_file
        self.folder_id        = folder_id or "0"

    def _headers(self) -> dict:
        token_file = _box_token_file(self.credentials_file)
        if not os.path.exists(token_file):
            raise RuntimeError(
                "Box not authenticated. Click 'Authenticate with Box' first."
            )
        with open(token_file) as f:
            tok = json.load(f)
        # Proactively refresh if we can (access tokens last 1 hour)
        try:
            import requests as _req
            r = _req.get(f"{self.API_BASE}/users/me",
                         headers={"Authorization": f"Bearer {tok['access_token']}"},
                         timeout=5)
            if r.status_code == 401:
                ok, _ = _box_refresh_token(self.credentials_file, tok)
                if ok:
                    with open(token_file) as f:
                        tok = json.load(f)
        except Exception:
            pass
        return {"Authorization": f"Bearer {tok['access_token']}"}

    def list_files(self, name_filter: str = "") -> List[dict]:
        import requests
        url  = f"{self.API_BASE}/folders/{self.folder_id}/items"
        resp = requests.get(url, headers=self._headers(),
                            params={"limit": 1000, "fields": "id,name,size,modified_at"},
                            timeout=30)
        resp.raise_for_status()
        items = resp.json().get("entries", [])
        if name_filter:
            items = [i for i in items if name_filter in i.get("name", "")]
        return [
            {
                "id":           i["id"],
                "name":         i["name"],
                "size":         i.get("size", 0),
                "modifiedTime": i.get("modified_at", ""),
            }
            for i in items if i.get("type") == "file"
        ]

    def upload_file(
        self,
        local_path:  str,
        remote_name: str,
        on_progress: Optional[ProgressCallback] = None,
    ) -> str:
        """Upload a file to the configured Box folder. Returns item ID."""
        import requests

        file_size = os.path.getsize(local_path)
        url       = f"{self.UPLOAD_BASE}/files/content"
        with open(local_path, "rb") as f:
            resp = requests.post(
                url,
                headers=self._headers(),
                files={"file": (remote_name, f, "application/octet-stream")},
                data={"attributes": json.dumps({
                    "name": remote_name,
                    "parent": {"id": self.folder_id},
                })},
                timeout=600,
            )
        # 409 = file exists, use upload new version endpoint
        if resp.status_code == 409:
            existing_id = resp.json().get("context_info", {}).get(
                "conflicts", [{}]
            )[0].get("id", "")
            if existing_id:
                url = f"{self.UPLOAD_BASE}/files/{existing_id}/content"
                with open(local_path, "rb") as f:
                    resp = requests.post(
                        url,
                        headers=self._headers(),
                        files={"file": (remote_name, f, "application/octet-stream")},
                        data={"attributes": json.dumps({"name": remote_name})},
                        timeout=600,
                    )
        resp.raise_for_status()
        if on_progress:
            on_progress(file_size, file_size)
        entries = resp.json().get("entries", [{}])
        return entries[0].get("id", "") if entries else ""

    def download_file(
        self,
        file_id:     str,
        local_path:  str,
        on_progress: Optional[ProgressCallback] = None,
    ) -> None:
        """Download a Box file by ID to local_path."""
        import requests
        url  = f"{self.API_BASE}/files/{file_id}/content"
        resp = requests.get(url, headers=self._headers(), stream=True, timeout=60)
        resp.raise_for_status()
        total    = int(resp.headers.get("Content-Length", 0))
        received = 0
        os.makedirs(os.path.dirname(local_path) or ".", exist_ok=True)
        with open(local_path, "wb") as f:
            for chunk in resp.iter_content(CHUNK_SIZE):
                f.write(chunk)
                received += len(chunk)
                if on_progress:
                    on_progress(received, total)


# ---------------------------------------------------------------------------
# Backblaze B2 — API key auth (no OAuth, no app registration)
# ---------------------------------------------------------------------------

def test_b2_connection(key_id: str, app_key: str, bucket_name: str) -> tuple:
    """
    Validate B2 credentials by calling b2_authorize_account.
    Returns (success: bool, message: str).
    """
    key_id     = (key_id or "").strip()
    app_key    = (app_key or "").strip()
    bucket_name = (bucket_name or "").strip()
    if not key_id or not app_key:
        return False, "Key ID and Application Key are required."
    try:
        import base64
        import requests
        token = base64.b64encode(f"{key_id}:{app_key}".encode()).decode()
        resp  = requests.get(
            "https://api.backblazeb2.com/b2api/v2/b2_authorize_account",
            headers={"Authorization": f"Basic {token}"},
            timeout=15,
        )
        if resp.status_code == 401:
            return False, "401 Unauthorized — check Key ID and Application Key."
        resp.raise_for_status()
        display = resp.json().get("accountId", key_id)
        return True, f"✓ Connected (account {display}, bucket: {bucket_name or 'not set'})"
    except Exception as e:
        return False, f"Connection failed: {e}"


class B2Client:
    AUTH_URL = "https://api.backblazeb2.com/b2api/v2/b2_authorize_account"

    def __init__(self, key_id: str, app_key: str, bucket_name: str, folder: str = ""):
        """
        key_id:      Backblaze Key ID  (from App Keys page)
        app_key:     Application Key   (from App Keys page)
        bucket_name: Name of the bucket (not the bucket ID)
        folder:      Optional path prefix within the bucket
        """
        self._key_id      = (key_id or "").strip()
        self._app_key     = (app_key or "").strip()
        self._bucket_name = (bucket_name or "").strip()
        self.folder       = folder.strip("/")
        self._auth_cache      = None
        self._bucket_id_cache = None

    def _auth(self) -> dict:
        if self._auth_cache:
            return self._auth_cache
        import base64
        import requests
        token = base64.b64encode(
            f"{self._key_id}:{self._app_key}".encode()
        ).decode()
        resp = requests.get(
            self.AUTH_URL,
            headers={"Authorization": f"Basic {token}"},
            timeout=15,
        )
        resp.raise_for_status()
        self._auth_cache = resp.json()
        return self._auth_cache

    def _bucket_id(self) -> str:
        if self._bucket_id_cache:
            return self._bucket_id_cache
        import requests
        auth = self._auth()
        resp = requests.post(
            f"{auth['apiUrl']}/b2api/v2/b2_list_buckets",
            headers={"Authorization": auth["authorizationToken"]},
            json={"accountId": auth["accountId"], "bucketName": self._bucket_name},
            timeout=15,
        )
        resp.raise_for_status()
        buckets = resp.json().get("buckets", [])
        if not buckets:
            raise RuntimeError(f"B2 bucket '{self._bucket_name}' not found.")
        self._bucket_id_cache = buckets[0]["bucketId"]
        return self._bucket_id_cache

    def list_files(self, name_filter: str = "") -> List[dict]:
        """List latest version of each file. Returns {id, name, size, sha1}."""
        import requests
        auth   = self._auth()
        prefix = (self.folder + "/") if self.folder else ""
        resp   = requests.post(
            f"{auth['apiUrl']}/b2api/v2/b2_list_file_names",
            headers={"Authorization": auth["authorizationToken"]},
            json={
                "bucketId":     self._bucket_id(),
                "prefix":       prefix,
                "maxFileCount": 10000,
            },
            timeout=30,
        )
        resp.raise_for_status()
        result = []
        for f in resp.json().get("files", []):
            name = f["fileName"]
            if prefix and name.startswith(prefix):
                name = name[len(prefix):]
            if "/" in name:                  # skip subfolders
                continue
            if not name:
                continue
            if name.startswith("_suyb_"):    # skip internal manifest files
                continue
            if name_filter and name_filter not in name:
                continue
            sha1 = f.get("contentSha1", "")
            if sha1 == "none":               # B2 returns "none" for unverified legacy uploads
                sha1 = ""
            result.append({
                "id":   f["fileId"],
                "name": name,
                "size": f.get("contentLength", 0),
                "sha1": sha1,
            })
        return result

    def upload_file(
        self,
        local_path:  str,
        remote_name: str,
        on_progress: Optional[ProgressCallback] = None,
    ) -> dict:
        """Upload a file to B2. Returns {"file_id": str, "sha1": str}.
        B2 verifies the SHA1 server-side on receipt — if they don't match
        B2 returns an error, so a successful upload is an implicit integrity check.
        """
        import hashlib
        import requests
        from urllib.parse import quote

        auth          = self._auth()
        file_name     = (self.folder + "/" + remote_name) if self.folder else remote_name
        file_name_enc = quote(file_name, safe="/")
        file_size     = os.path.getsize(local_path)

        # Compute SHA1 before upload — sent as header; B2 verifies on receipt
        sha1 = hashlib.sha1()
        with open(local_path, "rb") as f:
            for chunk in iter(lambda: f.read(CHUNK_SIZE), b""):
                sha1.update(chunk)
        sha1_hex = sha1.hexdigest()

        # Get upload URL
        up_resp = requests.post(
            f"{auth['apiUrl']}/b2api/v2/b2_get_upload_url",
            headers={"Authorization": auth["authorizationToken"]},
            json={"bucketId": self._bucket_id()},
            timeout=15,
        )
        up_resp.raise_for_status()
        up = up_resp.json()

        class _ProgressReader:
            def __init__(self_, fh):
                self_._fh   = fh
                self_._sent = 0
            def read(self_, n=-1):
                data = self_._fh.read(n)
                self_._sent += len(data)
                if on_progress:
                    on_progress(self_._sent, file_size)
                return data

        with open(local_path, "rb") as fh:
            resp = requests.post(
                up["uploadUrl"],
                headers={
                    "Authorization":     up["authorizationToken"],
                    "X-Bz-File-Name":    file_name_enc,
                    "Content-Type":      "application/octet-stream",
                    "Content-Length":    str(file_size),
                    "X-Bz-Content-Sha1": sha1_hex,
                },
                data=_ProgressReader(fh),
                timeout=600,
            )
        resp.raise_for_status()
        data = resp.json()
        # B2 echoes back the SHA1 it stored — should always match sha1_hex
        confirmed_sha1 = data.get("contentSha1", sha1_hex)
        if confirmed_sha1 == "none":
            confirmed_sha1 = sha1_hex
        return {"file_id": data.get("fileId", ""), "sha1": confirmed_sha1}

    def download_file(
        self,
        file_id:     str,
        local_path:  str,
        on_progress: Optional[ProgressCallback] = None,
    ) -> None:
        """Download a B2 file by ID to local_path."""
        import requests
        auth = self._auth()
        url  = f"{auth['downloadUrl']}/b2api/v2/b2_download_file_by_id?fileId={file_id}"
        resp = requests.get(
            url,
            headers={"Authorization": auth["authorizationToken"]},
            stream=True,
            timeout=60,
        )
        resp.raise_for_status()
        total    = int(resp.headers.get("Content-Length", 0))
        received = 0
        os.makedirs(os.path.dirname(local_path) or ".", exist_ok=True)
        with open(local_path, "wb") as f:
            for chunk in resp.iter_content(CHUNK_SIZE):
                f.write(chunk)
                received += len(chunk)
                if on_progress:
                    on_progress(received, total)

    def list_all_versions(self, name_filter: str = "") -> List[dict]:
        """Return ALL historical versions of every file in the bucket/folder.

        Each entry: {id, name, size, sha1, uploaded_ms}
        Only 'upload' actions are included (hides delete markers).
        SHA1 is "" if B2 stored "none" (legacy unverified upload).
        """
        import requests
        auth   = self._auth()
        prefix = (self.folder + "/") if self.folder else ""
        results = []
        start_file_name = None
        start_file_id   = None
        while True:
            payload: dict = {
                "bucketId":     self._bucket_id(),
                "prefix":       prefix,
                "maxFileCount": 1000,
            }
            if start_file_name:
                payload["startFileName"] = start_file_name
            if start_file_id:
                payload["startFileId"] = start_file_id
            resp = requests.post(
                f"{auth['apiUrl']}/b2api/v2/b2_list_file_versions",
                headers={"Authorization": auth["authorizationToken"]},
                json=payload,
                timeout=30,
            )
            resp.raise_for_status()
            data = resp.json()
            for f in data.get("files", []):
                if f.get("action") != "upload":
                    continue
                name = f["fileName"]
                if prefix and name.startswith(prefix):
                    name = name[len(prefix):]
                if "/" in name or not name:
                    continue
                if name.startswith("_suyb_"):    # skip internal files
                    continue
                if name_filter and name_filter not in name:
                    continue
                sha1 = f.get("contentSha1", "")
                if sha1 == "none":
                    sha1 = ""
                results.append({
                    "id":          f["fileId"],
                    "name":        name,
                    "size":        f.get("contentLength", 0),
                    "sha1":        sha1,
                    "uploaded_ms": f.get("uploadTimestamp", 0),
                })
            next_name = data.get("nextFileName")
            next_id   = data.get("nextFileId")
            if not next_name:
                break
            start_file_name = next_name
            start_file_id   = next_id
        return results

    def upload_manifest(self, local_path: str, remote_name: str) -> None:
        """Upload a file to the bucket ROOT (no folder prefix). Used for manifest files."""
        import hashlib
        import requests
        from urllib.parse import quote

        auth      = self._auth()
        file_size = os.path.getsize(local_path)

        sha1 = hashlib.sha1()
        with open(local_path, "rb") as f:
            for chunk in iter(lambda: f.read(CHUNK_SIZE), b""):
                sha1.update(chunk)
        sha1_hex = sha1.hexdigest()

        up_resp = requests.post(
            f"{auth['apiUrl']}/b2api/v2/b2_get_upload_url",
            headers={"Authorization": auth["authorizationToken"]},
            json={"bucketId": self._bucket_id()},
            timeout=15,
        )
        up_resp.raise_for_status()
        up = up_resp.json()

        with open(local_path, "rb") as fh:
            resp = requests.post(
                up["uploadUrl"],
                headers={
                    "Authorization":     up["authorizationToken"],
                    "X-Bz-File-Name":    quote(remote_name, safe="/"),
                    "Content-Type":      "application/json",
                    "Content-Length":    str(file_size),
                    "X-Bz-Content-Sha1": sha1_hex,
                },
                data=fh,
                timeout=60,
            )
        resp.raise_for_status()

    def download_manifest(self, remote_name: str, local_path: str) -> None:
        """Download a file from the bucket ROOT by name. Used for manifest files."""
        import requests
        auth = self._auth()
        url  = f"{auth['downloadUrl']}/file/{self._bucket_name}/{remote_name}"
        resp = requests.get(
            url,
            headers={"Authorization": auth["authorizationToken"]},
            timeout=30,
        )
        resp.raise_for_status()
        os.makedirs(os.path.dirname(local_path) or ".", exist_ok=True)
        with open(local_path, "wb") as f:
            f.write(resp.content)

    def delete_file_version(self, file_id: str, file_name: str) -> None:
        """Delete a specific version of a B2 file by ID."""
        import requests
        auth = self._auth()
        # Prepend folder prefix back on if needed
        full_name = (self.folder + "/" + file_name) if self.folder else file_name
        resp = requests.post(
            f"{auth['apiUrl']}/b2api/v2/b2_delete_file_version",
            headers={"Authorization": auth["authorizationToken"]},
            json={"fileId": file_id, "fileName": full_name},
            timeout=15,
        )
        resp.raise_for_status()

# ---------------------------------------------------------------------------
# Factory
# ---------------------------------------------------------------------------

        return DriveClient(creds, folder)
    if provider == "box" and creds:
        return BoxClient(creds, folder)
    # B2 is only used in cloud sync jobs, not backup profiles.
    # Sync jobs call B2Client(key_id, app_key, bucket_name) directly.
    return None
# ===== SNAPSMACK EOF =====
