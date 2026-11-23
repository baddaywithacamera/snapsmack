<!-- SNAPSMACK_EOF_HEADER -->
<!-- Last non-empty line must be the canonical SNAPSMACK EOF marker. -->

# PROX VOX P1b numbered setup sheet

These are staged commands, not a record that they ran. Run the Linux block once
inside CT102 and CT106. Type the password only at the `passwd` prompt.

Run this sheet from top to bottom in one owner-authorized session:

1. Create the read-only SFTP account in CT102 and CT106.
2. Add and verify the container SSH restrictions.
3. Create the two source-bound MariaDB readers on CT110.
4. Install the root-owned read-only wrapper on the Proxmox host.
5. Add and verify the host SSH restrictions.
6. Save the four fresh credentials through the PROX VOX setup screen.
7. Run every proof in the final section; a refused write is a required PASS.
8. Record the installed wrapper SHA-256 and proof results in Brain Trust.

## 1. CT102 and CT106 read-only account

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

## 2. CT102 and CT106 SSH boundary

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

## 3. CT110 source-bound database readers

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

## 4. Proxmox host read-only wrapper

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

## 5. Proxmox host SSH boundary

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

The wrapper accepts only `inventory`, `host-list/stat/read`, and
`ct-list/stat/read`; it builds fixed argv with `shell=False`, returns complete
ordinary files without a size or operation-time ceiling, and has no write
operation. Do not grant this account any other sudo command.

Files that can authorize writes or impersonate the host return metadata plus a
SHA-256 digest, never their contents. The fixed server-side path list is:

- `/etc/pve/priv/**` (the complete secret tree, including future filenames)
- `/var/lib/pve-cluster/config.db*` (pmxcfs database plus WAL/SHM companions)
- `/etc/pve/nodes/*/pve-ssl.key`
- `/etc/pve/nodes/*/pveproxy-ssl.key`
- `/etc/corosync/authkey`
- `/etc/ssh/ssh_host_*_key` (not `.pub`)
- `/root/.ssh/id_*` and `/home/*/.ssh/id_*` (not `.pub`)
- `/etc/ssl/private/*`
- `/etc/pki/tls/private/*`
- `/etc/letsencrypt/live/*/privkey.pem`
- `/etc/letsencrypt/archive/*/privkey*.pem`
- `*.p12` and `*.pfx` anywhere (PKCS#12 private-key bundles)

The wrapper also detects standard OpenSSH, PEM and PuTTY private-key headers so
a symlink or custom filename cannot turn a private key into a content read. The
same policy runs after path resolution inside every container. Everything else
remains content-readable through bounded 1 MiB chunks, so file size does not
become Proxmox-host memory use. Each remote command has Sean's approved 60-second
timeout and no size cap; a timeout kills the command, is audited, and returns a
clear error.

## 6. Save credentials on the Windows workstation

Use the PROX VOX setup screen. It writes only to Windows Credential Manager via
`snap_native_creds.set_site`; do not put a password in this file, JSON, the
repository, or Brain Trust.

1. Save the CT102 SSH password as `proxvox:ct102_ssh`.
2. Save the CT106 SSH password as `proxvox:ct106_ssh`.
3. Save the shared source-bound database password as `proxvox:mysql_ro`.
4. Save the host-wrapper password as `proxvox:pve_host_ssh`.
5. Close and reopen the setup screen and confirm all four entries report
   present without displaying their values.

## 7. Required proofs before calling the setup complete

Record PASS or FAIL for every line. Never include returned file contents or a
credential in the record.

1. CT102: download one ordinary site file over SFTP.
2. CT102: prove upload, rename, delete, command execution, remote forwarding,
   agent forwarding and TTY all fail.
3. Repeat proofs 1 and 2 on CT106.
4. Through each source-bound MariaDB account, run a harmless `SELECT`,
   `SHOW VIEW`, and `SHOW GRANTS`; prove `INSERT`, `UPDATE`, `DELETE`, DDL and
   privilege changes fail.
5. Through the host wrapper, run `inventory`, one host `stat/read`, and one
   container `stat/read`; prove an unknown verb fails.
6. Read `/root/.ssh/id_ed25519.pub` and confirm normal content is returned.
7. Read `/etc/pve/priv/tfa.cfg.pub` and confirm only metadata plus SHA-256 is
   returned. This proves `.pub` never escapes the protected tree.
8. Read `/var/lib/pve-cluster/config.db.pub` and confirm only metadata plus
   SHA-256 is returned.
9. Read one ordinary file larger than 1 MiB and confirm chunked transfer
   reaches EOF with the same SHA-256 and bounded one-MiB chunks.
10. Inspect the off-box audit and confirm every attempt, success, refusal,
    malformed reply, timeout and non-refusal failure names the operation and
    path, never file contents.
11. Record the installed `/usr/local/sbin/proxvox-readonly` SHA-256 and compare
    it with the reviewed repository copy.

## 8. Completion record

Post one line to Brain Trust with: host, wrapper SHA-256, proof count, PASS/FAIL,
and any failed step numbers. Do not call the setup complete while any step is
unverified.

<!-- ===== SNAPSMACK EOF ===== -->
