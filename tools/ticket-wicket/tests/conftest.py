"""Keep pytest's scratch inside the tool's own build area.

pytest's default temp root under %LOCALAPPDATA%\Temp is refused in the
sandboxed environment the agents run in, which makes every tmp_path test error
out with PermissionError. This lives in conftest.py rather than pytest.ini
because tools/*/*.ini is gitignored, so an ini would work on one machine and
silently fail for everybody else — including Codex.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os


def pytest_configure(config):
    if not config.option.basetemp:
        here = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
        scratch = os.path.join(here, "build", "_pytest_tmp")
        os.makedirs(scratch, exist_ok=True)
        config.option.basetemp = scratch

# ===== SNAPSMACK EOF =====
