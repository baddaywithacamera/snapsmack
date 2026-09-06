"""
SMACK YOUR MOUTH — fleet.py
Loads the fleet SMACK YOUR MOUTH moderates, straight from the SHARED stores that
THE HUB's "Discover Fleet" fills — snap_profiles (one connection profile per
site) and snap_creds (the shared credential vault) — so the operator sets the
fleet up ONCE and every family tool, this one included, already has every site.

Read-only against _shared: it imports snap_home / snap_profiles / snap_creds
exactly as COLD SNAP does and never writes to them.

NOTE ON KEYS: the comment endpoints on a spoke authenticate the caller as the
hub (the api_key_local the hub was issued at handshake). A profile's stored
api_key MAY instead be a posting-scoped fleet key (COLD SNAP's case). This loader
therefore prefers, in order: an explicit extras['api_key_local'], then the
profile's api_key, then the shared 'hub_key'. If none of those carry moderation
scope the spoke returns 401 and the tool says so plainly — see the spec's
"Server API" for the scope gap and its two clean server-side fixes.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import os
import sys
from dataclasses import dataclass, field
from typing import List, Optional


def _add_shared_to_path() -> None:
    """Put tools/_shared (…/_shared next to the install at runtime) on sys.path.
    No-op if already present or unreachable. Copied from COLD SNAP's bootstrap."""
    try:
        if getattr(sys, "frozen", False):
            base = os.path.dirname(sys.executable)
        else:
            base = os.path.dirname(os.path.abspath(__file__))
        for cand in (os.path.join(base, "..", "_shared"),   # dev tree
                     base):                                   # frozen: bundled flat
            cand = os.path.normpath(cand)
            if os.path.isdir(cand) and cand not in sys.path:
                sys.path.insert(0, cand)
    except Exception:
        pass


def _shared_modules():
    """Import (snap_home, snap_profiles, snap_creds) from _shared, or (None,)*3 if
    the shared home isn't reachable — the tool then runs in single-site mode."""
    _add_shared_to_path()
    try:
        import snap_home
        import snap_profiles
    except Exception:
        return None, None, None
    try:
        import snap_creds
    except Exception:
        snap_creds = None
    return snap_home, snap_profiles, snap_creds


@dataclass
class SiteEntry:
    name:          str = ""
    site_url:      str = ""
    site_key:      str = ""
    api_key:       str = ""
    node_id:       int = 0
    reachable:     bool = True
    pending_count: int = 0
    note:          str = ""

    def as_ingest_site(self) -> dict:
        """The subset Session.ingest_pull() needs."""
        return {
            "site_url":  self.site_url,
            "site_name": self.name,
            "site_key":  self.site_key,
            "node_id":   self.node_id,
        }


def load_fleet() -> List[SiteEntry]:
    """Every site in the shared profile store, as SiteEntry rows (no network).
    Empty list if the shared home is unreachable (single-site mode covers that)."""
    snap_home, snap_profiles, snap_creds = _shared_modules()
    if not snap_profiles:
        return []
    hub_key = ""
    if snap_creds is not None:
        try:
            snap_creds.init()
            hub_key = snap_creds.get("hub_key", "")
        except Exception:
            hub_key = ""

    entries: List[SiteEntry] = []
    try:
        profiles = snap_profiles.list_profiles()
    except Exception:
        profiles = []
    for p in profiles:
        site_url = (p.get("site_url") or "").strip()
        if not site_url:
            continue
        extras = p.get("extras") or {}
        # Prefer the hub->spoke key (moderation scope), then the profile key,
        # then the shared hub key.
        api_key = ((snap_creds.get_site(site_url, "api_key_local").strip()
                    if snap_creds is not None else "")
                   or str(p.get("api_key") or "").strip()
                   or hub_key)
        try:
            skey = snap_home.site_key(site_url) if snap_home else site_url
        except Exception:
            skey = site_url
        try:
            node_id = int(extras.get("node_id") or 0)
        except (TypeError, ValueError):
            node_id = 0
        entries.append(SiteEntry(
            name=p.get("name") or site_url,
            site_url=site_url,
            site_key=skey,
            api_key=api_key,
            node_id=node_id,
        ))
    entries.sort(key=lambda e: (e.name or "").lower())
    return entries


def probe_fleet(entries: List[SiteEntry], timeout: int = 12) -> List[SiteEntry]:
    """Live reachability pass: fill reachable / pending_count / note for each
    site via multisite/heartbeat. Mutates and returns the same list. Kept out of
    load_fleet() so the fleet renders instantly offline, then upgrades when a
    connection is available."""
    from moderation_api import MouthConnection  # local import avoids a cycle
    for e in entries:
        if not e.api_key:
            e.reachable, e.note = False, "no API key in the shared profile"
            continue
        conn = MouthConnection(e.site_url, e.api_key)
        reachable, pending, note = conn.heartbeat(timeout=timeout)
        e.reachable, e.pending_count, e.note = reachable, pending, note
    return entries


def find_entry(entries: List[SiteEntry], site_key: str) -> Optional[SiteEntry]:
    for e in entries:
        if e.site_key == site_key:
            return e
    return None
# ===== SNAPSMACK EOF =====
