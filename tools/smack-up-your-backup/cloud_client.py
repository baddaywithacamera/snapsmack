"""
Smack Up Your Backup — cloud_client.py
Google Drive and OneDrive cloud upload/download.

Google Drive authenticates via OAuth (InstalledAppFlow). Point the app at
your OAuth client secret JSON (Desktop app type, downloaded from Google Cloud
Console). The first backup run opens a browser for consent; after that the
token is cached next to the credentials file and refreshes silently.

Each blog profile can set its own credentials or fall back to the global
cloud config (credentials file + folder ID).
"""

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

    if not creds or not creds.valid:
        if creds and creds.expired and creds.refresh_token:
            creds.refresh(Request())
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


def get_oauth_token_status(credentials_file: str) -> str:
    """Return a human-readable status string for the OAuth token."""
    if not credentials_file or not os.path.isfile(credentials_file):
        return ""
    if not _is_oauth_client_secret(credentials_file):
        return ""
    token_file = credentials_file.replace(".json", "_token.json")
    if not os.path.exists(token_file):
        return "Not authenticated — click Authenticate"
    try:
        from google.oauth2.credentials import Credentials
        from google.auth.transport.requests import Request
        creds = Credentials.from_authorized_user_file(
            token_file, ["https://www.googleapis.com/auth/drive.file"]
        )
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


# ---------------------------------------------------------------------------
# OneDrive helpers
# ---------------------------------------------------------------------------

def authenticate_onedrive(credentials_file: str) -> tuple:
    """
    Run the Microsoft interactive OAuth flow. Opens the system browser.
    Returns (success: bool, message: str).
    credentials_file must contain {"client_id": "..."}.
    """
    if not credentials_file or not os.path.isfile(credentials_file):
        return False, "Credentials file not found."
    try:
        import msal
        with open(credentials_file) as f:
            creds = json.load(f)
        client_id = creds.get("client_id", "")
        if not client_id:
            return False, "No client_id found in credentials file."

        token_cache_file = credentials_file.replace(".json", "_token_cache.bin")
        cache = msal.SerializableTokenCache()
        if os.path.exists(token_cache_file):
            with open(token_cache_file) as f:
                cache.deserialize(f.read())

        app    = msal.PublicClientApplication(
            client_id,
            authority="https://login.microsoftonline.com/common",
            token_cache=cache,
        )
        SCOPES = ["Files.ReadWrite", "offline_access"]
        result = app.acquire_token_interactive(SCOPES)

        if "access_token" not in result:
            return False, f"Authentication failed: {result.get('error_description', 'Unknown error')}"

        if cache.has_state_changed:
            with open(token_cache_file, "w") as f:
                f.write(cache.serialize())

        account = result.get("id_token_claims", {}).get("preferred_username", "")
        return True, f"✓ Authenticated with Microsoft{(' as ' + account) if account else ''}."
    except Exception as e:
        return False, f"Authentication failed: {e}"


