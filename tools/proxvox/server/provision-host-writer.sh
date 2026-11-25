#!/bin/bash
# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical shell EOF marker below.
#
# PROX VOX — provision the SCOPED WRITER account on the Proxmox host. (SS-1507)
#
# Sean's go, 2026-11-24 22:43. This installs the root-owned forced-command
# writer and its own dedicated account, SEPARATE from the read-only one.
#
# WHY A SECOND ACCOUNT AND NOT A SECOND COMMAND ON THE FIRST. proxvox-root-ro
# is the read-only account and its name is a promise. Adding a write capability
# to it would mean every existing read key could write, and nothing about the
# account would say so. So: its own user, its own key, its own forced command,
# its own sudoers line. Revoking write access is then deleting one account,
# not editing a protocol.
#
# IT INSTALLS NOTHING THAT CAN WRITE ANYTHING YET. The wrapper ships with an
# allowlist holding one verified file and no maintenance tasks, and a live
# write additionally needs a grant from a fresh owner login. Installing this
# does not grant a capability; it puts the mechanism in place for one.
#
#   ./provision-host-writer.sh           install or re-install
#   ./provision-host-writer.sh verify    check the installed copy, change nothing
#
# THE verify MODE IS WHY THIS FILE EXISTS IN THIS SHAPE, and it comes straight
# out of SS-1574. The read-only provisioner checks the installed checksum AT
# INSTALL TIME and never again, so a host provisioned before a fix keeps the
# old behaviour indefinitely and reports nothing. That is exactly what happened
# to the read wrapper: the live host ran a revision whose find format contained
# a real NUL byte, every directory listing failed, and it was found by accident
# weeks later. verify compares what is installed against what is pinned here
# and says so, so the next lag is reported rather than discovered.

set -eu

WRITER_COMMIT=REPLACE_WITH_REVIEWED_COMMIT
WRITER_SHA256=REPLACE_WITH_REVIEWED_SHA256
WRITER_URL="https://raw.githubusercontent.com/baddaywithacamera/snapsmack/${WRITER_COMMIT}/tools/proxvox/server/proxvox_writer.py"
WRITER_PATH=/usr/local/sbin/proxvox-writer
WRITER_USER=proxvox-root-rw

fail() {
    printf 'PROX VOX WRITER FAILED: %s\n' "$*" >&2
    exit 1
}

note() {
    printf 'PROX VOX WRITER: %s\n' "$*" >&2
}

# ── verify: read-only, changes nothing, and is safe to run any time ─────────
if [ "${1-}" = "verify" ]; then
    command -v sha256sum >/dev/null 2>&1 || fail "missing command: sha256sum"
    # Refuse rather than compare against the placeholder. A verify that always
    # reports drift is a verify people learn to ignore, which would leave this
    # file in the same state as the check it exists to replace.
    case "$WRITER_SHA256" in
        REPLACE_WITH_*)
            fail "no reviewed revision is pinned, so there is nothing to verify against. Set WRITER_COMMIT and WRITER_SHA256 first."
            ;;
    esac
    [ -f "$WRITER_PATH" ] || fail "no writer is installed at $WRITER_PATH"
    installed=$(sha256sum "$WRITER_PATH" | awk '{print $1}')
    if [ "$installed" = "$WRITER_SHA256" ]; then
        note "installed writer matches the pinned revision ($WRITER_COMMIT)"
        exit 0
    fi
    printf 'PROX VOX WRITER DRIFT: the installed writer is NOT the pinned one.\n' >&2
    printf '  installed: %s\n' "$installed" >&2
    printf '  pinned   : %s  (%s)\n' "$WRITER_SHA256" "$WRITER_COMMIT" >&2
    printf 'Re-run this script without "verify" to install the pinned copy.\n' >&2
    printf 'This is the condition that hid SS-1574 for weeks: an installed copy\n' >&2
    printf 'older than the fix, reporting nothing wrong.\n' >&2
    exit 1
fi

case "$WRITER_COMMIT" in
    REPLACE_WITH_*)
        fail "this script has no reviewed commit pinned yet. Set WRITER_COMMIT and WRITER_SHA256 to the reviewed revision before running it. Refusing is the safe answer: an unpinned installer fetches whatever is newest, which is how an unreviewed wrapper reaches a live host."
        ;;
