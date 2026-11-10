"""OPAUDIT 024 — CRONOMETER must not report a failed fleet load as an empty fleet.

The watchman was asleep for 33 days because config.load_fleet() returned [] both
when the shared profile store could not be read AND when it genuinely held no
sites. These checks lock the two states apart: a failed load raises
FleetLoadError; a clean empty store returns []; the happy path is unchanged.

Pure stdlib, no GUI, no real snap_profiles — snap_profiles is faked via sys.modules.
"""

import os
import sys
import types

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CRONOMETER = os.path.join(ROOT, "tools", "cronometer")
if CRONOMETER not in sys.path:
    sys.path.insert(0, CRONOMETER)

import config  # noqa: E402

_fails = 0


def check(label, cond):
    global _fails
    if cond:
        print(f"ok: {label}")
    else:
        _fails += 1
        sys.stderr.write(f"FAIL: {label}\n")


def _with_snap_profiles(fake):
    """Install `fake` as snap_profiles for one call; restore after. fake=None
    forces `import snap_profiles` to raise ImportError (sys.modules[None] trick)."""
    old = sys.modules.get("snap_profiles", "absent")
    if fake is None:
        sys.modules["snap_profiles"] = None   # -> ImportError on `import snap_profiles`
    else:
        sys.modules["snap_profiles"] = fake
    try:
        return config.load_fleet()
    finally:
        if old == "absent":
            sys.modules.pop("snap_profiles", None)
        else:
            sys.modules["snap_profiles"] = old


# 1. A clean, genuinely empty store returns [] (NOT an error).
empty = types.SimpleNamespace(list_profiles=lambda: [])
check("empty store returns [] (not an error)", _with_snap_profiles(empty) == [])

# 2. Import failure -> FleetLoadError (blind, not empty).
try:
    _with_snap_profiles(None)
    check("import failure raises FleetLoadError", False)
except config.FleetLoadError:
    check("import failure raises FleetLoadError", True)

# 3. list_profiles() raising -> FleetLoadError (blind, not empty).
def _boom():
    raise RuntimeError("profile store unreadable")
exploding = types.SimpleNamespace(list_profiles=_boom)
try:
    _with_snap_profiles(exploding)
    check("list_profiles() failure raises FleetLoadError", False)
except config.FleetLoadError:
    check("list_profiles() failure raises FleetLoadError", True)

# 4. FleetLoadError chains the original cause (so the caller can log it).
try:
    _with_snap_profiles(exploding)
except config.FleetLoadError as exc:
    check("FleetLoadError chains the original cause", isinstance(exc.__cause__, RuntimeError))

# 5. The two states are DISTINCT values: empty is [], failure is an exception.
#    (This is the whole OPAUDIT 024 fix in one assertion.)
empty_result = _with_snap_profiles(empty)
raised = False
try:
    _with_snap_profiles(None)
except config.FleetLoadError:
    raised = True
check("empty != failed (list vs exception)", empty_result == [] and raised)

# 6. Happy path still returns the sites (unchanged behaviour), and one malformed
#    profile is skipped rather than blanking the whole board or raising.
good_and_bad = types.SimpleNamespace(list_profiles=lambda: [
    {"name": "Good", "site_url": "https://good.test",
     "api_key": "k", "extras": {"api_key_local": "full"}},
    "this-is-not-a-dict-and-must-not-kill-the-board",
    {"name": "AlsoGood", "site_url": "https://also.test", "api_key": "k2", "extras": {}},
])
fleet = _with_snap_profiles(good_and_bad)
check("malformed profile skipped, good ones kept", len(fleet) == 2)
check("full management key still preferred", fleet[0]["api_key"] == "full")

if _fails:
    sys.stderr.write(f"\n{_fails} assertion(s) FAILED\n")
    sys.exit(1)
print("\nCRONOMETER fleet-load failure distinction (OPAUDIT 024): all checks passed")

# ===== SNAPSMACK EOF =====
