"""
Smack Up Your Backup — b2_integrity.py
One-time cleanup of a dirty B2 destination.

Flow:
  1. Inventory B2 (all versions, SHA1 + size — no downloads needed)
  2. Inventory source (MD5 + size from API — no downloads needed)
  3. Cross-reference by filename + size (size is the cross-algorithm key)
  4. Generate dedup report CSV
  5. After user confirmation: delete bad versions, re-transfer bad files
  6. Write cleanup log CSV
  7. Return results for post-cleanup summary

Size is used as the primary cross-algorithm key because B2 stores SHA1 and
Drive provides MD5 — they cannot be directly compared without downloading.
Files are considered "good" when their B2 size matches the Drive source size.
The ongoing sync engine records SHA1 for all future transfers so subsequent
runs use hash comparison directly.
"""

import csv
import hashlib
import os
import tempfile
from datetime import datetime
from typing import Callable, Dict, List, Optional


CHUNK = 65536


# ------------------------------------------------------------------
# Inventory
# ------------------------------------------------------------------

def inventory_b2(b2_client, on_log: Callable) -> Dict[str, List[dict]]:
    """
    List every version of every file in the B2 bucket (no downloads).
    Returns {filename: [{id, size, sha1, uploaded_ms}]}, sorted newest-first.
    """
    on_log("Inventorying B2 destination (all versions)…")
    versions = b2_client.list_all_versions()
    grouped: Dict[str, List[dict]] = {}
    for v in versions:
        grouped.setdefault(v["name"], []).append(v)
    for name in grouped:
        grouped[name].sort(key=lambda x: x.get("uploaded_ms", 0), reverse=True)
    total_v = sum(len(v) for v in grouped.values())
    on_log(f"  {len(grouped)} unique filename(s), {total_v} total version(s).")
    return grouped


def inventory_source(src_client, on_log: Callable) -> Dict[str, dict]:
    """
    List all files on the source (no downloads).
    Returns {filename: {id, size, md5}} — md5 may be None for GDocs native files.
    """
    on_log("Inventorying source…")
    files = src_client.list_files()
    result = {}
    for f in files:
        result[f["name"]] = {
            "id":   f["id"],
            "size": int(f.get("size") or 0),
            "md5":  f.get("md5Checksum") or f.get("md5") or None,
        }
    on_log(f"  {len(result)} file(s) on source.")
    return result


# ------------------------------------------------------------------
# Dedup report
# ------------------------------------------------------------------

