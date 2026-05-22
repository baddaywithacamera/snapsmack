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
            return json.loads(resp.read().decode())
    except urllib.error.HTTPError as e:
        msg = e.read().decode()
        try:
            err = json.loads(msg)
            raise SnapError(err.get("error", msg))
        except (json.JSONDecodeError, KeyError):
            raise SnapError(f"HTTP {e.code}: {msg}")
    except urllib.error.URLError as e:
        raise SnapError(f"Connection error: {e.reason}")


# --------------------------------------------------------------------------
# Media
# --------------------------------------------------------------------------

def upload_media(filepath: str | Path, filename: str | None = None) -> dict:
    """
    Upload a local file to SnapSmack media library.
    Returns {asset_id, asset_url}.
    """
    filepath = Path(filepath)
    if not filename:
        filename = filepath.name
    mime = mimetypes.guess_type(filename)[0] or "application/octet-stream"

    boundary = "SmackPressBoundary"
    body  = (
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

    if not result.get("success"):
        raise SnapError(result.get("error", "Unknown upload error"))
    return result["data"]


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
    result = _request("POST", "/posts", body=payload)
    if not result.get("success"):
        raise SnapError(result.get("error", "Unknown error creating post"))
    return result["data"]


def update_post(post_id: int, payload: dict) -> dict:
    """Update an existing SMACKTALK post."""
    result = _request("POST", "/posts", body={"post_id": post_id, **payload})
    if not result.get("success"):
        raise SnapError(result.get("error", "Unknown error updating post"))
    return result["data"]


def get_post(post_id: int) -> dict:
    result = _request("GET", f"/posts/{post_id}")
    if not result.get("success"):
        raise SnapError(result.get("error", "Post not found"))
    return result["data"]


def get_categories() -> list:
    result = _request("GET", "/categories")
    if not result.get("success"):
        raise SnapError(result.get("error", "Could not fetch categories"))
    return result["data"]


# --------------------------------------------------------------------------
# Mosaics
# --------------------------------------------------------------------------

def create_mosaic(title: str, asset_ids: list[int], gap: int = 4) -> dict:
    """
    Create a mosaic and return {mosaic_id}.
    The caller is responsible for inserting [mosaic:ID] into post content.
    """
    result = _request("POST", "/mosaics", body={
        "title":     title,
        "asset_ids": asset_ids,
        "gap":       gap,
    })
    if not result.get("success"):
        raise SnapError(result.get("error", "Could not create mosaic"))
    return result["data"]

# ===== SNAPSMACK EOF =====
