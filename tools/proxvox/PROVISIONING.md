<!-- SNAPSMACK_EOF_HEADER -->
<!-- Last non-empty line must be the canonical SNAPSMACK EOF marker. -->

# PROX VOX P1b owner-run provisioning

These are staged commands, not a record that they ran. Run the Linux block once
inside CT102 and CT106. Type the password only at the `passwd` prompt.

```sh
sudo useradd --system --no-create-home --shell /usr/sbin/nologin proxvox-ro
sudo passwd proxvox-ro
sudo setfacl -R -m u:proxvox-ro:rX /var/www/sites /var/log/apache2
sudo setfacl -R -d -m u:proxvox-ro:rX /var/www/sites /var/log/apache2
sudo setfacl -m u:proxvox-ro:x /etc/apache2 /etc/apache2/sites-enabled /etc/php /etc/php/8.3 /etc/php/8.3/apache2
sudo setfacl -m u:proxvox-ro:r /etc/apache2/apache2.conf /etc/apache2/ports.conf /etc/php/8.3/apache2/php.ini
sudo find /etc/apache2/sites-enabled -maxdepth 1 -type f ! -name '*.key' -exec setfacl -m u:proxvox-ro:r {} +
sudo find /etc/apache2/sites-enabled -maxdepth 1 -type l ! -name '*.key' -exec sh -c 'for link do target=$(readlink -f "$link"); case "$target" in *.key) exit 1;; esac; setfacl -m u:proxvox-ro:r "$target"; done' sh {} +
sudo -u proxvox-ro find /etc/apache2 /etc/php -type f -name '*.key' -readable -print
```

The final command must print nothing. Replace `8.3` only with the installed PHP
version. Do not grant recursive ACLs on `/etc/apache2` or `/etc/php`; add another
named configuration file explicitly if a later read proves it is needed.

Append this exact `Match` block at the end of `sshd_config`, validate with `sshd -t`, then
reload SSH. `internal-sftp -R` is the server-side write refusal. The account may
open only a local tunnel to the database host; it cannot execute commands.
After reload, prove with this account that a download succeeds and an upload,
rename, and delete all fail; `sshd -t` alone does not exercise `-R`.

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
Repeat the two `GRANT` lines for every site schema that PROX VOX must inspect.
Never grant on `*.*`, `mysql.*`, `sys.*`, `performance_schema.*`, or
`information_schema.*`.

```sql
CREATE USER 'proxvox_ro'@'192.168.1.12' IDENTIFIED BY 'TYPE-A-FRESH-PASSWORD-HERE';
CREATE USER 'proxvox_ro'@'192.168.1.16' IDENTIFIED BY 'TYPE-THE-SAME-PASSWORD-HERE';
GRANT SELECT, SHOW VIEW ON `REPLACE_WITH_ONE_SITE_SCHEMA`.* TO 'proxvox_ro'@'192.168.1.12';
GRANT SELECT, SHOW VIEW ON `REPLACE_WITH_ONE_SITE_SCHEMA`.* TO 'proxvox_ro'@'192.168.1.16';
SHOW GRANTS FOR 'proxvox_ro'@'192.168.1.12';
SHOW GRANTS FOR 'proxvox_ro'@'192.168.1.16';
```

Save the two SSH passwords and the database password through the PROX VOX setup
screen. It calls `snap_native_creds.set_site` for `ct102_ssh`, `ct106_ssh`, and
`mysql_ro`; do not put any of them in this file or the local JSON config.

## Root-level host and all-container reads

On the Proxmox host, install `server/proxvox_readonly.py` as the root-owned
wrapper, then create a login that can run only that wrapper. This is the path
for host files, logs, storage/backup listings, and every CT including CT110.

```sh
sudo install -o root -g root -m 0755 server/proxvox_readonly.py /usr/local/sbin/proxvox-readonly
sudo useradd --system --no-create-home --shell /bin/sh proxvox-root-ro
sudo passwd proxvox-root-ro
sudo sh -c 'printf "%s\n" "Defaults:proxvox-root-ro env_keep += \"SSH_ORIGINAL_COMMAND\"" "proxvox-root-ro ALL=(root) NOPASSWD: /usr/local/sbin/proxvox-readonly" > /etc/sudoers.d/proxvox-root-ro'
sudo chmod 0440 /etc/sudoers.d/proxvox-root-ro
sudo visudo -cf /etc/sudoers.d/proxvox-root-ro
```

Append this block at the end of the host's `sshd_config`, validate it, and
reload SSH. The valid shell is required for OpenSSH to launch an external
forced command; the `Match` block prevents that shell from being selected by
the client and removes every TTY, forwarding, agent, and X11 path.

```text
Match User proxvox-root-ro
    ForceCommand sudo -n /usr/local/sbin/proxvox-readonly
    PermitTTY no
    X11Forwarding no
    AllowAgentForwarding no
    AllowTcpForwarding no
```

Save its fresh password as `proxvox:pve_host_ssh` through the PROX VOX setup
screen. The wrapper accepts only `inventory`, `host-list/stat/read`, and
`ct-list/stat/read`; it builds fixed argv with `shell=False`, caps reads at
16 MiB per call, and has no write operation. Do not grant this account any
other sudo command.

<!-- ===== SNAPSMACK EOF ===== -->