def generate_dedup_report(
    src_inventory: Dict[str, dict],
    b2_inventory: Dict[str, List[dict]],
    on_log: Callable,
) -> List[dict]:
    """
    Cross-reference B2 versions against source by filename + size.
    Returns a list of action rows describing every file's status.

    Actions:
      OK_SINGLE_VERSION     — one version, size matches source
      DELETE_BAD_VERSION    — version size doesn't match source (delete it)
      DELETE_DUPLICATE      — extra version with correct size (keep newest, delete rest)
      KEEP_GOOD_VERSION     — the version to keep (size matches source)
      KEEP_NEWEST_MATCHING  — newest of multiple size-matching versions (keep)
      BAD_SIZE_REPLACE      — no version matches source size; re-transfer needed
      MISSING_FROM_DEST     — file on source, not on B2 at all
      ORPHAN_NOT_IN_SOURCE  — file on B2, not on source
    """
    on_log("Generating dedup report…")
    rows = []

    all_names = sorted(set(b2_inventory.keys()) | set(src_inventory.keys()))
    for filename in all_names:
        src      = src_inventory.get(filename)
        versions = b2_inventory.get(filename, [])

        # On B2 but not on source
        if src is None:
            for v in versions:
                rows.append(_row(filename, "ORPHAN_NOT_IN_SOURCE", v, None,
                                 "On B2 but not on source — do not auto-delete"))
            continue

        # On source but not on B2
        if not versions:
            rows.append(_row(filename, "MISSING_FROM_DEST", None, src,
                             "Not on B2 — will be transferred"))
            continue

        src_size = src["size"]
        good = [v for v in versions if v["size"] == src_size]
        bad  = [v for v in versions if v["size"] != src_size]

        if len(versions) == 1:
            v = versions[0]
            if v["size"] == src_size:
                rows.append(_row(filename, "OK_SINGLE_VERSION", v, src, ""))
            else:
                rows.append(_row(filename, "BAD_SIZE_REPLACE", v, src,
                                 f"Size mismatch — B2={v['size']:,} source={src_size:,}"))
        elif len(good) == 1:
            rows.append(_row(filename, "KEEP_GOOD_VERSION", good[0], src,
                             "Size matches source — keep"))
            for v in bad:
                rows.append(_row(filename, "DELETE_BAD_VERSION", v, src,
                                 f"Wrong size (source={src_size:,}) — delete"))
        elif len(good) > 1:
            # Keep newest, delete older duplicates
            rows.append(_row(filename, "KEEP_NEWEST_MATCHING", good[0], src,
                             "Newest version matching source size — keep"))
            for v in good[1:]:
                rows.append(_row(filename, "DELETE_DUPLICATE", v, src,
                                 "Older duplicate with correct size — delete"))
            for v in bad:
                rows.append(_row(filename, "DELETE_BAD_VERSION", v, src,
                                 f"Wrong size (source={src_size:,}) — delete"))
        else:
            # No version has the right size — all must be replaced
            for v in versions:
                rows.append(_row(filename, "BAD_SIZE_REPLACE", v, src,
                                 f"No version matches source size {src_size:,} — re-transfer"))

    # Summary log
    counts: Dict[str, int] = {}
    for r in rows:
        counts[r["action"]] = counts.get(r["action"], 0) + 1
    on_log(f"Report complete — {len(rows)} row(s):")
    for action in sorted(counts):
        on_log(f"  {action}: {counts[action]}")
    return rows


def _row(filename: str, action: str,
         b2_version: Optional[dict], src: Optional[dict],
         note: str) -> dict:
    return {
        "filename":      filename,
        "action":        action,
        "b2_version_id": b2_version["id"]   if b2_version else "",
        "b2_size":       b2_version["size"] if b2_version else "",
        "b2_sha1":       b2_version.get("sha1", "") if b2_version else "",
        "src_size":      src["size"] if src else "",
        "src_md5":       (src.get("md5") or "") if src else "",
        "note":          note,
    }


# ------------------------------------------------------------------
# Summary (for confirmation dialog)
# ------------------------------------------------------------------

def cleanup_summary(report: List[dict]) -> dict:
    to_delete  = sum(1 for r in report
                     if r["action"] in ("DELETE_BAD_VERSION", "DELETE_DUPLICATE"))
    to_replace = len({r["filename"] for r in report
                      if r["action"] == "BAD_SIZE_REPLACE"})
    missing    = sum(1 for r in report if r["action"] == "MISSING_FROM_DEST")
    orphans    = sum(1 for r in report if r["action"] == "ORPHAN_NOT_IN_SOURCE")
    ok         = sum(1 for r in report
                     if r["action"] in ("OK_SINGLE_VERSION",
                                        "KEEP_GOOD_VERSION",
                                        "KEEP_NEWEST_MATCHING"))
    return {
        "to_delete":  to_delete,
        "to_replace": to_replace,
        "missing":    missing,
        "orphans":    orphans,
        "ok":         ok,
    }


# ------------------------------------------------------------------
# CSV helpers
# ------------------------------------------------------------------

