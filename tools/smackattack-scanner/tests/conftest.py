"""Keep pytest's scratch inside the tool's own build area, and remove it after.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
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
        _OURS.append(scratch)


def pytest_sessionfinish(session, exitstatus):
    if int(exitstatus) != 0:
        return
    for scratch in _OURS:
        shutil.rmtree(scratch, ignore_errors=True)

# ===== SNAPSMACK EOF =====
