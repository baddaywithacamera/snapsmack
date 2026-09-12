"""Safe, namespace-tolerant Blogger Atom normalization."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

from __future__ import annotations

import hashlib
import io
import xml.etree.ElementTree as ET
from html.parser import HTMLParser

from .source_model import MediaRef, SourceBlog, SourceEntry


class FeedRefused(ValueError):
    """The selected XML is unsafe or not a usable Blogger feed."""


def _local(tag: str) -> str:
    return tag.rsplit("}", 1)[-1].lower()


def _child(element: ET.Element, name: str) -> ET.Element | None:
    return next((node for node in element if _local(node.tag) == name), None)


def _text(element: ET.Element, name: str) -> str:
    node = _child(element, name)
    return "" if node is None else "".join(node.itertext()).strip()


class _Images(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.items: list[MediaRef] = []

    def handle_starttag(self, tag, attrs):
        values = {str(k).lower(): str(v or "") for k, v in attrs}
        if tag.lower() == "img" and values.get("src"):
            self.items.append(MediaRef(values["src"], values.get("alt", ""),
                                       values.get("title", ""), "img"))


def _entry_kind(entry: ET.Element) -> str:
    for cat in (node for node in entry if _local(node.tag) == "category"):
        term = cat.attrib.get("term", "").lower()
        if term.endswith("#post"):
            return "post"
        if term.endswith("#page"):
            return "page"
        if term.endswith("#comment"):
            return "comment"
    return "unknown"


def _link(entry: ET.Element, rel: str) -> str:
    for node in entry:
        if _local(node.tag) == "link" and node.attrib.get("rel", "alternate") == rel:
            return node.attrib.get("href", "").strip()
    return ""


def _author(entry: ET.Element) -> tuple[str, str, str]:
    author = _child(entry, "author")
    if author is None:
        return "", "", ""
    return _text(author, "name"), _text(author, "email"), _text(author, "uri")


def _status(entry: ET.Element) -> str:
    for node in entry.iter():
        if _local(node.tag) == "draft" and (node.text or "").strip().lower() in {"yes", "true", "1"}:
            return "draft"
    return "published"


def _parent(entry: ET.Element) -> str:
    for node in entry.iter():
        if _local(node.tag) == "in-reply-to":
            return (node.attrib.get("ref") or node.attrib.get("href") or "").strip()
    return ""


def _labels(entry: ET.Element) -> tuple[str, ...]:
    labels = []
    for node in entry:
        if _local(node.tag) != "category":
            continue
        term = node.attrib.get("term", "").strip()
        if term and "kind#" not in term.lower() and term not in labels:
            labels.append(term)
    return tuple(labels)


def parse_feed(data: bytes) -> SourceBlog:
    head = data[:8192].upper()
    if b"<!DOCTYPE" in head or b"<!ENTITY" in head:
        raise FeedRefused("XML declarations that can load or expand entities are not allowed.")
    try:
        root = ET.parse(io.BytesIO(data)).getroot()
    except ET.ParseError as exc:
        raise FeedRefused(f"The Blogger feed is not valid XML: {exc}") from exc
    if _local(root.tag) != "feed":
        raise FeedRefused("The selected XML is not an Atom feed.")

    entries = []
    warnings = []
    for raw in (node for node in root if _local(node.tag) == "entry"):
        kind = _entry_kind(raw)
        content = _text(raw, "content")
        images = _Images()
        try:
            images.feed(content)
        except Exception:
            warnings.append(f"Could not inspect images in entry {_text(raw, 'id') or '(unknown)' }.")
        author_name, author_email, author_uri = _author(raw)
        entries.append(SourceEntry(
            source_id=_text(raw, "id"), kind=kind, title=_text(raw, "title"),
            content_html=content, published=_text(raw, "published"),
            updated=_text(raw, "updated"), status=_status(raw),
            canonical_url=_link(raw, "alternate"), labels=_labels(raw),
            author_name=author_name, author_email=author_email,
            author_uri=author_uri, parent_id=_parent(raw),
            media=tuple(images.items),
            source_sha256=hashlib.sha256(ET.tostring(raw, encoding="utf-8")).hexdigest(),
        ))
    if not entries:
        warnings.append("The feed contains no Blogger entries.")
    return SourceBlog(source_id=_text(root, "id"), title=_text(root, "title"),
                      canonical_url=_link(root, "alternate"), entries=tuple(entries),
                      warnings=tuple(warnings))

# ===== SNAPSMACK EOF =====
