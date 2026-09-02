"""
COLD SNAP — sumna_post.py
HTTP transport for the offline poster suite. Injected into the SyncEngine so
the engine itself stays headless. Nothing here is rebuilt from scratch — the
solo path reuses the exact, already-verified smack-post-solo.php contract from
SYBU's poster.py, and the gram path reuses the proven shared three-across
carousel-write API (formerly 'unzucker'), which is the only Bearer-authenticated,
post_id-returning carousel/trigram contract the server exposes today.

Posts push via the SnapSmack API using a scoped API key (posting scope), stored
locally in the connection profile and never uploaded — consistent with the
0.7.261 Bass Ackwards least-privilege model.

Server-side items this build flags (see addendum):
  * threeacross/posts still generates 400px thumbs server-side and does NOT yet
    consume the client 300²/600px thumbs (the 0.7.305 "skip-GD" wiring is not
    in this checkout). We send client thumbs anyway as extra multipart parts so
    they are used the moment the server accepts them — harmless until then.
  * sybu-data.php does not yet return site_mode; until it does, the probe
    reports MODE_UNKNOWN and the install shows greyed with a note (spec-faithful).
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import os
import re
import sys
from typing import List, Optional, Tuple

import requests

from sumna_offline import (
    Draft, SyncResult,
    KIND_SOLO, KIND_GRAM_CAROUSEL, KIND_GRAM_SINGLE, KIND_GRAM_TRIGRAM,
    MODE_SOLO, MODE_GRAM, MODE_SMACKTALK, MODE_UNKNOWN,
)
import sumna_resize

# Canonical per-site settings contract. Bundled flat next to this module on the
# frozen exe, one dir up under _shared/ in the dev tree (same shim sumna_resize uses).
try:
    import snap_site_settings
except ImportError:  # pragma: no cover - dev-tree import shim
    _shared = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), '_shared')
    if _shared not in sys.path:
        sys.path.insert(0, _shared)
    import snap_site_settings

# Shared offline content library (producer/consumer store). Optional: recording a
# post into it is best-effort and must never break a successful post, so a missing
# module just disables the local mirror.
try:
    import snap_library
except Exception:  # pragma: no cover - optional shared dependency
    snap_library = None


# Export sizing policy. The fleet standard Sean set is a 3840 long-edge cap plus a
# mild sharpen on downsize (SPEC-image-sizing-4k-coldsnap-gyss.md §9/§10). COLD SNAP
# sizes to this BEFORE upload so what you post is what gets stored and the sharpen
# lands on the final pixels; the photographer's original on disk is never touched.
#
# The number is no longer hard-coded here: it comes from the canonical per-site
# settings contract (snap_site_settings.max_long_edge). A poster resolves its
# destination's policy once via _export_policy() and every image it sends uses it.
# When a site has no portable settings mirrored yet, validate_portable() returns the
# fleet default (3840 / q85 / sharpen-on-downsize) — so behaviour is unchanged until
# the hub actually pushes a per-site override, then it flows through with no code
# change. This is the seam the shared portable mirror was always meant to fill.
EXPORT_MAX_LONG_EDGE = snap_site_settings.DEFAULT_MAX_LONG_EDGE  # fleet default (3840)


def _export_policy(portable=None) -> dict:
    """Resolve the (max_long_edge, jpeg_quality, sharpen) COLD SNAP should export
    at for one destination, from the canonical settings contract.

    `portable` is the destination site's portable settings dict (or None for the
    fleet default). RESIZE OFF (image_resize_enabled=False) disables sizing by
    setting the long edge to 0, which export_path() treats as "upload original".
    export_sharpen "off" disables the mild sharpen; every other value keeps it
    (mild-v1, applied only on a real downsize by snap_sizing)."""
    p = snap_site_settings.validate_portable(portable or {})
    long_edge = p["max_long_edge"] if p["image_resize_enabled"] else 0
    return {
        "max_long_edge": long_edge,
        "jpeg_quality": p["jpeg_quality"],
        "sharpen": p["export_sharpen"] != "off",
    }


def _portable_from_site_data(site_data) -> dict:
    """Best-effort extraction of a portable settings dict from COLD SNAP's per-site
    data, tolerant of shape. Returns only keys the contract knows (so an unrelated
    field never trips validate_portable's unknown-key guard); {} means fleet default."""
    try:
        if not isinstance(site_data, dict):
            return {}
        src = site_data.get("portable") if isinstance(site_data.get("portable"), dict) else site_data
        known = set(snap_site_settings.PORTABLE_DEFAULTS)
        return {k: src[k] for k in known if k in src}
    except Exception:
        return {}


def _upload_ready(local_path: str, policy: dict = None) -> str:
    """Path to the size-capped, mild-sharpened derivative for upload, or the
    original path when it's already within policy. Never modifies the original;
    falls back to the original on any resize error so a post is never blocked."""
    policy = policy or _export_policy()
    return sumna_resize.export_path(
        local_path, policy["max_long_edge"],
        jpeg_quality=policy["jpeg_quality"], sharpen=policy["sharpen"])


def _mime(path: str) -> str:
    ext = os.path.splitext(path)[1].lower()
    return {
        ".jpg": "image/jpeg", ".jpeg": "image/jpeg",
        ".png": "image/png", ".gif": "image/gif", ".webp": "image/webp",
    }.get(ext, "image/jpeg")


def _resp_msg(r, default: str) -> str:
    """Surface the server's JSON error message (consent gate / rate limit / etc.)
    so the user sees 'Offline posting is not enabled…' rather than a generic note."""
    try:
        m = (r.json() or {}).get("message")
        if m:
            return str(m)
    except Exception:
        pass
    return default


# ---------------------------------------------------------------------------
# Connection — one Bearer session shared by solo + gram, matching SYBU.
# ---------------------------------------------------------------------------

class SumnaConnection:
    def __init__(self, base_url: str, api_key: str = "", api_path: str = "/api.php"):
        self.base_url = base_url.rstrip("/")
        self.api_path = api_path
        self.api_key = api_key
        self.session = requests.Session()
        self.session.headers.update({
            "User-Agent": "ColdSnap/%s" % "0.1.0",
            "Authorization": f"Bearer {api_key}",
            # Opt into smack-post-solo.php's deterministic AJAX reply ("success").
            "X-Requested-With": "XMLHttpRequest",
        })

    def _api(self, route: str) -> str:
        return f"{self.base_url}{self.api_path}?route={route}"

    # -- site mode probe ----------------------------------------------------
    def probe_site_mode(self, timeout: int = 12) -> Tuple[str, bool, str]:
        """
        Return (site_mode, reachable, note). Tries sybu-data.php first (it can
        carry an optional 'site_mode'); falls back to the three-across site route.
        An unreachable or mode-less install returns MODE_UNKNOWN with a note so
        the picker greys it out rather than hiding it.
        """
        for url in (f"{self.base_url}/sybu-data.php", self._api("threeacross/site")):
            try:
                r = self.session.get(url, timeout=timeout)
            except requests.RequestException as e:
                last = f"unreachable: {e}"
                continue
            if r.status_code in (401, 403):
                return MODE_UNKNOWN, True, "API key rejected for mode check"
            if r.status_code != 200:
                last = f"HTTP {r.status_code}"
                continue
            try:
                data = r.json()
            except ValueError:
                last = "non-JSON response"
                continue
            mode = (data.get("site_mode") or data.get("mode") or "").strip().lower()
            if mode in (MODE_SOLO, MODE_GRAM, MODE_SMACKTALK):
                return mode, True, ""
            return MODE_UNKNOWN, True, "couldn't verify mode (server didn't report site_mode)"
        return MODE_UNKNOWN, False, locals().get("last", "unreachable")


# ---------------------------------------------------------------------------
# SoloPoster — BATCH SLAPPED. Reuses the smack-post-solo.php multipart contract.
# ---------------------------------------------------------------------------

class SoloPoster:
    def __init__(self, conn: SumnaConnection, site_data=None, copyright_text: str = ""):
        self.conn = conn
        self.site_data = site_data  # optional poster.SiteData for cat/album id lookup
        self.copyright_text = copyright_text
        # Resolve the destination's export sizing policy once (fleet default until
        # the site's portable settings are mirrored — see _export_policy).
        self.policy = _export_policy(_portable_from_site_data(site_data))

    def _resolve_ids(self, draft: Draft) -> Tuple[Optional[int], Optional[int]]:
        cat_id = album_id = None
        if self.site_data is not None:
            if draft.category:
                cat_id = self.site_data.categories.get(draft.category.lower())
            if draft.album:
                album_id = self.site_data.albums.get(draft.album.lower())
        return cat_id, album_id

    def sync_solo(self, draft: Draft) -> SyncResult:
        im = draft.cover()
        if im is None or not os.path.isfile(im.local_path):
            return SyncResult(False, message="no image on disk")

        cat_id, album_id = self._resolve_ids(draft)
        post_tags = draft.tags
        if draft.ai_colors:
            post_tags = f"{post_tags} {draft.ai_colors}".strip()

        form = {
            "title":                draft.title,
            "tags":                 post_tags,
            "img_status":           draft.img_status,
            "desc":                 draft.caption or self.copyright_text,
            "allow_download":       "1" if (draft.allow_download and draft.download_url) else "0",
            "download_url":         draft.download_url,
            "orientation_override": draft.orientation or "auto",
            "source_file":          im.filename or os.path.basename(im.local_path),
            "img_ai_colors":        draft.ai_colors,
        }
        if draft.post_date:
            form["img_date"] = draft.post_date
        if cat_id is not None:
            form["cat_ids[]"] = str(cat_id)
        if album_id is not None:
            form["album_ids[]"] = str(album_id)

        _up = _upload_ready(im.local_path, self.policy)  # size-cap + mild sharpen; original untouched
        files = {"img_file": (form["source_file"], open(_up, "rb"), _mime(_up))}
        # Forward client thumbs so the server can skip its GD pass once wired.
        _opened = [files["img_file"][1]]
        if im.thumb_square and os.path.isfile(im.thumb_square):
            fh = open(im.thumb_square, "rb"); _opened.append(fh)
            files["thumb_square"] = (os.path.basename(im.thumb_square), fh, "image/jpeg")
        if im.thumb_aspect and os.path.isfile(im.thumb_aspect):
            fh = open(im.thumb_aspect, "rb"); _opened.append(fh)
            files["thumb_aspect"] = (os.path.basename(im.thumb_aspect), fh, "image/jpeg")

        try:
            resp = self.conn.session.post(
                f"{self.conn.base_url}/smack-post-solo.php",
                data=form, files=files, timeout=120,
            )
            resp.raise_for_status()
        except requests.RequestException as e:
            return SyncResult(False, message=f"network error: {e}")
        finally:
            for fh in _opened:
                try:
                    fh.close()
                except Exception:
                    pass

        body = (resp.text or "").strip()
        confirmed = (body == "success"
                     or "TRANSMISSION_LIVE" in (resp.url or "")
                     or "TRANSMISSION_LIVE" in body)
        if not confirmed:
            return SyncResult(False, message=_server_reason(body))
        return SyncResult(True, message="Posted")

    # Positive verification — pull the live post back and confirm it exists.
    def verify(self, draft: Draft) -> bool:
        return _verify_by_title(self.conn, draft.title)


# ---------------------------------------------------------------------------
# GramPoster — BATCH, PLEASE. Reuses the Unzucker upload/posts/trigram API.
# ---------------------------------------------------------------------------

class GramPoster:
    def __init__(self, conn: SumnaConnection, site_data=None):
        self.conn = conn
        # Same destination-aware sizing policy as solo posts.
        self.policy = _export_policy(_portable_from_site_data(site_data))

    def _upload_image(self, im) -> dict:
        """POST one JPEG + its client thumbs to threeacross/gram/upload. Client
        thumbs are mandatory — the server saves them and skips its GD pass.
        Returns {path, thumb_square, thumb_aspect, width, height}."""
        opened = []
        try:
            _up = _upload_ready(im.local_path, self.policy)  # size-cap + mild sharpen; original untouched
            fh = open(_up, "rb"); opened.append(fh)
            files = {"image": (os.path.basename(im.local_path), fh, _mime(_up))}
            if im.thumb_square and os.path.isfile(im.thumb_square):
                t = open(im.thumb_square, "rb"); opened.append(t)
                files["thumb_square"] = (os.path.basename(im.thumb_square), t, "image/jpeg")
            if im.thumb_aspect and os.path.isfile(im.thumb_aspect):
                a = open(im.thumb_aspect, "rb"); opened.append(a)
                files["thumb_aspect"] = (os.path.basename(im.thumb_aspect), a, "image/jpeg")
            r = self.conn.session.post(self.conn._api("threeacross/gram/upload"),
                                       files=files, timeout=120)
        finally:
            for f in opened:
                try:
                    f.close()
                except Exception:
                    pass
        if r.status_code in (401, 403, 429):
            raise RuntimeError(_resp_msg(r, "Image upload rejected (key scope / consent / rate limit)."))
        r.raise_for_status()
        data = r.json()
        if data.get("status") != "ok" or not data.get("path"):
            raise RuntimeError(data.get("error", "upload failed (no path returned)"))
        return data

    @staticmethod
    def _img_controls(im) -> dict:
        """Serialize one image's full control set (1:1 with snap_post_images)."""
        return {
            "path": im.remote_path,
            "thumb_square": im.remote_thumb_square,
            "thumb_aspect": im.remote_thumb_aspect,
            "width": im.width, "height": im.height,
            "crop_mode": im.crop_mode, "size_pct": im.size_pct,
            "border_px": im.border_px, "border_color": im.border_color,
            "bg_color": im.bg_color, "shadow": im.shadow,
            "focus_x": im.focus_x, "focus_y": im.focus_y, "zoom": im.zoom,
            "is_cover": im.is_cover, "sort_position": im.sort_position,
            "split": im.split,
        }

    def sync_gram(self, draft: Draft) -> SyncResult:
        if not draft.images:
            return SyncResult(False, message="no images on draft")
        try:
            images_payload = []
            for im in draft.images:
                if not os.path.isfile(im.local_path):
                    return SyncResult(False, message=f"image missing: {im.local_path}")
                up = self._upload_image(im)
                im.remote_path = up.get("path", "")
                im.remote_thumb_square = up.get("thumb_square", "")
                im.remote_thumb_aspect = up.get("thumb_aspect", "")
                if not im.width:
                    im.width = int(up.get("width", 0) or 0)
                if not im.height:
                    im.height = int(up.get("height", 0) or 0)
                images_payload.append(self._img_controls(im))

            payload = {
                "title": draft.title,
                "body": draft.caption,
                "post_date": draft.post_date or None,
                "status": draft.img_status,
                "post_type": draft.post_type or "",
                "panorama_rows": draft.panorama_rows,
                "allow_comments": 1 if draft.allow_comments else 0,
                "allow_download": 1 if draft.allow_download else 0,
                "download_url": draft.download_url,
                "images": images_payload,
                "tags": [t.lstrip("#") for t in draft.tags.split() if t.strip()],
            }
            r = self.conn.session.post(self.conn._api("threeacross/gram/post"),
                                       json=payload, timeout=120)
            if r.status_code in (401, 403, 429):
                return SyncResult(False, message=_resp_msg(r, "Post create rejected (key scope / consent / rate limit)."))
            r.raise_for_status()
            data = r.json()
        except requests.RequestException as e:
            return SyncResult(False, message=f"network error: {e}")
        except Exception as e:
            return SyncResult(False, message=str(e))

        post_id   = int(data.get("post_id") or 0)
        split_ids = [int(x) for x in (data.get("split_post_ids") or [])]
        # A draft whose images are ALL marked "post separately" produces no
        # grouped post (post_id == 0) — the split singles ARE the result. Treat
        # that as success instead of a false failure (and avoid a dup on retry).
        if data.get("status") != "ok" or (not post_id and not split_ids):
            return SyncResult(False, message=data.get("error", "server did not confirm the post"))
        # Stash the fanned-out post ids so verify() can confirm each one.
        draft._split_post_ids = split_ids
        return SyncResult(True, remote_post_id=(post_id or split_ids[0]), message="Posted")

    def link_trigram(self, post_ids: List[int], orientation: str = "h") -> int:
        payload = {
            "post_id_1": post_ids[0],
            "post_id_2": post_ids[1],
            "post_id_3": post_ids[2],
            "orientation": orientation if orientation in ("h", "v") else "h",
        }
        r = self.conn.session.post(self.conn._api("threeacross/trigram"),
                                   json=payload, timeout=60)
        if r.status_code in (401, 403, 429):
            raise RuntimeError(_resp_msg(r, "Trigram link rejected (key scope / consent / rate limit)."))
        r.raise_for_status()
        data = r.json()
        if data.get("status") != "ok" or not data.get("trigram_id"):
            raise RuntimeError(data.get("error", "trigram link failed"))
        return int(data["trigram_id"])

    # Positive verification — the create call returns an explicit {status:'ok',
    # post_id}; we treat that server confirmation as the verify (and best-effort
    # pull the post back when an audit read is in scope).
    def _verify_one(self, post_id: int, expect_images: int) -> bool:
        """Pull one live post back and confirm it exists with the expected image
        count. Falls back to the audit list, then to trusting the explicit
        server 'ok' rather than manufacturing a false failure."""
        try:
            r = self.conn.session.get(self.conn._api("threeacross/gram/verify"),
                                      params={"post_id": post_id}, timeout=20)
            if r.status_code == 200:
                data = r.json()
                if data.get("status") == "ok":
                    return int(data.get("image_count", -1)) == expect_images
            if r.status_code == 404:
                return False  # server says the post isn't there — real failure
        except requests.RequestException:
            pass
        # Fallback: audit list, else trust the explicit server 'ok' from create
        # rather than manufacturing a false failure.
        ok = _verify_by_post_id(self.conn, post_id)
        return True if ok is None else ok

    def verify(self, draft: Draft) -> bool:
        if not draft.remote_post_id:
            return False
        # Split images each became their own single-image post; confirm every
        # one. A grouped post (when present alongside splits) holds the rest.
        split_ids = getattr(draft, "_split_post_ids", None) or []
        if split_ids:
            results = [self._verify_one(pid, 1) for pid in split_ids]
            grouped_count = len(draft.images) - len(split_ids)
            if draft.remote_post_id not in split_ids and grouped_count > 0:
                results.append(self._verify_one(draft.remote_post_id, grouped_count))
            return all(results)
        return self._verify_one(draft.remote_post_id, len(draft.images))


# ---------------------------------------------------------------------------
# SmacktalkPoster — SMACKTALK longform post + image BUCKET.
# ---------------------------------------------------------------------------
#
# Posts through the SMACKPRESS JSON API (api.php?route=smackpress/...), the ONE
# server contract that creates a post_type='longform' post and fills its bucket
# (snap_bucket_items) with no site_mode guard. Confirmed contract:
#   1. per photo -> POST smackpress/media/upload (multipart field 'file') -> image_id
#   2. one post  -> POST smackpress/posts {title, content, status,
#                   featured_image_id, bucket_image_ids:[...]} -> post_id (+ bucket)
#
# AUTH: smackpress/* require a key_type='smackpress' Bearer key. COLD SNAP's normal
# 'sybu' key is rejected (401) by design, so this poster carries its OWN key —
# issued from smack-api-keys.php and stored in the profile as `smackpress_key`.
# Images are size-capped + mild-sharpened to the fleet standard before upload
# (same _upload_ready as solo/gram); the photographer's original is never modified.

class SmacktalkPoster:
    def __init__(self, base_url: str, smackpress_key: str, site_data=None, session=None):
        self.base_url = (base_url or "").rstrip("/")
        self.key = (smackpress_key or "").strip()
        self.session = session or requests.Session()
        if session is None:
            self.session.headers.update({
                "User-Agent": "ColdSnap/%s" % "0.1.0",
                "Authorization": f"Bearer {self.key}",
                "X-Requested-With": "XMLHttpRequest",
            })
        # Same destination-aware sizing policy as solo/gram (per-site max_long_edge,
        # falling back to the fleet default). Resolved once per poster.
        self.policy = _export_policy(_portable_from_site_data(site_data))

    def _route(self, route: str) -> str:
        return f"{self.base_url}/api.php?route={route}"

    def _upload_one(self, im) -> int:
        """Upload one photo to the Gallery via smackpress/media/upload and return its
        snap_images id. Sizes/sharpens to the site's policy first; original untouched."""
        up = _upload_ready(im.local_path, self.policy)
        fh = open(up, "rb")
        try:
            files = {"file": (im.filename or os.path.basename(im.local_path), fh, _mime(up))}
            r = self.session.post(self._route("smackpress/media/upload"), files=files, timeout=120)
        finally:
            fh.close()
        if r.status_code in (401, 403):
            raise RuntimeError(_resp_msg(
                r, "Upload rejected — this site's SMACKTALK key must be a 'smackpress' key."))
        r.raise_for_status()
        data = r.json()
        image_id = int(data.get("image_id") or data.get("asset_id") or 0)
        if image_id <= 0:
            raise RuntimeError("media/upload did not return an image id")
        return image_id

    def build_payload(self, draft, image_ids, cover_id, content=None) -> dict:
        """The smackpress/posts JSON body. Split out so it's unit-testable without a
        network round-trip. Cover leads the bucket server-side (position 0).
        `content` overrides draft.caption once mosaic placeholders are resolved."""
        payload = {
            "title": draft.title,
            "content": (content if content is not None else (draft.caption or "")),
            "status": "published" if draft.img_status == "published" else "draft",
            "featured_image_id": cover_id,
            "bucket_image_ids": list(image_ids),
            "tags": " ".join(t.lstrip("#") for t in (draft.tags or "").split() if t.strip()),
        }
        if draft.post_date:
            payload["date"] = draft.post_date
        return payload

    # A [mosaic] placeholder (optionally [mosaic:bucket]/[mosaic:new]/[mosaic:auto])
    # means "build an inline gallery from THIS essay's photos here." A numeric
    # [mosaic:123] the author typed points at an existing panel and is left alone.
    _MOSAIC_TOKEN = re.compile(r'\[mosaic(?::\s*(?:bucket|new|auto)\s*)?\]', re.I)

    def create_mosaic(self, image_ids, title="Mosaic", gap=4) -> Tuple[int, str]:
        """Create a snap_mosaics panel from ordered Gallery image ids via
        POST smackpress/mosaics. Returns (mosaic_id, '[mosaic:ID]')."""
        r = self.session.post(
            self._route("smackpress/mosaics"),
            json={"title": title or "Mosaic", "asset_ids": [int(i) for i in image_ids],
                  "gap": max(0, min(20, int(gap or 4)))},
            timeout=60)
        if r.status_code in (401, 403):
            raise RuntimeError(_resp_msg(
                r, "Mosaic create rejected — this site's SMACKTALK key must be a 'smackpress' key."))
        r.raise_for_status()
        data = r.json()
        mid = int(data.get("mosaic_id") or 0)
        if mid <= 0:
            raise RuntimeError("mosaics endpoint did not return a mosaic_id")
        return mid, data.get("shortcode") or ("[mosaic:%d]" % mid)

    def _resolve_mosaics(self, content, image_ids, draft) -> Tuple[str, list]:
        """Turn a [mosaic] placeholder in the body into a real [mosaic:ID] gallery of
        the essay's just-uploaded photos. One mosaic is created and reused for every
        placeholder in the body. Numeric [mosaic:123] tokens are untouched. Returns
        (resolved_content, [mosaic_id])."""
        content = content or ""
        if not image_ids or not self._MOSAIC_TOKEN.search(content):
            return content, []
        gap = int(getattr(draft, "mosaic_gap", 4) or 4)
        mid, shortcode = self.create_mosaic(image_ids, title=(draft.title or "Mosaic"), gap=gap)
        return self._MOSAIC_TOKEN.sub(lambda _m: shortcode, content), [mid]

    def _record_to_library(self, draft, post_id, content, server_data) -> None:
        """Producer contract: on post-success, mirror what we posted into the shared
        offline library so other tools compose against it. Best-effort — never let a
        library hiccup turn a live post into a reported failure."""
        if snap_library is None:
            return
        try:
            site = self.base_url
            assets = []
            for im in draft.images:
                try:
                    if not os.path.isfile(im.local_path):
                        continue
                    a = snap_library.store_media(site, im.local_path,
                                                 orig_name=(getattr(im, "filename", "") or ""))
                    a["alt"] = getattr(im, "alt", "") or ""
                    assets.append(a)
                except Exception:
                    continue
            snap_library.record_post(site, {
                "post_id":     post_id,
                "site_mode":   "smacktalk",
                "post_type":   "long",
                "title":       draft.title,
                "body":        content,
                "permalink":   (server_data or {}).get("permalink", "")
                               or (server_data or {}).get("url", ""),
                "tags":        [t.lstrip("#") for t in (draft.tags or "").split() if t.strip()],
                "source_tool": "coldsnap",
            }, assets=assets)
        except Exception:
            pass

    def sync_smacktalk(self, draft) -> SyncResult:
        if not self.key:
            return SyncResult(False, message="No SMACKTALK key set for this site (needs a 'smackpress' API key).")
        if not draft.images:
            return SyncResult(False, message="no images on draft")
        if not (draft.title or "").strip():
            return SyncResult(False, message="a SMACKTALK post needs a title")
        try:
            image_ids, cover_id = [], None
            for im in draft.images:
                if not os.path.isfile(im.local_path):
                    return SyncResult(False, message=f"image missing: {im.local_path}")
                iid = self._upload_one(im)
                image_ids.append(iid)
                if im.is_cover and cover_id is None:
                    cover_id = iid
            if cover_id is None and image_ids:
                cover_id = image_ids[0]

            # MOSAIC: a [mosaic] placeholder in the body becomes an inline justified
            # gallery of this essay's photos (created from the just-uploaded Gallery
            # ids). The photographer writes text + [mosaic]; the render is a real
            # tiled gallery in place. No server change — smackpress/mosaics exists.
            content = self._resolve_mosaics(draft.caption or "", image_ids, draft)[0]

            r = self.session.post(self._route("smackpress/posts"),
                                  json=self.build_payload(draft, image_ids, cover_id, content=content),
                                  timeout=120)
            if r.status_code in (401, 403, 429):
                return SyncResult(False, message=_resp_msg(
                    r, "Post rejected (key scope / rate limit). SMACKTALK needs a 'smackpress' key."))
            r.raise_for_status()
            data = r.json()
        except requests.RequestException as e:
            return SyncResult(False, message=f"network error: {e}")
        except Exception as e:
            return SyncResult(False, message=str(e))

        post_id = int(data.get("post_id") or 0)
        if not post_id:
            return SyncResult(False, message=data.get("error") or "server did not confirm the post")
        # Producer: mirror the finished post + its originals into the shared library.
        self._record_to_library(draft, post_id, content, data)
        return SyncResult(True, remote_post_id=post_id, message="Posted")

    def verify(self, draft) -> bool:
        """Best-effort: pull the post back via GET smackpress/posts/{id}. Trust the
        create's explicit post_id if the read isn't reachable, rather than a false fail."""
        pid = getattr(draft, "remote_post_id", 0)
        if not pid:
            return False
        try:
            r = self.session.get(self._route(f"smackpress/posts/{int(pid)}"), timeout=20)
            if r.status_code == 200:
                return True
            if r.status_code == 404:
                return False
        except requests.RequestException:
            pass
        return True  # created ok; read path just unavailable


# ---------------------------------------------------------------------------
# Batch runner — POST-tab manifest entries -> single grams (carousel sites)
# ---------------------------------------------------------------------------

def run_gram_batch(
    conn: "SumnaConnection",
    entries,
    image_folder: str,
    on_progress=None,
    cancel_event=None,
):
    """Post each selected POST-tab manifest entry as its OWN single gram via the
    threeacross/gram API. The gram counterpart of poster.run_batch: it reuses the
    SAME enriched batch table — the Gemini caption + hashtags already live on
    entry.caption / entry.tags — and only changes the destination, sending single
    grams to a carousel (GRAMOFSMACK) site instead of solo posts to the photoblog
    endpoint that 409s there. One image per draft, split=False => the server makes
    a post_type='single' post. Returns a list of SyncResult; never raises."""
    from sumna_offline import DraftImage  # local import avoids a top-level cycle
    poster = GramPoster(conn)
    results = []
    total = len(entries)
    for i, entry in enumerate(entries, start=1):
        if cancel_event is not None and cancel_event.is_set():
            break
        local_path = os.path.join(image_folder, getattr(entry, "file", ""))
        if not os.path.isfile(local_path):
            res = SyncResult(False, message=f"image not found: {local_path}")
        else:
            di = DraftImage(local_path=local_path,
                            filename=os.path.basename(local_path))
            draft = Draft(
                draft_id=f"posttab-{i}",
                kind=KIND_GRAM_SINGLE,
                mode=MODE_GRAM,
                title=(getattr(entry, "title", "") or ""),
                caption=(getattr(entry, "caption", "") or "").strip(),
                tags=(getattr(entry, "tags", "") or "").strip(),
                post_type="single",
                images=[di],
            )
            res = poster.sync_gram(draft)
        results.append(res)
        if on_progress is not None:
            on_progress(i, total, res)
    return results


# ---------------------------------------------------------------------------
# Verification helpers — best-effort pull-back via smack-audit.php.
# ---------------------------------------------------------------------------

def _audit_list(conn: SumnaConnection):
    """Return the published-post list, or None if the read is unavailable/out of scope."""
    try:
        r = conn.session.get(f"{conn.base_url}/smack-audit.php",
                             params={"action": "list"}, timeout=(10, 120))
    except requests.RequestException:
        return None
    if r.status_code in (401, 403):
        return None
    if r.status_code != 200:
        return None
    try:
        data = r.json()
    except ValueError:
        return None
    if not data.get("ok"):
        return None
    return data.get("posts", [])


def _verify_by_post_id(conn: SumnaConnection, post_id: int):
    """True/False if the audit list is available; None if it isn't."""
    posts = _audit_list(conn)
    if posts is None:
        return None
    return any(int(p.get("id", 0)) == int(post_id) for p in posts)


def _verify_by_title(conn: SumnaConnection, title: str) -> bool:
    posts = _audit_list(conn)
    if posts is None:
        # Audit unavailable — solo create was already body-confirmed ("success").
        return True
    t = (title or "").strip().lower()
    if not t:
        return True
    return any((p.get("title") or "").strip().lower() == t for p in posts)


def _server_reason(body: str) -> str:
    import re
    if not body:
        return "empty response"
    low = body.lower()
    if "download url is required" in low:
        return "site requires a download link for published posts, but none was set"
    if "<form" in low or "initialize new transmission" in low:
        return "validation failed — server re-rendered the post form"
    if any(w in low for w in ("login", "password", "sign in", "unauthorized")):
        return "session/login page returned — API key may be invalid or expired"
    text = re.sub(r"<[^>]+>", " ", body)
    text = re.sub(r"\s+", " ", text).strip()
    return (text[:160] + "…") if len(text) > 160 else (text or "unrecognised response")
# ===== SNAPSMACK EOF =====
