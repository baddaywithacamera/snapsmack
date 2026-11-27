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


import html
from itertools import groupby
import logging
import os
import re
import sys
from typing import List, Optional, Tuple

import requests

_log = logging.getLogger("coldsnap.library")

from sumna_offline import (
    Draft, SyncResult,
    KIND_SOLO, KIND_GRAM_CAROUSEL, KIND_GRAM_SINGLE, KIND_GRAM_TRIGRAM,
    MODE_SOLO, MODE_GRAM, MODE_SMACKTALK, MODE_UNKNOWN,
)
import sumna_resize


def _gap_value(raw):
    """The mosaic gap, with ZERO meaning zero, clamped to the column's range.

    THE PUBLISH PATH COLLAPSED A SAVED 0 TO 4, in two places -- `int(gap or 4)`
    and `int(getattr(draft, "mosaic_gap", 4) or 4)` -- because `0 or 4` is 4.
    Gap 0 is a real choice: snap_mosaics.gap allows 0 to 20, core/parser.php
    clamps to the same range, and ss-engine-mosaic.js honours it. It is the
    value a flush grid of photographs needs, and COLD SNAP could not send it.

    Found while fixing the same expression in the BIGGIE preview
    (coldsnap_qt/canvas.py:_mosaic_gap). A fault in one place on a shared value
    means checking every place that reads it, and this is the layer that
    decides what the SITE stores -- the preview only decides what Sean sees.
    The two copies are deliberate: the transport must not import the Qt
    package, and each names the other.

    Only a missing, blank or unparseable value falls back to 4, the schema
    default. Unlike the preview, this one clamps, because it is what leaves the
    machine and the site clamps it anyway.
    """
    if raw is None or raw == "":
        return 4
    try:
        return max(0, min(20, int(float(raw))))
    except (TypeError, ValueError):
        return 4

try:
    import snap_site_scope   # X-Snap-Site header (mutual-auth A1, SECAUDIT 054)
except Exception:  # noqa: BLE001
    # tools/_shared may not be on sys.path yet at this point in the file (each
    # tool adds it at a different spot). Find it from here; frozen exes bundle
    # it next to the entry script.
    import os as _sso, sys as _sss
    _d = _sso.path.dirname(_sso.path.abspath(__file__))
    for _up in range(4):
        _cand = _sso.path.join(_d, "_shared")
        if _sso.path.isdir(_cand):
            if _cand not in _sss.path:
                _sss.path.insert(0, _cand)
            break
        _d = _sso.path.dirname(_d)
    try:
        import snap_site_scope
    except Exception:  # noqa: BLE001
        snap_site_scope = None


def _site_scope(site_url):
    return snap_site_scope.header(site_url) if snap_site_scope else {}


# Canonical per-site settings contract. Bundled flat next to this module on the
# frozen exe, one dir up under _shared/ in the dev tree (same shim sumna_resize uses).
try:
    import snap_site_settings
except ImportError:  # pragma: no cover - dev-tree import shim
    _shared = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), '_shared')
    if _shared not in sys.path:
        sys.path.insert(0, _shared)
    import snap_site_settings

# SECAUDIT 054 F1 — the transport guard the other five tools already call
# (SYBU / flkr-fckr / SUYB / CRONOMETER / discovery) and COLD SNAP silently
# skipped. Same _shared shim so it resolves flat on the frozen exe too.
try:
    import snap_stepup
except ImportError:  # pragma: no cover - dev-tree import shim
    _shared = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), '_shared')
    if _shared not in sys.path:
        sys.path.insert(0, _shared)
    import snap_stepup

# Shared offline content library (producer/consumer store). Optional: recording a
# post into it is best-effort and must never break a successful post, so a missing
# module just disables the local mirror.
try:
    import snap_library
except Exception:  # pragma: no cover - optional shared dependency
    snap_library = None


class InsecureTransportError(RuntimeError):
    """Refused to attach the posting key to an insecure (non-https) connection."""


class AuthorizationExpiredError(RuntimeError):
    """A saved API authorization was rejected and must be replaced."""

    def __init__(self, credential_kind="api"):
        self.credential_kind = str(credential_kind or "api")
        label = "long-form" if self.credential_kind == "long-form" else "site"
        super().__init__(
            "This site's saved %s authorization expired or was rejected. "
            "Nothing was uploaded. Re-authenticate with a fresh key."
            % label
        )


def _raise_authorization(response, credential_kind="api"):
    if int(getattr(response, "status_code", 0) or 0) in (401, 403):
        raise AuthorizationExpiredError(credential_kind)


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


