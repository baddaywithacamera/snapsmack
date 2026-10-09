"""
SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
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
_SP_GALLERY = re.compile(
    r"\[smackpress-gallery\](.*?)\[/smackpress-gallery\]", re.I | re.S)
# an <img> wrapped in <a>…</a> and/or <figure>…</figure> (with optional figcaption)
_WRAPPED   = re.compile(
    r"(?:<figure\b[^>]*>\s*)?(?:<a\b[^>]*>\s*)?(<img\b[^>]*>)(?:\s*</a>)?"
    r"(?:\s*<figcaption\b[^>]*>.*?</figcaption>)?(?:\s*</figure>)?", re.I | re.S)
_EMPTY_P   = re.compile(r"<p\b[^>]*>\s*(\[img:g?bucket:\d+\])\s*</p>", re.I)
_WP_BLOCK_COMMENT = re.compile(r"<!--\s*/?wp:[\s\S]*?-->", re.I)
_WP_SPACER = re.compile(
    r'<div\b[^>]*class=["\'][^"\']*\bwp-block-spacer\b[^"\']*["\'][^>]*>.*?</div>',
    re.I | re.S,
)
_DIV_TAG = re.compile(r"</?div\b[^>]*>", re.I)
_WP_COLUMNS_BLOCK = re.compile(
    r"<!--\s*wp:columns\b[^>]*-->(.*?)<!--\s*/wp:columns\s*-->", re.I | re.S)
_WP_GALLERY_BLOCK = re.compile(
    r"<!--\s*wp:gallery\b[^>]*-->(.*?)<!--\s*/wp:gallery\s*-->", re.I | re.S)
_BUCKET_TOKEN = re.compile(r"\[img:g?bucket:(\d+)\]", re.I)
_ADJACENT_BUCKETS = re.compile(
    r"(?P<run>\[img:g?bucket:\d+\](?:\s+\[img:g?bucket:\d+\])+)",
    re.I,
)
_IMAGE_LINK = re.compile(
    r"<a\b[^>]*\bhref\s*=\s*([\"'])([^\"']+)\1[^>]*>\s*<img\b",
    re.I | re.S,
)


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

    def token_for_tag(tag: str, gallery: bool = False) -> str:
        src = _SRC_ATTR.search(tag)
        if not src:
            return ""
        alt = _ALT_ATTR.search(tag)
        prefix = "g" if gallery else ""
        return "\n[img:%sbucket:%d]\n" % (
            prefix,
            slot(src.group(1), html.unescape(alt.group(1)) if alt else ""),
        )

    body = content or ""

    # Classic [gallery] shortcodes are expanded by the companion inside this
    # explicit boundary.  Resolve every member through slot() first, then carry
    # the whole authored group forward as one native mosaic placeholder.
    def repl_sp_gallery(match):
        positions = [slot(item.group(2)) for item in _SP_IMAGE.finditer(match.group(1))]
        return ("\n[mosaic=%s layout=asymmetric]\n" %
                ",".join(str(position) for position in positions)) if positions else ""

    body = _SP_GALLERY.sub(repl_sp_gallery, body)
    body = _SP_IMAGE.sub(repl_sp, body)

    # Gutenberg stores gallery membership in block comments around nested image
    # figures.  Read that boundary before the ordinary image pass consumes the
    # figures, so a four-photo block stays a four-photo mosaic.
    def repl_wp_gallery(match):
        positions = []
        for tag in _IMG_TAG.findall(match.group(1)):
            src = _SRC_ATTR.search(tag)
            if not src:
                continue
            alt = _ALT_ATTR.search(tag)
            positions.append(slot(
                src.group(1), html.unescape(alt.group(1)) if alt else ""))
        return ("\n[mosaic=%s layout=asymmetric]\n" %
                ",".join(str(position) for position in positions)) if positions else ""

    body = _WP_GALLERY_BLOCK.sub(repl_wp_gallery, body)
    # A WordPress image wrapped in a media-file link is authored lightbox intent.
    # Preserve that distinction as a gallery-forced bucket token; the COLD SNAP
    # poster resolves it to [img:gID] after uploading the photograph.
    body = _WRAPPED.sub(
        lambda m: token_for_tag(m.group(1), bool(_IMAGE_LINK.search(m.group(0)))),
        body,
    )
    body = _IMG_TAG.sub(lambda m: token_for_tag(m.group(0)), body)   # any bare <img> left over
    body = _EMPTY_P.sub(r"\n\1\n", body)

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

    # Authored adjacency is the grouping rule. Two or more pictures together
    # between prose blocks are one visual cluster and therefore one mosaic.
    # A paragraph (or any other retained content) ends the run. This applies
    # equally to classic, Gutenberg, and plain HTML imports; it does not guess
    # a layout from one site's historical block structure.
    def adjacent_mosaic(match):
        positions = _BUCKET_TOKEN.findall(match.group("run"))
        return "\n[mosaic=%s layout=asymmetric]\n" % ",".join(positions)

    body = _ADJACENT_BUCKETS.sub(adjacent_mosaic, body).strip()
    return body, ordered


_WP_SIGNATURE_HINT = re.compile(r"(?:signature|autograph|sign[-_ ]?off)", re.I)
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


def _profile_markers(profile: Optional[dict], key: str) -> List[str]:
    values = (profile or {}).get(key) or []
    if isinstance(values, str):
        values = [values]
    return [str(value).strip() for value in values if str(value).strip()]


def extract_wordpress_ephemera(body: str, ordered: List[dict],
                               profile: Optional[dict] = None) -> Tuple[str, str, set]:
    """Translate recurring WordPress-era conventions into explicit post data.

    This deliberately belongs to the WordPress adapter. The CMS and skins never
    inspect prose, filenames, or old editor markup to guess what content means.
    Returns cleaned body, colophon HTML, and 1-based signature image positions.
    """
    signature_slots = set()
    signature_markers = _profile_markers(profile, "signature_markers")
    for position, image in enumerate(ordered, 1):
        haystack = " ".join(str(image.get(key) or "") for key in
                            ("filename", "title", "alt", "caption", "url"))
        profiled_signature = any(marker.casefold() in haystack.casefold()
                                 for marker in signature_markers)
        if _WP_SIGNATURE_HINT.search(haystack) or profiled_signature:
            signature_slots.add(position)
            body = re.sub(r"(?:^|\n)\s*\[img:bucket:%d\]\s*(?=\n|$)" % position, "\n", body)

    colophon = ""
    final = re.search(r"(<p\b[^>]*>(?:(?!<p\b).)*?</p>)\s*$", body, re.I | re.S)
    if final:
        plain = html.unescape(re.sub(r"<[^>]+>", " ", final.group(1)))
        terms = {m.group(0).lower() for m in _WP_EQUIPMENT.finditer(plain)}
        colophon_markers = _profile_markers(profile, "colophon_markers")
        profiled_colophon = any(marker.casefold() in plain.casefold()
                                for marker in colophon_markers)
        if _WP_COLOPHON_LEAD.search(plain) or len(terms) >= 3 or profiled_colophon:
            colophon = final.group(1)
            body = body[:final.start()].rstrip()

    return re.sub(r"\n{3,}", "\n\n", body).strip(), colophon, signature_slots


# ── the adapter ───────────────────────────────────────────────────────────────
def draft_from_wp(full_post: dict, workdir: str, *, fetch: Callable = None,
                  status: str = "draft", on_progress: Callable[[str], None] = None,
                  profile: Optional[dict] = None) -> Draft:
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
        body, colophon, signature_slots = extract_wordpress_ephemera(body, ordered, profile)

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
