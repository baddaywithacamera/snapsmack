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
    """Persistent app directory — next to the .exe when frozen, source dir otherwise.
    SUYB is a PORTABLE thumb-drive utility: the ini and all state ride next to the
    executable. Never write to %APPDATA%, the registry, or anywhere else in Windows."""
    if getattr(sys, 'frozen', False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


CONFIG_FILE = os.path.join(_app_dir(), "config.ini")

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
