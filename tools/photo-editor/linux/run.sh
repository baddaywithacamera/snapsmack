#!/usr/bin/env sh
set -eu
HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
cargo build --release --offline --manifest-path "$HERE/../core/Cargo.toml"
PYTHONPATH="$HERE/../../_shared${PYTHONPATH:+:$PYTHONPATH}" exec python3 "$HERE/app.py"
# ===== SNAPSMACK EOF =====
