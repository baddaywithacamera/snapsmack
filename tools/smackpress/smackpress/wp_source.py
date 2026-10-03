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

What travels: authored words (title, body and image alt text), every image,
the original publication date, and the authored URL slug so migrated links
keep working. WordPress directory structure, taxonomy, comments, excerpt and
attachment metadata do not cross the boundary.
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
from datetime import datetime, timezone
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
_WP_BLOCK_COMMENT = re.compile(r"<!--\s*/?wp:[\s\S]*?-->", re.I)
_WP_SPACER = re.compile(
    r'<div\b[^>]*class=["\'][^"\']*\bwp-block-spacer\b[^"\']*["\'][^>]*>.*?</div>',
    re.I | re.S,
)
_DIV_TAG = re.compile(r"</?div\b[^>]*>", re.I)
_WP_COLUMNS_BLOCK = re.compile(
    r"<!--\s*wp:columns\b[^>]*-->(.*?)<!--\s*/wp:columns\s*-->", re.I | re.S)
_FOLLOWING_IMAGE_BLOCK = re.compile(
    r"^\s*<!--\s*wp:image\b[^>]*-->\s*(\[img:bucket:\d+\])\s*"
    r"<!--\s*/wp:image\s*-->", re.I)
_PRECEDING_IMAGE_BLOCK = re.compile(
    r"<!--\s*wp:image\b[^>]*-->\s*(\[img:bucket:\d+\])\s*"
    r"<!--\s*/wp:image\s*-->\s*$", re.I)
_BUCKET_TOKEN = re.compile(r"\[img:bucket:(\d+)\]", re.I)


def _discard_source_links(body: str, source_url: str) -> str:
    """Keep link text, but do not carry links back to the retired WP site."""
    source_host = (urllib.parse.urlparse(source_url or "").hostname or "").lower()
    if not source_host:
        return body

    anchor = re.compile(
        r'<a\b([^>]*)\bhref\s*=\s*(["\'])(.*?)\2([^>]*)>(.*?)</a>', re.I | re.S)

    def replace(match):
        host = (urllib.parse.urlparse(html.unescape(match.group(3))).hostname or "").lower()
        return match.group(5) if host == source_host else match.group(0)

    return anchor.sub(replace, body)


