"""One network boundary for hub-owned portable per-site settings."""

from datetime import datetime, timezone

import requests

import snap_creds
import snap_profiles
import snap_site_settings


class SyncError(RuntimeError):
    pass


def _connection():
    snap_creds.init()
    hub = snap_creds.get("hub_url").strip().rstrip("/")
    key = snap_creds.get("hub_key").strip()
    if not hub or not key:
        raise SyncError("SNAP HQ needs a hub URL and hub API key")
    return hub, key


def refresh_all(timeout=25):
    """Refresh every portable mirror. Existing cache survives any failure."""
    hub, key = _connection()
    try:
        reply = requests.get(hub + "/suyb-data.php",
            headers={"Authorization": "Bearer " + key, "User-Agent": "SnapHQ/0.3"},
            timeout=timeout)
        reply.raise_for_status()
        rows = reply.json().get("portable_sites") or {}
    except Exception as exc:
        raise SyncError(str(exc)) from exc
    synced = datetime.now(timezone.utc).isoformat()
    updated = []
    for profile in snap_profiles.list_profiles():
        site = (profile.get("site_url") or "").rstrip("/")
        row = rows.get(site) or {}
        profile["portable"] = snap_site_settings.validate_portable(row.get("settings") or {})
        profile["portable_sync"] = {"synced_at": synced, "hub_updated_at": row.get("updated_at")}
        snap_profiles.save(profile)
        updated.append(site)
    return {"synced_at": synced, "sites": updated}


def save(site_url, values, timeout=25):
    """Write through to the hub. Cache only after the hub accepts the write."""
    hub, key = _connection()
    portable = snap_site_settings.validate_portable(values)
    try:
        reply = requests.post(hub + "/suyb-data.php",
            headers={"Authorization": "Bearer " + key, "User-Agent": "SnapHQ/0.3"},
            json={"action": "save-site-settings", "site_url": site_url,
                  "settings": portable}, timeout=timeout)
        data = reply.json()
        if reply.status_code >= 400 or not data.get("ok"):
            raise SyncError(data.get("error") or "Hub rejected settings")
    except SyncError:
        raise
    except Exception as exc:
        raise SyncError(str(exc)) from exc
    profile = snap_profiles.load_by_site(site_url)
    if profile:
        profile["portable"] = portable
        profile["portable_sync"] = {"synced_at": datetime.now(timezone.utc).isoformat()}
        snap_profiles.save(profile)
    return data


def combined(site_url):
    profile = snap_profiles.load_by_site(site_url) or {}
    meta = profile.get("portable_sync") or {}
    return snap_site_settings.combined(site_url, profile.get("portable"),
        synced_at=meta.get("synced_at"), offline=True)

# ===== SNAPSMACK EOF =====
