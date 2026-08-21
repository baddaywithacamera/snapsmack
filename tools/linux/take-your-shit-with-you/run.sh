#!/usr/bin/env bash
# TAKE YOUR SHIT WITH YOU — launch the Chrome/Blink port on Linux.
#
# Opens the window in whatever Chromium/Chrome/Brave/Edge is on the box (see
# snap_blink.find_chromium). The Python that does the actual export is the same
# code the Windows tkinter build runs.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"

# Shared library root (~/snapsmack) unless the caller already set one.
export SNAPSMACK_HOME="${SNAPSMACK_HOME:-$HOME/snapsmack}"

# _shared/ holds snap_blink + snap_vault; .. holds config/tyswy_client/
# export_engine/tyswy_core. app.py also inserts these on sys.path, so this is
# belt and suspenders.
export PYTHONPATH="$HERE/../../_shared:$HERE/../../$(basename "$HERE"):${PYTHONPATH:-}"

exec python3 "$HERE/app.py"
# ===== SNAPSMACK EOF =====