def _publication_instant(full_post: dict) -> str:
    """Return an unambiguous UTC instant when WordPress supplied one."""
    gmt = str(full_post.get("date_gmt") or "").strip()
    if gmt and not gmt.startswith("0000-00-00"):
        try:
            parsed = datetime.fromisoformat(gmt.replace(" ", "T").replace("Z", "+00:00"))
            if parsed.tzinfo is None:
                parsed = parsed.replace(tzinfo=timezone.utc)
            return parsed.astimezone(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")
        except ValueError as exc:
            raise ImportError_("WordPress returned an invalid GMT publication date") from exc

    local = str(full_post.get("date") or "").strip()
    if not local:
        return ""
    try:
        parsed = datetime.fromisoformat(local.replace(" ", "T"))
    except ValueError as exc:
        raise ImportError_("WordPress returned an invalid publication date") from exc
    return parsed.isoformat(timespec="seconds")


def _norm(url: str) -> str:
    u = html.unescape((url or "").strip())
    # WordPress serves the same attachment as name.jpg, name-scaled.jpg and
    # name-1024x768.jpg. They are variants of one photograph, not three media
    # records in SnapSmack.
    return re.sub(r"(?:-scaled|-\d{2,5}x\d{2,5})(\.[a-z0-9]+)$", r"\1", u, flags=re.I).lower()


def _variant_rank(image: dict) -> tuple:
    """Prefer the largest known WordPress variant, then its original URL."""
    width = max(0, int(image.get("width") or 0))
    height = max(0, int(image.get("height") or 0))
    url = html.unescape(str(image.get("url") or ""))
    derivative = bool(re.search(r"(?:-scaled|-\d{2,5}x\d{2,5})(?:\.[a-z0-9]+)$", url, re.I))
    return (width * height, 0 if derivative else 1)


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
            position = by_url[key]
            candidate = dict(extra or {})
            candidate.setdefault("url", html.unescape(url))
            candidate.setdefault("alt", alt)
            if _variant_rank(candidate) > _variant_rank(ordered[position - 1]):
                ordered[position - 1] = candidate
            return position
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

    # A common Gutenberg gallery idiom in Sean's archive is one normal image
    # immediately followed by a two-column image block.  It is one visual
    # cluster, not three unrelated pictures.  Carry that intent across as the
    # native SMACKTALK mosaic placeholder; the poster resolves bucket positions
    # to permanent image ids and creates the mosaic during sync.
    # Work one exact Gutenberg columns block at a time. A single broad regex can
    # backtrack across paragraphs into a later columns block and silently group
    # the wrong photographs.
    for columns in reversed(list(_WP_COLUMNS_BLOCK.finditer(body))):
        pair = _BUCKET_TOKEN.findall(columns.group(1))
        following = _FOLLOWING_IMAGE_BLOCK.match(body[columns.end():])
        if len(pair) == 2 and following:
            last = _BUCKET_TOKEN.search(following.group(1)).group(1)
            token = "\n[mosaic=%s layout=one-top]\n" % ",".join([pair[0], pair[1], last])
            body = body[:columns.start()] + token + body[columns.end() + following.end():]

    # For any two-column pair without a directly following image, use the
    # directly preceding full-width image instead.
    for columns in reversed(list(_WP_COLUMNS_BLOCK.finditer(body))):
        pair = _BUCKET_TOKEN.findall(columns.group(1))
        preceding = _PRECEDING_IMAGE_BLOCK.search(body[:columns.start()])
        if len(pair) == 2 and preceding:
            first = _BUCKET_TOKEN.search(preceding.group(1)).group(1)
            token = "\n[mosaic=%s layout=one-top]\n" % ",".join([first, pair[0], pair[1]])
            body = body[:preceding.start()] + token + body[columns.end():]
    # Gutenberg comments are editor metadata, not post content.  Leaving the
    # opening comment in place also defeats the destination API's "already
    # HTML" check, causing the entire post (including its <p> tags) to be
    # escaped as visible text.  Columns are layout wrappers WordPress owns;
    # TILEZ lays adjacent imported images out itself, so unwrap them here.
    body = _WP_SPACER.sub("\n", body)
    body = _WP_BLOCK_COMMENT.sub("\n", body)
    body = _DIV_TAG.sub("\n", body)
    body = re.sub(r"<p\b[^>]*>\s*</p>", "\n", body, flags=re.I)
    body = re.sub(r"\n{3,}", "\n\n", body).strip()
    return body, ordered


_WP_SIGNATURE_HINT = re.compile(r"(?:signature|autograph|sean[-_ ]?mccormick[-_ ]?black[-_ ]?low[-_ ]?res)", re.I)
_WP_COLOPHON_LEAD = re.compile(
    r"\b(?:main camera|camera used|also used|equipment used|shot (?:on|with)|taken with|"
    r"photos? (?:from|(?:taken|made|shot) with)|images? (?:from|(?:taken|made|shot) with)|photographed with)\b",
    re.I,
)
_WP_EQUIPMENT = re.compile(
    r"\b(?:camera|body|lens|lenses|film|film stock|drone|cellphone|phone|iphone|galaxy|pixel|"
    r"canon|nikon|sony|fujifilm|fuji|olympus|pentax|leica|hasselblad|dji|kodak|ilford|helios|eos)\b",
    re.I,
)


def extract_wordpress_ephemera(body: str, ordered: List[dict]) -> Tuple[str, str, set]:
    """Translate recurring WordPress-era conventions into explicit post data.

    This deliberately belongs to the WordPress adapter. The CMS and skins never
    inspect prose, filenames, or old editor markup to guess what content means.
    Returns cleaned body, colophon HTML, and 1-based signature image positions.
    """
    signature_slots = set()
    for position, image in enumerate(ordered, 1):
        haystack = " ".join(str(image.get(key) or "") for key in
                            ("filename", "title", "alt", "caption", "url"))
        if _WP_SIGNATURE_HINT.search(haystack):
            signature_slots.add(position)
            body = re.sub(r"(?:^|\n)\s*\[img:bucket:%d\]\s*(?=\n|$)" % position, "\n", body)

    colophon = ""
    final = re.search(r"(<p\b[^>]*>(?:(?!<p\b).)*?</p>)\s*$", body, re.I | re.S)
    if final:
        plain = html.unescape(re.sub(r"<[^>]+>", " ", final.group(1)))
        terms = {m.group(0).lower() for m in _WP_EQUIPMENT.finditer(plain)}
        if _WP_COLOPHON_LEAD.search(plain) or len(terms) >= 3:
            colophon = final.group(1)
            body = body[:final.start()].rstrip()

    return re.sub(r"\n{3,}", "\n\n", body).strip(), colophon, signature_slots


# ── the adapter ───────────────────────────────────────────────────────────────
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
    destination_type = "page" if full_post.get("type") == "page" else "post"
    if destination_type == "page":
        # A WordPress Page is authored static content.  Do not reinterpret its
        # final paragraph, signature, or links as post metadata: the page must
        # arrive as written.  Images still become local bucket tokens so the
        # old WordPress host can be retired safely.
        colophon, signature_slots = "", set()
    else:
        body = _discard_source_links(body, full_post.get("link") or "")
        body, colophon, signature_slots = extract_wordpress_ephemera(body, ordered)

    draft_images: List[DraftImage] = []
    for n, im in enumerate(ordered, 1):
        url = im["url"]
        say(f"picture {n}/{len(ordered)}: {os.path.basename(urllib.parse.urlparse(url).path)}")
        try:
            path = download_image(url, workdir, im.get("filename", ""), fetch)
        except Exception as e:  # noqa: BLE001
            raise ImportError_(f"Picture {n} could not be brought across ({url}): {e}") from e
        draft_images.append(DraftImage(
            local_path=path, original_path=path, filename=os.path.basename(path),
            width=int(im.get("width") or 0), height=int(im.get("height") or 0),
            alt=(im.get("alt") or im.get("caption") or "")[:255],
            is_signature=n in signature_slots,
        ))

    date = _publication_instant(full_post)

    return Draft(
        draft_id=str(uuid.uuid4()),
        kind=KIND_SMACKTALK, mode=MODE_SMACKTALK,
        title=html.unescape(full_post.get("title") or ""),
        caption=body,
        colophon=colophon,
        tags="",
        post_date=date,
        img_status=("published" if full_post.get("status") in ("publish", "published") else status)
                   if destination_type == "page" else status,
        category="",
        slug=full_post.get("slug") or "",
        destination_type=destination_type,
        images=draft_images,
    )

# ===== SNAPSMACK EOF =====
