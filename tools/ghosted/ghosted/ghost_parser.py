"""Parse Ghost JSON exports into normalized SourceBlog records."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

from __future__ import annotations

import hashlib
import html
import json
import re
from datetime import datetime, timezone

from .source_model import LeftoverCard, MediaRef, SourceBlog, SourceEntry

_KG_COMMENT_RE = re.compile(r"<!--\s*kg-card-begin:\s*([a-z0-9_-]+)\s*-->(.*?)<!--\s*kg-card-end:\s*\1\s*-->", re.I | re.S)
_KG_CLASS_RE = re.compile(r"<(?:figure|div|aside)\b[^>]*class\s*=\s*['\"][^'\"]*\bkg-([a-z0-9_-]+)-card\b[^'\"]*['\"][^>]*>", re.I)
_IMG_TAG_RE = re.compile(r"<img\b([^>]*?)>", re.I | re.S)
_ATTR_RE = re.compile(r"([a-zA-Z_:.-]+)\s*=\s*(?:\"([^\"]*)\"|'([^']*)'|([^\s\"'>]+))")
_SHORTCODE_RE = re.compile(r"\[([a-zA-Z][a-zA-Z0-9_-]{1,30})(?:[:\s][^\]]*)?\]")
_HANDLED_KG_CARDS = {"image", "gallery"}
_SNAP_SHORTCODES = {"img", "mosaic"}


def _mysql_date(value: str | None) -> str:
    if not value:
        return ""
    raw = str(value).strip()
    if not raw:
        return ""
    for fmt in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%dT%H:%M:%S"):
        try:
            return datetime.strptime(raw[:19], fmt).strftime("%Y-%m-%d %H:%M:%S")
        except ValueError:
            pass
    try:
        dt = datetime.fromisoformat(raw.replace("Z", "+00:00"))
        if dt.tzinfo is not None:
            dt = dt.astimezone(timezone.utc)
        return dt.strftime("%Y-%m-%d %H:%M:%S")
    except ValueError:
        return ""


def _slugify(value: str, fallback: str) -> str:
    s = re.sub(r"[^a-z0-9-]+", "-", (value or "").lower()).strip("-")
    return s or fallback


def _parse_attrs(attr_str: str) -> dict[str, str]:
    out: dict[str, str] = {}
    for m in _ATTR_RE.finditer(attr_str or ""):
        key = m.group(1).lower()
        val = m.group(2) if m.group(2) is not None else (
            m.group(3) if m.group(3) is not None else (m.group(4) or ""))
        out[key] = html.unescape(val)
    return out


def _mobiledoc_to_html(raw: str) -> tuple[str, list[LeftoverCard]]:
    leftovers: list[LeftoverCard] = []
    try:
        doc = json.loads(raw)
    except json.JSONDecodeError:
        return "", [LeftoverCard("mobiledoc", "Unparseable mobiledoc JSON payload")]
    cards = doc.get("cards") or []
    parts: list[str] = []
    for sec in (doc.get("sections") or []):
        if not isinstance(sec, list) or not sec:
            continue
        sec_type = sec[0]
        if sec_type == 1:
            tag = sec[1] if len(sec) > 1 and isinstance(sec[1], str) else "p"
            markers = sec[2] if len(sec) > 2 and isinstance(sec[2], list) else []
            text = "".join(m[3] for m in markers if isinstance(m, list) and len(m) > 3 and isinstance(m[3], str))
            if text.strip():
                parts.append(f"<{tag}>{html.escape(text)}</{tag}>")
        elif sec_type == 10 and len(sec) > 1 and isinstance(sec[1], int):
            idx = sec[1]
            if 0 <= idx < len(cards) and isinstance(cards[idx], list) and cards[idx]:
                ctype = str(cards[idx][0] or "unknown")
                payload = cards[idx][1] if len(cards[idx]) > 1 and isinstance(cards[idx][1], dict) else {}
                if ctype == "image" and payload.get("src"):
                    alt = html.escape(str(payload.get("alt") or ""))
                    src = html.escape(str(payload["src"]))
                    parts.append(f'<figure class="kg-card kg-image-card"><img src="{src}" alt="{alt}"></figure>')
                elif ctype == "html" and payload.get("html"):
                    parts.append(str(payload["html"]))
                    leftovers.append(LeftoverCard("mobiledoc:html", "Raw HTML card preserved"))
                else:
                    leftovers.append(LeftoverCard(f"mobiledoc:{ctype}", f"Unsupported mobiledoc card '{ctype}'"))
    return "\n".join(parts), leftovers


def _lexical_to_html(raw: str) -> tuple[str, list[LeftoverCard]]:
    leftovers: list[LeftoverCard] = []
    try:
        doc = json.loads(raw)
    except json.JSONDecodeError:
        return "", [LeftoverCard("lexical", "Unparseable lexical JSON payload")]
    parts: list[str] = []

    def walk(node):
        if not isinstance(node, dict):
            return
        ntype = str(node.get("type") or "")
        if ntype in {"paragraph", "heading", "quote"}:
            tag = node.get("tag") if ntype == "heading" else ("blockquote" if ntype == "quote" else "p")
            texts = []
            for ch in (node.get("children") or []):
                if isinstance(ch, dict) and ch.get("type") == "text":
                    texts.append(html.escape(str(ch.get("text") or "")))
            joined = "".join(texts).strip()
            if joined:
                parts.append(f"<{tag}>{joined}</{tag}>")
            return
        if ntype == "image" and node.get("src"):
            src = html.escape(str(node["src"]))
            alt = html.escape(str(node.get("alt") or ""))
            parts.append(f'<figure class="kg-card kg-image-card"><img src="{src}" alt="{alt}"></figure>')
            return
        if ntype not in {"", "root", "text", "linebreak", "list", "listitem"}:
            leftovers.append(LeftoverCard(f"lexical:{ntype}", f"Unsupported lexical node '{ntype}'"))
        for ch in (node.get("children") or []):
            walk(ch)

    walk(doc.get("root") or doc)
    return "\n".join(parts), leftovers


def _inspect_html_and_media(raw_html: str, feature_image: str, feature_alt: str) -> tuple[str, list[MediaRef], list[LeftoverCard]]:
    media: list[MediaRef] = []
    seen_urls: set[str] = set()
    leftovers: list[LeftoverCard] = []

    def add_media(url: str, alt: str = "", title: str = "", context: str = "img"):
        clean = (url or "").strip()
        if not clean or clean in seen_urls:
            return
        seen_urls.add(clean)
        media.append(MediaRef(url=clean, alt=alt, title=title, context=context))

    if feature_image:
        add_media(feature_image, alt=feature_alt, context="feature")

    # Detect kg-card comments
    for m in _KG_COMMENT_RE.finditer(raw_html):
        card_type = m.group(1).lower()
        if card_type not in _HANDLED_KG_CARDS:
            leftovers.append(LeftoverCard(f"kg-card:{card_type}", f"Ghost '{card_type}' card left as HTML"))

    # Detect kg-*-card classes not covered by handled types
    for m in _KG_CLASS_RE.finditer(raw_html):
        card_type = m.group(1).lower()
        tag_id = f"kg-card:{card_type}"
        if card_type not in _HANDLED_KG_CARDS and not any(c.kind == tag_id for c in leftovers):
            leftovers.append(LeftoverCard(tag_id, f"Ghost '{card_type}' card class left in body"))

    # Detect shortcodes (excluding SNAPSMACK [img:...] / [mosaic:...])
    stripped_for_shortcodes = re.sub(r"<[^>]+>", " ", raw_html)
    for m in _SHORTCODE_RE.finditer(stripped_for_shortcodes):
        sc = m.group(1).lower()
        if sc not in _SNAP_SHORTCODES:
            leftovers.append(LeftoverCard(f"shortcode:{sc}", f"Shortcode [{sc}] left in text"))

    # Extract all <img> tags
    for m in _IMG_TAG_RE.finditer(raw_html):
        attrs = _parse_attrs(m.group(1))
        src = attrs.get("src", "").strip()
        if src:
            add_media(src, alt=attrs.get("alt", ""), title=attrs.get("title", ""), context="img")

    # Strip handled kg-card-begin/end comments for image/gallery
    cleaned = re.sub(r"<!--\s*kg-card-(?:begin|end):\s*(?:image|gallery)\s*-->", "", raw_html, flags=re.I)
    return cleaned.strip(), media, leftovers


def parse_ghost_json(raw_bytes: bytes, *, source_name: str = "ghost-export",
                     media_root: str = "", zip_path: str = "") -> SourceBlog:
    doc = json.loads(raw_bytes.decode("utf-8"))
    if isinstance(doc.get("db"), list) and doc["db"]:
        root = doc["db"][0]
    else:
        root = doc
    meta = root.get("meta") or {}
    data = root.get("data") or {}

    tags_by_id = {str(t.get("id")): str(t.get("name") or "").strip()
                  for t in (data.get("tags") or []) if t.get("id") and t.get("name")}
    users_by_id = {str(u.get("id")): u for u in (data.get("users") or []) if u.get("id")}

    post_tags_map: dict[str, list[tuple[int, str]]] = {}
    for pt in (data.get("posts_tags") or []):
        pid = str(pt.get("post_id") or "")
        tid = str(pt.get("tag_id") or "")
        if pid and tid in tags_by_id:
            order = int(pt.get("sort_order") or 0)
            post_tags_map.setdefault(pid, []).append((order, tags_by_id[tid]))

    post_author_map: dict[str, dict] = {}
    for pa in (data.get("posts_authors") or []):
        pid = str(pa.get("post_id") or "")
        aid = str(pa.get("author_id") or "")
        if pid and aid in users_by_id and pid not in post_author_map:
            post_author_map[pid] = users_by_id[aid]

    entries: list[SourceEntry] = []
    warnings: list[str] = []

    for idx, p in enumerate(data.get("posts") or []):
        pid = str(p.get("id") or f"ghost-{idx}")
        raw_type = str(p.get("type") or "post").lower()
        kind = "page" if raw_type == "page" else "post"
        raw_status = str(p.get("status") or "published").lower()
        status = "published" if raw_status == "published" else "draft"
        title = str(p.get("title") or f"Untitled {pid}")
        slug = _slugify(str(p.get("slug") or title), f"{kind}-{pid}")

        raw_format = "html"
        raw_html = str(p.get("html") or "")
        extra_leftovers: list[LeftoverCard] = []
        if not raw_html.strip() and p.get("mobiledoc"):
            raw_format = "mobiledoc"
            raw_html, extra_leftovers = _mobiledoc_to_html(str(p["mobiledoc"]))
        elif not raw_html.strip() and p.get("lexical"):
            raw_format = "lexical"
            raw_html, extra_leftovers = _lexical_to_html(str(p["lexical"]))
        elif not raw_html.strip() and p.get("plaintext"):
            raw_format = "plaintext"
            raw_html = f"<p>{html.escape(str(p['plaintext']))}</p>"

        feature_img = str(p.get("feature_image") or "").strip()
        feature_alt = str(p.get("feature_image_alt") or "").strip()
        cleaned_html, media_refs, html_leftovers = _inspect_html_and_media(raw_html, feature_img, feature_alt)
        leftovers = tuple(extra_leftovers + html_leftovers)
        for lc in leftovers:
            warnings.append(f"{kind.upper()} '{slug}': {lc.kind} — {lc.detail}")

        ordered_tags = tuple(name for _o, name in sorted(post_tags_map.get(pid, []), key=lambda x: x[0]))
        author = post_author_map.get(pid) or {}
        sha = hashlib.sha256(json.dumps(p, sort_keys=True, ensure_ascii=False).encode("utf-8")).hexdigest()

        entries.append(SourceEntry(
            source_id=pid,
            kind=kind,
            title=title,
            slug=slug,
            content_html=cleaned_html or "<p></p>",
            excerpt=str(p.get("custom_excerpt") or ""),
            published=_mysql_date(p.get("published_at") or p.get("created_at")),
            created=_mysql_date(p.get("created_at") or p.get("published_at")),
            updated=_mysql_date(p.get("updated_at") or p.get("created_at")),
            status=status,
            tags=ordered_tags,
            author_name=str(author.get("name") or ""),
            author_email=str(author.get("email") or ""),
            feature_image=feature_img,
            feature_image_alt=feature_alt,
            media=tuple(media_refs),
            leftover_cards=leftovers,
            source_sha256=sha,
            raw_format=raw_format,
        ))

    source_id = hashlib.sha256(raw_bytes[:65536] + source_name.encode("utf-8")).hexdigest()[:24]
    all_tags = tuple(sorted(set(tags_by_id.values())))
    return SourceBlog(
        source_id=f"ghost:{source_id}",
        title=source_name,
        canonical_url="",
        ghost_version=str(meta.get("version") or ""),
        entries=tuple(entries),
        tags=all_tags,
        users=tuple(users_by_id.values()),
        warnings=tuple(warnings),
        media_root=media_root,
        zip_path=zip_path,
    )

# ===== SNAPSMACK EOF =====
