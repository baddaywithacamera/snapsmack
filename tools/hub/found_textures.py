"""Found Textures client for SNAP SLAPPER.

Browse foundtextures.ca (or any SnapSmack site) and import textures as layers.
It talks to the site's read API (``api.php?route=gyss/photos``) which returns
images with thumbnail URLs, categories, and search — the full texture URL is
derived from the thumbnail URL. Auth pulls the site + key from The Hub's shared
profile store (all auth pulls from The Hub).

The pure helpers (search_url / parse_response / full_url_from_thumb) have no
network and are unit-tested; ``search`` and ``download`` do the HTTP.
"""

import datetime
import json
import os
import urllib.parse
import urllib.request

try:
    import requests
except Exception:  # noqa: BLE001
    requests = None

try:
    import snap_profiles
except Exception:  # noqa: BLE001
    snap_profiles = None

try:
    import snap_log
    _log = snap_log.get("snap_slapper")
except Exception:  # noqa: BLE001
    import logging
    _log = logging.getLogger("snapsmack.snap_slapper")

DEFAULT_SITE_HINT = "foundtextures"


# --- Credentials (from The Hub's profile store) ------------------------------
def resolve_profile(hint=DEFAULT_SITE_HINT):
    """Return the (site_url, api_key) for the Found Textures site, or None.

    Picks the Hub profile whose site URL contains `hint` (e.g. 'foundtextures').
    """
    if snap_profiles is None:
        return None
    try:
        for profile in snap_profiles.list_profiles():
            site = (profile.get("site_url") or "").lower()
            if hint in site:
                return (profile.get("site_url", "").rstrip("/"),
                        profile.get("api_key", ""))
    except Exception:  # noqa: BLE001
        _log.exception("Found Textures: could not read Hub profiles")
    return None


# --- Pure helpers (no network) ----------------------------------------------
def full_url_from_thumb(thumb_url):
    """Derive the full image URL from an aspect-thumb URL.

    gyss builds thumbs as '<dir>/thumbs/a_<name>', so the full image is the same
    path without the 'thumbs/a_' segment.
    """
    return thumb_url.replace("/thumbs/a_", "/", 1) if thumb_url else ""


def search_url(site_url, query="", category_id=None, page=1, per_page=40):
    base = site_url.rstrip("/") + "/api.php"
    params = {"route": "gyss/photos", "search": query or "",
              "limit": int(per_page), "offset": (max(1, int(page)) - 1) * int(per_page)}
    if category_id:
        params["category_id"] = int(category_id)
    return base + "?" + urllib.parse.urlencode(params)


def parse_response(payload, site_url):
    """Turn the API payload into (textures, total). Each texture carries the
    provenance the spec asks imported layers to preserve."""
    if not isinstance(payload, dict):
        raise ValueError("Unexpected Found Textures response")
    if payload.get("ok") is False:
        raise ValueError(payload.get("error") or "Found Textures API error")
    retrieved = datetime.datetime.now().strftime("%Y-%m-%d")
    textures = []
    for photo in payload.get("photos", []) or []:
        thumb = photo.get("thumb_url", "")
        textures.append({
            "id": photo.get("id"),
            "title": photo.get("title") or photo.get("filename") or "Texture",
            "category": photo.get("category_name"),
            "thumb_url": thumb,
            "full_url": full_url_from_thumb(thumb),
            "source_site": site_url,
            # rights aren't in this endpoint yet; treat unknown as flagged.
            "licence": photo.get("licence") or photo.get("rights") or "unknown",
            "retrieved_at": retrieved,
        })
    return textures, int(payload.get("total") or len(textures))


def provenance(texture):
    """The attribution block stored on an imported texture layer."""
    return {
        "texture_id": texture.get("id"),
        "title": texture.get("title"),
        "source_url": texture.get("full_url"),
        "source_site": texture.get("source_site"),
        "licence": texture.get("licence", "unknown"),
        "retrieved_at": texture.get("retrieved_at"),
    }


# --- Network ----------------------------------------------------------------
def search(site_url, api_key, query="", category_id=None, page=1, per_page=40, timeout=15):
    """Search the Found Textures site. Returns (textures, total)."""
    url = search_url(site_url, query, category_id, page, per_page)
    headers = {"Authorization": f"Bearer {api_key}"} if api_key else {}
    _log.info("Found Textures search: %s", url)
    if requests is not None:
        response = requests.get(url, headers=headers, timeout=timeout)
        response.raise_for_status()
        payload = response.json()
    else:
        request = urllib.request.Request(url, headers=headers)
        with urllib.request.urlopen(request, timeout=timeout) as resp:
            payload = json.loads(resp.read().decode("utf-8"))
    return parse_response(payload, site_url)


def fetch_bytes(url, api_key=None, timeout=20):
    """GET a URL (with the Hub key) and return the raw bytes. For thumbnails."""
    headers = {"Authorization": f"Bearer {api_key}"} if api_key else {}
    if requests is not None:
        response = requests.get(url, headers=headers, timeout=timeout)
        response.raise_for_status()
        return response.content
    request = urllib.request.Request(url, headers=headers)
    with urllib.request.urlopen(request, timeout=timeout) as resp:
        return resp.read()


def cache_dir():
    try:
        import snap_home
        directory = os.path.join(snap_home.home(), "snap_slapper", "textures")
    except Exception:  # noqa: BLE001
        directory = os.path.join(os.path.expanduser("~"), "SnapSmack", "textures")
    os.makedirs(directory, exist_ok=True)
    return directory


def download(texture, api_key=None, timeout=30):
    """Download a texture's full image into the local cache; return the path."""
    url = texture.get("full_url")
    if not url:
        raise ValueError("Texture has no image URL")
    name = f"ft_{texture.get('id', 'x')}_{os.path.basename(urllib.parse.urlparse(url).path)}"
    dest = os.path.join(cache_dir(), name)
    if os.path.isfile(dest) and os.path.getsize(dest) > 0:
        return dest                                  # already cached
    _log.info("Found Textures download: %s -> %s", url, dest)
    data = fetch_bytes(url, api_key, timeout=timeout)
    tmp = dest + ".part"
    with open(tmp, "wb") as handle:
        handle.write(data)
    os.replace(tmp, dest)
    return dest

# ===== SNAPSMACK EOF =====
