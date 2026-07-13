<!--
  SNAPSMACK_EOF_HEADER: this file MUST end with the canonical .md EOF marker.
-->

# SMACKVERSE Relay — deploy runbook (Proxmox CT)

Copy-paste standup for `smackverse.snapsmack.ca`. The relay code is complete;
this is the only remaining step. Give it its **own** CT — do NOT co-locate on the
1 GiB snapsmack LXC (it OOMs on packaging). Low traffic by design (no images), so
a small CT is plenty.

Everything below is a fresh Debian 12 CT. Adjust IDs/IPs/paths to your basement
Proxmox. `~15 min` end to end.

> **Two paths.** If you're standing up a brand-new relay, start at §1. If the relay
> already exists on **CT106** (it does, as of 2026-07-13) and you're re-homing it to
> **photoblogs.fyi** with the DB on **CT110**, do **§0 first** — sections 1–7 are then
> reference for the pieces §0 points back to.

---

## 0. RE-HOME MIGRATION → photoblogs.fyi (existing CT106 relay)

**State (2026-07-13):** relay app runs on **CT106 = 192.168.1.16** against a *local*
MariaDB (db `smack`). **Target:** DB consolidated onto **CT110 = 192.168.1.20** as
`smackverse_relay`; public identity re-homed to **photoblogs.fyi**; private key
**encrypted at rest**. **Zero subscribers today, so the identity + key changes are
FREE now** — do the disruptive ones first; the pure-infra DB move can trail.

Backup BEFORE go-live. Dump already pulled: `C:\Users\neutr\Documents\smack_2026-07-13.sql`
(plain `mysqldump smack`, loads into any db name). Keys are in Bitwarden.

### 0a. Encrypt the key — do FIRST, while 0 followers  ⟵ code shipped, needs the KEK
`lib/ap.php` now stores the relay private key AES-256-CBC encrypted (`enc:v1:`
envelope, same as the CMS `core/secret-store.php`) and decrypts in-memory only at
sign time. **It is inert until a KEK exists in the live `config.php`.** On **CT106**:

```bash
php -r 'echo bin2hex(random_bytes(32)), "\n";'   # generate the KEK ON the box
nano /srv/smackverse-relay/config.php            # add:  'secret_kek' => '<that value>',
```

Save that KEK to Bitwarden next to `db_pass`. With it set, a **fresh** key is born
encrypted; a **legacy plaintext** key already in `relay_settings` is transparently
re-encrypted in place the next time the relay signs. Losing the KEK = the stored key
can't be decrypted and the relay mints a fresh one (fine at 0 followers, strands them
later — hence: back it up).

### 0b. Re-home to photoblogs.fyi — free while 0 followers
`config.php` on CT106: `domain → photoblogs.fyi`. Route the hostname into CT106 the
SAME way it's served today — via the Cloudflare **"snapsmack"** tunnel (add the
hostname to the tunnel, or A-record — your ingress). New actor id
`https://photoblogs.fyi/actor`; the fresh **encrypted** keypair mints on the first
`/actor` hit. Retire the `smackverse.snapsmack.ca` vhost (301 optional). Update the
CMS default relay URL.

### 0c. Verify identity
```bash
curl -s https://photoblogs.fyi/actor | head -c 400 ; echo    # Application actor + publicKey
curl -s 'https://photoblogs.fyi/.well-known/webfinger?resource=acct:relay@photoblogs.fyi'
```

### 0d. Move the DB CT106 → CT110 — pure infra, can trail (costs the same anytime)
On **CT110 (192.168.1.20)**: `mysql -e "SHOW DATABASES;"` — `smack` collides with the
CMS DB, so use the clean name:
```bash
mysql -e "CREATE DATABASE smackverse_relay CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER 'relay'@'192.168.1.16' IDENTIFIED BY 'PICK_A_STRONG_PASS'; \
          GRANT ALL ON smackverse_relay.* TO 'relay'@'192.168.1.16'; FLUSH PRIVILEGES;"
mysql smackverse_relay < smack_2026-07-13.sql      # dump loads into any name
# confirm MariaDB binds on the LAN (bind-address), not just 127.0.0.1
```
Then repoint CT106 `config.php`: `db_host → 192.168.1.20`, `db_name → smackverse_relay`,
`db_user → relay`, new pass. Drop `mariadb-server` from CT106 (app-only after).

### 0e. Wire the backup
Add `smackverse_relay` to `DATABASES=()` in `/usr/local/bin/mariadb_backup.sh`
(b2 CLI → OptiplexSQL). Run it once; confirm it lands in B2.

### 0f. Go live
Allowlist the fleet in `admin.php`, JOIN from one blog, confirm fan-out (§8).

### 0g. Security debt from the key-pull night
CT106 SSH was left open (`PermitRootLogin yes` + password) to pull the key. Re-lock:
```bash
sed -i 's/^PermitRootLogin yes/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
sshd -t && systemctl restart ssh
```
Clear the root password from the FileZilla site's Comments box.

---

## 1. Create the CT (run on the Proxmox host)

