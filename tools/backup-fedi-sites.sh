#!/bin/bash
set -Eeuo pipefail

# Back up the three production Fedistructure document roots from CT106.
# Database contents are handled separately by mariadb_backup.sh on this host.

readonly CT_ID=106
readonly STAGING=/var/backups/fedi-sites
readonly LOG=/var/log/fedi-sites-backup.log
readonly REMOTE=b2-optiplex-www:OptiplexWWW/fedi-sites
readonly STAMP="$(date +%Y%m%d_%H%M%S)"
readonly BASENAME="fedi-sites_${STAMP}.tar.zst"
readonly ARCHIVE="${STAGING}/${BASENAME}"
readonly CHECKSUM="${ARCHIVE}.sha256"
readonly CONTENTS="${ARCHIVE}.contents"

mkdir -p "$STAGING"
umask 077

log() {
    printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1" | tee -a "$LOG"
}

prune_remote_archives() {
    local file stamp archive_date week month
    local daily_count=0 weekly_count=0 monthly_count=0
    local -a archives=()
    declare -A keep=() seen_day=() seen_week=() seen_month=()

    mapfile -t archives < <(
        rclone lsf "$REMOTE" --files-only --include 'fedi-sites_*.tar.zst' | sort -r
    )

    # Sorted newest first: retain one recovery point for each of the newest
    # 14 calendar days, 8 ISO weeks, and 12 calendar months. A file can satisfy
    # more than one tier. Files with unexpected names are deliberately ignored.
    for file in "${archives[@]}"; do
        if [[ ! $file =~ ^fedi-sites_([0-9]{8})_([0-9]{6})\.tar\.zst$ ]]; then
            log "WARNING: Leaving unrecognized remote file untouched: ${file}"
            continue
        fi
        stamp=${BASH_REMATCH[1]}
        archive_date="${stamp:0:4}-${stamp:4:2}-${stamp:6:2}"
        if ! date -d "$archive_date" >/dev/null 2>&1; then
            log "WARNING: Leaving invalid dated remote file untouched: ${file}"
            continue
        fi

        if [[ -z ${seen_day[$archive_date]+x} && $daily_count -lt 14 ]]; then
            seen_day[$archive_date]=1
            keep[$file]=1
            ((daily_count += 1))
        fi

        week=$(date -d "$archive_date" +%G-%V)
        if [[ -z ${seen_week[$week]+x} && $weekly_count -lt 8 ]]; then
            seen_week[$week]=1
            keep[$file]=1
            ((weekly_count += 1))
        fi

        month=${archive_date:0:7}
        if [[ -z ${seen_month[$month]+x} && $monthly_count -lt 12 ]]; then
            seen_month[$month]=1
            keep[$file]=1
            ((monthly_count += 1))
        fi
    done

    for file in "${archives[@]}"; do
        [[ -n ${keep[$file]+x} ]] && continue
        [[ $file =~ ^fedi-sites_[0-9]{8}_[0-9]{6}\.tar\.zst$ ]] || continue
        log "Pruning expired remote recovery point: ${file}"
        rclone deletefile "${REMOTE}/${file}"
        rclone deletefile "${REMOTE}/${file}.sha256" 2>/dev/null || true
    done

    log "Remote retention applied: 14 daily, 8 weekly, 12 monthly recovery points."
}

cleanup_failed_archive() {
    if [[ ${1:-0} -ne 0 ]]; then
        rm -f "$ARCHIVE" "$CHECKSUM" "$CONTENTS"
        log "ERROR: Fedistructure file backup failed."
    fi
}
trap 'cleanup_failed_archive $?' EXIT

command -v pct >/dev/null
command -v zstd >/dev/null
command -v rclone >/dev/null

log "Archiving CT106 production Fedistructure sites."
pct exec "$CT_ID" -- tar --numeric-owner --one-file-system \
    -C /var/www -cf - \
    photofri.day photoblogs.fyi mirrorproject.fyi \
    | zstd -T0 -q -o "$ARCHIVE"

zstd -q -t "$ARCHIVE"
zstd -q -dc "$ARCHIVE" | tar -tf - > "$CONTENTS"
for site in photofri.day photoblogs.fyi mirrorproject.fyi; do
    if ! grep -q "^${site}/" "$CONTENTS"; then
        log "ERROR: ${site} is missing from ${BASENAME}."
        exit 1
    fi
done
rm -f "$CONTENTS"

(cd "$STAGING" && sha256sum "$BASENAME" > "${BASENAME}.sha256")
rclone copyto "$ARCHIVE" "${REMOTE}/${BASENAME}" --checksum
rclone copyto "$CHECKSUM" "${REMOTE}/${BASENAME}.sha256" --checksum
prune_remote_archives

find "$STAGING" -type f \( -name 'fedi-sites_*.tar.zst' -o -name 'fedi-sites_*.tar.zst.sha256' \) \
    -mmin +10080 -delete

log "OK: Uploaded ${BASENAME} and checksum."
trap - EXIT

