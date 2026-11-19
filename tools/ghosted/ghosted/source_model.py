"""Normalized, destination-independent Ghost source records."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

from __future__ import annotations

from dataclasses import dataclass, field


@dataclass(frozen=True)
class MediaRef:
    url: str
    alt: str = ""
    title: str = ""
    context: str = "img"


@dataclass(frozen=True)
class LeftoverCard:
    kind: str
    detail: str


@dataclass(frozen=True)
class SourceEntry:
    source_id: str
    kind: str
    title: str
    slug: str
    content_html: str
    published: str
    created: str
    updated: str
    status: str
    excerpt: str = ""
    tags: tuple[str, ...] = ()
    author_name: str = ""
    author_email: str = ""
    feature_image: str = ""
    feature_image_alt: str = ""
    media: tuple[MediaRef, ...] = ()
    leftover_cards: tuple[LeftoverCard, ...] = ()
    source_sha256: str = ""
    raw_format: str = "html"


@dataclass(frozen=True)
class SourceBlog:
    source_id: str
    title: str
    canonical_url: str
    ghost_version: str = ""
    entries: tuple[SourceEntry, ...] = ()
    tags: tuple[str, ...] = ()
    users: tuple[dict, ...] = ()
    warnings: tuple[str, ...] = field(default_factory=tuple)
    media_root: str = ""
    zip_path: str = ""

    def counts(self) -> dict[str, int]:
        posts = sum(1 for e in self.entries if e.kind == "post")
        pages = sum(1 for e in self.entries if e.kind == "page")
        unique_images = {m.url for e in self.entries for m in e.media if m.url}
        unique_tags = set(self.tags) or {t for e in self.entries for t in e.tags if t}
        leftover = sum(len(e.leftover_cards) for e in self.entries)
        return {
            "posts": posts,
            "pages": pages,
            "images": len(unique_images),
            "tags": len(unique_tags),
            "leftover_cards": leftover,
        }

# ===== SNAPSMACK EOF =====
