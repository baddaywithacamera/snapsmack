#!/usr/bin/env bash
# GET YOUR SHIT SORTED (GYSS) — launch the Chrome/Blink port on Linux.
# Puts tools/_shared (snap_blink, snap_home) and tools/gyss on PYTHONPATH, then
# runs app.py, which opens a Chromium --app window and blocks until it is closed.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export SNAPSMACK_HOME="${SNAPSMACK_HOME:-$HOME/snapsmack}"
export PYTHONPATH="$HERE/../../_shared:$HERE/../../$(basename "$HERE"):${PYTHONPATH:-}"
exec python3 "$HERE/app.py"
# ===== SNAPSMACK EOF =====
