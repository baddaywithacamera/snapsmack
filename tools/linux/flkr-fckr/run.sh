#!/usr/bin/env bash
# FLKR FCKR — launch the Chrome/Blink port on Linux.
# Sets a sane SnapSmack home and puts the shared library + the tool root on
# PYTHONPATH so app.py finds snap_blink (shared) and the reused work modules
# (config, flickr_parser, poster, checkpoint, image_prep) the same way the
# Windows tool did.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
export SNAPSMACK_HOME="${SNAPSMACK_HOME:-$HOME/snapsmack}"
export PYTHONPATH="$HERE/../../_shared:$HERE/../../$(basename "$HERE"):${PYTHONPATH:-}"
exec python3 "$HERE/app.py"
# ===== SNAPSMACK EOF =====
