"""
blink-suyb — suyb_bridge.py
Bridge between the browser UI and the existing SMACK UP YOUR BACKUP engines.

This is the carry-over layer. blink-suyb does NOT invent a new config format:
it reads SUYB's own portable state files exactly as the desktop app wrote them
 - config.ini        (INI, via configparser)
 - profiles/*.json   (one JSON per backup profile)
 - credentials.json  (credential library)
...so a user's existing profiles and settings appear with zero conversion.

The heavy engines (backup_engine, restore_engine, cloud_sync_engine, ...) are
imported live from the SUYB source tree; nothing is rewritten. That is what lets
blink-suyb do EVERYTHING the Windows app does — including FTP and SFTP — because
it is the same Python running behind a browser UI instead of tkinter.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import configparser
import json
import os
import sys

# ----------------------------------------------------------------------------
# Locating the SUYB data directory (portable — state rides next to the exe).
# ----------------------------------------------------------------------------

_HERE = os.path.dirname(os.path.abspath(__file__))          # .../projects/blink-suyb/server
_BLINK_ROOT = os.path.dirname(_HERE)                        # .../projects/blink-suyb


def _candidate_dirs():
    """Ordered list of places the SUYB portable folder might live."""
    cands = []
    env = os.environ.get("BLINK_SUYB_DATA")
    if env:
        cands.append(env)
    # Sibling of the blink-suyb project inside the SnapSmack repo (dev layout).
    cands.append(os.path.normpath(os.path.join(_BLINK_ROOT, "..", "..", "tools", "smack-up-your-backup")))
    # A vendored copy shipped inside a packaged build.
    cands.append(os.path.join(_BLINK_ROOT, "suyb-core"))
    # Common user-facing portable locations.
    cands.append(os.path.join(os.path.expanduser("~"), "SmackUpYourBackup"))
    cands.append(os.getcwd())
    return cands


def find_suyb_dir():
    """Return the first candidate dir that looks like a real SUYB install."""
    for d in _candidate_dirs():
        if not d or not os.path.isdir(d):
            continue
        # A real install has the engines and/or the portable state.
        markers = ("backup_engine.py", "config.ini", "profiles", "credentials.json")
        if any(os.path.exists(os.path.join(d, m)) for m in markers):
            return d
    return None


SUYB_DIR = find_suyb_dir()


def ensure_on_path():
    """Put the SUYB source dir on sys.path so its engine modules import cleanly."""
    if SUYB_DIR and SUYB_DIR not in sys.path:
        sys.path.insert(0, SUYB_DIR)


# ----------------------------------------------------------------------------
# Carry-over readers — read SUYB's own files, redacting anything sensitive.
# ----------------------------------------------------------------------------

_SECRET_HINTS = ("pass", "secret", "token", "key", "credential", "auth")


def _redact(obj):
    """Deep-copy a profile/cred dict with secret-looking values masked for the UI."""
    if isinstance(obj, dict):
        out = {}
        for k, v in obj.items():
            if isinstance(v, (dict, list)):
                out[k] = _redact(v)
            elif isinstance(v, str) and v and any(h in k.lower() for h in _SECRET_HINTS):
                out[k] = "••••••"      # ••••••
            else:
                out[k] = v
        return out
    if isinstance(obj, list):
        return [_redact(x) for x in obj]
    return obj


def read_config():
    """SUYB config.ini -> nested dict. Empty dict if none yet."""
    if not SUYB_DIR:
        return {}
    path = os.path.join(SUYB_DIR, "config.ini")
    if not os.path.exists(path):
        return {}
    cfg = configparser.ConfigParser()
    cfg.read(path)
    return {section: dict(cfg[section]) for section in cfg.sections()}


def list_profiles():
    """Every backup profile SUYB has saved, redacted for display."""
    if not SUYB_DIR:
        return []
    pdir = os.path.join(SUYB_DIR, "profiles")
    if not os.path.isdir(pdir):
        return []
    skip = {"google-drive-auth.json", "credentials.json"}
    out = []
    for fname in sorted(os.listdir(pdir)):
        if not fname.endswith(".json") or fname in skip:
            continue
        try:
            with open(os.path.join(pdir, fname), encoding="utf-8") as f:
                data = json.load(f)
        except (OSError, json.JSONDecodeError) as e:
            out.append({"_file": fname, "_error": str(e)})
            continue
        red = _redact(data)
        red["_file"] = fname
        out.append(red)
    return out


def list_credentials():
    """The credential library (credentials.json), redacted."""
    if not SUYB_DIR:
        return []
    path = os.path.join(SUYB_DIR, "credentials.json")
    if not os.path.exists(path):
        return []
    try:
        with open(path, encoding="utf-8") as f:
            data = json.load(f)
    except (OSError, json.JSONDecodeError):
        return []
    return _redact(data if isinstance(data, list) else [data])


def status():
    """Summary the UI shows on the dashboard: is carry-over live?"""
    profs = list_profiles()
    return {
        "suyb_dir": SUYB_DIR,
        "found": SUYB_DIR is not None,
        "profile_count": len(profs),
        "credential_count": len(list_credentials()),
        "has_config": bool(read_config()),
    }


# ----------------------------------------------------------------------------
# Engine discovery — which real SUYB engines are importable in this environment.
# Actual backup/restore/sync operations are wired on top of these next.
# ----------------------------------------------------------------------------

ENGINE_MODULES = [
    "backup_engine", "restore_engine", "cloud_sync_engine", "audit_engine",
    "coverage_engine", "ftp_client", "sftp_client", "cloud_client",
    "b2_integrity", "scheduler", "os_schedule", "profile_manager",
    "credential_store", "config",
]


def engine_report():
    """Try to import each engine; report availability so the UI can be honest
    about what is wired vs. what the current host is missing deps for."""
    ensure_on_path()
    report = {}
    for mod in ENGINE_MODULES:
        try:
            __import__(mod)
            report[mod] = {"ok": True}
        except Exception as e:                          # noqa: BLE001 - report, don't crash
            report[mod] = {"ok": False, "error": f"{type(e).__name__}: {e}"}
    return report


if __name__ == "__main__":
    import pprint
    print("SUYB_DIR:", SUYB_DIR)
    pprint.pp(status())

# ===== SNAPSMACK EOF =====
