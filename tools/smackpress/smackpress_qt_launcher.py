"""SMACKPRESS Qt launcher — the frozen exe's entry script (copied from coldsnap_qt_launcher.py)."""
# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
import os
import sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
# Headless automation must run before the desktop event loop or log capture.
import os as _cli_os, sys as _cli_sys
if "--cli" in _cli_sys.argv:
    _cli_sys.path.insert(0, _cli_os.path.abspath(_cli_os.path.join(_cli_os.path.dirname(__file__), "..", "_shared")))
    import app_cli as _desktop_cli
    raise SystemExit(_desktop_cli.main(_cli_sys.argv[_cli_sys.argv.index("--cli") + 1:]))

from smackpress_qt.app import main
raise SystemExit(main())
# ===== SNAPSMACK EOF =====
