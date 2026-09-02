"""
Smack Your Batch Up — profile_manager.py
Per-site profile CRUD. One JSON file per site in profiles/.
Password is base64-obfuscated (not encrypted — matches SYBU/SUYB convention).
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

# The ONE shared cross-tool profile store — the same profiles SNAP HQ, GYSS and
# SYBU write to (shared_library/profiles/<site>.json via snap_profiles). COLD SNAP
# reads it so a site set up ANYWHERE shows up in the LOAD PROFILE dropdown. Optional
# import via the same _shared shim the offline suite uses; a local profiles/ folder
# stays as a legacy fallback so nothing already saved locally disappears.
try:
    import snap_profiles as _shared_profiles
except Exception:  # pragma: no cover - dev-tree import shim
    try:
        _sd = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), '_shared')
        if _sd not in sys.path:
            sys.path.insert(0, _sd)
        import snap_profiles as _shared_profiles
    except Exception:
        _shared_profiles = None


def _shared_to_coldsnap(p: dict) -> Dict:
    """Map a canonical shared profile (name/site_url/api_key/extras) onto the shape
    COLD SNAP's connection panel reads (url / api_key / smackpress_key), preserving
    any tool extras so nothing a profile carried is lost."""
    extras = dict(p.get('extras') or {})
    out = {
        'name':           p.get('name', ''),
        'url':            p.get('site_url', ''),
        'api_key':        p.get('api_key', ''),
        'smackpress_key': extras.get('smackpress_key', ''),
    }
    for k, v in extras.items():
        out.setdefault(k, v)
    return out


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
    """Return sorted profile display names — the SHARED store (every tool's sites)
    plus any legacy local profiles, de-duplicated by name."""
    names = set()
    if _shared_profiles is not None:
        try:
            for p in _shared_profiles.list_profiles():
                n = p.get('name') or p.get('site_url')
                if n:
                    names.add(n)
        except Exception:
            pass
    os.makedirs(PROFILES_DIR, exist_ok=True)
    for fname in os.listdir(PROFILES_DIR):
        if fname.endswith('.json'):
            try:
                with open(os.path.join(PROFILES_DIR, fname), 'r') as f:
                    data = json.load(f)
                names.add(data.get('name', fname[:-5]))
            except Exception:
                pass
    return sorted(names)


def load_profile(name: str) -> Optional[Dict]:
    """Load a profile by display name. Prefers the SHARED store (SNAP HQ / GYSS /
    SYBU) so the site you set up in the Hub loads its url + key here; falls back to
    a legacy local profile file. Returns a dict with plain-text secrets."""
    if _shared_profiles is not None:
        try:
            p = _shared_profiles.load_by_name(name)
            if p:
                return _shared_to_coldsnap(p)
        except Exception:
            pass

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
# ===== SNAPSMACK EOF =====
