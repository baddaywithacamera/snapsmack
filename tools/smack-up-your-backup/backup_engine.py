"""
Smack Up Your Backup — backup_engine.py
Six-stage backup pipeline.

Stage 1 — Pull recovery kit (HTTP, authenticated)
Stage 2 — Parse manifest
Stage 3 — Differential download via FTP
Stage 4 — Package into dated ZIP
Stage 5 — Cloud push (ZIP + cloud state JSON)
Stage 6 — Verify (local checksums + cloud size + server state upload)
"""

import hashlib
import io
import json
import os
import tarfile
import time
import zipfile
from datetime import datetime, timezone
from typing import Callable, Dict, List, Optional, Tuple

import requests

import cloud_client as cloud_module
import cloud_manifest
import ftp_client as ftp_module
import manifest_reader

# Progress callback: (stage, message, pct_overall)
ProgressCallback = Callable[[str, str, float], None]


# ---------------------------------------------------------------------------
# HTTP session (shared with SYBU auth pattern)
# ---------------------------------------------------------------------------

class SnapSmackSession:
    """Cookie-based HTTP session to the SnapSmack admin panel."""

    def __init__(self, site_url: str):
        self.site_url = site_url.rstrip("/")
        self.session  = requests.Session()
        self.session.headers.update({"User-Agent": "smack-up-your-backup/1.0"})
        self._username = ""
        self._password = ""
        self._logged_in = False

    def login(self, username: str, password: str) -> None:
        self._username = username
        self._password = password
        url  = f"{self.site_url}/login.php"
        resp = self.session.post(
            url,
            data={"username": username, "password": password},
            timeout=15,
            allow_redirects=True,
        )
        resp.raise_for_status()
        if "login.php" in resp.url:
            raise RuntimeError("SnapSmack login failed — check admin credentials.")
        self._logged_in = True

    def is_alive(self) -> bool:
        if not self._logged_in:
            return False
        try:
            resp = self.session.get(
                f"{self.site_url}/smack-admin.php", timeout=10, allow_redirects=True
            )
            return "login.php" not in resp.url
        except Exception:
            return False

    def keepalive(self) -> bool:
        if self.is_alive():
            return True
        return self.relogin()

    def relogin(self) -> bool:
        if not self._username:
            return False
        try:
            self.login(self._username, self._password)
            return True
        except Exception:
            return False

    def download_sql_dump(self, local_path: str, dump_type: str = "full",
                         on_progress: Optional[Callable] = None) -> None:
        """Download a SQL dump via the suyb-export.php endpoint.

        dump_type: 'schema', 'full', 'keys'
        """
        url = f"{self.site_url}/suyb-export.php?type={dump_type}"
        resp = self.session.get(url, stream=True, timeout=120)
        resp.raise_for_status()

        content_type = resp.headers.get("Content-Type", "")
        if "application/sql" not in content_type and "text/" not in content_type:
            # Might be a JSON error response
            try:
                data = resp.json()
                raise RuntimeError(data.get("error", "Unknown export error"))
            except (ValueError, KeyError):
                raise RuntimeError(
                    f"Unexpected response from suyb-export.php "
                    f"(Content-Type: {content_type})"
                )

        self._stream_to_file(resp, local_path, on_progress)

    def download_spoke_sql_dump(self, spoke_url: str, api_key: str,
                                local_path: str, dump_type: str = "full",
                                on_progress: Optional[Callable] = None) -> None:
        """Download a SQL dump from a spoke via the multisite/backup/export
        API endpoint using Bearer token authentication."""
        url = f"{spoke_url.rstrip('/')}/multisite-api.php?resource=backup&sub_action=export&dump={dump_type}"
        resp = requests.get(
            url,
            headers={
                "Authorization": f"Bearer {api_key}",
                "User-Agent": "smack-up-your-backup/1.0",
            },
            stream=True,
            timeout=120,
        )
        resp.raise_for_status()

        content_type = resp.headers.get("Content-Type", "")
        if "application/sql" not in content_type and "text/" not in content_type:
            try:
                data = resp.json()
                raise RuntimeError(data.get("error", "Unknown export error"))
            except (ValueError, KeyError):
                raise RuntimeError(
                    f"Unexpected response from spoke export endpoint "
                    f"(Content-Type: {content_type})"
                )

        self._stream_to_file(resp, local_path, on_progress)

    def download_recovery_kit(self, local_path: str, on_progress: Optional[Callable] = None) -> None:
        """Trigger recovery kit export and download the .tar.gz."""
        # Trigger export — smack-disaster.php expects action=export&type=recovery_kit
        trigger_url = f"{self.site_url}/smack-disaster.php"
        resp = self.session.post(
            trigger_url,
            data={"action": "export", "type": "recovery_kit"},
            timeout=60,
            allow_redirects=True,
        )
        resp.raise_for_status()

        # The export returns a download URL or streams directly
        # Try streaming the response as the kit file
        if "application/x-gzip" in resp.headers.get("Content-Type", "") or \
           "application/octet-stream" in resp.headers.get("Content-Type", ""):
            self._stream_to_file(resp, local_path, on_progress)
            return

        # Fallback: parse a JSON response with a download URL
        try:
            data = resp.json()
            kit_url = data.get("download_url") or data.get("url", "")
            if kit_url:
                if not kit_url.startswith("http"):
                    kit_url = f"{self.site_url}/{kit_url.lstrip('/')}"
                dl_resp = self.session.get(kit_url, stream=True, timeout=120)
                dl_resp.raise_for_status()
                self._stream_to_file(dl_resp, local_path, on_progress)
                return
        except Exception:
            pass

        raise RuntimeError(
            "Could not download recovery kit — check that smack-disaster.php "
            "is accessible and the export completed."
        )

    def _stream_to_file(self, resp, local_path: str, on_progress: Optional[Callable]) -> None:
        total    = int(resp.headers.get("Content-Length", 0))
        received = 0
        os.makedirs(os.path.dirname(local_path) or ".", exist_ok=True)
        with open(local_path, "wb") as f:
            for chunk in resp.iter_content(65536):
                f.write(chunk)
                received += len(chunk)
                if on_progress:
                    on_progress(received, total)


