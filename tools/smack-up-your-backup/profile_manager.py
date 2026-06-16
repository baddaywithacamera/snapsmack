"""
Smack Up Your Backup — profile_manager.py
Blog profile CRUD. One JSON file per blog in profiles/.
Passwords are base64-obfuscated (not encrypted) — matches SYBU convention.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.




import base64
import json
import os
import sys
from typing import Dict, List, Optional


def _app_dir() -> str:
    """Persistent app directory — next to the .exe when frozen, source dir otherwise."""
    if getattr(sys, 'frozen', False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


PROFILES_DIR = os.path.join(_app_dir(), "profiles")


def _obfuscate(plain: str) -> str:
    return base64.b64encode(plain.encode()).decode()


def _deobfuscate(blob: str) -> str:
    try:
        return base64.b64decode(blob.encode()).decode()
    except Exception:
        return ""


def _profile_path(name: str) -> str:
    safe = name.replace("/", "_").replace("\\", "_")
    return os.path.join(PROFILES_DIR, f"{safe}.json")


def list_profiles() -> List[str]:
    """Return sorted list of profile display names."""
    os.makedirs(PROFILES_DIR, exist_ok=True)
    names = []
    for fname in os.listdir(PROFILES_DIR):
        if fname.endswith(".json"):
            try:
                with open(os.path.join(PROFILES_DIR, fname)) as f:
                    data = json.load(f)
                names.append(data.get("name", fname[:-5]))
            except Exception:
                pass
    return sorted(names)


def load_profile(name: str) -> Optional[Dict]:
    """Load a profile by display name. Returns dict with plain-text passwords."""
    path = _profile_path(name)
    if not os.path.exists(path):
        # Try scanning all profiles for matching name
        for fname in os.listdir(PROFILES_DIR):
            if fname.endswith(".json"):
                candidate = os.path.join(PROFILES_DIR, fname)
                try:
                    with open(candidate) as f:
                        data = json.load(f)
                    if data.get("name") == name:
                        path = candidate
                        break
                except Exception:
                    pass
        else:
            return None

    with open(path) as f:
        data = json.load(f)

    # Deobfuscate passwords
    data["ftp_pass"]        = _deobfuscate(data.get("ftp_pass_enc", ""))
    data["snap_admin_pass"] = _deobfuscate(data.get("snap_admin_pass_enc", ""))
    return data


def save_profile(profile: Dict) -> None:
    """Save a profile. Obfuscates passwords before writing."""
    os.makedirs(PROFILES_DIR, exist_ok=True)
    data = dict(profile)

    # Obfuscate and remove plain-text passwords
    data["ftp_pass_enc"]        = _obfuscate(data.pop("ftp_pass", ""))
    data["snap_admin_pass_enc"] = _obfuscate(data.pop("snap_admin_pass", ""))

    path = _profile_path(data["name"])
    with open(path, "w") as f:
        json.dump(data, f, indent=2)


def delete_profile(name: str) -> None:
    path = _profile_path(name)
    if os.path.exists(path):
        os.remove(path)


def new_profile_template() -> Dict:
    """Return a blank profile with all required keys."""
    return {
        "name":                  "",
        "site_url":              "",
        "ftp_host":              "",
        "ftp_port":              21,
        "ftp_user":              "",
        "ftp_pass":              "",
        "ftp_remote_dir":        "/",
        "ftp_ssl":               True,
        "ftp_verify_cert":       False,
        "snap_admin_user":       "",
        "snap_admin_pass":       "",
        "api_key":               "",             # X-Snap-Key; preferred over admin login
        "backup_method":         "cloud",         # "ftp" | "cloud" | "local"
        "schedule_enabled":      False,
        "schedule_type":         "daily",        # "daily" | "weekly"
        "schedule_day":          "monday",       # weekday for weekly schedule
        "schedule_time":         "02:00",        # HH:MM 24-hour local time
        "last_scheduled_run":    "",
        "cloud_provider":        "none",        # "google_drive" | "onedrive" | "none"
        "cloud_credentials_file":"",
        "cloud_folder_id":       "",
        "backup_dir":            "",
        "last_backup_date":      "",
        "pacing_delay":          2,
        "batch_size":            0,
    }


def duplicate_profile(name: str, new_name: str) -> Optional[Dict]:
    """Duplicate an existing profile under a new name."""
    profile = load_profile(name)
    if profile is None:
        return None
    profile["name"] = new_name
    profile["last_backup_date"] = ""
    save_profile(profile)
    return profile
# ===== SNAPSMACK EOF =====
