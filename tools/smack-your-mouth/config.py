"""
SMACK YOUR MOUTH — config.py
Reads and writes config.ini for the moderation shell. Per-site keys come from
the SHARED fleet (see fleet.py); this file holds only the tool's own small
settings: an optional single-site connection, the reply display name, and window
prefs. It follows the family's shared-home contract exactly — config lives under
C:\\snapsmack\\config_files\\smackmouth (migrated from a legacy next-to-exe copy
on first run) and shared secrets overlay from snap_creds — so a value set in ANY
tool is seen here, and an old standalone copy still runs when the shared home is
unreachable.

Any credential is stored base64-obfuscated (not encrypted — just not plaintext
at a glance), matching the SYBU/COLD SNAP/SUYB convention.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import base64
import configparser
import os
import sys


def _base_dir() -> str:
    if getattr(sys, "frozen", False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


# This tool's folder key in the shared C:\snapsmack layout.
_TOOL = "smackmouth"


def _add_shared_to_path() -> None:
    """Put tools/_shared (…/_shared next to the install at runtime) on sys.path.
    No-op if already present or unreachable. Copied from COLD SNAP's bootstrap."""
    try:
        for cand in (os.path.join(_base_dir(), "..", "_shared"), _base_dir()):
            cand = os.path.normpath(cand)
            if os.path.isdir(cand) and cand not in sys.path:
                sys.path.insert(0, cand)
    except Exception:
        pass


def _shared_home():
    """Import snap_home (the C:\\snapsmack directory contract). None if
    unreachable — the tool then falls back to its next-to-exe config."""
    _add_shared_to_path()
    try:
        import snap_home
        return snap_home
    except Exception:
        return None


def _shared_creds():
    """Import the shared credential store. None if unreachable."""
    _add_shared_to_path()
    try:
        import snap_creds
        return snap_creds
    except Exception:
        return None


def _config_path() -> str:
    """config.ini under the shared config_files/<tool> (migrated from next-to-exe).
    Falls back to next-to-exe when the shared home is unreachable."""
    legacy = os.path.join(_base_dir(), "config.ini")
    h = _shared_home()
    if not h:
        return legacy
    try:
        new_path = h.config_path(_TOOL, "config.ini")
        h.adopt_legacy(legacy, new_path)
        return new_path
    except Exception:
        return legacy


def _b64_load(raw: str) -> str:
    try:
        return base64.b64decode(raw.encode()).decode() if raw else ""
    except Exception:
        return ""


def _b64_save(plain: str) -> str:
    return base64.b64encode(plain.encode()).decode() if plain else ""


def load() -> dict:
    """Load config from disk. Returns a dict with all settings."""
    cfg = configparser.ConfigParser()
    cfg.read(_config_path())

    data = {
        # Optional single-site connection (for a one-off site not in the fleet).
        "url":          cfg.get("site", "url", fallback=""),
        "api_key":      _b64_load(cfg.get("auth", "api_key", fallback="")),
        # Display name used as the author of admin replies.
        "reply_author": cfg.get("moderation", "reply_author", fallback="SnapSmack"),
        # UI prefs.
        "last_session": cfg.get("ui", "last_session", fallback=""),
        "win_geometry": cfg.get("ui", "win_geometry", fallback=""),
        # Shared secrets (overlaid below; carried for parity with the family).
        "gemini_api_key":     "",
        "google_credentials": "",
        "drive_folder_id":    "",
    }

    # Shared store wins when set. No-op if _shared is absent.
    sc = _shared_creds()
    if sc:
        try:
            sc.apply_shared(data)
        except Exception:
            pass
    return data


def save(data: dict) -> None:
    """Write config to disk. Only rewrites the keys this tool owns."""
    cfg = configparser.ConfigParser()
    # Preserve any existing sections/keys we don't manage.
    cfg.read(_config_path())

    if not cfg.has_section("site"):
        cfg.add_section("site")
    cfg.set("site", "url", data.get("url", ""))

    if not cfg.has_section("auth"):
        cfg.add_section("auth")
    cfg.set("auth", "api_key", _b64_save(data.get("api_key", "")))

    if not cfg.has_section("moderation"):
        cfg.add_section("moderation")
    cfg.set("moderation", "reply_author", data.get("reply_author", "SnapSmack"))

    if not cfg.has_section("ui"):
        cfg.add_section("ui")
    if "last_session" in data:
        cfg.set("ui", "last_session", str(data.get("last_session", "")))
    if data.get("win_geometry"):
        cfg.set("ui", "win_geometry", str(data.get("win_geometry")))

    with open(_config_path(), "w") as f:
        cfg.write(f)

    # Propagate shared secrets so a key set here fills them in for the others too.
    sc = _shared_creds()
    if sc:
        try:
            sc.push_shared(data)
        except Exception:
            pass
# ===== SNAPSMACK EOF =====
