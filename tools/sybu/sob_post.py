"""
SON OF A BATCH — sob_post.py
HTTP transport for the offline poster suite. Injected into the SyncEngine so
the engine itself stays headless. Nothing here is rebuilt from scratch — the
solo path reuses the exact, already-verified smack-post-solo.php contract from
SYBU's poster.py, and the gram path reuses the proven Unzucker import API
(upload -> posts -> trigram), which is the only Bearer-authenticated,
post_id-returning carousel/trigram contract the server exposes today.

Posts push via the SnapSmack API using a scoped API key (posting scope), stored
locally in the connection profile and never uploaded — consistent with the
0.7.261 Bass Ackwards least-privilege model.

Server-side items this build flags (see addendum):
  * unzucker/posts still generates 400px thumbs server-side and does NOT yet
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
from typing import List, Optional, Tuple

import requests

from sob_offline import (
    Draft, SyncResult,
    KIND_SOLO, KIND_GRAM_CAROUSEL, KIND_GRAM_SINGLE, KIND_GRAM_TRIGRAM,
    MODE_SOLO, MODE_GRAM, MODE_SMACKTALK, MODE_UNKNOWN,
)


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

class SobConnection:
    def __init__(self, base_url: str, api_key: str = "", api_path: str = "/api.php"):
        self.base_url = base_url.rstrip("/")
        self.api_path = api_path
        self.api_key = api_key
        self.session = requests.Session()
        self.session.headers.update({
            "User-Agent": "SonOfABatch/%s" % "0.1.0",
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
        carry an optional 'site_mode'); falls back to the Unzucker site route.
        An unreachable or mode-less install returns MODE_UNKNOWN with a note so
        the picker greys it out rather than hiding it.
        """
        for url in (f"{self.base_url}/sybu-data.php", self._api("unzucker/site")):
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
    def __init__(self, conn: SobConnection, site_data=None, copyright_text: str = ""):
        self.conn = conn
        self.site_data = site_data  # optional poster.SiteData for cat/album id lookup
        self.copyright_text = copyright_text

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

        files = {"img_file": (form["source_file"], open(im.local_path, "rb"), _mime(im.local_path))}
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
    def __init__(self, conn: SobConnection):
        self.conn = conn

    def _upload_image(self, im) -> dict:
        """POST one JPEG + its client thumbs to unzucker/gram/upload. Client
        thumbs are mandatory — the server saves them and skips its GD pass.
        Returns {path, thumb_square, thumb_aspect, width, height}."""
        opened = []
        try:
            fh = open(im.local_path, "rb"); opened.append(fh)
            files = {"image": (os.path.basename(im.local_path), fh, _mime(im.local_path))}
            if im.thumb_square and os.path.isfile(im.thumb_square):
                t = open(im.thumb_square, "rb"); opened.append(t)
                files["thumb_square"] = (os.path.basename(im.thumb_square), t, "image/jpeg")
            if im.thumb_aspect and os.path.isfile(im.thumb_aspect):
                a = open(im.thumb_aspect, "rb"); opened.append(a)
                files["thumb_aspect"] = (os.path.basename(im.thumb_aspect), a, "image/jpeg")
            r = self.conn.session.post(self.conn._api("unzucker/gram/upload"),
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
            r = self.conn.session.post(self.conn._api("unzucker/gram/post"),
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
        r = self.conn.session.post(self.conn._api("unzucker/trigram"),
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
            r = self.conn.session.get(self.conn._api("unzucker/gram/verify"),
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
# Verification helpers — best-effort pull-back via smack-audit.php.
# ---------------------------------------------------------------------------

def _audit_list(conn: SobConnection):
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


def _verify_by_post_id(conn: SobConnection, post_id: int):
    """True/False if the audit list is available; None if it isn't."""
    posts = _audit_list(conn)
    if posts is None:
        return None
    return any(int(p.get("id", 0)) == int(post_id) for p in posts)


def _verify_by_title(conn: SobConnection, title: str) -> bool:
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
