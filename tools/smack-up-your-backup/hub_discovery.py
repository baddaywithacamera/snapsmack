"""
Smack Up Your Backup — hub_discovery.py
Multisite hub/spoke discovery and auto-populate from blog settings.

Connects to a SnapSmack blog, authenticates as admin, hits suyb-data.php
to pull the spoke list and cloud configuration. For spoke blogs that are
already in the multisite network, can also query them via the multisite
API (Bearer token) to fetch their backup config.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import json
import requests
from typing import Dict, List, Optional, Tuple


class DiscoveryError(Exception):
    """Raised when hub discovery fails."""
    pass


class HubDiscovery:
    """Connects to a SnapSmack hub and discovers its spoke network."""

    def __init__(self, site_url: str, admin_user: str = "", admin_pass: str = "",
                 api_key: str = "", timeout: int = 30):
        self.site_url = site_url.rstrip("/")
        self.admin_user = admin_user
        self.admin_pass = admin_pass
        self.api_key = (api_key or "").strip()
        self.timeout = timeout
        self._session: Optional[requests.Session] = None

    def _ensure_session(self) -> requests.Session:
        """Log into the blog's admin panel and return an authenticated session."""
        if self._session is not None:
            return self._session

        s = requests.Session()
        s.headers["User-Agent"] = "SmackUpYourBackup/1.0"

        # API-key auth (preferred): no login, send X-Snap-Key on every request.
        if self.api_key:
            s.headers["X-Snap-Key"] = self.api_key
            self._session = s
            return s

        login_url = f"{self.site_url}/login.php"
        try:
            resp = s.post(login_url, data={
                "username": self.admin_user,
                "password": self.admin_pass,
            }, timeout=self.timeout, allow_redirects=True)
        except requests.RequestException as e:
            raise DiscoveryError(f"Could not connect to {login_url}: {e}")

        # Check login succeeded — SnapSmack redirects to smack-admin.php
        if resp.status_code != 200:
            raise DiscoveryError(
                f"Login failed (HTTP {resp.status_code}). Check URL and credentials."
            )
        # Verify we have an admin session — look for a session cookie
        if not any("PHPSESSID" in c.name or "snapsess" in c.name.lower()
                    for c in s.cookies):
            # Some installs use custom cookie names — check response content
            if "login" in resp.url.lower() and "error" in resp.text.lower():
                raise DiscoveryError("Login failed. Check admin username and password.")

        self._session = s
        return s

    def fetch_suyb_data(self) -> Dict:
        """Hit suyb-data.php and return the full response dict.

        Returns dict with keys: ok, site_url, site_name, cloud_config,
        backup_status, multisite.
        """
        s = self._ensure_session()
        url = f"{self.site_url}/suyb-data.php"
        try:
            resp = s.get(url, timeout=self.timeout)
        except requests.RequestException as e:
            raise DiscoveryError(f"Could not reach {url}: {e}")

        if resp.status_code != 200:
            raise DiscoveryError(
                f"suyb-data.php returned HTTP {resp.status_code}. "
                "Is the blog running SnapSmack v0.7.9g or later?"
            )

        try:
            data = resp.json()
        except (ValueError, json.JSONDecodeError):
            raise DiscoveryError("suyb-data.php returned invalid JSON.")

        if not data.get("ok"):
            raise DiscoveryError("suyb-data.php returned an error response.")

        return data

    def discover_spokes(self) -> Tuple[Dict, List[Dict]]:
        """Connect to a hub, return (hub_info, spoke_list).

        hub_info is a dict with: site_url, site_name, cloud_config, backup_status
        spoke_list is a list of dicts from multisite.nodes with role='spoke'
        """
        data = self.fetch_suyb_data()

        hub_info = {
            "site_url":      data.get("site_url", self.site_url),
            "site_name":     data.get("site_name", ""),
            "cloud_config":  data.get("cloud_config", {}),
            "backup_status": data.get("backup_status", {}),
        }

        ms = data.get("multisite", {})
        all_nodes = ms.get("nodes", [])
        # Filter to spokes only — the hub itself is not a "spoke"
        spokes = [n for n in all_nodes if n.get("role") == "spoke"]

        return hub_info, spokes

    def fetch_spoke_backup_config(self, spoke_url: str,
                                  api_key: str) -> Optional[Dict]:
        """Query a spoke's multisite/backup/config endpoint using its API key.

        Returns dict with: site_url, site_name, cloud_provider, cloud_folder_id, version
        or None on failure.
        """
        url = f"{spoke_url.rstrip('/')}/api.php?route=multisite/backup/config"
        try:
            resp = requests.get(url, headers={
                "Authorization": f"Bearer {api_key}",
                "User-Agent": "SmackUpYourBackup/1.0",
            }, timeout=self.timeout)
        except requests.RequestException:
            return None

        if resp.status_code != 200:
            return None

        try:
            data = resp.json()
        except (ValueError, json.JSONDecodeError):
            return None

        return data if data.get("ok") else None

    def close(self) -> None:
        """Close the HTTP session."""
        if self._session:
            self._session.close()
            self._session = None