def write_csv(rows: List[dict], filepath: str) -> None:
    if not rows:
        return
    os.makedirs(os.path.dirname(os.path.abspath(filepath)), exist_ok=True)
    with open(filepath, "w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=list(rows[0].keys()))
        writer.writeheader()
        writer.writerows(rows)


def _ts() -> str:
    return datetime.now().strftime("%Y%m%d-%H%M%S")


def report_paths(output_dir: str) -> dict:
    ts = _ts()
    return {
        "dedup_report": os.path.join(output_dir, f"dedup-report-{ts}.csv"),
        "cleanup_log":  os.path.join(output_dir, f"cleanup-log-{ts}.csv"),
        "src_manifest": os.path.join(output_dir, f"source-manifest-{ts}.csv"),
        "dst_manifest": os.path.join(output_dir, f"destination-manifest-{ts}.csv"),
    }


# ------------------------------------------------------------------
# Execute cleanup
# ------------------------------------------------------------------

def execute_cleanup(
    b2_client,
    src_client,
    src_inventory: Dict[str, dict],
    report: List[dict],
    scratch_dir: str,
    on_log: Callable,
    on_progress: Callable,   # on_progress(float 0.0-1.0)
    cancelled: Callable,     # cancelled() -> bool
) -> dict:
    """
    1. Delete bad/duplicate B2 versions.
    2. Re-transfer files where no version matched source size.
       - If source file exists: download, verify size, upload with SHA1 check, delete old versions.
       - If source file missing: log MISSING_FROM_SOURCE, skip.
    Returns result dict with counts and log_rows list.
    """
    result = {
        "deleted":              0,
        "replaced":             0,
        "failed":               0,
        "missing_from_source":  0,
        "log_rows":             [],
    }

    to_delete = [r for r in report
                 if r["action"] in ("DELETE_BAD_VERSION", "DELETE_DUPLICATE")]

    # Unique filenames needing re-transfer (BAD_SIZE_REPLACE).
    # Collect all bad version IDs per filename so we can delete them all after upload.
    replace_files: Dict[str, dict] = {}      # filename -> src info
    replace_bad_ids: Dict[str, List[str]] = {}  # filename -> [b2_version_id, ...]
    for r in report:
        if r["action"] == "BAD_SIZE_REPLACE":
            fname = r["filename"]
            if fname not in replace_files:
                replace_files[fname] = src_inventory.get(fname, {})
                replace_bad_ids[fname] = []
            if r["b2_version_id"]:
                replace_bad_ids[fname].append(r["b2_version_id"])

    total_ops = len(to_delete) + len(replace_files)
    done = 0

    def _log(filename, action, status, note=""):
        row = {
            "filename":  filename,
            "action":    action,
            "status":    status,
            "note":      note,
            "timestamp": datetime.now().isoformat(),
        }
        result["log_rows"].append(row)

    # ── Phase 1: Delete bad/duplicate versions ─────────────────────
    on_log(f"Phase 1: Deleting {len(to_delete)} bad/duplicate version(s)…")
    for r in to_delete:
        if cancelled():
            on_log("Cancelled.")
            break
        fname = r["filename"]
        vid   = r["b2_version_id"]
        try:
            b2_client.delete_file_version(vid, fname)
            on_log(f"  ✓ Deleted: {fname}  ({_fmt(r['b2_size'])})")
            result["deleted"] += 1
            _log(fname, "DELETE", "OK", f"version_id={vid} size={r['b2_size']}")
        except Exception as e:
            on_log(f"  ✗ Delete failed: {fname} — {e}")
            result["failed"] += 1
            _log(fname, "DELETE", "FAILED", str(e))
        done += 1
        on_progress(done / total_ops if total_ops else 1.0)

    # ── Phase 2: Re-transfer files with wrong-size versions ────────
    on_log(f"Phase 2: Re-transferring {len(replace_files)} file(s) from source…")
    tmp_dir = os.path.join(scratch_dir, "suyb_cleanup")
    os.makedirs(tmp_dir, exist_ok=True)

    for fname, src_info in replace_files.items():
        if cancelled():
            on_log("Cancelled.")
            break

        src_id   = src_info.get("id", "")
        src_size = src_info.get("size", 0)
        tmp_path = os.path.join(tmp_dir, fname)

        # Look up source file if not in inventory (edge case)
        if not src_id:
            files = src_client.list_files(name_filter=fname)
            match = next((f for f in files if f["name"] == fname), None)
            if not match:
                on_log(f"  ✗ {fname} — not found on source (MISSING_FROM_SOURCE)")
                result["missing_from_source"] += 1
                result["failed"] += 1
                _log(fname, "REPLACE", "MISSING_FROM_SOURCE")
                done += 1
                on_progress(done / total_ops if total_ops else 1.0)
                continue
            src_id   = match["id"]
            src_size = int(match.get("size") or 0)

        # Download from source
        dl_ok = False
        for attempt in range(1, 4):
            try:
                on_log(f"  ↓ Downloading {fname} from source (attempt {attempt})…")
                src_client.download_file(src_id, tmp_path)
                dl_ok = True
                break
            except Exception as e:
                on_log(f"    ⚠ Download attempt {attempt} failed: {e}")
                if os.path.exists(tmp_path):
                    try: os.remove(tmp_path)
                    except Exception: pass

        if not dl_ok:
            on_log(f"  ✗ {fname} — download failed after 3 attempts")
            result["failed"] += 1
            _log(fname, "REPLACE", "DOWNLOAD_FAILED")
            done += 1
            on_progress(done / total_ops if total_ops else 1.0)
            continue

        # Verify downloaded size matches source
        actual_size = os.path.getsize(tmp_path)
        if src_size and actual_size != src_size:
            on_log(f"  ✗ {fname} — size mismatch after download "
                   f"(expected {src_size:,}, got {actual_size:,})")
            result["failed"] += 1
            _log(fname, "REPLACE", "DOWNLOAD_SIZE_MISMATCH",
                 f"expected={src_size} got={actual_size}")
            try: os.remove(tmp_path)
            except Exception: pass
            done += 1
            on_progress(done / total_ops if total_ops else 1.0)
            continue

        # Compute local SHA1 before upload
        local_sha1 = _sha1(tmp_path)

        # Upload to B2 (B2 verifies SHA1 server-side on receipt)
        ul_ok = False
        b2_sha1 = ""
        for attempt in range(1, 4):
            try:
                on_log(f"  ↑ Uploading {fname} to B2 (attempt {attempt})…")
                upload_result = b2_client.upload_file(tmp_path, fname)
                b2_sha1 = upload_result.get("sha1", "")
                ul_ok = True
                break
            except Exception as e:
                on_log(f"    ⚠ Upload attempt {attempt} failed: {e}")

        try: os.remove(tmp_path)
        except Exception: pass

        if not ul_ok:
            on_log(f"  ✗ {fname} — upload failed after 3 attempts")
            result["failed"] += 1
            _log(fname, "REPLACE", "UPLOAD_FAILED")
            done += 1
            on_progress(done / total_ops if total_ops else 1.0)
            continue

        # Verify SHA1 round-trip (B2 returns the SHA1 it stored)
        if b2_sha1 and b2_sha1 != local_sha1:
            on_log(f"  ✗ {fname} — SHA1 mismatch after upload "
                   f"(local={local_sha1[:12]}… b2={b2_sha1[:12]}…)")
            result["failed"] += 1
            _log(fname, "REPLACE", "SHA1_MISMATCH_AFTER_UPLOAD",
                 f"local={local_sha1} b2={b2_sha1}")
            done += 1
            on_progress(done / total_ops if total_ops else 1.0)
            continue

        on_log(f"  ✓ {fname} re-transferred and SHA1-verified")
        result["replaced"] += 1
        _log(fname, "REPLACE", "OK", f"sha1={local_sha1}")

        # Delete the old bad B2 versions for this file
        for bad_vid in replace_bad_ids.get(fname, []):
            try:
                b2_client.delete_file_version(bad_vid, fname)
            except Exception:
                pass  # best-effort; version may already be gone

        done += 1
        on_progress(done / total_ops if total_ops else 1.0)

    # Clean up temp dir
    try:
        if os.path.isdir(tmp_dir) and not os.listdir(tmp_dir):
            os.rmdir(tmp_dir)
    except Exception:
        pass

    return result


# ------------------------------------------------------------------
# Helpers
# ------------------------------------------------------------------

def _sha1(path: str) -> str:
    h = hashlib.sha1()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(CHUNK), b""):
            h.update(chunk)
    return h.hexdigest()


def _fmt(n) -> str:
    try:
        n = int(n)
        for unit in ("B", "KB", "MB", "GB"):
            if n < 1024:
                return f"{n:,} {unit}"
            n //= 1024
        return f"{n:,} TB"
    except Exception:
        return str(n)
