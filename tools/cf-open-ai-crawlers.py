# SNAPSMACK_EOF_HEADER
# Last non-empty line of this file MUST be: # ===== SNAPSMACK EOF =====
# Missing or different = truncated/corrupted. Restore before saving.
#
# cf-open-ai-crawlers.py
# -----------------------------------------------------------------------------
# One-shot, idempotent Cloudflare fleet fixer for Sean's account. For EVERY zone
# it:
#   1. ALLOWS AI crawlers  — bot_management: ai_bots_protection=disabled,
#      crawler_protection=disabled, is_robots_txt_managed=false. This removes the
#      Cloudflare-managed robots.txt block (ai-train=no + ClaudeBot/GPTBot/CCBot
#      Disallow) AND the edge WAF rule that 403s AI training crawlers. Search +
#      Agent + Training crawlers can reach the images. (Sean's ethic: return to
#      the commons what you drew from it.)
#   2. STOPS CACHING ANYTHING CRITICAL — adds a Cache Rule that BYPASSES cache
#      for every dynamic/crawler-facing path (all *.php, /robots.txt,
#      /sitemap.xml, /.well-known/*) so a control file can never go stale again.
#      Images/CSS/JS stay cached — you keep the CDN speed + bandwidth savings.
#   3. PURGES the currently-cached /robots.txt and /sitemap.xml so the fix shows
#      immediately instead of waiting on TTL.
#
# Re-runnable safely: it checks before adding the cache rule and only sets what
# needs setting.
#
# ---- HOW TO RUN (git-bash) --------------------------------------------------
#   1. Create an API token at https://dash.cloudflare.com/profile/api-tokens
#      -> Create Token -> Custom token, with these ZONE permissions (All zones):
#         - Zone            : Read
#         - Bot Management   : Edit
#         - Cache Rules      : Edit
#         - Cache Purge      : Purge
#   2. export CF_API_TOKEN="paste-the-token-here"
#   3. python tools/cf-open-ai-crawlers.py
#      (add  --dry-run  first if you want to see what it WOULD do, changing
#       nothing.)
# -----------------------------------------------------------------------------

import json
import os
import sys
import urllib.request
import urllib.error

API = "https://api.cloudflare.com/client/v4"
TOKEN = os.environ.get("CF_API_TOKEN", "").strip()
DRY_RUN = "--dry-run" in sys.argv

# Bypass cache for anything that must never be served stale. Images/CSS/JS are
# NOT matched here, so they keep caching normally.
CACHE_EXPR = (
    '(ends_with(http.request.uri.path, ".php")) or '
    '(http.request.uri.path eq "/robots.txt") or '
    '(http.request.uri.path eq "/sitemap.xml") or '
    '(starts_with(http.request.uri.path, "/.well-known/"))'
)
RULE_DESC = "SnapSmack: bypass cache for critical paths (php/robots.txt/sitemap.xml/.well-known)"


def api(method, path, body=None):
    """Minimal Cloudflare API call. Returns parsed JSON (success or error body)."""
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(
        API + path, data=data, method=method,
        headers={"Authorization": "Bearer " + TOKEN, "Content-Type": "application/json"},
    )
    try:
        with urllib.request.urlopen(req) as r:
            return json.load(r)
    except urllib.error.HTTPError as e:
        try:
            return json.load(e)
        except Exception:
            return {"success": False, "errors": [{"message": "HTTP %s" % e.code}]}
    except Exception as e:
        return {"success": False, "errors": [{"message": str(e)}]}


def errmsg(resp):
    return "; ".join(x.get("message", str(x)) for x in resp.get("errors", [])) or "unknown error"


def list_zones():
    zones, page = [], 1
    while True:
        r = api("GET", "/zones?per_page=50&page=%d" % page)
        if not r.get("success"):
            sys.exit("Could not list zones: " + errmsg(r))
        zones += r["result"]
        info = r.get("result_info", {})
        if page >= info.get("total_pages", 1):
            break
        page += 1
    return zones


def allow_ai_crawlers(zid):
    """Turn off AI-bot blocking + managed robots.txt. Falls back to the two
    free-plan fields if the full payload is rejected."""
    full = {
        "ai_bots_protection": "disabled",
        "crawler_protection": "disabled",
        "is_robots_txt_managed": False,
    }
    r = api("PUT", "/zones/%s/bot_management" % zid, full)
    if r.get("success"):
        return "bot settings: AI blocking off, managed robots.txt off"
    # Retry without crawler_protection (may be plan-gated).
    r2 = api("PUT", "/zones/%s/bot_management" % zid,
             {"ai_bots_protection": "disabled", "is_robots_txt_managed": False})
    if r2.get("success"):
        return "bot settings: AI blocking off, managed robots.txt off (crawler_protection skipped)"
    return "!! bot settings FAILED: " + errmsg(r2)


def ensure_cache_bypass(zid):
    """Add the critical-path cache-bypass rule if it isn't already there."""
    ep = "/zones/%s/rulesets/phases/http_request_cache_settings/entrypoint" % zid
    rs = api("GET", ep)
    rules = rs.get("result", {}).get("rules", []) if rs.get("success") else []
    if any(x.get("description") == RULE_DESC for x in rules):
        return "cache-bypass rule already present"
    new_rule = {
        "expression": CACHE_EXPR,
        "action": "set_cache_settings",
        "action_parameters": {"cache": False},
        "description": RULE_DESC,
        "enabled": True,
    }
    if rs.get("success") and rs.get("result", {}).get("id"):
        rid = rs["result"]["id"]
        r = api("PUT", "/zones/%s/rulesets/%s" % (zid, rid), {"rules": rules + [new_rule]})
    else:
        # Phase entrypoint doesn't exist yet -> create it with just our rule.
        r = api("PUT", ep, {"rules": [new_rule]})
    return "cache-bypass rule added" if r.get("success") else "!! cache rule FAILED: " + errmsg(r)


def purge_critical(zid, zname):
    r = api("POST", "/zones/%s/purge_cache" % zid,
            {"files": ["https://%s/robots.txt" % zname, "https://%s/sitemap.xml" % zname]})
    return "purged robots.txt + sitemap.xml" if r.get("success") else "!! purge FAILED: " + errmsg(r)


def main():
    if not TOKEN:
        sys.exit("Set CF_API_TOKEN first (see header of this file).")
    zones = list_zones()
    print("Found %d zones.%s\n" % (len(zones), "  [DRY RUN — no changes]" if DRY_RUN else ""))
    for z in zones:
        zid, zname = z["id"], z["name"]
        print("== %s" % zname)
        if DRY_RUN:
            print("   would: allow AI crawlers, add cache-bypass rule, purge robots/sitemap\n")
            continue
        print("   " + allow_ai_crawlers(zid))
        print("   " + ensure_cache_bypass(zid))
        print("   " + purge_critical(zid, zname))
        print("")
    print("Done." if not DRY_RUN else "Dry run complete.")


if __name__ == "__main__":
    main()
# ===== SNAPSMACK EOF =====
