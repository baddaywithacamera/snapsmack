#!/usr/bin/env bash
#
# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
#
# pfd-migrate-ct.sh — move photofri.day from one Proxmox CT to another.
#
# RUN ON THE PROXMOX HOST, AS ROOT. Not inside a container.
#
#   ./pfd-migrate-ct.sh                       # migrate SRC -> DST, verify-then-stop
#   ./pfd-migrate-ct.sh --dry-run             # show what it would do, touch nothing
#   ./pfd-migrate-ct.sh --force               # allow overwriting an existing DST dir
#   ./pfd-migrate-ct.sh --decommission-source # ONLY after you've verified DST live
#
# What it DOES:  files, config.php (secret_kek!), database, vhost, packages, cron.
# What it does NOT do, on purpose: DNS, Cloudflare, TLS, stopping the source.
#   You flip traffic by hand once the verification block passes.
#
# Why it never touches the source until you say so: until DNS moves, the SOURCE
# is still the live actor. And after DNS moves, if the source's cron is still
# running you have TWO hosts draining queues as @participate@photofri.day —
# split brain, signed by the same key. --decommission-source closes that.
#
set -euo pipefail

### ─── CONFIG — the only lines you should need to touch ────────────────────
SRC_CT=102
DST_CT=106
SITE=photofri.day
SRC_DIR=/var/www/photofri.day
DST_DIR=/var/www/photofri.day
WORK=/tmp/pfd-migrate
### ────────────────────────────────────────────────────────────────────────

DRY=0; FORCE=0; DECOM=0
for a in "$@"; do
  case "$a" in
    --dry-run)             DRY=1 ;;
    --force)               FORCE=1 ;;
    --decommission-source) DECOM=1 ;;
    *) echo "unknown arg: $a" >&2; exit 2 ;;
  esac
done

c_ok(){   printf '\033[32m  ok\033[0m %s\n' "$*"; }
c_step(){ printf '\n\033[1;36m==>\033[0m \033[1m%s\033[0m\n' "$*"; }
c_warn(){ printf '\033[33m  !!\033[0m %s\n' "$*"; }
die(){    printf '\033[31mFATAL:\033[0m %s\n' "$*" >&2; exit 1; }
run(){ if [ "$DRY" = 1 ]; then printf '   [dry] %s\n' "$*"; else eval "$@"; fi; }

# exec a local script file inside a CT (avoids all pct-exec quoting hell)
in_ct(){ local ct="$1" f="$2" base; base=$(basename "$f")
  run "pct push $ct '$f' /tmp/$base"
  run "pct exec $ct -- bash /tmp/$base"
}

[ "$(id -u)" = 0 ] || die "run as root on the Proxmox host"
command -v pct >/dev/null || die "pct not found — are you on the PVE host?"

# ══════════════════════════════════════════════════════════════════════════
# DECOMMISSION MODE — separate, deliberate, run only after verification
# ══════════════════════════════════════════════════════════════════════════
if [ "$DECOM" = 1 ]; then
  c_step "Decommissioning source CT $SRC_CT (cron + vhost only — no deletion)"
  c_warn "Only do this AFTER $SITE resolves to CT $DST_CT and verifies clean."
  read -r -p "  Type YES to disable the source: " ans
  [ "$ans" = "YES" ] || die "aborted"
  mkdir -p "$WORK"
  cat > "$WORK/decom.sh" <<'DECOMEOF'
set -e
crontab -l 2>/dev/null | grep -v 'photofri.day/cron/drain.php' | crontab - || true
echo "cron drain entry removed"
a2dissite "__SITE__.conf" 2>/dev/null || true
systemctl reload apache2 2>/dev/null || true
echo "vhost disabled"
echo "NOTE: files and database left INTACT as a rollback path."
DECOMEOF
  sed -i "s|__SITE__|$SITE|g" "$WORK/decom.sh"
  in_ct "$SRC_CT" "$WORK/decom.sh"
  c_ok "source disabled; files + DB retained for rollback"
  exit 0
fi

