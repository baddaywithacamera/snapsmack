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
# The SSH key only connects to the protocol. A live write still requires
# host-witnessed owner TOTP and the nonce for the exact file operation.
#
#   ./provision-host-writer.sh /path/to/writer.pub  install or re-install
#   ./provision-host-writer.sh verify               check the installed binary
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

# PINNED TO A REVIEWED REVISION, MEASURED RATHER THAN COPIED.
#
# WRITER_SHA256 is the sha256 of tools/proxvox/server/proxvox_writer.py AS
# COMMITTED at WRITER_COMMIT, which is what raw.githubusercontent.com serves
# and therefore what lands at WRITER_PATH. I verified that by fetching the
# published files before installation. Both hashes are against the committed
# bytes, rather than a Windows checkout that may have a different line ending.
#
# The writer at this commit adds host-attested sessions, operation nonces,
# server-side expiry and revoke, and full regular-file scope while authorized.
#
# RE-PIN WHEN THE WRITER MERGES. A commit reachable only from a topic branch
# stops being fetchable if that branch is deleted without being merged or
# archived, and this URL would then 404 on a host that is trying to install.
# Once the writer lands on dev, re-pin to the dev commit.
WRITER_COMMIT=5709332d588a0e4749f58353f3255a2e6d544155
WRITER_SHA256=aedb64d94a21caaa09668685d639c87db3200d9d18e0778cbd53d09f1d485f1d
WRITER_URL="https://raw.githubusercontent.com/baddaywithacamera/snapsmack/${WRITER_COMMIT}/tools/proxvox/server/proxvox_writer.py"
WRITER_PATH=/usr/local/sbin/proxvox-writer
DB_SHA256=e04c639235c578cdd3767d21bb20b4208b865bc7f062fd438c84f204fd8702ff
DB_URL="https://raw.githubusercontent.com/baddaywithacamera/snapsmack/${WRITER_COMMIT}/tools/proxvox/server/proxvox_database.py"
DB_PATH=/usr/local/sbin/proxvox_database.py
CONNECTOR_PATH=/usr/local/sbin/proxvox-pymysql.whl
CONNECTOR_SHA256=e169bed906168fffd0088925f45b3df024c2775c6fd128da0df773d47c458840
CONNECTOR_URL="https://files.pythonhosted.org/packages/a3/60/acffa1e7261fdc68fadf6f196b66058e03dcb3df239f2c0acbfd11165845/pymysql-1.2.4-py3-none-any.whl"
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
        [ -f "$DB_PATH" ] || fail "the pinned database companion is missing"
        installed_db=$(sha256sum "$DB_PATH" | awk '{print $1}')
        [ "$installed_db" = "$DB_SHA256" ] || fail "the database companion does not match the pinned revision"
        [ -f "$CONNECTOR_PATH" ] || fail "the pinned MariaDB connector package is missing"
        installed_connector=$(sha256sum "$CONNECTOR_PATH" | awk '{print $1}')
        [ "$installed_connector" = "$CONNECTOR_SHA256" ] || fail "the MariaDB connector package does not match the pin"
        note "installed writer and database companion match pinned revision ($WRITER_COMMIT)"
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
for command_name in curl sha256sum install useradd visudo grep mktemp awk sshd ssh-keygen systemctl python3 lxc-info; do
    command -v "$command_name" >/dev/null 2>&1 || fail "missing command: $command_name"
done
[ "$#" -eq 1 ] && [ -f "$1" ] || fail "supply the dedicated writer's Ed25519 public-key file"
public_key=$(awk 'NF { if (++lines > 1 || $1 != "ssh-ed25519" || NF < 2) exit 1; key = $1 " " $2 } END { if (lines != 1) exit 1; if (key != "") print key }' "$1") || fail "expected one Ed25519 public key"
[ -n "$public_key" ] && ssh-keygen -lf "$1" >/dev/null || fail "invalid Ed25519 public key"

writer_tmp=$(mktemp /tmp/proxvox-writer.XXXXXX)
db_tmp=
connector_tmp=
cleanup() { rm -f "$writer_tmp" "$db_tmp" "$connector_tmp"; }
trap cleanup EXIT HUP INT TERM
db_tmp=$(mktemp /tmp/proxvox-database.XXXXXX)
connector_tmp=$(mktemp /tmp/proxvox-connector.XXXXXX)
curl --fail --silent --show-error --location "$CONNECTOR_URL" --output "$connector_tmp"
printf '%s  %s\n' "$CONNECTOR_SHA256" "$connector_tmp" | sha256sum --check --status \
    || fail "pinned MariaDB package SHA-256 mismatch; nothing was installed"
curl --fail --silent --show-error --location "$DB_URL" --output "$db_tmp"
printf '%s  %s\n' "$DB_SHA256" "$db_tmp" | sha256sum --check --status \
    || fail "reviewed database companion SHA-256 mismatch; nothing was installed"
curl --fail --silent --show-error --location "$WRITER_URL" --output "$writer_tmp"
printf '%s  %s\n' "$WRITER_SHA256" "$writer_tmp" | sha256sum --check --status \
    || fail "reviewed writer SHA-256 mismatch; nothing was installed"
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

