# SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
"""
SmackPress — smacktalk_client.py
SnapSmack REST API client.  Creates/updates SMACKTALK posts, uploads media,
creates mosaics.  Authenticates via Bearer token (key_type='smackpress').

# ===== SNAPSMACK EOF =====
"""

from __future__ import annotations
import json
import mimetypes
import os
import urllib.request
import urllib.parse
import urllib.error
from pathlib import Path
from typing import Any

import config


class SnapError(Exception):
    pass


def _headers(extra: dict | None = None) -> dict:
    h = {
        "Authorization": f"Bearer {config.get('snap_api_key')}",
        "Accept": "application/json",
    }
    if extra:
        h.update(extra)
    return h


def _base() -> str:
    return config.get("snap_url").rstrip("/") + "/api.php/smackpress"


def _request(method: str, path: str, body: dict | None = None,
             params: dict | None = None) -> Any:
    url = _base() + path
    if params:
        url += "?" + urllib.parse.urlencode(params)
    data = json.dumps(body).encode() if body else None
    req  = urllib.request.Request(
        url, data=data,
        headers=_headers({"Content-Type": "application/json"} if body else None),
        method=method,
    )
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            result = json.loads(resp.read().decode())
    except urllib.error.HTTPError as e:
        msg = e.read().decode()
        try:
            err = json.loads(msg)
        except json.JSONDecodeError:
            raise SnapError(f"HTTP {e.code}: {msg}")
        raise SnapError(err.get("message", err.get("error", msg)))
    except urllib.error.URLError as e:
        raise SnapError(f"Connection error: {e.reason}")

    # House contract: {"status": "ok", ...flat fields}  OR
    #                 {"status": "error", "message": "..."}
    if isinstance(result, dict) and result.get("status") == "error":
        raise SnapError(result.get("message", "Unknown API error"))
    return result


# --------------------------------------------------------------------------
# Media
# --------------------------------------------------------------------------

def upload_media(filepath: str | Path, filename: str | None = None,
                 caption_from_filename: bool | None = None) -> dict:
    """
    Upload a local file to SnapSmack media library.
    Returns {asset_id, asset_url}.
    """
    filepath = Path(filepath)
    if not filename:
        filename = filepath.name
    mime = mimetypes.guess_type(filename)[0] or "application/octet-stream"

    boundary = "SmackPressBoundary"
    body = b""
    if caption_from_filename is not None:
        body += (
            f"--{boundary}\r\n"
            'Content-Disposition: form-data; name="caption_from_filename"\r\n\r\n'
            f'{"1" if caption_from_filename else "0"}\r\n'
        ).encode()
    body += (
        f"--{boundary}\r\n"
        f'Content-Disposition: form-data; name="file"; filename="{filename}"\r\n'
        f'Content-Type: {mime}\r\n'
        f"\r\n"
    ).encode()
    body += filepath.read_bytes()
    body += f"\r\n--{boundary}--\r\n".encode()

    req = urllib.request.Request(
        _base() + "/media/upload",
        data=body,
        headers=_headers({
            "Content-Type": f"multipart/form-data; boundary={boundary}",
        }),
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=120) as resp:
            result = json.loads(resp.read().decode())
    except urllib.error.HTTPError as e:
        msg = e.read().decode()
        raise SnapError(f"Media upload failed HTTP {e.code}: {msg}")
    except urllib.error.URLError as e:
        raise SnapError(f"Media upload connection error: {e.reason}")

    if result.get("status") != "ok":
        raise SnapError(result.get("message", "Unknown upload error"))
    return result   # flat: {status, image_id, asset_id, thumb, title}


def upload_media_from_url(url: str, filename: str | None = None,
                          caption_from_filename: bool | None = None) -> dict:
    """
    Download a remote image (e.g. a WordPress attachment URL) to a temp file and
    upload it into the SnapSmack GALLERY. Returns the flat upload dict (image_id…).
    """
    import tempfile
    if not url:
        raise SnapError("No image URL to download.")
    if not filename:
        filename = url.split("/")[-1].split("?")[0] or "image.jpg"
    try:
        dl = urllib.request.Request(url, headers={"User-Agent": "SmackPress/1.0"})
        with urllib.request.urlopen(dl, timeout=120) as resp:
            data = resp.read()
    except urllib.error.URLError as e:
        raise SnapError(f"Could not download {url}: {e.reason}")
    suffix = os.path.splitext(filename)[1] or ".jpg"
    with tempfile.NamedTemporaryFile(delete=False, suffix=suffix) as tmp:
        tmp.write(data)
        tmp_path = tmp.name
    try:
        return upload_media(tmp_path, filename,
                            caption_from_filename=caption_from_filename)
    finally:
        try:
            os.unlink(tmp_path)
        except OSError:
            pass


# --------------------------------------------------------------------------
# Posts
# --------------------------------------------------------------------------

def create_post(payload: dict) -> dict:
    """
    Create a new SMACKTALK post.
    Required keys: title, content_raw, date (YYYY-MM-DD).
    Optional: slug, tags (space-separated), category_id, status (draft|publish).
    Returns {post_id, post_url}.
    """
    return _request("POST", "/posts", body=payload)   # flat: {status, post_id, slug, url}


def update_post(post_id: int, payload: dict) -> dict:
    """Update an existing SMACKTALK post."""
    return _request("POST", "/posts", body={"post_id": post_id, **payload})


def get_post(post_id: int) -> dict:
    return _request("GET", f"/posts/{post_id}")


def get_categories() -> list:
    result = _request("GET", "/categories")
    return result.get("categories", [])


# --------------------------------------------------------------------------
# Pages (WordPress Pages -> SnapSmack static pages / snap_pages)
# --------------------------------------------------------------------------

def create_page(payload: dict) -> dict:
    """
    Create a new SnapSmack static page (snap_pages).
    Required: title. Optional: slug, content_raw/content, status
    (published|draft) or is_active, image_asset, image_size, image_align,
    image_shadow, menu_order.
    Returns {page_id, slug, url, is_active}.
    """
    return _request("POST", "/pages", body=payload)


def update_page(page_id: int, payload: dict) -> dict:
    """Update an existing SnapSmack static page."""
    return _request("POST", "/pages", body={"page_id": page_id, **payload})


# --------------------------------------------------------------------------
# Mosaics
# --------------------------------------------------------------------------

def create_mosaic(title: str, asset_ids: list[int], gap: int = 4) -> dict:
    """
    Create a mosaic and return {mosaic_id}.
    The caller is responsible for inserting [mosaic:ID] into post content.
    """
    return _request("POST", "/mosaics", body={
        "title":     title,
        "asset_ids": asset_ids,
        "gap":       gap,
    })   # flat: {status, mosaic_id, shortcode}

# ===== SNAPSMACK EOF =====
