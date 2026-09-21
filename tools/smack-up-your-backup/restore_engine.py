"""
Smack Up Your Backup — restore_engine.py
Restore pipeline: parse manifest, pre-create dirs, match files, paced upload.
Accepts local ZIP, local recovery kit, or cloud-downloaded ZIP.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import os
import zipfile
from typing import Callable, Dict, List, Optional

import cloud_client as cloud_module
import ftp_client as ftp_module
import transport
import file_matcher
import manifest_reader
from path_safety import is_safe_relative

ProgressCallback = Callable[[str, str, float], None]
# (stage, message, pct_overall)


_is_safe_rel_path = is_safe_relative


# ── Bounded ZIP extraction (SECAUDIT 058 B) ──────────────────────────────────
# A backup package is user-selected or cloud-downloaded. Before 0.7.724D it
# was `extractall()`'d wholesale: no name check, no size check, no cleanup.
# A damaged or crafted ZIP could fill the disk before SUYB looked at the
# manifest. These bounds are generous for a real backup (a photo archive is
# large but honest) and fatal for a bomb.
ZIP_MAX_MEMBERS        = 250_000
ZIP_MAX_MEMBER_BYTES   = 8 * 1024 ** 3        # one file: 8 GB
ZIP_MAX_RATIO          = 200                  # expanded / compressed, per member
ZIP_RATIO_FLOOR_BYTES  = 1024 * 1024          # ratio only judged above 1 MB expanded
ZIP_FREE_SPACE_MARGIN  = 512 * 1024 ** 2      # keep 512 MB free on the volume
_ZIP_COPY_CHUNK        = 1024 * 1024


def inventory_zip(zf: "zipfile.ZipFile") -> int:
    """Walk every member and refuse the archive on the first unsafe one.
    Returns the total declared expanded size. Nothing is written."""
    import stat
    infos = zf.infolist()
    if len(infos) > ZIP_MAX_MEMBERS:
        raise ValueError(f"Backup package has {len(infos)} entries (limit {ZIP_MAX_MEMBERS})")
    total = 0
    for info in infos:
        name = info.filename
        if name.endswith("/"):
            if not is_safe_relative(name.rstrip("/")):
                raise ValueError(f"Unsafe directory name in backup package: {name!r}")
            continue
        if not is_safe_relative(name):
            raise ValueError(f"Unsafe file name in backup package: {name!r}")
        # Unix zips carry st_mode in the high 16 bits; Windows/plain zips carry
        # nothing there (file-type bits 0). Only a SET, non-regular type is a link
        # or device — refuse those; leave mode-less entries alone.
        ftype = ((info.external_attr >> 16) & 0xFFFF) & 0xF000
        if ftype and ftype not in (stat.S_IFREG, stat.S_IFDIR):
            raise ValueError(f"Backup package contains a non-file entry (link/device): {name!r}")
        if info.file_size > ZIP_MAX_MEMBER_BYTES:
            raise ValueError(f"{name!r} declares {info.file_size} bytes (limit {ZIP_MAX_MEMBER_BYTES})")
        if info.file_size > ZIP_RATIO_FLOOR_BYTES:
            ratio = info.file_size / max(info.compress_size, 1)
            if ratio > ZIP_MAX_RATIO:
                raise ValueError(f"{name!r} expands {ratio:.0f}:1 — refused as a decompression bomb")
        total += info.file_size
    return total


def extract_zip_bounded(zf: "zipfile.ZipFile", dest: str) -> None:
    """Inventory, check free space, then stream each member under `dest`.
    A member that yields more bytes than it declared aborts the extraction."""
    import shutil
    from path_safety import contained_local_path
    total = inventory_zip(zf)
    free = shutil.disk_usage(dest).free
    if total + ZIP_FREE_SPACE_MARGIN > free:
        raise ValueError(
            f"Backup package expands to {total // 1048576} MB but only "
            f"{free // 1048576} MB is free here")
    for info in zf.infolist():
        if info.filename.endswith("/"):
            os.makedirs(contained_local_path(dest, info.filename.rstrip("/")), exist_ok=True)
            continue
        target = contained_local_path(dest, info.filename)
        os.makedirs(os.path.dirname(target), exist_ok=True)
        written = 0
        with zf.open(info, "r") as src, open(target, "wb") as out:
            while True:
                chunk = src.read(_ZIP_COPY_CHUNK)
                if not chunk:
                    break
                written += len(chunk)
                if written > info.file_size:
                    raise ValueError(
                        f"{info.filename!r} produced more bytes than it declared — refused")
                out.write(chunk)


class RestoreEngine:
    def __init__(
        self,
        profile:      dict,
        on_progress:  Optional[ProgressCallback] = None,
        on_log:       Optional[Callable[[str], None]] = None,
        global_cloud: Optional[dict] = None,
    ):
        self.profile      = profile
        self.on_progress  = on_progress or (lambda s, m, p: None)
        self.on_log       = on_log or print
        self.global_cloud = global_cloud or {}
        self._cancelled   = False

    def cancel(self) -> None:
        self._cancelled = True

    def _progress(self, stage: str, msg: str, pct: float) -> None:
        self.on_progress(stage, msg, pct)

    def _log(self, msg: str) -> None:
        self.on_log(msg)

    # ------------------------------------------------------------------
    # Source resolution
    # ------------------------------------------------------------------

    def restore_from_zip(self, zip_path: str) -> dict:
        """Restore from a local backup package ZIP.

        SECAUDIT 058 B: the package is inventoried and bounded BEFORE a single
        byte is expanded, streamed out under the staging directory, and the
        staging directory is always removed afterwards — success or failure.
        """
        import shutil
        import tempfile
        self._progress("extract", "Extracting backup package…", 0.02)
        extract_dir = tempfile.mkdtemp(prefix="sibu_restore_")
        try:
            try:
                with zipfile.ZipFile(zip_path, "r") as zf:
                    extract_zip_bounded(zf, extract_dir)
            except Exception as e:
                return self._fail(f"Could not extract ZIP: {e}")

            # Find the recovery kit inside
            kit_path = None
            for fname in os.listdir(extract_dir):
                if fname.endswith(".tar.gz"):
                    kit_path = os.path.join(extract_dir, fname)
                    break

            if not kit_path:
                return self._fail("No recovery kit (.tar.gz) found in backup package.")

            return self.restore_from_kit(kit_path, extract_dir)
        finally:
            shutil.rmtree(extract_dir, ignore_errors=True)

    def restore_from_cloud(self, file_id: str, local_download_dir: str) -> dict:
        """Download a backup ZIP from cloud then restore from it."""
        self._progress("cloud_dl", "Connecting to cloud…", 0.01)
        cloud = cloud_module.get_cloud_client(self.profile, global_cloud=self.global_cloud)
        if not cloud:
            return self._fail("No cloud provider configured for this profile.")

        # Find filename from listing
        files  = cloud.list_files()
        target = next((f for f in files if f["id"] == file_id), None)
        fname  = target["name"] if target else f"{file_id}.zip"
        local_zip = os.path.join(local_download_dir, fname)

        self._progress("cloud_dl", f"Downloading {fname}…", 0.03)
        try:
            cloud.download_file(
                file_id, local_zip,
                on_progress=lambda r, t: self._progress(
                    "cloud_dl",
                    f"Downloading from cloud… {r // 1048576}MB / {t // 1048576}MB",
                    0.03 + 0.20 * (r / max(t, 1)),
                ),
            )
        except Exception as e:
            return self._fail(f"Cloud download failed: {e}")

        return self.restore_from_zip(local_zip)

    def restore_from_kit(self, kit_path: str, media_dir: str) -> dict:
        """
        Restore from a recovery kit .tar.gz + a directory of local media files.
        media_dir is scanned for matching files using file_matcher.
        """
        result = {
            "success":        False,
            "uploaded":       0,
            "skipped":        0,
            "failed":         0,
            "failed_files":   [],
            "errors":         [],
        }

        # ── Parse manifest ───────────────────────────────────────────
        self._progress("parse", "Parsing manifest…", 0.25)
        try:
            manifest = manifest_reader.from_tar(kit_path)
        except Exception as e:
            return self._fail(f"Manifest parse error: {e}", result)

        media_files = {k: v for k, v in manifest.files.items() if not v.bundled}
        self._log(f"Manifest: {len(media_files)} media files to restore.")

        # ── Match local files ────────────────────────────────────────
        self._progress("match", "Matching local files to manifest…", 0.28)
        matches = file_matcher.match_manifest_to_local(manifest, media_dir)

        unmatched = [k for k, m in matches.items() if m.strategy == "unmatched"]
        if unmatched:
            self._log(f"WARNING: {len(unmatched)} files have no local match.")
            for k in unmatched[:10]:
                self._log(f"  Unmatched: {k}")

        # ── Connect (FTP or SFTP per profile) ─────────────────────────
        self._progress("ftp", "Connecting…", 0.30)
        ftp = transport.make_client(
            self.profile,
            transfer_delay = float(self.profile.get("pacing_delay", 2)),
            batch_size     = int(self.profile.get("batch_size", 0)),
        )
        try:
            if hasattr(ftp, "on_log"):
                ftp.on_log = self._log
            ftp.connect()
        except Exception as e:
            try:
                import ftps_pins
                if isinstance(e, ftps_pins.CertificateChanged):
                    result["certificate_change"] = {
                        "host": e.host,
                        "port": int(self.profile.get("ftp_port") or 21),
                        "old_fp": e.old_fp,
                        "new_fp": e.new_fp,
                        "why": e.why,
                    }
            except ImportError:
                pass
            return self._fail(f"FTP connection failed: {e}", result)

        # ── Pre-create directory tree ────────────────────────────────
        # Drop any manifest directory that would escape remote_dir (Finding C).
        safe_dirs = [d for d in manifest.directory_structure if _is_safe_rel_path(d)]
        rejected_dirs = len(manifest.directory_structure) - len(safe_dirs)
        if rejected_dirs:
            self._log(
                f"WARNING: {rejected_dirs} manifest directory path(s) rejected as "
                f"unsafe (traversal/absolute) and skipped."
            )
        self._progress("dirs", "Creating directory tree on server…", 0.33)
        ftp.ensure_directory_tree(safe_dirs)

        # ── Build remote index ───────────────────────────────────────
        self._progress("index", "Building remote file index…", 0.36)
        try:
            remote_index = ftp.build_remote_index()
        except Exception as e:
            self._log(f"WARNING: Could not build remote index ({e}). Uploading all files.")
            remote_index = {}

        # ── Upload ───────────────────────────────────────────────────
        total = len(media_files)
        done  = 0

        for key, record in media_files.items():
            if self._cancelled:
                break

            pct = 0.38 + 0.55 * (done / max(total, 1))
            match = matches.get(key)

            # Refuse to upload to a target that escapes remote_dir (Finding C).
            if not _is_safe_rel_path(record.restores_to):
                result["failed"] += 1
                result["failed_files"].append(record.restores_to)
                result["errors"].append(
                    f"Unsafe restore target rejected: {record.restores_to}"
                )
                self._log(f"✗ Unsafe restore target rejected: {record.restores_to}")
                done += 1
                continue

            if not match or match.strategy == "unmatched":
                result["failed"] += 1
                result["failed_files"].append(record.restores_to)
                result["errors"].append(f"No local file for: {record.restores_to}")
                done += 1
                continue

            # Check if server already has it at the right size
            remote_size = remote_index.get(record.restores_to, -1)
            if remote_size == record.size:
                result["skipped"] += 1
                self._progress("upload", f"Skip (exists): {record.restores_to}", pct)
                done += 1
                continue

            # ── Pre-upload checksum: verify local file matches manifest ─
            if record.checksum:
                actual = ftp_module.FTPClient.sha256_file(match.local_path)
                if actual != record.checksum:
                    self._log(
                        f"✗ Local file checksum mismatch, will not upload: {record.restores_to}\n"
                        f"  Expected: {record.checksum}\n"
                        f"  Got:      {actual}\n"
                        f"  Source:   {match.local_path}"
                    )
                    result["failed"] += 1
                    result["failed_files"].append(record.restores_to)
                    result["errors"].append(
                        f"Checksum mismatch (corrupt local file): {record.restores_to}"
                    )
                    done += 1
                    continue

            self._progress("upload", f"Uploading: {record.restores_to}", pct)
            ok = ftp.upload_file(
                match.local_path,
                record.restores_to,
                on_progress=lambda fn, r, t, s: None,
            )

            if ok:
                result["uploaded"] += 1
            else:
                result["failed"] += 1
                result["failed_files"].append(record.restores_to)
                result["errors"].append(f"Upload failed: {record.restores_to}")

            done += 1

        # ── Verify uploads: size check via FTP SIZE command ──────────
        self._progress("verify", "Verifying uploads…", 0.94)
        verify_failures = []
        for key, record in media_files.items():
            if self._cancelled:
                break
            match = matches.get(key)
            if not match or match.strategy == "unmatched":
                continue
            if not _is_safe_rel_path(record.restores_to):
                continue  # already rejected during upload (Finding C)
            # Check remote size matches manifest expected size
            remote_size = ftp.get_remote_size(record.restores_to)
            if remote_size is not None and remote_size != record.size:
                verify_failures.append(record.restores_to)
                self._log(
                    f"✗ Size mismatch after upload: {record.restores_to} "
                    f"(expected {record.size}B, server has {remote_size}B)"
                )

        if verify_failures:
            result["errors"].append(
                f"{len(verify_failures)} file(s) failed post-upload size verification."
            )
            self._log(f"WARNING: {len(verify_failures)} upload(s) failed size verification.")

        ftp.disconnect()

        result["success"] = result["failed"] == 0
        status = "complete" if result["success"] else "completed with errors"
        self._progress(
            "done",
            f"Restore {status} — {result['uploaded']} uploaded, "
            f"{result['skipped']} skipped, {result['failed']} failed.",
            1.0,
        )
        return result

    def _fail(self, msg: str, result: Optional[dict] = None) -> dict:
        if result is None:
            result = {"success": False, "uploaded": 0, "skipped": 0,
                      "failed": 0, "failed_files": [], "errors": []}
        result["errors"].append(msg)
        self._log(f"ERROR: {msg}")
        self._progress("error", msg, 1.0)
        return result
# ===== SNAPSMACK EOF =====
