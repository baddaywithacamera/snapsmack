<!-- SNAPSMACK_EOF_HEADER -->
<!-- Last non-empty line must be the canonical SNAPSMACK EOF marker. -->

# PROX VOX P1b owner-run provisioning

These are staged commands, not a record that they ran. Run the Linux block once
inside CT102 and CT106. Type the password only at the `passwd` prompt.

```sh
sudo useradd --system --no-create-home --shell /usr/sbin/nologin proxvox-ro
sudo passwd proxvox-ro
sudo setfacl -R -m u:proxvox-ro:rX /var/www/sites /var/log/apache2 /etc/apache2 /etc/php
sudo setfacl -R -d -m u:proxvox-ro:rX /var/www/sites /var/log/apache2
```

Append this exact `Match` block to `sshd_config`, validate with `sshd -t`, then
reload SSH. `internal-sftp -R` is the server-side write refusal. The account may
open only a local tunnel to the database host; it cannot execute commands.

```text
Match User proxvox-ro
    ForceCommand internal-sftp -R
    PermitTTY no
    X11Forwarding no
    AllowAgentForwarding no
    AllowTcpForwarding local
    PermitOpen 192.168.1.20:3306
```

On CT110, open the MariaDB client as an administrator. Type a fresh password in
place of the placeholder; use the same password for both source-bound accounts.

```sql
CREATE USER 'proxvox_ro'@'192.168.1.12' IDENTIFIED BY 'TYPE-A-FRESH-PASSWORD-HERE';
CREATE USER 'proxvox_ro'@'192.168.1.16' IDENTIFIED BY 'TYPE-THE-SAME-PASSWORD-HERE';
GRANT SELECT, SHOW VIEW ON *.* TO 'proxvox_ro'@'192.168.1.12';
GRANT SELECT, SHOW VIEW ON *.* TO 'proxvox_ro'@'192.168.1.16';
SHOW GRANTS FOR 'proxvox_ro'@'192.168.1.12';
SHOW GRANTS FOR 'proxvox_ro'@'192.168.1.16';
```

Save the two SSH passwords and the database password through the PROX VOX setup
screen. It calls `snap_native_creds.set_site` for `ct102_ssh`, `ct106_ssh`, and
`mysql_ro`; do not put any of them in this file or the local JSON config.

<!-- ===== SNAPSMACK EOF ===== -->
