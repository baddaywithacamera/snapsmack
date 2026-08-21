#!/usr/bin/env bash
# Smack Up Your Backup — launch the Chrome/Blink port on Linux.
# Opens the HTML window in Chromium; the backup work is the tool's own Python.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"

# Shared home (config, creds, profiles, staging). snap_blink also defaults this
# to ~/snapsmack if unset; set it here too so a bare `python3 app.py` matches.
export SNAPSMACK_HOME="${SNAPSMACK_HOME:-$HOME/snapsmack}"

# _shared holds snap_blink + snap_home + snap_creds; the tool root holds the
# engine modules (backup_engine, hub_discovery, cloud_client, suyb_core, …).
export PYTHONPATH="$HERE/../../_shared:$HERE/../../$(basename "$HERE"):${PYTHONPATH:-}"

exec python3 "$HERE/app.py"
# ===== SNAPSMACK EOF =====
