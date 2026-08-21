#!/usr/bin/env bash
# SnapSmack Linux tool family — one launcher for every Chrome/Blink port.
#
#   ./run-tool.sh              list the tools
#   ./run-tool.sh sybu         launch SYBU in a Chrome/Blink window
#
# Each tool is a Python app whose window is drawn by Chromium (the Blink engine)
# via the shared tools/_shared/snap_blink.py runtime. No tkinter, no build step.
#
# Requirements on the Linux box: python3, and a Chromium/Chrome/Brave/Edge binary
# on PATH. Set SNAPSMACK_HOME to move the shared data root (default: ~/snapsmack).
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"

# tool key → path of its linux/app.py, relative to tools/
declare -A TOOLS=(
  [sybu]="linux/sybu/app.py"
  [coldsnap]="linux/coldsnap/app.py"
  [suyb]="linux/smack-up-your-backup/app.py"
  [hub]="linux/hub/app.py"
  [flkrfckr]="linux/flkr-fckr/app.py"
  [smackpress]="linux/smackpress/app.py"
  [unzucker]="linux/unzucker/app.py"
  [tyswy]="linux/take-your-shit-with-you/app.py"
  [shotsfired]="linux/shots-fired/app.py"
  [smackyourmouth]="linux/smack-your-mouth/app.py"
  [cronometer]="linux/cronometer/app.py"
  [smackattack]="linux/smackattack-scanner/app.py"
  [gyss]="linux/gyss/app.py"
  [ohsnap]="linux/oh-snap/app.py"
)

list() {
  echo "SnapSmack Linux tools (Chrome/Blink):"
  for k in $(printf '%s\n' "${!TOOLS[@]}" | sort); do
    app="$HERE/${TOOLS[$k]}"
    mark="  (not built)"; [ -f "$app" ] && mark=""
    printf "  %-16s %s%s\n" "$k" "${TOOLS[$k]}" "$mark"
  done
  echo
  echo "Usage: $0 <tool-key>"
}

main() {
  local key="${1:-}"
  if [ -z "$key" ]; then list; exit 0; fi
  local rel="${TOOLS[$key]:-}"
  if [ -z "$rel" ]; then echo "Unknown tool: $key"; echo; list; exit 1; fi
  local app="$HERE/$rel"
  if [ ! -f "$app" ]; then echo "Not built yet: $app"; exit 1; fi
  export SNAPSMACK_HOME="${SNAPSMACK_HOME:-$HOME/snapsmack}"
  export PYTHONPATH="$HERE/_shared:$HERE/$(basename "$(dirname "$rel")"):${PYTHONPATH:-}"
  exec python3 "$app"
}
main "$@"
# ===== SNAPSMACK EOF =====