# ---------------------------------------------------------------------------
# Backup state (differential engine)
# ---------------------------------------------------------------------------

def load_backup_state(ftp: ftp_module.FTPClient, backup_state_rel: str = "backups/backup-state.json") -> dict:
    """Download backup-state.json from the server. Returns {} if not found."""
    import tempfile
    try:
        with tempfile.NamedTemporaryFile(suffix=".json", delete=False) as tmp:
            tmp_path = tmp.name
        ok = ftp.download_file(backup_state_rel, tmp_path)
        if ok and os.path.exists(tmp_path):
            with open(tmp_path) as f:
                return json.load(f)
    except Exception:
        pass
    finally:
        try:
            os.unlink(tmp_path)
        except Exception:
            pass
    return {}


def save_backup_state(
    ftp:          ftp_module.FTPClient,
    state:        dict,
    backup_state_rel: str = "backups/backup-state.json",
) -> bool:
    """Upload updated backup-state.json to the server."""
    import tempfile
    try:
        with tempfile.NamedTemporaryFile(
            mode="w", suffix=".json", delete=False
        ) as tmp:
            json.dump(state, tmp, indent=2)
            tmp_path = tmp.name
        # Ensure backups/ directory exists
        ftp.makedirs(f"{ftp.remote_dir}/backups")
        ok = ftp.upload_file(tmp_path, backup_state_rel)
        return ok
    except Exception:
        return False
    finally:
        try:
            os.unlink(tmp_path)
        except Exception:
            pass


def needs_download(record: manifest_reader.FileRecord, state: dict) -> bool:
    """Return True if the file needs to be downloaded (new or changed)."""
    prev = state.get("files", {}).get(record.key)
    if not prev:
        return True
    return prev.get("checksum") != record.checksum or prev.get("size") != record.size


# ---------------------------------------------------------------------------
# Main backup engine
# ---------------------------------------------------------------------------

