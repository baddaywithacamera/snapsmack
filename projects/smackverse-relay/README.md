<!--
  SNAPSMACK_EOF_HEADER: this file MUST end with the canonical .md EOF marker.
-->

# SMACKVERSE Relay

A stripped ActivityPub **relay** for the SnapSmack network. SnapSmack blogs
subscribe; each blog's public posts fan out to every other subscriber, so every
install's home timeline fills with the whole network without following everyone
by hand. **No images are stored** — the relay fans out activity ids + text only;
photos always load from the origin blog. Additive, never a SPOF: if the relay is
down, blogs still federate directly.

This is **phase 1** (the fan-out relay). Phase 2 (network search / discovery
index) is specced in `_spec/smackverse-relay-node-spec-v1.md`.

## Requirements

- Its **own** host / Proxmox CT (do NOT co-locate on the 1 GiB snapsmack LXC).
- PHP 8.x (openssl, curl, pdo_mysql), MySQL/MariaDB, nginx or Apache, TLS for
  `smackverse.snapsmack.ca`.

## Install

1. Create the database and (optionally) load the schema — the app also
   self-loads `schema.sql` on first request:
   ```
   mysql -e "CREATE DATABASE smackverse_relay CHARACTER SET utf8mb4"
   mysql smackverse_relay < schema.sql
   ```
2. `cp config.sample.php config.php` and fill in DB creds, the public `domain`,
   and a long random `admin_token`. (`config.php` is gitignored.)
3. Point the web root at **`public/`**. The front controller (`public/index.php`)
   routes everything; `public/.htaccess` handles Apache. For nginx:
   ```
   server {
     listen 443 ssl;
     server_name smackverse.snapsmack.ca;
     root /srv/smackverse-relay/public;
     index index.php;
     location / { try_files $uri /index.php$is_args$args; }
     location ~ \.php$ { include fastcgi_params; fastcgi_pass unix:/run/php/php-fpm.sock;
                         fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; }
   }
   ```
4. First hit to `/actor` generates the relay keypair and creates tables. Verify:
   `curl https://smackverse.snapsmack.ca/actor` returns the `Application` actor.
5. Add the drain cron (also drains inline after each POST):
   ```
   * * * * * /usr/bin/php /srv/smackverse-relay/cron/drain.php >> /var/log/relay-drain.log 2>&1
   ```

## Operate

- Console: `https://smackverse.snapsmack.ca/admin.php?token=YOUR_ADMIN_TOKEN`.
- Default mode is **allowlist**: new subscribers land as *pending* until you
  approve them (or add their domain to the allowlist to auto-admit). Your own
  fleet domains: add them to the allowlist once and they join frictionlessly.
- Flip to **open** (anyone joins, you block after) from the console when the
  network is ready to go public.
- **Block** a domain to drop its inbound and exclude it from fan-out.

## How blogs join

In the CMS: **SMACKVERSE → Federation → JOIN NETWORK** (pushable fleet-wide from
the multisite hub). That sends the relay-follow (`Follow` with object = the
Public collection) to the relay. Relayed posts arrive through the inbox each
blog already runs.

## Endpoints

`/actor` · `/.well-known/webfinger` · `/.well-known/nodeinfo` · `/nodeinfo/2.0` ·
`/inbox` (POST, signed) · `/followers` `/following` `/outbox` (collections) ·
`/admin.php` (operator).

<!-- ===== SNAPSMACK EOF ===== -->
