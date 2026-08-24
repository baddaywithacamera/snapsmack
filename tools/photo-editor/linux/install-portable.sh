#!/usr/bin/env sh
set -eu
ROOT=${1:-${SNAPSMACK_HOME:-}}
if [ -z "$ROOT" ]; then echo "Pass the SnapSmack USB suite root or set SNAPSMACK_HOME." >&2; exit 2; fi
HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(CDPATH= cd -- "$ROOT" && pwd)
case "$ROOT" in /) echo "Refusing to install into /." >&2; exit 2;; esac
cargo build --release --offline --manifest-path "$HERE/../core/Cargo.toml"
DEST="$ROOT/pixel-tickler"
mkdir -p "$DEST/linux/bin" "$DEST/web"
cp "$HERE/app.py" "$HERE/run.sh" "$DEST/linux/"
cp "$HERE/../core/target/release/snapsmack-photo-core" "$DEST/linux/bin/"
cp "$HERE/../web/index.html" "$HERE/../web/editor.css" "$HERE/../web/editor.js" "$HERE/../web/editor-host.js" "$DEST/web/"
chmod +x "$DEST/linux/run.sh" "$DEST/linux/bin/snapsmack-photo-core"
echo "PIXEL TICKLER installed to $DEST"
