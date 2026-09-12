"""Record an executable installed by an operator-run suite build as trusted.

SNAPSMACK_EOF_HEADER: last non-empty line must be # ===== SNAPSMACK EOF =====
"""

from __future__ import annotations

import hashlib
import json
import os
import sys


def trust(path: str) -> str:
    path = os.path.abspath(path)
    if not os.path.isfile(path) or not path.lower().endswith(".exe"):
        raise ValueError("The installed executable does not exist.")
    digest = hashlib.sha256()
    with open(path, "rb") as stream:
        for chunk in iter(lambda: stream.read(1 << 20), b""):
            digest.update(chunk)
    folder = os.path.join(os.environ.get("APPDATA") or os.path.expanduser("~"),
                          "snapsmack-hub")
    os.makedirs(folder, exist_ok=True)
    ledger_path = os.path.join(folder, "exe-pins.json")
    try:
        with open(ledger_path, encoding="utf-8") as stream:
            ledger = json.load(stream)
        if not isinstance(ledger, dict):
            ledger = {}
    except Exception:
        ledger = {}
    ledger[path.lower()] = digest.hexdigest()
    temp = ledger_path + ".new"
    with open(temp, "w", encoding="utf-8") as stream:
        json.dump(ledger, stream, indent=1, sort_keys=True)
        stream.flush()
        os.fsync(stream.fileno())
    os.replace(temp, ledger_path)
    return digest.hexdigest()


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit("usage: trust-installed-exe.py <installed.exe>")
    print(trust(sys.argv[1]))

# ===== SNAPSMACK EOF =====
