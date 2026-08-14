"""
SHOTS FIRED — fleet.py

Loads the FLEET (every blog the operator has set up in any SnapSmack tool) from
the SHARED cross-tool profile store, tools/_shared/snap_profiles. SHOTS FIRED is
read-only against that store: it never writes a profile, it just enumerates the
sites so the calendar can ask each one for its upcoming scheduled posts.

A profile carries (name, site_url, api_key) — exactly what schedule_client needs
to authenticate. Sites with no api_key are still listed (greyed, "no key") so the
operator can see the whole fleet and knows which blogs need a posting key before
their queue can be read or shuffled.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""


import os
import sys
from dataclasses import dataclass, field
from typing import List


def _add_shared_to_path() -> None:
    """Copied from config.py — put tools/_shared on sys.path for the bare-name
    imports (snap_profiles, snap_home)."""
    try:
        if getattr(sys, 'frozen', False):
            base = os.path.dirname(sys.executable)
        else:
            base = os.path.dirname(os.path.abspath(__file__))
        _sd = os.path.join(base, '..', '_shared')
        if os.path.isdir(_sd) and _sd not in sys.path:
            sys.path.insert(0, _sd)
    except Exception:
        pass


@dataclass
class Site:
    """One blog in the fleet, as SHOTS FIRED sees it."""
    name: str
    url: str
    api_key: str = ""
    extras: dict = field(default_factory=dict)

    @property
    def has_key(self) -> bool:
        return bool(self.api_key.strip())

    @property
    def display(self) -> str:
        return self.name or self.url


def load_fleet() -> List[Site]:
    """Return every shared profile as a Site, sorted by display name. Returns an
    empty list (never raises) when the shared store is unreachable or empty — the
    UI then shows a 'no sites configured' note rather than crashing."""
    _add_shared_to_path()
    try:
        import snap_profiles
    except Exception:
        return []
    try:
        profs = snap_profiles.list_profiles()
    except Exception:
        return []
    sites: List[Site] = []
    for p in profs:
        url = (p.get("site_url") or "").strip()
        if not url:
            continue
        sites.append(Site(
            name=(p.get("name") or "").strip(),
            url=url,
            api_key=(p.get("api_key") or "").strip(),
            extras=dict(p.get("extras") or {}),
        ))
    sites.sort(key=lambda s: s.display.lower())
    return sites
# ===== SNAPSMACK EOF =====
