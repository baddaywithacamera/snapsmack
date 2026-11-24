"""Frozen & source entry point for KEYS PLEASE."""

import os
import sys

_ROOT = os.path.dirname(os.path.abspath(__file__))
_SHARED = os.path.normpath(os.path.join(_ROOT, "..", "_shared"))
for _p in (_ROOT, _SHARED):
    if os.path.isdir(_p) and _p not in sys.path:
        sys.path.insert(0, _p)

if "--cli" in sys.argv:
    import app_cli as _desktop_cli
    raise SystemExit(_desktop_cli.main(sys.argv[sys.argv.index("--cli") + 1:]))

from keysplease_qt.app import main

if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
