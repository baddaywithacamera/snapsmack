#!/usr/bin/env bash
# Print the HIGHEST version in service anywhere on the fleet.
#
# A single-site check is not enough and caused a real failure on 15 Oct 2026:
# the guard sampled wateronthebrain.ca, which was still on 0.7.891D, and
# allowed v0.7.892D to be force-moved -- while twelve other sites were already
# serving 892D. A tag that has been built anywhere must never move.
set -u
SITES="wateronthebrain.ca theschoolofhardnocks.ca pixhellated.ca craptasti.ca
baddaywithacamera.ca foreverphotograph.ing fauxlaroid.fyi photofri.day
foundtextures.ca unzucked.ca photowalk.ing acolourlesslife.ca
hekeepsdroningon.ca lightafterdark.ca allinthewrist.photoblogs.fyi
usedcarparts.photoblogs.fyi"
HIGHEST=""
for s in $SITES; do
  v=$(curl -s -m 8 "https://$s/?cb=$RANDOM" 2>/dev/null | grep -oE '0\.7\.[0-9]+D' | head -1)
  [ -z "$v" ] && continue
  if [ -z "$HIGHEST" ]; then HIGHEST="$v"; else
    hn=${HIGHEST#0.7.}; hn=${hn%D}; vn=${v#0.7.}; vn=${vn%D}
    [ "$vn" -gt "$hn" ] && HIGHEST="$v"
  fi
done
[ -z "$HIGHEST" ] && { echo "UNKNOWN"; exit 1; }
echo "$HIGHEST"
