"""
Smack Up Your Backup — credential_store.py
Named credential library. Register a credentials JSON file once under a
friendly name; every part of the app (backup profiles, Cloud Sync jobs,
Global Cloud Config) picks from the same list instead of asking for the
raw file path every time.

Storage: credentials.json next to the exe (or source dir when uncompiled).
Format:
    [
        {"name": "My Google Drive", "path": "C:\\SmackUpYourBackup\\drive.json"},
        ...
    ]
"""

import json
import os
import sys
from typing import List, Optional


def _app_dir() -> str:
    if getattr(sys, 'frozen', False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


STORE_FILE = os.path.join(_app_dir(), "credentials.json")


def load() -> List[dict]:
    """Return the full credential list, oldest-first."""
    if not os.path.exists(STORE_FILE):
        return []
    try:
        with open(STORE_FILE) as f:
            data = json.load(f)
        if isinstance(data, list):
            return [e for e in data if isinstance(e, dict) and e.get("name") and e.get("path")]
    except Exception:
        pass
    return []


def save(entries: List[dict]) -> None:
    with open(STORE_FILE, "w") as f:
        json.dump(entries, f, indent=2)


def names() -> List[str]:
    """Sorted list of credential names for dropdown population."""
    return [e["name"] for e in load()]


def path_for(name: str) -> Optional[str]:
    """Return the file path for a credential name, or None."""
    for e in load():
        if e["name"] == name:
            return e["path"]
    return None


def name_for(path: str) -> Optional[str]:
    """Return the name registered for a given path, or None."""
    norm = os.path.normcase(os.path.abspath(path))
    for e in load():
        if os.path.normcase(os.path.abspath(e["path"])) == norm:
            return e["name"]
    return None


def add_or_update(name: str, path: str) -> None:
    """Add a new entry or update the path for an existing name."""
    entries = load()
    for e in entries:
        if e["name"] == name:
            e["path"] = path
            save(entries)
            return
    entries.append({"name": name, "path": path})
    save(entries)


def remove(name: str) -> None:
    entries = [e for e in load() if e["name"] != name]
    save(entries)


def rename(old_name: str, new_name: str) -> None:
    entries = load()
    for e in entries:
        if e["name"] == old_name:
            e["name"] = new_name
    save(entries)
