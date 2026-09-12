"""Normalized, destination-independent Blogger source records."""

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
class SourceEntry:
    source_id: str
    kind: str
    title: str
    content_html: str
    published: str
    updated: str
    status: str
    canonical_url: str
    labels: tuple[str, ...] = ()
    author_name: str = ""
    author_email: str = ""
    author_uri: str = ""
    parent_id: str = ""
    media: tuple[MediaRef, ...] = ()
    source_sha256: str = ""


@dataclass(frozen=True)
class SourceBlog:
    source_id: str
    title: str
    canonical_url: str
    entries: tuple[SourceEntry, ...] = ()
    warnings: tuple[str, ...] = field(default_factory=tuple)

    def counts(self) -> dict[str, int]:
        out = {"post": 0, "page": 0, "comment": 0, "unknown": 0}
        for entry in self.entries:
            out[entry.kind if entry.kind in out else "unknown"] += 1
        return out

# ===== SNAPSMACK EOF =====
