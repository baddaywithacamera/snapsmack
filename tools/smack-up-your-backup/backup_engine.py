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

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.




import hashlib
import io
import json
import os
import tarfile
import threading
import time
import zipfile
from datetime import datetime, timezone
from typing import Callable, Dict, List, Optional, Tuple

# Pause and ask the user after this many download failures (already retried once each).
# 1 = stop on the very first unrecoverable failure.  0 = disable the prompt entirely.
FAILURE_PROMPT_THRESHOLD = 1

import requests

import cloud_client as cloud_module
import cloud_manifest
import checkpoint as checkpoint_module
import ftp_client as ftp_module
import manifest_reader

# Progress callback: (stage, message, pct_overall)
ProgressCallback = Callable[[str, str, float], None]


def filename_token(site_url: str, fallback: str = "blog") -> str:
    """Slugify a site URL into a filesystem-safe token used in backup filenames,
    so archives from different sites are unambiguous in one shared directory.
    e.g. 'https://photowalk.ing/' -> 'photowalk-ing'. Falls back to `fallback`
    (the profile name) if no usable URL is given."""
    s = (site_url or "").strip().lower()
    for pre in ("https://", "http://"):
        if s.startswith(pre):
            s = s[len(pre):]
            break
    s = s.strip("/")
    token = "".join(ch if ch.isalnum() else "-" for ch in s)
    while "--" in token:
        token = token.replace("--", "-")
    token = token.strip("-")
    if token:
        return token
    fb = "".join(ch if ch.isalnum() else "-" for ch in (fallback or "").strip().lower())
    return fb.strip("-") or "blog"


# ---------------------------------------------------------------------------
# HTTP session (shared with SYBU auth pattern)
# ---------------------------------------------------------------------------

