#!/usr/bin/env bash
# COLD SNAP — launch the Chrome/Blink port on Linux.
# Opens the offline poster in a Chromium --app window (no dev server, no tkinter).
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"

# Shared-library root. snap_blink also defaults this to ~/snapsmack if unset,
# but set it here so config/creds/profiles land in the family location.
export SNAPSMACK_HOME="${SNAPSMACK_HOME:-$HOME/snapsmack}"

# tools/_shared (snap_blink + shared library) and tools/coldsnap (the tool's
# own modules: config, profile_manager, sumna_*) must be importable.
export PYTHONPATH="$HERE/../../_shared:$HERE/../../$(basename "$HERE"):${PYTHONPATH:-}"

exec python3 "$HERE/app.py"
# ===== SNAPSMACK EOF =====
