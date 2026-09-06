"""
Hub roster-exe hash pinning (SECAUDIT 054) — the Hub launches exes that live
inside the GYSS-writable shared root, so each exe's sha256 is pinned on first
launch and a CHANGED exe is refused until the operator explicitly trusts it.
The pin ledger lives in %APPDATA%, outside the jail.

Run: python tools/hub/tests/test_exe_pinning.py   (exit 0 = all pass)
"""

import os
import sys
import tempfile

_HUB = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, _HUB)
sys.path.insert(0, os.path.join(os.path.dirname(_HUB), "_shared"))

# Sandbox the ledger AND the shared root before importing the hub.
_tmp = tempfile.mkdtemp(prefix="hub-pin-")
os.environ["APPDATA"] = os.path.join(_tmp, "appdata")
os.environ["SNAPSMACK_HOME"] = os.path.join(_tmp, "root")

import main as hub_main

FAILED = 0


def check(ok, message):
    global FAILED
    print(("PASS " if ok else "FAIL ") + message)
    if not ok:
        FAILED += 1


exe = os.path.join(_tmp, "tool.exe")
with open(exe, "wb") as fh:
    fh.write(b"ORIGINAL BUILD")

status, pinned, current = hub_main._exe_pin_verify(exe)
check(status == "new" and pinned is None, "first sight of an exe reports 'new'")
hub_main._exe_pin_store(exe, current)

status, pinned, current = hub_main._exe_pin_verify(exe)
check(status == "ok" and pinned == current, "unchanged exe verifies 'ok'")

with open(exe, "wb") as fh:
    fh.write(b"SWAPPED PAYLOAD")
status, pinned, current = hub_main._exe_pin_verify(exe)
check(status == "changed" and pinned != current, "a swapped exe reports 'changed'")

check(hub_main._exe_ledger_path().lower().startswith(os.environ["APPDATA"].lower()),
      "the pin ledger lives under APPDATA — outside the GYSS write-jail")
check(not hub_main._exe_ledger_path().lower().startswith(
        os.environ["SNAPSMACK_HOME"].lower()),
      "the pin ledger is NOT inside the shared root")

# Re-trust: storing the new hash makes it 'ok' again (the one-click Yes path).
hub_main._exe_pin_store(exe, current)
status, _, _ = hub_main._exe_pin_verify(exe)
check(status == "ok", "explicitly trusting the new build re-pins it")

print("ALL PASS" if FAILED == 0 else f"{FAILED} FAILURE(S)")
sys.exit(0 if FAILED == 0 else 1)

# ===== SNAPSMACK EOF =====