def _produce_library(site, draft, post_id, *, site_mode, post_type, body="",
                     permalink="", per_image=None, source_tool="coldsnap"):
    """Producer contract (shared-library-post-cache-producer spec): on
    post-success, EVERY COLD SNAP mode records what it posted into the shared
    library — post metadata plus the images, so the other tools can read them
    offline without re-pulling (Sean, 2026-09-06: discovered images go into the
    shared store).

    What gets stored is the WEB-SIZE file that actually went over the wire —
    a cache of server state. The photographer's originals (often RAW, never
    uploaded) are NOT copied anywhere; keeping originals would be a backup
    feature and stays explicit + opt-in, never a silent side effect
    ([[feedback_user_owns_archive]]).

    per_image: list of (DraftImage, server_image_id_or_None, uploaded_path).
    Best-effort — a library hiccup never turns a live post into a failure."""
    if snap_library is None or not post_id:
        return
    try:
        assets = []
        for im, server_id, up_path in (per_image or []):
            entry = None
            if up_path and os.path.isfile(up_path):
                entry = snap_library.store_media(
                    site, up_path,
                    orig_name=getattr(im, "filename", "") or os.path.basename(up_path))
            elif server_id:
                entry = {"asset_id": "img:%d" % int(server_id),
                         "orig_name": getattr(im, "filename", "") or ""}
            if entry is None:
                continue
            entry["alt"] = getattr(im, "alt", "") or ""
            entry["width"] = int(getattr(im, "width", 0) or 0)
            entry["height"] = int(getattr(im, "height", 0) or 0)
            if server_id:
                entry["source_ref"] = "img:%d" % int(server_id)
            assets.append(entry)
        snap_library.record_post(site, {
            "post_id":     int(post_id),
            "site_mode":   site_mode,
            "post_type":   post_type,
            "title":       draft.title,
            "body":        body,
            "permalink":   permalink or "",
            "tags":        [t.lstrip("#") for t in (draft.tags or "").split() if t.strip()],
            "source_tool": source_tool,
        }, assets=assets)
    except Exception:
        # A library hiccup must never fail a live post — but a persistently
        # broken mirror must not be invisible either (SECAUDIT 053 F).
        _log.warning("shared library: could not record post %s for %s",
                     post_id, site, exc_info=True)


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
        # SECAUDIT 054 F1 — REFUSE before the Bearer key is ever attached to a
        # session or sent. Every SumnaConnection (post, probe, mode-check) goes
        # through here, so this is the acting boundary for "the key leaves the
        # machine": a hard refusal, matching SUYB's login guard. Loopback is
        # allowed by insecure_transport_reason itself (dev/test on localhost).
        reason = snap_stepup.insecure_transport_reason(self.base_url)
        if reason:
            raise InsecureTransportError(reason)
        self.api_path = api_path
        self.api_key = api_key
        self.session = requests.Session()
        self.session.headers.update({
            "User-Agent": "ColdSnap/%s" % "0.1.0",
            "Authorization": f"Bearer {api_key}",
            **_site_scope(self.base_url),
            # Opt into smack-post-solo.php's deterministic AJAX reply ("success").
            "X-Requested-With": "XMLHttpRequest",
        })

    def _api(self, route: str) -> str:
        return f"{self.base_url}{self.api_path}?route={route}"

    def publishing_policy(self, timeout: int = 15) -> dict:
        """Read live publish invariants from the destination CMS."""
        r = self.session.get(f"{self.base_url}/sybu-data.php", timeout=timeout)
        _raise_authorization(r, "api")
        r.raise_for_status()
        data = r.json()
        policy = data.get("publishing_policy") or {}
        return {
            "download_link_required": bool(policy.get("download_link_required", False)),
            "download_default_mode": str(
                policy.get("download_default_mode", "per_post") or "per_post"),
        }

    def site_catalog(self, timeout: int = 15) -> dict:
        """Fetch the CMS-owned category/album vocabulary and canonical IDs."""
        r = self.session.get(f"{self.base_url}/sybu-data.php", timeout=timeout)
        _raise_authorization(r, "api")
        r.raise_for_status()
        data = r.json()
        return data if isinstance(data, dict) else {}

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

    def fetch_site_colors(self, timeout: int = 8) -> Tuple[str, str]:
        """Resolve (bg_hex, fg_hex) for the target site from its HTML / skin CSS."""
        from urllib.parse import urljoin

        def _norm_color(val: str) -> str:
            v = str(val or "").strip()
            if re.fullmatch(r"#[0-9a-fA-F]{6}", v):
                return v.lower()
            if re.fullmatch(r"#[0-9a-fA-F]{3}", v):
                return "#" + "".join(c * 2 for c in v[1:]).lower()
            m = re.fullmatch(r"rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*[\d.]+)?\s*\)", v)
            if m:
                r, g, b = (max(0, min(255, int(x))) for x in m.groups())
                return f"#{r:02x}{g:02x}{b:02x}"
            return ""

        def _resolve_var(raw: str, vars_map: dict) -> str:
            vm = re.match(r"var\(\s*(--[a-zA-Z0-9_-]+)(?:\s*,\s*([^)]+))?\s*\)", raw)
            if vm:
                return vars_map.get(vm.group(1), vm.group(2) or "")
            return raw

        def _extract_from_css(css: str) -> Tuple[str, str]:
            vars_map = {}
            for k, v in re.findall(r"(--[a-zA-Z0-9_-]+)\s*:\s*([^;}\n]+)", css):
                vars_map[k.strip()] = v.strip()
            bg, fg = "", ""
            for block in re.findall(r"(?:^|[}\s])(?:html\s*,\s*)?body\s*\{([^}]+)\}", css, re.IGNORECASE):
                if not bg:
                    for prop in ("background-color", "background"):
                        m = re.search(rf"{prop}\s*:\s*([^;!\n]+)", block, re.IGNORECASE)
                        if m:
                            raw = _resolve_var(m.group(1).strip(), vars_map)
                            token = raw.split()[0] if raw else ""
                            c = _norm_color(token)
                            if c:
                                bg = c
                                break
                if not fg:
                    m = re.search(r"(?:^|;|\s)color\s*:\s*([^;!\n]+)", block, re.IGNORECASE)
                    if m:
                        raw = _resolve_var(m.group(1).strip(), vars_map)
                        token = raw.split()[0] if raw else ""
                        c = _norm_color(token)
                        if c:
                            fg = c
            if not bg:
                for vname in ("--bg-primary", "--page-bg", "--canvas-bg", "--bg", "--background"):
                    if vname in vars_map:
                        c = _norm_color(vars_map[vname])
                        if c:
                            bg = c
                            break
            if not fg:
                for vname in ("--text-primary", "--text", "--fg", "--foreground", "--body-color"):
                    if vname in vars_map:
                        c = _norm_color(vars_map[vname])
                        if c:
                            fg = c
                            break
            return bg, fg

        best_bg, best_fg = "", ""
        try:
            r = self.session.get(f"{self.base_url}/", timeout=timeout)
            if r.status_code != 200:
                return "", ""
            html = r.text or ""
            is_maintenance_holding = "<!-- Wrench SVG" in html
            if not is_maintenance_holding:
                for style in re.findall(r"<style[^>]*>(.*?)</style>", html, re.IGNORECASE | re.DOTALL):
                    b, f = _extract_from_css(style)
                    best_bg = best_bg or b
                    best_fg = best_fg or f
                    if best_bg and best_fg:
                        return best_bg, best_fg
            hrefs = re.findall(
                r"""<link[^>]+rel=["']stylesheet["'][^>]+href=["']([^"']+)["']""",
                html, re.IGNORECASE,
            )
            for href in hrefs[:4]:
                css_url = urljoin(f"{self.base_url}/", href)
                try:
                    cr = self.session.get(css_url, timeout=timeout)
                    if cr.status_code == 200:
                        b, f = _extract_from_css(cr.text or "")
                        best_bg = best_bg or b
                        best_fg = best_fg or f
                        if best_bg and best_fg:
                            return best_bg, best_fg
                except requests.RequestException:
                    continue
            if (not best_bg or not best_fg) and is_maintenance_holding:
                try:
                    sr = self.session.get(f"{self.base_url}/sybu-data.php", timeout=timeout)
                    if sr.status_code == 200:
                        sdata = sr.json() if hasattr(sr, "json") else {}
                        for k in ("site_bg", "page_bg", "background_color", "bg_color"):
                            c = _norm_color(sdata.get(k, ""))
                            if c:
                                best_bg = best_bg or c
                                break
                        fg_c = _norm_color(sdata.get("site_fg", ""))
                        if fg_c:
                            best_fg = best_fg or fg_c
                        skin_slug = str(sdata.get("active_skin") or "").strip()
                        if (not best_bg or not best_fg) and skin_slug:
                            cr = self.session.get(f"{self.base_url}/skins/{skin_slug}/style.css", timeout=timeout)
                            if cr.status_code == 200:
                                b, f = _extract_from_css(cr.text or "")
                                best_bg = best_bg or b
                                best_fg = best_fg or f
                except Exception:
                    pass
        except requests.RequestException:
            return best_bg, best_fg
        return best_bg, best_fg

    def fetch_site_bg(self, timeout: int = 8) -> str:
        """Resolve the target site's page background colour (#rrggbb)."""
        return self.fetch_site_colors(timeout=timeout)[0]


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

    def publishing_policy(self) -> dict:
        return self.conn.publishing_policy()

    def _resolve_ids(self, draft: Draft) -> Tuple[Optional[int], Optional[int]]:
        cat_id = album_id = None
        if self.site_data is not None:
            categories = (self.site_data.get("categories", {})
                          if isinstance(self.site_data, dict)
                          else getattr(self.site_data, "categories", {}))
            albums = (self.site_data.get("albums", {})
                      if isinstance(self.site_data, dict)
                      else getattr(self.site_data, "albums", {}))
            if draft.category:
                cat_id = categories.get(draft.category.lower())
            if draft.album:
                album_id = albums.get(draft.album.lower())
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
            # ALT + colour travel WITH THE IMAGE (snap_images.img_alt /
            # img_color_mode). The endpoint accepted both all along — the tool
            # was silently dropping them on the wire.
            "alt":                  draft.alt,
            "color_mode":           draft.color_mode,
            "want_id":              "1",   # opt-in: server returns "success:<img id>"
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
        confirmed = (body == "success" or body.startswith("success:")
                     or "TRANSMISSION_LIVE" in (resp.url or "")
                     or "TRANSMISSION_LIVE" in body)
        if not confirmed:
            return SyncResult(False, message=_server_reason(body))
        # "success:<id>" (want_id servers) carries the new snap_images id — the
        # key for the shared-library record. An older server's bare "success"
        # just means no local record; the post itself is fine either way.
        img_id = 0
        if body.startswith("success:"):
            try:
                img_id = int(body.split(":", 1)[1])
            except ValueError:
                img_id = 0
        if img_id:
            im.alt = draft.alt   # solo: the post's ALT is the image's ALT
            _produce_library(self.conn.base_url, draft, img_id,
                             site_mode="photoblog", post_type="solo",
                             body=draft.caption or "",
                             per_image=[(im, img_id, _up)])
        return SyncResult(True, remote_post_id=(img_id or None), message="Posted")

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

    def publishing_policy(self) -> dict:
        return self.conn.publishing_policy()

    def _upload_image(self, im) -> dict:
        """POST one JPEG + its client thumbs to threeacross/gram/upload. Client
        thumbs are mandatory — the server saves them and skips its GD pass.
        Returns {path, thumb_square, thumb_aspect, width, height}."""
        opened = []
        try:
            _up = _upload_ready(im.local_path, self.policy)  # size-cap + mild sharpen; original untouched
            im.uploaded_path = _up   # runtime-only: the exact file that went over the wire
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
            "alt": im.alt,   # → snap_images.img_alt (travels with the image)
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
        # Producer: the grouped post records all its images; an all-split draft
        # records each split post with its own image when the counts line up.
        per_image = [(im, None, getattr(im, "uploaded_path", "")) for im in draft.images]
        if post_id:
            _produce_library(self.conn.base_url, draft, post_id,
                             site_mode="carousel",
                             post_type=(draft.post_type or "carousel"),
                             body=draft.caption or "",
                             per_image=per_image)
        elif len(split_ids) == len(per_image):
            for sid, pi in zip(split_ids, per_image):
                _produce_library(self.conn.base_url, draft, sid,
                                 site_mode="carousel", post_type="single",
                                 body=draft.caption or "", per_image=[pi])
        elif split_ids:
            _produce_library(self.conn.base_url, draft, split_ids[0],
                             site_mode="carousel", post_type="single",
                             body=draft.caption or "", per_image=per_image)
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
    signature_image_id = 0

    def __init__(self, base_url: str, smackpress_key: str, site_data=None, session=None,
                 *, signature_image_id=0):
        self.signature_image_id = signature_image_id
        self.base_url = (base_url or "").rstrip("/")
        self.key = (smackpress_key or "").strip()
        self.session = session or requests.Session()
        if session is None:
            self.session.headers.update({
                "User-Agent": "ColdSnap/%s" % "0.1.0",
                "Authorization": f"Bearer {self.key}",
                **_site_scope(self.base_url),
                "X-Requested-With": "XMLHttpRequest",
            })
        # Same destination-aware sizing policy as solo/gram (per-site max_long_edge,
        # falling back to the fleet default). Resolved once per poster.
        self.policy = _export_policy(_portable_from_site_data(site_data))

    def publishing_policy(self, timeout: int = 15) -> dict:
        r = self.session.get(f"{self.base_url}/sybu-data.php", timeout=timeout)
        _raise_authorization(r, "long-form")
        r.raise_for_status()
        policy = (r.json().get("publishing_policy") or {})
        return {
            "download_link_required": bool(policy.get("download_link_required", False)),
            "download_default_mode": str(
                policy.get("download_default_mode", "per_post") or "per_post"),
        }

    def _route(self, route: str) -> str:
        return f"{self.base_url}/api.php?route={route}"

    def _upload_one(self, im) -> int:
        """Upload one photo to the Gallery via smackpress/media/upload and return its
        snap_images id. Sizes/sharpens to the site's policy first; original untouched."""
        up = _upload_ready(im.local_path, self.policy)
        im.uploaded_path = up   # runtime-only: the exact file that went over the wire
        fh = open(up, "rb")
        try:
            # A COLD TAKE flip/crop is a JPEG derivative beside an untouched
            # source. Give the upload the derivative's real extension instead
            # of pairing JPEG bytes with the source's old .png/.webp name.
            source = os.path.abspath(getattr(im, "original_path", "") or im.local_path)
            edited = os.path.abspath(im.local_path) != source
            upload_name = (os.path.basename(im.local_path) if edited
                           else (im.filename or os.path.basename(im.local_path)))
            files = {"file": (upload_name, fh, _mime(up))}
            # ALT rides the upload → snap_images.img_alt (travels with the image).
            data = {"alt": im.alt} if getattr(im, "alt", "") else None
            r = self.session.post(self._route("smackpress/media/upload"),
                                  files=files, data=data, timeout=120)
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
            "cat_ids": [int(value) for value in (getattr(draft, "category_ids", []) or [])],
            "album_ids": [int(value) for value in (getattr(draft, "album_ids", []) or [])],
            "colophon": getattr(draft, "colophon", "") or "",
        }
        signature_ids = [image_ids[n] for n, image in enumerate(draft.images)
                         if n < len(image_ids) and getattr(image, "is_signature", False)]
        if not draft.signature_enabled:
            payload["signature_image_id"] = 0
        elif signature_ids or self.signature_image_id or not draft.remote_post_id:
            payload["signature_image_id"] = int(signature_ids[0]) if signature_ids else self.signature_image_id
        if draft.post_date:
            payload["date"] = draft.post_date
        # An imported post keeps its old URL slug (SMACKPRESS / BLOGGER FLOGGER);
        # the server de-duplicates. Blank = server derives from the title.
        if getattr(draft, "slug", ""):
            payload["slug"] = draft.slug
        if getattr(draft, "remote_post_id", 0):
            payload["post_id"] = int(draft.remote_post_id)
        return payload

    def build_page_payload(self, draft, content=None) -> dict:
        """Static WordPress Pages belong in snap_pages, never snap_posts."""
        payload = {
            "title": draft.title,
            "slug": getattr(draft, "slug", "") or "",
            "content": content if content is not None else (draft.caption or ""),
            "status": "published" if draft.img_status == "published" else "draft",
        }
        if draft.post_date:
            payload["date"] = draft.post_date
        if getattr(draft, "remote_post_id", 0):
            payload["page_id"] = int(draft.remote_post_id)
        return payload

    def list_posts(self, limit: int = 500, offset: int = 0) -> list:
        params = {"limit": max(1, min(500, int(limit)))}
        if int(offset or 0) > 0:
            params["offset"] = int(offset)
        r = self.session.get(self._route("smackpress/posts"),
                             params=params, timeout=30)
        _raise_authorization(r, "long-form")
        r.raise_for_status()
        posts = list(r.json().get("posts") or [])
        posts.sort(
            key=lambda p: (
                str(p.get("created_at") or p.get("updated_at") or ""),
                int(p.get("id") or 0),
            ),
            reverse=True,
        )
        return posts

    def list_pages(self, limit: int = 500) -> list:
        r = self.session.get(self._route("smackpress/pages"),
                             params={"limit": max(1, min(500, int(limit)))}, timeout=30)
        _raise_authorization(r, "long-form")
        r.raise_for_status()
        return list(r.json().get("pages") or [])

    def get_page(self, page_id: int) -> dict:
        r = self.session.get(self._route(f"smackpress/pages/{int(page_id)}"), timeout=30)
        _raise_authorization(r, "long-form")
        r.raise_for_status()
        return dict(r.json().get("page") or {})

    def get_post(self, post_id: int) -> dict:
        r = self.session.get(self._route(f"smackpress/posts/{int(post_id)}"), timeout=30)
        _raise_authorization(r, "long-form")
        r.raise_for_status()
        return dict(r.json().get("post") or {})

    # A [mosaic] placeholder (optionally [mosaic:bucket]/[mosaic:new]/[mosaic:auto])
    # means "build an inline gallery from THIS essay's photos here." A numeric
    # [mosaic:123] the author typed points at an existing panel and is left alone.
    # Every layout name smackpress/mosaics accepts. The old pattern only knew the
    # four three-photo layouts, so a mosaic built as Columns / Rows / Square /
    # Asymmetric never matched and went to the site as literal marker text.
    _MOSAIC_LAYOUTS = ("asymmetric", "columns", "rows", "square", "masonry", "one-column",
                       "one-left", "one-right", "three-across", "one-top")
    # `shuffle` (optional, after the layout) means the owner wants this gallery
    # SHOWN in a random order. It is the only channel between the WordPress
    # importer and the panel it creates: wp_source writes the keyword into the
    # marker, this reads it, and create_mosaic sends gallery_shuffle. An absent
    # keyword means authored order, which is what every existing marker means,
    # so nothing already written changes behaviour.
    _MOSAIC_TOKEN = re.compile(
        r'\[mosaic(?:=\s*([0-9]+(?:\s*,\s*[0-9]+)*))?'
        r'(?:\s+layout=([a-z-]+))?'
        r'(?:\s+(shuffle))?'
        r'(?:\s+gap=([0-9]{1,2}))?'
        r'(?:\s+weights=([0-9]+:-?[0-9]+(?:\s*,\s*[0-9]+:-?[0-9]+)*))?'
        r'(?:\s+mirrors=([0-9]+:[0-9]+(?:,[0-9]+:[0-9]+)*))?'
        r'(?::\s*(?:bucket|new|auto)\s*)?\]', re.I)
    _WEIGHTED_MOSAIC = re.compile(r'\[mosaic\b[^\]\r\n]*\bweights=[^\]\r\n]*\]', re.I)
    _MIRRORED_MOSAIC = re.compile(r'\[mosaic\b[^\]\r\n]*\bmirrors=[^\]\r\n]*\]', re.I)

    def _check_mosaic_weights(self, body, photo_count):
        weighted = self._WEIGHTED_MOSAIC.findall(body)
        mirrored = self._MIRRORED_MOSAIC.findall(body)
        if not weighted and not mirrored:
            return
        for token in weighted:
            match = self._MOSAIC_TOKEN.fullmatch(token)
            if not match or not match.group(1) or not match.group(5):
                raise ValueError("The mosaic size choices are invalid. Reopen the mosaic and save it again.")
            positions = {int(pos) for pos in match.group(1).split(",")}
            weights = self._mosaic_weights(match.group(5))
            if not weights or any(pos not in positions or pos > photo_count for pos in weights):
                raise ValueError("A resized mosaic photo is missing. Reopen the mosaic and save it again.")
        for token in mirrored:
            match = self._MOSAIC_TOKEN.fullmatch(token)
            if not match or not match.group(1) or not match.group(6) or match.group(3):
                raise ValueError("The mirrored mosaic is invalid. Reopen it and save it again.")
            positions = [int(pos) for pos in match.group(1).split(",")]
            if len(set(positions)) != len(positions) or any(not 1 <= pos <= photo_count for pos in positions):
                raise ValueError("A mirrored mosaic photo is missing. Reopen it and save it again.")
            self._mosaic_mirror_groups(match.group(6), positions)
        response = self.session.get(self._route("smackpress/mosaics"), timeout=20)
        if response.status_code in (404, 405):
            change = "mirrored mosaic blocks" if mirrored else "resized mosaic tiles"
            raise RuntimeError(f"This site's CMS cannot publish {change} yet. Update the site before sending this draft.")
        response.raise_for_status()
        capabilities = response.json()
        if weighted and capabilities.get("mosaic_weights") is not True:
            raise RuntimeError("This site's CMS cannot publish resized mosaic tiles yet. Update the site before sending this draft.")
        if mirrored and capabilities.get("mosaic_mirrors") is not True:
            raise RuntimeError("This site's CMS cannot publish mirrored mosaic blocks yet. Update the site before sending this draft.")

    @staticmethod
    def _mosaic_mirror_groups(raw, photos):
        groups = []
        used = set()
        for value in raw.split(",") if raw else ():
            start, count = map(int, value.split(":"))
            group = photos[start:start + count]
            if count < 2 or len(group) != count or used.intersection(group):
                raise ValueError("The mirrored mosaic blocks are invalid. Reopen the mosaic and save it again.")
            groups.append(group)
            used.update(group)
        return groups

    @staticmethod
    def _mosaic_weights(raw):
        weights = {}
        for pair in raw.split(",") if raw else ():
            bucket_pos, weight = map(int, pair.split(":"))
            if bucket_pos < 1 or weight not in (-1, 1, 2) or bucket_pos in weights:
                raise ValueError("The mosaic size choices are invalid. Reopen the mosaic and save it again.")
            weights[bucket_pos] = weight
        return weights

    def create_mosaic(self, image_ids, title="Mosaic", gap=4,
                      layout="asymmetric", gallery_shuffle=False,
                      mosaic_weights=None, mosaic_mirrors=None) -> Tuple[int, str]:
        """Create a snap_mosaics panel from ordered Gallery image ids via
        POST smackpress/mosaics. Returns (mosaic_id, '[mosaic:ID]').

        image_ids is the AUTHORED order and is sent as authored. gallery_shuffle
        asks the site to PRESENT the panel in a random order; it never changes
        what is stored, so turning it off afterwards gives this sequence back.

        The key is omitted entirely when it is off, so a site that has not yet
        synced the column sees exactly the request it saw before."""
        payload = {"title": title or "Mosaic",
                   "asset_ids": [int(i) for i in image_ids],
                   "gap": _gap_value(gap), "layout": layout}
        if gallery_shuffle:
            payload["gallery_shuffle"] = True
        if mosaic_weights:
            payload["mosaic_weights"] = mosaic_weights
        if mosaic_mirrors:
            payload["mosaic_mirrors"] = mosaic_mirrors
        r = self.session.post(
            self._route("smackpress/mosaics"),
            json=payload,
            timeout=60)
        if r.status_code in (401, 403):
            raise RuntimeError(_resp_msg(
                r, "Mosaic create rejected — this site's SMACKTALK key must be a 'smackpress' key."))
        r.raise_for_status()
        data = r.json()
        if mosaic_weights and data.get("mosaic_weights") != mosaic_weights:
            raise RuntimeError("The site did not confirm the mosaic sizes. The post was not sent.")
        if mosaic_mirrors and data.get("mosaic_mirrors") != mosaic_mirrors:
            raise RuntimeError("The site did not confirm the mirrored mosaic blocks. The post was not sent.")
        mid = int(data.get("mosaic_id") or 0)
        if mid <= 0:
            raise RuntimeError("mosaics endpoint did not return a mosaic_id")
        return mid, data.get("shortcode") or ("[mosaic:%d]" % mid)

    # A photo from THIS post's bucket placed inline in the text. The canvas
    # writes [img:bucket:N|size|align] (N = 1-based position in THE PHOTOS,
    # the same numbering [mosaic=1,2,3] uses) because an unposted photo has no
    # site id yet. At send time the bucket has just been uploaded, so N maps to
    # the new site id and the tag becomes the ordinary [img:ID|size|align].
    # (Sean, 2026-09-10: temp id locally, permanent id on sync.) An N that no
    # longer exists — photo removed after writing — drops the tag rather than
    # sending the site a broken one.
    _BUCKET_IMG_TOKEN = re.compile(r'\[img:(g)?bucket:(\d+)((?:\|[^\]]*)?)\]', re.I)
    _CAPTION_IMG_TOKEN = re.compile(
        r'\[img:([^\]]*?)\s*\|\s*caption=([^|\]]+)((?:\s*\|[^\]]*)?)\]', re.I
    )

    def _resolve_bucket_images(self, content, image_ids) -> str:
        import html as _html
        content = content or ""
        if self._BUCKET_IMG_TOKEN.search(content):
            ids = list(image_ids or [])

            def swap(match):
                gallery = "g" if match.group(1) else ""
                pos = int(match.group(2))
                if 1 <= pos <= len(ids):
                    return "[img:%s%d%s]" % (gallery, int(ids[pos - 1]), match.group(3) or "")
                return ""

            content = self._BUCKET_IMG_TOKEN.sub(swap, content)

        if self._CAPTION_IMG_TOKEN.search(content):
            def wrap_caption(match):
                prefix = (match.group(1) or "").strip()
                cap_raw = (match.group(2) or "").strip()
                tail = (match.group(3) or "").strip()
                inner = f"[img:{prefix}{tail}]"
                if not cap_raw:
                    return inner
                esc = _html.escape(cap_raw, quote=False)
                return f'<figure class="snap-image-figure">{inner}<figcaption>{esc}</figcaption></figure>'

            content = self._CAPTION_IMG_TOKEN.sub(wrap_caption, content)
            # Ensure plain-text paragraph chunks are wrapped in <p> when <figure> is present
            # so smack_autop_long in core/smackpress-api.php treats the post as block HTML.
            chunks = re.split(r"\n\n+", content.replace("\r\n", "\n"))
            out_chunks = []
            for ch in chunks:
                t = ch.strip()
                if not t:
                    continue
                if (t.startswith("<")
                        or re.match(r"^\[(?:img|mosaic|spacer|columns|pullquote)\b", t, re.I)):
                    out_chunks.append(t)
                else:
                    # Keep the exact anchor form emitted by COLD SNAP's link tool.
                    # Escape all other text as before, including literal angle brackets.
                    parts = re.split(
                        r'(<a href="[^"<>\r\n]+"(?: target="_blank")?'
                        r'(?: rel="(?:noopener(?: nofollow)?|nofollow)")?>[^<>\r\n]*</a>)',
                        t,
                    )
                    parts[::2] = [
                        _html.escape(part, quote=False).replace("\n", "<br>\n")
                        for part in parts[::2]
                    ]
                    out_chunks.append("<p>" + "".join(parts) + "</p>")
            content = "\n\n".join(out_chunks)
        return content

    _WIRE_MOSAIC = r"\[mosaic:[1-9][0-9]*(?:\s+width=[0-9]{2,4})?\]"
    _LEGACY_MOSAIC_PARAGRAPH = re.compile(
        rf"(<p(?:\s[^<>]*)?>)\s*({_WIRE_MOSAIC})\s*(.*?)</p>", re.I | re.S,
    )
    _WIRE_HTML_START = re.compile(
        r"^\s*(?:\[(?:img|mosaic|spacer):[^\]]+\]\s*)*"
        r"<(?:p|h[1-6]|ul|ol|blockquote|figure|div|section|article|header|main|aside|nav)\b",
        re.I,
    )

    @classmethod
    def _standalone_mosaic_body(cls, content):
        """Send mosaics between prose blocks, so the CMS does not join them to prose."""
        def split_legacy(match):
            opener, mosaic, prose = match.groups()
            return mosaic + ("\n\n" + opener + prose + "</p>" if prose.strip() else "")

        body = cls._LEGACY_MOSAIC_PARAGRAPH.sub(split_legacy, content)
        # Already-authored HTML keeps its existing markup, including attributes
        # and the untouched portions of an imported paragraph.
        if cls._WIRE_HTML_START.match(body):
            return body

        # Do not split the internal blank lines in columns or pull quotes.
        blocks = re.split(
            r"(\[columns[^\]]*\].*?\[/columns\]|\[pullquote\].*?\[/pullquote\])"
            + rf"|^[ \t]*({cls._WIRE_MOSAIC})[ \t]*$|\n[ \t]*\n+",
            body.replace("\r\n", "\n"), flags=re.I | re.M | re.S,
        )
        output = []
        for block in blocks:
            if block is None or not block.strip():
                continue
            text = block.strip()
            if re.match(r"^<(?:p|h[1-6]|ul|ol|blockquote|figure|div|section|article|header|main|aside|nav)\b",
                        text, re.I) or re.fullmatch(
                r"\[(?:img|mosaic|spacer):[^\]]+\]|"
                r"\[columns[^\]]*\].*?\[/columns\]|\[pullquote\].*?\[/pullquote\]", text, re.I | re.S,
            ):
                output.append(text)
                continue
            output.append("<p>" + cls._wire_prose(text) + "</p>")
        wire = "\n\n".join(output)
        # The CMS recognizes the HTML path only when it can see a block tag.
        # A mosaic-only or shortcode-only post still needs that marker.
        return wire if cls._WIRE_HTML_START.match(wire) else "<div></div>\n\n" + wire

    @staticmethod
    def _wire_prose(text):
        parts = re.split(
            r'(<a href="[^"<>\r\n]+"(?: target="_blank")?'
            r'(?: rel="(?:noopener(?: nofollow)?|nofollow)")?>[^<>\r\n]*</a>'
            r'|</?(?:b|i|u|strong|em)>)', text,
        )
        parts[::2] = [html.escape(html.unescape(part), quote=False).replace("\n", "<br>\n")
                      for part in parts[::2]]
        return "".join(parts)

    @classmethod
    def _wire_imported_blocks(cls, blocks, serialize_block):
        """Honor the parser's source slices until one member of a group is edited."""
        output = []
        verbatim = True
        for group, items in groupby(blocks, key=lambda block: (block.get("wrap") or {}).get("group")):
            blocks_in_group = list(items)
            if group is None:
                for block in blocks_in_group:
                    if block.get("src"):
                        output.append(block["src"] + block.get("sep", ""))
                        continue
                    text = serialize_block(block)
                    if block["type"] == "para":
                        text = "<p>" + cls._wire_prose(text) + "</p>"
                    output.append(text + (block.get("sep") or "\n\n"))
                    verbatim = False
                continue

            wrap = blocks_in_group[0]["wrap"]
            opening, closing = wrap["open"], wrap["close"]
            source = "".join(block.get("src", "") + block.get("sep", "")
                             for block in blocks_in_group)
            if (len(blocks_in_group) > 1 and all(block.get("src") for block in blocks_in_group)
                    and source.startswith(opening) and source.rstrip().endswith(closing)):
                output.append(source)
                continue

            verbatim = False
            for block in blocks_in_group:
                existing = block.get("src", "")
                kind = block["type"]
                if kind in {"mosaic", "image"}:
                    if existing:
                        token = re.search(cls._WIRE_MOSAIC if kind == "mosaic" else r"\[img:[^\]]+\]",
                                          existing, re.I)
                        if token is None:
                            raise ValueError("An imported media block lost its source shortcode.")
                        text = token[0]
                    else:
                        text = serialize_block(block)
                else:
                    if existing:
                        text = existing.removeprefix(opening).removesuffix(closing)
                    else:
                        text = cls._wire_prose(serialize_block(block))
                    text = opening + text + closing
                output.append(text + (block.get("sep") or "\n\n"))
        return "".join(output), verbatim

    def _resolve_mosaics(self, content, image_ids, draft) -> Tuple[str, list]:
        """Turn a [mosaic] placeholder in the body into a real [mosaic:ID] gallery of
        the essay's just-uploaded photos. One mosaic is created and reused for every
        placeholder in the body. Numeric [mosaic:123] tokens are untouched. Returns
        (resolved_content, [mosaic_id])."""
        content = content or ""
        if not image_ids or not self._MOSAIC_TOKEN.search(content):
            return content, []
        gap = _gap_value(getattr(draft, "mosaic_gap", 4))
        mosaic_ids = []

        def build(match):
            selection = match.group(1)
            layout = (match.group(2) or 'asymmetric').lower()
            shuffle = bool(match.group(3))
            gallery_gap = _gap_value(match.group(4)) if match.group(4) is not None else gap
            if layout not in self._MOSAIC_LAYOUTS:
                layout = 'asymmetric'
            chosen = list(image_ids)
            site_weights = {}
            if selection:
                positions = [int(value.strip()) for value in selection.split(',')]
                chosen = [image_ids[pos - 1] for pos in positions
                          if 1 <= pos <= len(image_ids)]
                site_weights = {
                    str(image_ids[pos - 1]): weight
                    for pos, weight in self._mosaic_weights(match.group(5)).items()
                    if pos in positions and 1 <= pos <= len(image_ids)
                }
            if not chosen:
                return ''
            options = {}
            if site_weights:
                options["mosaic_weights"] = site_weights
            if match.group(6):
                options["mosaic_mirrors"] = self._mosaic_mirror_groups(match.group(6), chosen)
            mid, shortcode = self.create_mosaic(
                chosen, title=(draft.title or "Mosaic"), gap=gallery_gap, layout=layout,
                gallery_shuffle=shuffle, **options)
            mosaic_ids.append(mid)
            return shortcode

        return self._MOSAIC_TOKEN.sub(build, content), mosaic_ids

    def _record_to_library(self, draft, post_id, content, server_data, image_ids) -> None:
        """Producer contract — now via the shared _produce_library, which also
        stores the web-size upload bytes in shared_library/<site>/media (a cache
        of server state; originals are never copied)."""
        imgs = draft.images or []
        per_image = []
        for idx, im in enumerate(imgs):
            iid = image_ids[idx] if idx < len(image_ids) else None
            per_image.append((im, iid, getattr(im, "uploaded_path", "")))
        _produce_library(
            self.base_url, draft, post_id,
            site_mode="smacktalk", post_type="long", body=content,
            permalink=(server_data or {}).get("permalink", "")
                      or (server_data or {}).get("url", ""),
            per_image=per_image)

    @staticmethod
    def _long_slugify(text: str) -> str:
        s = re.sub(r"[^a-z0-9\s-]", "", (text or "").strip().lower())
        s = re.sub(r"[\s-]+", "-", s).strip("-")
        return s

    @classmethod
    def _body_text_for_sync(cls, draft) -> str:
        return cls._wire_body(draft)[0]

    @classmethod
    def _wire_body(cls, draft):
        raw = (getattr(draft, "caption", "") or "").strip()
        bjson = (getattr(draft, "body_blocks", "") or "").strip()
        if not bjson or (raw and '"src"' not in bjson and '"wrap"' not in bjson):
            return raw, "authored"
        try:
            from coldsnap_qt import biggie
            blocks = biggie.blocks_from_json(bjson)
            if not blocks:
                return raw, "authored"
            positions = {
                str(getattr(im, "asset_uuid", "")): i + 1
                for i, im in enumerate(getattr(draft, "images", []) or [])
                if getattr(im, "asset_uuid", "")
            }

            def _wire(blk):
                out = dict(blk)
                if out.get("type") == "image":
                    show_cap = bool(out.get("show_caption"))
                    cap_text = str(out.get("caption") or "").strip()
                    if out.get("asset_uuid"):
                        pos = positions.get(str(out["asset_uuid"]))
                        if pos:
                            out["img_id"] = f"bucket:{pos}"
                        for k in ("asset_uuid", "placement", "width_ratio", "crop", "alt", "link"):
                            out.pop(k, None)
                    if show_cap and cap_text:
                        out["show_caption"] = True
                        out["caption"] = cap_text
                        out.pop("raw", None)
                    else:
                        out.pop("caption", None)
                        out.pop("show_caption", None)
                if out.get("type") == "mosaic" and out.get("order"):
                    biggie.wire_mosaic_ids(out, positions)
                if out.get("type") == "columns":
                    out["cols"] = [[_wire(c) for c in col] for col in out.get("cols", [])]
                return out

            if any("src" in block or "wrap" in block for block in blocks):
                content, verbatim = cls._wire_imported_blocks(
                    [_wire(block) for block in blocks], biggie.serialize_block,
                )
                return content, "verbatim" if verbatim else "imported"
            return raw or biggie.serialize_blocks([_wire(b) for b in blocks]).strip(), "authored"
        except Exception:
            return "", "authored"

    def _slug_taken_on_server(self, slug: str, cached_posts: list) -> bool:
        target = self._long_slugify(slug)
        if not target:
            return False
        try:
            r = self.session.get(self._route("smackpress/posts"),
                                 params={"slug": target}, timeout=30)
            if r.status_code == 200:
                payload = r.json() or {}
                if payload.get("exact"):
                    return any(self._long_slugify(str(p.get("slug", "") or "")) == target
                               for p in (payload.get("posts") or []) if isinstance(p, dict))
        except Exception:
            pass
        return any(self._long_slugify(str(p.get("slug", "") or "")) == target
                   for p in (cached_posts or []) if isinstance(p, dict))

    def _unique_slug(self, base_slug: str, cached_posts: list) -> str:
        base = self._long_slugify(base_slug)
        if not base:
            return ""
        n = 2
        while True:
            cand = f"{base}-{n}"
            if not self._slug_taken_on_server(cand, cached_posts):
                return cand
            n += 1

    def _resolve_existing_post_id(self, draft, confirm_overwrite_fn=None) -> Tuple[Optional[str], bool]:
        """Resolve an explicit draft.slug (never draft.title!) to an existing remote post_id.
        Returns (error_message_or_None, cancelled_bool)."""
        if getattr(draft, "remote_post_id", 0):
            return None, False
        explicit_slug = self._long_slugify(getattr(draft, "slug", "") or "")
        if not explicit_slug:
            return None, False
        posts = []
        list_full = False
        try:
            r = self.session.get(self._route("smackpress/posts"),
                                 params={"slug": explicit_slug}, timeout=30)
            if r.status_code == 200:
                payload = r.json() or {}
                if payload.get("exact"):
                    posts = list(payload.get("posts") or [])
                else:
                    posts = list(payload.get("posts") or []) or self.list_posts(limit=500)
                    list_full = bool(payload.get("truncated")) or len(posts) >= 500
            else:
                posts = self.list_posts(limit=500)
                list_full = len(posts) >= 500
        except Exception:
            try:
                posts = self.list_posts(limit=500)
                list_full = len(posts) >= 500
            except Exception:
                return None, False

        matched = None
        for p in (posts or []):
            if not isinstance(p, dict):
                continue
            pslug = self._long_slugify(str(p.get("slug", "") or ""))
            pid = int(p.get("id") or 0)
            if pslug == explicit_slug and pid > 0:
                matched = p
                break

        if matched is None and not list_full:
            return None, False

        if matched is not None and bool(getattr(draft, "confirm_overwrite", False)):
            draft.remote_post_id = int(matched["id"])
            draft.slug = matched.get("slug", explicit_slug)
            return None, False

        if callable(confirm_overwrite_fn):
            info = dict(matched or {
                "id": 0,
                "slug": explicit_slug,
                "title": getattr(draft, "title", "") or explicit_slug,
                "created_at": getattr(draft, "post_date", "") or "",
            })
            if matched is None and list_full:
                info["list_full"] = True
            choice = confirm_overwrite_fn(info)
            if choice is True or choice == "update":
                if matched is not None:
                    draft.remote_post_id = int(matched["id"])
                    draft.slug = matched.get("slug", explicit_slug)
                    return None, False
                return f"Cannot update '{explicit_slug}': exact post could not be verified from truncated list.", False
            if choice == "new":
                draft.remote_post_id = 0
                draft.slug = self._unique_slug(explicit_slug, posts)
                return None, False
            return "Sending cancelled.", True

        if matched is not None:
            return (
                f"Post with slug '{explicit_slug}' already exists (id={int(matched['id'])}). "
                "Confirm overwrite before updating it."
            ), False
        return None, False

    def sync_smacktalk(self, draft, confirm_overwrite_fn=None) -> SyncResult:
        if not self.key:
            return SyncResult(False, message="No SMACKTALK key set for this site (needs a 'smackpress' API key).")
        is_page = getattr(draft, "destination_type", "post") == "page"
        if not (draft.title or "").strip():
            return SyncResult(False, message="a SMACKTALK post needs a title")
        body_text, wire_mode = self._wire_body(draft)
        if not body_text and not is_page and (getattr(draft, "body_blocks", "") or "").strip():
            return SyncResult(False, message="Draft body blocks produced empty content — refusing to overwrite with an empty post.")
        if (not draft.images and not is_page
                and self._MOSAIC_TOKEN.search(body_text)):
            return SyncResult(False, message=(
                "This post asks for a MOSAIC but has no photographs. Add the "
                "photos, or take the mosaic out of the writing."))
        try:
            self._check_mosaic_weights(body_text, len(draft.images))
            if not is_page:
                err_msg, was_cancelled = self._resolve_existing_post_id(
                    draft, confirm_overwrite_fn=confirm_overwrite_fn)
                if err_msg:
                    return SyncResult(False, message=err_msg, cancelled=was_cancelled)
            image_ids, cover_id = [], None
            for im in draft.images:
                existing_id = int(getattr(im, "remote_image_id", 0) or 0)
                if existing_id:
                    image_ids.append(existing_id)
                    if im.is_cover and cover_id is None:
                        cover_id = existing_id
                    continue
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
            content = body_text
            if wire_mode != "verbatim":
                content, new_mosaics = self._resolve_mosaics(content, image_ids, draft)
                content = self._resolve_bucket_images(content, image_ids)
                if wire_mode == "authored" and new_mosaics:
                    content = self._standalone_mosaic_body(content)
                elif (wire_mode == "imported" and re.search(self._WIRE_MOSAIC, content, re.I)
                      and not self._WIRE_HTML_START.match(content)):
                    content = "<div></div>\n\n" + content

            route = "smackpress/pages" if is_page else "smackpress/posts"
            payload = (self.build_page_payload(draft, content=content) if is_page
                       else self.build_payload(draft, image_ids, cover_id, content=content))
            r = self.session.post(self._route(route), json=payload,
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

        post_id = int(data.get("page_id" if is_page else "post_id") or 0)
        if not post_id:
            return SyncResult(False, message=data.get("error") or "server did not confirm the post")
        # Producer: record the finished post + which Gallery images it used (ids only,
        # no photo files copied — originals stay on disk).
        if not is_page:
            self._record_to_library(draft, post_id, content, data, image_ids)
        return SyncResult(True, remote_post_id=post_id,
                          message="Updated" if getattr(draft, "remote_post_id", 0) else "Posted")

    def verify(self, draft) -> bool:
        """Best-effort: pull the post back via GET smackpress/posts/{id}. Trust the
        create's explicit post_id if the read isn't reachable, rather than a false fail."""
        pid = getattr(draft, "remote_post_id", 0)
        if not pid:
            return False
        if getattr(draft, "destination_type", "post") == "page":
            try:
                r = self.session.get(self._route(f"smackpress/pages/{int(pid)}"), timeout=20)
                return r.status_code == 200
            except requests.RequestException:
                return True
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
