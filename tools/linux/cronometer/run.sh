#!/usr/bin/env bash
# CRONOMETER — launch the Chrome/Blink port on Linux.
# Puts tools/_shared and tools/cronometer on PYTHONPATH so app.py can import
# snap_blink plus the original config.py / heartbeat_client.py, then runs app.py.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export SNAPSMACK_HOME="${SNAPSMACK_HOME:-$HOME/snapsmack}"
export PYTHONPATH="$HERE/../../_shared:$HERE/../../$(basename "$HERE"):${PYTHONPATH:-}"
exec python3 "$HERE/app.py"
# ===== SNAPSMACK EOF =====