# ══════════════════════════════════════════════════════════════════════════
# 0. PREFLIGHT
# ══════════════════════════════════════════════════════════════════════════
c_step "Preflight"
pct config "$SRC_CT" >/dev/null 2>&1 || die "CT $SRC_CT does not exist"
pct config "$DST_CT" >/dev/null 2>&1 || die "CT $DST_CT does not exist"
[ "$(pct status "$SRC_CT")" = "status: running" ] || die "CT $SRC_CT is not running"
if [ "$(pct status "$DST_CT")" != "status: running" ]; then
  c_warn "CT $DST_CT not running — starting it"
  run "pct start $DST_CT"; sleep 5
fi
pct exec "$SRC_CT" -- test -d "$SRC_DIR" || die "$SRC_DIR not found in CT $SRC_CT"
if pct exec "$DST_CT" -- test -e "$DST_DIR" 2>/dev/null; then
  [ "$FORCE" = 1 ] || die "$DST_DIR already exists in CT $DST_CT — re-run with --force"
  c_warn "$DST_DIR exists in CT $DST_CT and will be overwritten (--force)"
fi
c_ok "CT $SRC_CT -> CT $DST_CT, site $SITE"
mkdir -p "$WORK"

# ══════════════════════════════════════════════════════════════════════════
# 1. PULL FILES + CONFIG
# ══════════════════════════════════════════════════════════════════════════
c_step "Pulling site directory from CT $SRC_CT"
PARENT=$(dirname "$SRC_DIR"); LEAF=$(basename "$SRC_DIR")
run "pct exec $SRC_CT -- tar czf /tmp/pfd-site.tgz -C '$PARENT' '$LEAF'"
run "pct pull $SRC_CT /tmp/pfd-site.tgz '$WORK/pfd-site.tgz'"
[ "$DRY" = 1 ] || c_ok "$(du -h "$WORK/pfd-site.tgz" | cut -f1) archive"

c_step "Locating config.php (secret_kek lives here — this is the irreplaceable bit)"
if pct exec "$SRC_CT" -- test -f "$SRC_DIR/config.php"; then
  run "pct pull $SRC_CT '$SRC_DIR/config.php' '$WORK/config.php'"
  c_ok "config.php captured"
else
  die "config.php NOT found at $SRC_DIR/config.php — find it before continuing.
       Without secret_kek the actor keypair cannot be decrypted and every
       follower is stranded (DEPLOY.md step 2)."
fi

# Tolerant creds parse — handles  'db_name'=>'x'  /  \$db_name='x'  /  define('DB_NAME','x')
getcfg(){ grep -oiE "${1}['\"]?[[:space:]]*(=>|=|,)[[:space:]]*['\"][^'\"]+" "$WORK/config.php" \
          | head -1 | sed -E "s/.*['\"]([^'\"]+)$/\1/"; }
if [ "$DRY" = 1 ]; then DB_NAME=photofri_day; DB_USER=photofri; DB_PASS='(dry-run)'
else
  DB_NAME=$(getcfg db_name || true); DB_USER=$(getcfg db_user || true); DB_PASS=$(getcfg db_pass || true)
  [ -n "$DB_NAME" ] || read -r -p "  db_name not auto-detected, enter it: " DB_NAME
  [ -n "$DB_USER" ] || read -r -p "  db_user not auto-detected, enter it: " DB_USER
  [ -n "$DB_PASS" ] || read -r -s -p "  db_pass not auto-detected, enter it: " DB_PASS && echo
fi
c_ok "db=$DB_NAME user=$DB_USER"

# ══════════════════════════════════════════════════════════════════════════
# 2. DUMP DATABASE (keypair + participants)
# ══════════════════════════════════════════════════════════════════════════
c_step "Dumping database $DB_NAME"
run "pct exec $SRC_CT -- mysqldump --single-transaction --routines --events '$DB_NAME' > /tmp/pfd-db.sql"
run "pct pull $SRC_CT /tmp/pfd-db.sql '$WORK/pfd-db.sql'"
if [ "$DRY" != 1 ]; then
  c_ok "$(du -h "$WORK/pfd-db.sql" | cut -f1) dump"
  if grep -qiE 'privkey|private_key|actor_key' "$WORK/pfd-db.sql"; then
    c_warn "Actor keypair IS present — /actor has been hit. Identity is real."
    c_warn "Carrying config.php + this dump verbatim is mandatory, not optional."
  else
    c_ok "no keypair yet — actor has never been minted; low-risk move"
  fi
