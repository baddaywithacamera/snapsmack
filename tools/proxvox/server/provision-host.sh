#!/bin/bash
# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical shell EOF marker below.

set -eu

WRAPPER_COMMIT=d5642f25292896c6d08544847f43c5f3cfc96a39
WRAPPER_SHA256=5f423ab350dbc7e453ff212193488c4be90646bb91f04ca1da14e82fa3a4a723
WRAPPER_URL="https://raw.githubusercontent.com/baddaywithacamera/snapsmack/${WRAPPER_COMMIT}/tools/proxvox/server/proxvox_readonly.py"

fail() {
    printf 'PROX VOX HOST FAILED: %s\n' "$*" >&2
    exit 1
}

[ "$(id -u)" -eq 0 ] || fail "run this on the Proxmox host as root"
for command_name in curl sha256sum install useradd chpasswd visudo grep mktemp cp; do
    command -v "$command_name" >/dev/null 2>&1 || fail "missing command: $command_name"
done

wrapper_tmp=$(mktemp /tmp/proxvox-readonly.XXXXXX)
cleanup() { rm -f "$wrapper_tmp"; }
trap cleanup EXIT HUP INT TERM
curl --fail --silent --show-error --location "$WRAPPER_URL" --output "$wrapper_tmp"
printf '%s  %s\n' "$WRAPPER_SHA256" "$wrapper_tmp" | sha256sum --check --status \
    || fail "reviewed wrapper SHA-256 mismatch"
install -o root -g root -m 0755 "$wrapper_tmp" /usr/local/sbin/proxvox-readonly

if ! id proxvox-root-ro >/dev/null 2>&1; then
    useradd --system --no-create-home --shell /bin/sh proxvox-root-ro
fi
printf 'Proxmox host proxvox-root-ro password: ' >&2
IFS= read -r -s password
printf '\n' >&2
[ -n "$password" ] || fail "password cannot be empty"
printf 'proxvox-root-ro:%s\n' "$password" | chpasswd
unset password

sudoers_tmp=$(mktemp /tmp/proxvox-sudoers.XXXXXX)
cat >"$sudoers_tmp" <<'EOF'
Defaults:proxvox-root-ro env_keep += "SSH_ORIGINAL_COMMAND"
proxvox-root-ro ALL=(root) NOPASSWD: /usr/local/sbin/proxvox-readonly
EOF
chmod 0440 "$sudoers_tmp"
visudo -cf "$sudoers_tmp" >/dev/null \
    || fail "sudoers validation failed"
install -o root -g root -m 0440 "$sudoers_tmp" /etc/sudoers.d/proxvox-root-ro
rm -f "$sudoers_tmp"

config=/etc/ssh/sshd_config
marker='# BEGIN PROX VOX proxvox-root-ro'
if ! grep -Fqx "$marker" "$config"; then
    if grep -Eq '^[[:space:]]*Match[[:space:]]+User[[:space:]]+proxvox-root-ro([[:space:]]|$)' "$config"; then
        fail "an unmarked proxvox-root-ro Match block already exists; no change was made"
    fi
    backup=$(mktemp /etc/ssh/sshd_config.proxvox.XXXXXX)
    cp -p "$config" "$backup"
    cat >>"$config" <<'EOF'

# BEGIN PROX VOX proxvox-root-ro
Match User proxvox-root-ro
    ForceCommand sudo -n /usr/local/sbin/proxvox-readonly
    PermitTTY no
    X11Forwarding no
    AllowAgentForwarding no
    AllowTcpForwarding no
# END PROX VOX proxvox-root-ro
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
systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || fail "could not reload SSH"

installed_sha=$(sha256sum /usr/local/sbin/proxvox-readonly | awk '{print $1}')
[ "$installed_sha" = "$WRAPPER_SHA256" ] || fail "installed wrapper SHA-256 mismatch"
printf 'PROX VOX HOST PASS — wrapper SHA-256 %s\n' "$installed_sha"

# ===== SNAPSMACK EOF =====
