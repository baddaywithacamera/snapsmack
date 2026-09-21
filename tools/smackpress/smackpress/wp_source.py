"""
SmackPress — wp_source.py

A WordPress post → a COLD SNAP Draft, with the pictures already yours.

WHY THIS EXISTS (2026-09-21). SMACKPRESS used to push WordPress's raw HTML to
SMACKTALK as-is — every `<img src="https://old-wordpress-site/…">` stayed a
link to the OLD site. The moment someone switched WordPress off (the whole
point of migrating) every migrated post's pictures died. This module does the
one thing that matters: every image the post uses is downloaded, becomes a
DraftImage, and its place in the body becomes an `[img:bucket:N]` token —
exactly what COLD SNAP's own editor writes. COLD SNAP's `SmacktalkPoster`
then uploads the images to the site's GALLERY and rewrites the tokens to
`[img:ID]`. Nothing in the migrated post points back at WordPress.

SMACKPRESS no longer has its own editor, poster or mosaic code. It borrows
COLD SNAP's (`tools/coldsnap/` on sys.path): the Draft model here, BodyEditor
in the shell, SmacktalkPoster to publish. One editor to fix, one poster to
secure.

    draft = draft_from_wp(full_post, workdir)      # full_post = companion's post JSON
    SmacktalkPoster(site_url, key).sync_smacktalk(draft)

What travels: title, body (HTML — the server's sanitiser accepts it), every
image, alt text, tags, first category name, original date, original slug
(old URLs keep working), featured image first so it becomes the cover.
What does not: comments (separate route, later), excerpt (SMACKTALK has none).
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

from __future__ import annotations

import html
import os
import re
import sys
import urllib.parse
import urllib.request
import uuid
from pathlib import Path
from typing import Callable, Dict, List, Optional, Tuple

# COLD SNAP's data model and shared safety module, by path — same repo, same build.
_HERE = Path(__file__).resolve()
for _p in (_HERE.parents[2] / "coldsnap", _HERE.parents[2] / "_shared"):
    if _p.is_dir() and str(_p) not in sys.path:
        sys.path.insert(0, str(_p))

from sumna_offline import Draft, DraftImage, KIND_SMACKTALK, MODE_SMACKTALK  # noqa: E402

try:
    import snap_imgsafe  # noqa: E402
except Exception:  # noqa: BLE001
    snap_imgsafe = None

MAX_IMAGE_BYTES = 60 * 1024 * 1024


class ImportError_(RuntimeError):
    """A picture could not be brought across. Named so the shell can say which."""


# ── fetching ──────────────────────────────────────────────────────────────────
def _default_fetch(url: str, timeout: int = 60) -> bytes:
    """Plain GET, no credentials (public media). Redirects are fine here —
    there is nothing in the request worth stealing."""
    req = urllib.request.Request(url, headers={"User-Agent": "SmackPress/0.2"})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        data = resp.read(MAX_IMAGE_BYTES + 1)
    if len(data) > MAX_IMAGE_BYTES:
        raise ImportError_(f"{url}: larger than {MAX_IMAGE_BYTES // 1048576} MB, refused")
    return data


def _safe_name(url: str, filename: str = "", fallback: str = "image") -> str:
    name = filename or os.path.basename(urllib.parse.urlparse(url).path) or fallback
    name = os.path.basename(name.replace("\\", "/"))
    name = re.sub(r"[^A-Za-z0-9._-]+", "-", name).strip("-.") or fallback
    return name


def download_image(url: str, workdir: str, filename: str = "", fetch: Callable = None) -> str:
    """Download one image into `workdir`, verify it really is an image, return the path."""
    fetch = fetch or _default_fetch
    data = fetch(url)
    if snap_imgsafe is not None:
        snap_imgsafe.check_bytes(data)          # raises on junk / wrong type
    os.makedirs(workdir, exist_ok=True)
    name = _safe_name(url, filename)
    path = os.path.join(workdir, name)
    n = 1
    while os.path.exists(path):
        stem, ext = os.path.splitext(name)
        path = os.path.join(workdir, f"{stem}-{n}{ext}")
        n += 1
    with open(path, "wb") as f:
        f.write(data)
    return path


# ── body rewriting ────────────────────────────────────────────────────────────
_IMG_TAG   = re.compile(r"<img\b[^>]*>", re.I)
_SRC_ATTR  = re.compile(r"""\bsrc\s*=\s*["']([^"']+)["']""", re.I)
_ALT_ATTR  = re.compile(r"""\balt\s*=\s*["']([^"']*)["']""", re.I)
# companion's gallery expansion: [smackpress-image id="12" url="https://…"]
_SP_IMAGE  = re.compile(r"""\[smackpress-image\s+id=["'](\d+)["']\s+url=["']([^"']+)["']\s*\]""", re.I)
# an <img> wrapped in <a>…</a> and/or <figure>…</figure> (with optional figcaption)
_WRAPPED   = re.compile(
    r"(?:<figure\b[^>]*>\s*)?(?:<a\b[^>]*>\s*)?(<img\b[^>]*>)(?:\s*</a>)?"
    r"(?:\s*<figcaption\b[^>]*>.*?</figcaption>)?(?:\s*</figure>)?", re.I | re.S)
_EMPTY_P   = re.compile(r"<p\b[^>]*>\s*(\[img:bucket:\d+\])\s*</p>", re.I)


def _norm(url: str) -> str:
    u = html.unescape((url or "").strip())
    # WordPress serves resized variants like name-1024x768.jpg; match the original too.
    return re.sub(r"-\d{2,4}x\d{2,4}(\.[a-z0-9]+)$", r"\1", u, flags=re.I).lower()


def rewrite_body(content: str, images: List[dict]) -> Tuple[str, List[dict]]:
    """Replace every picture in the body with an [img:bucket:N] token.

    `images` is the companion's list ({id,url,alt,filename,…}). Pictures found
    in the body that the companion did not list (hot-linked from elsewhere) are
    appended so they get downloaded too. Returns (body, ordered_images) where
    position N in ordered_images ↔ [img:bucket:N] (1-based)."""
    ordered: List[dict] = []
    by_url: Dict[str, int] = {}

    def slot(url: str, alt: str = "", extra: Optional[dict] = None) -> int:
        key = _norm(url)
        if key in by_url:
            return by_url[key]
        img = dict(extra or {})
        img.setdefault("url", html.unescape(url))
        img.setdefault("alt", alt)
        ordered.append(img)
        by_url[key] = len(ordered)
        return len(ordered)

    # Seed with the companion's images so numbering follows its order (featured first
    # is arranged by the caller).
    for im in images or []:
        if im.get("url"):
            slot(im["url"], im.get("alt", ""), im)

    def repl_sp(m):
        return "\n[img:bucket:%d]\n" % slot(m.group(2))

    def token_for_tag(tag: str) -> str:
        src = _SRC_ATTR.search(tag)
        if not src:
            return ""
        alt = _ALT_ATTR.search(tag)
        return "\n[img:bucket:%d]\n" % slot(src.group(1), html.unescape(alt.group(1)) if alt else "")

    body = _SP_IMAGE.sub(repl_sp, content or "")
    body = _WRAPPED.sub(lambda m: token_for_tag(m.group(1)), body)   # <img> with figure / a / caption
    body = _IMG_TAG.sub(lambda m: token_for_tag(m.group(0)), body)   # any bare <img> left over
    body = _EMPTY_P.sub(r"\n\1\n", body)
    body = re.sub(r"\n{3,}", "\n\n", body).strip()
    return body, ordered


# ── the adapter ───────────────────────────────────────────────────────────────
def _tag_token(name: str) -> str:
    t = re.sub(r"[^A-Za-z0-9]+", "", (name or "").lower())
    return "#" + t if t else ""


def draft_from_wp(full_post: dict, workdir: str, *, fetch: Callable = None,
                  status: str = "draft", on_progress: Callable[[str], None] = None) -> Draft:
    """Turn the companion's full post JSON into a COLD SNAP Draft with every
    picture downloaded into `workdir`. Raises ImportError_ naming the picture
    if one cannot be brought across — a post with a missing picture is not
    migrated half-way."""
    say = on_progress or (lambda m: None)
    content = full_post.get("content_expanded") or full_post.get("content_raw") or ""
    images = list(full_post.get("images") or [])

    # Featured image leads: COLD SNAP's poster makes images[0] the cover.
    feat = full_post.get("featured_image") or {}
    if feat.get("url"):
        images = [feat] + [im for im in images if _norm(im.get("url", "")) != _norm(feat["url"])]

    body, ordered = rewrite_body(content, images)

    draft_images: List[DraftImage] = []
    for n, im in enumerate(ordered, 1):
        url = im["url"]
        say(f"picture {n}/{len(ordered)}: {os.path.basename(urllib.parse.urlparse(url).path)}")
        try:
            path = download_image(url, workdir, im.get("filename", ""), fetch)
        except Exception as e:  # noqa: BLE001
            raise ImportError_(f"Picture {n} could not be brought across ({url}): {e}") from e
        draft_images.append(DraftImage(
            local_path=path, original_path=url, filename=os.path.basename(path),
            width=int(im.get("width") or 0), height=int(im.get("height") or 0),
            alt=(im.get("alt") or im.get("caption") or "")[:255],
        ))

    tags = " ".join(t for t in (_tag_token(x) for x in (full_post.get("tags") or [])) if t)
    cats = full_post.get("categories") or []
    date = (full_post.get("date") or "")[:19].replace("T", " ")

    return Draft(
        draft_id=str(uuid.uuid4()),
        kind=KIND_SMACKTALK, mode=MODE_SMACKTALK,
        title=html.unescape(full_post.get("title") or ""),
        caption=body,
        tags=tags,
        post_date=date,
        img_status=status,
        category=(cats[0].get("name") if cats and isinstance(cats[0], dict) else "") or "",
        slug=(full_post.get("slug") or ""),
        images=draft_images,
    )

# ===== SNAPSMACK EOF =====