fi

# ══════════════════════════════════════════════════════════════════════════
# 3. PREP DESTINATION
# ══════════════════════════════════════════════════════════════════════════
c_step "Installing packages in CT $DST_CT"
cat > "$WORK/pkgs.sh" <<'PKGEOF'
set -e
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq apache2 mariadb-server \
  php php-cli php-mysql php-curl php-sodium php-mbstring php-xml libapache2-mod-php
a2enmod rewrite >/dev/null
php -m | grep -qi '^sodium$' || { echo "FATAL: sodium missing after install"; exit 1; }
echo "packages ok; sodium present"
PKGEOF
in_ct "$DST_CT" "$WORK/pkgs.sh"

c_step "Restoring files into CT $DST_CT"
run "pct push $DST_CT '$WORK/pfd-site.tgz' /tmp/pfd-site.tgz"
cat > "$WORK/unpack.sh" <<'UNPEOF'
set -e
mkdir -p "__PARENT__"
rm -rf "__DSTDIR__"
tar xzf /tmp/pfd-site.tgz -C "__PARENT__"
[ -d "__DSTDIR__" ] || mv "__PARENT__/__LEAF__" "__DSTDIR__" 2>/dev/null || true
chown -R www-data:www-data "__DSTDIR__"
find "__DSTDIR__" -type d -exec chmod 755 {} \;
find "__DSTDIR__" -type f -exec chmod 644 {} \;
chmod 640 "__DSTDIR__/config.php" 2>/dev/null || true
echo "files in place, owned by www-data"
UNPEOF
sed -i "s|__PARENT__|$(dirname "$DST_DIR")|g; s|__DSTDIR__|$DST_DIR|g; s|__LEAF__|$LEAF|g" "$WORK/unpack.sh"
in_ct "$DST_CT" "$WORK/unpack.sh"

c_step "Restoring config.php"
run "pct push $DST_CT '$WORK/config.php' '$DST_DIR/config.php'"
run "pct exec $DST_CT -- chown www-data:www-data '$DST_DIR/config.php'"
run "pct exec $DST_CT -- chmod 640 '$DST_DIR/config.php'"
c_ok "secret_kek carried across intact"

