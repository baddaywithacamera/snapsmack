"""
Smack Up Your Backup — config.py
Global config persistence (window geometry, last-used profile, defaults).
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.




import configparser
import os
import sys


def _app_dir() -> str:
    """The tool's own directory — next to the .exe when frozen, source dir otherwise.
    Historically SUYB was a portable thumb-drive tool that kept ALL state here; as of
    the C:\\snapsmack consolidation, config.ini moves to the shared config_files/ (see
    _config_file() below). Remaining state files still resolve from here pending their
    own migration pass."""
    if getattr(sys, 'frozen', False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


def _shared_home():
    """Import snap_home (the C:\\snapsmack directory contract). None if unreachable —
    callers then fall back to next-to-exe so old installs keep working."""
    try:
        _sd = os.path.join(_app_dir(), '..', '_shared')
        if os.path.isdir(_sd) and _sd not in sys.path:
            sys.path.insert(0, _sd)
        import snap_home
        return snap_home
    except Exception:
        return None


def shared_cred(key: str, default: str = "") -> str:
    """Read one value from The Hub's shared credential store (snap_creds, the same
    box THE HUB app fills on Discover Fleet). This is how SUYB honours the rule that
    every tool needing a login pulls from The Hub, not its own private store.
    Returns `default` if the shared store isn't reachable (old / portable installs),
    so nothing breaks where the shared home doesn't exist yet."""
    try:
        _sd = os.path.join(_app_dir(), '..', '_shared')
        if os.path.isdir(_sd) and _sd not in sys.path:
            sys.path.insert(0, _sd)
        import snap_creds
        val = snap_creds.get(key, default)
        return val if val else default
    except Exception:
        return default


def resolve_file(name: str) -> str:
    """Path to a SUYB config/state FILE under C:\\snapsmack\\config_files\\suyb,
    migrating the legacy next-to-exe copy in on first run (adopt_legacy). Falls back
    to next-to-exe if the shared home isn't reachable. Shared by the sibling modules
    (credential_store, profile_manager, secret_vault) so all of SUYB's state lands in
    one place."""
    legacy = os.path.join(_app_dir(), name)
    h = _shared_home()
    if not h:
        return legacy
    try:
        new_path = h.config_path('suyb', name)
        h.adopt_legacy(legacy, new_path)
        return new_path
    except Exception:
        return legacy


def resolve_dir(name: str) -> str:
    """Directory form of resolve_file (profiles/, sync_jobs/) — migrates the whole
    tree on first run (adopt_legacy_tree). Falls back to next-to-exe."""
    legacy = os.path.join(_app_dir(), name)
    h = _shared_home()
    if not h:
        return legacy
    try:
        new_dir = os.path.join(h.config_dir('suyb'), name)
        h.adopt_legacy_tree(legacy, new_dir)
        return new_dir
    except Exception:
        return legacy


CONFIG_FILE = resolve_file("config.ini")

DEFAULTS = {
    "window": {
        "width":  "1100",
        "height": "920",
        "x":      "",
        "y":      "",
    },
    "app": {
        "last_profile":    "",
        "active_tab":      "backup",
    },
    "pacing": {
        "transfer_delay":      "2",
        "batch_size":          "0",
        "keepalive_interval":  "60",
    },
    "cloud": {
        "provider":         "google_drive",
        "credentials_file": "",
        "folder_id":        "",
    },
}


def load() -> configparser.ConfigParser:
    cfg = configparser.ConfigParser()
    # Seed with defaults
    for section, values in DEFAULTS.items():
        cfg[section] = values
    if os.path.exists(CONFIG_FILE):
        cfg.read(CONFIG_FILE)
    return cfg


def save(cfg: configparser.ConfigParser) -> None:
    with open(CONFIG_FILE, "w") as f:
        cfg.write(f)
# ===== SNAPSMACK EOF =====
