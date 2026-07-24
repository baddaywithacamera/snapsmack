<!-- SNAPSMACK_EOF_HEADER: last non-empty line MUST be the EOF marker comment at the bottom. -->

# PHOTOFRI.DAY — deploy (step 1: the @participate signup actor)

Standalone ActivityPub service on its own Proxmox CT + TLS host `photofri.day`.
Whole-fediverse Photo Friday. **FOLLOW `@participate@photofri.day` = JOIN** (auto-Accept
+ follow-back); **unfollow = LEAVE** (participant deleted, our follow-back Undone). The
participant list is the population for the Starter Kit + the SnapSmack global feed (next steps).

## Layout
- `public/` = **docroot** (front controller `index.php` + `.htaccess`). Put the landing
  `index.html` + `img/` INSIDE `public/` so `/` serves the page and its assets.
- `lib/` = AP primitives (`db.php`, `ap.php` — reused from the SMACKVERSE relay, crypto
  verbatim) + `participants.php` (join/leave/follow-back inbox).
- `schema.sql`, `config.sample.php`, `cron/drain.php`.

## Steps
1. DNS `photofri.day` → the CT; TLS (Let's Encrypt). Apache docroot → `.../public/`, `AllowOverride All`, `a2enmod rewrite`.
2. `cp config.sample.php config.php` and fill: `db_*`, `admin_token`, and **`secret_kek` BEFORE the first `/actor` hit** (`php -r "echo bin2hex(random_bytes(32));"`) so the minted keypair is encrypted from birth. Losing `secret_kek` strands every follower.
3. DB: `CREATE DATABASE photofri_day; CREATE USER 'photofri'@'127.0.0.1' …; GRANT …;` then `mysql photofri_day < schema.sql` (the app also self-heals the schema on first hit).
4. Cron: `* * * * * php /var/www/photofri.day/cron/drain.php >/dev/null 2>&1` (delivery queue; the inbox also drains inline).
5. Back up the DB (keypair + participant list) + `config.php` (db_pass + secret_kek).

## Test
- `curl https://photofri.day/.well-known/webfinger?resource=acct:participate@photofri.day` → JRD pointing at `/actor`.
- `curl -H 'Accept: application/activity+json' https://photofri.day/actor` → Application actor, `manuallyApprovesFollowers:false`.
- From any fediverse account, follow `@participate@photofri.day` → you should get an Accept, a follow-back, and a row in `pfd_participants`. Unfollow → row gone.
- Watch `pfd_inbox_log` for verb/actor/outcome.

## Next (not built)
Weekly prompt post; `#photofri` board ingest (teaser-only, hotlink origin preview, canonical→origin, rolling 5/author/week); Starter Kit feed (photoblogs.fyi pulls it); global-feed registration; contest.
<!-- ===== SNAPSMACK EOF ===== -->
