#!/usr/bin/env bash
# THE HUB — launch the Chrome/Blink port on Linux.
# Puts tools/_shared (the shared library) and tools/hub (the original modules) on
# PYTHONPATH, gives the shared library a Linux-sane home if none is set, then runs
# the Blink app. Close the Chromium window to quit.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export SNAPSMACK_HOME="${SNAPSMACK_HOME:-$HOME/snapsmack}"
export PYTHONPATH="$HERE/../../_shared:$HERE/../../$(basename "$HERE"):$HERE:${PYTHONPATH:-}"
exec python3 "$HERE/app.py"
# ===== SNAPSMACK EOF =====