def build_profiles_from_spokes(
    hub_info: Dict,
    spokes: List[Dict],
    spoke_configs: Dict[str, Dict],
    default_backup_dir: str = "",
    global_cloud: Optional[Dict] = None,
    hub_api_key: str = "",
) -> List[Dict]:
    """Turn discovered spokes into SUYB profile dicts ready for save_profile().

    Args:
        hub_info:        Dict from discover_spokes() with hub's own config
        spokes:          List of spoke dicts from discover_spokes()
        spoke_configs:   Map of spoke site_url → backup/config response
        default_backup_dir: Base directory for backups (subfolders per blog)

    Returns:
        List of profile dicts compatible with profile_manager.save_profile()
    """
    import os
    import profile_manager

    profiles = []

    gc = global_cloud or {}

    # Hub profile first
    hub_cloud = hub_info.get("cloud_config", {})
    hub_tmpl = profile_manager.new_profile_template()
    hub_tmpl.update({
        "name":                   hub_info.get("site_name", "Hub"),
        "site_url":               hub_info.get("site_url", ""),
        "api_key":                hub_api_key,
        "backup_method":          "cloud",
        "cloud_provider":         gc.get("cloud_provider") or hub_cloud.get("provider") or "google_drive",
        "cloud_credentials_file": "",   # inherit the one global Drive credential
        "cloud_folder_id":        hub_cloud.get("folder_id", ""),
        "backup_dir":             os.path.join(default_backup_dir,
                                         _safe_dirname(hub_info.get("site_name", "hub")))
                                  if default_backup_dir else "",
    })
    profiles.append(hub_tmpl)

    # Spoke profiles
    for spoke in spokes:
        url  = spoke.get("site_url", "").rstrip("/")
        name = spoke.get("site_name", "") or _name_from_url(url)
        cfg  = spoke_configs.get(url, {})

        tmpl = profile_manager.new_profile_template()
        tmpl.update({
            "name":                   name,
            "site_url":               url,
            "api_key":                spoke.get("api_key_local", ""),  # hub->spoke key from the hub's own DB (auth for backup pulls)
            "backup_method":          "cloud",
            "cloud_provider":         gc.get("cloud_provider") or cfg.get("cloud_provider") or "google_drive",
            "cloud_credentials_file": "",   # inherit the one global Drive credential
            "cloud_folder_id":        cfg.get("cloud_folder_id", ""),
        })

        if default_backup_dir:
            tmpl["backup_dir"] = os.path.join(default_backup_dir,
                                               _safe_dirname(name))

        profiles.append(tmpl)

    return profiles


def _safe_dirname(name: str) -> str:
    """Turn a blog name into a safe directory component."""
    safe = name.replace("/", "_").replace("\\", "_").replace(":", "_").strip()
    return safe or "blog"


def _name_from_url(url: str) -> str:
    """Extract a readable name from a URL."""
    from urllib.parse import urlparse
    host = urlparse(url).hostname or url
    return host.replace("www.", "")
# ===== SNAPSMACK EOF =====
