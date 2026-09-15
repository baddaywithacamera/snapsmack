"""FED UP — optional copy of the archive to the owner's own cloud storage.

Two doors, both optional, the local folder is always written first:

* MIRROR FOLDER — any second folder: a synced Dropbox / OneDrive / Google Drive
  folder, an external drive. Plain copy, size-compared, no credentials. This is
  the cheap answer and the one most people should take.
* BACKBLAZE B2 — the same native-API client SMACK UP YOUR BACKUP uses
  (tools/smack-up-your-backup/cloud_client.py, imported, not copied). Key ID +
  application key + bucket; files land under ``fed-up/<handle>/…``. Uploads
  only files whose sha256 changed since the last push (tracked in a small
  ledger next to the manifest, never inside it).

The B2 secret is held in the shared desktop credential store (snap_creds),
the same place every other SnapSmack tool keeps its keys — never in config.
"""
# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
from __future__ import annotations

import json
import os
import sys
from typing import Callable, Optional

LogFn = Callable[[str], None]
_SUYB = os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))), "smack-up-your-backup")
CRED_KEY_ID = "fedup_b2_key_id"
CRED_APP_KEY = "fedup_b2_app_key"


def _b2_module():
    """SUYB's cloud_client, lazily; its Google/Box imports are lazy inside it too."""
    for p in (_SUYB, getattr(sys, "_MEIPASS", "")):
        if p and p not in sys.path and os.path.isdir(p):
            sys.path.insert(0, p)
    import cloud_client  # noqa: WPS433 (SUYB module)
    return cloud_client


def b2_available() -> bool:
    try:
        _b2_module()
        return True
    except Exception:
        return False


def _creds():
    import snap_creds  # tools/_shared, on sys.path via app.py
    snap_creds.init()
    return snap_creds


def save_b2_credentials(key_id: str, app_key: str) -> None:
    c = _creds()
    c.set(CRED_KEY_ID, key_id.strip())
    if app_key.strip():
        c.set(CRED_APP_KEY, app_key.strip())


def load_b2_credentials() -> tuple:
    try:
        c = _creds()
        return c.get(CRED_KEY_ID, ""), c.get(CRED_APP_KEY, "")
    except Exception:
        return "", ""


def test_b2(key_id: str, app_key: str, bucket: str) -> tuple:
    try:
        return _b2_module().test_b2_connection(key_id, app_key, bucket)
    except Exception as e:  # import or network
        return False, f"B2 client unavailable: {e}"


def push_b2(archive_dir: str, handle: str, key_id: str, app_key: str, bucket: str,
            log: Optional[LogFn] = None, progress: Optional[Callable[[int, int, str], None]] = None) -> dict:
    """Upload changed files. Returns {uploaded, skipped, failed}."""
    log = log or (lambda s: None)
    progress = progress or (lambda d, t, w: None)
    cc = _b2_module()
    client = cc.B2Client(key_id, app_key, bucket, folder=f"fed-up/{handle}")
    ledger_path = os.path.join(archive_dir, ".b2-ledger.json")
    try:
        with open(ledger_path, encoding="utf-8") as fh:
            ledger = json.load(fh)
        if not isinstance(ledger, dict):
            ledger = {}
    except (OSError, ValueError):
        ledger = {}
    try:
        with open(os.path.join(archive_dir, "manifest.json"), encoding="utf-8") as fh:
            manifest = json.load(fh)
    except (OSError, ValueError):
        raise RuntimeError("No manifest.json — run a backup first.")
    wanted = {"manifest.json": ""}
    wanted.update(manifest.get("files") or {})
    for rel, rec in (manifest.get("media") or {}).items():
        wanted[rel] = (rec or {}).get("sha256", "")
    # manifest.json changes every run; hash it on the spot.
    import hashlib
    with open(os.path.join(archive_dir, "manifest.json"), "rb") as fh:
        wanted["manifest.json"] = hashlib.sha256(fh.read()).hexdigest()
    uploaded = skipped = failed = 0
    total = len(wanted)
    for i, (rel, digest) in enumerate(sorted(wanted.items()), 1):
        path = os.path.join(archive_dir, rel)
        if not os.path.isfile(path):
            continue
        if ledger.get(rel) == digest and digest:
            skipped += 1
            progress(i, total, f"up to date: {rel}")
            continue
        try:
            client.upload_file(path, rel.replace(os.sep, "/"))
            ledger[rel] = digest
            uploaded += 1
            progress(i, total, f"uploaded {rel}")
        except Exception as e:
            failed += 1
            log(f"B2 upload failed for {rel}: {e}")
        if i % 25 == 0:
            _save_ledger(ledger_path, ledger)
    _save_ledger(ledger_path, ledger)
    log(f"B2: {uploaded} uploaded, {skipped} already there, {failed} failed")
    return {"uploaded": uploaded, "skipped": skipped, "failed": failed}


def _save_ledger(path: str, ledger: dict) -> None:
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as fh:
        json.dump(ledger, fh, indent=1, sort_keys=True)
    os.replace(tmp, path)

# ===== SNAPSMACK EOF =====
