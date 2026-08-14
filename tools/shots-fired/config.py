"""
SHOTS FIRED — config.py

Shared-home bootstrap + this tool's own small prefs file. SHOTS FIRED gets its
fleet (site url + api key per blog) from the SHARED cross-tool profile store
(snap_profiles), so unlike COLD SNAP it keeps almost nothing of its own — just
window geometry and the last look-ahead window. Everything here degrades to a
next-to-exe config.ini when the shared C:\\snapsmack home is unreachable, so a
stray standalone copy still runs.

The `_add_shared_to_path()` bootstrap is copied verbatim from COLD SNAP's
config.py so every tool resolves tools/_shared the same way.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""


import configparser
import os
import sys


# This tool's folder key in the shared C:\snapsmack layout (config_files/<tool>,
# logs/<tool>_*, C:\snapsmack\<tool>). One place to change the name.
_TOOL = 'shots-fired'


def _base_dir() -> str:
    if getattr(sys, 'frozen', False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


def _add_shared_to_path() -> None:
    """Put tools/_shared (C:\\snapsmack\\_shared at runtime) on sys.path. No-op if
    already present or unreachable. Copied from COLD SNAP's config.py."""
    try:
        _sd = os.path.join(_base_dir(), '..', '_shared')
        if os.path.isdir(_sd) and _sd not in sys.path:
            sys.path.insert(0, _sd)
    except Exception:
        pass


def _shared_home():
    """Import snap_home (the C:\\snapsmack directory contract). None if unreachable —
    the tool then falls back to its old next-to-exe file, so a standalone copy runs."""
    _add_shared_to_path()
    try:
        import snap_home
        return snap_home
    except Exception:
        return None


def _resolve(name: str) -> str:
    """Path to one of this tool's config files. Prefers the shared
    C:\\snapsmack\\config_files\\shots-fired\\<name>, migrating a legacy next-to-exe copy
    in on first run. Falls back to next-to-exe if the shared home isn't reachable."""
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


DEFAULTS = {
    # How many days ahead the agenda pulls when it loads the fleet.
    'lookahead_days': 60,
    'win_geometry':   '',
}


def load() -> dict:
    """Load this tool's small prefs. Never raises — a missing/garbled file just
    yields the defaults."""
    cfg = configparser.ConfigParser()
    try:
        cfg.read(_config_path())
    except Exception:
        pass
    try:
        lookahead = cfg.getint('view', 'lookahead_days',
                               fallback=DEFAULTS['lookahead_days'])
    except Exception:
        lookahead = DEFAULTS['lookahead_days']
    return {
        'lookahead_days': lookahead,
        'win_geometry':   cfg.get('ui', 'win_geometry',
                                  fallback=DEFAULTS['win_geometry']),
    }


def save(data: dict) -> None:
    """Persist prefs. Best-effort; a failed write is swallowed (prefs are a
    convenience, never load-bearing)."""
    cfg = configparser.ConfigParser()
    cfg['view'] = {'lookahead_days': str(int(data.get('lookahead_days',
                                                      DEFAULTS['lookahead_days'])))}
    cfg['ui'] = {'win_geometry': str(data.get('win_geometry', ''))}
    try:
        with open(_config_path(), 'w') as f:
            cfg.write(f)
    except Exception:
        pass
# ===== SNAPSMACK EOF =====
