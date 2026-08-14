"""
SNAPSMACK — snap_discovery.py  (shared "set the hub up once" engine)

Point this at your SnapSmack hub, and it fills the SHARED stores every tool reads:

    hub key / Gemini / Drive config  ->  snap_creds  (shared_library\\auth)
    every spoke site                 ->  snap_profiles (shared_library\\profiles)

so a photographer configures the fleet ONCE — in the Hub, or from any tool — and
SYBU, SUYB, GYSS and COLD SNAP all have every site and every shared secret. This is
the engine behind the Hub's "Discover Fleet" and behind a matching button in the
individual tools. The discovery transport is lifted from SUYB's hub_discovery.py
(suyb-data.php + the multisite node list); the new part is that it writes the
SHARED stores instead of one tool's private config.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import json
import os
import sys

import requests

_HERE = os.path.dirname(os.path.abspath(__file__))
if _HERE not in sys.path:
    sys.path.insert(0, _HERE)

import snap_creds
import snap_profiles

try:
    from snap_stepup import insecure_transport_reason
except Exception:
    def insecure_transport_reason(base_url: str) -> str:
        u = str(base_url).strip().lower()
        if u.startswith("https://"):
            return ""
        for host in ("http://localhost", "http://127.", "http://[::1]"):
            if u.startswith(host):
                return ""
        return ("This site URL is not https://, so your admin password would be "
                "sent across the network in the clear.")


class DiscoveryError(Exception):
    pass


def _session(hub_url, api_key="", admin_user="", admin_pass="", timeout=30,
             login_slug="snap-in"):
    """Authenticated requests.Session for the hub. Bearer key preferred; falls back
    to an admin login (refused over plain http)."""
    s = requests.Session()
    s.headers["User-Agent"] = "SnapSmackHub/1.0"
    hub_url = hub_url.rstrip("/")
    if api_key.strip():
        s.headers["Authorization"] = f"Bearer {api_key.strip()}"
        return s
    reason = insecure_transport_reason(hub_url)
    if reason:
        raise DiscoveryError(reason)
    slug = (login_slug or "snap-in").strip("/") or "snap-in"
    try:
        resp = s.post(f"{hub_url}/{slug}",
                      data={"username": admin_user, "password": admin_pass},
                      timeout=timeout, allow_redirects=True)
    except requests.RequestException as e:
        raise DiscoveryError(f"Could not connect to {hub_url}: {e}")
    if resp.status_code != 200:
        raise DiscoveryError(f"Login failed (HTTP {resp.status_code}). Check URL + credentials.")
    if "login" in resp.url.lower() and "error" in resp.text.lower():
        raise DiscoveryError("Login failed. Check admin username and password.")
    return s


def discover(hub_url, api_key="", admin_user="", admin_pass="", timeout=30):
    """Connect to a hub and return (hub_info, spokes).

    hub_info: {site_url, site_name, cloud_config, backup_status}
    spokes:   list of {site_url, site_name?, api_key?} from multisite.nodes (role=spoke)
    """
    hub_url = hub_url.rstrip("/")
    s = _session(hub_url, api_key, admin_user, admin_pass, timeout)
    try:
        resp = s.get(f"{hub_url}/suyb-data.php", timeout=timeout)
    except requests.RequestException as e:
        raise DiscoveryError(f"Could not reach {hub_url}/suyb-data.php: {e}")
    if resp.status_code != 200:
        raise DiscoveryError(f"suyb-data.php returned HTTP {resp.status_code} "
                             "(is the blog on SnapSmack v0.7.9g+?).")
    try:
        data = resp.json()
    except (ValueError, json.JSONDecodeError):
        raise DiscoveryError("suyb-data.php returned invalid JSON.")
    if not data.get("ok"):
        raise DiscoveryError("suyb-data.php returned an error response.")

    hub_info = {
        "site_url":      data.get("site_url", hub_url),
        "site_name":     data.get("site_name", ""),
        "cloud_config":  data.get("cloud_config", {}) or {},
        "backup_status": data.get("backup_status", {}) or {},
    }
    nodes = (data.get("multisite", {}) or {}).get("nodes", []) or []
    spokes = [n for n in nodes if n.get("role") == "spoke"]
    return hub_info, spokes


# ── writing the SHARED stores ───────────────────────────────────────────────
def _save_cloud_to_vault(hub_url, api_key, cloud_config) -> list:
    """Fold the hub's shared secrets into snap_creds. Returns the keys written."""
    written = []
    if api_key.strip():
        snap_creds.set("hub_key", api_key.strip()); written.append("hub_key")
    # cloud_config shapes vary; only copy well-known keys when present + non-empty.
    for src, dst in (("gemini_api_key", "gemini_api_key"),
                     ("google_credentials", "google_credentials"),
                     ("drive_folder_id", "drive_folder_id")):
        val = str(cloud_config.get(src, "") or "").strip()
        if val:
            snap_creds.set(dst, val); written.append(dst)
    return written


