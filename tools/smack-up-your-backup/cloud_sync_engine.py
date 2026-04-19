"""
Smack Up Your Backup — cloud_sync_engine.py
Google Drive → OneDrive differential file sync.

Run in a background thread. All UI communication via callbacks.
Mirrors backup_engine.py conventions: on_log, on_progress, on_stats,
on_done, on_ask, cancel(), prompt_continue().
"""

import os
import tempfile
import threading
from typing import Callable, Optional

FAILURE_PROMPT_THRESHOLD = 1   # prompt user after this many failures

# Size tolerance for differential check (Drive and OneDrive can report
# slightly different sizes for the same file due to metadata differences).
SIZE_TOLERANCE_PCT = 0.01


class CloudSyncEngine:
    def __init__(
        self,
        config:      dict,
        on_log:      Callable[[str], None],
        on_progress: Callable[[float], None],
        on_stats:    Callable,
        on_done:     Callable[[dict], None],
        on_ask:      Optional[Callable[[str], None]] = None,
    ):
        self._config  = config
        self.on_log   = on_log
        self.on_progress = on_progress
        self.on_stats = on_stats
        self.on_done  = on_done
        self.on_ask   = on_ask

        self._cancelled       = False
        self._prompt_event    = threading.Event()
        self._prompt_continue = False
        self._asked_once      = False

    # ------------------------------------------------------------------
    # Control (called from UI thread)
    # ------------------------------------------------------------------

    def cancel(self) -> None:
        self._cancelled = True
        self._prompt_event.set()

    def prompt_continue(self) -> None:
        self._prompt_continue = True
        self._prompt_event.set()

    def _ask_user(self, msg: str) -> bool:
        if self._asked_once:
            return True
        self._asked_once = True
        if not self.on_ask:
            self._log("✗ Failure threshold reached — aborting (unattended run).")
            return False
        self._prompt_event.clear()
        self._prompt_continue = False
        self.on_ask(msg)
        self._prompt_event.wait()
        return self._prompt_continue

    # ------------------------------------------------------------------
    # Logging helpers
    # ------------------------------------------------------------------

    def _log(self, msg: str) -> None:
        self.on_log(msg)

    # ------------------------------------------------------------------
    # Main run (called in background thread)
    # ------------------------------------------------------------------

    def run(self) -> None:
        result = {
            "ok":            False,
            "cancelled":     False,
            "files_synced":  0,
            "files_skipped": 0,
            "files_failed":  0,
            "files_cancelled": 0,
            "bytes_synced":  0,
            "error":         "",
        }

        try:
            self._run_inner(result)
        except Exception as e:
            result["error"] = str(e)
            self._log(f"✗ Fatal error: {e}")
        finally:
            self.on_done(result)

    def _run_inner(self, result: dict) -> None:
        import cloud_client as cc

        config = self._config

        # ── Build clients ──────────────────────────────────────────────
        self._log("Connecting to Google Drive…")
        try:
            src = cc.DriveClient(
                config["source_credentials_file"],
                config["source_folder_id"],
                readonly=True,
            )
        except Exception as e:
            result["error"] = f"Google Drive init failed: {e}"
            self._log(f"✗ {result['error']}")
            return

        self._log("Connecting to OneDrive…")
        try:
            dst = cc.OneDriveClient(
                config["dest_credentials_file"],
                config["dest_folder_path"],
            )
        except Exception as e:
            result["error"] = f"OneDrive init failed: {e}"
            self._log(f"✗ {result['error']}")
            return

        # ── List source ────────────────────────────────────────────────
        self._log("Listing Google Drive source folder…")
        try:
            src_files = src.list_files()
        except Exception as e:
            result["error"] = f"Could not list Google Drive folder: {e}"
            self._log(f"✗ {result['error']}")
            return

        if not src_files:
            self._log("Source folder is empty — nothing to sync.")
            result["ok"] = True
            return

        self._log(f"Found {len(src_files)} file(s) in source.")

        # ── List destination ───────────────────────────────────────────
        self._log("Listing OneDrive destination folder…")
        try:
            dst_files = dst.list_files()
            dst_map = {}
            for f in dst_files:
                dst_map[f["name"]] = int(f.get("size") or 0)
        except Exception as e:
            # Non-fatal if destination folder doesn't exist yet — it'll be
            # created on first upload. But if it's an auth error, stop.
            if "401" in str(e) or "403" in str(e) or "auth" in str(e).lower():
                result["error"] = f"OneDrive auth error: {e}"
                self._log(f"✗ {result['error']}")
                return
            self._log(f"⚠ Could not list destination (may not exist yet): {e}")
            dst_map = {}

        # ── Differential filter ────────────────────────────────────────
        to_sync = []
        skipped = 0
        for f in src_files:
            name     = f["name"]
            src_size = int(f.get("size") or 0)
            if name in dst_map:
                dst_size = dst_map[name]
                # Skip if sizes are within tolerance
                if src_size == 0 or abs(src_size - dst_size) / max(src_size, 1) <= SIZE_TOLERANCE_PCT:
                    skipped += 1
                    continue
            to_sync.append(f)

        result["files_skipped"] = skipped
        self._log(f"Skipping {skipped} already-synced file(s).")
        self._log(f"Transferring {len(to_sync)} file(s).")

        if not to_sync:
            self._log("Everything up to date.")
            result["ok"] = True
            self.on_progress(1.0)
            return

        bytes_total   = sum(int(f.get("size") or 0) for f in to_sync)
        bytes_done    = 0
        total_files   = len(to_sync)
        files_done    = 0
        files_failed  = 0
        files_cancelled = 0

        self.on_stats(files_done, total_files, skipped, files_failed, bytes_done, bytes_total)

        tmp_dir = os.path.join(tempfile.gettempdir(), "suyb_sync")
        os.makedirs(tmp_dir, exist_ok=True)

        # ── Transfer loop ──────────────────────────────────────────────
        for f in to_sync:
            if self._cancelled:
                self._log(f"↩ Cancelled: {f['name']}")
                files_cancelled += 1
                continue

            name    = f["name"]
            file_id = f["id"]
            size    = int(f.get("size") or 0)
            tmp_path = os.path.join(tmp_dir, name)

            self._log(f"↓ {name}")

            # Download from Drive
            dl_ok  = True
            dl_err = ""
            try:
                src.download_file(file_id, tmp_path)
            except Exception as e:
                dl_ok  = False
                dl_err = str(e)

            if self._cancelled:
                if os.path.exists(tmp_path):
                    try: os.remove(tmp_path)
                    except Exception: pass
                self._log(f"↩ Cancelled: {name}")
                files_cancelled += 1
                continue

            if not dl_ok:
                self._log(f"✗ Download failed: {name} — {dl_err}")
                files_failed += 1
                if os.path.exists(tmp_path):
                    try: os.remove(tmp_path)
                    except Exception: pass
                if files_failed >= FAILURE_PROMPT_THRESHOLD:
                    keep_going = self._ask_user(
                        f"{files_failed} download failure(s) so far.\n\n"
                        f"Last error: {dl_err}\n\nContinue syncing anyway?"
                    )
                    if not keep_going:
                        result["cancelled"] = True
                        break
                self.on_stats(files_done, total_files, skipped, files_failed, bytes_done, bytes_total)
                continue

            # Upload to OneDrive
            ul_ok  = True
            ul_err = ""
            try:
                dst.upload_file(tmp_path, name)
            except Exception as e:
                ul_ok  = False
                ul_err = str(e)
            finally:
                if os.path.exists(tmp_path):
                    try: os.remove(tmp_path)
                    except Exception: pass

            if not ul_ok:
                self._log(f"✗ Upload failed: {name} — {ul_err}")
                files_failed += 1
                if files_failed >= FAILURE_PROMPT_THRESHOLD:
                    keep_going = self._ask_user(
                        f"{files_failed} upload failure(s) so far.\n\n"
                        f"Last error: {ul_err}\n\nContinue syncing anyway?"
                    )
                    if not keep_going:
                        result["cancelled"] = True
                        break
                self.on_stats(files_done, total_files, skipped, files_failed, bytes_done, bytes_total)
                continue

            # Success
            self._log(f"✓ {name}")
            files_done += 1
            bytes_done += size
            pct = files_done / total_files if total_files else 1.0
            self.on_progress(pct)
            self.on_stats(files_done, total_files, skipped, files_failed, bytes_done, bytes_total)

        # ── Final result ───────────────────────────────────────────────
        result["files_synced"]   = files_done
        result["files_failed"]   = files_failed
        result["files_cancelled"] = files_cancelled
        result["bytes_synced"]   = bytes_done

        if result.get("cancelled"):
            result["ok"] = False
        elif files_failed > 0:
            result["ok"] = False
        else:
            result["ok"] = True

        if not result.get("cancelled") and not self._cancelled:
            self.on_progress(1.0)

        # Clean up temp dir if empty
        try:
            if os.path.isdir(tmp_dir) and not os.listdir(tmp_dir):
                os.rmdir(tmp_dir)
        except Exception:
            pass
