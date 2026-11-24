#!/bin/bash
# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical shell EOF marker below.

set -eu

fail() {
    printf 'PROX VOX CT110 FAILED: %s\n' "$*" >&2
    exit 1
}

[ "$(id -u)" -eq 0 ] || fail "run this through pct exec as root"
database_client=$(command -v mariadb || command -v mysql || true)
[ -n "$database_client" ] || fail "MariaDB client is missing"
for command_name in od tr; do
    command -v "$command_name" >/dev/null 2>&1 || fail "missing command: $command_name"
done

printf 'CT110 shared proxvox_ro database password: ' >&2
IFS= read -r -s password
printf '\n' >&2
[ -n "$password" ] || fail "password cannot be empty"
password_hex=$(printf '%s' "$password" | od -An -vtx1 | tr -d ' \n')
unset password

"$database_client" --batch --skip-column-names <<SQL
SET @proxvox_password = CONVERT(0x${password_hex} USING utf8mb4);
SET @create_102 = CONCAT("CREATE USER IF NOT EXISTS 'proxvox_ro'@'192.168.1.12' IDENTIFIED BY ", QUOTE(@proxvox_password));
PREPARE proxvox_stmt FROM @create_102; EXECUTE proxvox_stmt; DEALLOCATE PREPARE proxvox_stmt;
SET @alter_102 = CONCAT("ALTER USER 'proxvox_ro'@'192.168.1.12' IDENTIFIED BY ", QUOTE(@proxvox_password));
PREPARE proxvox_stmt FROM @alter_102; EXECUTE proxvox_stmt; DEALLOCATE PREPARE proxvox_stmt;
SET @create_106 = CONCAT("CREATE USER IF NOT EXISTS 'proxvox_ro'@'192.168.1.16' IDENTIFIED BY ", QUOTE(@proxvox_password));
PREPARE proxvox_stmt FROM @create_106; EXECUTE proxvox_stmt; DEALLOCATE PREPARE proxvox_stmt;
SET @alter_106 = CONCAT("ALTER USER 'proxvox_ro'@'192.168.1.16' IDENTIFIED BY ", QUOTE(@proxvox_password));
PREPARE proxvox_stmt FROM @alter_106; EXECUTE proxvox_stmt; DEALLOCATE PREPARE proxvox_stmt;
GRANT SELECT, SHOW VIEW ON *.* TO 'proxvox_ro'@'192.168.1.12';
GRANT SELECT, SHOW VIEW ON *.* TO 'proxvox_ro'@'192.168.1.16';
SHOW GRANTS FOR 'proxvox_ro'@'192.168.1.12';
SHOW GRANTS FOR 'proxvox_ro'@'192.168.1.16';
SQL
unset password_hex

printf 'PROX VOX CT110 PASS\n'

# ===== SNAPSMACK EOF =====
