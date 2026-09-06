"""One read-only connection adapter for every SnapSmack desktop tool.

Discover owns the profile inventory and credential vault.  Applications may
retain their own non-secret preferences, but must resolve site credentials here
instead of keeping a second fleet database.
"""

from __future__ import annotations

from typing import Iterable

import snap_creds
import snap_profiles
try:
    import snap_native_creds
except Exception:
    snap_native_creds = None


_FIELDS = {
    "sybu": ("api_key_sybu", "api_key"),
    "gyss": ("api_key_gyss",),
    "ohsnap": ("api_key_ohsnap",),
    "tyswy": ("api_key_tyswy",),
    "unzucker": ("api_key_unzucker",),
    "flkrfckr": ("api_key_flkrfckr",),
    "smackpress": ("api_key_smackpress",),
}


def _secret(site_url: str, fields: Iterable[str]) -> str:
    for field in fields:
        if snap_native_creds and field.startswith("api_key_"):
            value = snap_native_creds.get_site(site_url, field[len("api_key_"):])
            if value:
                return value
        value = snap_creds.get_site(site_url, field, "")
        if value:
            return value
    return ""


def list_connections(key_type: str = "sybu") -> list[dict]:
    """Return all Discover profiles with the requested least-privilege key.

    A row is still returned when its key is unavailable so UIs can say that the
    vault is locked/missing rather than silently dropping the site.
    """
    fields = _FIELDS.get(key_type, (f"api_key_{key_type}",))
    result = []
    for profile in snap_profiles.list_profiles():
        site = (profile.get("site_url") or "").strip()
        if not site:
            continue
        key = _secret(site, fields)
        result.append({
            "name": profile.get("name") or site,
            "site_url": site,
            "api_key": key,
            "credential_available": bool(key),
            "portable": dict(profile.get("portable") or {}),
            "portable_sync": dict(profile.get("portable_sync") or {}),
            "extras": dict(profile.get("extras") or {}),
        })
    return result


def resolve(site_url: str = "", key_type: str = "sybu") -> dict | None:
    """Resolve an existing selection, or the sole discovered site.

    Never guesses when multiple sites exist: callers must preserve/show their
    chosen URL.  This prevents an app from acting on the wrong blog.
    """
    rows = list_connections(key_type)
    wanted = (site_url or "").rstrip("/").lower()
    if wanted:
        return next((r for r in rows if r["site_url"].rstrip("/").lower() == wanted), None)
    return rows[0] if len(rows) == 1 else None

# ===== SNAPSMACK EOF =====