class SnapSmackSession:
    """Cookie-based HTTP session to the SnapSmack admin panel."""

    def __init__(self, site_url: str, api_key: str = ""):
        self.site_url = site_url.rstrip("/")
        self.session  = requests.Session()
        self.session.headers.update({"User-Agent": "smack-up-your-backup/1.0"})
        self._username = ""
        self._password = ""
        self._api_key  = (api_key or "").strip()
        self._logged_in = False
        if self._api_key:
            # Key auth: every request carries X-Snap-Key — no login, and no
            # session to time out on long jobs. Validated by core/api-auth.php.
            self.session.headers["X-Snap-Key"] = self._api_key
            self._logged_in = True

    def login(self, username: str = "", password: str = "") -> None:
        # API-key profiles never log in — the key header is already set.
        if self._api_key:
            self._logged_in = True
            return
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
        if self._api_key:
            return True   # key auth doesn't expire
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
        if self._api_key:
            return True   # nothing to refresh — the key is stateless
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

    def report_backup_complete(self, status: str = "clean",
                               size_bytes: int = 0,
                               destination: str = "") -> None:
        """Tell the site a backup just completed, so the Multisite dashboard can
        show backup freshness. POSTs to suyb-complete.php using the logged-in
        admin session (same auth as the rest of SUYB). Best-effort — the caller
        treats any failure here as non-fatal."""
        url  = f"{self.site_url}/suyb-complete.php"
        data = {"status": status}
        if size_bytes:
            data["size_bytes"] = str(int(size_bytes))
        if destination:
            data["destination"] = destination
        resp = self.session.post(url, data=data, timeout=30)
        resp.raise_for_status()
        body = resp.json()
        if not body.get("ok"):
            raise RuntimeError(body.get("error", "backup-complete rejected"))

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
        ok, _ = ftp.download_file(backup_state_rel, tmp_path)
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
        on_ask:           Optional[Callable[[str], None]] = None,
        on_stats:         Optional[Callable[[int, int, int, int, int, int], None]] = None,
        force_full:       bool = False,
        include_settings: bool = False,
        global_config:    Optional[dict] = None,
        global_cloud:     Optional[dict] = None,
        resume_checkpoint: Optional["checkpoint_module.BackupCheckpoint"] = None,
    ):
        self.profile           = profile
        self.on_progress       = on_progress or (lambda stage, msg, pct: None)
        self.on_log            = on_log or print
        self.on_ask            = on_ask   # Callable[[str], None] — engine blocks until caller responds
        # on_stats(files_done, files_total, files_failed, bytes_done, bytes_total, bytes_failed)
        self.on_stats          = on_stats or (lambda *a: None)
        self.force_full        = force_full
        self.include_settings  = include_settings
        self.global_config     = global_config or {}
        self.global_cloud      = global_cloud or {}
        self._cancelled        = False
        self._resume_cp        = resume_checkpoint
        self._prompt_event     = threading.Event()
        self._prompt_continue  = False
        self._asked_once       = False    # only prompt the user once per run

    def cancel(self) -> None:
        self._cancelled = True
        self._prompt_event.set()   # unblock engine if it's waiting for a prompt response

    def prompt_continue(self) -> None:
        """Called by the UI when the user chooses to continue after a failure prompt."""
        self._prompt_continue = True
        self._prompt_event.set()

    def _ask_user(self, msg: str) -> bool:
        """Pause the engine thread and ask the UI whether to abort or continue.

        Returns True if the user chose to continue, False if they chose to abort
        (or if there is no on_ask handler, which is the case for scheduled backups).
        Only fires once per run — subsequent failures won't re-prompt.
        """
        if self._asked_once:
            return True   # user already said continue; keep going
        self._asked_once = True

        if not self.on_ask:
            # No interactive handler (e.g. scheduled backup) — abort automatically.
            self._log("✗ Failure threshold reached — aborting (unattended run).")
            return False

        self._prompt_event.clear()
        self._prompt_continue = False
        self.on_ask(msg)
        self._prompt_event.wait()   # blocks until prompt_continue() or cancel() is called
        return self._prompt_continue

    def _progress(self, stage: str, msg: str, pct: float) -> None:
        self.on_progress(stage, msg, pct)

    def _log(self, msg: str) -> None:
        self.on_log(msg)

    def _write_log_file(self, backup_dir: str, blog_name: str,
                        timestamp: str, result: dict) -> None:
        """Write a persistent session log to backup_dir/logs/."""
        try:
            log_dir = os.path.join(backup_dir, "logs")
            os.makedirs(log_dir, exist_ok=True)
            log_path = os.path.join(log_dir, f"{blog_name}_backup_{timestamp}.log")
            with open(log_path, "w", encoding="utf-8") as f:
                f.write(f"Smack Up Your Backup — session log\n")
                f.write(f"Blog:      {self.profile.get('name', '')}\n")
                f.write(f"Site:      {self.profile.get('site_url', '')}\n")
                f.write(f"Timestamp: {timestamp}\n")
                f.write(f"Mode:      {'Full' if self.force_full else 'Differential'}\n")
                f.write("─" * 60 + "\n\n")
                f.write(f"Files downloaded: {result.get('files_downloaded', 0)}\n")
                f.write(f"Files skipped:    {result.get('files_skipped', 0)}\n")
                f.write(f"Files failed:     {result.get('files_failed', 0)}\n")
                if result.get('files_cancelled', 0):
                    f.write(f"Files cancelled:  {result.get('files_cancelled', 0)}\n")
                f.write(f"ZIP:              {result.get('zip_path', '')}\n")
                f.write(f"Cloud ID:         {result.get('cloud_id', '') or 'Not uploaded'}\n")
                if result.get('cancelled'):
                    f.write(f"Status:           Cancelled\n")
                else:
                    f.write(f"Success:          {result.get('success', False)}\n")
                if result.get("errors"):
                    f.write("\nERRORS:\n")
                    for err in result["errors"]:
                        f.write(f"  ✗ {err}\n")
                else:
                    f.write("\nNo errors.\n")
        except Exception:
            pass  # never let logging break a backup

    # ------------------------------------------------------------------

    def run(self) -> dict:
        """
        Execute all six stages.  Returns a result dict with keys:
          success, kit_path, zip_path, cloud_id, files_downloaded,
          files_skipped, files_failed, errors

        If self._resume_cp is set, stages 1-2 are skipped (kit already
        on disk) and Stage 3 skips files already recorded in the checkpoint.
        """
        result = {
            "success":          False,
            "kit_path":         "",
            "zip_path":         "",
            "cloud_id":         "",
            "files_downloaded": 0,
            "files_skipped":    0,
            "files_failed":     0,
            "files_cancelled":  0,
            "cancelled":        False,
            "errors":           [],
        }

        backup_dir = self.profile.get("backup_dir", "")
        if not backup_dir:
            result["errors"].append("No backup directory configured.")
            return result
        os.makedirs(backup_dir, exist_ok=True)

        blog_name = self.profile.get("name", "blog")
        # File prefix is the site-URL slug (unambiguous across sites in one
        # shared cloud folder); falls back to the profile name if no URL.
        file_token = filename_token(self.profile.get("site_url", ""), blog_name)
        cp        = self._resume_cp  # None for fresh run, populated for resume
        resuming  = cp is not None

        if resuming:
            # ── Restore state from checkpoint ────────────────────────
            timestamp       = cp.data["timestamp"]
            kit_path        = cp.data["kit_path"]
            sql_full_path   = cp.data.get("sql_full_path", "")
            sql_schema_path = cp.data.get("sql_schema_path", "")
            local_media_dir = cp.data["local_media_dir"]
            zip_name        = cp.data["zip_name"]
            prev_state      = cp.data.get("prev_state", {})
            already_done    = cp.already_downloaded()
            result["files_downloaded"] = cp.data.get("files_downloaded", 0)
            result["files_skipped"]    = cp.data.get("files_skipped", 0)
            result["files_failed"]     = cp.data.get("files_failed", 0)
            self._log(f"Resuming interrupted backup from {cp.data.get('created_at', 'unknown time')}.")
            self._log(f"Already downloaded: {len(already_done)} files — skipping those.")
        else:
            timestamp       = datetime.now().strftime("%Y-%m-%d_%H-%M-%S")
            kit_path        = os.path.join(backup_dir, f"{file_token}_recovery_kit_{timestamp}.tar.gz")
            sql_full_path   = ""
            sql_schema_path = ""
            local_media_dir = ""
            zip_name        = ""
            prev_state      = {}
            already_done    = set()

            # ── Stage 1: Pull recovery kit ───────────────────────────
            if self._cancelled:
                return result
            self._progress("stage1", "Connecting to site…", 0.02)
            http = SnapSmackSession(self.profile["site_url"],
                                    self.profile.get("api_key", ""))
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

            # ── Stage 1b: Pull SQL dumps ─────────────────────────────
            if self._cancelled:
                return result
            self._progress("stage1", "Downloading SQL dumps…", 0.12)

            sql_full_path   = os.path.join(backup_dir, f"{file_token}_full_{timestamp}.sql")
            sql_schema_path = os.path.join(backup_dir, f"{file_token}_schema_{timestamp}.sql")
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

        # Set up paths for a fresh run (resuming already has these)
        if not resuming:
            local_media_dir = os.path.join(backup_dir, f"{file_token}_media_{timestamp}")
            zip_name        = f"{file_token}_backup_{timestamp}.zip"

        os.makedirs(local_media_dir, exist_ok=True)

        # Check if all files are already done (crash during packaging)
        remaining = {k: v for k, v in media_files.items() if k not in already_done}
        need_ftp  = bool(remaining)

        if need_ftp:
            self._progress("stage3", "Connecting via FTP…", 0.18)
            ftp = ftp_module.FTPClient(
                host            = self.profile.get("ftp_host", ""),
                user            = self.profile.get("ftp_user", ""),
                password        = self.profile.get("ftp_pass", ""),
                remote_dir      = self.profile.get("ftp_remote_dir", "/"),
                port            = int(self.profile.get("ftp_port", 21)),
                use_tls         = bool(self.profile.get("ftp_ssl", True)),
                verify_cert     = bool(self.profile.get("ftp_verify_cert", False)),
                transfer_delay  = float(self.profile.get("pacing_delay", 2)),
                batch_size      = int(self.profile.get("batch_size", 0)),
            )
            try:
                ftp.connect()
            except Exception as e:
                result["errors"].append(f"FTP connection failed: {e}")
                return result
        else:
            ftp = None
            self._log("All files already downloaded — skipping FTP stage.")

        # Load prev_state for fresh runs (resuming already has it from checkpoint)
        if not resuming and need_ftp:
            self._progress("stage3", "Checking previous backup state…", 0.20)
            if self.force_full:
                prev_state = {}
                self._log("Full backup mode — ignoring previous backup state.")
            else:
                prev_state = load_backup_state(ftp)

        # Create checkpoint for this run (fresh only — resume uses existing cp)
        if not resuming:
            cp_path = checkpoint_module.BackupCheckpoint.path_for(backup_dir, blog_name)
            cp = checkpoint_module.BackupCheckpoint(cp_path)
            cp.start(
                blog_name       = blog_name,
                timestamp       = timestamp,
                kit_path        = kit_path,
                sql_full_path   = sql_full_path,
                sql_schema_path = sql_schema_path,
                local_media_dir = local_media_dir,
                zip_name        = zip_name,
                prev_state      = prev_state,
                force_full      = self.force_full,
            )

        total_files     = len(media_files)
        bytes_total     = sum(r.size for r in media_files.values() if r.size)
        bytes_done      = 0
        bytes_failed    = 0
        done            = 0
        consec_failures = 0   # counts up to FAILURE_PROMPT_THRESHOLD then triggers prompt

        for key, record in media_files.items():
            if self._cancelled:
                break

            pct = 0.20 + 0.45 * (done / max(total_files, 1))

            # Skip files already confirmed downloaded in a previous run
            if key in already_done:
                done += 1
                self._progress("stage3", f"Resume skip: {record.restores_to}", pct)
                continue

            if not needs_download(record, prev_state):
                result["files_skipped"] += 1
                done += 1
                cp.record(key, skipped=True)
                self._progress("stage3", f"Skip (unchanged): {record.restores_to}", pct)
                continue

            local_path = os.path.join(local_media_dir, record.restores_to.replace("/", os.sep))
            self._progress("stage3", f"Downloading: {record.restores_to}", pct)

            ok, dl_err = ftp.download_file(
                record.restores_to, local_path,
                on_progress=lambda fn, r, t, s: None,
            )

            # ── Post-download checksum verification ──────────────────
            if ok and record.checksum and os.path.exists(local_path):
                actual = ftp_module.FTPClient.sha256_file(local_path)
                if actual != record.checksum:
                    self._log(f"✗ Checksum mismatch: {record.restores_to} — retrying once")
                    ok, dl_err = ftp.download_file(
                        record.restores_to, local_path,
                        on_progress=lambda fn, r, t, s: None,
                    )
                    if ok:
                        actual = ftp_module.FTPClient.sha256_file(local_path)
                        if actual != record.checksum:
                            dl_err = f"checksum wrong after retry (expected {record.checksum[:8]}… got {actual[:8]}…)"
                            self._log(
                                f"✗ Checksum wrong after retry: {record.restores_to}\n"
                                f"  Expected: {record.checksum}\n"
                                f"  Got:      {actual}"
                            )
                            ok = False
                        else:
                            self._log(f"✓ Checksum OK on retry: {record.restores_to}")

            if ok:
                result["files_downloaded"] += 1
                bytes_done += record.size or 0
                cp.record(key, downloaded=True)
                consec_failures = 0   # reset streak on success
            elif self._cancelled:
                # In-flight file interrupted by cancel — not a real failure
                result["files_cancelled"] += 1
                cp.record(key, failed=True)
                self._log(f"↩ Cancelled: {record.restores_to}")
            else:
                result["files_failed"] += 1
                bytes_failed += record.size or 0
                cp.record(key, failed=True)
                err_msg = f"Download/verify failed: {record.restores_to}"
                if dl_err:
                    err_msg += f" — {dl_err}"
                self._log(f"✗ {err_msg}")
                result["errors"].append(err_msg)
                consec_failures += 1

                # ── Failure threshold prompt ──────────────────────────
                if (FAILURE_PROMPT_THRESHOLD > 0
                        and consec_failures >= FAILURE_PROMPT_THRESHOLD
                        and not self._asked_once):
                    ask_msg = (
                        f"Download failed (retried once — not recoverable).\n\n"
                        f"Error: {dl_err or 'unknown'}\n"
                        f"File:  {record.restores_to}\n\n"
                        f"Downloaded so far: {result['files_downloaded']}    "
                        f"Failed: {result['files_failed']}    "
                        f"Remaining: {total_files - done - 1}"
                    )
                    should_continue = self._ask_user(ask_msg)
                    if not should_continue:
                        self._cancelled = True
                        break
                    consec_failures = 0   # user said continue — reset streak

            self.on_stats(
                result["files_downloaded"] + result["files_failed"],
                total_files,
                result["files_failed"],
                bytes_done,
                bytes_total,
                bytes_failed,
            )

            done += 1

        # ── Stage 4: Package ─────────────────────────────────────────
        if self._cancelled:
            result["cancelled"] = True
            if ftp:
                ftp.disconnect()
            return result

        self._progress("stage4", "Packaging backup ZIP…", 0.66)
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
            if ftp:
                ftp.disconnect()
            return result

        result["zip_path"] = zip_path
        self._log(f"Backup package: {zip_path}")

        # ── Stage 5: Cloud push ──────────────────────────────────────
        self._progress("stage5", "Pushing to cloud…", 0.72)

        if result["files_failed"] > 0:
            msg = (
                f"Cloud upload skipped — {result['files_failed']} file(s) failed to download. "
                f"Pushing an incomplete backup would overwrite a good one."
            )
            self._log(f"✗ {msg}")
            result["errors"].append(msg)
            if ftp:
                ftp.disconnect()
            return result

        cloud = cloud_module.get_cloud_client(self.profile, global_cloud=self.global_cloud)
        cloud_id = ""

        if not cloud:
            provider = (self.profile.get("cloud_provider")
                        or (self.global_cloud or {}).get("cloud_provider") or "none")
            creds    = (self.profile.get("cloud_credentials_file")
                        or (self.global_cloud or {}).get("cloud_credentials_file") or "")
            if provider in ("google_drive", "onedrive"):
                self._log(f"Cloud upload skipped — provider is {provider} but no credentials file is set.")
                result["errors"].append("Cloud upload skipped — set SA key file in Settings → Global Cloud Config and click Save Defaults.")
            else:
                self._log("Cloud upload skipped — no cloud provider configured.")

        if cloud:
            try:
                self._log("Connecting to cloud storage…")
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
                err = f"Cloud push failed: {e}"
                result["errors"].append(err)
                self._log(f"✗ {err}")   # always visible in log pane

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
        if ftp:
            state_ok = save_backup_state(ftp, new_state)
            if not state_ok:
                result["errors"].append("Could not upload backup-state.json to server (non-fatal).")
            ftp.disconnect()

        # ── Log any errors that weren't already logged ───────────────
        non_fatal = [e for e in result["errors"]
                     if not e.startswith("✗") and "Cloud push failed" not in e
                     and "Download" not in e]
        for err in non_fatal:
            self._log(f"⚠ {err}")

        # ── Write session log file ────────────────────────────────────
        self._write_log_file(backup_dir, file_token, timestamp, result)

        if verify_ok:
            result["success"] = True
            self._progress("done", f"Backup complete — {result['files_downloaded']} downloaded, {result['files_skipped']} skipped.", 1.0)
            self._log("Backup successful.")
            cp.delete()
            self._log("Checkpoint cleared.")
        else:
            self._progress("done", "Backup completed with errors — check the log file.", 1.0)
            self._log(f"✗ Backup completed with {len(result['errors'])} error(s). See log file in {backup_dir}")

        # ── Report completion to the site (Multisite dashboard freshness) ─────
        # Best-effort: never let a reporting hiccup fail an otherwise-good backup.
        try:
            status_str = "clean" if verify_ok else "partial"
            size_b = os.path.getsize(zip_path) if (zip_path and os.path.exists(zip_path)) else 0
            dest = (self.profile.get("cloud_provider", "") or "cloud") if (cloud and cloud_id) else "local"
            reporter = SnapSmackSession(self.profile["site_url"])
            reporter.login(
                self.profile.get("snap_admin_user", ""),
                self.profile.get("snap_admin_pass", ""),
            )
            reporter.report_backup_complete(status_str, size_b, dest)
            self._log(f"Reported backup status to site: {status_str}.")
        except Exception as e:
            self._log(f"Backup-complete ping failed (non-fatal): {e}")

        return result
# ===== SNAPSMACK EOF =====
