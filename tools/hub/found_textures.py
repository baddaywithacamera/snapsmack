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
import re
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
    import snap_connections
except Exception:  # noqa: BLE001
    snap_connections = None

try:
    import snap_discovery
except Exception:  # noqa: BLE001
    snap_discovery = None

# SECAUDIT 054 chokepoint 1: downloaded texture bytes are validated as a real
# image in an allowed format BEFORE they are cached to disk for the editor to
# decode. FAIL-CLOSED — with the safety module missing, download() refuses.
try:
    import snap_imgsafe
except Exception:  # noqa: BLE001
    snap_imgsafe = None

try:
    import snap_log
    _log = snap_log.get("snap_slapper")
except Exception:  # noqa: BLE001
    import logging
    _log = logging.getLogger("snapsmack.snap_slapper")

DEFAULT_SITE_HINT = "foundtextures"


# --- Credentials (from The Hub's profile store) ------------------------------
def resolve_profile(hint=DEFAULT_SITE_HINT):
    """Return the (site_url, GYSS api_key) for Found Textures, or None.

    Picks the Hub profile whose site URL contains `hint` (e.g. 'foundtextures').
    Prefer the dedicated GYSS read key. Sites running 0.7.722D or newer also
    accept the profile's SYBU key for this published-photo catalogue only, so
    it is a safe compatibility fallback when an older discovery omitted GYSS.
    """
    if snap_profiles is None:
        return None
    try:
        for profile in snap_profiles.list_profiles():
            site = (profile.get("site_url") or "").lower()
            if hint in site:
                site_url = profile.get("site_url", "").rstrip("/")
                key = ""
                if snap_connections is not None:
                    connection = snap_connections.resolve(site_url, "gyss")
                    key = (connection or {}).get("api_key", "")
                if not key:
                    key = (profile.get("extras") or {}).get("api_key_gyss", "")
                # Discovery from an older HQ could save the site and its full
                # hub credential without ever minting the least-privilege GYSS
                # key. Repair that incomplete result once, in place. This does
                # not broaden access: the already-authorized full key is the
                # credential the site's provisioning route requires.
                if not key and snap_discovery is not None:
                    full_key = (profile.get("extras") or {}).get("api_key_local", "")
                    if full_key:
                        key = snap_discovery._provision_spoke_key(
                            site_url, full_key, "gyss")
                        if key:
                            profile.setdefault("extras", {})["api_key_gyss"] = key
                            snap_profiles.save(profile)
                if not key:
                    key = profile.get("api_key", "")
                return site_url, key
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


def search_url(site_url, query="", category_id=None, page=1, per_page=40,
               rights="all"):
    base = site_url.rstrip("/") + "/api.php"
    params = {"route": "gyss/photos", "search": query or "",
              "limit": int(per_page), "offset": (max(1, int(page)) - 1) * int(per_page)}
    if category_id:
        params["category_id"] = int(category_id)
    if rights in {"clear", "unclear", "unknown"}:
        params["rights"] = rights
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
            "description": photo.get("description") or "",
            "filename": photo.get("filename") or "",
            "category": photo.get("category_name"),
            "thumb_url": thumb,
            "full_url": full_url_from_thumb(thumb),
            "source_site": site_url,
            "source_page_url": photo.get("source_page_url") or "",
            "highres_download_url": photo.get("highres_download_url") or "",
            "licence": photo.get("licence") or "unknown",
            "rights_status": photo.get("rights_status") or "unknown",
            "retrieved_at": retrieved,
        })
    return textures, int(payload.get("total") or len(textures))


def provenance(texture):
    """The attribution block stored on an imported texture layer."""
    value = {
        "texture_id": texture.get("id"),
        "title": texture.get("title"),
        "source_url": texture.get("full_url"),
        "source_page_url": texture.get("source_page_url", ""),
        "highres_download_url": texture.get("highres_download_url", ""),
        "source_site": texture.get("source_site"),
        "licence": texture.get("licence", "unknown"),
        "rights_status": texture.get("rights_status", "unknown"),
        "retrieved_at": texture.get("retrieved_at"),
    }
    try:
        import texture_assets
        value["asset_ref"] = texture_assets.reference(value)
    except Exception:  # noqa: BLE001
        pass
    return value


# --- Network ----------------------------------------------------------------
def search(site_url, api_key, query="", category_id=None, page=1, per_page=40,
           rights="all", timeout=15):
    """Search the Found Textures site. Returns (textures, total)."""
    url = search_url(site_url, query, category_id, page, per_page, rights)
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


