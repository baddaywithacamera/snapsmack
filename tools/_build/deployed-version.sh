#!/usr/bin/env bash
# SNAPSMACK_EOF_HEADER
# Last non-empty line of this file MUST be: # ===== SNAPSMACK EOF =====
# Missing or different = truncated/corrupted. Restore before saving.
#
# What version is actually in service, and is anything left behind?
#
#   bash tools/_build/deployed-version.sh            # one line: highest in service
#   bash tools/_build/deployed-version.sh --report   # every site; exit 1 on stragglers
#
# TWO QUESTIONS, NOT ONE.
#
# "What is the highest version anywhere" guards a tag: a tag must never move
# once any site serves it. That is what the bare call answers, and
# tests/agreed-rules-gate.php parses exactly that one line, so it stays.
#
# "Is every site on it" is the opposite question, and this script could not
# answer it. On 21 Oct 2026 it printed 0.7.927D while photofri.day sat on
# 917D, photoblogs.fyi -- the relay and FLEET hub -- on 916D, and
# mirrorproject.fyi on 899D, twenty-eight versions behind. A maximum cannot
# see a straggler. The fleet updater reported "25 updated, 0 failed" at the
# same time and was believed, because a site that is simply old looks normal
# from outside: it serves, it renders, nothing errors. It just runs last
# month's code, security fixes included.
#
# So the bare call now also WARNS ON STDERR when sites are behind. The gate
# redirects stderr away and keeps its clean one-line answer; a human or an
# agent running it sees the straggler. Nobody has to remember a second command
# to be told the thing they would most want to know.
#
# Read-only. Touches nothing, changes nothing.
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
ROSTER="$ROOT/tools/_build/fleet-sites.txt"

# Cloudflare 1010-blocks anything that does not look like a browser.
UA="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36"

REPORT=0
[ "${1:-}" = "--report" ] && REPORT=1

if [ ! -f "$ROSTER" ]; then
  echo "UNKNOWN"
  echo "deployed-version: roster missing at $ROSTER" >&2
  exit 1
fi
SITES="$(grep -vE '^\s*(#|$)' "$ROSTER")"

num() { v="${1#0.7.}"; echo "${v%D}"; }

HIGHEST=""
FOUND=""      # "site version" per line
UNREAD=""

for s in $SITES; do
  v=$(curl -s -m 10 -A "$UA" "https://$s/?cb=$RANDOM" 2>/dev/null | grep -oE '0\.7\.[0-9]+D' | head -1)
  if [ -z "$v" ]; then UNREAD="$UNREAD $s"; continue; fi
  FOUND="$FOUND$s $v
"
  if [ -z "$HIGHEST" ] || [ "$(num "$v")" -gt "$(num "$HIGHEST")" ]; then HIGHEST="$v"; fi
done

if [ -z "$HIGHEST" ]; then
  echo "UNKNOWN"
  echo "deployed-version: no site answered with a version." >&2
  exit 1
fi

BEHIND=$(printf '%s' "$FOUND" | while read -r site ver; do
  [ -n "$site" ] && [ "$ver" != "$HIGHEST" ] && echo "$site $ver"
done)

if [ "$REPORT" = "1" ]; then
  printf '%-32s %-10s %s\n' SITE VERSION STATE
  printf '%-32s %-10s %s\n' -------------------------------- ---------- -----
  printf '%s' "$FOUND" | sort | while read -r site ver; do
    [ -z "$site" ] && continue
    if [ "$ver" = "$HIGHEST" ]; then printf '%-32s %-10s current\n' "$site" "$ver"
    else printf '%-32s %-10s BEHIND by %s\n' "$site" "$ver" "$(( $(num "$HIGHEST") - $(num "$ver") ))"; fi
  done
  for s in $UNREAD; do printf '%-32s %-10s %s\n' "$s" "-" "UNREADABLE"; done
  echo
  echo "highest in service: $HIGHEST"
  [ -n "$BEHIND" ] && { echo "STRAGGLERS: $(printf '%s
' "$BEHIND" | grep -c .) site(s) behind $HIGHEST."; exit 1; }
  [ -n "$UNREAD" ] && { echo "UNREADABLE:$UNREAD"; exit 1; }
  echo "every site on the roster is on $HIGHEST."
  exit 0
fi

# Bare call: the one line the gate parses, unchanged.
echo "$HIGHEST"

# ...and the warning it would otherwise never hear. stderr, so the gate's
# captured stdout stays exactly one version string.
if [ -n "$BEHIND" ]; then
  echo "deployed-version: WARNING -- $(printf '%s
' "$BEHIND" | grep -c .) site(s) behind $HIGHEST:" >&2
  printf '%s
' "$BEHIND" | while read -r site ver; do
    [ -n "$site" ] && echo "  $site $ver (behind by $(( $(num "$HIGHEST") - $(num "$ver") )))" >&2
  done
  echo "  run: bash tools/_build/deployed-version.sh --report" >&2
fi
[ -n "$UNREAD" ] && echo "deployed-version: could not read:$UNREAD" >&2

exit 0
# ===== SNAPSMACK EOF =====
