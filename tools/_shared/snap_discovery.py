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
import re
import secrets
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
    if hub_url.strip():
        snap_creds.set("hub_url", hub_url.strip().rstrip("/")); written.append("hub_url")
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


def _provision_spoke_key(site_url, api_key_local, key_type="sybu", key_value="", timeout=20):
    """Ask a spoke to provision a TOOL key for the fleet, using the hub's FULL key
    (api_key_local).

    - No key_value: the spoke MINTS a fresh per-site key and returns it (legacy).
    - key_value given (the ONE shared fleet key, 64 hex): the spoke INSTALLS that
      exact value so every site accepts the same key. The endpoint never echoes a
      supplied value, so on success we return the value we sent — a truthy result
      still signals success.

    Returns the key (minted or supplied) on success, or "" if the spoke is on an
    older build without the route / the call failed."""
    if not (site_url and api_key_local):
        return ""
    body = {"key_type": key_type}
    if key_value:
        body["key_value"] = key_value.strip().lower()
    try:
        r = requests.post(
            site_url.rstrip("/") + "/api.php",
            params={"route": "multisite/provision-key"},
            json=body,
            headers={"Authorization": "Bearer " + api_key_local.strip(),
                     "User-Agent": "SnapSmackHub/1.0"},
            timeout=timeout,
        )
        if r.status_code == 200:
            data = r.json()
            if data.get("ok"):
                return str(data.get("api_key") or key_value or "").strip()
    except Exception:
        pass
    return ""


def _provision_hub_backup_key(site_url, hub_api_key, key_value, timeout=20):
    """Install the shared SUYB key on the hub through its local endpoint.

    The hub intentionally has no self-referential multisite node, so sending its
    discovery key to the spoke-only multisite/provision-key route always 401s.
    suyb-data.php authenticates the hub credential directly and exposes only
    this narrow same-site SUYB-key installation action.
    """
    if not (site_url and hub_api_key and key_value):
        return ""
    try:
        r = requests.post(
            site_url.rstrip("/") + "/suyb-data.php",
            json={"action": "provision-backup-key",
                  "key_value": key_value.strip().lower()},
            headers={"Authorization": "Bearer " + hub_api_key.strip(),
                     "User-Agent": "SnapSmackHub/1.0"},
            timeout=timeout,
        )
        if r.status_code == 200 and r.json().get("ok"):
            return key_value.strip().lower()
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
    """One call: discover the fleet from the hub and write the shared stores.

    Also pushes the ONE shared backup key to every site INCLUDING the hub. The
    hub never registers as a spoke, so it never mints its own per-site backup
    key — which is why the hub was the one node SUYB could not back up. Folding
    the shared-key push into Discover fixes that in a single action. It is
    idempotent and best-effort: a failure here never aborts the discovery, it is
    just reported under 'backup_key' so the caller can surface it.
    """
    hub_info, spokes = discover(hub_url, api_key, admin_user, admin_pass, timeout)
    summary = save_to_shared(hub_info, spokes, hub_api_key=api_key)
    try:
        summary["backup_key"] = provision_shared_backup_key(hub_url, api_key, timeout=timeout)
    except Exception as e:
        summary["backup_key"] = {"error": str(e)}
    return summary


def provision_shared_backup_key(hub_url="", api_key="", key_value="",
                                key_type="suyb", timeout=30) -> dict:
    """Give the WHOLE fleet ONE backup key — the fix for per-site key drift.

    This is the Hub 'mint-and-push' half of the one-key backup design. It:
      1. Reuses the one shared key already in the vault ('backup_hub_key'), or the
         value you pass, or mints a single fresh 64-hex key.
      2. Discovers the fleet from the hub and POSTs that SAME value to every
         spoke's multisite/provision-key (key_type='suyb', key_value=<shared>),
         using each spoke's full hub key. The site half (0.7.546D) installs it, so
         every site now accepts the one key. The hub is included as a target too.
      3. Stores the one key in the shared vault so SUYB's resolver
         (config.effective_backup_key) authenticates the whole fleet with it.

    No per-site minting by hand. Idempotent — each site converges on the one key,
    prior HUB keys of that type are retired server-side, and re-running is safe.
    Keys are revocable per-site on each blog's API-Keys page.

    hub_url/api_key default to the vault's stored hub creds, so the Hub button — or
    `python snap_discovery.py provision-backup-key` — is a single action.

    Returns {key_prefix, sites_ok:[...], sites_failed:[...], count}."""
    hub_url = (hub_url or snap_creds.get("hub_url") or "").strip()
    api_key = (api_key or snap_creds.get("hub_key") or "").strip()
    if not (hub_url and api_key):
        raise DiscoveryError("Hub URL and hub key are required — set the hub up "
                             "first (Discover Fleet), then run this.")

    shared = (key_value or snap_creds.get("backup_hub_key") or "").strip().lower()
    if not shared:
        shared = secrets.token_hex(32)          # 64 lowercase hex — matches the endpoint
    if not re.fullmatch(r"[a-f0-9]{64}", shared):
        raise DiscoveryError("shared backup key must be 64 hexadecimal characters")

    hub_info, spokes = discover(hub_url, api_key, timeout=timeout)

    hub_site = (hub_info.get("site_url") or hub_url).strip()
    ok, failed = [], []

    # The hub has no self-node and therefore needs its own local provisioning
    # route. Do this separately; the spoke route and all working spokes remain
    # unchanged.
    if _provision_hub_backup_key(hub_site, api_key, shared, timeout=timeout):
        ok.append(hub_site)
    else:
        failed.append(hub_site)

    targets = []
    for n in spokes:
        su  = (n.get("site_url") or n.get("url") or "").strip()
        akl = (n.get("api_key_local") or "").strip()
        if su and akl:
            targets.append({"site_url": su, "api_key_local": akl})

    for tgt in targets:
        res = _provision_spoke_key(tgt["site_url"], tgt["api_key_local"],
                                   key_type=key_type, key_value=shared, timeout=timeout)
        (ok if res else failed).append(tgt["site_url"])

    # Rollout order is a security and availability boundary: every target must
    # accept the key before SUYB begins preferring it fleet-wide. Never publish a
    # newly minted key into the vault after a partial provisioning failure.
    if not failed:
        try:
            snap_creds.set("backup_hub_key", shared)
        except Exception:
            pass

    return {"key_prefix": shared[:8], "sites_ok": ok,
            "sites_failed": failed, "count": len(ok)}


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "provision-backup-key":
        _hu = sys.argv[2] if len(sys.argv) > 2 else ""
        _hk = sys.argv[3] if len(sys.argv) > 3 else ""
        _out = provision_shared_backup_key(hub_url=_hu, api_key=_hk)
        print(json.dumps(_out, indent=2))
    else:
        print("usage: python snap_discovery.py provision-backup-key [hub_url] [hub_key]")
# ===== SNAPSMACK EOF =====
