#!/usr/bin/env sh
set -eu
HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
if [ -x "$HERE/bin/snapsmack-photo-core" ]; then
  SNAPSMACK_PHOTO_CORE="$HERE/bin/snapsmack-photo-core"
  export SNAPSMACK_PHOTO_CORE
else
  cargo build --release --offline --manifest-path "$HERE/../core/Cargo.toml"
fi
PYTHONPATH="$HERE/../../_shared${PYTHONPATH:+:$PYTHONPATH}" exec python3 "$HERE/app.py"
# ===== SNAPSMACK EOF =====