class BackupEngine:
    def __init__(
        self,
        profile:          dict,
        on_progress:      Optional[ProgressCallback] = None,
        on_log:           Optional[Callable[[str], None]] = None,
        force_full:       bool = False,
        include_settings: bool = False,
        global_config:    Optional[dict] = None,
        global_cloud:     Optional[dict] = None,
    ):
        self.profile          = profile
        self.on_progress      = on_progress or (lambda stage, msg, pct: None)
        self.on_log           = on_log or print
        self.force_full       = force_full
        self.include_settings = include_settings
        self.global_config    = global_config or {}
        self.global_cloud     = global_cloud or {}
        self._cancelled       = False

    def cancel(self) -> None:
        self._cancelled = True

    def _progress(self, stage: str, msg: str, pct: float) -> None:
        self.on_progress(stage, msg, pct)

    def _log(self, msg: str) -> None:
        self.on_log(msg)

    # ------------------------------------------------------------------

    def run(self) -> dict:
        """
        Execute all six stages.  Returns a result dict with keys:
          success, kit_path, zip_path, cloud_id, files_downloaded,
          files_skipped, files_failed, errors
        """
        result = {
            "success":          False,
            "kit_path":         "",
            "zip_path":         "",
            "cloud_id":         "",
            "files_downloaded": 0,
            "files_skipped":    0,
            "files_failed":     0,
            "errors":           [],
        }

        backup_dir = self.profile.get("backup_dir", "")
        if not backup_dir:
            result["errors"].append("No backup directory configured.")
            return result
        os.makedirs(backup_dir, exist_ok=True)

        blog_name  = self.profile.get("name", "blog")
        timestamp  = datetime.now().strftime("%Y-%m-%d_%H-%M")
        kit_path   = os.path.join(backup_dir, f"{blog_name}_recovery_kit_{timestamp}.tar.gz")

        # ── Stage 1: Pull recovery kit ───────────────────────────────
        if self._cancelled:
            return result
        self._progress("stage1", "Connecting to site…", 0.02)
        http = SnapSmackSession(self.profile["site_url"])
        try:
            http.login(
                self.profile.get("snap_admin_user", ""),
                self.profile.get("snap_admin_pass", ""),
            )
        except Exception as e:
            result["errors"].append(f"Login failed: {e}")
            return result

        self._progress("stage1", "Downloading recovery kit…", 0.05)
        try:
            http.download_recovery_kit(
                kit_path,
                on_progress=lambda r, t: self._progress(
                    "stage1", f"Downloading kit… {r // 1024}KB", 0.05 + 0.10 * (r / max(t, 1))
                ),
            )
        except Exception as e:
            result["errors"].append(f"Recovery kit download failed: {e}")
            return result

        result["kit_path"] = kit_path
        self._log(f"Recovery kit saved: {kit_path}")

        # ── Stage 1b: Pull SQL dumps ────────────────────────────────
        if self._cancelled:
            return result
        self._progress("stage1", "Downloading SQL dumps…", 0.12)

        sql_full_path   = os.path.join(backup_dir, f"{blog_name}_full_{timestamp}.sql")
        sql_schema_path = os.path.join(backup_dir, f"{blog_name}_schema_{timestamp}.sql")
        result["sql_full_path"]   = ""
        result["sql_schema_path"] = ""

        try:
            http.download_sql_dump(
                sql_full_path, dump_type="full",
                on_progress=lambda r, t: self._progress(
                    "stage1", f"SQL dump (full)… {r // 1024}KB", 0.12
                ),
            )
            result["sql_full_path"] = sql_full_path
            self._log(f"Full SQL dump saved: {sql_full_path}")
        except Exception as e:
            self._log(f"Full SQL dump failed (non-fatal): {e}")
            result["errors"].append(f"SQL dump (full) failed: {e}")

        try:
            http.download_sql_dump(
                sql_schema_path, dump_type="schema",
                on_progress=lambda r, t: self._progress(
                    "stage1", f"SQL dump (schema)… {r // 1024}KB", 0.14
                ),
            )
            result["sql_schema_path"] = sql_schema_path
            self._log(f"Schema SQL dump saved: {sql_schema_path}")
        except Exception as e:
            self._log(f"Schema SQL dump failed (non-fatal): {e}")
            result["errors"].append(f"SQL dump (schema) failed: {e}")

        # ── Stage 2: Parse manifest ──────────────────────────────────
        if self._cancelled:
            return result
        self._progress("stage2", "Parsing manifest…", 0.15)
        try:
            manifest = manifest_reader.from_tar(kit_path)
        except Exception as e:
            result["errors"].append(f"Manifest parse error: {e}")
            return result

        media_files = {k: v for k, v in manifest.files.items() if not v.bundled}
        self._log(f"Manifest: {len(media_files)} media files, {manifest.stats.get('total_media_bytes', 0) // 1048576}MB")

        # ── Stage 3: Differential download ──────────────────────────
        if self._cancelled:
            return result
        self._progress("stage3", "Connecting via FTP…", 0.18)

        ftp = ftp_module.FTPClient(
            host            = self.profile.get("ftp_host", ""),
            user            = self.profile.get("ftp_user", ""),
            password        = self.profile.get("ftp_pass", ""),
            remote_dir      = self.profile.get("ftp_remote_dir", "/public_html"),
            port            = int(self.profile.get("ftp_port", 21)),
            use_tls         = bool(self.profile.get("ftp_ssl", True)),
            transfer_delay  = float(self.profile.get("pacing_delay", 2)),
            batch_size      = int(self.profile.get("batch_size", 0)),
        )
        try:
            ftp.connect()
        except Exception as e:
            result["errors"].append(f"FTP connection failed: {e}")
            return result

        self._progress("stage3", "Checking previous backup state…", 0.20)
        if self.force_full:
            prev_state = {}   # Empty state → every file counts as new
            self._log("Full backup mode — ignoring previous backup state.")
        else:
            prev_state = load_backup_state(ftp)

        local_media_dir = os.path.join(backup_dir, f"{blog_name}_media_{timestamp}")
        os.makedirs(local_media_dir, exist_ok=True)

        total_files = len(media_files)
        done        = 0

        for key, record in media_files.items():
            if self._cancelled:
                break

            pct = 0.20 + 0.45 * (done / max(total_files, 1))

            if not needs_download(record, prev_state):
                result["files_skipped"] += 1
                done += 1
                self._progress("stage3", f"Skip (unchanged): {record.restores_to}", pct)
                continue

            local_path = os.path.join(local_media_dir, record.restores_to.replace("/", os.sep))
            self._progress("stage3", f"Downloading: {record.restores_to}", pct)

            ok = ftp.download_file(
                record.restores_to, local_path,
                on_progress=lambda fn, r, t, s: None,  # per-file byte progress ignored at this level
            )
            if ok:
                result["files_downloaded"] += 1
            else:
                result["files_failed"] += 1
                result["errors"].append(f"Download failed: {record.restores_to}")
            done += 1

        # ── Stage 4: Package ─────────────────────────────────────────
        if self._cancelled:
            ftp.disconnect()
            return result

        self._progress("stage4", "Packaging backup ZIP…", 0.66)
        zip_name = f"{blog_name}_backup_{timestamp}.zip"
        zip_path = os.path.join(backup_dir, zip_name)

        # Build new backup state
        new_state_files = dict(prev_state.get("files", {}))
        now_iso = datetime.now(timezone.utc).isoformat()

        for key, record in media_files.items():
            local_path = os.path.join(local_media_dir, record.restores_to.replace("/", os.sep))
            if os.path.exists(local_path):
                new_state_files[key] = {
                    "size":      record.size,
                    "checksum":  record.checksum,
                    "backed_up": now_iso,
                }

        new_state = {
            "blog_name":          blog_name,
            "last_backup":        now_iso,
            "snapsmack_version":  manifest.snapsmack_version,
            "total_files":        len(new_state_files),
            "total_bytes":        sum(v["size"] for v in new_state_files.values()),
            "files":              new_state_files,
        }

        try:
            with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as zf:
                # Include the recovery kit
                zf.write(kit_path, os.path.basename(kit_path))
                # Include SQL dumps (if available)
                if result.get("sql_full_path") and os.path.exists(result["sql_full_path"]):
                    zf.write(result["sql_full_path"], f"database/{os.path.basename(result['sql_full_path'])}")
                if result.get("sql_schema_path") and os.path.exists(result["sql_schema_path"]):
                    zf.write(result["sql_schema_path"], f"database/{os.path.basename(result['sql_schema_path'])}")
                # Include backup-state.json
                zf.writestr("backup-state.json", json.dumps(new_state, indent=2))
                # Include all downloaded media files
                for dirpath, _, fnames in os.walk(local_media_dir):
                    for fname in fnames:
                        full = os.path.join(dirpath, fname)
                        arc  = os.path.relpath(full, local_media_dir)
                        zf.write(full, arc)
                # Optionally include SUYB settings (profile + global config)
                if self.include_settings:
                    settings_bundle = {
                        "export_version": 1,
                        "profile":        {k: v for k, v in self.profile.items()
                                           if k not in ("ftp_pass", "snap_admin_pass")},
                        "global_config":  self.global_config,
                    }
                    zf.writestr("suyb-settings.json", json.dumps(settings_bundle, indent=2))
                    self._log("SUYB settings bundled into backup.")
        except Exception as e:
            result["errors"].append(f"Packaging failed: {e}")
            ftp.disconnect()
            return result

        result["zip_path"] = zip_path
        self._log(f"Backup package: {zip_path}")

        # ── Stage 5: Cloud push ──────────────────────────────────────
        self._progress("stage5", "Pushing to cloud…", 0.72)
        cloud = cloud_module.get_cloud_client(self.profile, global_cloud=self.global_cloud)
        cloud_id = ""

        if cloud:
            try:
                cloud_id = cloud.upload_file(
                    zip_path, zip_name,
                    on_progress=lambda r, t: self._progress(
                        "stage5", f"Cloud upload… {r // 1048576}MB / {t // 1048576}MB",
                        0.72 + 0.18 * (r / max(t, 1))
                    ),
                )
                result["cloud_id"] = cloud_id
                self._log(f"Cloud upload complete: {cloud_id}")

                # List current ZIPs for the cloud state index
                available_zips = cloud_manifest.list_available_backups(cloud)
                cloud_manifest.push_cloud_state(cloud, self.profile, new_state, available_zips)
                self._log("Cloud state JSON updated.")
            except Exception as e:
                result["errors"].append(f"Cloud push failed: {e}")
                # Non-fatal — continue to verify

        # ── Stage 6: Verify ──────────────────────────────────────────
        self._progress("stage6", "Verifying…", 0.92)
        verify_ok = True

        # Local verification: spot-check the zip is readable
        try:
            with zipfile.ZipFile(zip_path, "r") as zf:
                bad = zf.testzip()
                if bad:
                    result["errors"].append(f"ZIP CRC error on: {bad}")
                    verify_ok = False
        except Exception as e:
            result["errors"].append(f"ZIP verification failed: {e}")
            verify_ok = False

        # Cloud verification
        if cloud and cloud_id:
            zip_size = os.path.getsize(zip_path)
            if not cloud.verify_upload(cloud_id, zip_size):
                result["errors"].append("Cloud file size mismatch after upload.")
                verify_ok = False

        # Upload updated backup-state.json to server
        self._progress("stage6", "Updating server state…", 0.96)
        state_ok = save_backup_state(ftp, new_state)
        if not state_ok:
            result["errors"].append("Could not upload backup-state.json to server (non-fatal).")

        ftp.disconnect()

        if verify_ok:
            result["success"] = True
            self._progress("done", f"Backup complete — {result['files_downloaded']} downloaded, {result['files_skipped']} skipped.", 1.0)
            self._log("Backup successful.")
        else:
            self._progress("done", "Backup completed with errors.", 1.0)

        return result