```bash
# 107 = next free CTID; pick your storage + bridge. 1GB RAM / 1 core / 8GB disk.
pct create 107 local:vztmpl/debian-12-standard_12.7-1_amd64.tar.zst \
  --hostname smackverse-relay --cores 1 --memory 1024 --swap 512 \
  --rootfs local-lvm:8 --net0 name=eth0,bridge=vmbr0,ip=dhcp \
  --features nesting=1 --unprivileged 1 --onboot 1
pct start 107
pct exec 107 -- bash -c 'apt-get update && apt-get -y upgrade'
pct enter 107     # drop into the CT for the rest
```

## 2. Packages (inside the CT)

```bash
apt-get -y install nginx mariadb-server certbot python3-certbot-nginx \
  php-fpm php-cli php-mysql php-curl php-xml php-mbstring git
# note the php-fpm socket name (version-dependent), e.g. /run/php/php8.2-fpm.sock:
ls /run/php/
```

## 3. Database

```bash
mysql <<'SQL'
CREATE DATABASE smackverse_relay CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'relay'@'127.0.0.1' IDENTIFIED BY 'PICK_A_STRONG_DB_PASSWORD';
GRANT ALL PRIVILEGES ON smackverse_relay.* TO 'relay'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
```

## 4. Code + config

```bash
mkdir -p /srv
git clone <YOUR_SNAPSMACK_REMOTE> /tmp/ss && \
  cp -r /tmp/ss/projects/smackverse-relay /srv/smackverse-relay
#   (or rsync just projects/smackverse-relay/ up — it is self-contained)

cd /srv/smackverse-relay
cp config.sample.php config.php
# Generate a long admin token:
php -r 'echo bin2hex(random_bytes(24)), "\n";'
nano config.php     # set db_pass (from step 3), admin_token (above), domain stays smackverse.snapsmack.ca

# Optional: pre-load schema (the app self-loads it on first request too)
mysql smackverse_relay < schema.sql

chown -R www-data:www-data /srv/smackverse-relay
```

## 5. nginx + TLS

```bash
cat >/etc/nginx/sites-available/relay <<'NGINX'
server {
    listen 80;
    server_name smackverse.snapsmack.ca;
    root /srv/smackverse-relay/public;
    index index.php;
    location / { try_files $uri /index.php$is_args$args; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;   # <-- match step 2
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
NGINX
ln -sf /etc/nginx/sites-available/relay /etc/nginx/sites-enabled/relay
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

DNS first: point `smackverse.snapsmack.ca` A record at this CT's public IP (or
your reverse-proxy / port-forward), then:

```bash
certbot --nginx -d smackverse.snapsmack.ca --redirect -m you@snapsmack.ca --agree-tos -n
```

## 6. Drain cron

```bash
( crontab -l 2>/dev/null; echo '* * * * * /usr/bin/php /srv/smackverse-relay/cron/drain.php >> /var/log/relay-drain.log 2>&1' ) | crontab -
```

## 7. Verify

```bash
curl -s https://smackverse.snapsmack.ca/actor | head -c 400 ; echo      # Application actor + publicKey
curl -s https://smackverse.snapsmack.ca/nodeinfo/2.0 | head -c 200 ; echo
curl -s 'https://smackverse.snapsmack.ca/.well-known/webfinger?resource=acct:relay@smackverse.snapsmack.ca'
```

Open the operator console:
`https://smackverse.snapsmack.ca/admin.php?token=YOUR_ADMIN_TOKEN`

## 8. Admit your fleet, then go live

1. In the console, add your own blog domains to the **allowlist** (foreverphotograph.ing,
   photowalk.ing, wateronthebrain.ca, hekeepsdroningon.ca, badday…) so they auto-admit.
2. On one blog: **SMACKVERSE → Federation → JOIN NETWORK**. Within seconds it should
   move from *pending* to *active* in the console (allowlisted) and get an Accept.
3. Post a public photo on that blog; confirm the fan-out lands in another joined
   blog's reader. Check `admin.php` → *Recent inbound* for `fanned to N`.
4. When the network is ready for outsiders, flip mode to **OPEN** (block bad actors
   after the fact) — or keep ALLOWLIST and approve each site by hand.

## Notes / gotchas

- **Keypair** generates on first `/actor` hit and is stored in `relay_settings`,
  **encrypted at rest** when `secret_kek` is set in `config.php` (see §0a) — the
  private key never sits in the DB in the clear. Back up the KEK *and* that row (or
  the whole DB) once live: losing the row means re-subscribing everyone; losing the
  KEK means the relay can't read its own key and mints a fresh one.
- The relay stores **no images** — only activity ids + text fan out; photos always
  load from the origin blog. Storage/bandwidth stay tiny.
- Additive, never a SPOF: if this CT is down, blogs still federate directly. Safe to
  reboot/patch anytime.
- If a join stays *pending* under ALLOWLIST, the domain isn't allowlisted — add it,
  or hit APPROVE.
- Moderation: BLOCK a domain to drop its inbound and pull it from fan-out.

<!-- ===== SNAPSMACK EOF ===== -->
