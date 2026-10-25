"""Keep pytest's scratch inside the tool's own build area, and remove it after.

pytest's default temp root under %LOCALAPPDATA%\\Temp is refused in the
sandboxed environment the agents run in, which makes every tmp_path test error
out with PermissionError. forum-cli, log-flogger and ticket-wicket already had
this file; tools/hub never did, so 112 of its tests errored at setup with
"[WinError 5] Access is denied: ...\\Temp\\pytest-of-neutr" the moment that
directory was not already lying around.

This lives in conftest.py rather than pytest.ini because tools/*/*.ini is
gitignored, so an ini would work on one machine and silently fail for
everybody else — including Codex.

Cleaning up is the other half. pytest keeps its last three basetemp
directories on purpose so you can open what a test wrote, but with a basetemp
of our own choosing that rotation never runs, and the scratch grows with every
session. A full hub run leaves 112 directories behind. So a clean session
deletes its own scratch on the way out, and a session with failures keeps it,
because that is exactly when somebody wants to look inside.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os
import shutil

_OURS = []


def pytest_configure(config):
    if not config.option.basetemp:
        here = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
        scratch = os.path.join(here, "build", "_pytest_tmp")
        os.makedirs(scratch, exist_ok=True)
        config.option.basetemp = scratch
        # Only a path we chose is ours to delete. A --basetemp the caller
        # passed is theirs, and they may be pointing it somewhere they keep.
        _OURS.append(scratch)


def pytest_sessionfinish(session, exitstatus):
    if int(exitstatus) != 0:
        return
    for scratch in _OURS:
        shutil.rmtree(scratch, ignore_errors=True)

# ===== SNAPSMACK EOF =====
