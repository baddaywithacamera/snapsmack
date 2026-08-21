#!/usr/bin/env bash
# SMACK YOUR BATCH UP — launch the Chrome/Blink port on Linux.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export SNAPSMACK_HOME="${SNAPSMACK_HOME:-$HOME/snapsmack}"
# _shared (snap_blink + shared library) and the tool dir (poster/gemini/... modules).
export PYTHONPATH="$HERE/../../_shared:$HERE/../../$(basename "$HERE"):${PYTHONPATH:-}"
exec python3 "$HERE/app.py"
# ===== SNAPSMACK EOF =====
