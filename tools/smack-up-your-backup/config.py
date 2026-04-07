"""
Smack Up Your Backup — config.py
Global config persistence (window geometry, last-used profile, defaults).
"""

import configparser
import os

CONFIG_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "config.ini")

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
