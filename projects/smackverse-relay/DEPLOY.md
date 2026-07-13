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

- **Keypair** generates on first `/actor` hit and is stored in `relay_settings`.
  Back up that row (or the whole DB) once live — losing it means re-subscribing
  everyone.
- The relay stores **no images** — only activity ids + text fan out; photos always
  load from the origin blog. Storage/bandwidth stay tiny.
- Additive, never a SPOF: if this CT is down, blogs still federate directly. Safe to
  reboot/patch anytime.
- If a join stays *pending* under ALLOWLIST, the domain isn't allowlisted — add it,
  or hit APPROVE.
- Moderation: BLOCK a domain to drop its inbound and pull it from fan-out.

<!-- ===== SNAPSMACK EOF ===== -->
