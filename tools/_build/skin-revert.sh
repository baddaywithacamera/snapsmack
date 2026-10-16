#!/usr/bin/env bash
#
# Put one skin back exactly as it was, in a way that actually reaches the sites.
#
#   tools/_build/skin-revert.sh mark    <skin>          remember where it is now
#   tools/_build/skin-revert.sh marks   [skin]          list what can be gone back to
#   tools/_build/skin-revert.sh revert  <skin> [ref]    put it back
#   tools/_build/skin-revert.sh diff    <skin> [ref]    what changed since the mark
#
# WHY THE VERSION BUMP MATTERS, and why this is a script rather than a note.
#
# Skins install BY VERSION. Restoring a skin's files and leaving its version
# alone produces a revert that is correct in the repository and absent from
# every site: the registry offers the same number it already offered, each
# spoke says "already up to date", and the bad skin keeps serving. Sean has
# been burned by exactly this before.
#
# So `revert` never restores alone. It restores the files and then sets the
# version HIGHER than both the current and the restored one, so the fleet sees
# a new number and pulls it. The content goes backwards; the version only ever
# goes forwards.
#
# SNAPSHOT HONESTY. The mark is a git ref, not a copy of the files, so it
# cannot drift out of step with history and costs nothing to keep. Marking
# requires a clean tree for that skin, because a mark taken over uncommitted
# work would silently record something nobody can return to.
#
# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====

set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT" || exit 1
MARKS=".skin-marks"

die() { echo "  $*" >&2; exit 1; }

skin_version() {   # skin_version <skin> [ref]
  if [ -n "${2:-}" ]; then
    git show "$2:skins/$1/manifest.json" 2>/dev/null | grep -oE '"version"[^,]*' | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+'
  else
    grep -oE '"version"[^,]*' "skins/$1/manifest.json" 2>/dev/null | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+'
  fi
}

higher() {          # higher <a> <b>  -> the greater, bumped by one patch
  local a="$1" b="$2" pick
  pick="$a"
  if [ -n "$b" ]; then
    local A B; A=$(echo "$a" | awk -F. '{printf "%d%05d%05d",$1,$2,$3}')
    B=$(echo "$b" | awk -F. '{printf "%d%05d%05d",$1,$2,$3}')
    [ "$B" -gt "$A" ] && pick="$b"
  fi
  echo "$pick" | awk -F. '{printf "%d.%d.%d", $1, $2, $3+1}'
}

cmd="${1:-}"; skin="${2:-}"

case "$cmd" in
  mark)
    [ -n "$skin" ] || die "usage: skin-revert.sh mark <skin>"
    [ -d "skins/$skin" ] || die "no such skin: $skin"
    if ! git diff --quiet -- "skins/$skin" || ! git diff --cached --quiet -- "skins/$skin"; then
      die "skins/$skin has uncommitted changes. Commit or discard them first --
  a mark taken over uncommitted work records a state nobody can return to."
    fi
    ref=$(git rev-parse --short HEAD)
    ver=$(skin_version "$skin")
    mkdir -p "$MARKS"
    printf '%s %s %s\n' "$ref" "$ver" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >> "$MARKS/$skin"
    echo "  marked $skin at $ref (version $ver)"
    echo "  go back with: tools/_build/skin-revert.sh revert $skin"
    ;;

  marks)
    if [ -n "$skin" ]; then
      [ -f "$MARKS/$skin" ] || die "no marks for $skin"
      echo "  marks for $skin (newest last):"; sed 's/^/    /' "$MARKS/$skin"
    else
      [ -d "$MARKS" ] || die "no marks yet"
      for f in "$MARKS"/*; do
        [ -f "$f" ] || continue
        printf '  %-22s %s\n' "$(basename "$f")" "$(tail -1 "$f")"
      done
    fi
    ;;

  diff)
    [ -n "$skin" ] || die "usage: skin-revert.sh diff <skin> [ref]"
    ref="${3:-$(tail -1 "$MARKS/$skin" 2>/dev/null | awk '{print $1}')}"
    [ -n "$ref" ] || die "no mark for $skin and no ref given"
    echo "  $skin since $ref:"
    git diff --stat "$ref" -- "skins/$skin" | sed 's/^/    /'
    ;;

  revert)
    [ -n "$skin" ] || die "usage: skin-revert.sh revert <skin> [ref]"
    ref="${3:-$(tail -1 "$MARKS/$skin" 2>/dev/null | awk '{print $1}')}"
    [ -n "$ref" ] || die "no mark for $skin and no ref given. Marks: $MARKS/$skin"
    git rev-parse --verify "$ref^{commit}" >/dev/null 2>&1 || die "not a commit: $ref"

    now=$(skin_version "$skin")
    was=$(skin_version "$skin" "$ref")
    [ -n "$was" ] || die "could not read $skin's version at $ref"

    git checkout "$ref" -- "skins/$skin" || die "restore failed"

    # The content went backwards. The version must go forwards, or the fleet
    # will never pull it and the revert exists only in this repository.
    next=$(higher "$now" "$was")
    python - "$skin" "$next" <<'PY'
import io, re, sys
skin, nxt = sys.argv[1], sys.argv[2]
p = "skins/%s/manifest.json" % skin
s = io.open(p, encoding="utf-8").read()
m = re.search(r'"version"\s*:\s*"[0-9]+\.[0-9]+\.[0-9]+"', s)
io.open(p, "w", encoding="utf-8", newline="").write(s[:m.start()] + '"version": "%s"' % nxt + s[m.end():])
PY
    echo "  $skin restored from $ref"
    echo "    content : back to the $was files"
    echo "    version : $now -> $next  (forward, so the fleet actually pulls it)"
    echo
    echo "  Still to do: commit, tag, package, and INSTALL the skin."
    echo "  A revert that is not installed has not reverted anything."
    ;;

  *)
    sed -n '3,8p' "$0" | sed 's/^# \?//'
    exit 2
    ;;
esac

# ===== SNAPSMACK EOF =====
