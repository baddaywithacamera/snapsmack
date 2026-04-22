"""
Smack Your Batch Up — profile_manager.py
Per-site profile CRUD. One JSON file per site in profiles/.
Password is base64-obfuscated (not encrypted — matches SYBU/SUYB convention).
"""

import base64
import json
import os
import sys
from typing import Dict, List, Optional


def _app_dir() -> str:
    """Persistent directory — next to the .exe when frozen, source dir otherwise."""
    if getattr(sys, 'frozen', False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


PROFILES_DIR = os.path.join(_app_dir(), 'profiles')


def _obfuscate(plain: str) -> str:
    return base64.b64encode(plain.encode()).decode()


def _deobfuscate(blob: str) -> str:
    try:
        return base64.b64decode(blob.encode()).decode()
    except Exception:
        return ''


def _safe_filename(name: str) -> str:
    return name.replace('/', '_').replace('\\', '_').replace(':', '_')


def _profile_path(name: str) -> str:
    return os.path.join(PROFILES_DIR, f"{_safe_filename(name)}.json")


# ---------------------------------------------------------------------------
# CRUD
# ---------------------------------------------------------------------------

def list_profiles() -> List[str]:
    """Return sorted list of profile display names."""
    os.makedirs(PROFILES_DIR, exist_ok=True)
    names = []
    for fname in os.listdir(PROFILES_DIR):
        if fname.endswith('.json'):
            try:
                with open(os.path.join(PROFILES_DIR, fname), 'r') as f:
                    data = json.load(f)
                names.append(data.get('name', fname[:-5]))
            except Exception:
                pass
    return sorted(names)


def load_profile(name: str) -> Optional[Dict]:
    """Load a profile by display name. Returns dict with plain-text password."""
    path = _profile_path(name)
    if not os.path.exists(path):
        # Scan all profiles for matching name field
        os.makedirs(PROFILES_DIR, exist_ok=True)
        for fname in os.listdir(PROFILES_DIR):
            if fname.endswith('.json'):
                candidate = os.path.join(PROFILES_DIR, fname)
                try:
                    with open(candidate) as f:
                        data = json.load(f)
                    if data.get('name') == name:
                        path = candidate
                        break
                except Exception:
                    pass
        else:
            return None

    with open(path) as f:
        data = json.load(f)

    data['password'] = _deobfuscate(data.pop('password_enc', ''))
    return data


def save_profile(profile: Dict) -> None:
    """Save a profile. Obfuscates the password before writing."""
    os.makedirs(PROFILES_DIR, exist_ok=True)
    data = dict(profile)
    data['password_enc'] = _obfuscate(data.pop('password', ''))
    path = _profile_path(data['name'])
    with open(path, 'w') as f:
        json.dump(data, f, indent=2)


def delete_profile(name: str) -> None:
    path = _profile_path(name)
    if os.path.exists(path):
        os.remove(path)


def rename_profile(old_name: str, new_name: str) -> bool:
    """Rename a profile. Returns True on success."""
    profile = load_profile(old_name)
    if profile is None:
        return False
    old_path = _profile_path(old_name)
    profile['name'] = new_name
    save_profile(profile)
    if os.path.exists(old_path) and old_path != _profile_path(new_name):
        try:
            os.remove(old_path)
        except OSError:
            pass
    return True


def blank_profile() -> Dict:
    """Return a new empty profile with all required keys."""
    return {
        'name':             'New Site',
        'url':              'https://',
        'username':         '',
        'password':         '',
        'google_credentials': '',
        'drive_folder_id':  '',
        'drive_enabled':    True,
        'gemini_api_key':   '',
        'copyright_text':   '',
        'default_category': '',
        'default_album':    '',
        'default_orientation': 'auto',
    }