esac

[ "$(id -u)" -eq 0 ] || fail "run this on the Proxmox host as root"
for command_name in curl sha256sum install useradd chpasswd visudo grep mktemp awk sshd; do
    command -v "$command_name" >/dev/null 2>&1 || fail "missing command: $command_name"
done

writer_tmp=$(mktemp /tmp/proxvox-writer.XXXXXX)
cleanup() { rm -f "$writer_tmp"; }
trap cleanup EXIT HUP INT TERM
curl --fail --silent --show-error --location "$WRITER_URL" --output "$writer_tmp"
printf '%s  %s\n' "$WRITER_SHA256" "$writer_tmp" | sha256sum --check --status \
    || fail "reviewed writer SHA-256 mismatch; nothing was installed"
install -o root -g root -m 0755 "$writer_tmp" "$WRITER_PATH"

if ! id "$WRITER_USER" >/dev/null 2>&1; then
    useradd --system --no-create-home --shell /bin/sh "$WRITER_USER"
fi
printf 'Proxmox host %s password: ' "$WRITER_USER" >&2
IFS= read -r -s password
printf '\n' >&2
[ -n "$password" ] || fail "password cannot be empty"
printf '%s:%s\n' "$WRITER_USER" "$password" | chpasswd
unset password

# The writer runs under sudo with SSH_ORIGINAL_COMMAND kept, exactly as the
# reader does. It is a SEPARATE sudoers line naming a SEPARATE binary, so the
# read-only account gains nothing from this file existing.
sudoers_tmp=$(mktemp /tmp/proxvox-writer-sudoers.XXXXXX)
cat >"$sudoers_tmp" <<EOF
Defaults:${WRITER_USER} env_keep += "SSH_ORIGINAL_COMMAND"
${WRITER_USER} ALL=(root) NOPASSWD: ${WRITER_PATH}
EOF
chmod 0440 "$sudoers_tmp"
visudo -cf "$sudoers_tmp" >/dev/null || fail "sudoers validation failed"
install -o root -g root -m 0440 "$sudoers_tmp" /etc/sudoers.d/proxvox-root-rw
rm -f "$sudoers_tmp"

config=/etc/ssh/sshd_config
marker="# BEGIN PROX VOX ${WRITER_USER}"
if ! grep -Fqx "$marker" "$config"; then
    if grep -Eq "^[[:space:]]*Match[[:space:]]+User[[:space:]]+${WRITER_USER}([[:space:]]|$)" "$config"; then
        fail "an unmarked ${WRITER_USER} Match block already exists; no change was made"
    fi
    backup=$(mktemp /etc/ssh/sshd_config.proxvox-writer.XXXXXX)
    cp -p "$config" "$backup"
    cat >>"$config" <<EOF

# BEGIN PROX VOX ${WRITER_USER}
Match User ${WRITER_USER}
    ForceCommand sudo -n ${WRITER_PATH}
    PermitTTY no
    X11Forwarding no
    AllowAgentForwarding no
    AllowTcpForwarding no
# END PROX VOX ${WRITER_USER}
EOF
    sshd_path=$(command -v sshd || true)
    [ -n "$sshd_path" ] || { cp -p "$backup" "$config"; fail "sshd is missing; configuration restored"; }
    if ! "$sshd_path" -t; then
        cp -p "$backup" "$config"
        fail "sshd validation failed; configuration restored"
    fi
    rm -f "$backup"
fi

sshd_path=$(command -v sshd || true)
[ -n "$sshd_path" ] || fail "sshd is missing"
"$sshd_path" -t || fail "sshd validation failed"

# The same check the reader does, and then the reason verify exists.
installed_sha=$(sha256sum "$WRITER_PATH" | awk '{print $1}')
[ "$installed_sha" = "$WRITER_SHA256" ] || fail "installed writer SHA-256 mismatch"

note "installed $WRITER_PATH from $WRITER_COMMIT"
note "account $WRITER_USER created with a forced command and no TTY"
note "the read-only account is untouched and remains read-only"
note "run '$0 verify' at any time to confirm the installed copy still matches"
note "no path can be written until one is in the wrapper's allowlist AND a"
note "grant from a fresh owner login accompanies the write"

# ===== SNAPSMACK EOF =====
