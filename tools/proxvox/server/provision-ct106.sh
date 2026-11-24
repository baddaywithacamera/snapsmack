#!/bin/bash
# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical shell EOF marker below.

set -eu

fail() {
    printf 'PROX VOX CT106 FAILED: %s\n' "$*" >&2
    exit 1
}

[ "$(id -u)" -eq 0 ] || fail "run this through pct exec as root"
for command_name in useradd chpasswd setfacl find grep mktemp cp; do
    command -v "$command_name" >/dev/null 2>&1 || fail "missing command: $command_name"
done

if ! id proxvox-ro >/dev/null 2>&1; then
    useradd --system --no-create-home --shell /usr/sbin/nologin proxvox-ro
fi

printf 'CT106 proxvox-ro password: ' >&2
IFS= read -r -s password
printf '\n' >&2
[ -n "$password" ] || fail "password cannot be empty"
printf 'proxvox-ro:%s\n' "$password" | chpasswd
unset password

# CT106 stores each site directly under /var/www. Unlike CT102, it has no
# /var/www/sites wrapper; the live fail-closed provisioning check confirmed
# the six Apache document roots are /var/www/<site>.
for path in /var/www /var/log/apache2; do
    [ -d "$path" ] || fail "required directory is missing: $path"
    setfacl -R -m u:proxvox-ro:rX "$path"
    find "$path" -type d -exec setfacl -m d:u:proxvox-ro:rX {} +
done

config=/etc/ssh/sshd_config
marker='# BEGIN PROX VOX proxvox-ro'
if ! grep -Fqx "$marker" "$config"; then
    if grep -Eq '^[[:space:]]*Match[[:space:]]+User[[:space:]]+proxvox-ro([[:space:]]|$)' "$config"; then
        fail "an unmarked proxvox-ro Match block already exists; no change was made"
    fi
    backup=$(mktemp /etc/ssh/sshd_config.proxvox.XXXXXX)
    cp -p "$config" "$backup"
    cat >>"$config" <<'EOF'

# BEGIN PROX VOX proxvox-ro
Match User proxvox-ro
    ForceCommand internal-sftp -R
    PermitTTY no
    X11Forwarding no
    AllowAgentForwarding no
    AllowTcpForwarding local
    PermitOpen 192.168.1.20:3306
# END PROX VOX proxvox-ro
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

printf 'PROX VOX CT106 PASS\n'

# ===== SNAPSMACK EOF =====
