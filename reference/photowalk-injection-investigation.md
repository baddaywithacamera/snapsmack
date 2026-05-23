# photowalk.ing — Script Injection Investigation
**Date:** 2026-05-12  
**Investigated by:** Claude (Cowork) + prior analysis by Claude Web

---

## The Problem

Every page on photowalk.ing contains an injected script tag that does not belong there:

```html
<script type='text/javascript' src='https://photowalk.ing/[long-random-token]'></script>
```

- The token changes on every page load (fingerprinting behaviour)
- The injection survives replacing PHP files via FTP
- The injection is not present in any PHP source file

---

## What Was Ruled Out

### Server-side (prior investigation)
- PHP source files: clean, no injected code
- Database: clean
- Apache access/error logs: no evidence of exploit

### Cloudflare (this session — full dashboard audit)

Every relevant Cloudflare feature on the photowalk.ing zone was checked:

| Feature | Finding |
|---|---|
| Zaraz (Web Tag Management) | No tools configured — empty state |
| Workers Routes | Zero routes configured for photowalk.ing |
| Transform Rules | No custom rules (redirected to empty Overview) |
| Redirect Rules | No custom rules |
| Cache Rules | No custom rules |
| Rocket Loader | **Enabled** — but injects from `ajax.cloudflare.com`, not photowalk.ing |
| Cloudflare Fonts | Enabled — modifies font URLs only, no script injection |
| Speed Brain | Enabled — uses Speculation Rules API, not arbitrary JS |
| Web Analytics (RUM) | Set to `enable_lite` — injects beacon from `static.cloudflareinsights.com`, not photowalk.ing |
| Web Analytics Advanced Options | No first-party domain / custom subdomain configured |
| Page Rules | Not checked (legacy) — no evidence of use |

### Cloudflare Account Audit Log (last 7 days)
- **Every action is from `sean@baddaywithacamera.ca`**
- No unauthorized actors, no unknown email addresses
- No suspicious rule/Worker/Transform creations
- Zone setting changes on May 10 were `development_mode` toggles — benign
- **Cloudflare account is NOT compromised**

---

## Conclusion

**The injection is originating from the origin server (photowalk.ing Proxmox CT), not from Cloudflare.**

The `https://photowalk.ing/[changing-token]` pattern — served from the site's own domain with a per-request unique token — is not produced by any Cloudflare feature. It is consistent with a server-side PHP payload or web server config that:
1. Intercepts HTML responses
2. Appends a script tag with a dynamically generated token (likely for tracking/exfiltration)

The injection survives FTP file replacement because **it is not in a `.php` file** — it is in a config layer that PHP or Apache reads separately.

---

## Most Likely Cause

### `.user.ini` with `auto_append_file` (highest probability)

PHP-FPM scans for `.user.ini` in the document root every 300 seconds. A malicious `.user.ini` could contain:

```ini
auto_append_file = /var/www/photowalk.ing/.inject.php
```

Where `.inject.php` generates the script tag. This file:
- Is a dot file (hidden from normal `ls`)
- Is NOT a `.php` file, so replacing PHP files has no effect
- Survives every FTP session
- Would not appear in PHP file searches

---

## Commands to Run on the photowalk.ing CT

SSH into the container and run these:

```bash
# 1. Find .user.ini anywhere in the web root
find /var/www/photowalk.ing -name ".user.ini" 2>/dev/null
find /var/www -name ".user.ini" 2>/dev/null

# 2. Find ALL dot files in the web root (hidden files)
find /var/www/photowalk.ing -name ".*" -type f 2>/dev/null

# 3. Check PHP-FPM pool configs for auto_append/prepend
grep -rn "auto_append\|auto_prepend" /etc/php/ 2>/dev/null

# 4. Check Apache VirtualHost for mod_substitute (server-level injection)
grep -rni "substitute\|append\|prepend\|inject" /etc/apache2/sites-enabled/ 2>/dev/null

# 5. Find recently modified files not from your FTP session
#    (adjust the reference file to one you FTPd last)
find /var/www/photowalk.ing -name "*.php" -newer /var/www/photowalk.ing/index.php -ls 2>/dev/null

# 6. Check ALL recently modified files including non-PHP
find /var/www/photowalk.ing -newer /var/www/photowalk.ing/index.php -ls 2>/dev/null

# 7. Check cron jobs — a cron could be re-injecting after each FTP session
crontab -l 2>/dev/null
cat /etc/cron.d/* 2>/dev/null
ls -la /etc/cron.hourly/ /etc/cron.daily/ /etc/cron.weekly/ 2>/dev/null

# 8. Check if mod_substitute is loaded in Apache
apache2ctl -M 2>/dev/null | grep -i subst

# 9. Check for suspicious loaded PHP extensions
php -m 2>/dev/null | sort
```

---

## If `.user.ini` Is Found

1. `cat` the file to confirm the `auto_append_file` setting
2. Note what file it points to and `cat` that file too
3. Delete the `.user.ini`: `rm /var/www/photowalk.ing/.user.ini`
4. Delete the injected file it pointed to
5. Restart PHP-FPM: `systemctl restart php*-fpm`
6. Purge Cloudflare cache for photowalk.ing
7. Check all other sites on this Proxmox host for the same `.user.ini`

---

## If `.user.ini` Is Not Found

Check Apache mod_substitute config — a `SubstituteOutputLine` rule in the VirtualHost would inject into responses at the Apache layer and also survive FTP. Look in:
- `/etc/apache2/sites-enabled/*.conf`
- `/etc/apache2/conf-enabled/*.conf`
- `/etc/apache2/apache2.conf`

---

## Other Sites to Check

Once photowalk.ing is clean, run the same `.user.ini` scan on:
- `foundtextures.ca` CT
- `wateronthebrain.ca` CT
- `hekeepsdroningon.ca` CT
- `strathmore.pics` CT

If one CT was compromised they may all be — especially if they share a Proxmox host or were set up from the same template.

<!-- ===== SNAPSMACK EOF ===== -->
