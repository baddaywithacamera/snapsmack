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
```

The direct container account remains useful for ordinary site/log reads and
the database tunnel. Root-level reads of every other container path, including
configuration, private keys and hashes, use the host wrapper below; no separate
per-container `/etc` ACL is needed.

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
Sean's full-read ruling includes every schema, including system tables and
credential hashes. These accounts remain source-bound and read-only: they have
`SELECT` and `SHOW VIEW`, with no database write privilege.

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
`ct-list/stat/read`; it builds fixed argv with `shell=False`, returns complete
files without a size or operation-time ceiling, and has no write operation. Do
not grant this account any other sudo command.

<!-- ===== SNAPSMACK EOF ===== -->