def get_onedrive_token_status(credentials_file: str) -> str:
    """Return a human-readable OneDrive auth status string."""
    if not credentials_file or not os.path.isfile(credentials_file):
        return ""
    token_cache_file = credentials_file.replace(".json", "_token_cache.bin")
    if not os.path.exists(token_cache_file):
        return "Not authenticated — click Authenticate"
    try:
        import msal
        with open(credentials_file) as f:
            creds = json.load(f)
        client_id = creds.get("client_id", "")
        if not client_id:
            return "Invalid credentials file — no client_id"
        cache = msal.SerializableTokenCache()
        with open(token_cache_file) as f:
            cache.deserialize(f.read())
        app      = msal.PublicClientApplication(
            client_id,
            authority="https://login.microsoftonline.com/common",
            token_cache=cache,
        )
        accounts = app.get_accounts()
        if not accounts:
            return "Not authenticated — click Authenticate"
        result = app.acquire_token_silent(
            ["Files.ReadWrite", "offline_access"], account=accounts[0]
        )
        if result and "access_token" in result:
            username = accounts[0].get("username", "")
            return f"✓ Authenticated{(' — ' + username) if username else ''}"
        return "Token expired — re-authenticate"
    except Exception as e:
        return f"Token error: {e}"


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
        Returns list of {id, name, size, modifiedTime}.
        """
        svc   = self._svc()
        query = f"'{self.folder_id}' in parents and trashed=false"
        if name_filter:
            query += f" and name contains '{name_filter}'"
        results = (
            svc.files()
            .list(
                q=query,
                fields="files(id,name,size,modifiedTime)",
                orderBy="modifiedTime desc",
            )
            .execute()
        )
        return results.get("files", [])

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
# OneDrive (Microsoft Graph API via MSAL)
# ---------------------------------------------------------------------------

class OneDriveClient:
    AUTHORITY    = "https://login.microsoftonline.com/common"
    SCOPES       = ["Files.ReadWrite", "offline_access"]
    GRAPH_BASE   = "https://graph.microsoft.com/v1.0"

    def __init__(self, credentials_file: str, folder_path: str):
        """
        folder_path: a folder name relative to OneDrive root (e.g. "FoundTexturesBackup"),
                     or "root" for the drive root itself.
        """
        self.credentials_file = credentials_file
        self.folder_path      = folder_path   # display name / path, not an item ID
        self._token:  Optional[str] = None

    def _get_token(self) -> str:
        try:
            import msal
        except ImportError:
            raise RuntimeError("msal is required for OneDrive support.")

        token_cache_file = self.credentials_file.replace(".json", "_token_cache.bin")

        with open(self.credentials_file) as f:
            creds = json.load(f)
        client_id = creds.get("client_id", "")

        cache = msal.SerializableTokenCache()
        if os.path.exists(token_cache_file):
            with open(token_cache_file) as f:
                cache.deserialize(f.read())

        app      = msal.PublicClientApplication(
            client_id, authority=self.AUTHORITY, token_cache=cache
        )
        accounts = app.get_accounts()
        result   = None

        if accounts:
            result = app.acquire_token_silent(self.SCOPES, account=accounts[0])

        if not result:
            # User must authenticate via the UI button before running a sync.
            # Do not trigger interactive flow from a background thread.
            raise RuntimeError(
                "OneDrive not authenticated. "
                "Click 'Authenticate with Microsoft' in the Cloud Sync job settings first."
            )

        if "access_token" not in result:
            raise RuntimeError(f"OneDrive auth failed: {result.get('error_description')}")

        if cache.has_state_changed:
            with open(token_cache_file, "w") as f:
                f.write(cache.serialize())

        return result["access_token"]

    def _folder_children_url(self) -> str:
        """Graph API URL for listing children of the configured folder."""
        if not self.folder_path or self.folder_path.lower() == "root":
            return f"{self.GRAPH_BASE}/me/drive/root/children"
        # Path-based access: root:/FolderName:/children
        return f"{self.GRAPH_BASE}/me/drive/root:/{self.folder_path}:/children"

    def _upload_session_url(self, filename: str) -> str:
        """Graph API URL for creating an upload session for filename in folder."""
        if not self.folder_path or self.folder_path.lower() == "root":
            return f"{self.GRAPH_BASE}/me/drive/root:/{filename}:/createUploadSession"
        return f"{self.GRAPH_BASE}/me/drive/root:/{self.folder_path}/{filename}:/createUploadSession"

    def _headers(self) -> dict:
        if not self._token:
            self._token = self._get_token()
        return {"Authorization": f"Bearer {self._token}"}

    def upload_file(
        self,
        local_path:  str,
        remote_name: str,
        on_progress: Optional[ProgressCallback] = None,
    ) -> str:
        """Upload a file via Graph API upload session. Returns item ID."""
        import requests

        file_size    = os.path.getsize(local_path)
        session_url  = self._upload_session_url(remote_name)
        session_resp = requests.post(
            session_url,
            headers=self._headers(),
            json={"item": {"@microsoft.graph.conflictBehavior": "replace"}},
        )
        session_resp.raise_for_status()
        upload_url = session_resp.json()["uploadUrl"]

        offset  = 0
        item_id = ""
        with open(local_path, "rb") as f:
            while offset < file_size:
                chunk = f.read(CHUNK_SIZE)
                end   = offset + len(chunk) - 1
                headers = {
                    "Content-Length": str(len(chunk)),
                    "Content-Range":  f"bytes {offset}-{end}/{file_size}",
                }
                resp = requests.put(upload_url, data=chunk, headers=headers)
                resp.raise_for_status()
                offset += len(chunk)
                if on_progress:
                    on_progress(offset, file_size)
                if resp.status_code in (200, 201):
                    item_id = resp.json().get("id", "")

        return item_id

    def list_files(self, name_filter: str = "") -> List[dict]:
        import requests
        url  = self._folder_children_url()
        resp = requests.get(url, headers=self._headers())
        resp.raise_for_status()
        items = resp.json().get("value", [])
        if name_filter:
            items = [i for i in items if name_filter in i.get("name", "")]
        return [
            {
                "id":           i["id"],
                "name":         i["name"],
                "size":         i.get("size", 0),
                "modifiedTime": i.get("lastModifiedDateTime", ""),
            }
            for i in items
        ]

    def download_file(
        self,
        file_id:     str,
        local_path:  str,
        on_progress: Optional[ProgressCallback] = None,
    ) -> None:
        import requests
        url  = f"{self.GRAPH_BASE}/me/drive/items/{file_id}/content"
        resp = requests.get(url, headers=self._headers(), stream=True)
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

    def verify_upload(self, item_id: str, expected_size: int) -> bool:
        import requests
        try:
            url  = f"{self.GRAPH_BASE}/me/drive/items/{item_id}"
            resp = requests.get(url, headers=self._headers())
            resp.raise_for_status()
            return int(resp.json().get("size", -1)) == expected_size
        except Exception:
            return False


# ---------------------------------------------------------------------------
# Factory
# ---------------------------------------------------------------------------

def get_cloud_client(profile: dict, global_cloud: Optional[dict] = None):
    """Return the appropriate cloud client for a profile, or None.

    Resolution order for each field:
      1. Profile-level value (if non-empty)
      2. Global cloud config (if provided)
      3. None / empty → skip

    This lets you configure a single OAuth credentials file and Drive folder
    in global settings while still allowing per-profile overrides.
    """
    gc = global_cloud or {}

    def _pick(*vals):
        """Return first non-empty, non-'none' value."""
        for v in vals:
            if v and v != "none":
                return v
        return ""

    provider = _pick(profile.get("cloud_provider"), gc.get("cloud_provider")) or "none"
    creds    = _pick(profile.get("cloud_credentials_file"), gc.get("cloud_credentials_file"))
    folder   = _pick(profile.get("cloud_folder_id"), gc.get("cloud_folder_id"))

    if provider == "google_drive" and creds:
        return DriveClient(creds, folder)
    if provider == "onedrive" and creds:
        return OneDriveClient(creds, folder)   # folder = folder path for OneDrive
    return None
