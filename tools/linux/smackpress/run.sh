#!/usr/bin/env bash
# SMACKPRESS — launch the Chrome/Blink port on Linux.
# Serves the HTML window locally and opens it in Chromium; the Python side is the
# tool's own logic modules (config/db/wp_client/smacktalk_client/ai_client).
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export SNAPSMACK_HOME="${SNAPSMACK_HOME:-$HOME/snapsmack}"
# _shared holds snap_blink; the parent tool dir holds the smackpress package.
export PYTHONPATH="$HERE/../../_shared:$HERE/../../smackpress/smackpress:$HERE/../../smackpress:${PYTHONPATH:-}"
exec python3 "$HERE/app.py"
# ===== SNAPSMACK EOF =====
