"""
Smack Up Your Backup — cloud_client.py
Google Drive and OneDrive OAuth + chunked upload/download.
Each blog profile has its own credential file so different accounts
can coexist across blogs.
"""

import json
import os
from typing import Callable, List, Optional

ProgressCallback = Callable[[int, int], None]   # (bytes_done, bytes_total)

CHUNK_SIZE = 5 * 1024 * 1024   # 5 MB


# ---------------------------------------------------------------------------
# Google Drive
# ---------------------------------------------------------------------------

def _get_drive_service(credentials_file: str):
    """Build an authenticated Drive service from a client_secret JSON."""
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

    SCOPES = ["https://www.googleapis.com/auth/drive.file"]
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


class DriveClient:
    def __init__(self, credentials_file: str, folder_id: str):
        self.credentials_file = credentials_file
        self.folder_id        = folder_id
        self._service         = None

    def _svc(self):
        if not self._service:
            self._service = _get_drive_service(self.credentials_file)
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

    def __init__(self, credentials_file: str, folder_id: str):
        self.credentials_file = credentials_file
        self.folder_id        = folder_id
        self._token:  Optional[str] = None
        self._app                   = None

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

        app = msal.PublicClientApplication(
            client_id, authority=self.AUTHORITY, token_cache=cache
        )
        accounts = app.get_accounts()
        result   = None

        if accounts:
            result = app.acquire_token_silent(self.SCOPES, account=accounts[0])

        if not result:
            flow   = app.initiate_device_flow(scopes=self.SCOPES)
            print(flow["message"])   # In UI this would be shown in a dialog
            result = app.acquire_token_by_device_flow(flow)

        if "access_token" not in result:
            raise RuntimeError(f"OneDrive auth failed: {result.get('error_description')}")

        if cache.has_state_changed:
            with open(token_cache_file, "w") as f:
                f.write(cache.serialize())

        return result["access_token"]

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

        file_size = os.path.getsize(local_path)
        url       = (
            f"{self.GRAPH_BASE}/me/drive/items/{self.folder_id}:/{remote_name}:/createUploadSession"
        )
        session_resp = requests.post(
            url, headers=self._headers(), json={"item": {"@microsoft.graph.conflictBehavior": "replace"}}
        )
        session_resp.raise_for_status()
        upload_url = session_resp.json()["uploadUrl"]

        offset = 0
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
        url  = f"{self.GRAPH_BASE}/me/drive/items/{self.folder_id}/children"
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

def get_cloud_client(profile: dict):
    """Return the appropriate cloud client for a profile, or None."""
    provider = profile.get("cloud_provider", "none")
    creds    = profile.get("cloud_credentials_file", "")
    folder   = profile.get("cloud_folder_id", "")

    if provider == "google_drive" and creds:
        return DriveClient(creds, folder)
    if provider == "onedrive" and creds:
        return OneDriveClient(creds, folder)
    return None
