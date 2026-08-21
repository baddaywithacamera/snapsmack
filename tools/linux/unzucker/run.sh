#!/usr/bin/env bash
# UNZUCKER — launch the Chrome/Blink port on Linux.
# Puts tools/_shared (snap_blink) and tools/unzucker (the tool's own modules)
# on PYTHONPATH, then runs app.py, which opens the Chromium app window.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export SNAPSMACK_HOME="${SNAPSMACK_HOME:-$HOME/snapsmack}"
export PYTHONPATH="$HERE/../../_shared:$HERE/../../$(basename "$HERE"):${PYTHONPATH:-}"
exec python3 "$HERE/app.py"
# ===== SNAPSMACK EOF =====