def _profile_for(node, fallback_key="") -> dict:
    """A canonical snap_profiles dict from a discovered node."""
    site = (node.get("site_url") or node.get("url") or "").strip()
    return {
        "name":     node.get("site_name") or node.get("name") or site,
        "site_url": site,
        # Each spoke ships its per-site key from the multisite config. api_key_local
        # (the hub->spoke FULL key) is posting-capable, so PREFER it. Only fall back
        # to the hub key when a node genuinely has none — that fallback cannot post.
        "api_key":  (node.get("api_key_local") or node.get("api_key")
                     or node.get("key") or fallback_key or "").strip(),
        "extras":   {},
    }


def _provision_spoke_key(site_url, api_key_local, key_type="sybu", timeout=20):
    """Ask a spoke to mint a proper TOOL key for the fleet, using the hub's FULL
    key (api_key_local). Returns the raw key, or "" if the spoke is on an older
    build without the route — the caller then falls back to the discovered key."""
    if not (site_url and api_key_local):
        return ""
    try:
        r = requests.post(
            site_url.rstrip("/") + "/api.php",
            params={"route": "multisite/provision-key"},
            json={"key_type": key_type},
            headers={"Authorization": "Bearer " + api_key_local.strip(),
                     "User-Agent": "SnapSmackHub/1.0"},
            timeout=timeout,
        )
        if r.status_code == 200:
            data = r.json()
            if data.get("ok") and data.get("api_key"):
                return str(data["api_key"]).strip()
    except Exception:
        pass
    return ""


def save_to_shared(hub_info, spokes, hub_api_key="") -> dict:
    """Write a discovered fleet into the shared stores. Returns a summary dict."""
    vault_keys = _save_cloud_to_vault(hub_info.get("site_url", ""), hub_api_key,
                                      hub_info.get("cloud_config", {}))
    saved_sites = []
    # The hub itself is a site too — save it so the tools can target it.
    hub_node = {"site_url": hub_info.get("site_url", ""),
                "site_name": hub_info.get("site_name", "")}
    for node in [hub_node] + list(spokes):
        prof = _profile_for(node, fallback_key=hub_api_key)
        if not prof["site_url"]:
            continue
        akl = (node.get("api_key_local") or "").strip()
        # The full node key (role='hub' on the spoke). Hub-role tools like SMACK
        # YOUR MOUTH authenticate with THIS, not the sybu posting key — so persist
        # it in extras to give them the moderation credential per site. The hub's
        # own row carries no api_key_local; its full key is the hub key itself.
        akl_for_extras = akl or ((hub_api_key or "").strip() if node is hub_node else "")
        if akl_for_extras:
            prof.setdefault("extras", {})["api_key_local"] = akl_for_extras
        # Have the spoke mint a real sybu posting key for the fleet (set-up-once).
        if akl:
            minted = _provision_spoke_key(prof["site_url"], akl, "sybu")
            if minted:
                prof["api_key"] = minted
        try:
            snap_profiles.save(prof)
            saved_sites.append(prof["site_url"])
        except Exception:
            pass
    return {"vault_keys": vault_keys, "sites": saved_sites, "count": len(saved_sites)}


def discover_and_save(hub_url, api_key="", admin_user="", admin_pass="", timeout=30) -> dict:
    """One call: discover the fleet from the hub and write the shared stores."""
    hub_info, spokes = discover(hub_url, api_key, admin_user, admin_pass, timeout)
    return save_to_shared(hub_info, spokes, hub_api_key=api_key)
# ===== SNAPSMACK EOF =====
