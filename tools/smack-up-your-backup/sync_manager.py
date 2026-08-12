"""
Smack Up Your Backup — sync_manager.py
Cloud-to-cloud sync job CRUD. One JSON file per job in sync_jobs/.
Mirrors profile_manager.py conventions exactly.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.




import json
import os
import sys
from typing import Dict, List, Optional

import secret_vault
import config  # resolve_dir() → shared C:\snapsmack layout

_SECRET_FIELDS = ("source_b2_key_id", "source_b2_app_key",
                  "dest_b2_key_id", "dest_b2_app_key")


def _app_dir() -> str:
    # Portable app: sync jobs ride next to the executable, never in %APPDATA%.
    if getattr(sys, 'frozen', False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


SYNC_JOBS_DIR = config.resolve_dir("sync_jobs")


def _job_path(name: str) -> str:
    safe = name.replace("/", "_").replace("\\", "_")
    return os.path.join(SYNC_JOBS_DIR, f"{safe}.json")


def list_jobs() -> List[str]:
    """Return sorted list of sync job names."""
    os.makedirs(SYNC_JOBS_DIR, exist_ok=True)
    names = []
    for fname in os.listdir(SYNC_JOBS_DIR):
        if fname.endswith(".json"):
            try:
                with open(os.path.join(SYNC_JOBS_DIR, fname)) as f:
                    data = json.load(f)
                names.append(data.get("name", fname[:-5]))
            except Exception:
                pass
    return sorted(names)


def load_job(name: str) -> Optional[Dict]:
    """Load a sync job by name."""
    path = _job_path(name)
    if not os.path.exists(path):
        for fname in os.listdir(SYNC_JOBS_DIR):
            if fname.endswith(".json"):
                candidate = os.path.join(SYNC_JOBS_DIR, fname)
                try:
                    with open(candidate) as f:
                        data = json.load(f)
                    if data.get("name") == name:
                        return _open_secrets(data)
                except Exception:
                    pass
        return None
    with open(path) as f:
        return _open_secrets(json.load(f))


def save_job(config: Dict) -> None:
    """Save a sync job config."""
    os.makedirs(SYNC_JOBS_DIR, exist_ok=True)
    path = _job_path(config["name"])
    _atomic_write(path, _serialize_job(config))


def _open_secrets(data: Dict) -> Dict:
    data = dict(data)
    for field in _SECRET_FIELDS:
        value = data.get(field, "") or ""
        if secret_vault.is_encrypted(value):
            if not secret_vault.is_unlocked():
                raise RuntimeError("Credential vault is locked; cannot load sync job.")
            data[field] = secret_vault.decrypt(value)
    return data


def _serialize_job(config: Dict) -> bytes:
    data = dict(config)
    if secret_vault.is_enabled() and not secret_vault.is_unlocked():
        raise RuntimeError("Credential vault is locked; refusing plaintext sync-key write.")
    if secret_vault.is_enabled():
        for field in _SECRET_FIELDS:
            data[field] = secret_vault.encrypt(data.get(field, "") or "")
    return json.dumps(data, indent=2).encode("utf-8")


def _atomic_write(path: str, content: bytes) -> None:
    tmp = path + ".tmp"
    with open(tmp, "wb") as f:
        f.write(content)
        f.flush()
        os.fsync(f.fileno())
    os.replace(tmp, path)
    try:
        os.chmod(path, 0o600)
    except Exception:
        pass


def delete_job(name: str) -> None:
    path = _job_path(name)
    if os.path.exists(path):
        os.remove(path)


def new_job_template() -> Dict:
    """Return a blank sync job config with all required keys."""
    return {
        "name":                    "",
        "source_provider":         "google_drive",
        "source_credentials_file": "",
        "source_folder":           "",
        "source_b2_key_id":        "",
        "source_b2_app_key":       "",
        "dest_provider":           "backblaze_b2",
        "dest_credentials_file":   "",
        "dest_folder":             "",
        "dest_b2_key_id":          "",
        "dest_b2_app_key":         "",
        "last_sync_date":          "",
        "last_files_synced":       0,
        "last_bytes_synced":       0,
    }
# ===== SNAPSMACK EOF =====
