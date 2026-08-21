#!/usr/bin/env bash
# OH SNAP! — launch the Chrome/Blink port on Linux.
# Puts tools/_shared and tools/oh-snap on PYTHONPATH, then runs app.py, which
# starts a localhost-only server and opens a Chromium --app window.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export SNAPSMACK_HOME="${SNAPSMACK_HOME:-$HOME/snapsmack}"
export PYTHONPATH="$HERE/../../_shared:$HERE/../../$(basename "$HERE"):${PYTHONPATH:-}"
exec python3 "$HERE/app.py"
# ===== SNAPSMACK EOF =====
