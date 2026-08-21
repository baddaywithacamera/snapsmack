#!/usr/bin/env bash
# SMACKATTACK SCANNER — launch the Chrome/Blink port on Linux.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export SNAPSMACK_HOME="${SNAPSMACK_HOME:-$HOME/snapsmack}"
# _shared holds snap_blink; the tool root holds config.py/db.py/scanner.py/smackattack_core.py
export PYTHONPATH="$HERE/../../_shared:$HERE/../../$(basename "$HERE"):${PYTHONPATH:-}"
exec python3 "$HERE/app.py"
# ===== SNAPSMACK EOF =====
