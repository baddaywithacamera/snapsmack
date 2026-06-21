"""
Smack Up Your Backup — cloud_sync_engine.py
Cloud-to-cloud differential file sync with SHA1 integrity verification.

Transfer logic:
  Phase 1 — Pre-transfer manifest: list source (MD5+size) and destination (SHA1+size)
  Phase 2 — Differential: skip files already in manifest with matching size+MD5
             For files on dest but not in manifest: trust if sizes match (add to manifest)
  Phase 3 — Transfer: download from source, compute SHA1, upload to B2
             B2 verifies SHA1 server-side on receipt — upload error = integrity failure
             On success, record SHA1 + drive_md5 in persistent manifest
  Phase 4 — Save manifest locally + upload copy to B2 bucket root

Run in a background thread. All UI communication via callbacks.
Callbacks: on_log, on_progress, on_stats, on_done, on_ask, cancel(), prompt_continue()
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import os
import tempfile
import threading
import time
from typing import Callable, Optional

FAILURE_PROMPT_THRESHOLD = 5
MAX_RETRIES              = 3
RETRY_DELAY_SECS         = 5


class CloudSyncEngine:
    def __init__(
        self,
        config:      dict,
        on_log:      Callable[[str], None],
        on_progress: Callable[[float], None],
        on_stats:    Callable,
        on_done:     Callable[[dict], None],
        on_ask:      Optional[Callable[[str], None]] = None,
        scratch_dir: Optional[str] = None,
    ):
        self._config      = config
        self._scratch_dir = scratch_dir
        self.on_log       = on_log
        self.on_progress  = on_progress
        self.on_stats     = on_stats
        self.on_done      = on_done
        self.on_ask       = on_ask

        self._cancelled       = False
        self._prompt_event    = threading.Event()
        self._prompt_continue = False
        self._asked_once      = False

    # ------------------------------------------------------------------
    # Control
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
    # Logging
    # ------------------------------------------------------------------

    def _log(self, msg: str) -> None:
        self.on_log(msg)

    # ------------------------------------------------------------------
    # Entry point
    # ------------------------------------------------------------------

    def run(self) -> None:
        result = {
            "ok":              False,
            "cancelled":       False,
            "files_synced":    0,
            "files_skipped":   0,
            "files_failed":    0,
            "files_cancelled": 0,
            "bytes_synced":    0,
            "error":           "",
        }
        try:
            self._run_inner(result)
        except Exception as e:
            result["error"] = str(e)
            self._log(f"✗ Fatal error: {e}")
        finally:
            self.on_done(result)

    # ------------------------------------------------------------------
    # Build clients
    # ------------------------------------------------------------------

    @staticmethod
    def _build_client(provider: str, config: dict, prefix: str, source: bool = False):
        import cloud_client as cc
        creds_file = config.get(f"{prefix}_credentials_file", "")
        folder     = config.get(f"{prefix}_folder") or config.get(f"{prefix}_folder_id", "")

        if provider == "google_drive":
            return cc.DriveClient(creds_file, folder, readonly=source)
        if provider == "box":
            return cc.BoxClient(creds_file, folder)
        if provider in ("b2", "backblaze_b2"):
            key_id  = config.get(f"{prefix}_b2_key_id", "").strip()
            app_key = config.get(f"{prefix}_b2_app_key", "").strip()
            bucket  = folder.strip()
            if not key_id or not app_key:
                raise ValueError("Backblaze B2 requires Key ID and Application Key.")
            return cc.B2Client(key_id, app_key, bucket)
        raise ValueError(f"Unknown provider: {provider!r}")

    @staticmethod
    def _provider_label(provider: str) -> str:
        return {
            "google_drive": "Google Drive",
            "box":          "Box",
            "b2":           "Backblaze B2",
            "backblaze_b2": "Backblaze B2",
        }.get(provider, provider)

    # ------------------------------------------------------------------
    # Manifest directory
    # ------------------------------------------------------------------

    @staticmethod
    def _manifest_dir() -> str:
        import sys
        if getattr(sys, "frozen", False):
            base = os.path.dirname(sys.executable)
        else:
            base = os.path.dirname(os.path.abspath(__file__))
        return os.path.join(base, "manifests")

    # ------------------------------------------------------------------
    # SHA1 helper
    # ------------------------------------------------------------------

    @staticmethod
    def _sha1(path: str) -> str:
        import hashlib
        h = hashlib.sha1()
        with open(path, "rb") as f:
            for chunk in iter(lambda: f.read(65536), b""):
                h.update(chunk)
        return h.hexdigest()

    # ------------------------------------------------------------------
    # Core sync
    # ------------------------------------------------------------------

    def _run_inner(self, result: dict) -> None:
        from sync_manifest import SyncManifest

        config       = self._config
        src_provider = config.get("source_provider", "google_drive")
        dst_provider = config.get("dest_provider", "backblaze_b2")
        job_name     = config.get("name", "unnamed")
        dst_is_b2    = dst_provider in ("b2", "backblaze_b2")

        # ── Build clients ──────────────────────────────────────────────
        self._log(f"Connecting to {self._provider_label(src_provider)} (source)…")
        try:
            src = self._build_client(src_provider, config, "source", source=True)
        except Exception as e:
            result["error"] = f"Source init failed: {e}"
            self._log(f"✗ {result['error']}")
            return

        self._log(f"Connecting to {self._provider_label(dst_provider)} (destination)…")
        try:
            dst = self._build_client(dst_provider, config, "dest", source=False)
        except Exception as e:
            result["error"] = f"Destination init failed: {e}"
            self._log(f"✗ {result['error']}")
            return

        # ── Load persistent manifest ───────────────────────────────────
        manifest = SyncManifest(job_name, self._manifest_dir())
        manifest.load()
        if dst_is_b2:
            self._log("Checking B2 for newer manifest copy…")
            manifest.try_load_from_b2(dst)
        self._log(f"Manifest: {manifest.file_count()} previously verified file(s).")

        # ── List source (with MD5) ─────────────────────────────────────
        self._log(f"Listing {self._provider_label(src_provider)} source…")
        try:
            src_files = src.list_files()
        except Exception as e:
            result["error"] = f"Could not list source: {e}"
            self._log(f"✗ {result['error']}")
            return

        if not src_files:
            self._log("Source folder is empty — nothing to sync.")
            result["ok"] = True
            return

        # Build src_map keyed by filename. When duplicates exist (same name,
        # multiple Drive entries), keep the most recently modified version.
        src_map = {}
        src_dupes = 0
        for f in src_files:
            name = f["name"]
            entry = {
                "id":           f["id"],
                "size":         int(f.get("size") or 0),
                "md5":          f.get("md5Checksum") or f.get("md5") or None,
                "modifiedTime": f.get("modifiedTime", ""),
            }
            if name in src_map:
                src_dupes += 1
                if entry["modifiedTime"] > src_map[name]["modifiedTime"]:
                    src_map[name] = entry
            else:
                src_map[name] = entry
        if src_dupes:
            self._log(f"⚠ {src_dupes} duplicate filename(s) in source — kept newest version of each.")
        self._log(f"Source: {len(src_map)} file(s).")

        # ── List destination (with SHA1) ───────────────────────────────
        self._log(f"Listing {self._provider_label(dst_provider)} destination…")
        dst_map = {}
        try:
            dst_files = dst.list_files()
            for f in dst_files:
                dst_map[f["name"]] = {
                    "size": int(f.get("size") or 0),
                    "sha1": f.get("sha1", ""),
                }
        except Exception as e:
            if "401" in str(e) or "403" in str(e) or "auth" in str(e).lower():
                result["error"] = f"Destination auth error: {e}"
                self._log(f"✗ {result['error']}")
                return
            self._log(f"⚠ Could not list destination (may not exist yet): {e}")

        self._log(f"Destination: {len(dst_map)} file(s).")

        # ── Differential ──────────────────────────────────────────────
        to_sync      = []
        skipped      = 0

        for name, sf in src_map.items():
            src_size = sf["size"]
            src_md5  = sf["md5"]

            # Already in manifest with a proven hash match → skip (the ONLY
            # skip-without-transfer path; see SyncManifest.is_current).
            if manifest.is_current(name, src_size, src_md5):
                skipped += 1
                continue

            # A file on the destination with a matching *size* is NOT trusted on
            # size alone: the source exposes an MD5 while the destination (B2)
            # exposes a SHA1, so they can't be compared without the bytes.
            # Trusting size here used to bless (and manifest-cache) a
            # same-size-but-different file — a silent-corruption hole. Instead we
            # queue it; the transfer loop downloads, computes the local SHA1, and
            # skips only the UPLOAD when the destination SHA1 actually matches,
            # recording the manifest entry only after real content verification.
            to_sync.append(dict(sf, name=name))

        result["files_skipped"] = skipped
        self._log(f"Skipping {skipped} file(s) verified from manifest.")
        self._log(f"Transferring/verifying {len(to_sync)} file(s).")

        if not to_sync:
            self._log("Everything up to date.")
            result["ok"] = True
            self.on_progress(1.0)
            self._save_manifest(manifest, dst, dst_is_b2)
            return

        bytes_total     = sum(int(f.get("size") or 0) for f in to_sync)
        bytes_done      = 0
        total_files     = len(to_sync)
        files_done      = 0
        files_failed    = 0
        files_cancelled = 0

        self.on_stats(files_done, total_files, skipped, files_failed,
                      bytes_done, bytes_total)

        base    = self._scratch_dir if self._scratch_dir else tempfile.gettempdir()
        tmp_dir = os.path.join(base, "suyb_sync")
        os.makedirs(tmp_dir, exist_ok=True)

        # ── Transfer loop ──────────────────────────────────────────────
        for f in to_sync:
            if self._cancelled:
                self._log(f"↩ Cancelled: {f['name']}")
                files_cancelled += 1
                continue

            name     = f["name"]
            file_id  = f["id"]
            src_size = int(f.get("size") or 0)
            src_md5  = f.get("md5")
            tmp_path = os.path.join(tmp_dir, name)

            self._log(f"↓ {name}")

            # Download from source (with retries)
            dl_ok  = False
            dl_err = ""
            for attempt in range(1, MAX_RETRIES + 1):
                if self._cancelled:
                    break
                try:
                    src.download_file(file_id, tmp_path)
                    dl_ok  = True
                    dl_err = ""
                    break
                except Exception as e:
                    dl_err = str(e)
                    if os.path.exists(tmp_path):
                        try: os.remove(tmp_path)
                        except Exception: pass
                    if attempt < MAX_RETRIES and not self._cancelled:
                        self._log(f"  ⚠ Download attempt {attempt}/{MAX_RETRIES} failed — "
                                  f"retrying in {RETRY_DELAY_SECS}s…")
                        time.sleep(RETRY_DELAY_SECS)

            if self._cancelled:
                if os.path.exists(tmp_path):
                    try: os.remove(tmp_path)
                    except Exception: pass
                self._log(f"↩ Cancelled: {name}")
                files_cancelled += 1
                continue

            if not dl_ok:
                self._log(f"✗ Download failed ({MAX_RETRIES} attempts): {name} — {dl_err}")
                files_failed += 1
                if os.path.exists(tmp_path):
                    try: os.remove(tmp_path)
                    except Exception: pass
                if files_failed >= FAILURE_PROMPT_THRESHOLD:
                    if not self._ask_user(
                        f"{files_failed} failure(s) so far.\n\n"
                        f"Last error: {dl_err}\n\nContinue syncing anyway?"
                    ):
                        result["cancelled"] = True
                        break
                self.on_stats(files_done, total_files, skipped, files_failed,
                              bytes_done, bytes_total)
                continue

            # Exact size check — zero tolerance
            actual_size = os.path.getsize(tmp_path)
            if src_size and actual_size != src_size:
                self._log(f"✗ Download size mismatch: {name} "
                          f"(expected {src_size:,} B, got {actual_size:,} B) — skipping")
                files_failed += 1
                manifest.remove(name)
                try: os.remove(tmp_path)
                except Exception: pass
                self.on_stats(files_done, total_files, skipped, files_failed,
                              bytes_done, bytes_total)
                continue

            # Compute local SHA1
            local_sha1 = self._sha1(tmp_path)

            # Post-download destination check: if dest has same size+SHA1, skip upload
            df = dst_map.get(name)
            if df and df["size"] == actual_size and df.get("sha1") == local_sha1:
                try: os.remove(tmp_path)
                except Exception: pass
                self._log(f"↷ Already synced (SHA1 verified): {name}")
                manifest.update(name, actual_size, src_md5, local_sha1)
                skipped += 1
                continue

            # Upload to destination
            ul_ok   = False
            ul_err  = ""
            b2_sha1 = ""
            for attempt in range(1, MAX_RETRIES + 1):
                if self._cancelled:
                    break
                try:
                    upload_result = dst.upload_file(tmp_path, name)
                    # B2 returns dict {"file_id", "sha1"}; other providers return str
                    if isinstance(upload_result, dict):
                        b2_sha1 = upload_result.get("sha1", local_sha1)
                    else:
                        b2_sha1 = local_sha1
                    ul_ok  = True
                    ul_err = ""
                    break
                except Exception as e:
                    ul_err = str(e)
                    if attempt < MAX_RETRIES and not self._cancelled:
                        self._log(f"  ⚠ Upload attempt {attempt}/{MAX_RETRIES} failed — "
                                  f"retrying in {RETRY_DELAY_SECS}s…")
                        time.sleep(RETRY_DELAY_SECS)

            if os.path.exists(tmp_path):
                try: os.remove(tmp_path)
                except Exception: pass

            if not ul_ok:
                self._log(f"✗ Upload failed ({MAX_RETRIES} attempts): {name} — {ul_err}")
                files_failed += 1
                if files_failed >= FAILURE_PROMPT_THRESHOLD:
                    if not self._ask_user(
                        f"{files_failed} failure(s) so far.\n\n"
                        f"Last error: {ul_err}\n\nContinue syncing anyway?"
                    ):
                        result["cancelled"] = True
                        break
                self.on_stats(files_done, total_files, skipped, files_failed,
                              bytes_done, bytes_total)
                continue

            # SHA1 round-trip verification
            if b2_sha1 and b2_sha1 != local_sha1:
                self._log(f"✗ SHA1 mismatch after upload: {name} "
                          f"(local={local_sha1[:12]}… b2={b2_sha1[:12]}…)")
                files_failed += 1
                self.on_stats(files_done, total_files, skipped, files_failed,
                              bytes_done, bytes_total)
                continue

            # Verified — record in manifest
            confirmed_sha1 = b2_sha1 or local_sha1
            manifest.update(name, actual_size, src_md5, confirmed_sha1)
            self._log(f"✓ {name}  ({actual_size:,} B  SHA1:{confirmed_sha1[:12]}…)")
            files_done += 1
            bytes_done += actual_size
            self.on_progress(files_done / total_files if total_files else 1.0)
            self.on_stats(files_done, total_files, skipped, files_failed,
                          bytes_done, bytes_total)

        # ── Save manifest ──────────────────────────────────────────────
        self._save_manifest(manifest, dst, dst_is_b2)

        # ── Final result ───────────────────────────────────────────────
        result["files_synced"]    = files_done
        result["files_failed"]    = files_failed
        result["files_cancelled"] = files_cancelled
        result["bytes_synced"]    = bytes_done

        if result.get("cancelled") or self._cancelled:
            result["ok"] = False
        elif files_failed > 0:
            result["ok"] = False
        else:
            result["ok"] = True

        if not result.get("cancelled") and not self._cancelled:
            self.on_progress(1.0)

        try:
            if os.path.isdir(tmp_dir) and not os.listdir(tmp_dir):
                os.rmdir(tmp_dir)
        except Exception:
            pass

    def _save_manifest(self, manifest, dst, dst_is_b2: bool) -> None:
        self._log("Saving manifest…")
        if dst_is_b2:
            try:
                manifest.upload_to_b2(dst)
                self._log(f"  Manifest saved locally and uploaded to B2.")
            except Exception as e:
                self._log(f"  ⚠ Manifest saved locally; B2 upload failed: {e}")
        else:
            manifest.save()
            self._log(f"  Manifest saved: {manifest.path}")
# ===== SNAPSMACK EOF =====
