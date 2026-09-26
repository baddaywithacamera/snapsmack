"""
snap_site_scope — name the site a request is meant for (mutual-auth A1, SECAUDIT 054).

The fleet shares one key across sites, so a write a tool meant for site A would
be accepted by site B. The server can now refuse that — IF the tool says which
site it is talking to. This is the one line every tool adds:

    headers.update(snap_site_scope.header(site_url))
    # → {"X-Snap-Site": "pixhellated.ca"}

Server side: core/api-site-scope.php. Off by default per site; ENFORCE refuses
a write that names a different site; REQUIRE also refuses writes that don't
name one — so every tool must send this before any site goes to REQUIRE.

No network, no state. Safe to call with '' (returns {}).
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

from urllib.parse import urlsplit

HEADER = "X-Snap-Site"


def host_of(site_url) -> str:
    """Lower-case host with no port from a site URL or bare host. '' if none."""
    u = str(site_url or "").strip()
    if not u:
        return ""
    if "://" not in u:
        u = "https://" + u
    try:
        host = (urlsplit(u).hostname or "").strip(".").lower()
    except ValueError:
        return ""
    return host


def header(site_url) -> dict:
    """The header dict to merge into a request aimed at `site_url`."""
    h = host_of(site_url)
    return {HEADER: h} if h else {}

# ===== SNAPSMACK EOF =====
