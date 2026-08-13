"""
Smack Your Batch Up — profile_manager.py
Per-site connection profiles.

As of the C:\\snapsmack consolidation these live in the SHARED cross-tool store
(tools/_shared/snap_profiles.py -> ...\\shared_library\\profiles\\<site_key>.json), so
a blog set up here is found and used by SUYB, GYSS and COLD SNAP — and vice versa.

This module is a thin SYBU-shaped adapter over that store: it maps SYBU's profile
dict (url / api_key / drive / gemini / defaults) to the canonical shape and back,
migrates the old sybu\\profiles\\ files in ONCE, and falls back to the old local
folder only if the shared home can't be reached (so a stray standalone copy still
runs). The public API (list/load/save/delete/rename/duplicate/blank) is unchanged,
so nothing in main.py had to move.
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
    """Persistent directory — next to the .exe when frozen, source dir otherwise."""
    if getattr(sys, 'frozen', False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


# Legacy per-tool location (pre-consolidation). Kept for one-time migration and as
# the fallback when the shared home is unreachable. PROFILES_DIR stays as an alias
# in case anything outside this module still references it.
LEGACY_PROFILES_DIR = os.path.join(_app_dir(), 'profiles')
PROFILES_DIR = LEGACY_PROFILES_DIR

# SYBU-specific fields. Everything outside the canonical core (name/site_url/api_key)
# rides along in the shared profile's "extras" so it travels but does not pollute
# the shared core that other tools read.
_SYBU_EXTRA_KEYS = (
    'google_credentials', 'drive_folder_id', 'drive_enabled', 'gemini_api_key',
    'copyright_text', 'default_category', 'default_album', 'default_orientation',
)


def _add_shared_to_path() -> None:
    try:
        _sd = os.path.join(_app_dir(), '..', '_shared')
        if os.path.isdir(_sd) and _sd not in sys.path:
            sys.path.insert(0, _sd)
    except Exception:
        pass


def _shared():
    """The shared snap_profiles module, or None if the shared home is unreachable —
    in which case we fall back to the legacy local folder so SYBU still runs."""
    _add_shared_to_path()
    try:
        import snap_profiles
        return snap_profiles
    except Exception:
        return None


# ── SYBU shape <-> canonical shape ──────────────────────────────────────────
def _sybu_to_canonical(d: Dict) -> Dict:
    extras = {k: d[k] for k in _SYBU_EXTRA_KEYS if k in d}
    return {
        'name':           d.get('name', ''),
        'site_url':       d.get('url', ''),
        'api_key':        d.get('api_key', ''),
        'last_connected': d.get('last_connected'),
        'extras':         extras,
    }


def _canonical_to_sybu(c: Dict) -> Dict:
    out = {
        'name':    c.get('name', ''),
        'url':     c.get('site_url', ''),
        'api_key': c.get('api_key', ''),
    }
    for k, v in (c.get('extras') or {}).items():
        out[k] = v
    if c.get('last_connected') is not None:
        out['last_connected'] = c['last_connected']
    return out


# ── one-time migration of the legacy sybu\profiles\ folder ──────────────────
_MIGRATION_MARKER = os.path.join(LEGACY_PROFILES_DIR, '.migrated_to_shared')


def _legacy_read_all() -> List[Dict]:
    out = []
    if not os.path.isdir(LEGACY_PROFILES_DIR):
        return out
    for fname in os.listdir(LEGACY_PROFILES_DIR):
        if not fname.endswith('.json'):
            continue
        try:
            with open(os.path.join(LEGACY_PROFILES_DIR, fname), encoding='utf-8') as f:
                d = json.load(f)
        except Exception:
            continue
        # Legacy files kept api_key plaintext, or an even older base64 password_enc.
        if 'api_key' not in d and 'password_enc' in d:
            try:
                d['api_key'] = base64.b64decode(d.get('password_enc', '').encode()).decode()
            except Exception:
                d['api_key'] = ''
        out.append(_sybu_to_canonical(d))
    return out


def _migrate_once(sp) -> None:
    if os.path.exists(_MIGRATION_MARKER):
        return
    try:
        legacy = _legacy_read_all()
        if legacy:
            sp.migrate_in(legacy, overwrite=False)
        # Drop the marker even with nothing to migrate, so we don't rescan every
        # launch — and never delete the legacy files (they stay as a backup).
        os.makedirs(LEGACY_PROFILES_DIR, exist_ok=True)
        with open(_MIGRATION_MARKER, 'w', encoding='utf-8') as f:
            f.write('SYBU profiles migrated to shared_library/profiles\n')
    except Exception:
        pass


# ── legacy fallback (shared home unreachable) ───────────────────────────────
def _legacy_path(name: str) -> str:
    safe = name.replace('/', '_').replace('\\', '_').replace(':', '_')
    return os.path.join(LEGACY_PROFILES_DIR, f'{safe}.json')


def _legacy_load(name: str) -> Optional[Dict]:
    path = _legacy_path(name)
    if not os.path.exists(path):
        return None
    try:
        with open(path, encoding='utf-8') as f:
            d = json.load(f)
    except Exception:
        return None
    if 'api_key' not in d and 'password_enc' in d:
        try:
            d['api_key'] = base64.b64decode(d.get('password_enc', '').encode()).decode()
        except Exception:
            d['api_key'] = ''
    return d


# ── public API (unchanged signatures) ───────────────────────────────────────
def list_profiles() -> List[str]:
    """Sorted profile display names."""
    sp = _shared()
    if sp:
        _migrate_once(sp)
        return sorted(p.get('name', '') for p in sp.list_profiles())
    os.makedirs(LEGACY_PROFILES_DIR, exist_ok=True)
    names = []
    for fname in os.listdir(LEGACY_PROFILES_DIR):
        if fname.endswith('.json'):
            try:
                with open(os.path.join(LEGACY_PROFILES_DIR, fname), encoding='utf-8') as f:
                    names.append(json.load(f).get('name', fname[:-5]))
            except Exception:
                pass
    return sorted(names)


def load_profile(name: str) -> Optional[Dict]:
    """Load a profile by display name. Returns a SYBU dict with plaintext api_key."""
    sp = _shared()
    if sp:
        _migrate_once(sp)
        c = sp.load_by_name(name)
        return _canonical_to_sybu(c) if c else None
    return _legacy_load(name)


def save_profile(profile: Dict) -> None:
    """Save a profile. Keyed by site in the shared store, so re-saving the same
    blog updates it in place and every other tool sees the change."""
    sp = _shared()
    if sp:
        _migrate_once(sp)
        sp.save(_sybu_to_canonical(profile))
        return
    os.makedirs(LEGACY_PROFILES_DIR, exist_ok=True)
    with open(_legacy_path(profile.get('name', '')), 'w', encoding='utf-8') as f:
        json.dump(profile, f, indent=2)


def delete_profile(name: str) -> None:
    sp = _shared()
    if sp:
        _migrate_once(sp)
        sp.delete_by_name(name)
        return
    path = _legacy_path(name)
    if os.path.exists(path):
        os.remove(path)


def rename_profile(old_name: str, new_name: str) -> bool:
    """Rename a profile. Returns True on success."""
    profile = load_profile(old_name)
    if profile is None:
        return False
    profile['name'] = new_name
    save_profile(profile)
    # Same site -> shared store updated the one file in place (nothing to remove).
    # Different site or legacy mode -> drop the stale old-named entry.
    if new_name != old_name:
        delete_profile(old_name)
    return True


def duplicate_profile(name: str, new_name: str) -> Optional[Dict]:
    """Duplicate an existing profile under a new name."""
    profile = load_profile(name)
    if profile is None:
        return None
    profile['name'] = new_name
    save_profile(profile)
    return profile


def blank_profile() -> Dict:
    """A new empty profile with all keys SYBU's UI expects."""
    return {
        'name':                'New Site',
        'url':                 'https://',
        'api_key':             '',
        'google_credentials':  '',
        'drive_folder_id':     '',
        'drive_enabled':       True,
        'gemini_api_key':      '',
        'copyright_text':      '',
        'default_category':    '',
        'default_album':       '',
        'default_orientation': 'auto',
    }
# ===== SNAPSMACK EOF =====