# ══════════════════════════════════════════════════════════════════════════
# 4. DATABASE ON DESTINATION
# ══════════════════════════════════════════════════════════════════════════
c_step "Creating database + grant in CT $DST_CT"
# Creds are passed as a sourced env file, NOT sed-substituted — a password
# containing | & / ' would corrupt a sed replacement. SQL literals get
# backslash + single-quote escaped separately.
if [ "$DRY" != 1 ]; then
  DB_PASS_SQL=${DB_PASS//\\/\\\\}; DB_PASS_SQL=${DB_PASS_SQL//\'/\\\'}
  printf 'DB_NAME=%q\nDB_USER=%q\nDB_PASS_SQL=%q\n' \
    "$DB_NAME" "$DB_USER" "$DB_PASS_SQL" > "$WORK/dbcreds.env"
  chmod 600 "$WORK/dbcreds.env"
fi
cat > "$WORK/db.sh" <<'DBEOF'
set -e
. /tmp/dbcreds.env
rm -f /tmp/dbcreds.env
systemctl enable --now mariadb >/dev/null 2>&1 || true
mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
for h in 127.0.0.1 localhost; do
  mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'${h}' IDENTIFIED BY '${DB_PASS_SQL}';"
  mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'${h}';"
done
mysql -e "FLUSH PRIVILEGES;"
mysql "${DB_NAME}" < /tmp/pfd-db.sql
echo "tables restored:"; mysql -N -B -e "SHOW TABLES;" "${DB_NAME}" | sed 's/^/    /'
DBEOF
run "pct push $DST_CT '$WORK/pfd-db.sql' /tmp/pfd-db.sql"
[ "$DRY" = 1 ] || run "pct push $DST_CT '$WORK/dbcreds.env' /tmp/dbcreds.env"
in_ct "$DST_CT" "$WORK/db.sh"
run "rm -f '$WORK/dbcreds.env'"

# ══════════════════════════════════════════════════════════════════════════
# 5. VHOST + CRON
# ══════════════════════════════════════════════════════════════════════════
c_step "Writing vhost (port 80 — TLS after you flip traffic)"
cat > "$WORK/vhost.sh" <<'VHEOF'
set -e
cat > "/etc/apache2/sites-available/__SITE__.conf" <<'CONF'
<VirtualHost *:80>
    ServerName __SITE__
    ServerAlias www.__SITE__
    DocumentRoot __DSTDIR__/public

    <Directory __DSTDIR__/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog  ${APACHE_LOG_DIR}/__SITE__-error.log
    CustomLog ${APACHE_LOG_DIR}/__SITE__-access.log combined
</VirtualHost>
CONF
a2ensite "__SITE__.conf" >/dev/null
a2dissite 000-default.conf >/dev/null 2>&1 || true
apache2ctl configtest
systemctl reload apache2
echo "vhost live, docroot __DSTDIR__/public"
VHEOF
sed -i "s|__SITE__|$SITE|g; s|__DSTDIR__|$DST_DIR|g" "$WORK/vhost.sh"
in_ct "$DST_CT" "$WORK/vhost.sh"

c_step "Installing drain cron in CT $DST_CT"
cat > "$WORK/cron.sh" <<'CRONEOF'
set -e
LINE="* * * * * php __DSTDIR__/cron/drain.php >/dev/null 2>&1"
( crontab -l 2>/dev/null | grep -v 'photofri.day/cron/drain.php' || true; echo "$LINE" ) | crontab -
echo "cron installed:"; crontab -l | grep drain.php | sed 's/^/    /'
CRONEOF
sed -i "s|__DSTDIR__|$DST_DIR|g" "$WORK/cron.sh"
in_ct "$DST_CT" "$WORK/cron.sh"

# ══════════════════════════════════════════════════════════════════════════
# 6. VERIFY — before any DNS/Cloudflare change
# ══════════════════════════════════════════════════════════════════════════
c_step "Destination ready — verify BEFORE touching DNS"
DST_IP=$(pct exec "$DST_CT" -- hostname -I 2>/dev/null | awk '{print $1}' || echo "<dst-ip>")
cat <<VERIFY

  CT $DST_CT is serving $SITE on $DST_IP (port 80, no TLS yet).
  Nothing public has changed. CT $SRC_CT is untouched and still live.

  Run these from anywhere that can reach $DST_IP:

    curl -sI -H 'Host: $SITE' http://$DST_IP/ | head -1
    curl -s  -H 'Host: $SITE' 'http://$DST_IP/.well-known/webfinger?resource=acct:participate@$SITE'
    curl -s  -H 'Host: $SITE' -H 'Accept: application/activity+json' http://$DST_IP/actor

  Expect: 200 on the landing page; a JRD pointing at /actor; an Application
  actor with manuallyApprovesFollowers:false. If the actor's publicKey differs
  from what CT $SRC_CT serves, STOP — the keypair did not carry.

  Then, in order:
    1. TLS on CT $DST_CT  (DNS-01 if Cloudflare is proxied — HTTP-01 can't
       validate until traffic already points here)
    2. Repoint traffic. If Cloudflare resolves to your public IP and a
       port-forward/reverse-proxy sends 443 onward, change THAT, not Cloudflare.
       Only touch the A record if it points straight at the CT.
    3. Purge Cloudflare cache
    4. Re-run the three checks against https://$SITE from outside
    5. Only then:  $0 --decommission-source

VERIFY
c_warn "Do NOT skip step 5 — two hosts draining as @participate@$SITE is split brain."

# ===== SNAPSMACK EOF =====