config=/etc/ssh/sshd_config
marker="# BEGIN PROX VOX ${WRITER_USER}"
end_marker="# END PROX VOX ${WRITER_USER}"
candidate=$(mktemp /etc/ssh/sshd_config.proxvox-writer-candidate.XXXXXX)
backup=$(mktemp /etc/ssh/sshd_config.proxvox-writer-backup.XXXXXX)
key_tmp=$(mktemp /tmp/proxvox-writer-key.XXXXXX)
key_backup=$(mktemp /tmp/proxvox-writer-key-backup.XXXXXX)
trap 'rm -f "$writer_tmp" "$db_tmp" "$connector_tmp" "$sudoers_tmp" "$candidate" "$backup" "$key_tmp" "$key_backup"' EXIT
cp -p "$config" "$backup"
awk -v start="$marker" -v end="$end_marker" '
    $0 == start { if (inside || seen++) exit 1; inside = 1; next }
    $0 == end { if (!inside) exit 1; inside = 0; next }
    !inside { print }
    END { if (inside) exit 1 }
' "$config" > "$candidate" || fail "existing writer Match block is malformed"
if grep -Eq "^[[:space:]]*Match[[:space:]]+User[[:space:]]+${WRITER_USER}([[:space:]]|$)" "$candidate"; then
    fail "an unmarked ${WRITER_USER} Match block already exists"
fi
cat >>"$candidate" <<EOF

# BEGIN PROX VOX ${WRITER_USER}
Match User ${WRITER_USER}
    AuthenticationMethods publickey
    PasswordAuthentication no
    KbdInteractiveAuthentication no
    AuthorizedKeysFile /etc/ssh/authorized_keys/%u
    ForceCommand sudo -n ${WRITER_PATH}
    DisableForwarding yes
    PermitTTY no
    X11Forwarding no
    AllowAgentForwarding no
    AllowTcpForwarding no
# END PROX VOX ${WRITER_USER}
EOF
sshd_path=$(command -v sshd || true)
[ -n "$sshd_path" ] || fail "sshd is missing"
"$sshd_path" -t -f "$candidate" || fail "candidate sshd configuration failed validation"
effective=$("$sshd_path" -T -f "$candidate" -C "user=$WRITER_USER,host=localhost,addr=127.0.0.1")
for setting in "authenticationmethods publickey" "passwordauthentication no" \
    "kbdinteractiveauthentication no" "disableforwarding yes" \
    "authorizedkeysfile /etc/ssh/authorized_keys/%u" "forcecommand sudo -n $WRITER_PATH"; do
    printf '%s\n' "$effective" | grep -Fqx "$setting" || fail "writer SSH setting did not take effect: $setting"
done
install -o root -g root -m 0644 "$connector_tmp" "$CONNECTOR_PATH"
install -o root -g root -m 0644 "$db_tmp" "$DB_PATH"
install -o root -g root -m 0755 "$writer_tmp" "$WRITER_PATH"
if ! id "$WRITER_USER" >/dev/null 2>&1; then
    useradd --system --no-create-home --shell /bin/sh "$WRITER_USER"
fi
install -o root -g root -m 0440 "$sudoers_tmp" /etc/sudoers.d/proxvox-root-rw
install -d -o root -g root -m 0755 /etc/ssh/authorized_keys
key_path="/etc/ssh/authorized_keys/$WRITER_USER"
if [ -f "$key_path" ]; then
    cp -p "$key_path" "$key_backup"
fi
printf 'restrict %s\n' "$public_key" > "$key_tmp"
install -o root -g root -m 0644 "$key_tmp" "$key_path"
chmod --reference="$config" "$candidate"
chown --reference="$config" "$candidate"
mv -f "$candidate" "$config"
if ! systemctl reload ssh; then
    cp -p "$backup" "$config"
    if [ -s "$key_backup" ]; then
        cp -p "$key_backup" "$key_path"
    else
        rm -f "$key_path"
    fi
    systemctl reload ssh || true
    fail "SSH reload failed; the previous configuration was restored"
fi

# The same check the reader does, and then the reason verify exists.
installed_sha=$(sha256sum "$WRITER_PATH" | awk '{print $1}')
[ "$installed_sha" = "$WRITER_SHA256" ] || fail "installed writer SHA-256 mismatch"
installed_db=$(sha256sum "$DB_PATH" | awk '{print $1}')
[ "$installed_db" = "$DB_SHA256" ] || fail "installed database companion SHA-256 mismatch"
installed_connector=$(sha256sum "$CONNECTOR_PATH" | awk '{print $1}')
[ "$installed_connector" = "$CONNECTOR_SHA256" ] || fail "installed MariaDB connector SHA-256 mismatch"

note "installed $WRITER_PATH from $WRITER_COMMIT"
note "account $WRITER_USER uses the dedicated public key and forced command"
note "the read-only account is untouched and remains read-only"
note "run '$0 verify' at any time to confirm the installed copy still matches"
note "live writes require host-witnessed owner TOTP and the exact operation nonce"

# ===== SNAPSMACK EOF =====
