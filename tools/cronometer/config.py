"""
CRONOMETER — config.py

Two jobs:
  1. Load this tool's own prefs (poll interval, window geometry) from a shared
     config.ini under C:\\snapsmack\\config_files\\cronometer, migrating a legacy
     next-to-exe copy in on first run — the exact contract COLD SNAP uses.
  2. Load the FLEET: every site the family already knows about, read from the ONE
     shared per-site profile store (tools/_shared/snap_profiles). Set a blog up in
     SYBU/GYSS/COLD SNAP and CRONOMETER sees it too — no re-typing keys here.

Nothing in _shared is written by this tool; it is imported read-only. If the shared
home is unreachable (a stray standalone copy) the tool falls back to its own
next-to-exe config.ini and simply reports an empty fleet.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import configparser
import os
import sys


# This tool's folder key in the shared C:\snapsmack layout (config_files/<tool>,
# logs/<tool>_*, C:\snapsmack\<tool>). One place to change the name.
_TOOL = 'cronometer'


def _base_dir() -> str:
    if getattr(sys, 'frozen', False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


def _add_shared_to_path() -> None:
    """Put tools/_shared (C:\\snapsmack\\_shared at runtime) on sys.path. No-op if
    already present or unreachable. Copied verbatim from COLD SNAP's config."""
    try:
        _sd = os.path.join(_base_dir(), '..', '_shared')
        if os.path.isdir(_sd) and _sd not in sys.path:
            sys.path.insert(0, _sd)
    except Exception:
        pass


def _shared_home():
    """Import snap_home (the C:\\snapsmack directory contract). None if unreachable —
    the tool then falls back to its old next-to-exe files, so old installs still run."""
    _add_shared_to_path()
    try:
        import snap_home
        return snap_home
    except Exception:
        return None


def _shared_creds():
    """Import the shared credential store (tools/_shared/snap_creds), or None. Only
    used to pull a fleet-wide hub key if one was ever configured elsewhere; never
    written from here."""
    _add_shared_to_path()
    try:
        import snap_creds
        return snap_creds
    except Exception:
        return None


def _resolve(name: str) -> str:
    """Path to one of this tool's config files. Prefers the shared
    C:\\snapsmack\\config_files\\cronometer\\<name>, migrating the legacy next-to-exe
    copy in on first run. Falls back to next-to-exe if the shared home isn't
    reachable, so a standalone copy still keeps its settings."""
    legacy = os.path.join(_base_dir(), name)
    h = _shared_home()
    if not h:
        return legacy
    try:
        new_path = h.config_path(_TOOL, name)
        h.adopt_legacy(legacy, new_path)
        return new_path
    except Exception:
        return legacy


def _config_path() -> str:
    return _resolve('config.ini')


# ---------------------------------------------------------------------------
# Fleet — read from the shared cross-tool profile store (read-only).
# ---------------------------------------------------------------------------

def load_fleet() -> list:
    """Return the fleet as a list of dicts: {'name', 'url', 'api_key'}.

    Source is the ONE shared per-site profile store (snap_profiles) so a blog set
    up in ANY family tool shows up here. Sites with no api_key are still returned
    (rendered as 'no key' on the board) so the operator sees the gap rather than a
    silently-missing site. Returns [] if the shared store is unreachable."""
    _add_shared_to_path()
    try:
        import snap_profiles
    except Exception:
        return []
    out = []
    try:
        for prof in snap_profiles.list_profiles():
            url = (prof.get('site_url') or '').strip()
            if not url:
                continue
            out.append({
                'name':    prof.get('name') or url,
                'url':     url,
                'api_key': prof.get('api_key') or '',
            })
    except Exception:
        return []
    return out


# ---------------------------------------------------------------------------
# Tool prefs — poll cadence + window geometry.
# ---------------------------------------------------------------------------

# How stale (seconds) a heartbeat reply may be before REFRESH ALL re-polls it, and
# the network timeout for a single heartbeat probe. Kept small; this is a console
# an operator watches, not a background daemon.
DEFAULT_POLL_TIMEOUT = 12
DEFAULT_AUTO_REFRESH  = 0      # 0 = manual only (REFRESH ALL / RE-CHECK)


def load() -> dict:
    """Load tool prefs from the shared config.ini (never raises)."""
    cfg = configparser.ConfigParser()
    try:
        cfg.read(_config_path())
    except Exception:
        pass
    return {
        'poll_timeout':  cfg.getint('net', 'poll_timeout', fallback=DEFAULT_POLL_TIMEOUT),
        'auto_refresh':  cfg.getint('ui', 'auto_refresh', fallback=DEFAULT_AUTO_REFRESH),
        'win_geometry':  cfg.get('ui', 'win_geometry', fallback=''),
    }


def save(data: dict) -> None:
    """Persist tool prefs. Rebuilds the file; only this tool's keys live here."""
    cfg = configparser.ConfigParser()
    cfg['net'] = {
        'poll_timeout': str(int(data.get('poll_timeout', DEFAULT_POLL_TIMEOUT))),
    }
    cfg['ui'] = {
        'auto_refresh': str(int(data.get('auto_refresh', DEFAULT_AUTO_REFRESH))),
        'win_geometry': str(data.get('win_geometry', '') or ''),
    }
    try:
        with open(_config_path(), 'w') as f:
            cfg.write(f)
    except Exception:
        pass
# ===== SNAPSMACK EOF =====
