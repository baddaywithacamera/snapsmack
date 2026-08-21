#!/usr/bin/env bash
# SMACK YOUR MOUTH — launch the Chrome/Blink port on Linux.
# Puts tools/_shared (snap_blink, snap_home, snap_creds, snap_profiles) and the
# tool root (config, fleet, moderation_*) on PYTHONPATH, then runs app.py.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export SNAPSMACK_HOME="${SNAPSMACK_HOME:-$HOME/snapsmack}"
export PYTHONPATH="$HERE/../../_shared:$HERE/../../$(basename "$HERE"):${PYTHONPATH:-}"
exec python3 "$HERE/app.py"
# ===== SNAPSMACK EOF =====