def search_catalog(site_url, api_key, query="", page=1, per_page=40,
                   rights="all", timeout=15):
    """Browse/search textures without requiring a server-side search feature.

    Found Textures currently exposes the ordinary GYSS photo catalogue.  For a
    keyword search, fetch that catalogue in large pages on the worker thread
    and filter its returned metadata locally. This keeps the feature entirely
    inside SNAP SLAPPER and works with the already-deployed site.
    """
    query = (query or "").strip()
    if not query:
        return search(site_url, api_key, page=page, per_page=per_page,
                      rights=rights, timeout=timeout)
    terms = [term.lstrip("#").casefold() for term in re.split(r"[\s,]+", query)
             if term.lstrip("#")]
    # Found Textures records its rights decision with these site hashtags.
    # The API exposes the decision as rights_status rather than returning the
    # private tag row, so translate the hashtags back into that API filter.
    rights_tags = {"certifiedrights": "clear", "unclearrights": "unclear"}
    requested_rights = [rights_tags[term] for term in terms if term in rights_tags]
    if requested_rights:
        rights = requested_rights[-1]
    terms = [term for term in terms if term not in rights_tags]
    batch_size = 500
    textures, total = search(site_url, api_key, page=1, per_page=batch_size,
                             rights=rights, timeout=timeout)
    all_textures = list(textures)
    remote_page = 2
    while len(all_textures) < total:
        batch, _total = search(site_url, api_key, page=remote_page,
                               per_page=batch_size, rights=rights,
                               timeout=timeout)
        if not batch:
            break
        all_textures.extend(batch)
        remote_page += 1
    matches = []
    for texture in all_textures:
        haystack = " ".join(str(texture.get(field) or "") for field in
                            ("title", "description", "filename", "category")).casefold()
        if all(term in haystack for term in terms):
            matches.append(texture)
    start = (max(1, int(page)) - 1) * int(per_page)
    return matches[start:start + int(per_page)], len(matches)


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


def highres_fetch_url(url):
    """Turn a public Google Drive share link into its download endpoint.

    The original share URL is still retained in provenance.  This conversion
    only prevents the downloader from caching Google's HTML viewer as though it
    were a texture image.
    """
    if not url:
        return ""
    match = re.search(r"/file/d/([A-Za-z0-9_-]+)", url)
    if not match:
        match = re.search(r"[?&]id=([A-Za-z0-9_-]+)", url)
    if match and "drive.google.com" in urllib.parse.urlparse(url).netloc.lower():
        return "https://drive.google.com/uc?export=download&id=" + match.group(1)
    return url


def cache_dir():
    try:
        import texture_assets
        directory = texture_assets.files_dir()
    except Exception:  # noqa: BLE001
        directory = os.path.join(os.path.expanduser("~"), "SnapSmack", "textures")
    os.makedirs(directory, exist_ok=True)
    return directory


def download(texture, api_key=None, timeout=30):
    """Download a texture's full image into the local cache; return the path."""
    highres = texture.get("highres_download_url") or ""
    url = highres_fetch_url(highres) if highres else texture.get("full_url")
    if not url:
        raise ValueError("Texture has no image URL")
    source_name = os.path.basename(urllib.parse.urlparse(
        texture.get("full_url") or url).path) or "texture.jpg"
    name = f"ft_{texture.get('id', 'x')}_{source_name}"
    dest = os.path.join(cache_dir(), name)
    if os.path.isfile(dest) and os.path.getsize(dest) > 0:
        try:
            import texture_assets
            texture_assets.register(provenance(texture), dest)
        except Exception:  # noqa: BLE001
            pass
        return dest                                  # already cached
    _log.info("Found Textures download: %s -> %s", url, dest)
    data = fetch_bytes(url, api_key, timeout=timeout)
    # A hostile or compromised server must not be able to park arbitrary bytes
    # in the local cache wearing a .jpg name — the editor decodes this file
    # later. Identify the bytes as a real allowed image or refuse the download.
    if snap_imgsafe is None:
        raise RuntimeError(
            "Texture refused — snap_imgsafe (shared image safety module) is "
            "not available. Reinstall/repair SNAP SLAPPER; downloads are "
            "never cached unguarded.")
    snap_imgsafe.check_bytes(data)   # raises UnsafeImageError on junk
    tmp = dest + ".part"
    with open(tmp, "wb") as handle:
        handle.write(data)
    os.replace(tmp, dest)
    try:
        import texture_assets
        texture_assets.register(provenance(texture), dest)
    except Exception:  # noqa: BLE001
        _log.exception("Could not register texture in the shared library")
    return dest

# ===== SNAPSMACK EOF =====
