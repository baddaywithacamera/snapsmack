"""Authenticated, bounded reads for the backup connection overview.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

from dataclasses import dataclass
from datetime import datetime
import os

import requests

import backup_engine
import cloud_client
from cloud_sync_engine import CloudSyncEngine

CONNECTED = "Connected"
SIGN_IN = "Needs sign-in"
UNREACHABLE = "Unreachable"


@dataclass(frozen=True)
class ConnectionResult:
    label: str
    state: str
    checked: str
    fix: tuple


class SignInRequired(RuntimeError):
    pass


def _check(label, read, fix):
    try:
        read()
        state = CONNECTED
    except (SignInRequired, cloud_client.DriveAuthorizationExpired):
        state = SIGN_IN
    except Exception as error:
        response = getattr(error, "response", None)
        status = getattr(response, "status_code", None)
        status = status or getattr(getattr(error, "resp", None), "status", None)
        state = SIGN_IN if status in (401, 403) else UNREACHABLE
    return ConnectionResult(label, state, datetime.now().astimezone().strftime("%Y-%m-%d %H:%M:%S %Z"), fix)


def _site_read(site, key, *, database=False):
    if not key:
        raise SignInRequired()
    if backup_engine._insecure_transport_reason(site):
        raise ValueError("Unsafe site URL")
    session = backup_engine.SnapSmackSession(site, api_key=key)
    endpoint = "/suyb-export.php?type=schema" if database else "/suyb-data.php"
    with session.session as http:
        with http.get(site.rstrip("/") + endpoint, timeout=(5, 15),
                      allow_redirects=False, stream=database) as response:
            response.raise_for_status()
            if response.status_code != 200:
                raise ValueError("Unexpected response")
            if database:
                if "application/sql" not in response.headers.get("Content-Type", "").lower():
                    raise ValueError("Schema export unavailable")
                sample = bytearray()
                for chunk in response.iter_content(4096):
                    sample.extend(chunk)
                    if b"CREATE TABLE" in sample.upper():
                        return
                    if len(sample) >= 65536:
                        break
                raise ValueError("Schema export did not return table definitions")
            if "application/json" not in response.headers.get("Content-Type", "").lower():
                raise ValueError("Expected authenticated site metadata")
            if response.json().get("ok") is not True:
                raise ValueError("Site metadata unavailable")


def _cloud_read(client):
    if isinstance(client, cloud_client.DriveClient):
        if not client.credentials_file:
            raise SignInRequired()
        service = client._svc()
        if client.folder_id:
            meta = service.files().get(fileId=client.folder_id, fields="id,mimeType,trashed",
                                       supportsAllDrives=True).execute()
            if meta.get("trashed") or meta.get("mimeType") != "application/vnd.google-apps.folder":
                raise ValueError("Expected a visible Drive folder")
        else:
            # Check account access without resolving or creating a backup folder.
            service.files().list(pageSize=1, fields="files(id)").execute()
    elif isinstance(client, cloud_client.BoxClient):
        if not client.credentials_file or not os.path.isfile(cloud_client._box_token_file(client.credentials_file)):
            raise SignInRequired()
        response = requests.get(f"{client.API_BASE}/folders/{client.folder_id}",
                                params={"fields": "id"}, headers=client._headers(), timeout=15)
        response.raise_for_status()
        if not response.json().get("id"):
            raise ValueError("Box folder unavailable")
    elif isinstance(client, cloud_client.B2Client):
        auth = client._auth()
        response = requests.post(f"{auth['apiUrl']}/b2api/v2/b2_list_file_names",
                                 headers={"Authorization": auth["authorizationToken"]},
                                 json={"bucketId": client._bucket_id(), "maxFileCount": 1},
                                 timeout=15)
        response.raise_for_status()
        if not isinstance(response.json().get("files"), list):
            raise ValueError("Backblaze bucket unavailable")
    else:
        raise SignInRequired()


def _sync_read(provider, job):
    if provider in ("backblaze_b2", "b2") and not (
        job.get("dest_b2_key_id") and job.get("dest_b2_app_key")
    ):
        raise SignInRequired()
    _cloud_read(CloudSyncEngine._build_client(provider, job, "dest"))


def check_connections(profile, global_cloud, jobs, key):
    site = profile.get("site_url", "")
    provider = profile.get("cloud_provider") or global_cloud.get("cloud_provider") or "none"
    creds = profile.get("cloud_credentials_file") or global_cloud.get("cloud_credentials_file") or ""
    checks = [
        ("CMS", lambda: _site_read(site, key), ("settings", 8)),
        ("Database export", lambda: _site_read(site, key, database=True), ("settings", 8)),
    ]
    if provider not in ("none", "local", "disabled"):
        fix = ("drive", 9, creds) if provider == "google_drive" and creds else ("settings", 9)
        checks.append((f"Backup · {CloudSyncEngine._provider_label(provider)}",
                       lambda: _cloud_read(cloud_client.get_cloud_client(profile, global_cloud)), fix))
    for name, job in jobs:
        if job is None:
            def unavailable():
                raise SignInRequired()
            checks.append((f"Cloud Sync · {name}", unavailable, ("settings", 6, name)))
            continue
        destination = job.get("dest_provider", "backblaze_b2")
        credentials = job.get("dest_credentials_file", "")
        fix = ("drive", 6, credentials, name) if destination == "google_drive" and credentials else ("settings", 6, name)
        checks.append((f"Cloud Sync · {name} · {CloudSyncEngine._provider_label(destination)}",
                       lambda job=job, provider=destination: _sync_read(provider, job), fix))
    return [_check(*item) for item in checks]


# ===== SNAPSMACK EOF =====
