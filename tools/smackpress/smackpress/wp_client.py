"""
SmackPress — wp_client.py
WordPress REST API client (reads posts, resolves images, hides migrated posts).
Authenticates with WordPress Application Passwords (Basic auth over HTTPS).

# ===== SNAPSMACK EOF =====
"""

from __future__ import annotations
import base64
import json
from typing import Any
import urllib.request
import urllib.parse
import urllib.error

import config


class WPError(Exception):
    pass


def _headers() -> dict:
    user = config.get("wp_user")
    pwd  = config.get("wp_app_password").replace(" ", "")
    creds = base64.b64encode(f"{user}:{pwd}".encode()).decode()
    return {
        "Authorization": f"Basic {creds}",
        "Accept": "application/json",
        "Content-Type": "application/json",
    }


def _base() -> str:
    url = config.get("wp_url").rstrip("/")
    return f"{url}/wp-json/smackpress/v1"


def _request(method: str, path: str, body: dict | None = None, params: dict | None = None) -> Any:
    url = _base() + path
    if params:
        url += "?" + urllib.parse.urlencode(params)

    data = json.dumps(body).encode() if body else None
    req  = urllib.request.Request(url, data=data, headers=_headers(), method=method)
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return json.loads(resp.read().decode())
    except urllib.error.HTTPError as e:
        msg = e.read().decode()
        try:
            err = json.loads(msg)
            raise WPError(err.get("message", msg))
        except (json.JSONDecodeError, KeyError):
            raise WPError(f"HTTP {e.code}: {msg}")
    except urllib.error.URLError as e:
        raise WPError(f"Connection error: {e.reason}")


# --------------------------------------------------------------------------
# Public API
# --------------------------------------------------------------------------

def test_connection() -> dict:
    """Returns plugin status dict or raises WPError."""
    return _request("GET", "/status")


def get_posts(page: int = 1, per_page: int = 20,
              status: str = "publish", search: str = "", category: int = 0) -> dict:
    """
    Returns {posts, total, total_pages, page, per_page}.
    status may be comma-separated, e.g. "publish,private".
    """
    params: dict = {"page": page, "per_page": per_page, "status": status}
    if search:
        params["search"] = search
    if category:
        params["category"] = category
    return _request("GET", "/posts", params=params)


def get_post(wp_id: int) -> dict:
    """Full single-post data with expanded galleries and image list."""
    return _request("GET", f"/posts/{wp_id}")


def hide_post(wp_id: int, migrated_to_url: str = "") -> dict:
    """Sets WP post to private and records the SnapSmack destination URL."""
    return _request("POST", f"/posts/{wp_id}/hide",
                    body={"migrated_to_url": migrated_to_url})


def get_categories() -> list:
    result = _request("GET", "/categories")
    return result.get("categories", [])

# ===== SNAPSMACK EOF =====
