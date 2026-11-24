<!-- SNAPSMACK_EOF_HEADER -->
<!-- Last non-empty line must be the canonical SNAPSMACK EOF marker. -->

# PROX VOX P1b four-paste setup sheet

Sean does not hand-type Linux, SQL, ACL, SSH or installer commands. His part is
four paste lines in the Proxmox shell, then four entries in the PROX VOX setup
screen. Each line downloads one target-specific installer from the reviewed
commit, verifies the printed SHA-256 before executing it, and stops loudly on
the first failure.

CT110 remains at base installer commit
`8fd93087f260b0c44ced5a213a76ba7050bf392f`. CT102 and CT106 use live-proof
repair commit `055966363c8bf298cda749a09b4f83126e34166d`; CT106 matches its measured
layout (`/var/www/<site>`, not `/var/www/sites`) and both container installers
skip an unnecessary service reload on idempotent password-only reruns. The host
installer uses commit `cd71c3c8a3648ede0a019c6c9da2cfc7e474a291`. The four reviewed script
hashes are:

- CT102: `4e6ed32bb3a0f22c9607345db855ec183bbbaa54ad7c430cbbeff7783515b026`
- CT106: `ca5112dcda02b156780c8193373deae6d27fe22436ed4aff557450ee049fb909`
- CT110: `d68ea3572f13f01af9c6698429ce50fe8fae84f76fb590ea65de97c2b21dbd79`
- Proxmox host: `06a855c84a77b0b994c84f48196c5ae89a6ae90d002415421cea7d1cd0d39ac6`

The host installer separately fetches the reviewed root reader from commit
`055966363c8bf298cda749a09b4f83126e34166d`, verifies SHA-256
`48b3c84357f40a3db73f679d76223472b313d5ffc5e9972f62801778aec5718c`,
then installs that exact file as `/usr/local/sbin/proxvox-readonly`. No
FileZilla or desktop control is involved.

## Sean: paste four lines

Open the Proxmox shell. For each numbered item, copy the whole single line,
press **Ctrl+Shift+V**, and press Enter. When the password prompt appears, paste
the new password and press Enter. The password is not displayed.

1. **CT102 — SFTP reader, ACLs and SSH boundary**

```sh
tmp=$(mktemp) && curl -fsSL https://raw.githubusercontent.com/baddaywithacamera/snapsmack/055966363c8bf298cda749a09b4f83126e34166d/tools/proxvox/server/provision-ct102.sh -o "$tmp" && printf '%s  %s\n' 4e6ed32bb3a0f22c9607345db855ec183bbbaa54ad7c430cbbeff7783515b026 "$tmp" | sha256sum -c - && pct push 102 "$tmp" /root/provision-proxvox.sh --perms 0700 && pct exec 102 -- /root/provision-proxvox.sh
```

2. **CT106 — SFTP reader, ACLs and SSH boundary**

```sh
tmp=$(mktemp) && curl -fsSL https://raw.githubusercontent.com/baddaywithacamera/snapsmack/055966363c8bf298cda749a09b4f83126e34166d/tools/proxvox/server/provision-ct106.sh -o "$tmp" && printf '%s  %s\n' ca5112dcda02b156780c8193373deae6d27fe22436ed4aff557450ee049fb909 "$tmp" | sha256sum -c - && pct push 106 "$tmp" /root/provision-proxvox.sh --perms 0700 && pct exec 106 -- /root/provision-proxvox.sh
```

3. **CT110 — two source-bound read-only MariaDB accounts**

```sh
tmp=$(mktemp) && curl -fsSL https://raw.githubusercontent.com/baddaywithacamera/snapsmack/8fd93087f260b0c44ced5a213a76ba7050bf392f/tools/proxvox/server/provision-ct110.sh -o "$tmp" && printf '%s  %s\n' d68ea3572f13f01af9c6698429ce50fe8fae84f76fb590ea65de97c2b21dbd79 "$tmp" | sha256sum -c - && pct push 110 "$tmp" /root/provision-proxvox.sh --perms 0700 && pct exec 110 -- /root/provision-proxvox.sh
```

4. **Proxmox host — root-owned read wrapper and SSH boundary**

```sh
tmp=$(mktemp) && curl -fsSL https://raw.githubusercontent.com/baddaywithacamera/snapsmack/cd71c3c8a3648ede0a019c6c9da2cfc7e474a291/tools/proxvox/server/provision-host.sh -o "$tmp" && printf '%s  %s\n' 06a855c84a77b0b994c84f48196c5ae89a6ae90d002415421cea7d1cd0d39ac6 "$tmp" | sha256sum -c - && bash "$tmp"
```

Each target prints `PROX VOX ... PASS` only after its account, permissions and
SSH configuration validate. If a line prints `FAILED`, stop on that machine
and send Codex the visible error. Do not improvise a repair.

## Sean: save four credentials

Open the PROX VOX setup screen from the repository checkout with:

```text
pythonw -m proxvox.setup_gui
```

Run that command with `tools/proxvox` as the working directory. The screen
writes only to Windows Credential Manager; no password belongs in this sheet,
JSON, Git or Brain Trust. Copy one password from Bitwarden, then click the
matching `PASTE & SAVE` button. The screen never displays the password and
clears the clipboard after a successful save.

1. Save the CT102 password as `proxvox:ct102_ssh`.
2. Save the CT106 password as `proxvox:ct106_ssh`.
3. Save the shared CT110 database password as `proxvox:mysql_ro`.
4. Save the host-wrapper password as `proxvox:pve_host_ssh`.
5. Close and reopen the setup screen. Confirm all four entries report present
   without displaying their values.

Sean's setup work ends there.

## Codex: run every proof

After the four credentials are present, Codex runs and records all proofs
through PROX VOX. Sean does not run these by hand.

1. CT102 and CT106 ordinary-file downloads succeed.
2. On both containers, upload, rename, delete, command execution, remote
   forwarding, agent forwarding and TTY all fail.
3. Both source-bound database readers allow harmless `SELECT`, `SHOW VIEW` and
   `SHOW GRANTS`, while `INSERT`, `UPDATE`, `DELETE`, DDL and privilege changes
   fail.
4. The host wrapper allows `inventory`, host `stat/read` and container
   `stat/read`, while an unknown verb fails.
5. The existing `/root/.ssh/id_rsa.pub` returns ordinary content.
6. `/etc/pve/priv/tfa.cfg` and `/var/lib/pve-cluster/config.db` return metadata plus SHA-256 only.
7. An ordinary file larger than 1 MiB reaches EOF in bounded one-MiB chunks
   with a matching SHA-256.
8. The off-box audit records every attempt, success, refusal, malformed reply,
   timeout and other failure with operation and path, never file contents.
9. The installed wrapper SHA-256 matches
   `48b3c84357f40a3db73f679d76223472b313d5ffc5e9972f62801778aec5718c`.

Codex posts one Brain Trust result naming the host, installed wrapper SHA-256,
proof count, PASS/FAIL and any failed proof numbers. The setup is not complete
until all proofs have an explicit result.

<!-- ===== SNAPSMACK EOF ===== -->
