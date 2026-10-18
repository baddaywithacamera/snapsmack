#!/usr/bin/env bash
# SNAPSMACK_EOF_HEADER
# Last non-empty line of this file MUST be: # ===== SNAPSMACK EOF =====
# Missing or different = truncated/corrupted. Restore before saving.
#
# Which SKIN is each live site actually running, and is it the one we built?
#
# The CMS and the skins ship on different rails. Core goes out as a release;
# skins are packaged with the Skin Packager, published to the registry, and
# installed per site from SYSTEM UPDATES. Nothing pulls a site's skin forward
# on its own, so a site can run current core and a skin from months ago.
#
# That cost a full day on 17 Oct 2026. Skin fixes were landing in the repo and
# never appearing on the sites. foundtextures.ca was serving TRUE GRIT 1.3.2
# while the repo held 1.3.13, and because 1.3.2 predates the cms_controller
# manifest key, archive.php could not hand off to the paginated archive route
# -- so that page rendered all 1,592 images at once. Everybody kept checking
# the repo and calling it fixed. The repo was fine. The site was stale.
#
# This is deployed-version.sh for skins: the live value, asked rather than
# remembered. Read-only -- it changes nothing and touches no site's settings.
#
#   bash tools/_build/installed-skins.sh
#
# Exit 1 if any site is stale or could not be read, so it can gate a packaging
# run if anyone ever wants it to. It is NOT wired into agreed-rules-gate.php:
# a stale skin is not a reason to refuse a commit, and the gate must not need
# the network to answer.
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

# Same roster as deployed-version.sh. Keep the two in step.
SITES="wateronthebrain.ca theschoolofhardnocks.ca pixhellated.ca craptasti.ca
baddaywithacamera.ca foreverphotograph.ing fauxlaroid.fyi photofri.day
foundtextures.ca unzucked.ca photowalk.ing acolourlesslife.ca"

# Cloudflare 1010-blocks anything that does not look like a browser.
UA="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36"

stale=0
unread=0
current=0

printf '%-26s %-22s %-10s %-10s %s\n' SITE SKIN INSTALLED REPO STATE
printf '%-26s %-22s %-10s %-10s %s\n' -------------------------- ---------------------- ---------- ---------- -----

for s in $SITES; do
  html=$(curl -s -m 12 -A "$UA" "https://$s/?cb=$RANDOM" 2>/dev/null)

  if [ -z "$html" ]; then
    printf '%-26s %-22s %-10s %-10s %s\n' "$s" "?" "?" "?" "UNREACHABLE"
    unread=$((unread + 1))
    continue
  fi

  # The skin and its build are in the stylesheet cache-buster, and there are
  # TWO shapes in service -- assuming only one reported the whole fleet stale
  # on the first run of this script:
  #   /skins/<slug>/style.css?v=<skin-version>                 (most sites)
  #   /skins/<slug>/style.css?v=<cms-version>-<skin-version>   (foundtextures)
  # Match style.css itself; variant stylesheets are stamped differently.
  buster=$(printf '%s' "$html" | grep -oE 'skins/[a-z0-9-]+/style\.css\?v=[^"'"'"']+' | head -1)
  slug=$(printf '%s' "$buster" | sed -nE 's#skins/([a-z0-9-]+)/style\.css.*#\1#p')
  stamp=$(printf '%s' "$buster" | sed -nE 's#.*\?v=(.+)$#\1#p')
  case "$stamp" in
    # CMS version with the skin build after it.
    0.7.*D-*) live=${stamp#*D-} ;;
    # CMS version alone: this build stamps no skin version of its own.
    0.7.*D)   live="" ;;
    *)        live="$stamp" ;;
  esac

  if [ -z "$slug" ]; then
    printf '%-26s %-22s %-10s %-10s %s\n' "$s" "?" "?" "?" "NO SKIN STYLESHEET FOUND"
    unread=$((unread + 1))
    continue
  fi

  manifest="$ROOT/skins/$slug/manifest.json"
  if [ ! -f "$manifest" ]; then
    printf '%-26s %-22s %-10s %-10s %s\n' "$s" "$slug" "${live:-?}" "-" "SKIN NOT IN THIS REPO"
    unread=$((unread + 1))
    continue
  fi
  repo=$(grep -m1 -oE '"version"[[:space:]]*:[[:space:]]*"[^"]+"' "$manifest" | sed -E 's/.*"([^"]+)"$/\1/')

  if [ -z "$live" ]; then
    # Serving a build old enough that it stamps no skin version at all.
    printf '%-26s %-22s %-10s %-10s %s\n' "$s" "$slug" "unstamped" "$repo" "STALE (no version stamp)"
    stale=$((stale + 1))
  elif [ "$live" = "$repo" ]; then
    printf '%-26s %-22s %-10s %-10s %s\n' "$s" "$slug" "$live" "$repo" "current"
    current=$((current + 1))
  else
    # Deliberately NOT reported as "N releases behind". Version numbers do not
    # record how many packages were cut between two of them, and a made-up
    # count would be the same guessing this script exists to replace.
    printf '%-26s %-22s %-10s %-10s %s\n' "$s" "$slug" "$live" "$repo" "STALE"
    stale=$((stale + 1))
  fi
done

echo
echo "$current current, $stale stale, $unread unreadable."
if [ "$stale" -gt 0 ]; then
  echo "A stale site is running skin code nobody is looking at. Package the skin"
  echo "in SMACK CENTRAL, publish it, then update it from the site's SYSTEM UPDATES."
fi
[ "$stale" -gt 0 ] || [ "$unread" -gt 0 ] && exit 1
exit 0
# ===== SNAPSMACK EOF =====
